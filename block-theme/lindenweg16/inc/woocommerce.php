<?php
/**
 * WooCommerce-Anbindung des Themes.
 *
 * Nur Filter, keine überschriebenen Templates: Die Shop-Seiten bestehen
 * ausschließlich aus WooCommerce-Blöcken (siehe /templates).
 *
 * @package Lindenweg16
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Platzhalter-Betrag für „Noch X € bis zum kostenlosen Versand“, solange in
 * WooCommerce keine Versandart „Kostenloser Versand“ mit Mindestbestellwert
 * eingerichtet ist.
 */
const LINDENWEG16_FREE_SHIPPING_PLACEHOLDER = 50.0;

/**
 * Ab welchem Warenwert der Versand kostenlos ist.
 *
 * Nimmt den kleinsten Mindestbestellwert aller aktiven Versandarten
 * „Kostenloser Versand“. Ohne solche Versandart greift der Platzhalter.
 * 0 blendet den Fortschrittsbalken aus.
 */
function lindenweg16_free_shipping_threshold(): float {
	static $threshold = null;

	if ( null !== $threshold ) {
		return $threshold;
	}

	$detected = 0.0;

	if ( class_exists( 'WC_Shipping_Zones' ) ) {
		$zones   = WC_Shipping_Zones::get_zones();
		$zones[] = array( 'shipping_methods' => ( new WC_Shipping_Zone( 0 ) )->get_shipping_methods( true ) );

		foreach ( $zones as $zone ) {
			foreach ( (array) ( $zone['shipping_methods'] ?? array() ) as $method ) {
				if ( ! $method instanceof WC_Shipping_Method || 'free_shipping' !== $method->id || 'yes' !== $method->enabled ) {
					continue;
				}

				$requires = (string) $method->get_option( 'requires', '' );
				$amount   = (float) $method->get_option( 'min_amount', 0 );

				if ( $amount > 0 && in_array( $requires, array( 'min_amount', 'either', 'both' ), true ) ) {
					$detected = $detected > 0 ? min( $detected, $amount ) : $amount;
				}
			}
		}
	}

	/**
	 * Filtert den Betrag für den Fortschrittsbalken im Mini-Cart.
	 *
	 * @param float $threshold Betrag in Shop-Währung; 0 blendet den Balken aus.
	 */
	$threshold = max( 0.0, (float) apply_filters( 'lindenweg16_free_shipping_threshold', $detected > 0 ? $detected : LINDENWEG16_FREE_SHIPPING_PLACEHOLDER ) );

	return $threshold;
}

/**
 * „inkl. MwSt., zzgl. Versand“ unter jedem Preis – aber nur, wenn weder
 * Germanized noch German Market aktiv ist. Die geben ihre eigenen Hinweise
 * (Grundpreis, Lieferzeit, Steuer, Versand) aus; das Theme stylt sie nur.
 *
 * @param string $content Gerenderter Preis-Block.
 */
function lindenweg16_price_note( string $content ): string {
	if ( '' === trim( $content ) || is_admin() || lindenweg16_has_legal_plugin() ) {
		return $content;
	}

	$shipping_url = (string) apply_filters( 'lindenweg16_shipping_page_url', lindenweg16_page_url( 'versand-und-zahlung' ) );

	if ( wc_tax_enabled() && 'incl' === get_option( 'woocommerce_tax_display_shop' ) ) {
		/* translators: %s: Link „Versand“. */
		$text = __( 'inkl. MwSt., zzgl. %s', 'lindenweg16' );
	} else {
		/* translators: %s: Link „Versand“. */
		$text = __( 'zzgl. %s', 'lindenweg16' );
	}

	$link = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( $shipping_url ),
		esc_html__( 'Versand', 'lindenweg16' )
	);

	return $content . sprintf(
		'<p class="lw16-price-note">%s</p>',
		sprintf( esc_html( $text ), $link )
	);
}
add_filter( 'render_block_woocommerce/product-price', 'lindenweg16_price_note' );

/**
 * Der Bestell-Button heißt „Zahlungspflichtig bestellen“ (§ 312j Abs. 3 BGB).
 * Der Text steht auch im Template; hier wird er übersetzbar gemacht.
 *
 * @param array<string, mixed> $block Geparster Block.
 * @return array<string, mixed>
 */
function lindenweg16_place_order_label( array $block ): array {
	if ( 'woocommerce/checkout-actions-block' !== ( $block['blockName'] ?? '' ) ) {
		return $block;
	}

	$label = (string) ( $block['attrs']['placeOrderButtonLabel'] ?? '' );

	if ( '' === $label || 'Zahlungspflichtig bestellen' === $label ) {
		$block['attrs']['placeOrderButtonLabel'] = __( 'Zahlungspflichtig bestellen', 'lindenweg16' );
	}

	return $block;
}
add_filter( 'render_block_data', 'lindenweg16_place_order_label' );

/**
 * Derselbe Text für Zahlarten, die auf den klassischen Button zurückfallen.
 */
function lindenweg16_order_button_text(): string {
	return __( 'Zahlungspflichtig bestellen', 'lindenweg16' );
}
add_filter( 'woocommerce_order_button_text', 'lindenweg16_order_button_text' );

/**
 * Tab-Titel auf der Produktseite: Beschreibung, Details, Bewertungen.
 *
 * @param array<string, array<string, mixed>> $tabs Produkt-Tabs.
 * @return array<string, array<string, mixed>>
 */
function lindenweg16_product_tabs( array $tabs ): array {
	if ( isset( $tabs['additional_information'] ) ) {
		$tabs['additional_information']['title'] = __( 'Details', 'lindenweg16' );
	}

	return $tabs;
}
add_filter( 'woocommerce_product_tabs', 'lindenweg16_product_tabs', 20 );

/**
 * Klassische Shop-Hinweise (z. B. im Kundenkonto) bekommen dieselbe
 * Pillen-Optik wie die Block-Hinweise – hier nur eine Klasse am Body.
 *
 * @param string[] $classes Body-Klassen.
 * @return string[]
 */
function lindenweg16_woocommerce_body_class( array $classes ): array {
	if ( function_exists( 'is_account_page' ) && is_account_page() ) {
		$classes[] = 'lw16-account';
	}

	return $classes;
}
add_filter( 'body_class', 'lindenweg16_woocommerce_body_class' );
