<?php
/**
 * Ereignisprotokoll des ERP.
 *
 * Hält fest, was im Betrieb geschieht, in drei Stufen:
 *
 * - **normal** - der gewöhnliche Lauf: Bestellung eingegangen, Lager gebucht,
 *   Einstellung geändert, Anmeldung.
 * - **attention** (Aufmerksamkeit) - etwas weicht ab und sollte jemand sehen:
 *   Bestand unter null, fehlgeschlagene Anmeldung, Stornierung, Abweichung
 *   zwischen ERP und Shop.
 * - **critical** (kritisch) - etwas ist nicht gelaufen: eine Buchung
 *   zurückgerollt, ein Hintergrundjob abgebrochen, ein PHP-Fehler im Plugin.
 *
 * Das hashverkettete Änderungsprotokoll (AuditLog) bleibt die Quelle für die
 * Revision; dieses Protokoll ist das Betriebsbuch daneben. Es darf nie selbst
 * zum Problem werden: Ein Fehler beim Schreiben wird verschluckt.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Schreibt und liest das Ereignisprotokoll.
 */
class Log {

	const NORMAL    = 'normal';
	const ATTENTION = 'attention';
	const CRITICAL  = 'critical';

	/**
	 * Tabelle.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_event_log';
	}

	/**
	 * Die Stufen mit Bezeichnung, aufsteigend nach Schwere.
	 *
	 * @return array
	 */
	public static function levels() {
		return array(
			self::NORMAL    => __( 'Normal', 'lindenweg16' ),
			self::ATTENTION => __( 'Aufmerksamkeit', 'lindenweg16' ),
			self::CRITICAL  => __( 'Kritisch', 'lindenweg16' ),
		);
	}

	/**
	 * Stufe auf eine bekannte zurückführen - reine Regel.
	 *
	 * Die alten Namen (error, warning, info) bleiben gültig.
	 *
	 * @param string $level Stufe.
	 * @return string
	 */
	public static function normalize_level( $level ) {
		$map = array(
			'error'    => self::CRITICAL,
			'critical' => self::CRITICAL,
			'warning'  => self::ATTENTION,
			'attention' => self::ATTENTION,
		);

		return isset( $map[ $level ] ) ? $map[ $level ] : self::NORMAL;
	}

	/**
	 * Wie lange ein Eintrag aufbewahrt wird, in Tagen - reine Regel.
	 *
	 * Das Gewöhnliche wird schnell uninteressant, das Auffällige will man
	 * lange nachlesen können.
	 *
	 * @param string $level Stufe.
	 * @return int
	 */
	public static function keep_days( $level ) {
		$days = array(
			self::NORMAL    => 90,
			self::ATTENTION => 365,
			self::CRITICAL  => 1095,
		);

		return $days[ self::normalize_level( $level ) ];
	}

	/**
	 * Gewöhnliches Ereignis.
	 *
	 * @param string $area    Bereich, etwa „lager" oder „bestellung".
	 * @param string $message Meldung.
	 * @param array  $context Zusatzangaben (einfache Werte).
	 */
	public static function normal( $area, $message, array $context = array() ) {
		self::add( self::NORMAL, $area, $message, $context );
	}

	/**
	 * Etwas, das Aufmerksamkeit verdient.
	 *
	 * @param string $area    Bereich.
	 * @param string $message Meldung.
	 * @param array  $context Zusatzangaben.
	 */
	public static function attention( $area, $message, array $context = array() ) {
		self::add( self::ATTENTION, $area, $message, $context );
	}

	/**
	 * Etwas Kritisches.
	 *
	 * @param string $area    Bereich.
	 * @param string $message Meldung.
	 * @param array  $context Zusatzangaben.
	 */
	public static function critical( $area, $message, array $context = array() ) {
		self::add( self::CRITICAL, $area, $message, $context );
	}

	/**
	 * Alter Name für kritisch.
	 *
	 * @param string $area    Bereich.
	 * @param string $message Meldung.
	 * @param array  $context Zusatzangaben.
	 */
	public static function error( $area, $message, array $context = array() ) {
		self::add( self::CRITICAL, $area, $message, $context );
	}

	/**
	 * Alter Name für Aufmerksamkeit.
	 *
	 * @param string $area    Bereich.
	 * @param string $message Meldung.
	 * @param array  $context Zusatzangaben.
	 */
	public static function warning( $area, $message, array $context = array() ) {
		self::add( self::ATTENTION, $area, $message, $context );
	}

