<?php
/**
 * Einreihen von 3MF-Druckaufträgen für die Bambu-Lab-Anbindung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Bambu;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Installer;
use Lindenweg16\Core\Settings;
use Lindenweg16\Modules\Betrieb\ModelFile;

/**
 * Verbindet die bereits vorhandene Modellablage (ModelFile, siehe
 * Artikel & Revisionen) mit der Bambu-Warteschlange: Ein Job verweist auf
 * eine schon abgelegte, geslicte Druckdatei (.gcode.3mf) – es gibt keinen
 * zusätzlichen Kopiervorgang, genau wie beim DHL-Label in
 * PrintQueue\PrintQueueService.
 */
class BambuService {

	/**
	 * Geslicte Druckdatei aus der Modellablage in die Warteschlange eines
	 * Bambu-Druckers legen.
	 *
	 * @param string $printer_id      Stabiler Schlüssel des Bambu-Druckers.
	 * @param string $model_filename  Dateiname in der Modellablage (ModelFile::path()).
	 * @param int    $druckauftrag_id Optional: der Fertigungsauftrag, aus dem
	 *                                 der Job entstanden ist – darüber trägt ein
	 *                                 abgeschlossener Druck seine Ist-Druckzeit
	 *                                 automatisch ein (siehe BambuJobRepository::complete()).
	 * @return array {ok, job_id, error}
	 */
	public static function enqueue_model( $printer_id, $model_filename, $druckauftrag_id = 0 ) {
		$printer_id = sanitize_key( (string) $printer_id );

		if ( '' === $printer_id ) {
			return array( 'ok' => false, 'job_id' => 0, 'error' => __( 'Kein Drucker gewählt.', 'lindenweg16' ) );
		}

		if ( ! BambuPrinterRepository::find_by_printer_id( $printer_id ) ) {
			return array( 'ok' => false, 'job_id' => 0, 'error' => __( 'Unbekannter Bambu-Drucker.', 'lindenweg16' ) );
		}

		$path = ModelFile::safe_path( $model_filename );

		if ( '' === $path ) {
			return array( 'ok' => false, 'job_id' => 0, 'error' => __( 'Datei nicht in der Modellablage gefunden.', 'lindenweg16' ) );
		}

		if ( 'druckdatei' !== ModelFile::kind( $path ) ) {
			// Eine reine Projektdatei (nur Geometrie, kein G-Code) kann der
			// Drucker nicht ausführen – sie müsste erst in Bambu Studio
			// geslict werden. Ohne diese Prüfung würde der Job auf dem
			// Drucker als "ungültige Datei" scheitern, aber erst nach dem
			// Upload, statt schon hier verständlich abgewiesen zu werden.
			return array(
				'ok'     => false,
				'job_id' => 0,
				'error'  => __( 'Das ist keine geslicte Druckdatei (.gcode.3mf) – erst in Bambu Studio slicen und die Druckdatei hochladen.', 'lindenweg16' ),
			);
		}

		$job_id = BambuJobRepository::create(
			array(
				'printer_id'      => $printer_id,
				'druckauftrag_id' => $druckauftrag_id ? (int) $druckauftrag_id : 0,
				'file_path'       => $path,
				'file_name'       => basename( $path ),
			)
		);

		if ( $job_id ) {
			AuditLog::record(
				'bambu_druckjob_angelegt',
				'bambu_job',
				$job_id,
				array( 'drucker' => $printer_id, 'datei' => basename( $path ), 'druckauftrag_id' => (int) $druckauftrag_id )
			);
		}

		return array(
			'ok'     => (bool) $job_id,
			'job_id' => $job_id,
			'error'  => $job_id ? '' : __( 'Job konnte nicht angelegt werden.', 'lindenweg16' ),
		);
	}

