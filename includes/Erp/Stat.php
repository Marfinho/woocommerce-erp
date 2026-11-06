<?php
/**
 * Allgemeiner anonymer Tageszähler für die Auswertungen unter „Besucher“.
 *
 * Eine Zeile je Tag, Art (Produktansicht, Warenkorb, Suche, 404, Kampagne) und
 * Bezeichnung mit einer Zahl. Weder Kennung noch IP-Adresse noch User-Agent
 * kommen vor, und nichts verbindet zwei Zeilen zu einer Person.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Zählen und lesen.
 */
class Stat {

	/**
	 * Aufbewahrung in Tagen.
	 */
	const KEEP_DAYS = 90;

	/**
	 * Höchstens so viele verschiedene Zeilen je Tag und Art (Schutz vor ausgedachten Werten).
	 */
	const MAX_PER_DAY = 1000;

	/**
	 * Arten.
	 */
	const KINDS = array( 'pview', 'cart', 'search', 'miss', 'e404', 'camp' );

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	protected static function table() {
		global $wpdb;

		return $wpdb->prefix . 'lw16_stat';
	}

	/**
	 * Eins hochzählen.
	 *
	 * @param string $kind  Art, eine aus KINDS.
	 * @param string $label Bezeichnung (Produktnummer, Suchbegriff, Pfad, Quelle).
	 * @param string $extra Zweite Angabe (Verweisseite, Kampagne), darf leer sein.
	 * @param int    $now   Zeitpunkt.
	 */
	public static function bump( $kind, $label, $extra, $now ) {
		global $wpdb;

		if ( ! in_array( $kind, self::KINDS, true ) || '' === (string) $label ) {
			return;
		}

		$table = self::table();
		$day   = gmdate( 'Y-m-d', $now );
		$label = substr( (string) $label, 0, 120 );
		$extra = substr( (string) $extra, 0, 100 );
		$hash  = md5( $label . "\n" . $extra );

		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE day = %s AND kind = %s AND hash = %s", $day, $kind, $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $exists && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE day = %s AND kind = %s", $day, $kind ) ) >= self::MAX_PER_DAY ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (day, kind, hash, label, extra, n) VALUES (%s, %s, %s, %s, %s, 1) ON DUPLICATE KEY UPDATE n = n + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$day,
				$kind,
				$hash,
				$label,
				$extra
			)
		);

		if ( 1 === wp_rand( 1, 300 ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", gmdate( 'Y-m-d', $now - self::KEEP_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Summen einer Art im Zeitraum.
	 *
	 * @param string $kind Art.
	 * @param int    $days Zeitraum in Tagen.
	 * @param int    $now  Zeitpunkt.
	 * @return array Zeilen {label, extra, n}, größte zuerst, höchstens 500.
	 */
	public static function rows( $kind, $days, $now = null ) {
		global $wpdb;

		$now   = null === $now ? time() : (int) $now;
		$table = self::table();
		$since = gmdate( 'Y-m-d', $now - ( (int) $days - 1 ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT label, extra, SUM(n) AS n FROM {$table} WHERE kind = %s AND day >= %s GROUP BY label, extra ORDER BY n DESC LIMIT 500", $kind, $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}
}
