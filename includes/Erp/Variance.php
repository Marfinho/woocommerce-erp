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
			'setup' => isset( $run['setup_min'] ) && null !== $run['setup_min'] && '' !== $run['setup_min']
				? max( 0.0, (float) $run['setup_min'] )
				: ( null !== $setup_at && $setup_at < $print_at ? ( $print_at - $setup_at ) / 60.0 : 0.0 ),
			'print' => ( $end - $print_at ) / 60.0,
		);
	}

	/**
	 * Vollständige Messung einer erledigten Platte - getrennt nach Rüsten, Druck und Warten.
	 *
	 * - Druck: Maschinenzeit von Druckstart bis Druckende (Quelle: drucker, abfrage, hand).
	 * - Rüsten: nur Handarbeit. Von Hand gesetzt (setup_min) oder mit „Rüsten starten“
	 *   gestoppt (setup_src = hand) abzüglich Pausen. Eine Zeit, die nur der Upload
	 *   zum Drucker ausgelöst hat (setup_src = auto), ist keine Arbeitszeit und zählt nicht.
	 * - Warten: freigegeben bis Rüsten (vor) und Druckende bis Meldung (nach).
	 *
	 * @param array $run Platte (Spalten der Tabelle runs).
	 * @return array {print, print_src, failed, setup, setup_src, wait_before, wait_after} - Werte in
	 *               Minuten oder null, wenn nicht messbar.
	 */
	public static function measure( array $run ) {
		$print_at = self::ts( isset( $run['print_at'] ) ? $run['print_at'] : '' );
		$end      = self::ts( isset( $run['print_end_at'] ) ? $run['print_end_at'] : '' );
		$reported = self::ts( isset( $run['reported_at'] ) ? $run['reported_at'] : '' );
		$setup_at = self::ts( isset( $run['setup_at'] ) ? $run['setup_at'] : '' );
		$released = self::ts( isset( $run['released_at'] ) ? $run['released_at'] : '' );

		$print = null;
		if ( null !== $print_at ) {
			$stop = null !== $end ? $end : $reported;
			if ( null !== $stop && $stop > $print_at ) {
				$print = ( $stop - $print_at ) / 60.0;
			}
		}

		$setup     = null;
		$setup_src = null;
		if ( isset( $run['setup_min'] ) && null !== $run['setup_min'] && '' !== $run['setup_min'] ) {
			$setup     = max( 0.0, (float) $run['setup_min'] );
			$setup_src = 'hand';
		} elseif ( isset( $run['setup_src'] ) && 'hand' === $run['setup_src'] && null !== $setup_at && null !== $print_at && $print_at > $setup_at ) {
			$paused    = isset( $run['setup_paused_s'] ) ? max( 0, (int) $run['setup_paused_s'] ) : 0;
			$setup     = max( 0.0, ( $print_at - $setup_at - $paused ) / 60.0 );
			$setup_src = 'hand';
		}

		$src = isset( $run['print_src'] ) && in_array( $run['print_src'], array( 'drucker', 'abfrage', 'hand' ), true ) ? $run['print_src'] : 'abfrage';

		return array(
			'print'       => $print,
			'print_src'   => null === $print ? null : $src,
			'failed'      => ! empty( $run['print_failed'] ),
			'setup'       => $setup,
			'setup_src'   => $setup_src,
			'wait_before' => null !== $setup_at && null !== $released && $setup_at > $released ? ( $setup_at - $released ) / 60.0 : null,
			'wait_after'  => null !== $end && null !== $reported && $reported > $end ? ( $reported - $end ) / 60.0 : null,
		);
	}

	/**
	 * Median einer Liste - unempfindlich gegen Ausreißer (eine über Nacht liegen gebliebene Platte).
	 *
	 * @param array $values Zahlen.
	 * @return float|null Null bei leerer Liste.
	 */
	public static function median( array $values ) {
		$values = array_values( array_map( 'floatval', $values ) );
		$n      = count( $values );

		if ( 0 === $n ) {
			return null;
		}

		sort( $values );
		$mid = intdiv( $n, 2 );

		return 1 === $n % 2 ? $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
	}

	/**
	 * Ist die Platte rein, also mit nur einem Artikel? Nur dann lässt sich die Zeit je Teil exakt messen.
	 *
	 * @param array $items Positionen (article_id, qty).
	 * @return int Artikel-ID bei reiner Platte, sonst 0.
	 */
	public static function pure_article( array $items ) {
		$ids = array();

		foreach ( $items as $item ) {
			if ( (float) ( isset( $item['qty'] ) ? $item['qty'] : 0 ) > 0 ) {
				$ids[ (int) $item['article_id'] ] = true;
			}
		}

		return 1 === count( $ids ) ? (int) array_key_first( $ids ) : 0;
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
		// Kleiner Puffer gegen Gleitkomma: abs( 0.9 - 1 ) ist 0.0999…
		return null !== $factor && $runs >= self::MIN_RUNS && abs( $factor - 1 ) >= self::TOLERANCE - 1e-9;
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
