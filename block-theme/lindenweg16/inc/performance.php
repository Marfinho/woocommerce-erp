<?php
/**
 * Ladezeit und Verträglichkeit mit Plugins.
 *
 * Alles hier ist abwärtskompatibel: Fehlt ein Plugin, wird sein Filter nie
 * abgefragt.
 *
 * @package Lindenweg16
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Die beiden Schriften für den ersten Bildschirm (Überschriften, Fließtext)
 * gleich mit dem HTML anfordern statt erst nach dem CSS.
 */
function lindenweg16_preload_fonts(): void {
	$fonts = array(
		'assets/fonts/bricolage-grotesque-latin-wght-normal.woff2',
		'assets/fonts/inter-latin-wght-normal.woff2',
	);

	foreach ( $fonts as $font ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( get_theme_file_uri( $font ) )
		);
	}
}
add_action( 'wp_head', 'lindenweg16_preload_fonts', 2 );

/**
 * Kein Emoji-Skript von WordPress: Alle aktuellen Systeme zeigen Emojis
 * selbst an. Spart ein Skript und eine Prüfung auf jeder Seite.
 */
function lindenweg16_disable_emojis(): void {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	add_filter( 'emoji_svg_url', '__return_false' );
}
add_action( 'init', 'lindenweg16_disable_emojis' );

/**
 * Smush: kein Lazy Load per JavaScript.
 *
 * WordPress lädt Bilder selbst nativ nach und gibt dem ersten großen Bild
 * (z. B. dem Produktfoto) Vorrang. Smush setzte zusätzlich loading="lazy"
 * auch auf dieses Bild – es kam als Letztes. Bildkomprimierung und alles
 * andere von Smush bleibt.
 */
add_filter( 'wp_smush_should_skip_lazy_load', '__return_true' );

/**
 * Complianz: Pinterest-Ereignisse erst nach Einwilligung.
 *
 * Pinterest for WooCommerce schreibt den Tag in ein Skript (das Complianz
 * erkennt und blockiert) und die Seitenaufruf-Meldung in ein zweites (das
 * Complianz nicht erkennt). Das zweite lief ohne den Tag – „pintrk is not
 * defined“ auf jeder Seite – und schickte die Meldung an den Server, bevor
 * jemand zugestimmt hatte. Jetzt gehören beide zum Dienst Pinterest
 * (Marketing) und starten gemeinsam nach der Einwilligung.
 *
 * @param array<int, array<string, mixed>> $tags Bekannte Skripte.
 * @return array<int, array<string, mixed>>
 */
function lindenweg16_complianz_pinterest( $tags ) {
	if ( ! is_array( $tags ) ) {
		return $tags;
	}

	$tags[] = array(
		'name'     => 'pinterest',
		'category' => 'marketing',
		'urls'     => array( 'pintrk(' ),
	);

	return $tags;
}
add_filter( 'cmplz_known_script_tags', 'lindenweg16_complianz_pinterest' );
