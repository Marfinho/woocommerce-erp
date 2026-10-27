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
		if ( ! is_object( $product ) || ! ForecastService::settings()['shop_text'] || ! StockOwnership::is_active() ) {
			return $text;
		}

		$parent = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		$id     = StockOwnership::article_for_product( $parent );

		if ( ! $id ) {
			return $text;
		}

		$map = ForecastService::shop_map();

		if ( ! isset( $map[ $id ] ) ) {
			return $text;
		}

		$available = $map[ $id ]['all'];

		if ( $product->is_type( 'variation' ) ) {
			$attributes = $product->get_attributes();
			$taxonomy   = ColorRegistry::taxonomy();
			$color      = ColorRegistry::normalize( isset( $attributes[ $taxonomy ] ) ? $attributes[ $taxonomy ] : '' );
			$color      = '' === $color ? ColorRegistry::default_color() : $color;
			$available  = isset( $map[ $id ]['colors'][ $color ] ) ? $map[ $id ]['colors'][ $color ] : 0;
		}

		$estimate = null !== $map[ $id ]['weeks'] ? array( 'weeks' => $map[ $id ]['weeks'], 'date_to' => 'x' ) : null;

		return Promise::shop_text( (int) $available, $estimate );
	}
}
