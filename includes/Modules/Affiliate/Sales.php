<?php
/**
 * Affiliates: Bestellungen mit Affiliate-Code einlesen.
 *
 * Gelesen werden bezahlte Bestellungen (in Bearbeitung, abgeschlossen) über
 * `wc_get_orders()` und `$order->get_coupon_codes()`, damit es mit und ohne HPOS läuft.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Affiliate;

defined( 'ABSPATH' ) || exit;

/**
 * Bestellungen einlesen.
 */
class Sales {

	const META_RATE    = '_lw16_aff_provision';
	const META_PARTNER = '_lw16_aff_partner';

	/**
	 * Bezahlte Bestellungen, in denen ein Affiliate-Code eingelöst wurde.
	 *
	 * @param array  $partners Id => Partner.
	 * @param string $since    Frühestes Datum Y-m-d (Anlegedatum des ältesten Partners).
	 * @return array Zeilen mit `order`, `code`, `ts`, `base`, `total`, `rate`.
	 */
	public static function collect( array $partners, $since ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! $partners ) {
			return array();
		}

		$codes = array();
		foreach ( $partners as $p ) {
			$codes[ strtolower( $p['code'] ) ] = true;
		}

		$args = array(
			'type'    => 'shop_order',
			'status'  => array( 'wc-processing', 'wc-completed' ),
			'orderby' => 'ID',
			'order'   => 'ASC',
			'limit'   => 200,
		);

		if ( '' !== (string) $since ) {
			$args['date_created'] = '>=' . strtotime( $since . ' 00:00:00' );
		}

		$rows = array();
		$page = 1;

		do {
			$args['paged'] = $page;
			$orders        = wc_get_orders( $args );

			foreach ( $orders as $order ) {
				foreach ( (array) $order->get_coupon_codes() as $used ) {
					$code = strtolower( (string) $used );
					if ( ! isset( $codes[ $code ] ) ) {
						continue;
					}

					$rate = $order->get_meta( self::META_RATE );
					$rows[] = array(
						'order' => (int) $order->get_id(),
						'code'  => $code,
						'ts'    => (int) ( $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0 ),
						'base'  => self::base( $order ),
						'total' => round( (float) $order->get_total() - (float) $order->get_total_refunded(), 2 ),
						'rate'  => '' === $rate || null === $rate ? null : (float) $rate,
					);
				}
			}

			$page++;
		} while ( count( $orders ) === $args['limit'] );

		return $rows;
	}

	/**
	 * Bemessungsgrundlage einer Bestellung.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return float
	 */
	public static function base( $order ) {
		$refunded = 0.0;

		foreach ( (array) $order->get_refunds() as $refund ) {
			$refunded += Rules::refund_goods_net( $refund->get_total(), $refund->get_total_tax(), $refund->get_shipping_total() );
		}

		return Rules::base( $order->get_subtotal(), $order->get_total_discount( true ), $refunded );
	}
}
