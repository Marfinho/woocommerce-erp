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
use Lindenweg16\Modules\Betrieb\Nesting\PlateJobRepository;

/**
 * Verbindet die bereits vorhandene Modellablage (ModelFile, siehe
 * Artikel & Revisionen) mit der Bambu-Warteschlange: Ein Job verweist auf
 * eine schon abgelegte, geslicte Druckdatei (.gcode.3mf) – es gibt keinen
 * zusätzlichen Kopiervorgang, genau wie beim DHL-Label in
 * PrintQueue\PrintQueueService.
 */
class BambuService {

	/**
	 * Höchstgröße einer 3MF-Druckdatei. Großzügig bemessen – ein
	 * 3MF-Projekt kann eingebettete Vorschaubilder enthalten und ist
	 * deutlich größer als ein reines G-Code-Etikett.
	 *
	 * Die Grenze gilt an beiden Enden: beim Einreihen (hier) und beim
	 * Ausliefern an die Bridge (BambuRestApi). Ohne die Prüfung hier
	 * ließe sich eine zu große Datei einreihen, die anschließend dauerhaft
	 * nicht auslieferbar wäre – der Job bliebe in einer endlosen
	 * Wiederholschleife hängen.
	 */
	const MAX_FILE_BYTES = 200 * MB_IN_BYTES;

	/**
	 * Präfix der direkt hochgeladenen Druckdateien im Dokumentenordner.
	 * Nur Dateien mit diesem Präfix gehören der Warteschlange und dürfen
	 * beim Aufräumen gelöscht werden – Dateien der Modellablage gehören
	 * den Artikelrevisionen und bleiben unangetastet.
	 */
	const UPLOAD_PREFIX = 'bambu-upload-';

	/**
	 * Präfix der vom Slicing-Worker gelieferten Druckdateien (siehe
	 * SlicingService). Auch sie gehören allein dem Bambu-Job und vergehen
	 * mit ihm – bisher blieben sie für immer liegen.
	 */
	const SLICED_PREFIX = 'bambu-sliced-';

	/**
	 * Geslicte Druckdatei aus der Modellablage in die Warteschlange eines
	 * Bambu-Druckers legen – ein Einzeldruck ohne Buchung (Muster, Ersatz,
	 * privat). Was ins Lager soll, läuft über den Workflow.
	 *
	 * @param string   $printer_id     Stabiler Schlüssel des Bambu-Druckers.
	 * @param string   $model_filename Dateiname in der Modellablage (ModelFile::path()).
	 * @param int|null $ams_tray_id     Optional: gewähltes AMS-Fach für den
	 *                                   gesamten Druckauftrag - null lässt den
	 *                                   Drucker beim gerade eingelegten/externen
	 *                                   Filament (wie bisher).
	 * @return array {ok, job_id, error}
	 */
	public static function enqueue_model( $printer_id, $model_filename, $ams_tray_id = null ) {
		$printer_id = sanitize_key( (string) $printer_id );

		if ( '' === $printer_id ) {
			return array( 'ok' => false, 'job_id' => 0, 'error' => __( 'Kein Drucker gewählt.', 'lindenweg16' ) );
		}

		$printer_error = self::printer_error( $printer_id );
		if ( '' !== $printer_error ) {
			return array( 'ok' => false, 'job_id' => 0, 'error' => $printer_error );
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
				'printer_id'  => $printer_id,
				'ams_tray_id' => $ams_tray_id,
				'file_path'   => $path,
				'file_name'   => basename( $path ),
			)
		);

		if ( $job_id ) {
			AuditLog::record(
				'bambu_druckjob_angelegt',
				'bambu_job',
				$job_id,
				array( 'drucker' => $printer_id, 'datei' => basename( $path ) )
			);
		}