	/**
	 * Eintrag bauen - reine Logik.
	 *
	 * @param string $level   Stufe.
	 * @param string $area    Bereich.
	 * @param string $message Meldung.
	 * @param array  $context Zusatzangaben.
	 * @param int    $time    Zeitpunkt.
	 * @return array
	 */
	public static function entry( $level, $area, $message, array $context, $time ) {
		$clean = array();

		foreach ( $context as $key => $value ) {
			$clean[ (string) $key ] = is_scalar( $value ) || null === $value ? mb_substr( (string) $value, 0, 200 ) : '[' . gettype( $value ) . ']';
		}

		return array(
			'time'    => (int) $time,
			'level'   => self::normalize_level( $level ),
			'area'    => mb_substr( (string) $area, 0, 40 ),
			'message' => mb_substr( (string) $message, 0, 500 ),
			'context' => $clean,
		);
	}

	/**
	 * Eintrag schreiben. Wirft nie.
	 *
	 * @param string $level   Stufe.
	 * @param string $area    Bereich.
	 * @param string $message Meldung.
	 * @param array  $context Zusatzangaben.
	 */
	protected static function add( $level, $area, $message, array $context ) {
		try {
			global $wpdb;

			if ( ! isset( $wpdb ) ) {
				return;
			}

			$entry = self::entry( $level, $area, $message, $context, time() );

			// Was Aufmerksamkeit braucht, steht auch im Logger von WooCommerce.
			if ( self::NORMAL !== $entry['level'] && function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->log(
					self::CRITICAL === $entry['level'] ? 'error' : 'warning',
					'[' . $entry['area'] . '] ' . $entry['message'] . ( $entry['context'] ? ' ' . wp_json_encode( $entry['context'] ) : '' ),
					array( 'source' => 'lindenweg16' )
				);
			}

			$wpdb->suppress_errors( true );
			$wpdb->insert(
				self::table(),
				array(
					'created_at' => gmdate( 'Y-m-d H:i:s', $entry['time'] ),
					'level'      => $entry['level'],
					'area'       => $entry['area'],
					'message'    => $entry['message'],
					'context'    => $entry['context'] ? wp_json_encode( $entry['context'] ) : null,
					'user_id'    => function_exists( 'get_current_user_id' ) && get_current_user_id() ? get_current_user_id() : null,
				)
			);
			$wpdb->suppress_errors( false );

			// Gelegentlich aufräumen, statt einen eigenen Auftrag zu brauchen.
			if ( 1 === wp_rand( 1, 200 ) ) {
				self::prune();
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Das Protokoll darf nichts kaputt machen.
		}
	}

	/**
	 * Alte Einträge entfernen, je Stufe nach ihrer Aufbewahrungsfrist.
	 */
	public static function prune() {
		global $wpdb;

		$table = self::table();

		foreach ( array_keys( self::levels() ) as $level ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE level = %s AND created_at < %s LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$level,
					gmdate( 'Y-m-d H:i:s', time() - self::keep_days( $level ) * DAY_IN_SECONDS )
				)
			);
		}
	}

	/**
	 * Einträge abfragen.
	 *
	 * @param array $args level, area, q (Suchtext), page, per_page.
	 * @return array {rows, total}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['level'] ) && isset( self::levels()[ $args['level'] ] ) ) {
			$where[]  = 'level = %s';
			$params[] = $args['level'];
		}

		if ( ! empty( $args['area'] ) ) {
			$where[]  = 'area = %s';
			$params[] = (string) $args['area'];
		}

		if ( ! empty( $args['q'] ) ) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
		}

		$per_page = max( 1, min( 200, isset( $args['per_page'] ) ? (int) $args['per_page'] : 50 ) );
		$offset   = max( 0, ( ( isset( $args['page'] ) ? (int) $args['page'] : 1 ) - 1 ) * $per_page );
		$sql_where = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows_sql  = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Anzahl je Stufe in den letzten Tagen.
	 *
	 * @param int $days Tage.
	 * @return array Stufe => Anzahl.
	 */
	public static function counts( $days = 7 ) {
		global $wpdb;

		$table = self::table();
		$out   = array_fill_keys( array_keys( self::levels() ), 0 );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT level, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY level", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS )
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$out[ self::normalize_level( $row['level'] ) ] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * Die vorkommenden Bereiche.
	 *
	 * @return string[]
	 */
	public static function areas() {
		global $wpdb;

		$table = self::table();

		return array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT area FROM {$table} ORDER BY area ASC" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
