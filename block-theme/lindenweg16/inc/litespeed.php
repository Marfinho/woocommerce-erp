<?php
/**
 * Verträglichkeit mit LiteSpeed Cache.
 *
 * Die Filter greifen nur, wenn LiteSpeed Cache aktiv ist; sonst fragt sie
 * niemand ab.
 *
 * @package Lindenweg16
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LiteSpeed-Einstellungen, die das Theme vorgibt.
 *
 * LiteSpeed bietet dafür je Einstellung den Filter
 * litespeed_conf_load_option_{id} an und zeigt im eigenen Admin an, dass
 * der Wert per Filter festgelegt ist. Vorgegeben wird nur, was mit
 * WooCommerce-Blöcken nachweislich Fehler macht oder doppelt arbeitet –
 * alles andere (Cache, CSS/JS minimieren, Bilder, CDN …) bleibt, wie es in
 * LiteSpeed eingestellt ist.
 *
 * - Gast-Optimierung aus: Sie erzwingt beim ersten Besuch JS zusammenfassen
 *   und verzögern sowie UCSS. Folge: „wp is not defined“, „_ is not
 *   defined“, Google-Listings-Fehler und eine ungestylte Seite, bis LiteSpeed
 *   neu lädt. Der Gastmodus selbst (schnelle Auslieferung aus dem Cache)
 *   bleibt an.
 * - Kein UCSS: behält nur CSS, das beim Erzeugen sichtbar war – Handy-Menü,
 *   Mini-Cart, Filter und Hinweise fehlen dann.
 * - Kein CSS asynchron: ohne fertiges Critical CSS blitzt die Seite ungestylt.
 * - JS nicht zusammenfassen und höchstens „aufschieben“ (defer), nicht
 *   „verzögern“: Die Interactivity-Module von WooCommerce und Inline-Skripte
 *   der Plugins brauchen ihre Reihenfolge.
 * - Kein Lazy Load per JavaScript: WordPress lädt Bilder selbst nativ
 *   nach und gibt dem ersten großen Bild Vorrang (fetchpriority). Das
 *   JS-Lazy-Load hielt sonst das Produktbild zurück.
 * - Emoji-Skript entfernen.
 *
 * Anpassen: add_filter( 'lindenweg16_litespeed_settings', fn( $s ) => … );
 * Ganz abschalten: add_filter( 'lindenweg16_litespeed_settings', '__return_empty_array' );
 *
 * @return array<string, bool|int>
 */
function lindenweg16_litespeed_settings(): array {
	return (array) apply_filters(
		'lindenweg16_litespeed_settings',
		array(
			'guest_optm'     => false,
			'optm-ucss'      => false,
			'optm-css_async' => false,
			'optm-js_comb'   => false,
			'optm-js_defer'  => 1,
			'media-lazy'     => false,
			'optm-emoji_rm'  => true,
		)
	);
}

/**
 * Hängt die Vorgaben an LiteSpeeds Options-Filter.
 */
function lindenweg16_litespeed_register_settings(): void {
	foreach ( lindenweg16_litespeed_settings() as $id => $value ) {
		add_filter(
			'litespeed_conf_load_option_' . $id,
			static function ( $current ) use ( $id, $value ) {
				// „Verzögern“ (2) wird zu „aufschieben“ (1); aus (0) bleibt aus.
				if ( 'optm-js_defer' === $id ) {
					return min( (int) $current, (int) $value );
				}

				return is_bool( $current ) ? (bool) $value : $value;
			}
		);
	}
}
lindenweg16_litespeed_register_settings();

/**
 * Skripte, die LiteSpeed nicht anfassen soll.
 *
 * Jetpacks WooCommerce-Analytics lädt Teile per Webpack nach und sucht sie
 * neben der eigenen Datei. Nach dem Minimieren liegt die Datei im
 * LiteSpeed-Ordner, das Nachladen schlägt fehl („Loading chunk 591 failed“).
 *
 * @param mixed $excludes Ausschlüsse aus den LiteSpeed-Einstellungen.
 * @return mixed
 */
function lindenweg16_litespeed_js_excludes( $excludes ) {
	$excludes   = is_array( $excludes ) ? $excludes : array();
	$excludes[] = 'woocommerce-analytics';

	return $excludes;
}
add_filter( 'litespeed_optimize_js_excludes', 'lindenweg16_litespeed_js_excludes' );

/**
 * Keine Seitenoptimierung (JS zusammenfassen/verzögern …) auf Warenkorb, Kasse
 * und Mein Konto. Die Cart-/Checkout-Blöcke von WooCommerce brauchen ihre
 * Skripte in der richtigen Reihenfolge – verzögertes JS lässt sie im
 * Ladezustand hängen („wp is not defined“).
 *
 * @param bool $can Darf LiteSpeed optimieren?
 */
function lindenweg16_litespeed_skip_shop_pages( $can ) {
	// Vor der Abfrage (z. B. beim init) kennt WordPress die Seite noch nicht.
	if ( ! did_action( 'wp' ) || ! function_exists( 'is_cart' ) ) {
		return $can;
	}

	if ( is_cart() || is_checkout() || is_account_page() ) {
		return false;
	}

	return $can;
}
add_filter( 'litespeed_can_optm', 'lindenweg16_litespeed_skip_shop_pages' );
