<?php
/**
 * Zahlenformatierung in deutscher Notation – einmal zentral statt fünfmal kopiert.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Formatiert Mengen und Beträge einheitlich.
 */
class Format {

	/**
	 * Menge ohne überflüssige Nachkommastellen (1.000,5 statt 1.000,50).
	 *
	 * @param float $n Zahl.
	 * @return string
	 */
	public static function qty( $n ) {
		$s = number_format( (float) $n, 2, ',', '.' );
		if ( false === strpos( $s, ',' ) ) {
			return $s;
		}
		return rtrim( rtrim( $s, '0' ), ',' );
	}

	/**
	 * Betrag mit zwei Nachkommastellen, ohne Währungszeichen.
	 *
	 * @param float $n Betrag.
	 * @return string
	 */
	public static function amount( $n ) {
		return number_format( (float) $n, 2, ',', '.' );
	}

	/**
	 * Betrag mit Euro-Zeichen.
	 *
	 * @param float $n Betrag.
	 * @return string
	 */
	public static function euro( $n ) {
		return self::amount( $n ) . ' €';
	}
}
