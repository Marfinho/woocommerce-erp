<?php
/**
 * Kapazitätsvorschau und Rückwärtsrechnung.
 *
 * Eine Ausgabe, keine neue Tabelle – alle Daten liegen bereits vor.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Rechnet Bedarf gegen Kapazität und ermittelt die knappste Ressource.
 */
class CapacityPlanner {

	/**
	 * Bedarf einer Wochenplanung – reine Logik.
	 *
	 * @param array $bundles  Bestellarten mit erwarteter Anzahl:
	 *                        [['items' => [[article_id, qty]], 'count' => int]].
	 * @param array $ctx      Kalkulationskontext.
	 * @param array $settings Annahmen.
	 * @return array
	 */
	public static function demand( array $bundles, array $ctx, array $settings ) {
		$print_h    = 0.0;
		$post_min   = 0.0;
		$purchased  = array();
		$orders     = 0;

		foreach ( $bundles as $bundle ) {
			$count = isset( $bundle['count'] ) ? (float) $bundle['count'] : 0.0;
			if ( $count <= 0 ) {
				continue;
			}
			$orders += $count;

			foreach ( (array) $bundle['items'] as $item ) {
				$item       = (array) $item;
				$article_id = (int) $item['article_id'];
				$qty        = (float) $item['qty'] * $count;

				$cost = CostCalculator::manufacturing_cost( $article_id, $ctx, $settings );

				$print_h  += $qty * $cost['print_time_h'];
				$post_min += $qty * $cost['postprocessing_min'];

				// Zukaufmengen getrennt ausweisen – sie haben Lieferzeit.
				self::collect_purchased( $article_id, $qty, $ctx, $purchased );
			}
		}

		return array(
			'orders'             => $orders,
			'print_time_h'       => $print_h,
			'postprocessing_min' => $post_min,
			'purchased'          => $purchased,
		);
	}

	/**
	 * Zukaufmengen rekursiv einsammeln.
	 *
	 * @param int   $article_id Artikel.
	 * @param float $qty        Menge.
	 * @param array $ctx        Kontext.
	 * @param array $purchased  Ergebnis (Referenz).
	 */
	protected static function collect_purchased( $article_id, $qty, array $ctx, array &$purchased ) {
		if ( ! isset( $ctx['articles'][ $article_id ] ) ) {
			return;
		}

		$article = (array) $ctx['articles'][ $article_id ];

		if ( in_array( $article['sourcing_type'], array( 'zukauf', 'montage', 'hybrid' ), true ) ) {
			if ( ! isset( $purchased[ $article_id ] ) ) {
				$purchased[ $article_id ] = array(
					'sku' => $article['sku'],
					'qty' => 0.0,
				);
			}
			$purchased[ $article_id ]['qty'] += $qty;
		}

		$rows = isset( $ctx['bom'][ $article_id ] ) ? $ctx['bom'][ $article_id ] : array();
		foreach ( $rows as $row ) {
			if ( 'artikel' === $row['child_type'] ) {
				self::collect_purchased( (int) $row['child_id'], $qty * (float) $row['qty'], $ctx, $purchased );
			}
		}
	}

	/**
	 * Auslastung einer Woche – reine Logik.
	 *
	 * @param float $demand_h        Benötigte Druckstunden.
	 * @param int   $printer_count   Geräte.
	 * @param float $net_hours       Nettostunden je Gerät und Woche.
	 * @return array {capacity_h, utilisation, level}
	 */
	public static function utilisation( $demand_h, $printer_count, $net_hours ) {
		$capacity = (float) $printer_count * (float) $net_hours;
		$rate     = $capacity > 0 ? (float) $demand_h / $capacity : 0.0;

		if ( $rate >= 0.9 ) {
			$level = 'rot';
		} elseif ( $rate >= 0.75 ) {
			$level = 'gelb';
		} else {
			$level = 'gruen';
		}

		return array(
			'capacity_h'  => $capacity,
			'utilisation' => $rate,
			'level'       => $level,
		);
	}

