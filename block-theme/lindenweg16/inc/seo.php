<?php
/**
 * Meta-Beschreibung (Suchergebnis-Text), solange kein SEO-Plugin sie liefert.
 *
 * - Startseite: Text aus Design → Lindenweg16 (mit sinnvoller Vorgabe)
 * - Produkte: Kurzbeschreibung; Seiten/Beiträge: Auszug oder Textanfang
 * - Kategorien: Kategoriebeschreibung; Shop: eigener Satz
 *
 * Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework: Das Theme hält sich
 * raus. Jetpack SEO: Das Theme füllt nur dessen leere Beschreibung.
 *
 * @package Lindenweg16
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vorgabe für die Startseite.
 */
function lindenweg16_default_front_description(): string {
	return __( 'Ordnung für dein Rad: Der Haster Haken aus Haste – eine Holzschiene, viele Module für Helm, Schloss, Handschuhe und Werkzeug. Handgemacht von Lindenweg16.', 'lindenweg16' );
}

/**
 * Ist ein SEO-Plugin aktiv, das selbst Beschreibungen ausgibt?
 */
function lindenweg16_has_seo_plugin(): bool {
	return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' );
}

/**
 * Jetpack SEO mit eigener Meta-Ausgabe aktiv?
 */
function lindenweg16_jetpack_seo_active(): bool {
	return class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) && Jetpack::is_module_active( 'seo-tools' ) && apply_filters( 'jetpack_seo_meta_tags_enabled', true );
}

/**
 * Text kürzen: ohne HTML, Shortcodes und Zeilenumbrüche, höchstens ~155 Zeichen.
 *
 * @param string $text Rohtext.
 */
function lindenweg16_description_trim( string $text ): string {
	$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $text ) ) ) );

	if ( mb_strlen( $text ) > 158 ) {
		$text = rtrim( mb_substr( $text, 0, 155 ), " ,.;:–-" ) . '…';
	}

	return $text;
}

/**
 * Beschreibung für die aktuelle Seite (leer = keine).
 */
function lindenweg16_meta_description(): string {
	$text = '';

	if ( is_front_page() ) {
		$text = (string) get_theme_mod( 'lindenweg16_meta_description', '' );
		$text = '' !== trim( $text ) ? $text : lindenweg16_default_front_description();
	} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
		$text = __( 'Alle Sets und Module des Haster Haken: handgemachte Fahrrad-Organisation aus Haste. Schiene wählen, Module dazu – fertig ist die aufgeräumte Wand.', 'lindenweg16' );
	} elseif ( is_singular() ) {
		$post = get_post();
		if ( $post ) {
			$text = 'product' === $post->post_type && '' !== trim( (string) $post->post_excerpt )
				? (string) $post->post_excerpt
				: ( has_excerpt( $post ) ? (string) $post->post_excerpt : (string) $post->post_content );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$text = (string) term_description();
	}

	/**
	 * Filtert die Meta-Beschreibung des Themes.
	 *
	 * @param string $text Beschreibung (ungekürzt).
	 */
	$text = (string) apply_filters( 'lindenweg16_meta_description', $text );

	return lindenweg16_description_trim( $text );
}

/**
 * Ausgabe im <head>, wenn weder SEO-Plugin noch Jetpack SEO zuständig ist.
 */
function lindenweg16_print_meta_description(): void {
	if ( lindenweg16_has_seo_plugin() || lindenweg16_jetpack_seo_active() ) {
		return;
	}

	$text = lindenweg16_meta_description();

	if ( '' !== $text ) {
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $text ) );
	}
}
add_action( 'wp_head', 'lindenweg16_print_meta_description', 1 );

/**
 * Jetpack SEO: leere Beschreibung mit der des Themes füllen.
 *
 * @param mixed $meta Meta-Tags von Jetpack.
 * @return mixed
 */
function lindenweg16_jetpack_meta_description( $meta ) {
	if ( is_array( $meta ) && empty( $meta['description'] ) && ! lindenweg16_has_seo_plugin() ) {
		$text = lindenweg16_meta_description();
		if ( '' !== $text ) {
			$meta['description'] = $text;
		}
	}

	return $meta;
}
add_filter( 'jetpack_seo_meta_tags', 'lindenweg16_jetpack_meta_description' );
