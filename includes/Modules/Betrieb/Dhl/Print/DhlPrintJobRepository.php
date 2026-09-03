<?php
/**
 * Druckjobs der DHL-Druck-Warteschlange.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Dhl\Print;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Installer;

/**
 * Verwaltet die Warteschlange: anlegen, abholen, claimen, Statuswechsel.
 *
 * Der Claim ist die einzige Stelle, an der zwei Raspberry-Pi-Clients um
 * denselben Job konkurrieren könnten. Er läuft deshalb als ein einziges
 * bedingtes UPDATE (WHERE status = 'queued'): InnoDB sperrt die betroffene
 * Zeile für die Dauer der Anweisung, ein zweiter gleichzeitiger Aufruf
 * sieht entweder die schon geänderte Zeile (0 betroffene Zeilen) oder
 * wartet kurz und scheitert danach ebenfalls – nie gewinnen beide.
 */
class DhlPrintJobRepository {

	/**
	 * Gültige Status, in der Reihenfolge des Normalablaufs.
	 */
	const STATUSES = array( 'queued', 'claimed', 'printing', 'printed', 'failed', 'cancelled' );

	/**
	 * Nach so vielen Minuten ohne Aktivität gilt ein Claim als verwaist.
	 */
	const LOCK_TIMEOUT_MINUTES = 10;

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_dhl_print_jobs';
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
	 * @param mixed $requested Angeforderte Sekunden (aus der Anfrage).
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
	 * Darf aus dem gegebenen Status nach "printing" gewechselt werden?
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_start_printing( $status ) {
		return in_array( (string) $status, array( 'claimed', 'printing' ), true );
	}

	/**
	 * Darf aus dem gegebenen Status abgeschlossen ("printed") werden?
	 * "printed" selbst ist erlaubt (Idempotenz: ein wiederholter Aufruf
	 * ändert nichts, meldet aber weiterhin Erfolg).
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_complete( $status ) {
		return in_array( (string) $status, array( 'claimed', 'printing', 'printed' ), true );
	}

	/**
	 * Darf aus dem gegebenen Status als "failed" markiert werden?
	 * Ein bereits gedruckter Job darf nicht nachträglich als
	 * fehlgeschlagen gelten – das PDF hat den Drucker schon verlassen.
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_fail( $status ) {
		return in_array( (string) $status, array( 'claimed', 'printing', 'failed' ), true );
	}

	/* -----------------------------------------------------------------
	 * Datenbank
	 * ------------------------------------------------------------- */

	/**
	 * Job anlegen (status = queued).
	 *
	 * @param array $data {order_id, label_id, label_path, label_filename, label_format, width_mm, height_mm, printer_id, is_test}
	 * @return int Neue ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'order_id'       => isset( $data['order_id'] ) && $data['order_id'] ? (int) $data['order_id'] : null,
				'label_id'       => isset( $data['label_id'] ) && $data['label_id'] ? (int) $data['label_id'] : null,
				'label_path'     => (string) $data['label_path'],
				'label_filename' => (string) $data['label_filename'],
				'label_format'   => isset( $data['label_format'] ) ? (string) $data['label_format'] : '910-300-610',
				'width_mm'       => isset( $data['width_mm'] ) ? (int) $data['width_mm'] : 103,
				'height_mm'      => isset( $data['height_mm'] ) ? (int) $data['height_mm'] : 199,
				'status'         => 'queued',
				'printer_id'     => sanitize_key( (string) $data['printer_id'] ),
				'is_test'        => ! empty( $data['is_test'] ) ? 1 : 0,
				'attempts'       => 0,
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s' )
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
	 * Job eines bestimmten Druckers (Zugriffsschutz: ein Pi darf nur seine
	 * eigenen Jobs sehen).
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
	public static function queued_for_printer( $printer_id, $limit = 20 ) {
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
	 * Job claimen: queued → claimed, mit Lock-Zeitstempel.
	 *
	 * Ein einziges bedingtes UPDATE ist der ganze Schutz gegen doppeltes
	 * Claimen (siehe Klassendoku). $wpdb->rows_affected ist 0, wenn ein
	 * anderer Pi schneller war oder der Job nicht mehr wartet.
	 *
	 * @param int    $id         Job-ID.
	 * @param string $printer_id Anfragender Drucker.
	 * @return bool Erfolg.
	 */
	public static function claim( $id, $printer_id ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'claimed', locked_at = %s, attempts = attempts + 1, updated_at = %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. ' WHERE id = %d AND printer_id = %s AND status = %s',
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
	 * Druckvorgang starten: claimed/printing → printing. Erneuert außerdem
	 * das Lease, damit ein langer Druck nicht mitten im Vorgang als
	 * verwaist gilt.
	 *
	 * @param int    $id         Job-ID.
	 * @param string $printer_id Anfragender Drucker.
	 * @return bool
	 */
	public static function mark_printing( $id, $printer_id ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'printing', locked_at = %s, updated_at = %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND printer_id = %s AND status IN ('claimed','printing')",
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				(int) $id,
				(string) $printer_id
			)
		);