		return array(
			'ok'     => (bool) $job_id,
			'job_id' => $job_id,
			'error'  => $job_id ? '' : __( 'Job konnte nicht angelegt werden.', 'lindenweg16' ),
		);
	}

	/**
	 * Darf auf diesem Drucker eingereiht werden? Ein deaktivierter Drucker
	 * wird von seiner Bridge nicht mehr abgefragt (sein Token gilt nicht) –
	 * ein Job bliebe dort für immer liegen.
	 *
	 * @param string $printer_id Stabiler Schlüssel.
	 * @return string Fehlermeldung oder leer.
	 */
	protected static function printer_error( $printer_id ) {
		$printer = BambuPrinterRepository::find_by_printer_id( $printer_id );

		if ( ! $printer ) {
			return __( 'Unbekannter Bambu-Drucker.', 'lindenweg16' );
		}

		if ( ! (int) $printer->active ) {
			return __( 'Dieser Bambu-Drucker ist deaktiviert – erst wieder aktivieren, dann einreihen.', 'lindenweg16' );
		}

		return '';
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
	 * @param string   $original_filename Ursprünglicher Dateiname, nur zur Anzeige.
	 * @param int|null $ams_tray_id       Optional: gewähltes AMS-Fach für den
	 *                                     gesamten Druckauftrag.
	 * @return array {ok, job_id, error}
	 */
	public static function enqueue_upload( $printer_id, $tmp_path, $original_filename, $ams_tray_id = null ) {
		$printer_id = sanitize_key( (string) $printer_id );

		if ( '' === $printer_id ) {
			return array( 'ok' => false, 'job_id' => 0, 'error' => __( 'Kein Drucker gewählt.', 'lindenweg16' ) );
		}

		$printer_error = self::printer_error( $printer_id );
		if ( '' !== $printer_error ) {
			return array( 'ok' => false, 'job_id' => 0, 'error' => $printer_error );
		}

		if ( ! is_uploaded_file( $tmp_path ) ) {
			return array( 'ok' => false, 'job_id' => 0, 'error' => __( 'Kein Datei-Upload erkannt.', 'lindenweg16' ) );
		}

		if ( '3mf' !== strtolower( pathinfo( (string) $original_filename, PATHINFO_EXTENSION ) ) ) {
			return array( 'ok' => false, 'job_id' => 0, 'error' => __( 'Nur .3mf-Dateien (geslicte Druckdatei aus Bambu Studio).', 'lindenweg16' ) );
		}

		$groesse = filesize( $tmp_path );

		if ( false === $groesse || $groesse <= 0 ) {
			return array( 'ok' => false, 'job_id' => 0, 'error' => __( 'Die hochgeladene Datei ist leer.', 'lindenweg16' ) );
		}

		if ( $groesse > self::MAX_FILE_BYTES ) {
			return array(
				'ok'     => false,
				'job_id' => 0,
				'error'  => sprintf(
					/* translators: %s: Höchstgröße, z. B. "200 MB". */
					__( 'Die Druckdatei ist zu groß (höchstens %s).', 'lindenweg16' ),
					size_format( self::MAX_FILE_BYTES )
				),
			);
		}

		Installer::ensure_docs_dir();

		$filename = sprintf( '%s%d-%s.3mf', self::UPLOAD_PREFIX, time(), wp_generate_password( 6, false, false ) );
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
				'printer_id'  => $printer_id,
				'ams_tray_id' => $ams_tray_id,
				'file_path'   => $pfad,
				'file_name'   => sanitize_file_name( (string) $original_filename ),
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
	 * Fehlgeschlagenen oder stornierten Job mit derselben Druckdatei erneut
	 * einreihen. Der neue Job übernimmt alle Verknüpfungen (Platte,
	 * Fertigungsauftrag, AMS-Fach); eine Platte zeigt danach auf den neuen
	 * Job, damit die Werkstatt ihm folgt.
	 *
	 * @param int $job_id Alter Job.
	 * @return array {ok, job_id, error}
	 */
	public static function requeue_job( $job_id ) {
		$job  = BambuJobRepository::find( (int) $job_id );
		$leer = array( 'ok' => false, 'job_id' => 0, 'error' => '' );

		if ( ! $job ) {
			$leer['error'] = __( 'Diesen Druckauftrag gibt es nicht (mehr).', 'lindenweg16' );
			return $leer;
		}

		if ( ! BambuJobRepository::can_requeue( $job->status ) ) {
			$leer['error'] = __( 'Nur fehlgeschlagene oder stornierte Aufträge lassen sich erneut einreihen.', 'lindenweg16' );
			return $leer;
		}

		$printer = BambuPrinterRepository::find_by_printer_id( $job->printer_id );
		if ( ! $printer || ! (int) $printer->active ) {
			$leer['error'] = __( 'Der Drucker dieses Auftrags ist nicht (mehr) aktiv.', 'lindenweg16' );
			return $leer;
		}

		if ( ! is_file( (string) $job->file_path ) ) {
			$leer['error'] = __( 'Die Druckdatei ist nicht mehr vorhanden – bitte neu hochladen bzw. neu slicen.', 'lindenweg16' );
			return $leer;
		}

		$new_id = BambuJobRepository::create(
			array(
				'printer_id'          => $job->printer_id,
				'druckauftrag_id'     => $job->druckauftrag_id,
				'work_order_id'       => $job->work_order_id,
				'article_revision_id' => $job->article_revision_id,
				'parts_planned'       => $job->parts_planned,
				'plate_job_id'        => $job->plate_job_id,
				'ams_tray_id'         => $job->ams_tray_id,
				'file_path'           => $job->file_path,
				'file_name'           => $job->file_name,
			)
		);

		if ( ! $new_id ) {
			$leer['error'] = __( 'Job konnte nicht angelegt werden.', 'lindenweg16' );
			return $leer;
		}

		if ( $job->plate_job_id ) {
			PlateJobRepository::set_status( (int) $job->plate_job_id, 'geslict', array( 'bambu_job_id' => $new_id ) );
		}

		AuditLog::record( 'bambu_druckjob_erneut_eingereiht', 'bambu_job', $new_id, array( 'vorher' => (int) $job->id, 'drucker' => $job->printer_id ) );

		return array( 'ok' => true, 'job_id' => $new_id, 'error' => '' );
	}

	/**
	 * Geslicte Druckdateien der aktiven Artikelrevisionen – die Auswahl
	 * für „aus der Modellablage einreihen".
	 *
	 * Nur Revisionen mit model_kind = 'druckdatei': eine reine
	 * Projektdatei (Geometrie ohne G-Code) kann der Drucker nicht
	 * ausführen (siehe enqueue_model()).
	 *
	 * @return array Liste aus {file, label}.
	 */
	public static function verfuegbare_druckdateien() {
		global $wpdb;

		$p = $wpdb->prefix . 'lw16_';

		$rows = $wpdb->get_results(
			"SELECT r.model_file, r.version, a.sku, a.name
			   FROM {$p}article_revisions r
			   JOIN {$p}articles a ON a.id = r.article_id
			  WHERE r.is_active = 1
			    AND r.model_kind = 'druckdatei'
			    AND r.model_file IS NOT NULL
			    AND r.model_file <> ''
			  ORDER BY a.sku ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$dateien = array();

		foreach ( (array) $rows as $row ) {
			$dateien[] = array(
				'file'  => (string) $row['model_file'],
				'label' => sprintf(
					'%s — %s (%s)',
					(string) $row['sku'],
					(string) $row['name'],
					(string) $row['version']
				),
			);
		}

		return $dateien;
	}

	/**
	 * Die zu einem Job gehörende Datei löschen – aber nur, wenn sie der
	 * Warteschlange gehört.
	 *
	 * Ein Job kann auf zwei verschiedene Dateien zeigen: auf eine eigens
	 * hochgeladene Kopie im Dokumentenordner (die mit dem Job vergeht) –
	 * oder auf eine Datei der Modellablage, die einer Artikelrevision
	 * gehört und weiterlebt. Deshalb wird hier doppelt geprüft: die Datei
	 * muss im Dokumentenordner liegen UND den Upload-Präfix tragen.
	 *
	 * Verweist noch ein anderer Job auf dieselbe Datei (erneut eingereiht),
	 * bleibt sie liegen.
	 *
	 * @param string $file_path Pfad aus dem Job-Datensatz.
	 * @param int    $job_id    Der Job, dessen Datei gelöscht werden soll (wird nicht mitgezählt).
	 * @return bool Ob gelöscht wurde.
	 */
	public static function delete_job_file( $file_path, $job_id = 0 ) {
		$real = realpath( (string) $file_path );

		if ( ! $real || ! self::is_queue_upload( $real, Installer::docs_dir() ) ) {
			return false;
		}

		if ( BambuJobRepository::count_by_file( (string) $file_path, (int) $job_id ) > 0 ) {
			return false;
		}

		wp_delete_file( $real );

		return true;
	}

	/**
	 * Gehört diese Datei der Warteschlange – darf sie also mit dem Job
	 * vergehen?
	 *
	 * Reine Pfadentscheidung, ohne WordPress, damit sie prüfbar ist: Die
	 * Frage entscheidet über einen Löschvorgang, und ein Fehler hier
	 * würde Konstruktionsdaten der Artikel vernichten. Deshalb müssen
	 * beide Bedingungen zutreffen – die Datei muss nach Auflösung aller
	 * Symlinks (realpath) unterhalb des Dokumentenordners liegen UND den
	 * Upload-Präfix tragen.
	 *
	 * @param string $file_path Pfad aus dem Job-Datensatz.
	 * @param string $docs_dir  Dokumentenverzeichnis.
	 * @return bool
	 */
	public static function is_queue_upload( $file_path, $docs_dir ) {
		$file_path = (string) $file_path;

		if ( '' === $file_path ) {
			return false;
		}

		$real = realpath( $file_path );
		$docs = realpath( (string) $docs_dir );

		if ( ! $real || ! $docs || ! is_file( $real ) ) {
			return false;
		}

		if ( 0 !== strpos( $real, rtrim( $docs, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
			return false;
		}

		$name = basename( $real );

		return 0 === strpos( $name, self::UPLOAD_PREFIX ) || 0 === strpos( $name, self::SLICED_PREFIX );
	}

	/**
	 * Präfix der Kamera-Standbilder im Dokumentenordner - wie
	 * UPLOAD_PREFIX nur zur Abgrenzung beim Aufräumen/Zugriff, hier gibt
	 * es aber ohnehin nur eine Datei je Drucker (wird bei jedem neuen
	 * Standbild überschrieben statt ergänzt).
	 */
	const SNAPSHOT_PREFIX = 'bambu-snapshot-';

	/**
	 * Aktuelles Kamera-Standbild eines Druckers speichern - überschreibt
	 * ein evtl. vorhandenes älteres Bild, es wird kein Verlauf gehalten.
	 *
	 * @param string $printer_id Stabiler Schlüssel des Bambu-Druckers.
	 * @param string $jpeg_bytes Rohe JPEG-Daten.
	 * @return bool
	 */
	public static function save_snapshot( $printer_id, $jpeg_bytes ) {
		$printer_id = sanitize_key( (string) $printer_id );

		if ( '' === $printer_id ) {
			return false;
		}

		Installer::ensure_docs_dir();

		$pfad = self::snapshot_path( $printer_id );
		$tmp  = $pfad . '.tmp';

		// Erst in eine temporäre Datei schreiben, dann umbenennen: rename()
		// ist atomar - ein Seitenaufruf mitten im Schreibvorgang bekommt so
		// nie ein halb geschriebenes JPEG zu sehen.
		if ( false === file_put_contents( $tmp, $jpeg_bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return false;
		}

		return rename( $tmp, $pfad ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename
	}

	/**
	 * Pfad zum Kamera-Standbild eines Druckers - reine Pfadbildung, ohne
	 * Dateisystemzugriff, damit sie sowohl beim Schreiben (hier) als auch
	 * beim Ausliefern (BambuPrintersPage::handle_snapshot()) dieselbe
	 * Logik verwenden.
	 *
	 * @param string $printer_id Bereits validierter (sanitize_key) Schlüssel.
	 * @return string
	 */
	public static function snapshot_path( $printer_id ) {
		return trailingslashit( Installer::docs_dir() ) . self::SNAPSHOT_PREFIX . $printer_id . '.jpg';
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

		if ( class_exists( \Lindenweg16\Erp\RunRepository::class ) ) {
			PlateJobRepository::cleanup_drafts( \Lindenweg16\Erp\RunRepository::table(), Installer::docs_dir() );
		}
	}
}
