<?php
/**
 * Orchestriert die Plattenanordnung: Bauteile wählen, anordnen, zu
 * Projekt-3MF-Dateien zusammensetzen und als Plattenaufträge ablegen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Installer;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository;
use Lindenweg16\Modules\Betrieb\ModelFile;
use Lindenweg16\Modules\Betrieb\RevisionRepository;

/**
 * Bindeglied zwischen Bedarf/Artikelauswahl, dem geometrischen Nesting
 * (BinPacker) und der eigentlichen Dateierzeugung (PlateComposer).
 */
class PlateService {

	/**
	 * Vorgabe-Sicherheitsabstand zwischen zwei Bauteilen und zum Bettrand.
	 */
	const DEFAULT_MARGIN_MM = 5.0;

	/**
	 * Eine oder mehrere Platten aus einer Artikel-/Mengen-Liste anlegen.
	 *
	 * @param string $bambu_printer_id Stabiler Schlüssel des Bambu-Druckers
	 *                                  (bestimmt das Druckbettmass).
	 * @param array  $items            Liste {article_id, qty}.
	 * @param float  $margin_mm        Sicherheitsabstand (mm).
	 * @return array {ok, plate_ids, warnings, error}
	 */
	public static function build_plates( $bambu_printer_id, array $items, $margin_mm = self::DEFAULT_MARGIN_MM ) {
		$bambu_printer_id = sanitize_key( (string) $bambu_printer_id );
		$leer             = array( 'ok' => false, 'plate_ids' => array(), 'warnings' => array(), 'error' => '' );

		$printer = BambuPrinterRepository::find_by_printer_id( $bambu_printer_id );
		if ( ! $printer ) {
			$leer['error'] = __( 'Unbekannter Bambu-Drucker.', 'lindenweg16' );
			return $leer;
		}

		$bed = BambuPrinterRepository::effective_bed_size( $printer );
		if ( ! $bed ) {
			$leer['error'] = __( 'Für diesen Drucker ist kein Druckbettmass bekannt – bitte bei den Verbindungsdaten eintragen oder ein erkennbares Druckermodell melden lassen (Bridge → POST /status).', 'lindenweg16' );
			return $leer;
		}

		// Feste Sperrflächen des Druckers (z. B. eine gesperrte Bettecke
		// bei Bambu-Druckern) aus einem hinterlegten Slicing-Profil lesen,
		// falls eins mit passendem Druckermodell existiert - ohne das
		// bleibt es beim bisherigen Verhalten (ganzes Bettrechteck
		// bebaubar). Sonst würde die Anordnung hier ein Bauteil für gültig
		// halten, das OrcaSlicer beim Slicen als Kollision mit der
		// Sperrfläche ablehnt (live so passiert).
		$exclusion_zones = array();
		if ( ! empty( $printer->printer_model ) ) {
			foreach ( SlicingProfileRepository::printer_profile_paths_for_model( $printer->printer_model ) as $profile_path ) {
				$exclusion_zones = PrinterBedExclusion::from_profile_json( $profile_path );
				if ( $exclusion_zones ) {
					break; // Erstes Profil, das tatsächlich eine Sperrfläche nennt.
				}
			}
		}

		$expanded = array();
		$warnings = array();

		foreach ( $items as $item ) {
			$article_id = isset( $item['article_id'] ) ? (int) $item['article_id'] : 0;
			$qty        = isset( $item['qty'] ) ? max( 0, (int) $item['qty'] ) : 0;

			if ( ! $article_id || $qty <= 0 ) {
				continue;
			}

			$article  = ArticleRepository::find( $article_id );
			$revision = RevisionRepository::active_for_article( $article_id );
			$label    = $article ? trim( $article['sku'] . ' – ' . $article['name'], ' –' ) : '#' . $article_id;

			if ( ! $revision || empty( $revision['model_file'] ) ) {
				$warnings[] = sprintf(
					/* translators: %s: Artikel (Nummer – Name). */
					__( '%s: Es ist keine Projekt-3MF hinterlegt, deshalb kann der Artikel nicht geplant werden. Bitte beim Artikel in der aktiven Revision die ungeslicte Projektdatei hochladen.', 'lindenweg16' ),
					$label
				);
				continue;
			}

			// Nur die ungeslicte Projektdatei hat noch verwertbare
			// Bauteilgeometrie samt <build>-Eintrag für BinPacker/
			// PlateComposer - eine bereits geslicte Druckdatei (model_kind
			// = druckdatei) ist hier fehl am Platz (dafür gibt es "Bedarf
			// direkt drucken", siehe BambuService::dispatch_from_demand()).
			if ( 'modell' !== $revision['model_kind'] ) {
				$warnings[] = sprintf(
					/* translators: %s: Artikel (Nummer – Name). */
					__( '%s: Die hinterlegte Datei ist schon geslict. Zum Anordnen der Platten wird die ungeslicte Projekt-3MF gebraucht – bitte beim Artikel in der aktiven Revision hochladen.', 'lindenweg16' ),
					$label
				);
				continue;
			}

			$path = ModelFile::safe_path( $revision['model_file'] );
			if ( '' === $path ) {
				$warnings[] = sprintf(
					/* translators: %s: Artikel (Nummer – Name). */
					__( '%s: Die hinterlegte 3MF-Datei liegt nicht mehr auf dem Server. Bitte beim Artikel erneut hochladen.', 'lindenweg16' ),
					$label
				);
				continue;
			}

			// xy_hull_from_file() statt xy_hull_from_xml(): manche Bambu-Studio-
		// Exporte lagern die Geometrie eines Objekts in eine eigene Datei
		// innerhalb der 3MF aus (Production Extension, siehe MeshOutline)
		// statt sie im Wurzel-3dmodel.model einzubetten - das lässt sich nur
		// mit Zugriff auf die ganze ZIP-Datei auflösen, nicht aus dem
		// XML-String allein.
		$hull = MeshOutline::xy_hull_from_file( $path );

			if ( ! $hull || count( $hull ) < 3 ) {
				$warnings[] = sprintf(
					/* translators: %s: Artikel (Nummer – Name). */
					__( '%s: In der 3MF ist keine auswertbare Form zu finden. Bitte die Projektdatei neu aus dem Slicer exportieren und hochladen.', 'lindenweg16' ),
					$label
				);
				continue;
			}

			for ( $i = 0; $i < $qty; $i++ ) {
				$expanded[] = array(
					'key'         => $article_id . '#' . $i,
					'hull'        => $hull,
					'article_id'  => $article_id,
					'source_path' => $path,
				);
			}
		}

		if ( ! $expanded ) {
			$leer['error'] = $warnings ? implode( ' ', $warnings ) : __( 'Keine druckbaren Positionen ausgewählt.', 'lindenweg16' );
			return $leer;
		}

		$result = BinPacker::arrange(
			array_map(
				static function ( $p ) {
					return array( 'key' => $p['key'], 'hull' => $p['hull'] );
				},
				$expanded
			),
			$bed['width_mm'],
			$bed['height_mm'],
			$margin_mm,
			BinPacker::DEFAULT_ROTATIONS,
			$exclusion_zones
		);

		foreach ( $result['errors'] as $error ) {
			$warnings[] = $error['message'];
		}

		if ( ! $result['bins'] ) {
			$leer['warnings'] = $warnings;
			$leer['error']    = __( 'Keine Platte konnte angeordnet werden.', 'lindenweg16' );
			return $leer;
		}

		$by_key = array();
		foreach ( $expanded as $p ) {
			$by_key[ $p['key'] ] = $p;
		}

		Installer::ensure_docs_dir();

		$plate_ids = array();

		foreach ( $result['bins'] as $index => $bin ) {
			$placements = array();
			foreach ( $bin['placements'] as $placement ) {
				$part = isset( $by_key[ $placement['key'] ] ) ? $by_key[ $placement['key'] ] : null;
				if ( ! $part ) {
					continue;
				}
				$placements[] = array(
					'source_path'  => $part['source_path'],
					'x'            => $placement['x'],
					'y'            => $placement['y'],
					'rotation_deg' => $placement['rotation_deg'],
					'article_id'   => $part['article_id'],
				);
			}

			if ( ! $placements ) {
				continue;
			}

			// Lesbar und eindeutig: Der Name erscheint auf dem Druckerdisplay und
			// ordnet den Druck im Workflow der Platte zu (RunFlow::file_matches).
			$filename = sanitize_file_name( sprintf( 'Platte-%s-%s-%d-%s.3mf', $bambu_printer_id, gmdate( 'Ymd-His' ), $index + 1, strtolower( wp_generate_password( 4, false, false ) ) ) );
			$out_path = trailingslashit( Installer::docs_dir() ) . $filename;

			$compose = PlateComposer::compose( $placements, $out_path );

			if ( ! $compose['ok'] ) {
				$warnings[] = sprintf(
					/* translators: 1: Plattennummer, 2: Fehlermeldung. */
					__( 'Platte %1$d konnte nicht zusammengesetzt werden: %2$s', 'lindenweg16' ),
					$index + 1,
					$compose['error']
				);
				continue;
			}

			$counts = array();
			foreach ( $placements as $p ) {
				$counts[ $p['article_id'] ] = ( isset( $counts[ $p['article_id'] ] ) ? $counts[ $p['article_id'] ] : 0 ) + 1;
			}

			$plate_id = PlateJobRepository::create(
				array(
					'printer_id'      => $bambu_printer_id,
					'file_path'       => $out_path,
					'file_name'       => $filename,
					'plate_width_mm'  => $bed['width_mm'],
					'plate_height_mm' => $bed['height_mm'],
					'placements'      => array(
						'items' => array_map(
							static function ( $p ) {
								return array(
									'article_id'   => $p['article_id'],
									'x'            => $p['x'],
									'y'            => $p['y'],
									'rotation_deg' => $p['rotation_deg'],
								);
							},
							$placements
						),
						'counts_by_article' => $counts,
					),
				)
			);

			if ( $plate_id ) {
				$plate_ids[] = $plate_id;
				AuditLog::record(
					'bambu_platte_angeordnet',
					'bambu_plate_job',
					$plate_id,
					array( 'drucker' => $bambu_printer_id, 'teile' => count( $placements ) )
				);
			}
		}

		if ( ! $plate_ids ) {
			$leer['warnings'] = $warnings;
			$leer['error']    = __( 'Keine Platte konnte gespeichert werden.', 'lindenweg16' );
			return $leer;
		}

		return array( 'ok' => true, 'plate_ids' => $plate_ids, 'warnings' => $warnings, 'error' => '' );
	}
}