	/**
	 * Eine direkt hochgeladene geslicte Druckdatei (.gcode.3mf) in die
	 * Warteschlange eines Bambu-Druckers legen – für den schnellen
	 * Einzeldruck, ohne dass die Datei erst in die dauerhafte Modellablage
	 * (ModelFile, siehe Artikel & Revisionen) übernommen werden muss. Die
	 * Datei wird einmalig ins geschützte Dokumentenverzeichnis kopiert,
	 * genau wie bei einem Etiketten-Testlabel.
	 *
	 * @param string $printer_id Stabiler Schlüssel des Bambu-Druckers.
	 * @param string $tmp_path   Temporärer Pfad der hochgeladenen Datei
	 *                            (z. B. $_FILES[...]['tmp_name']).
	 * @param string $original_filename Ursprünglicher Dateiname, nur zur Anzeige.
	 * @return array {ok, job_id, error}
	 */
	public static function enqueue_upload( $printer_id, $tmp_path, $original_filename ) {
		$printer_id = sanitize_key( (string) $printer_id );

		if ( '' === $printer_id ) {
			return array( 'ok' => false, 'job_id' => 0, 'error' => __( 'Kein Drucker gewählt.', 'lindenweg16' ) );
		}

		if ( ! BambuPrinterRepository::find_by_printer_id( $printer_id ) ) {
			return array( 'ok' => false, 'job_id' => 0, 'error' => __( 'Unbekannter Bambu-Drucker.', 'lindenweg16' ) );
		}

		if ( ! is_uploaded_file( $tmp_path ) ) {
			return array( 'ok' => false, 'job_id' => 0, 'error' => __( 'Kein Datei-Upload erkannt.', 'lindenweg16' ) );
		}

		if ( '3mf' !== strtolower( pathinfo( (string) $original_filename, PATHINFO_EXTENSION ) ) ) {
			return array( 'ok' => false, 'job_id' => 0, 'error' => __( 'Nur .3mf-Dateien (geslicte Druckdatei aus Bambu Studio).', 'lindenweg16' ) );
		}

		Installer::ensure_docs_dir();

		$filename = sprintf( 'bambu-upload-%d-%s.3mf', time(), wp_generate_password( 6, false, false ) );
		$pfad     = trailingslashit( Installer::docs_dir() ) . $filename;

		if ( ! move_uploaded_file( $tmp_path, $pfad ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file
			return array( 'ok' => false, 'job_id' => 0, 'error' => __( 'Datei konnte nicht gespeichert werden.', 'lindenweg16' ) );
		}

		if ( 'druckdatei' !== ModelFile::kind( $pfad ) ) {
			wp_delete_file( $pfad );
			return array(
				'ok'     => false,
				'job_id' => 0,
				'error'  => __( 'Das ist keine geslicte Druckdatei (.gcode.3mf) – erst in Bambu Studio slicen und die Druckdatei hochladen.', 'lindenweg16' ),
			);
		}

		$job_id = BambuJobRepository::create(
			array(
				'printer_id' => $printer_id,
				'file_path'  => $pfad,
				'file_name'  => sanitize_file_name( (string) $original_filename ),
			)
		);

		if ( $job_id ) {
			AuditLog::record( 'bambu_druckjob_angelegt', 'bambu_job', $job_id, array( 'drucker' => $printer_id, 'datei' => $original_filename ) );
		}

		return array(
			'ok'     => (bool) $job_id,
			'job_id' => $job_id,
			'error'  => $job_id ? '' : __( 'Job konnte nicht angelegt werden.', 'lindenweg16' ),
		);
	}

	/**
	 * Wartung: verwaiste Claims freigeben und alte Job-Einträge löschen.
	 * Aufgerufen aus dem wiederkehrenden Hintergrundjob
	 * (Scheduler::HOOK_BAMBU_MAINTENANCE).
	 */
	public static function run_maintenance() {
		$freigegeben = BambuJobRepository::release_stale_locks();

		if ( $freigegeben > 0 ) {
			AuditLog::record( 'bambu_druckjob_zurueckgesetzt', '', 0, array( 'anzahl' => $freigegeben ) );
		}

		BambuJobRepository::cleanup_retention( (int) Settings::get( 'bambu_aufbewahrung_tage', 90 ) );
	}
}
