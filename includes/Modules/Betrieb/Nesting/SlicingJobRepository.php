<?php
/**
 * Warteschlange für den Headless-Slicing-Worker.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

/**
 * Dasselbe Claim-/Lock-Muster wie die übrigen Warteschlangen dieses
 * Plugins (siehe PrintQueue\PrintJobRepository, Bambu\BambuJobRepository):
 * ein einziges bedingtes UPDATE als Claim, damit zwei Worker nie denselben
 * Slicing-Auftrag gewinnen können. Anders als dort gibt es hier nur einen
 * geteilten Worker (siehe SlicingWorkerAuth) statt vieler Geräte mit
 * eigenem Token – die Warteschlange selbst ist trotzdem mehrwertertauglich
 * gebaut, falls später ein zweiter Slicing-Rechner dazukommt.
 */
class SlicingJobRepository {

	/**
	 * Gültige Status.
	 */
	const STATUSES = array( 'queued', 'claimed', 'slicing', 'completed', 'failed', 'cancelled' );

	/**
	 * Ein Slice-Lauf kann bei einer komplexen Mehrteile-Platte mehrere
	 * Minuten dauern. Grosszügiger als die Etikettendrucker, aber deutlich
	 * kürzer als ein tatsächlicher Druck (siehe BambuJobRepository).
	 */
	const LOCK_TIMEOUT_MINUTES = 30;

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_slicing_jobs';
	}

	/* -----------------------------------------------------------------
	 * Reine Logik – ohne Datenbank, deshalb ohne WordPress testbar.
	 * ------------------------------------------------------------- */

	/**
	 * Ist ein Lock abgelaufen?
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
		// serverseitig warten" - fürs klassische Intervall-Polling (siehe
		// SlicingRestApi::get_jobs(), dasselbe Muster wie bei der Bambu-
		// Warteschlange, siehe BambuJobRepository::clamp_wait() für die
		// ausführliche Begründung: ohne diese Unterscheidung hält jede
		// Anfrage einen PHP-Worker samt Datenbankverbindung mindestens
		// $min Sekunden lang fest, auch im klassischen Poll-Modus).
		if ( 0 === $wert ) {
			return 0;
		}

		if ( $wert < 0 ) {
			return $default;
		}

		return max( (int) $min, min( (int) $max, $wert ) );
	}

	/**
	 * Darf ein Job aus dem gegebenen Status geclaimt werden?
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_claim( $status ) {
		return 'queued' === (string) $status;
	}

	/**
	 * Darf aus dem gegebenen Status abgeschlossen ("completed") werden?
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_complete( $status ) {
		return in_array( (string) $status, array( 'claimed', 'slicing', 'completed' ), true );
	}

	/**
	 * Darf aus dem gegebenen Status als "failed" markiert werden?
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_fail( $status ) {
		return in_array( (string) $status, array( 'claimed', 'slicing', 'failed' ), true );
	}

	/* -----------------------------------------------------------------
	 * Datenbank
	 * ------------------------------------------------------------- */

	/**
	 * Slicing-Auftrag anlegen.
	 *
	 * @param array $data {plate_job_id, profile_id}
	 * @return int Neue ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'plate_job_id' => (int) $data['plate_job_id'],
				'profile_id'   => (int) $data['profile_id'],
				'status'       => 'queued',
				'attempts'     => 0,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Einzelner Auftrag.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Wartende Aufträge, älteste zuerst.
	 *
	 * @param int $limit Höchstzahl.
	 * @return array
	 */
	public static function queued( $limit = 5 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE status = 'queued' ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $limit
			)
		);
	}

	/**
	 * Auftrag claimen: queued → claimed.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function claim( $id ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'claimed', locked_at = %s, started_at = %s, attempts = attempts + 1, updated_at = %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. ' WHERE id = %d AND status = %s',
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				(int) $id,
				'queued'
			)
		);

		return (int) $updated > 0;
	}

	/**
	 * Slice-Lauf gestartet: claimed/slicing → slicing. Erneuert das Lease.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function mark_slicing( $id ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'slicing', locked_at = %s, updated_at = %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND status IN ('claimed','slicing')",
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				(int) $id
			)
		);

		return (int) $updated > 0;
	}

	/**
	 * Slice-Lauf erfolgreich abgeschlossen. Idempotent wie die übrigen
	 * Warteschlangen dieses Plugins.
	 *
	 * @param int    $id                ID.
	 * @param string $result_file_path  Absoluter Pfad der erzeugten Druckdatei.
	 * @param string $result_file_name  Ursprünglicher Dateiname.
	 * @return array {ok, already}
	 */
	public static function complete( $id, $result_file_path, $result_file_name ) {
		$job = self::find( $id );

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

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'completed', completed_at = %s, updated_at = %s, locked_at = NULL, result_file_path = %s, result_file_name = %s, error_message = NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND status IN ('claimed','slicing')",
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				(string) $result_file_path,
				(string) $result_file_name,
				(int) $id
			)
		);

		return array( 'ok' => (int) $updated > 0, 'already' => false );
	}

	/**
	 * Slice-Lauf fehlgeschlagen. Idempotent wie complete().
	 *
	 * @param int    $id      ID.
	 * @param string $message Fehlermeldung des Slicers.
	 * @return bool
	 */
	public static function fail( $id, $message ) {
		$job = self::find( $id );

		if ( ! $job || ! self::can_fail( $job->status ) ) {
			return false;
		}

		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'failed', error_message = %s, updated_at = %s, locked_at = NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND status IN ('claimed','slicing','failed')",
				sanitize_textarea_field( (string) $message ),
				current_time( 'mysql', true ),
				(int) $id
			)
		);

		return (int) $updated > 0;
	}

	/**
	 * Verwaiste Claims freigeben.
	 *
	 * @return int Anzahl freigegebener Aufträge.
	 */
	public static function release_stale_locks() {
		global $wpdb;

		$grenze = gmdate( 'Y-m-d H:i:s', time() - self::LOCK_TIMEOUT_MINUTES * 60 );

		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'queued', locked_at = NULL, started_at = NULL, updated_at = %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE status IN ('claimed','slicing') AND locked_at IS NOT NULL AND locked_at < %s",
				current_time( 'mysql', true ),
				$grenze
			)
		);
	}

	/**
	 * Liste für die Admin-Übersicht.
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
	 * Alte, abgeschlossene Aufträge nach Aufbewahrungsfrist entfernen.
	 *
	 * @param int $tage 0 = unbegrenzt.
	 * @return int Anzahl gelöschter Zeilen.
	 */
	public static function cleanup_retention( $tage ) {
		global $wpdb;

		$tage = (int) $tage;
		if ( $tage <= 0 ) {
			return 0;
		}

		$grenze = gmdate( 'Y-m-d H:i:s', time() - $tage * DAY_IN_SECONDS );

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . " WHERE status IN ('completed','failed','cancelled') AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$grenze
			)
		);
	}
}
