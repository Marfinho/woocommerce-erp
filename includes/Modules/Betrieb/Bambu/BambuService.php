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
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\ModelFile;
use Lindenweg16\Modules\Betrieb\PrinterRepository;
use Lindenweg16\Modules\Betrieb\RevisionRepository;
use Lindenweg16\Modules\Betrieb\WorkOrderRepository;

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
	 * @param string $file_path Pfad aus dem Job-Datensatz.
	 * @return bool Ob gelöscht wurde.
	 */
	public static function delete_job_file( $file_path ) {
		$real = realpath( (string) $file_path );

		if ( ! $real || ! self::is_queue_upload( $real, Installer::docs_dir() ) ) {
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

		return 0 === strpos( basename( $real ), self::UPLOAD_PREFIX );
	}

	/**
	 * "Bedarf direkt drucken": Aus dem offenen Bedarf eines Artikels heraus
	 * unmittelbar Platten in die Bambu-Warteschlange einreihen, ohne den
	 * Umweg über einen manuell angelegten Fertigungsauftrag samt manueller
	 * Druckjournal-Buchung.
	 *
	 * Setzt voraus, dass für den Artikel bereits eine geslicte Druckdatei
	 * hinterlegt ist (aktive Revision, model_kind = druckdatei) – ist die
	 * benötigte Menge kein glattes Vielfaches von parts_per_plate oder
	 * sollen mehrere Artikel gemeinsam auf eine Platte, ist die
	 * Plattenanordnung (Nesting\PlateService) der richtige Weg statt
	 * dieser Methode.
	 *
	 * Legt je Platte einen eigenen Fertigungsauftrag (WorkOrderRepository,
	 * kind=druck) und einen eigenen Bambu-Job an, damit ein einzelner
	 * fehlgeschlagener Druck nicht die ganze angeforderte Menge blockiert
	 * und die Ist-Druckzeit je Platte sauber zugeordnet bleibt. Ist ein
	 * Druck fertig, bucht BambuJobRepository::complete() automatisch das
	 * Druckjournal und schliesst den zugehörigen Fertigungsauftrag.
	 *
	 * @param int    $article_id       Artikel.
	 * @param string $bambu_printer_id Stabiler Schlüssel des Bambu-Druckers.
	 * @param float  $qty              Gewünschte Menge (wird auf ganze Platten aufgerundet).
	 * @return array {ok, plates, work_order_ids, job_ids, error}
	 */
	public static function dispatch_from_demand( $article_id, $bambu_printer_id, $qty ) {
		$article_id       = (int) $article_id;
		$bambu_printer_id = sanitize_key( (string) $bambu_printer_id );
		$qty              = max( 0.0, (float) $qty );

		$leer = array( 'ok' => false, 'plates' => 0, 'work_order_ids' => array(), 'job_ids' => array(), 'error' => '' );

		if ( ! $article_id || $qty <= 0 ) {
			$leer['error'] = __( 'Bitte einen Artikel und eine Menge größer 0 angeben.', 'lindenweg16' );
			return $leer;
		}

		$printer = BambuPrinterRepository::find_by_printer_id( $bambu_printer_id );
		if ( ! $printer ) {
			$leer['error'] = __( 'Unbekannter Bambu-Drucker.', 'lindenweg16' );
			return $leer;
		}

		if ( ! $printer->geraet_id || ! PrinterRepository::find( (int) $printer->geraet_id ) ) {
			$leer['error'] = __( 'Dieser Bambu-Drucker ist noch nicht mit einem Gerät der Kalkulation verknüpft – bitte unter „Bambu-Lab-Drucker" → Drucker verbinden.', 'lindenweg16' );
			return $leer;
		}

		$article = ArticleRepository::find( $article_id );
		if ( ! $article ) {
			$leer['error'] = __( 'Artikel nicht gefunden.', 'lindenweg16' );
			return $leer;
		}

		$revision = RevisionRepository::active_for_article( $article_id );
		if ( ! $revision ) {
			$leer['error'] = __( 'Für diesen Artikel ist keine Revision freigegeben.', 'lindenweg16' );
			return $leer;
		}

		if ( empty( $revision['model_file'] ) || 'druckdatei' !== $revision['model_kind'] ) {
			$leer['error'] = __( 'Für die aktive Revision liegt keine geslicte Druckdatei vor – entweder in Bambu Studio slicen und bei „Artikel & Revisionen" hochladen, oder die Plattenanordnung nutzen.', 'lindenweg16' );
			return $leer;
		}

		$path = ModelFile::safe_path( $revision['model_file'] );
		if ( '' === $path ) {
			$leer['error'] = __( 'Die hinterlegte Druckdatei ist nicht mehr auffindbar.', 'lindenweg16' );
			return $leer;
		}

		$per_plate = max( 1, (int) $revision['parts_per_plate'] );
		$plates    = (int) ceil( $qty / $per_plate );
		$rest      = $qty;

		$work_order_ids = array();
		$job_ids        = array();

		for ( $i = 0; $i < $plates; $i++ ) {
			$plate_qty = min( $rest, $per_plate );
			$rest     -= $plate_qty;

			$wo_result = WorkOrderRepository::validate(
				array(
					'kind'        => 'druck',
					'printer_id'  => (int) $printer->geraet_id,
					'article_id'  => $article_id,
					'qty_planned' => $plate_qty,
				)
			);

			if ( $wo_result['errors'] ) {
				continue;
			}

			$work_order_id = WorkOrderRepository::insert( $wo_result['data'] );
			if ( ! $work_order_id ) {
				continue;
			}

			$job_id = BambuJobRepository::create(
				array(
					'printer_id'          => $bambu_printer_id,
					'work_order_id'       => $work_order_id,
					'article_revision_id' => (int) $revision['id'],
					'parts_planned'       => (int) $plate_qty,
					'file_path'           => $path,
					'file_name'           => basename( $path ),
				)
			);

			if ( ! $job_id ) {
				continue;
			}

			$work_order_ids[] = $work_order_id;
			$job_ids[]        = $job_id;

			AuditLog::record(
				'bambu_druckjob_aus_bedarf_angelegt',
				'bambu_job',
				$job_id,
				array( 'drucker' => $bambu_printer_id, 'artikel_id' => $article_id, 'work_order_id' => $work_order_id, 'menge' => $plate_qty )
			);
		}

		if ( ! $job_ids ) {
			$leer['error'] = __( 'Es konnte kein Druckauftrag angelegt werden.', 'lindenweg16' );
			return $leer;
		}

		return array(
			'ok'             => true,
			'plates'         => count( $job_ids ),
			'work_order_ids' => $work_order_ids,
			'job_ids'        => $job_ids,
			'error'          => '',
		);
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
	}
}
