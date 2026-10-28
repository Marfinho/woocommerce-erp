<?php
/**
 * Prämienpunkte im Warenkorb und an der Kasse.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Loyalty;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;
use Lindenweg16\Core\Settings;

/**
 * Zeigt, wie viele Punkte die aktuelle Bestellung bringt – mit Aktionen und
 * Stufe. Block-Warenkorb und -Kasse: Daten über die Store-API, Anzeige im
 * Bereich unter der Summe (assets/js/loyalty-cart.js). Klassischer Warenkorb:
 * eine Zeile in der Summentabelle.
 */
class LoyaltyCart {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Je nach Ladereihenfolge ist WooCommerce Blocks schon geladen.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_data();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_data' ) );
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'woocommerce_cart_totals_after_order_total', array( $this, 'classic_row' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( $this, 'classic_row' ) );
	}

	/**
	 * Punkte für den aktuellen Warenkorb.
	 *
	 * @return array{points:int,text:string,detail:string}
	 */
	public static function estimate() {
		$empty = array( 'points' => 0, 'text' => '', 'detail' => '' );
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return $empty;
		}
		$per_euro = (float) Settings::get( 'punkte_pro_euro', 1 );
		if ( $per_euro <= 0 ) {
			return $empty;
		}

		$lines = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			$cat_src = $product && $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
			$lines[] = array(
				'total' => (float) $item['line_total'] + (float) $item['line_tax'],
				'cats'  => $cat_src ? array_map( 'intval', $cat_src->get_category_ids() ) : array(),
			);
		}

		$result  = LoyaltyRules::order_points( (float) WC()->cart->get_total( 'edit' ), $lines, $per_euro, LoyaltyCampaigns::active() );
		$user_id = get_current_user_id();
		$points  = $result['points'];
		$detail  = $result['names'];
		$tier    = $user_id ? LoyaltyTiers::for_user( $user_id ) : null;
		if ( $tier && $tier['tier']['faktor'] > 1 ) {
			$points = LoyaltyRules::apply_tier( $points, $tier['tier']['faktor'] );
			/* translators: %s: Stufenname */
			$detail[] = sprintf( __( 'Stufe %s', 'lindenweg16' ), $tier['tier']['name'] );
		}
		if ( $points <= 0 ) {
			return $empty;
		}

		$text = $user_id
			/* translators: %s: Punkte */
			? sprintf( __( 'Mit dieser Bestellung sammelst du %s Prämienpunkte', 'lindenweg16' ), number_format_i18n( $points ) )
			/* translators: %s: Punkte */
			: sprintf( __( 'Mit Kundenkonto sammelst du hier %s Prämienpunkte', 'lindenweg16' ), number_format_i18n( $points ) );

		return array(
			'points' => $points,
			'text'   => $text,
			'detail' => implode( ' · ', $detail ),
		);
	}

	/**
	 * Store-API: Punkte an die Warenkorb-Antwort hängen (extensions.lw16).
	 */
	public function register_store_data() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => 'lw16',
				'data_callback'   => array( __CLASS__, 'estimate' ),
				'schema_callback' => array( $this, 'schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Schema der Erweiterung.
	 *
	 * @return array
	 */
	public function schema() {
		return array(
			'points' => array( 'description' => __( 'Prämienpunkte für den Warenkorb', 'lindenweg16' ), 'type' => 'integer', 'readonly' => true ),
			'text'   => array( 'description' => __( 'Hinweistext', 'lindenweg16' ), 'type' => 'string', 'readonly' => true ),
			'detail' => array( 'description' => __( 'Aktionen und Stufe', 'lindenweg16' ), 'type' => 'string', 'readonly' => true ),
		);
	}

	/**
	 * Anzeige für Block-Warenkorb und -Kasse laden.
	 */
	public function enqueue() {
		if ( ! function_exists( 'is_cart' ) || ( ! is_cart() && ! is_checkout() ) || ! wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
			return;
		}
		wp_enqueue_script( 'lw16-loyalty-cart', LW16_PLUGIN_URL . 'assets/js/loyalty-cart.js', array( 'wp-plugins', 'wp-element', 'wc-blocks-checkout' ), Assets::version( 'assets/js/loyalty-cart.js' ), true );
	}

	/**
	 * Klassischer Warenkorb und klassische Kasse: Zeile in der Summentabelle.
	 */
	public function classic_row() {
		$e = self::estimate();
		if ( ! $e['points'] ) {
			return;
		}
		echo '<tr class="lw16-cart-points"><td colspan="2"><span class="lw16-cart-points__main">' . esc_html( $e['text'] ) . '</span>';
		if ( $e['detail'] ) {
			echo ' <small>' . esc_html( $e['detail'] ) . '</small>';
		}
		echo '</td></tr>';
	}
}
