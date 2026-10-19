<?php
/**
 * Hochrechnung: Was wird in den nächsten Wochen gebraucht?
 *
 * Aus dem Verkauf der letzten Wochen wird ein Wochenmittel je Artikel
 * gebildet; gebaut werden soll so viel, dass der Bestand für eine
 * einstellbare Zahl Wochen reicht. Offene Bestellungen zählen schon als
 * verbindlicher Bedarf und werden abgezogen – sie sind Teil desselben
 * Verkaufs, sonst würde doppelt geplant.
 *
 * Die Rechnung ist rein; das Einsammeln der Verkäufe aus WooCommerce steht
 * getrennt in sales_by_article().
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Hochrechnung aus dem Verkaufsverlauf.
 */
class Forecast {

	/**
	 * Fenster, aus dem das Wochenmittel gebildet wird.
	 */
	const WEEKS = 8;

	/**
	 * Wie viele Wochen der Bestand reichen soll.
	 */
	const COVER_WEEKS = 2;

	/**
	 * Bedarf aus dem Verkaufsverlauf.
	 *
	 * @param array $sold        article_id => verkaufte Menge im Fenster.
	 * @param array $open_orders article_id => Menge in offenen Bestellungen.
	 * @param int   $weeks       Fenster in Wochen.
	 * @param float $cover       Reichweite in Wochen.
	 * @return array Liste von {article_id, qty, source, label, per_week}.
	 */
	public static function demands( array $sold, array $open_orders, $weeks = self::WEEKS, $cover = self::COVER_WEEKS ) {
		$weeks = max( 1, (int) $weeks );
		$cover = max( 0.0, (float) $cover );
		$out   = array();

		foreach ( $sold as $article_id => $qty ) {
			$qty = (float) $qty;
			if ( $qty <= 0 ) {
				continue;
			}

			$per_week = $qty / $weeks;
			$want     = $per_week * $cover;
			$open     = isset( $open_orders[ $article_id ] ) ? (float) $open_orders[ $article_id ] : 0.0;
			$need     = (int) ceil( $want - $open - 0.0001 );

			if ( $need <= 0 ) {
				continue;
			}

			$out[] = array(
				'article_id' => (int) $article_id,
				'qty'        => (float) $need,
				'source'     => 'hochrechnung',
				'label'      => sprintf(
					/* translators: %s: Mittel je Woche. */
					__( 'Ø %s je Woche', 'lindenweg16' ),
					RunFlow::trim_qty( round( $per_week, 1 ) )
				),
				'per_week'   => round( $per_week, 2 ),
			);
		}

		return $out;
	}

	/**
	 * Verkäufe der letzten Wochen je Artikel aus WooCommerce.
	 *
	 * Eine Stunde zwischengespeichert: Die Zahl ändert sich langsam, die
	 * Abfrage ist nicht billig.
	 *
	 * @param int $weeks Fenster in Wochen.
	 * @return array article_id => Menge.
	 */
	public static function sales_by_article( $weeks = self::WEEKS ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$cache_key = 'lw16_erp_sales_' . (int) $weeks;
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map = \Lindenweg16\Modules\Betrieb\StockOwnership::article_map();
		$out = array();

		$orders = wc_get_orders(
			array(
				'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
				'limit'        => 2000,
				'date_created' => '>=' . ( time() - (int) $weeks * WEEK_IN_SECONDS ),
				'return'       => 'objects',
			)
		);

		foreach ( (array) $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$article_id = isset( $map[ (int) $item->get_product_id() ] ) ? (int) $map[ (int) $item->get_product_id() ] : 0;

				if ( $article_id ) {
					$out[ $article_id ] = ( isset( $out[ $article_id ] ) ? $out[ $article_id ] : 0.0 ) + (float) $item->get_quantity();
				}
			}
		}

		set_transient( $cache_key, $out, HOUR_IN_SECONDS );

		return $out;
	}
}
