<?php
/**
 * Zeiterfassung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Zeiteinträge.
 */
class TimeRepository {

	use SanitizesInput;

	/**
	 * Erlaubte Kategorien.
	 */
	const CATEGORIES = array( 'produktion', 'ruestzeit', 'druckzeit', 'versand', 'konstruktion', 'marketing', 'vertrieb', 'verwaltung', 'wartung', 'sonstiges' );

	/**
	 * Bezeichnungen der Kategorien.
	 *
	 * @return array
	 */
	public static function category_labels() {
		return array(
			'produktion'   => __( 'Produktion', 'lindenweg16' ),
			'ruestzeit'    => __( 'Rüstzeit', 'lindenweg16' ),
			'druckzeit'    => __( 'Druckzeit', 'lindenweg16' ),
			'versand'      => __( 'Versand', 'lindenweg16' ),
			'konstruktion' => __( 'Konstruktion', 'lindenweg16' ),
			'marketing'    => __( 'Marketing', 'lindenweg16' ),
			'vertrieb'     => __( 'Vertrieb', 'lindenweg16' ),
			'verwaltung'   => __( 'Verwaltung', 'lindenweg16' ),
			'wartung'      => __( 'Wartung', 'lindenweg16' ),
			'sonstiges'    => __( 'Sonstiges', 'lindenweg16' ),
		);
	}

	/**
	 * Bedingung, die Maschinenlaufzeit aus der Arbeitszeit herausnimmt.
	 *
	 * Während der Drucker läuft, arbeitet niemand daran: Die Druckzeit einer
	 * Platte ist Maschinenzeit (sie steht im Druckjournal), keine Arbeitszeit.
	 * Der Workflow bucht sie deshalb nicht mehr. Ältere Buchungen dieser Art
	 * (Kategorie Druckzeit, mit Platte verbunden) bleiben gespeichert, zählen
	 * aber nicht mit: nicht für Löhne, nicht für Wochensummen, nicht für die
	 * Kapazität. Von Hand erfasste Druckzeit (ohne Platte) zählt weiter.
	 *
	 * @return string SQL-Bedingung.
	 */
	public static function working_time_sql() {
		return "NOT ( category = 'druckzeit' AND run_id IS NOT NULL )";
	}

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_time_entries';
	}

	/**
	 * Einträge, neueste zuerst.
	 *
	 * @param array $args Filter.
	 * @return array
	 */
	public static function all( array $args = array() ) {
		global $wpdb;

		$table  = self::table();
		$where  = array( self::working_time_sql() );
		$params = array();

		foreach ( array( 'iso_year', 'iso_week' ) as $field ) {
			if ( ! empty( $args[ $field ] ) ) {
				$where[]  = "{$field} = %d";
				$params[] = (int) $args[ $field ];
			}
		}

		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY entry_date DESC, id DESC LIMIT %d';
		$params[] = isset( $args['limit'] ) ? (int) $args['limit'] : 200;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Stunden je ISO-Woche.
	 *
	 * @param int $weeks Anzahl zurückliegender Wochen.
	 * @return array Liste aus iso_year, iso_week, hours.
	 */
	public static function hours_by_week( $weeks = 12 ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT iso_year, iso_week, SUM( hours ) AS hours
				 FROM {$table}
				 WHERE " . self::working_time_sql() . "
				 GROUP BY iso_year, iso_week
				 ORDER BY iso_year DESC, iso_week DESC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $weeks
			),
			ARRAY_A
		);
	}

	/**
	 * Stunden einer Woche.
	 *
	 * @param int $iso_year ISO-Jahr.
	 * @param int $iso_week ISO-Woche.
	 * @return float
	 */
	public static function hours_in_week( $iso_year, $iso_week ) {
		global $wpdb;

		$table = self::table();

		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( hours ), 0 ) FROM {$table} WHERE iso_year = %d AND iso_week = %d AND " . self::working_time_sql(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $iso_year,
				(int) $iso_week
			)
		);
	}

	/**
	 * Stunden je Kategorie in einem Zeitraum.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return array
	 */
	public static function hours_by_category( $von, $bis ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT category, SUM( hours ) AS hours FROM {$table}
				 WHERE entry_date BETWEEN %s AND %s AND " . self::working_time_sql() . "
				 GROUP BY category ORDER BY hours DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$von,
				$bis
			),
			ARRAY_A
		);
	}

	/**
	 * Eingaben prüfen.
	 *
	 * @param array $input Eingaben.
	 * @return array {data, errors}
	 */
	public static function validate( array $input ) {
		$errors = array();
		$date   = self::date_or_today( $input, 'entry_date' );
		$hours  = self::decimal_or( $input, 'hours', 0 );

		if ( $hours <= 0 ) {
			$errors[] = __( 'Bitte eine Stundenzahl größer als 0 eintragen.', 'lindenweg16' );
		}
		if ( $hours > 24 ) {
			$errors[] = __( 'Mehr als 24 Stunden an einem Tag sind ein Tippfehler.', 'lindenweg16' );
		}

		$iso = IsoWeek::from_date( $date );

		return array(
			'data'   => array(
				'entry_date'  => $date,
				'iso_week'    => $iso['iso_week'],
				'iso_year'    => $iso['iso_year'],
				'category'    => self::enum( $input, 'category', self::CATEGORIES, 'sonstiges' ),
				'description' => self::text( $input, 'description' ),
				// Kommt die Zeit von einem Auftrag, bleibt die Verbindung
				// erhalten – erst dadurch sind Ist-Lohnkosten je Auftrag
				// überhaupt zu rechnen.
				'work_order_id' => self::int_or( $input, 'work_order_id', 0 ) ? self::int_or( $input, 'work_order_id', 0 ) : null,
				// Zeit aus dem Workflow bleibt mit ihrer Platte verbunden.
				'run_id'      => self::int_or( $input, 'run_id', 0 ) ? self::int_or( $input, 'run_id', 0 ) : null,
				'hours'       => $hours,
				'phase_block' => self::text( $input, 'phase_block' ),
				'note'        => isset( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) : null,
			),
			'errors' => $errors,
		);
	}

	/**
	 * Eintrag anlegen.
	 *
	 * @param array $data Geprüfte Daten.
	 * @return int
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'time_entries', $id, null, $data );

		return $id;
	}

	/**
	 * Zeitbuchungen eines Zeitraums – Grundlage der Lohnrechnung.
	 *
	 * @param string $von Von (Y-m-d).
	 * @param string $bis Bis (Y-m-d).
	 * @return array
	 */
	public static function between( $von, $bis ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE entry_date BETWEEN %s AND %s AND " . self::working_time_sql() . " ORDER BY entry_date DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$von,
				$bis
			),
			ARRAY_A
		);
	}

	/**
	 * Zeitbuchungen eines Nutzers, neueste zuerst.
	 *
	 * @param int $user_id Nutzer.
	 * @param int $limit   Obergrenze.
	 * @return array
	 */
	public static function for_user( $user_id, $limit = 200 ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE created_by = %d AND " . self::working_time_sql() . " ORDER BY entry_date DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id,
				(int) $limit
			),
			ARRAY_A
		);
	}

	/**
	 * Zeitbuchungen zu einem Fertigungsauftrag.
	 *
	 * @param int $work_order_id Auftrag.
	 * @return array
	 */
	public static function for_work_order( $work_order_id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE work_order_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $work_order_id
			),
			ARRAY_A
		);
	}
}
