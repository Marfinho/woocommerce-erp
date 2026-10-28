<?php
/**
 * Druckaufträge (3MF-Dateien) für die Bambu-Lab-Anbindung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Bambu;

defined( 'ABSPATH' ) || exit;


/**
 * Verwaltet die Warteschlange der 3MF-Druckaufträge: anlegen, abholen,
 * claimen, Statuswechsel, Live-Fortschritt.
 *
 * Dasselbe Claim-Muster wie bei den Etikettendruckern (siehe
 * PrintQueue\PrintJobRepository): Der Claim ist ein einziges bedingtes
 * UPDATE (WHERE status = 'queued'), damit zwei Bridges nie denselben Job
 * gewinnen können.
 */
class BambuJobRepository {

	/**
	 * Gültige Status, in der Reihenfolge des Normalablaufs. "uploading"
	 * steht zwischen "claimed" und "printing", weil die 3MF-Datei erst per
	 * FTPS auf den Drucker muss, bevor der eigentliche Druck beginnt – ein
	 * eigener Zustand, damit im Admin sichtbar ist, woran ein hängender
	 * Job gerade scheitert.
	 *
	 * "unklar" heißt: Der Job war schon auf dem Drucker, aber niemand weiß
	 * sicher, wie er ausging (Bridge lange ohne Lebenszeichen, Drucker nach
	 * einem Neustart der Bridge im Leerlauf). Ein solcher Job sperrt die
	 * Warteschlange seines Druckers, bis die Bridge ihn doch noch abschließt
	 * oder jemand ihn von Hand klärt (resolve_manually()) – automatisch
	 * erneut drucken wäre gefährlich: die Teile liegen womöglich schon fertig
	 * auf der Platte.
	 */
	const STATUSES = array( 'queued', 'claimed', 'uploading', 'printing', 'unklar', 'completed', 'failed', 'cancelled' );

	/**
	 * Status, in denen ein Job seinen Drucker belegt.
	 */
	const ACTIVE_STATUSES = array( 'claimed', 'uploading', 'printing', 'unklar' );