	/**
	 * Rückwärtsrechnung: Wie viele Bestellungen schaffe ich diese Woche?
	 *
	 * Begrenzt durch die knappste der drei Ressourcen Druckzeit, eigene
	 * Arbeitszeit und Zukaufbestand. Welche das ist, wird ausdrücklich
	 * benannt – ohne diese Angabe ist die Zahl nicht handlungsleitend.
	 *
	 * @param array $per_order Bedarf je Bestellung: print_time_h,
	 *                         postprocessing_min, purchased [id => qty].
	 * @param array $available Verfügbar: print_h, work_h, stock [id => qty].
	 * @return array {max_orders, limiting, limits}
	 */
	public static function backwards( array $per_order, array $available ) {
		$limits = array();

		$print_per_order = (float) $per_order['print_time_h'];
		$limits['druckzeit'] = $print_per_order > 0
			? (float) $available['print_h'] / $print_per_order
			: INF;

		$work_per_order = (float) $per_order['postprocessing_min'] / 60;
		$limits['arbeitszeit'] = $work_per_order > 0
			? (float) $available['work_h'] / $work_per_order
			: INF;

		$stock_limit = INF;
		$scarcest    = '';

		foreach ( (array) $per_order['purchased'] as $article_id => $needed ) {
			$needed = (float) $needed;
			if ( $needed <= 0 ) {
				continue;
			}
			$stock     = isset( $available['stock'][ $article_id ] ) ? (float) $available['stock'][ $article_id ] : 0.0;
			$candidate = $stock / $needed;

			if ( $candidate < $stock_limit ) {
				$stock_limit = $candidate;
				$scarcest    = (int) $article_id;
			}
		}

		$limits['zukauf'] = $stock_limit;

		$max      = min( $limits );
		$limiting = 'druckzeit';

		foreach ( $limits as $key => $value ) {
			if ( $value === $max ) {
				$limiting = $key;
				break;
			}
		}

		return array(
			'max_orders'      => INF === $max ? null : (int) floor( $max ),
			'limiting'        => $limiting,
			'limiting_label'  => self::limit_label( $limiting ),
			'scarcest_article' => 'zukauf' === $limiting ? $scarcest : 0,
			'limits'          => $limits,
		);
	}

	/**
	 * Bezeichnung der begrenzenden Ressource.
	 *
	 * @param string $key Schlüssel.
	 * @return string
	 */
	public static function limit_label( $key ) {
		$labels = array(
			'druckzeit'   => __( 'Druckzeit', 'lindenweg16' ),
			'arbeitszeit' => __( 'deine Arbeitszeit', 'lindenweg16' ),
			'zukauf'      => __( 'Zukaufbestand', 'lindenweg16' ),
		);

		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	/**
	 * Bedienzeit aus Plattenwechseln.
	 *
	 * @param float $plate_changes Anzahl.
	 * @param float $minutes_each  Minuten je Wechsel.
	 * @return float Stunden.
	 */
	/**
	 * Wochen in einem Zeitraum, als Bruch.
	 *
	 * Ein Tag ist ein Siebtel einer Woche, nicht eine ganze. Die frühere
	 * Rechnung im Reporting nahm die Differenz zweier Zeitstempel und klemmte
	 * sie auf mindestens eins: Ein Tagesbericht verglich damit einen Tag
	 * Druckzeit gegen eine ganze Woche Kapazität und meldete ein Siebtel der
	 * echten Auslastung. Ein Monat war um einen Tag zu kurz, weil der letzte
	 * Tag nicht mitzählte.
	 *
	 * @param string $von Von (Y-m-d).
	 * @param string $bis Bis (Y-m-d).
	 * @return float
	 */
	public static function weeks( $von, $bis ) {
		return Period::days( $von, $bis ) / 7;
	}

	/**
	 * Verfügbare Druckstunden eines Zeitraums.
	 *
	 * @param int   $printers  Produktive Geräte.
	 * @param float $net_hours Nettostunden je Gerät und Woche.
	 * @param float $weeks     Wochen.
	 * @return float
	 */
	public static function capacity_hours( $printers, $net_hours, $weeks ) {
		return max( 0, (int) $printers ) * max( 0.0, (float) $net_hours ) * max( 0.0, (float) $weeks );
	}

	public static function operator_hours( $plate_changes, $minutes_each ) {
		return (float) $plate_changes * (float) $minutes_each / 60;
	}
}
