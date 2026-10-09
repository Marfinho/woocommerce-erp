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

	// Klassische Menüs: Design → Menüs (und Customizer → Menüs) funktionieren
	// damit auch ohne Website-Editor. Header und Footer übernehmen die Menüs.
	register_nav_menus(
		array(
			'primary'        => __( 'Hauptmenü (Header)', 'lindenweg16' ),
			'footer-shop'    => __( 'Footer: Spalte 1 (Shop)', 'lindenweg16' ),
			'footer-service' => __( 'Footer: Spalte 2 (Service)', 'lindenweg16' ),
			'footer-legal'   => __( 'Footer: Spalte 3 (Rechtliches)', 'lindenweg16' ),
		)
	);

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
 * Link zu einer Produktkategorie anhand ihres Slugs – passend zu den
 * Permalink-Einstellungen. Gibt es die Kategorie (noch) nicht, führt der
 * Link in den Shop.
 */
function lindenweg16_category_url( string $slug ): string {
	if ( taxonomy_exists( 'product_cat' ) ) {
		$link = get_term_link( $slug, 'product_cat' );
		if ( is_string( $link ) ) {
			return $link;
		}
	}

	return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
}

/**
 * Blockmarkup für die Links einer Navigation – aus dem klassischen Menü am
 * Menü-Ort, sonst aus den Standard-Links des Themes.
 *
 * So lassen sich Header und Footer unter Design → Menüs pflegen, ohne den
 * Website-Editor zu öffnen. Untermenüs (eine Ebene) werden übernommen.
 *
 * @param string                $location Menü-Ort.
 * @param array<string, string> $fallback Standard-Links: Beschriftung => URL.
 */
