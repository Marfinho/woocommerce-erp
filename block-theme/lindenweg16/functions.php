<?php
/**
 * Lindenweg16 – Funktionen des Block-Themes.
 *
 * Hier steht nur, was theme.json nicht kann: Theme-Support für WooCommerce,
 * Block-Styles, das Einbinden von CSS/JS und die Pattern-Kategorie.
 * Alles rund um WooCommerce liegt in inc/woocommerce.php.
 *
 * @package Lindenweg16
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LINDENWEG16_VERSION', (string) ( wp_get_theme( get_template() )->get( 'Version' ) ?: '1.0.0' ) );

/**
 * Theme-Support.
 */
function lindenweg16_setup(): void {
	load_theme_textdomain( 'lindenweg16', get_template_directory() . '/languages' );

	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 96,
			'width'       => 320,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);

	add_theme_support(
		'woocommerce',
		array(
			'thumbnail_image_width' => 600,
			'single_image_width'    => 1200,
			'product_grid'          => array(
				'default_rows'    => 4,
				'min_rows'        => 1,
				'default_columns' => 3,
				'min_columns'     => 2,
				'max_columns'     => 4,
			),
		)
	);
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	// Die WordPress-eigenen Beispiel-Patterns verdrängen sonst die eigenen im Inserter.
	remove_theme_support( 'core-block-patterns' );

	// Dieselbe Optik im Editor wie auf der Seite.
	add_editor_style( 'assets/css/theme.css' );
}
add_action( 'after_setup_theme', 'lindenweg16_setup' );

/**
 * Stylesheet und das kleine Skript (ohne jQuery) für das Frontend.
 */
function lindenweg16_enqueue_assets(): void {
	wp_enqueue_style(
		'lindenweg16-theme',
		get_theme_file_uri( 'assets/css/theme.css' ),
		array(),
		LINDENWEG16_VERSION
	);

	wp_enqueue_script(
		'lindenweg16-theme',
		get_theme_file_uri( 'assets/js/theme.js' ),
		array(),
		LINDENWEG16_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);

	wp_add_inline_script(
		'lindenweg16-theme',
		'window.lindenweg16 = ' . wp_json_encode( lindenweg16_script_data(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'lindenweg16_enqueue_assets' );

/**
 * Daten und übersetzte Texte für assets/js/theme.js.
 *
 * @return array<string, mixed>
 */
function lindenweg16_script_data(): array {
	$data = array(
		'i18n' => array(
			/* translators: %s: Restbetrag bis zum kostenlosen Versand, z. B. „12,50 €“. */
			'freeShippingLeft'   => __( 'Noch %s bis zum kostenlosen Versand', 'lindenweg16' ),
			'freeShippingDone'   => __( 'Juhu – dein Versand ist kostenlos!', 'lindenweg16' ),
			'stickyAddToCart'    => __( 'In den Warenkorb', 'lindenweg16' ),
			'stickyChooseOption' => __( 'Ausführung wählen', 'lindenweg16' ),
		),
		'freeShipping' => null,
		'storeApi'     => esc_url_raw( rest_url( 'wc/store/v1/cart' ) ),
	);

	if ( function_exists( 'lindenweg16_free_shipping_threshold' ) && function_exists( 'get_woocommerce_currency_symbol' ) ) {
		$threshold = lindenweg16_free_shipping_threshold();
		if ( $threshold > 0 ) {
			$data['freeShipping'] = array(
				'threshold'   => $threshold,
				'decimals'    => wc_get_price_decimals(),
				'decimalSep'  => wc_get_price_decimal_separator(),
				'thousandSep' => wc_get_price_thousand_separator(),
				'symbol'      => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'position'    => get_option( 'woocommerce_currency_pos', 'right_space' ),
			);
		}
	}

	return $data;
}

/**
 * Block-Styles. Das Aussehen steht in assets/css/theme.css unter .is-style-*.
 */
function lindenweg16_register_block_styles(): void {
	$styles = array(
		'core/button'     => array(
			'primary' => __( 'Primär', 'lindenweg16' ),
			'soft'    => __( 'Soft', 'lindenweg16' ),
			'accent'  => __( 'Akzent', 'lindenweg16' ),
		),
		'core/group'      => array(
			'card'    => __( 'Karte', 'lindenweg16' ),
			'glass'   => __( 'Glas', 'lindenweg16' ),
			'sticker' => __( 'Sticker', 'lindenweg16' ),
		),
		'core/paragraph'  => array(
			'eyebrow' => __( 'Eyebrow', 'lindenweg16' ),
			'sticker' => __( 'Sticker', 'lindenweg16' ),
			'chip'    => __( 'Chip', 'lindenweg16' ),
		),
		'core/post-terms' => array(
			'eyebrow' => __( 'Eyebrow', 'lindenweg16' ),
		),
		'core/image'      => array(
			'float' => __( 'Schwebend', 'lindenweg16' ),
		),
		'core/list'       => array(
			'check' => __( 'Häkchen', 'lindenweg16' ),
		),
	);

	foreach ( $styles as $block => $variants ) {
		foreach ( $variants as $name => $label ) {
			register_block_style(
				$block,
				array(
					'name'       => $name,
					'label'      => $label,
					'is_default' => 'core/button' === $block && 'primary' === $name,
				)
			);
		}
	}
}
add_action( 'init', 'lindenweg16_register_block_styles' );

/**
 * Pattern-Kategorie. Die Patterns selbst liegen in /patterns und werden von
 * WordPress anhand ihres Datei-Headers automatisch registriert.
 */
function lindenweg16_register_pattern_categories(): void {
	register_block_pattern_category(
		'lindenweg16',
		array(
			'label'       => __( 'Lindenweg16', 'lindenweg16' ),
			'description' => __( 'Abschnitte für den Shop von Lindenweg16.', 'lindenweg16' ),
		)
	);
}
add_action( 'init', 'lindenweg16_register_pattern_categories', 9 );

/**
 * URL einer Seite anhand ihres Pfads, mit Rückfall auf einen festen Pfad.
 * Für die Links in Footer und Preis-Hinweis, bevor die Seiten angelegt sind.
 */
function lindenweg16_page_url( string $path ): string {
	$page = get_page_by_path( $path );

	return $page instanceof WP_Post ? (string) get_permalink( $page ) : home_url( '/' . trailingslashit( $path ) );
}

/**
 * Ist Germanized oder German Market aktiv? Dann kommen Grundpreis,
 * Lieferzeit, Steuer- und Versandhinweis von dort, nicht vom Theme.
 */
function lindenweg16_has_legal_plugin(): bool {
	$active = class_exists( 'WooCommerce_Germanized' ) || class_exists( 'Woocommerce_German_Market' ) || class_exists( 'WGM_Template' );

	/**
	 * Filtert, ob ein Rechtstexte-Plugin die Preis-Hinweise ausgibt.
	 *
	 * @param bool $active Erkanntes Ergebnis.
	 */
	return (bool) apply_filters( 'lindenweg16_has_legal_plugin', $active );
}

/**
 * Body-Klasse, damit das CSS weiß, wer die Hinweise ausgibt.
 *
 * @param string[] $classes Body-Klassen.
 * @return string[]
 */
function lindenweg16_body_class( array $classes ): array {
	if ( lindenweg16_has_legal_plugin() ) {
		$classes[] = 'lw16-legal-plugin';
	}

	return $classes;
}
add_filter( 'body_class', 'lindenweg16_body_class' );

if ( class_exists( 'WooCommerce' ) ) {
	require_once get_template_directory() . '/inc/woocommerce.php';
}
