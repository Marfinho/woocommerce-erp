<?php
/**
 * Wartungsprotokoll und Fälligkeiten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Wartungen erfassen und Fälligkeiten berechnen.
 */
class MaintenanceRepository {

	use SanitizesInput;

	/**
	 * Betriebsstunden, nach denen die Düse spätestens getauscht wird.
	 */
	const NOZZLE_HOURS_MAX = 800;

	/**
	 * Betriebsstunden, ab denen der Düsentausch angekündigt wird.
	 */
	const NOZZLE_HOURS_WARN = 500;

	/**
	 * Erlaubte Intervalle.
	 */
	const INTERVALS = array( 'woechentlich', 'monatlich', 'verschleiss', 'saison' );

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_maintenance_log';
	}

	/**
	 * Einträge eines Geräts.
	 *
	 * @param int $printer_id Gerät.
	 * @return array
	 */
	public static function for_printer( $printer_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE printer_id = %d ORDER BY performed_on DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $printer_id
			),
			ARRAY_A
		);
	}

	/**
	 * Letzte Wartung eines Intervalls.
	 *
	 * @param int    $printer_id Gerät.
	 * @param string $interval   Intervall.
	 * @return array|null
	 */
	public static function last( $printer_id, $interval ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE printer_id = %d AND interval_type = %s ORDER BY performed_on DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $printer_id,
				sanitize_key( $interval )
			),
			ARRAY_A
		);
	}

	/**
	 * Fälligkeiten aller Geräte.
	 *
	 * @return array Liste aus printer und reasons.
	 */
	public static function due() {
		$result = array();

		foreach ( PrinterRepository::all() as $printer ) {
			$result[] = array(
				'printer' => $printer,
				'reasons' => self::reasons_for( $printer ),
			);
		}

		return $result;
	}

	/**
	 * Fälligkeitsgründe eines Geräts – reine Logik bis auf die Abfragen.
	 *
	 * @param array $printer Gerät.
	 * @return string[]
	 */
	public static function reasons_for( array $printer ) {
		$reasons    = array();
		$printer_id = (int) $printer['id'];
		$hours      = PrintJobRepository::operating_hours( $printer_id );
		$since      = $hours - (float) $printer['hours_at_last_nozzle_change'];

		if ( $since >= self::NOZZLE_HOURS_MAX ) {
			$reasons[] = sprintf(
				/* translators: %s: Stunden. */
				__( 'Düsentausch überfällig (%s h seit dem letzten Wechsel)', 'lindenweg16' ),
				number_format_i18n( $since, 0 )
			);
		} elseif ( $since >= self::NOZZLE_HOURS_WARN ) {
			$reasons[] = sprintf(
				/* translators: %s: Stunden. */
				__( 'Düsentausch steht an (%s h seit dem letzten Wechsel)', 'lindenweg16' ),
				number_format_i18n( $since, 0 )
			);
		}

		foreach ( array(
			'woechentlich' => 7,
			'monatlich'    => 30,
		) as $interval => $days ) {
			$last = self::last( $printer_id, $interval );

			if ( ! $last ) {
				$reasons[] = sprintf(
					/* translators: %s: Intervall. */
					__( 'Noch keine %s Wartung erfasst', 'lindenweg16' ),
					'woechentlich' === $interval ? __( 'wöchentliche', 'lindenweg16' ) : __( 'monatliche', 'lindenweg16' )
				);
				continue;
			}

			$age = ( time() - strtotime( $last['performed_on'] . ' 12:00:00 UTC' ) ) / DAY_IN_SECONDS;

			if ( $age > $days ) {
				$reasons[] = sprintf(
					/* translators: 1: Intervall, 2: Tage. */
					__( '%1$s Wartung seit %2$s Tagen fällig', 'lindenweg16' ),
					'woechentlich' === $interval ? __( 'Wöchentliche', 'lindenweg16' ) : __( 'Monatliche', 'lindenweg16' ),
					number_format_i18n( $age - $days, 0 )
				);
			}
		}

		return $reasons;
	}

	/**
	 * Wartung erfassen.
	 *
	 * @param array $input Eingaben.
	 * @return int
	 */
	public static function insert( array $input ) {
		global $wpdb;

		$printer_id = self::int_or( $input, 'printer_id', 0 );
		if ( ! $printer_id ) {
			return 0;
		}

		$hours = PrintJobRepository::operating_hours( $printer_id );

		$data = array(
			'printer_id'         => $printer_id,
			'performed_on'       => self::date_or_today( $input, 'performed_on' ),
			'interval_type'      => self::enum( $input, 'interval_type', self::INTERVALS, 'woechentlich' ),
			'tasks_json'         => isset( $input['tasks'] ) ? wp_json_encode( (array) $input['tasks'] ) : null,
			'operating_hours_at' => $hours,
			'part_replaced'      => self::text( $input, 'part_replaced' ),
			'next_due_hours'     => $hours + self::NOZZLE_HOURS_MAX,
			'performed_by'       => get_current_user_id(),
			'note'               => isset( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) : null,
			'created_at'         => current_time( 'mysql', true ),
			'created_by'         => get_current_user_id(),
		);

		$wpdb->insert( self::table(), $data );
		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'maintenance_log', $id, null, $data );

		// Beim Düsentausch den Zählerstand am Gerät mitführen.
		if ( ! empty( $input['nozzle_changed'] ) ) {
			PrinterRepository::update(
				$printer_id,
				array( 'hours_at_last_nozzle_change' => $hours ),
				__( 'Düsenwechsel im Wartungsprotokoll erfasst.', 'lindenweg16' )
			);
		}

		return $id;
	}
}
