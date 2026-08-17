<?php
/**
 * Einheitliche Zeitbehandlung für alle DATETIME-Spalten des Plugins.
 *
 * Alle Spalten des Plugins speichern **lokale Seitenzeit** (wie
 * current_time( 'mysql' ) sie liefert). Damit Schreiben und Vergleichen nie
 * auseinanderlaufen, gehen sämtliche Zeitstempel durch diese Klasse.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Liefert MySQL-DATETIME-Strings konsistent in Seitenzeit.
 */
class Time {

	/**
	 * Jetzt als MySQL-DATETIME in Seitenzeit.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql' );
	}

	/**
	 * Heute als MySQL-DATE in Seitenzeit.
	 *
	 * @return string
	 */
	public static function today() {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Ein Unix-Timestamp als MySQL-DATETIME in Seitenzeit.
	 *
	 * wp_date() rechnet den Timestamp in die Zeitzone der Seite um – im
	 * Gegensatz zu gmdate(), das UTC liefert und damit gegen die in
	 * Seitenzeit gespeicherten Spalten nicht vergleichbar ist.
	 *
	 * @param int $timestamp Unix-Timestamp.
	 * @return string
	 */
	public static function at( $timestamp ) {
		return (string) wp_date( 'Y-m-d H:i:s', (int) $timestamp );
	}

	/**
	 * Zeitpunkt vor N Sekunden als MySQL-DATETIME in Seitenzeit.
	 *
	 * @param int|float $seconds Sekunden in der Vergangenheit.
	 * @return string
	 */
	public static function ago( $seconds ) {
		return self::at( time() - (int) round( $seconds ) );
	}

	/**
	 * Zeitpunkt in N Sekunden als MySQL-DATETIME in Seitenzeit.
	 *
	 * @param int|float $seconds Sekunden in der Zukunft.
	 * @return string
	 */
	public static function in( $seconds ) {
		return self::at( time() + (int) round( $seconds ) );
	}

	/**
	 * Wochentag (1 = Montag … 7 = Sonntag) in Seitenzeit.
	 *
	 * @return int
	 */
	public static function weekday() {
		return (int) current_time( 'N' );
	}

	/**
	 * Kalenderjahr in Seitenzeit.
	 *
	 * @return int
	 */
	public static function year() {
		return (int) current_time( 'Y' );
	}

	/**
	 * ISO-Kalenderwoche (JJJJ-WW) eines DATETIME-Strings in Seitenzeit.
	 *
	 * @param string $datetime MySQL-DATETIME.
	 * @return string
	 */
	public static function week_key( $datetime ) {
		$ts = strtotime( (string) $datetime );
		if ( ! $ts ) {
			return '';
		}
		return (string) wp_date( 'o-W', $ts );
	}
}
