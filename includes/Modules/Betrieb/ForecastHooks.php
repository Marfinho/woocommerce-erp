<?php
/**
 * Anbindung der Prognose: täglicher Auslöser-Lauf und Lieferzeit im Shop.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Scheduler;

/**
 * Hängt die Prognose an Hintergrundjob und Shop.
 */
class ForecastHooks {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( Scheduler::HOOK_FORECAST, array( ForecastService::class, 'run_triggers' ) );
		add_filter( 'woocommerce_get_availability_text', array( $this, 'availability_text' ), 20, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'cart_delivery_time' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_delivery_time' ), 20, 3 );
	}


	/**
	 * Verfügbarkeitstext einer Produktseite: „auf Lager“, „nur noch 2“ oder die Lieferzeit.
	 *
	 * Nur für Produkte mit ERP-Artikel und nur, wenn es unter Prognose &
	 * Planung eingeschaltet ist. Alles andere behält den Text von WooCommerce.
	 *
	 * @param string      $text    Bisheriger Text.
	 * @param \WC_Product $product Produkt oder Variante.
	 * @return string
	 */
	public function availability_text( $text, $product ) {
		if ( ! is_object( $product ) ) {
			return $text;
		}

		$made_to_order = self::made_to_order( $product );
		$state         = self::stock_state( $product );

		// Ohne ERP-Anzeige: Nur die Nachbestellung braucht eine eigene Zeile,
		// sonst stünde „Verfügbar bei Nachbestellung“ ohne Lieferzeit da.
		if ( ! ForecastService::settings()['shop_text'] || null === $state ) {
			if ( $made_to_order && 0 >= self::wc_stock( $product ) ) {
				return Promise::made_to_order_text( self::delivery_days( $product ) );
			}
			return $text;
		}

		$estimate = null !== $state['weeks'] ? array( 'weeks' => $state['weeks'], 'date_to' => 'x' ) : null;

		return Promise::shop_text( (int) $state['available'], $estimate, 3, $made_to_order );
	}

	/**
	 * Ist die Ware ohne Bestand bestellbar?
	 *
	 * @param \WC_Product $product Produkt.
	 * @return bool
	 */
	public static function made_to_order( $product ) {
		return is_object( $product ) && $product->managing_stock() && $product->backorders_allowed();
	}

	/**
	 * Bestand laut WooCommerce, nie negativ.
	 *
	 * @param \WC_Product $product Produkt.
	 * @return int
	 */
	protected static function wc_stock( $product ) {
		return max( 0, (int) $product->get_stock_quantity() );
	}

	/**
	 * Verfügbarkeit laut ERP, wenn es das Produkt führt.
	 *
	 * @param \WC_Product $product Produkt oder Variante.
	 * @return array|null {available, weeks} oder null ohne ERP-Artikel.
	 */
	public static function stock_state( $product ) {
		if ( ! StockOwnership::is_active() ) {
			return null;
		}

		// Hinter jeder Variante kann ein eigener Artikel stehen (Typ, Holz).
		$id = OrderLines::article_for_product_object( $product );

		if ( ! $id ) {
			return null;
		}

		$map = ForecastService::shop_map();

		if ( ! isset( $map[ $id ] ) ) {
			return null;
		}

		$available = $map[ $id ]['all'];

		if ( $product->is_type( 'variation' ) ) {
			$attributes = $product->get_attributes();
			$taxonomy   = ColorRegistry::taxonomy();
			$color      = ColorRegistry::normalize( isset( $attributes[ $taxonomy ] ) ? $attributes[ $taxonomy ] : '' );
			$color      = '' === $color ? ColorRegistry::default_color() : $color;
			$available  = isset( $map[ $id ]['colors'][ $color ] ) ? $map[ $id ]['colors'][ $color ] : 0;
		}

		return array(
			'available' => (int) $available,
			'weeks'     => $map[ $id ]['weeks'],
		);
	}

	/**
	 * Tage bis zum Versand einer nachbestellten Ware.
	 *
	 * @param \WC_Product $product Produkt.
	 * @return int
	 */
	public static function delivery_days( $product ) {
		$state = self::stock_state( $product );

		return Promise::days_for_weeks( null !== $state ? $state['weeks'] : null );
	}

	/**
	 * Sofort lieferbar: ERP-Bestand, sonst der von WooCommerce.
	 *
	 * @param \WC_Product $product Produkt.
	 * @return int
	 */
	protected static function available_now( $product ) {
		$state = self::stock_state( $product );

		return null !== $state ? max( 0, (int) $state['available'] ) : self::wc_stock( $product );
	}

	/**
	 * Text zur Lieferzeit einer Position, leer wenn alles am Lager ist - reine Regel.
	 *
	 * @param int $qty       Bestellte Menge.
	 * @param int $available Sofort lieferbar.
	 * @param int $days      Tage bis zum Versand des Rests.
	 * @return string
	 */
	public static function item_delivery_text( $qty, $available, $days ) {
		$qty       = (int) $qty;
		$available = max( 0, (int) $available );

		if ( $qty <= $available ) {
			return '';
		}

		$text = Promise::made_to_order_text( $days );

		if ( $available > 0 ) {
			/* translators: 1: sofort lieferbare Menge, 2: Text zur Fertigung. */
			return sprintf( __( '%1$d sofort, der Rest: %2$s', 'lindenweg16' ), $available, $text );
		}

		return $text;
	}

	/**
	 * Lieferzeit einer Warenkorbposition, sobald sie über den Bestand geht.
	 *
	 * @param array $item_data Bisherige Angaben.
	 * @param array $cart_item Position.
	 * @return array
	 */
	public function cart_delivery_time( $item_data, $cart_item ) {
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

		if ( ! self::made_to_order( $product ) ) {
			return $item_data;
		}

		$text = self::item_delivery_text( isset( $cart_item['quantity'] ) ? $cart_item['quantity'] : 1, self::available_now( $product ), self::delivery_days( $product ) );

		if ( '' !== $text ) {
			$item_data[] = array(
				'key'   => __( 'Lieferzeit', 'lindenweg16' ),
				'value' => $text,
			);
		}

		return $item_data;
	}

	/**
	 * Lieferzeit in die Bestellposition übernehmen (Bestätigung, Rechnung).
	 *
	 * @param \WC_Order_Item_Product $item   Position.
	 * @param string                 $key    Schlüssel im Warenkorb.
	 * @param array                  $values Warenkorbposition.
	 */
	public function order_delivery_time( $item, $key, $values ) {
		$product = isset( $values['data'] ) ? $values['data'] : null;

		if ( ! self::made_to_order( $product ) ) {
			return;
		}

		$text = self::item_delivery_text( isset( $values['quantity'] ) ? $values['quantity'] : 1, self::available_now( $product ), self::delivery_days( $product ) );

		if ( '' !== $text ) {
			$item->add_meta_data( __( 'Lieferzeit', 'lindenweg16' ), $text, true );
		}
	}
}
