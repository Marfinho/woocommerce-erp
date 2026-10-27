<?php
/**
 * Datenbank-Hilfen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Transaktionen für zusammengehörende Schreibvorgänge.
 */
class Db {

	/**
	 * Verschachtelungstiefe.
	 *
	 * @var int
	 */
	protected static $depth = 0;

	/**
	 * Alles oder nichts ausführen.
	 *
	 * Bricht der Ablauf ab - Ausnahme oder Zeitüberschreitung -, verwirft
	 * die Datenbank die halbe Arbeit. Eine Bestellung ist danach entweder
	 * ganz gebucht oder gar nicht. Verschachtelte Aufrufe reihen sich in
	 * die äußere Transaktion ein.
	 *
	 * @param callable $fn Ablauf.
	 * @return mixed Rückgabe des Ablaufs.
	 * @throws \Throwable Nach dem Zurückrollen weitergereicht.
	 */
	public static function transaction( callable $fn ) {
		global $wpdb;

		if ( self::$depth > 0 ) {
			self::$depth++;
			try {
				return $fn();
			} finally {
				self::$depth--;
			}
		}

		$wpdb->query( 'START TRANSACTION' );
		self::$depth = 1;

		try {
			$result = $fn();
			$wpdb->query( 'COMMIT' );

			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		} finally {
			self::$depth = 0;
		}
	}
}
