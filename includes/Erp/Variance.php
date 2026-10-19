<?php
/**
 * Soll gegen Ist: Rechenregeln.
 *
 * Der Workflow stempelt Rüst- und Druckzeit je Platte. Hier wird daraus die
 * Abweichung zur geplanten Druckzeit des Artikels. Die Zeit einer Platte mit
 * mehreren Artikeln wird im Verhältnis der geplanten Zeiten verteilt – das
 * ist die einzige Aufteilung, die ohne zusätzliche Messung auskommt.
 *
 * Reine Regeln ohne Datenbank; die Abfragen stehen in SollIst.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Rechenregeln für Soll gegen Ist.
 */
class Variance {

	/**
	 * Ab so vielen gemessenen Platten wird eine Korrektur vorgeschlagen.
	 */
	const MIN_RUNS = 3;

	/**
	 * Abweichung, ab der korrigiert wird (0,10 = 10 %).
	 */
	const TOLERANCE = 0.10;

	/**
	 * Marge, die bei der Nachrechnung als Warnung gilt: so viele
	 * Prozentpunkte DB II weniger als nach Plan.
	 */
	const MARGIN_DROP = 0.05;

	/**
	 * Rüst- und Druckminuten einer Platte aus den Zeitstempeln.
	 *
	 * @param array $run Platte mit setup_at, print_at, print_end_at, reported_at (UTC, Y-m-d H:i:s).
	 * @return array|null {setup, print} oder null ohne Druckzeit.
	 */
	public static function run_minutes( array $run ) {
		$print_at = self::ts( isset( $run['print_at'] ) ? $run['print_at'] : '' );

		if ( null === $print_at ) {
			return null;
		}

		$end = self::ts( isset( $run['print_end_at'] ) ? $run['print_end_at'] : '' );

		if ( null === $end ) {
			$end = self::ts( isset( $run['reported_at'] ) ? $run['reported_at'] : '' );
		}

		if ( null === $end || $end <= $print_at ) {
			return null;
		}

		$setup_at = self::ts( isset( $run['setup_at'] ) ? $run['setup_at'] : '' );

		return array(
			'setup' => null !== $setup_at && $setup_at < $print_at ? ( $print_at - $setup_at ) / 60.0 : 0.0,
			'print' => ( $end - $print_at ) / 60.0,
		);
	}

	/**
	 * Zeitstempel lesen.
	 *
	 * @param string $value Y-m-d H:i:s (UTC) oder leer.
	 * @return int|null
	 */
	protected static function ts( $value ) {
		if ( empty( $value ) || '0000-00-00 00:00:00' === $value ) {
			return null;
		}

		$time = strtotime( $value . ' UTC' );

		return false === $time ? null : $time;
	}

	/**
	 * Gemessene Minuten im Verhältnis der geplanten auf Artikel verteilen.
	 *
	 * @param float $actual Gemessene Minuten der Platte.
	 * @param array $plan   Artikel-ID => geplante Minuten.
	 * @return array Artikel-ID => zugeteilte Minuten.
	 */
	public static function allocate( $actual, array $plan ) {
		$sum    = array_sum( $plan );
		$count  = count( $plan );
		$result = array();

		foreach ( $plan as $id => $minutes ) {
			$result[ $id ] = $sum > 0 ? $actual * ( $minutes / $sum ) : ( $count > 0 ? $actual / $count : 0.0 );
		}

		return $result;
	}

	/**
	 * Faktor Ist durch Soll.
	 *
	 * @param float $plan   Geplante Minuten.
	 * @param float $actual Gemessene Minuten.
	 * @return float|null
	 */
	public static function factor( $plan, $actual ) {
		return $plan > 0 ? round( $actual / $plan, 3 ) : null;
	}

	/**
	 * Soll die Planzeit korrigiert werden?
	 *
	 * @param float|null $factor Ist durch Soll.
	 * @param int        $runs   Zahl der gemessenen Platten.
	 * @return bool
	 */
	public static function should_adjust( $factor, $runs ) {
		return null !== $factor && $runs >= self::MIN_RUNS && abs( $factor - 1 ) >= self::TOLERANCE;
	}

	/**
	 * Korrigierte Plattenzeit in Stunden.
	 *
	 * @param float $plate_h Bisherige Plattenzeit.
	 * @param float $factor  Ist durch Soll.
	 * @return float
	 */
	public static function adjusted_hours( $plate_h, $factor ) {
		return round( (float) $plate_h * (float) $factor, 2 );
	}

	/**
	 * Wird die Marge durch die gemessene Zeit kritisch?
	 *
	 * @param float $plan_pct   DB-II-Marge nach Plan (Anteil).
	 * @param float $actual_pct DB-II-Marge mit gemessener Zeit.
	 * @param float $actual_db2 DB II je Stück mit gemessener Zeit.
	 * @return string '' (in Ordnung), 'sinkt' oder 'verlust'
	 */
	public static function margin_state( $plan_pct, $actual_pct, $actual_db2 ) {
		if ( $actual_db2 < 0 ) {
			return 'verlust';
		}

		return ( $plan_pct - $actual_pct ) >= self::MARGIN_DROP ? 'sinkt' : '';
	}
}
