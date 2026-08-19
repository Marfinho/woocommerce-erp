<?php
/**
 * ISO-8601-Kalenderwochen.
 *
 * Entspricht WEEKNUM(datum;21) aus der Datenerfassungsmappe: Woche 1 ist die
 * Woche mit dem ersten Donnerstag des Jahres, die Woche beginnt am Montag.
 *
 * Die Jahreszahl ist dabei nicht zwingend das Kalenderjahr: Der 1. Januar 2027
 * ist ein Freitag und gehört zur Woche 53 des Jahres 2026. Wer hier das
 * Kalenderjahr nimmt, sortiert Druckjobs über den Jahreswechsel falsch ein.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Umrechnung zwischen Datum und ISO-Woche.
 */
class IsoWeek {

	/**
	 * ISO-Jahr und -Woche eines Datums.
	 *
	 * @param string $date Datum als Y-m-d.
	 * @return array {iso_year, iso_week}
	 */
	public static function from_date( $date ) {
		$timestamp = strtotime( (string) $date . ' 12:00:00 UTC' );

		if ( false === $timestamp ) {
			$timestamp = time();
		}

		return array(
			'iso_year' => (int) gmdate( 'o', $timestamp ),
			'iso_week' => (int) gmdate( 'W', $timestamp ),
		);
	}

	/**
	 * Montag und Sonntag einer ISO-Woche.
	 *
	 * @param int $iso_year ISO-Jahr.
	 * @param int $iso_week ISO-Woche.
	 * @return array {von, bis} jeweils Y-m-d.
	 */
	public static function bounds( $iso_year, $iso_week ) {
		$monday = strtotime( sprintf( '%04dW%02d', (int) $iso_year, (int) $iso_week ) . ' 12:00:00 UTC' );

		if ( false === $monday ) {
			return array(
				'von' => '',
				'bis' => '',
			);
		}

		return array(
			'von' => gmdate( 'Y-m-d', $monday ),
			'bis' => gmdate( 'Y-m-d', $monday + 6 * DAY_IN_SECONDS ),
		);
	}

	/**
	 * Anzahl der ISO-Wochen eines Jahres (52 oder 53).
	 *
	 * @param int $iso_year ISO-Jahr.
	 * @return int
	 */
	public static function weeks_in_year( $iso_year ) {
		// Der 28. Dezember liegt immer in der letzten ISO-Woche des Jahres.
		$result = self::from_date( sprintf( '%04d-12-28', (int) $iso_year ) );
		return $result['iso_week'];
	}

	/**
	 * Eine Folge von Wochen ab einem Startpunkt.
	 *
	 * @param int $iso_year Startjahr.
	 * @param int $iso_week Startwoche.
	 * @param int $count    Anzahl.
	 * @return array Liste aus {iso_year, iso_week}.
	 */
	public static function sequence( $iso_year, $iso_week, $count ) {
		$weeks = array();
		$year  = (int) $iso_year;
		$week  = (int) $iso_week;

		for ( $i = 0; $i < (int) $count; $i++ ) {
			$current = self::shift( $year, $week, $i );
			$weeks[] = $current;
		}

		return $weeks;
	}

	/**
	 * Eine Woche vor- oder zurückschieben.
	 *
	 * Nötig, weil eine einfache Subtraktion an der Jahresgrenze scheitert:
	 * Eine Woche vor KW 1/2027 ist KW 53/2026, nicht KW 0 – und ein auf 1
	 * begrenzter Wert zeigt dieselbe Woche mehrfach an.
	 *
	 * @param int $iso_year ISO-Jahr.
	 * @param int $iso_week ISO-Woche.
	 * @param int $delta    Verschiebung, negativ für zurück.
	 * @return array {iso_year, iso_week}
	 */
	public static function shift( $iso_year, $iso_week, $delta ) {
		$year = (int) $iso_year;
		$week = (int) $iso_week + (int) $delta;

		while ( $week < 1 ) {
			$year--;
			$week += self::weeks_in_year( $year );
		}

		while ( $week > self::weeks_in_year( $year ) ) {
			$week -= self::weeks_in_year( $year );
			$year++;
		}

		return array(
			'iso_year' => $year,
			'iso_week' => $week,
		);
	}

	/**
	 * Beschriftung einer Woche.
	 *
	 * @param int $iso_year ISO-Jahr.
	 * @param int $iso_week ISO-Woche.
	 * @return string
	 */
	public static function label( $iso_year, $iso_week ) {
		return sprintf( 'KW %02d/%04d', (int) $iso_week, (int) $iso_year );
	}
}
