<?php
/**
 * Title: Dunkler CTA
 * Slug: lindenweg16/cta-dark
 * Categories: lindenweg16, call-to-action
 * Keywords: cta, aufruf, dunkel, button
 * Viewport Width: 1400
 * Description: Dunkle Fläche (ink) mit großen Rundungen, zwei unscharfen Farbflecken, großer Headline und Akzent-Button.
 *
 * @package Lindenweg16
 */

$lindenweg16_shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
?>
<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section lw16-cta","backgroundColor":"ink","textColor":"on-ink","layout":{"type":"flex","orientation":"vertical","justifyContent":"center"}} -->
<section class="wp-block-group alignwide lw16-section lw16-cta has-on-ink-color has-ink-background-color has-text-color has-background">
	<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
	<p class="is-style-eyebrow"><?php esc_html_e( 'Bereit für Ordnung?', 'lindenweg16' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:heading {"textAlign":"center","fontSize":"hero"} -->
	<h2 class="wp-block-heading has-text-align-center has-hero-font-size"><?php esc_html_e( 'Dein Rad verdient einen festen Platz.', 'lindenweg16' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center","fontSize":"large"} -->
	<p class="has-text-align-center has-large-font-size"><?php esc_html_e( 'Starte mit dem Haster-Haken-Set und erweitere es Modul für Modul.', 'lindenweg16' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button {"className":"is-style-accent"} -->
		<div class="wp-block-button is-style-accent"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $lindenweg16_shop ); ?>"><?php esc_html_e( 'Jetzt Haster Haken holen', 'lindenweg16' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
</section>
<!-- /wp:group -->
