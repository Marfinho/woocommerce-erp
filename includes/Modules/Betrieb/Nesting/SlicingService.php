<?php
/**
 * Einreihen von Platten in die Slicing-Warteschlange und Weiterleitung
 * eines fertig geslicten Ergebnisses in die Bambu-Warteschlange.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Installer;
use Lindenweg16\Core\Settings;
use Lindenweg16\Modules\Betrieb\Bambu\BambuJobRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuService;
use Lindenweg16\Modules\Betrieb\RevisionRepository;

/**
 * Bindeglied zwischen Plattenanordnung (Nesting\PlateService) und der
 * Bambu-Druckwarteschlange: Eine im Workflow freigegebene Platte wird erst
 * automatisch geslict, dann in dieselbe Warteschlange gelegt, die auch der
 * manuelle Upload benutzt (siehe Erp\Workflow::slice()).
 */
class SlicingService {

	/**
	 * Eine entworfene Platte in die Slicing-Warteschlange legen.
	 *
	 * Das Material für die Profilwahl kommt vom ERSTEN Artikel auf der
	 * Platte – bei einer gemischten Platte mit unterschiedlichen
	 * Materialien ist das eine bewusste Vereinfachung (echtes
	 * Mehrmaterial-Slicing mit AMS-Zuordnung leistet dieser Worker nicht);
	 * in der Praxis bestehen Platten meist aus einem Material.
	 *
	 * @param int $plate_job_id Platte.
	 * @return array {ok, slicing_job_id, error}
	 */
	public static function enqueue_plate( $plate_job_id ) {
		$plate = PlateJobRepository::find( $plate_job_id );
		$leer  = array( 'ok' => false, 'slicing_job_id' => 0, 'error' => '' );

		if ( ! $plate ) {
			$leer['error'] = __( 'Platte nicht gefunden.', 'lindenweg16' );
			return $leer;
		}

		if ( ! PlateJobRepository::can_enqueue_for_slicing( $plate->status ) ) {
			$leer['error'] = __( 'Diese Platte wird bereits geslict oder liegt schon in der Bambu-Warteschlange.', 'lindenweg16' );
			return $leer;
		}

		// Sicherheitsnetz gegen Doppel-Klicks und Altlasten: Solange zu
		// dieser Platte noch ein offener Slicing-Auftrag existiert, keinen
		// zweiten anlegen - sonst würde der Worker dieselbe Platte zweimal
		// slicen und zweimal in die Bambu-Warteschlange stellen.
		$letzter = SlicingJobRepository::latest_for_plate( $plate_job_id );
		if ( $letzter && SlicingJobRepository::is_open( $letzter->status ) ) {
			$leer['error'] = __( 'Für diese Platte läuft bereits ein Slicing-Auftrag.', 'lindenweg16' );
			return $leer;
		}

		$placements = json_decode( (string) $plate->placements, true );
		$article_id = 0;
		if ( is_array( $placements ) && ! empty( $placements['items'][0]['article_id'] ) ) {
			$article_id = (int) $placements['items'][0]['article_id'];
		}

		$material = '';
		$color    = '';
		if ( $article_id ) {
			$revision = RevisionRepository::active_for_article( $article_id );
			$material = $revision ? strtoupper( (string) $revision['material'] ) : '';
			$color    = $revision ? (string) $revision['color'] : '';
		}

		if ( '' === $material ) {
			$leer['error'] = __( 'Material der Platte konnte nicht ermittelt werden.', 'lindenweg16' );
			return $leer;
		}

		$printer_model = self::printer_model_for( (string) $plate->printer_id );
		$profile       = $printer_model ? SlicingProfileRepository::find_for( $material, $printer_model, $color ) : null;

		if ( ! $profile ) {
			$leer['error'] = sprintf(
				/* translators: 1: Material, 2: Farbe, 3: Druckermodell. */
				__( 'Kein Slicing-Profil für %1$s%2$s auf %3$s hinterlegt.', 'lindenweg16' ),
				$material,
				'' !== $color ? ' (' . $color . ')' : '',
				$printer_model ? $printer_model : __( 'unbekanntes Druckermodell', 'lindenweg16' )
			);
			return $leer;
		}

		$slicing_job_id = SlicingJobRepository::create(
			array(
				'plate_job_id' => $plate_job_id,
				'profile_id'   => (int) $profile->id,
			)
		);

		if ( ! $slicing_job_id ) {
			$leer['error'] = __( 'Slicing-Auftrag konnte nicht angelegt werden.', 'lindenweg16' );
			return $leer;
		}

		PlateJobRepository::set_status( $plate_job_id, 'wird_geslict' );

		AuditLog::record( 'bambu_platte_zum_slicen_eingereiht', 'bambu_plate_job', $plate_job_id, array( 'slicing_job_id' => $slicing_job_id, 'material' => $material ) );

		return array( 'ok' => true, 'slicing_job_id' => $slicing_job_id, 'error' => '' );
	}

	/**
	 * Gemeldetes Druckermodell des Bambu-Druckers dieser Platte, für die
	 * Profilwahl.
	 *
	 * @param string $bambu_printer_id Stabiler Schlüssel des Bambu-Druckers.
	 * @return string
	 */
	protected static function printer_model_for( $bambu_printer_id ) {
		$printer = \Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository::find_by_printer_id( $bambu_printer_id );
		return $printer && $printer->printer_model ? (string) $printer->printer_model : '';
	}

