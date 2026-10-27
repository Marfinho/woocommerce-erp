<?php
/**
 * Tagesplan: Platten auf Drucker, Tag und Nacht verteilen.
 *
 * Der Drucker läuft ohne dich, das Wechseln der Platte nicht. Daraus folgt
 * eine einfache Regel: Lange Drucke gehören in die Nacht, wenn niemand da
 * ist, kurze in den Tag, wenn jemand die Platte wechseln kann. So laufen die
 * Maschinen länger, ohne dass jemand nachts aufsteht.
 *
 * Verteilt wird gierig: erst die langen Platten in die Nachtfenster, dann
 * die kurzen in die Tagfenster, dann Reste dort, wo noch Platz ist. Reicht
 * der Tag nicht, geht der Rest auf den nächsten. Zusätzlich begrenzt die
 * Handarbeit des Tages, wie viele Platten bestückt werden können.
 *
 * Reine Rechnung ohne Datenbank.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Verteilung der Platten.
 */
class DayPlan {

	/**
	 * Ab dieser Dauer gilt eine Platte als „lang“ und kommt bevorzugt in die Nacht.
	 */
	const LONG_H = 4.0;

	/**
	 * Platten aus Schritten des Produktionsplans.
	 *
	 * @param array $steps Schritte mit action, plates, machine_h, article_id, name, sku.
	 * @return array Platten: article_id, sku, name, hours.
	 */
	public static function plates( array $steps ) {
		$out = array();

		foreach ( $steps as $step ) {
			if ( 'drucken' !== ( isset( $step['action'] ) ? $step['action'] : '' ) || (int) $step['plates'] <= 0 ) {
				continue;
			}

			$each = (float) $step['machine_h'] / (int) $step['plates'];

			for ( $i = 0; $i < (int) $step['plates']; $i++ ) {
				$out[] = array(
					'article_id' => (int) $step['article_id'],
					'sku'        => isset( $step['sku'] ) ? (string) $step['sku'] : '',
					'name'       => isset( $step['name'] ) ? (string) $step['name'] : '',
					'hours'      => round( $each, 2 ),
				);
			}
		}

		return $out;
	}

	/**
	 * Platten auf Tage verteilen.
	 *
	 * @param array $plates     Platten (siehe plates()).
	 * @param int   $printers   Anzahl Drucker.
	 * @param array $opts       day_h (Fenster am Tag), night_h (Fenster in der Nacht),
	 *                          plate_min (Bedienminuten je Platte), operator_h (Handarbeit je Tag),
	 *                          max_days.
	 * @return array {days[], left}
	 */
	public static function build( array $plates, $printers, array $opts = array() ) {
		$o = array_merge(
			array(
				'day_h'      => 9.0,
				'night_h'    => 10.0,
				'plate_min'  => 10.0,
				'operator_h' => 6.0,
				'max_days'   => 14,
			),
			$opts
		);

		$printers = max( 1, (int) $printers );
		usort(
			$plates,
			static function ( $a, $b ) {
				return $b['hours'] <=> $a['hours'];
			}
		);

		$days = array();

		for ( $d = 1; $d <= (int) $o['max_days'] && $plates; $d++ ) {
			$hands = (float) $o['operator_h'] * 60.0;
			$day   = array(
				'day'      => $d,
				'printers' => array(),
				'plates'   => 0,
				'machine_h' => 0.0,
			);

			for ( $p = 1; $p <= $printers; $p++ ) {
				$row = array(
					'printer' => $p,
					'night'   => array(),
					'day'     => array(),
				);

				// 1. Lange Platten in die Nacht.
				$rest = (float) $o['night_h'];
				foreach ( $plates as $i => $plate ) {
					if ( $plate['hours'] >= self::LONG_H && $plate['hours'] <= $rest + 0.001 && $hands >= $o['plate_min'] ) {
						$row['night'][] = $plate;
						$rest          -= $plate['hours'];
						$hands         -= $o['plate_min'];
						unset( $plates[ $i ] );
					}
				}

				// 2. Danach den Tag füllen, kürzere zuerst bevorzugt, sonst was passt.
				$rest = (float) $o['day_h'];
				foreach ( array_reverse( $plates, true ) as $i => $plate ) {
					if ( $plate['hours'] <= $rest + 0.001 && $hands >= $o['plate_min'] ) {
						$row['day'][] = $plate;
						$rest        -= $plate['hours'];
						$hands       -= $o['plate_min'];
						unset( $plates[ $i ] );
					}
				}

				$hours = 0.0;
				foreach ( array_merge( $row['night'], $row['day'] ) as $pl ) {
					$hours += $pl['hours'];
				}

				$day['plates']    += count( $row['night'] ) + count( $row['day'] );
				$day['machine_h'] += $hours;
				$day['printers'][] = $row;
			}

			// Ein Tag ohne eine einzige Platte darf die Schleife nicht ewig laufen lassen:
			// eine Platte, die in kein Fenster passt, bekommt ein eigenes.
			if ( 0 === $day['plates'] && $plates ) {
				$first                         = array_shift( $plates );
				$day['printers'][0]['night'][] = $first;
				$day['plates']                 = 1;
				$day['machine_h']              = $first['hours'];
			}

			$day['machine_h'] = round( $day['machine_h'], 2 );
			$days[]           = $day;
		}

		return array(
			'days' => $days,
			'left' => count( $plates ),
		);
	}
}