	/**
	 * Nach so vielen Minuten ohne Aktivität gilt ein Claim als verwaist.
	 * Deutlich länger als bei Etiketten, da ein 3D-Druck Stunden dauern
	 * kann. Das Lease erneuern die Fortschrittsmeldungen der Bridge (die
	 * auch ohne Prozent-Änderung mindestens alle paar Minuten kommen) und
	 * jeder Status-Heartbeat, der einen laufenden Druck meldet (siehe
	 * touch_active_lease()).
	 */
	const LOCK_TIMEOUT_MINUTES = 180;

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_bambu_jobs';
	}

	/* -----------------------------------------------------------------
	 * Reine Logik – ohne Datenbank, deshalb ohne WordPress testbar.
	 * ------------------------------------------------------------- */

	/**
	 * Ist ein Lock (locked_at) abgelaufen?
	 *
	 * @param string|null $locked_at MySQL-Datetime (GMT) oder leer.
	 * @param int         $now       Aktueller Unix-Zeitstempel (GMT).
	 * @param int         $timeout_minutes Lease-Dauer in Minuten.
	 * @return bool
	 */
	public static function is_lock_expired( $locked_at, $now, $timeout_minutes = self::LOCK_TIMEOUT_MINUTES ) {
		if ( empty( $locked_at ) ) {
			return true;
		}

		$locked = strtotime( $locked_at . ' UTC' );

		if ( ! $locked ) {
			return true;
		}

		return ( (int) $now - $locked ) > ( (int) $timeout_minutes * 60 );
	}

	/**
	 * Wartezeit eines Long-Poll-Requests serverseitig begrenzen.
	 *
	 * @param mixed $requested Angeforderte Sekunden.
	 * @param int   $min       Untergrenze.
	 * @param int   $max       Obergrenze.
	 * @param int   $default   Rückfallwert ohne Angabe.
	 * @return int
	 */
	public static function clamp_wait( $requested, $min = 5, $max = 30, $default = 25 ) {
		if ( '' === $requested || null === $requested ) {
			return $default;
		}

		$wert = (int) $requested;

		// Explizit angefordertes wait=0 heißt "sofort antworten, nicht
		// serverseitig warten" - das braucht klassisches Intervall-Polling
		// (siehe BambuRestApi::get_jobs()): Dort übernimmt die Bridge die
		// Pause zwischen zwei Anfragen selbst, statt einen PHP-Worker samt
		// Datenbankverbindung minutenlang in einer Warteschleife zu binden.
		// "Kein wait angegeben" (leer/null, oben abgefangen) bleibt davon
		// unberührt und ergibt weiterhin den Long-Poll-Default.
		if ( 0 === $wert ) {
			return 0;
		}

		if ( $wert < 0 ) {
			return $default;
		}

		return max( (int) $min, min( (int) $max, $wert ) );
	}

	/**
	 * Gehört der vom Drucker gemeldete Dateiname zu diesem Job? Die Bridge
	 * legt jede Druckdatei als „lw16-<Job-ID>-<Name>“ auf den Drucker und
	 * startet sie unter diesem Namen – daran ist der Job eindeutig zu
	 * erkennen, unabhängig vom übrigen Dateinamen.
	 *
	 * @param string $reported_file Dateiname laut Drucker (mit oder ohne Pfad/Endung).
	 * @param int    $job_id        Job-ID.
	 * @return bool
	 */
	public static function file_belongs_to_job( $reported_file, $job_id ) {
		$name = strtolower( trim( basename( str_replace( '\\', '/', (string) $reported_file ) ) ) );

		return (int) $job_id > 0 && 0 === strpos( $name, 'lw16-' . (int) $job_id . '-' );
	}

	/**
	 * Wohin ein Job mit abgelaufenem Lease wechselt.
	 *
	 * Nur ein Job, der den Drucker sicher noch nicht erreicht hat, darf
	 * zurück in die Warteschlange. Ein Job im Druck – oder einer, dessen
	 * Datei der Drucker gerade meldet – wird „unklar“: Ein erneuter Druck
	 * landete sonst auf den womöglich schon fertigen Teilen, und das spätere
	 * „fertig“ der Bridge ginge verloren.
	 *
	 * @param string $status        Aktueller Status.
	 * @param string $reported_file Dateiname laut Drucker.
	 * @param int    $job_id        Job-ID.
	 * @return string queued, unklar oder der unveränderte Status.
	 */
	public static function stale_target( $status, $reported_file, $job_id ) {
		$status = (string) $status;

		if ( 'printing' === $status ) {
			return 'unklar';
		}

		if ( in_array( $status, array( 'claimed', 'uploading' ), true ) ) {
			return self::file_belongs_to_job( $reported_file, $job_id ) ? 'unklar' : 'queued';
		}

		return $status;
	}

	/**
	 * Darf ein Job aus dem gegebenen Ausgangsstatus geclaimt werden?
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_claim( $status ) {
		return 'queued' === (string) $status;
	}

	/**
	 * Darf aus dem gegebenen Status nach "uploading" gewechselt werden?
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_start_uploading( $status ) {
		return in_array( (string) $status, array( 'claimed', 'uploading' ), true );
	}

	/**
	 * Darf aus dem gegebenen Status nach "printing" gewechselt werden?
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_start_printing( $status ) {
		return in_array( (string) $status, self::ACTIVE_STATUSES, true );
	}

	/**
	 * Darf aus dem gegebenen Status abgeschlossen ("completed") werden?
	 * "completed" selbst ist erlaubt (Idempotenz).
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_complete( $status ) {
		return in_array( (string) $status, array( 'claimed', 'uploading', 'printing', 'unklar', 'completed' ), true );
	}

	/**
	 * Darf aus dem gegebenen Status als "failed" markiert werden? Ein
	 * bereits fertiger Druck darf nicht nachträglich als fehlgeschlagen
	 * gelten.
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_fail( $status ) {
		return in_array( (string) $status, array( 'claimed', 'uploading', 'printing', 'unklar', 'failed' ), true );
	}

	/**
	 * Darf ein Job als „unklar“ gemeldet werden?
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_mark_unclear( $status ) {
		return in_array( (string) $status, self::ACTIVE_STATUSES, true );
	}

	/**
	 * Darf ein Job erneut eingereiht werden? Nur ein fehlgeschlagener oder
	 * stornierter – ein fertiger würde doppelt drucken, ein aktiver läuft noch.
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_requeue( $status ) {
		return in_array( (string) $status, array( 'failed', 'cancelled' ), true );
	}

	/**
	 * Ist-Druckzeit in Minuten aus Start- und Endzeitpunkt (GMT-Datetime).
	 *
	 * @param string $started_at   GMT-Datetime.
	 * @param string $completed_at GMT-Datetime.
	 * @return float|null
	 */
	public static function actual_minutes( $started_at, $completed_at ) {
		if ( empty( $started_at ) || empty( $completed_at ) ) {
			return null;
		}

		$start = strtotime( $started_at . ' UTC' );
		$ende  = strtotime( $completed_at . ' UTC' );

		if ( ! $start || ! $ende || $ende < $start ) {
			return null;
		}

		return round( ( $ende - $start ) / 60, 2 );
	}

	/* -----------------------------------------------------------------
	 * Datenbank
	 * ------------------------------------------------------------- */

	/**
	 * Job anlegen (status = queued).
	 *
	 * @param array $data {printer_id, druckauftrag_id, work_order_id,
	 *                      article_revision_id, parts_planned, plate_job_id,
	 *                      ams_tray_id, file_path, file_name}
	 * @return int Neue ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'printer_id'          => sanitize_key( (string) $data['printer_id'] ),
				'druckauftrag_id'     => isset( $data['druckauftrag_id'] ) && $data['druckauftrag_id'] ? (int) $data['druckauftrag_id'] : null,
				'work_order_id'       => isset( $data['work_order_id'] ) && $data['work_order_id'] ? (int) $data['work_order_id'] : null,
				'article_revision_id' => isset( $data['article_revision_id'] ) && $data['article_revision_id'] ? (int) $data['article_revision_id'] : null,
				'parts_planned'       => isset( $data['parts_planned'] ) && $data['parts_planned'] ? (int) $data['parts_planned'] : null,
				'plate_job_id'        => isset( $data['plate_job_id'] ) && $data['plate_job_id'] ? (int) $data['plate_job_id'] : null,
				// null = kein AMS-Fach gewählt, der Drucker nutzt beim Start
				// das gerade eingelegte/externe Filament (siehe send_print_
				// command() auf der Bridge-Seite) - 0 ist ein gültiges
				// Fach (das erste), deshalb ausdrücklich auf '' statt auf
				// falsy prüfen.
				'ams_tray_id'         => isset( $data['ams_tray_id'] ) && '' !== $data['ams_tray_id'] && null !== $data['ams_tray_id']
					? max( 0, min( 255, (int) $data['ams_tray_id'] ) )
					: null,
				'file_path'           => (string) $data['file_path'],
				'file_name'           => (string) $data['file_name'],
				'status'              => 'queued',
				'attempts'            => 0,
				'created_at'          => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Einzelner Job.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Job eines bestimmten Druckers (Zugriffsschutz: eine Bridge darf nur
	 * ihre eigenen Jobs sehen).
	 *
	 * @param int    $id         Job-ID.
	 * @param string $printer_id Drucker.
	 * @return object|null
	 */
	public static function find_for_printer( $id, $printer_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE id = %d AND printer_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $id,
				(string) $printer_id
			)
		);
	}

	/**
	 * Jobs, die auf eine Datei verweisen – für das Aufräumen: Eine Datei, die
	 * ein erneut eingereihter Job weiter nutzt, darf mit dem alten nicht
	 * verschwinden.
	 *
	 * @param string $file_path Dateipfad.
	 * @param int    $except_id Diesen Job nicht mitzählen.
	 * @return int
	 */
	public static function count_by_file( $file_path, $except_id = 0 ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . ' WHERE file_path = %s AND id <> %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $file_path,
				(int) $except_id
			)
		);
	}

	/**
	 * Wartende Jobs eines Druckers, älteste zuerst.
	 *
	 * @param string $printer_id Drucker.
	 * @param int    $limit      Höchstzahl.
	 * @return array
	 */
	public static function queued_for_printer( $printer_id, $limit = 5 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE printer_id = %s AND status = 'queued' ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $printer_id,
				(int) $limit
			)
		);
	}

	/**
	 * Anzahl wartender Jobs eines Druckers (für /status).
	 *
	 * @param string $printer_id Drucker.
	 * @return int
	 */
	public static function count_queued( $printer_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE printer_id = %s AND status = 'queued'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $printer_id
			)
		);
	}

	/**
	 * Belegt gerade ein Job diesen Drucker (geclaimt bis druckend, oder
	 * unklar – ein unklarer Job sperrt die Warteschlange, bis er geklärt ist)?
	 *
	 * @param string $printer_id Drucker.
	 * @return object|null
	 */
	public static function active_for_printer( $printer_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE printer_id = %s AND status IN ('claimed','uploading','printing','unklar') ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $printer_id
			)
		);
	}

	/**
	 * Job claimen: queued → claimed, mit Lock-Zeitstempel.
	 *
	 * @param int    $id         Job-ID.
	 * @param string $printer_id Anfragende Bridge.
	 * @return bool Erfolg.
	 */
	public static function claim( $id, $printer_id ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'claimed', locked_at = %s, started_at = %s, attempts = attempts + 1, updated_at = %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. ' WHERE id = %d AND printer_id = %s AND status = %s',
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				(int) $id,
				(string) $printer_id,
				'queued'
			)
		);

		return (int) $updated > 0;
	}

	/**
	 * 3MF-Upload läuft: claimed/uploading → uploading. Erneuert das Lease.
	 *
	 * @param int    $id         Job-ID.
	 * @param string $printer_id Anfragende Bridge.
	 * @return bool
	 */
	public static function mark_uploading( $id, $printer_id ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'uploading', locked_at = %s, updated_at = %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND printer_id = %s AND status IN ('claimed','uploading')",
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				(int) $id,
				(string) $printer_id
			)
		);

		return (int) $updated > 0;
	}

	/**
	 * Druckvorgang gestartet: claimed/uploading/printing/unklar → printing.
	 * Erneuert das Lease. Die Bridge meldet das erst, wenn der Drucker den
	 * Druckbeginn bestätigt hat – ab hier ist die Platte belegt.
	 *
	 * Beim ersten Wechsel aus claimed/uploading beginnt hier auch die
	 * Ist-Druckzeit (started_at): Bisher zählte sie ab der Annahme des Jobs,
	 * also samt Download und Übertragung. Eine wieder aufgenommene Meldung
	 * (printing/unklar, etwa nach einem Neustart der Bridge) lässt den
	 * Startzeitpunkt stehen.
	 *
	 * @param int    $id         Job-ID.
	 * @param string $printer_id Anfragende Bridge.
	 * @return bool
	 */
	public static function mark_printing( $id, $printer_id ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET started_at = IF(status IN ('claimed','uploading'), %s, started_at), status = 'printing', locked_at = %s, updated_at = %s, error_message = NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND printer_id = %s AND status IN ('claimed','uploading','printing','unklar')",
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				(int) $id,
				(string) $printer_id
			)
		);

		if ( (int) $updated > 0 ) {
			BambuPrinterRepository::mark_bed_busy( $printer_id );
		}

		return (int) $updated > 0;
	}

	/**
	 * Laufenden Job mit Fortschritt aktualisieren und das Lease erneuern –
	 * die Bridge meldet bei jeder Prozent-Änderung und sonst mindestens alle
	 * paar Minuten, damit ein mehrstündiger Druck nicht mitten im Vorgang
	 * als verwaist gilt.
	 *
	 * Meldet die Bridge Fortschritt für einen „unklaren“ Job, läuft er
	 * offensichtlich doch noch (etwa nach langer Funkstille zum Drucker):
	 * Er wird wieder „printing“.
	 *
	 * @param int    $id                Job-ID.
	 * @param string $printer_id        Anfragende Bridge.
	 * @param int    $progress_percent  Fortschritt (0–100).
	 * @param int    $remaining_minutes Restzeit in Minuten.
	 */
	public static function touch_progress( $id, $printer_id, $progress_percent, $remaining_minutes ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = IF(status = 'unklar', 'printing', status), locked_at = %s, updated_at = %s, progress_percent = %d, remaining_minutes = %d" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND printer_id = %s AND status IN ('claimed','uploading','printing','unklar')",
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				max( 0, min( 100, (int) $progress_percent ) ),
				// SMALLINT UNSIGNED – ein größerer Wert ließe MySQL im strict
				// mode das ganze UPDATE zurückweisen (siehe apply_status()).
				max( 0, min( 65535, (int) $remaining_minutes ) ),
				(int) $id,
				(string) $printer_id
			)
		);
	}

	/**
	 * Lease des aktiven Jobs eines Druckers erneuern – aus dem Status-
	 * Heartbeat, wenn der Drucker einen laufenden Druck meldet. Damit läuft
	 * kein Druck ab, nur weil sich der Fortschritt lange nicht ändert (etwa
	 * eine Pause, weil nachts das Filament ausging). Ein „unklarer“ Job wird
	 * hier bewusst nicht wiederbelebt: Dass der Drucker irgendetwas druckt,
	 * heißt nicht, dass es dieser Job ist – das entscheidet die Bridge.
	 *
	 * @param string $printer_id Drucker.
	 */
	public static function touch_active_lease( $printer_id ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET locked_at = %s WHERE printer_id = %s AND status IN ('claimed','uploading','printing')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				(string) $printer_id
			)
		);
	}

	/**
	 * Druck erfolgreich abgeschlossen: claimed/uploading/printing →
	 * completed. Idempotent – ein wiederholter Aufruf für einen bereits
	 * abgeschlossenen Job bleibt erfolgreich, ohne ihn erneut anzustoßen.
	 *
	 * Bucht selbst nichts: Lagerzugang, Druckjournal und Zeiten entstehen
	 * allein bei der Ist-Meldung der Werkstatt-Platte (siehe
	 * Erp\Workflow::report()) – mit Gut/Ausschuss statt „alles gut“. Die
	 * früheren Automatikbuchungen („Bedarf direkt drucken“, Ist-Druckzeit
	 * alter Druckaufträge) sind entfallen.
	 *
	 * @param int    $id         Job-ID.
	 * @param string $printer_id Anfragende Bridge.
	 * @return array {ok, already} already = true, wenn schon vorher abgeschlossen.
	 */
	public static function complete( $id, $printer_id ) {
		$job = self::find_for_printer( $id, $printer_id );

		if ( ! $job ) {
			return array( 'ok' => false, 'already' => false );
		}

		if ( 'completed' === $job->status ) {
			return array( 'ok' => true, 'already' => true );
		}

		if ( ! self::can_complete( $job->status ) ) {
			return array( 'ok' => false, 'already' => false );
		}

		global $wpdb;

		$completed_at = current_time( 'mysql', true );
		$ist_minuten  = self::actual_minutes( $job->started_at, $completed_at );

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'completed', completed_at = %s, updated_at = %s, locked_at = NULL, progress_percent = 100, remaining_minutes = 0, actual_print_minutes = %f, error_message = NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND printer_id = %s AND status IN ('claimed','uploading','printing','unklar')",
				$completed_at,
				$completed_at,
				null === $ist_minuten ? 0 : $ist_minuten,
				(int) $id,
				(string) $printer_id
			)
		);

		return array( 'ok' => (int) $updated > 0, 'already' => false );
	}

	/**
	 * Druck als fehlgeschlagen markieren. Idempotent wie complete(): ein
	 * bereits abgeschlossener Job wird nicht mehr angetastet.
	 *
	 * @param int    $id         Job-ID.
	 * @param string $printer_id Anfragende Bridge.
	 * @param string $message    Fehlermeldung (z. B. Bambu-Fehlercode).
	 * @return bool
	 */
	public static function fail( $id, $printer_id, $message ) {
		$job = self::find_for_printer( $id, $printer_id );

		if ( ! $job || ! self::can_fail( $job->status ) ) {
			return false;
		}

		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'failed', error_message = %s, updated_at = %s, locked_at = NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND printer_id = %s AND status IN ('claimed','uploading','printing','unklar','failed')",
				sanitize_textarea_field( (string) $message ),
				current_time( 'mysql', true ),
				(int) $id,
				(string) $printer_id
			)
		);

		return (int) $updated > 0;
	}

	/**
	 * Angenommenen, aber nie gestarteten Job zurück in die Warteschlange
	 * geben: claimed/uploading → queued. Die Bridge tut das, wenn der
	 * Drucker kurz vor dem Start doch belegt oder nicht erreichbar ist –
	 * das Startkommando ist dann nie rausgegangen.
	 *
	 * @param int    $id         Job-ID.
	 * @param string $printer_id Drucker.
	 * @return bool
	 */
	public static function release( $id, $printer_id ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'queued', locked_at = NULL, started_at = NULL, updated_at = %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND printer_id = %s AND status IN ('claimed','uploading')",
				current_time( 'mysql', true ),
				(int) $id,
				(string) $printer_id
			)
		);

		return (int) $updated > 0;
	}

	/**
	 * Job als „unklar“ markieren: Er war auf dem Drucker, aber sein Ausgang
	 * ist nicht sicher bekannt. Sperrt die Warteschlange des Druckers, bis
	 * die Bridge ihn doch noch abschließt oder jemand ihn klärt.
	 *
	 * @param int    $id         Job-ID.
	 * @param string $printer_id Drucker.
	 * @param string $message    Begründung.
	 * @return bool
	 */
	public static function mark_unclear( $id, $printer_id, $message ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'unklar', error_message = %s, updated_at = %s, locked_at = NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND printer_id = %s AND status IN ('claimed','uploading','printing','unklar')",
				sanitize_textarea_field( (string) $message ),
				current_time( 'mysql', true ),
				(int) $id,
				(string) $printer_id
			)
		);

		return (int) $updated > 0;
	}

	/**
	 * Einen hängenden Job von Hand klären – der Weg aus „unklar“ (oder aus
	 * einem Job, dessen Bridge sich nicht mehr meldet), ohne auf den Ablauf
	 * des Leases zu warten.
	 *
	 * „completed“ schließt den Auftrag ab wie die Bridge (gebucht wird erst
	 * bei der Ist-Meldung der Werkstatt-Platte), „failed“ markiert ihn als
	 * fehlgeschlagen.
	 * Nur aktive Jobs; ein abgeschlossener Job bleibt, wie er ist.
	 *
	 * @param int    $id      Job-ID.
	 * @param string $outcome completed oder failed.
	 * @param string $note    Begründung für das Protokoll.
	 * @return array {ok, error}
	 */
	public static function resolve_manually( $id, $outcome, $note = '' ) {
		$job = self::find( $id );

		if ( ! $job ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}

		if ( ! in_array( $job->status, self::ACTIVE_STATUSES, true ) ) {
			return array( 'ok' => false, 'error' => 'not_active' );
		}

		if ( 'completed' === $outcome ) {
			$result = self::complete( (int) $job->id, (string) $job->printer_id );
			return array( 'ok' => (bool) $result['ok'], 'error' => $result['ok'] ? '' : 'invalid_state' );
		}

		if ( 'failed' === $outcome ) {
			$message = '' !== trim( (string) $note ) ? (string) $note : __( 'Von Hand als fehlgeschlagen markiert.', 'lindenweg16' );
			$ok      = self::fail( (int) $job->id, (string) $job->printer_id, $message );
			return array( 'ok' => $ok, 'error' => $ok ? '' : 'invalid_state' );
		}

		return array( 'ok' => false, 'error' => 'invalid_outcome' );
	}

	/**
	 * Verwaiste Jobs behandeln, wenn ihr Lock älter als LOCK_TIMEOUT_MINUTES
	 * ist (Bridge abgestürzt oder Netz weg, ohne dass jemals ein failed/
	 * completed ankam): Was den Drucker sicher noch nicht erreicht hat, geht
	 * zurück in die Warteschlange; alles andere wird „unklar“ (siehe
	 * stale_target()).
	 *
	 * @return int Anzahl behandelter Jobs.
	 */
	public static function release_stale_locks() {
		global $wpdb;

		$grenze = gmdate( 'Y-m-d H:i:s', time() - self::LOCK_TIMEOUT_MINUTES * 60 );

		$stale = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, printer_id, status FROM ' . self::table() . " WHERE status IN ('claimed','uploading','printing') AND locked_at IS NOT NULL AND locked_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$grenze
			)
		);

		$behandelt = 0;

		foreach ( (array) $stale as $job ) {
			$printer = BambuPrinterRepository::find_by_printer_id( $job->printer_id );
			$target  = self::stale_target( $job->status, $printer ? (string) $printer->current_file : '', (int) $job->id );

			if ( 'queued' === $target ) {
				$sql = $wpdb->prepare(
					'UPDATE ' . self::table() . " SET status = 'queued', locked_at = NULL, started_at = NULL, updated_at = %s WHERE id = %d AND status = %s AND locked_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					current_time( 'mysql', true ),
					(int) $job->id,
					(string) $job->status,
					$grenze
				);
			} elseif ( 'unklar' === $target ) {
				$sql = $wpdb->prepare(
					'UPDATE ' . self::table() . " SET status = 'unklar', locked_at = NULL, updated_at = %s, error_message = %s WHERE id = %d AND status = %s AND locked_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					current_time( 'mysql', true ),
					sprintf(
						/* translators: %d: Minuten. */
						__( 'Seit über %d Minuten kein Lebenszeichen der Bridge zu diesem Druck. Bitte am Drucker nachsehen und den Auftrag klären.', 'lindenweg16' ),
						self::LOCK_TIMEOUT_MINUTES
					),
					(int) $job->id,
					(string) $job->status,
					$grenze
				);
			} else {
				continue;
			}

			$behandelt += (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- oben vorbereitet.
		}

		return $behandelt;
	}

	/**
	 * Liste für die Admin-Übersicht, mit Filter und einfacher Paginierung.
	 *
	 * @param string $status Filter (leer = alle).
	 * @param int    $limit  Höchstzahl.
	 * @return array
	 */
	public static function list_for_admin( $status = '', $limit = 100 ) {
		global $wpdb;

		if ( $status && in_array( $status, self::STATUSES, true ) ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table() . ' WHERE status = %s ORDER BY id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$status,
					(int) $limit
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', (int) $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Alle Jobs eines Druckers löschen (beim Löschen des Druckers selbst),
	 * samt der eigens dafür hochgeladenen Druckdateien.
	 *
	 * @param string $printer_id Drucker.
	 */
	public static function delete_for_printer( $printer_id ) {
		global $wpdb;

		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, file_path FROM ' . self::table() . ' WHERE printer_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $printer_id
			)
		);

		// Erst die Zeilen, dann die Dateien: delete_job_file() lässt eine
		// Datei liegen, solange noch ein Job auf sie verweist.
		$wpdb->delete( self::table(), array( 'printer_id' => (string) $printer_id ) );

		foreach ( (array) $jobs as $job ) {
			BambuService::delete_job_file( $job->file_path );
		}
	}

	/**
	 * Alte, abgeschlossene Job-Einträge nach Aufbewahrungsfrist entfernen.
	 *
	 * Mit dem Eintrag verschwindet auch die zugehörige Druckdatei – aber
	 * nur, wenn sie eigens für die Warteschlange hochgeladen wurde. Eine
	 * Datei aus der Modellablage gehört der Artikelrevision und bleibt
	 * liegen (siehe BambuService::delete_job_file()). Ohne dieses
	 * Aufräumen wüchse der Dokumentenordner mit jedem Druck weiter,
	 * während die Datenbankzeilen verschwinden.
	 *
	 * @param int $tage 0 = unbegrenzt (keine Löschung).
	 * @return int Anzahl gelöschter Zeilen.
	 */
	public static function cleanup_retention( $tage ) {
		global $wpdb;

		$tage = (int) $tage;

		if ( $tage <= 0 ) {
			return 0;
		}

		$grenze = gmdate( 'Y-m-d H:i:s', time() - $tage * DAY_IN_SECONDS );

		$alte = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, file_path FROM ' . self::table() . " WHERE status IN ('completed','failed','cancelled') AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$grenze
			)
		);

		$geloescht = 0;

		foreach ( (array) $alte as $job ) {
			$geloescht += (int) $wpdb->delete( self::table(), array( 'id' => (int) $job->id ) );
			BambuService::delete_job_file( $job->file_path );
		}

		return $geloescht;
	}
}