	/**
	 * Ein vom Worker hochgeladenes Slicing-Ergebnis entgegennehmen: Datei
	 * ablegen, Slicing-Auftrag abschliessen und die fertige Druckdatei
	 * automatisch in die Bambu-Warteschlange legen.
	 *
	 * @param int    $slicing_job_id Slicing-Auftrag.
	 * @param string $tmp_path       Temporärer Pfad der hochgeladenen Datei.
	 * @param string $original_name  Ursprünglicher Dateiname.
	 * @return array {ok, error}
	 */
	public static function handle_result_upload( $slicing_job_id, $tmp_path, $original_name ) {
		$job  = SlicingJobRepository::find( $slicing_job_id );
		$leer = array( 'ok' => false, 'error' => '' );

		if ( ! $job ) {
			$leer['error'] = __( 'Slicing-Auftrag nicht gefunden.', 'lindenweg16' );
			return $leer;
		}

		if ( ! is_uploaded_file( $tmp_path ) ) {
			$leer['error'] = __( 'Kein Datei-Upload erkannt.', 'lindenweg16' );
			return $leer;
		}

		Installer::ensure_docs_dir();

		$filename = sprintf( '%s%d-%s.3mf', BambuService::SLICED_PREFIX, time(), wp_generate_password( 6, false, false ) );
		$target   = trailingslashit( Installer::docs_dir() ) . $filename;

		if ( ! move_uploaded_file( $tmp_path, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file
			$leer['error'] = __( 'Datei konnte nicht gespeichert werden.', 'lindenweg16' );
			return $leer;
		}

		$result = SlicingJobRepository::complete( $slicing_job_id, $target, sanitize_file_name( (string) $original_name ) );

		if ( ! $result['ok'] ) {
			wp_delete_file( $target );
			$leer['error'] = __( 'Ungültiger Statuswechsel.', 'lindenweg16' );
			return $leer;
		}

		if ( $result['already'] ) {
			// Bereits abgeschlossen (Wiederholung) - nicht doppelt an die
			// Bambu-Warteschlange weiterreichen.
			wp_delete_file( $target );
			return array( 'ok' => true, 'error' => '' );
		}

		self::forward_to_bambu_queue( (int) $job->plate_job_id, $target, $filename );

		return array( 'ok' => true, 'error' => '' );
	}

	/**
	 * Fertig geslicte Platte in die Bambu-Druckwarteschlange legen.
	 *
	 * @param int    $plate_job_id Platte.
	 * @param string $file_path    Absoluter Pfad der Druckdatei.
	 * @param string $file_name    Dateiname.
	 */
	protected static function forward_to_bambu_queue( $plate_job_id, $file_path, $file_name ) {
		$plate = PlateJobRepository::find( $plate_job_id );

		if ( ! $plate ) {
			return;
		}

		$job_id = BambuJobRepository::create(
			array(
				'printer_id'   => $plate->printer_id,
				'plate_job_id' => $plate_job_id,
				'file_path'    => $file_path,
				'file_name'    => $file_name,
			)
		);

		if ( $job_id ) {
			PlateJobRepository::set_status( $plate_job_id, 'geslict', array( 'bambu_job_id' => $job_id ) );
			AuditLog::record( 'bambu_platte_geslict', 'bambu_plate_job', $plate_job_id, array( 'bambu_job_id' => $job_id ) );
		} else {
			PlateJobRepository::set_status( $plate_job_id, 'fehlgeschlagen' );
		}
	}

	/**
	 * Slicing-Auftrag als fehlgeschlagen markieren und die Platte
	 * entsprechend kennzeichnen.
	 *
	 * @param int    $slicing_job_id Slicing-Auftrag.
	 * @param string $message        Fehlermeldung des Slicers.
	 * @return bool Erfolg (false bei ungültigem Statuswechsel oder unbekanntem Auftrag).
	 */
	public static function handle_failure( $slicing_job_id, $message ) {
		$job = SlicingJobRepository::find( $slicing_job_id );

		if ( ! $job || ! SlicingJobRepository::fail( $slicing_job_id, $message ) ) {
			return false;
		}

		PlateJobRepository::set_status( (int) $job->plate_job_id, 'fehlgeschlagen' );
		AuditLog::record( 'bambu_platte_slicen_fehlgeschlagen', 'bambu_plate_job', (int) $job->plate_job_id, array( 'slicing_job_id' => $slicing_job_id, 'meldung' => $message ) );

		return true;
	}

	/**
	 * Wartung: verwaiste Claims freigeben und alte Aufträge löschen.
	 */
	public static function run_maintenance() {
		$freigegeben = SlicingJobRepository::release_stale_locks();

		if ( $freigegeben > 0 ) {
			AuditLog::record( 'bambu_slicing_zurueckgesetzt', '', 0, array( 'anzahl' => $freigegeben ) );
		}

		SlicingJobRepository::cleanup_retention( (int) Settings::get( 'slicing_aufbewahrung_tage', 90 ) );
	}
}
