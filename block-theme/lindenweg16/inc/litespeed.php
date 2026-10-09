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
 * Kein „Unique CSS“ (UCSS) von QUIC.cloud.
 *
 * UCSS behält nur die CSS-Regeln, die der Crawler beim Erzeugen auf der Seite
 * gesehen hat. Was erst durch Klicks oder Skripte entsteht – offenes
 * Handy-Menü, Mini-Cart, Filter-Overlay, Varianten-Chips, Hinweise – fehlt
 * dann, und eine veraltete UCSS-Datei (z. B. noch vom vorherigen Theme) lässt
 * die Seite ganz ungestylt aussehen. Besonders betroffen ist der Erstbesuch,
 * weil „Gast-Optimierung“ UCSS dort immer erzwingt.
 *
 * Statt UCSS liefert LiteSpeed dann die normal zusammengefasste und minimierte
 * CSS-Datei aus – mit allen Regeln. Alle anderen Optimierungen bleiben an.
 *
 * Wer UCSS trotzdem nutzen will: add_filter( 'lindenweg16_litespeed_ucss', '__return_true' );
 *
 * @param mixed $excludes URI-Ausschlüsse aus den LiteSpeed-Einstellungen.
 * @return mixed
 */
function lindenweg16_litespeed_no_ucss( $excludes ) {
	if ( apply_filters( 'lindenweg16_litespeed_ucss', false ) ) {
		return $excludes;
	}

	$excludes   = is_array( $excludes ) ? $excludes : array();
	$excludes[] = '/'; // Trifft jede Adresse.

	return $excludes;
}
add_filter( 'litespeed_ucss_exc', 'lindenweg16_litespeed_no_ucss' );

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
