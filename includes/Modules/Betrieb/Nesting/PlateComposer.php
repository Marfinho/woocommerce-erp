<?php
/**
 * Zusammensetzen mehrerer Bauteile zu einer gemeinsamen (ungeslicten)
 * Projekt-3MF, nach den Platzierungen aus BinPacker::arrange().
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ThreeMf;

/**
 * Reine ZIP-/XML-Arbeit, kein Slicing-Wissen: Aus mehreren Quell-3MF wird
 * eine einzige 3MF mit mehreren <object>-Ressourcen und einem <build> voller
 * <item>-Platzierungen (Transformationsmatrix je Kopie).
 *
 * Grenzen (bewusst, siehe MeshOutline für dieselbe Einschränkung): Es wird
 * je Quelldatei nur das ERSTE <object> übernommen, und nur, wenn es ein
 * reiner Mesh-Körper ist (kein <components>-Verweis auf andere Objekte
 * innerhalb derselben Datei – eine solche Unterbaugruppe liesse sich ohne
 * die referenzierten Objekte nicht sinnvoll mitnehmen). compose() bricht
 * in diesem Fall mit einer verständlichen Fehlermeldung ab, statt eine
 * unvollständige 3MF zu erzeugen.
 */
class PlateComposer {

	/**
	 * 3MF-Transformationsmatrix (12 Zahlen, Zeilen zuerst) für eine reine
	 * Z-Rotation plus Verschiebung in der XY-Ebene – genau das, was
	 * BinPacker::arrange() liefert (2D-Anordnung auf dem Druckbett).
	 *
	 * Reine Rechnung, keine Datei-/Zip-Arbeit – deshalb separat und ohne
	 * WordPress-Testsuite prüfbar. Die Drehrichtung entspricht
	 * Polygon::rotate() (Gegenuhrzeigersinn), damit eine BinPacker-
	 * Platzierung unverändert hier eingesetzt werden kann.
	 *
	 * @param float $rotation_deg Winkel um die Z-Achse, Gegenuhrzeigersinn.
	 * @param float $tx           Verschiebung X (mm).
	 * @param float $ty           Verschiebung Y (mm).
	 * @return string Die 12 Matrixzahlen, leerzeichengetrennt.
	 */
	public static function transform_matrix( $rotation_deg, $tx, $ty ) {
		$rad = deg2rad( (float) $rotation_deg );
		$cos = cos( $rad );
		$sin = sin( $rad );

		$numbers = array( $cos, $sin, 0, -$sin, $cos, 0, 0, 0, 1, (float) $tx, (float) $ty, 0 );

		return implode(
			' ',
			array_map(
				static function ( $n ) {
					// Bis zu 6 Nachkommastellen, ohne unnötige Nullen -
					// genug Präzision für den Slicer, ohne die Datei mit
					// Fliesskomma-Rauschen aufzublähen.
					return rtrim( rtrim( sprintf( '%.6F', $n ), '0' ), '.' );
				},
				$numbers
			)
		);
	}