function lindenweg16_menu_link_blocks( string $location, array $fallback ): string {
	$items     = array();
	$locations = get_nav_menu_locations();

	if ( ! empty( $locations[ $location ] ) ) {
		$menu_items = wp_get_nav_menu_items( (int) $locations[ $location ], array( 'update_post_term_cache' => false ) );
		if ( is_array( $menu_items ) ) {
			$items = $menu_items;
		}
	}

	$link = static function ( string $label, string $url ): string {
		$attrs = array(
			'label'          => $label,
			'url'            => $url,
			'kind'           => 'custom',
			'isTopLevelLink' => true,
		);

		return '<!-- wp:navigation-link ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . ' /-->';
	};

	if ( ! $items ) {
		$html = '';
		foreach ( $fallback as $label => $url ) {
			$html .= $link( (string) $label, (string) $url ) . "\n";
		}

		return $html;
	}

	$children = array();
	foreach ( $items as $item ) {
		$children[ (int) $item->menu_item_parent ][] = $item;
	}

	$html = '';
	foreach ( $children[0] ?? array() as $item ) {
		$label = wp_strip_all_tags( (string) $item->title );
		$url   = (string) $item->url;

		if ( empty( $children[ (int) $item->ID ] ) ) {
			$html .= $link( $label, $url ) . "\n";
			continue;
		}

		$attrs = wp_json_encode(
			array(
				'label' => $label,
				'url'   => $url,
				'kind'  => 'custom',
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
		);

		$html .= '<!-- wp:navigation-submenu ' . $attrs . ' -->' . "\n";
		foreach ( $children[ (int) $item->ID ] as $child ) {
			$html .= $link( wp_strip_all_tags( (string) $child->title ), (string) $child->url ) . "\n";
		}
		$html .= '<!-- /wp:navigation-submenu -->' . "\n";
	}

	return $html;
}

/**
 * Name des Menüs an einem Menü-Ort – für die Spaltenüberschriften im Footer.
 */
function lindenweg16_menu_name( string $location, string $fallback ): string {
	$locations = get_nav_menu_locations();

	if ( ! empty( $locations[ $location ] ) ) {
		$menu = wp_get_nav_menu_object( (int) $locations[ $location ] );
		if ( $menu && '' !== trim( (string) $menu->name ) ) {
			return (string) $menu->name;
		}
	}

	return $fallback;
}

/**
 * Abschnitte der Startseite, in dieser Reihenfolge.
 *
 * @return string[] Pattern-Slugs.
 */
function lindenweg16_front_page_sections(): array {
	return (array) apply_filters(
		'lindenweg16_front_page_sections',
		array(
			'lindenweg16/hero',
			'lindenweg16/trust-bar',
			'lindenweg16/category-bento',
			'lindenweg16/product-highlights',
			'lindenweg16/how-it-works',
			'lindenweg16/testimonials',
			'lindenweg16/faq',
			'lindenweg16/newsletter',
			'lindenweg16/cta-dark',
		)
	);
}

/**
 * ID der statischen Startseite, wenn sie mit den Lindenweg16-Abschnitten
 * gebaut ist – dann zeigt die Startseite deren Inhalt. Eine ältere
 * Startseite (z. B. aus einem früheren Theme) wird nicht übernommen.
 */
function lindenweg16_editable_front_page_id(): int {
	if ( 'page' !== get_option( 'show_on_front' ) ) {
		return 0;
	}

	$page = get_post( (int) get_option( 'page_on_front' ) );

	if ( ! $page instanceof WP_Post || 'publish' !== $page->post_status ) {
		return 0;
	}

	return false !== strpos( (string) $page->post_content, 'lw16-' ) ? (int) $page->ID : 0;
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

/**
 * Blockvorlage einer Produktkarte für die Product Collection: Bild mit
 * Sale-Sticker und rundem Warenkorb-Knopf, darunter Name und Preis.
 *
 * Steht hier statt in einem eigenen Pattern, weil ein verschachteltes
 * Pattern den Abfrage-Kontext der Collection verlieren würde. Dieselbe
 * Vorlage steht wörtlich in den Shop-Templates.
 */
function lindenweg16_product_card_markup(): string {
	return '<!-- wp:woocommerce/product-template -->
<!-- wp:group {"className":"lw16-card-media","layout":{"type":"default"}} -->
<div class="wp-block-group lw16-card-media">
<!-- wp:woocommerce/product-image {"showSaleBadge":false,"isDescendentOfQueryLoop":true} -->
<!-- wp:woocommerce/product-sale-badge {"isDescendentOfQueryLoop":true,"align":"left"} /-->
<!-- /wp:woocommerce/product-image -->
<!-- wp:woocommerce/product-button {"isDescendentOfQueryLoop":true,"className":"lw16-quick-add"} /-->
</div>
<!-- /wp:group -->
<!-- wp:post-title {"level":3,"isLink":true,"__woocommerceNamespace":"woocommerce/product-collection/product-title"} /-->
<!-- wp:woocommerce/product-price {"isDescendentOfQueryLoop":true} /-->
<!-- /wp:woocommerce/product-template -->';
}

/**
 * Bildplätze der Startseite, die unter Design → Lindenweg16 belegt werden.
 *
 * @return array<string, string> Platz => Bezeichnung.
 */
function lindenweg16_front_image_slots(): array {
	return array(
		'hero'   => __( 'Großes Bild oben (Hero)', 'lindenweg16' ),
		'sets'   => __( 'Kachel „Sets“', 'lindenweg16' ),
		'module' => __( 'Kachel „Module“', 'lindenweg16' ),
	);
}

/**
 * Gewähltes Foto für einen Bildplatz der Startseite.
 *
 * @param string $slot hero, sets oder module.
 * @return array{id:int,url:string,alt:string}|null Null, solange keins gewählt ist – dann bleibt die Illustration.
 */
function lindenweg16_front_image( string $slot ): ?array {
	$id = (int) get_theme_mod( 'lindenweg16_image_' . $slot, 0 );

	if ( ! $id || ! wp_attachment_is_image( $id ) ) {
		return null;
	}

	$url = wp_get_attachment_image_url( $id, 'large' );

	if ( ! $url ) {
		return null;
	}

	return array(
		'id'  => $id,
		'url' => $url,
		'alt' => trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
	);
}

require_once get_template_directory() . '/inc/newsletter.php';
require_once get_template_directory() . '/inc/kundenstimmen.php';
require_once get_template_directory() . '/inc/emails.php';
require_once get_template_directory() . '/inc/litespeed.php';
require_once get_template_directory() . '/inc/performance.php';

if ( is_admin() ) {
	require_once get_template_directory() . '/inc/admin.php';
}

if ( class_exists( 'WooCommerce' ) ) {
	require_once get_template_directory() . '/inc/woocommerce.php';
}
