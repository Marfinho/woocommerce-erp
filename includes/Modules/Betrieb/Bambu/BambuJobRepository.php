<?php
/**
 * Druckaufträge (3MF-Dateien) für die Bambu-Lab-Anbindung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Bambu;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Production\ProductionModule;

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
	 */
	const STATUSES = array( 'queued', 'claimed', 'uploading', 'printing', 'completed', 'failed', 'cancelled' );

	/**
	 * Nach so vielen Minuten ohne Aktivität gilt ein Claim als verwaist.
	 * Deutlich länger als bei Etiketten, da ein 3D-Druck Stunden dauern
	 * kann und die Bridge währenddessen weiter Statusmeldungen schickt
	 * (siehe touch_lock()), die das Lease erneuern.
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

		if ( $wert <= 0 ) {
			return $default;
		}

		return max( (int) $min, min( (int) $max, $wert ) );
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
		return in_array( (string) $status, array( 'claimed', 'uploading', 'printing' ), true );
	}

	/**
	 * Darf aus dem gegebenen Status abgeschlossen ("completed") werden?
	 * "completed" selbst ist erlaubt (Idempotenz).
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_complete( $status ) {
		return in_array( (string) $status, array( 'claimed', 'uploading', 'printing', 'completed' ), true );
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
		return in_array( (string) $status, array( 'claimed', 'uploading', 'printing', 'failed' ), true );
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
	 * @param array $data {printer_id, druckauftrag_id, file_path, file_name}
	 * @return int Neue ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'printer_id'      => sanitize_key( (string) $data['printer_id'] ),
				'druckauftrag_id' => isset( $data['druckauftrag_id'] ) && $data['druckauftrag_id'] ? (int) $data['druckauftrag_id'] : null,
				'file_path'       => (string) $data['file_path'],
				'file_name'       => (string) $data['file_name'],
				'status'          => 'queued',
				'attempts'        => 0,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
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
	 * Läuft gerade ein Job auf diesem Drucker (geclaimt bis druckend)?
	 *
	 * @param string $printer_id Drucker.
	 * @return object|null
	 */
	public static function active_for_printer( $printer_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE printer_id = %s AND status IN ('claimed','uploading','printing') ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * Druckvorgang gestartet: claimed/uploading/printing → printing.
	 * Erneuert das Lease.
	 *
	 * @param int    $id         Job-ID.
	 * @param string $printer_id Anfragende Bridge.
	 * @return bool
	 */
	public static function mark_printing( $id, $printer_id ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'printing', locked_at = %s, updated_at = %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND printer_id = %s AND status IN ('claimed','uploading','printing')",
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				(int) $id,
				(string) $printer_id
			)
		);

		return (int) $updated > 0;
	}

	/**
	 * Laufenden Job mit Fortschritt aktualisieren und das Lease erneuern –
	 * wird bei jeder Statusmeldung der Bridge aufgerufen, damit ein
	 * mehrstündiger Druck nicht mitten im Vorgang als verwaist gilt.
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
				'UPDATE ' . self::table() . ' SET locked_at = %s, updated_at = %s, progress_percent = %d, remaining_minutes = %d' // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND printer_id = %s AND status IN ('claimed','uploading','printing')",
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
	 * Druck erfolgreich abgeschlossen: claimed/uploading/printing →
	 * completed. Idempotent – ein wiederholter Aufruf für einen bereits
	 * abgeschlossenen Job bleibt erfolgreich, ohne ihn erneut anzustoßen.
	 *
	 * Trägt bei Erfolg außerdem – sofern der Job aus einem Fertigungs-
	 * auftrag entstanden ist – die Ist-Druckzeit automatisch in die
	 * Ist-vs-Plan-Auswertung ein (siehe ProductionModule::complete_job()).
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
				. " WHERE id = %d AND printer_id = %s AND status IN ('claimed','uploading','printing')",
				$completed_at,
				$completed_at,
				null === $ist_minuten ? 0 : $ist_minuten,
				(int) $id,
				(string) $printer_id
			)
		);

		$ok = (int) $updated > 0;

		if ( $ok && $job->druckauftrag_id && null !== $ist_minuten && class_exists( ProductionModule::class ) ) {
			// Reine Ist-Druckzeit-Übernahme – die Ist-Arbeitszeit (Verpacken,
			// Entfernen von Stützstrukturen …) lässt sich aus einem
			// automatischen Statuswechsel nicht ableiten und bleibt daher
			// weiterhin eine manuelle Eingabe im Druckjournal.
			ProductionModule::complete_job( (int) $job->druckauftrag_id, $ist_minuten / 60, 0 );
		}

		return array( 'ok' => $ok, 'already' => false );
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
				. " WHERE id = %d AND printer_id = %s AND status IN ('claimed','uploading','printing','failed')",
				sanitize_textarea_field( (string) $message ),
				current_time( 'mysql', true ),
				(int) $id,
				(string) $printer_id
			)
		);

		return (int) $updated > 0;
	}

	/**
	 * Verwaiste Claims freigeben: claimed/uploading/printing → queued,
	 * wenn der Lock älter als LOCK_TIMEOUT_MINUTES ist (Bridge abgestürzt
	 * oder Netz weg, ohne dass jemals ein failed/completed ankam).
	 *
	 * @return int Anzahl freigegebener Jobs.
	 */
	public static function release_stale_locks() {
		global $wpdb;

		$grenze = gmdate( 'Y-m-d H:i:s', time() - self::LOCK_TIMEOUT_MINUTES * 60 );

		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'queued', locked_at = NULL, started_at = NULL, updated_at = %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE status IN ('claimed','uploading','printing') AND locked_at IS NOT NULL AND locked_at < %s",
				current_time( 'mysql', true ),
				$grenze
			)
		);
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

		foreach ( (array) $jobs as $job ) {
			BambuService::delete_job_file( $job->file_path );
		}

		$wpdb->delete( self::table(), array( 'printer_id' => (string) $printer_id ) );
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
			BambuService::delete_job_file( $job->file_path );
			$geloescht += (int) $wpdb->delete( self::table(), array( 'id' => (int) $job->id ) );
		}

		return $geloescht;
	}
}