	/**
	 * Mehrere Quell-3MF zu einer gemeinsamen Projekt-3MF zusammensetzen.
	 *
	 * @param array  $placements  Liste {source_path, x, y, rotation_deg}.
	 *                             source_path = absoluter Pfad der Quell-3MF
	 *                             (eine Datei darf mehrfach vorkommen, für
	 *                             mehrere Kopien desselben Bauteils).
	 * @param string $output_path Zielpfad der zusammengesetzten 3MF.
	 * @return array {ok, error}
	 */
	public static function compose( array $placements, $output_path ) {
		if ( ! $placements ) {
			return array( 'ok' => false, 'error' => __( 'Keine Platzierungen übergeben.', 'lindenweg16' ) );
		}

		if ( ! class_exists( '\ZipArchive' ) ) {
			return array( 'ok' => false, 'error' => __( 'PHP-Erweiterung ZipArchive fehlt.', 'lindenweg16' ) );
		}

		$base_path = (string) $placements[0]['source_path'];

		$base_zip = new \ZipArchive();
		if ( true !== $base_zip->open( $base_path ) ) {
			return array( 'ok' => false, 'error' => __( 'Basis-3MF konnte nicht geöffnet werden.', 'lindenweg16' ) );
		}

		$base_model_xml = $base_zip->getFromName( ThreeMf::MODEL_PATH );
		if ( ! is_string( $base_model_xml ) ) {
			$base_zip->close();
			return array( 'ok' => false, 'error' => __( 'Basis-3MF enthält kein 3dmodel.model.', 'lindenweg16' ) );
		}

		$doc = new \DOMDocument();
		$doc->loadXML( $base_model_xml, LIBXML_NONET );

		$resources = self::first_child( $doc->documentElement, 'resources' );
		$build     = self::first_child( $doc->documentElement, 'build' );

		if ( ! $resources || ! $build ) {
			$base_zip->close();
			return array( 'ok' => false, 'error' => __( 'Basis-3MF hat keine gültige 3MF-Struktur (resources/build fehlt).', 'lindenweg16' ) );
		}

		// Von den vorhandenen Objekten nur das erste behalten (siehe
		// Klassendoku) – alle anderen (z. B. schon vorhandene <item>s einer
		// bereits geslicten Datei) werden entfernt, bevor neue dazukommen.
		$objects = array();
		foreach ( iterator_to_array( $resources->childNodes ) as $child ) {
			if ( $child instanceof \DOMElement && 'object' === $child->localName ) {
				$objects[] = $child;
			}
		}

		if ( ! $objects ) {
			$base_zip->close();
			return array( 'ok' => false, 'error' => __( 'Basis-3MF enthält kein Bauteil (keine <object>-Ressource).', 'lindenweg16' ) );
		}

		$base_object = $objects[0];

		if ( self::has_components( $base_object ) ) {
			$base_zip->close();
			return array( 'ok' => false, 'error' => __( 'Diese Projektdatei ist eine Unterbaugruppe (verweist per <components> auf andere Objekte) – das unterstützt die Plattenanordnung nicht.', 'lindenweg16' ) );
		}

		foreach ( $objects as $obj ) {
			$resources->removeChild( $obj );
		}
		foreach ( iterator_to_array( $build->childNodes ) as $item ) {
			$build->removeChild( $item );
		}

		$resources->appendChild( $base_object );

		$next_id = self::max_resource_id( $doc ) + 1;

		// path => neu vergebene Objekt-ID im zusammengesetzten Dokument.
		$object_id_by_path            = array();
		$object_id_by_path[ $base_path ] = $base_object->getAttribute( 'id' );

		foreach ( $placements as $placement ) {
			$path = (string) $placement['source_path'];

			if ( ! isset( $object_id_by_path[ $path ] ) ) {
				$imported = self::import_object( $doc, $resources, $path, $next_id );

				if ( ! $imported['ok'] ) {
					$base_zip->close();
					return array( 'ok' => false, 'error' => $imported['error'] );
				}

				$object_id_by_path[ $path ] = $imported['object_id'];
				$next_id                    = $imported['object_id'] + 1;
			}

			$item = $doc->createElementNS( $doc->documentElement->namespaceURI ?: null, 'item' );
			$item->setAttribute( 'objectid', $object_id_by_path[ $path ] );
			$item->setAttribute(
				'transform',
				self::transform_matrix( isset( $placement['rotation_deg'] ) ? $placement['rotation_deg'] : 0, $placement['x'], $placement['y'] )
			);
			$build->appendChild( $item );
		}

		$new_model_xml = $doc->saveXML();

		$out = new \ZipArchive();
		if ( true !== $out->open( $output_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			$base_zip->close();
			return array( 'ok' => false, 'error' => __( 'Zieldatei konnte nicht angelegt werden.', 'lindenweg16' ) );
		}

		// Alle Nicht-Modell-Einträge des Basis-Containers unverändert
		// übernehmen ([Content_Types].xml, _rels, ggf. Vorschaubild) –
		// dieselbe grundsätzliche Struktur, wie Bambu Studio sie erzeugt,
		// muss nicht neu erfunden werden.
		for ( $i = 0; $i < $base_zip->numFiles; $i++ ) {
			$name = $base_zip->getNameIndex( $i );
			if ( ThreeMf::MODEL_PATH === $name ) {
				continue;
			}
			$out->addFromString( $name, $base_zip->getFromIndex( $i ) );
		}

		$out->addFromString( ThreeMf::MODEL_PATH, $new_model_xml );

		$base_zip->close();
		$out->close();

		return array( 'ok' => true, 'error' => '' );
	}

	/**
	 * Erstes Kindelement mit diesem lokalen Namen.
	 *
	 * @param \DOMElement|null $parent Elternelement.
	 * @param string           $name   Gesuchter Elementname.
	 * @return \DOMElement|null
	 */
	protected static function first_child( $parent, $name ) {
		if ( ! $parent ) {
			return null;
		}
		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof \DOMElement && $name === $child->localName ) {
				return $child;
			}
		}
		return null;
	}

	/**
	 * Enthält ein <object> einen <components>-Verweis auf andere Objekte
	 * (Unterbaugruppe statt reinem Mesh-Körper)?
	 *
	 * @param \DOMElement $object <object>-Knoten.
	 * @return bool
	 */
	protected static function has_components( \DOMElement $object ) {
		foreach ( $object->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'components' === $child->localName ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Höchste bislang vergebene Ressourcen-ID im Dokument (0, wenn keine).
	 *
	 * @param \DOMDocument $doc Dokument.
	 * @return int
	 */
	protected static function max_resource_id( \DOMDocument $doc ) {
		$max = 0;
		foreach ( $doc->getElementsByTagName( 'object' ) as $object ) {
			$id = (int) $object->getAttribute( 'id' );
			if ( $id > $max ) {
				$max = $id;
			}
		}
		return $max;
	}

	/**
	 * Das erste <object> einer weiteren Quell-3MF ins Zieldokument
	 * importieren, mit einer neuen, kollisionsfreien ID.
	 *
	 * @param \DOMDocument $doc       Zieldokument.
	 * @param \DOMElement  $resources Ziel-<resources>.
	 * @param string       $path      Quelldatei.
	 * @param int          $new_id    Zu vergebende ID.
	 * @return array {ok, object_id, error}
	 */
	protected static function import_object( \DOMDocument $doc, \DOMElement $resources, $path, $new_id ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return array( 'ok' => false, 'object_id' => 0, 'error' => __( 'PHP-Erweiterung ZipArchive fehlt.', 'lindenweg16' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return array( 'ok' => false, 'object_id' => 0, 'error' => __( 'Quell-3MF konnte nicht geöffnet werden.', 'lindenweg16' ) );
		}

		$xml = $zip->getFromName( ThreeMf::MODEL_PATH );
		$zip->close();

		if ( ! is_string( $xml ) ) {
			return array( 'ok' => false, 'object_id' => 0, 'error' => __( 'Quell-3MF enthält kein 3dmodel.model.', 'lindenweg16' ) );
		}

		$source_doc = new \DOMDocument();
		$source_doc->loadXML( $xml, LIBXML_NONET );

		$object = null;
		foreach ( $source_doc->getElementsByTagName( 'object' ) as $candidate ) {
			$object = $candidate;
			break;
		}

		if ( ! $object ) {
			return array( 'ok' => false, 'object_id' => 0, 'error' => __( 'Quell-3MF enthält kein Bauteil.', 'lindenweg16' ) );
		}

		if ( self::has_components( $object ) ) {
			return array(
				'ok'        => false,
				'object_id' => 0,
				'error'     => __( 'Eine der Projektdateien ist eine Unterbaugruppe (verweist per <components> auf andere Objekte) – das unterstützt die Plattenanordnung nicht.', 'lindenweg16' ),
			);
		}

		$imported = $doc->importNode( $object, true );
		$imported->setAttribute( 'id', (string) $new_id );
		$resources->appendChild( $imported );

		return array( 'ok' => true, 'object_id' => $new_id, 'error' => '' );
	}
}