		return (int) $updated > 0;
	}

	/**
	 * Druck abschließen: claimed/printing → printed. Idempotent – ein
	 * bereits gedruckter Job bleibt unverändert und meldet trotzdem
	 * Erfolg, damit ein wiederholter Aufruf (Netzwerk-Timeout auf dem
	 * Rückweg) keinen Fehler auslöst.
	 *
	 * @param int    $id         Job-ID.
	 * @param string $printer_id Anfragender Drucker.
	 * @return array {ok, already} already = true, wenn schon vorher gedruckt.
	 */
	public static function complete( $id, $printer_id ) {
		$job = self::find_for_printer( $id, $printer_id );

		if ( ! $job ) {
			return array( 'ok' => false, 'already' => false );
		}

		if ( 'printed' === $job->status ) {
			return array( 'ok' => true, 'already' => true );
		}

		if ( ! self::can_complete( $job->status ) ) {
			return array( 'ok' => false, 'already' => false );
		}

		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'printed', printed_at = %s, updated_at = %s, locked_at = NULL, error_message = NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND printer_id = %s AND status IN ('claimed','printing')",
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				(int) $id,
				(string) $printer_id
			)
		);

		return array( 'ok' => (int) $updated > 0, 'already' => false );
	}

	/**
	 * Druck als fehlgeschlagen markieren. Idempotent wie complete(): ein
	 * wiederholter Aufruf mit derselben oder einer neuen Meldung bleibt
	 * ohne Fehler, ein bereits gedruckter Job wird nicht mehr angetastet.
	 *
	 * @param int    $id         Job-ID.
	 * @param string $printer_id Anfragender Drucker.
	 * @param string $message    Fehlermeldung (z. B. von CUPS).
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
				. " WHERE id = %d AND printer_id = %s AND status IN ('claimed','printing','failed')",
				sanitize_textarea_field( (string) $message ),
				current_time( 'mysql', true ),
				(int) $id,
				(string) $printer_id
			)
		);

		return (int) $updated > 0;
	}

	/**
	 * Verwaiste Claims freigeben: claimed/printing → queued, wenn der
	 * Lock älter als LOCK_TIMEOUT_MINUTES ist. Wird sowohl vor jeder
	 * Job-Abfrage als auch aus dem Wartungs-Cronjob aufgerufen.
	 *
	 * @return int Anzahl freigegebener Jobs.
	 */
	public static function release_stale_locks() {
		global $wpdb;

		$grenze = gmdate( 'Y-m-d H:i:s', time() - self::LOCK_TIMEOUT_MINUTES * 60 );

		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'queued', locked_at = NULL, updated_at = %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE status IN ('claimed','printing') AND locked_at IS NOT NULL AND locked_at < %s",
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
	 * Alte, abgeschlossene Job-Einträge nach Aufbewahrungsfrist entfernen.
	 * Betrifft ausdrücklich nur die Zeilen dieser Warteschlange – die
	 * echten DHL-Labels im Belegarchiv (wp_lw16_shipping_labels) bleiben
	 * unangetastet, sie sind Kostenbelege und müssen erhalten bleiben.
	 * Nur Testlabel-Dateien werden mit entfernt, da sie kein Beleg sind.
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
				'SELECT * FROM ' . self::table() . " WHERE status IN ('printed','failed','cancelled') AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$grenze
			)
		);

		foreach ( $alte as $job ) {
			if ( $job->is_test ) {
				$pfad = trailingslashit( Installer::docs_dir() ) . $job->label_filename;
				if ( is_file( $pfad ) ) {
					wp_delete_file( $pfad );
				}
			}
			$wpdb->delete( self::table(), array( 'id' => (int) $job->id ) );
		}

		return count( $alte );
	}
}
