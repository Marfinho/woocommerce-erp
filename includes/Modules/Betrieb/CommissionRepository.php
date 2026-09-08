<?php
/**
 * Kommissionslisten buchen.
 *
 * Eine Stelle, an der eine Kommissionslieferung entsteht – gleich, ob sie
 * vom Schreibtisch oder vom Handy kommt. Beide Wege prüfen mit
 * `CommissionBatch` und buchen hier; sonst driften zwei Fassungen
 * derselben Regel auseinander, und die eine bucht den Bestellsatz, die
 * andere nicht.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Sammelbuchung der Kommissionsware.
 */
class CommissionRepository {

	/**
	 * Eine geprüfte Liste buchen.
	 *
	 * @param array  $lines      Zeilen aus CommissionBatch::validate().
	 * @param string $type       Vorgang.
	 * @param int    $contact_id Händler.
	 * @return string Meldung für den Nutzer.
	 */
	public static function book_batch( array $lines, $type, $contact_id ) {
		if ( ! $lines ) {
			return __( 'Es steht keine Menge in der Liste.', 'lindenweg16' );
		}

		StockRepository::book_many(
			CommissionBatch::movements( $lines, $type, $contact_id ),
			array(
				'ref_type' => 'manual',
				'note'     => __( 'Kommission', 'lindenweg16' ),
			)
		);

		$meldung = CommissionBatch::message( $type, count( $lines ), CommissionBatch::pieces( $lines ) );

		if ( 'kommission_abgerechnet' !== $type ) {
			return $meldung;
		}

		$order = self::settle( $lines, $contact_id );

		if ( '' === $order ) {
			return $meldung;
		}

		return $meldung . ' ' . $order;
	}

	/**
	 * Die Abrechnung als **ein** offener Posten.
	 *
	 * Hier liegt der eigentliche Gewinn gegenüber der Einzelbuchung: Die
	 * Rabattstaffel hängt am Bestellwert. Acht einzeln abgerechnete Artikel
	 * ergaben achtmal einen kleinen Wert und damit achtmal die unterste
	 * Staffel – die Lieferung als Ganzes erreicht die Stufe, die dem
	 * Händler zusteht.
	 *
	 * Die Lagerbuchung ist bereits erfolgt; der Bestellsatz darf sie nicht
	 * ein zweites Mal auslösen.
	 *
	 * @param array $lines      Zeilen.
	 * @param int   $contact_id Händler.
	 * @return string Meldung oder leer.
	 */
	protected static function settle( array $lines, $contact_id ) {
		$articles = ArticleRepository::all_by_id();
		$items    = array();

		foreach ( $lines as $line ) {
			$article = isset( $articles[ (int) $line['article_id'] ] ) ? $articles[ (int) $line['article_id'] ] : null;

			if ( ! $article ) {
				continue;
			}

			$items[] = array(
				'article_id'       => (int) $line['article_id'],
				'color'            => (string) $line['color'],
				'qty'              => (float) $line['qty'],
				'unit_price_gross' => (float) $article['price_gross'],
				'discountable'     => (int) $article['discountable'],
			);
		}

		if ( ! $items ) {
			return '';
		}

		$discount = DiscountResolver::for_contact( $contact_id, $items, gmdate( 'Y-m-d' ) );

		OrderOpsRepository::create_manual(
			array(
				'contact_id'           => $contact_id ? $contact_id : null,
				'manual_order_date'    => gmdate( 'Y-m-d' ),
				'manual_order_value'   => $discount['order_value_net_of_discount'],
				'channel'              => 'Fachhandel',
				'customer_type'        => 'B2B',
				'price_level'          => 'haendler',
				'applied_discount_pct' => $discount['discount_pct'],
				'applied_tier_id'      => $discount['tier_id'],
				'payment_terms_days'   => $discount['payment_terms_days'],
				'invoice_status'       => 'offen',
				'order_status'         => 'bestaetigt',
				'note'                 => __( 'Kommissionsabrechnung', 'lindenweg16' ),
			),
			$items,
			false
		);

		return sprintf(
			/* translators: %s: Bestellwert. */
			__( 'Als offener Posten über %s erfasst.', 'lindenweg16' ),
			number_format_i18n( $discount['order_value_net_of_discount'], 2 ) . ' €'
		);
	}
}
