<?php
/**
 * Title: Hero mit Stickern
 * Slug: lindenweg16/hero
 * Categories: lindenweg16, featured, banner
 * Keywords: hero, startseite, headline, sticker
 * Viewport Width: 1400
 * Description: Eyebrow-Sticker, große Headline mit Marker, Unterzeile, zwei Buttons und ein großes Bild mit zwei schwebenden Stickern.
 *
 * @package Lindenweg16
 */

$lindenweg16_shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
?>
<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section lw16-hero","layout":{"type":"default"}} -->
<section class="wp-block-group alignwide lw16-section lw16-hero">
	<!-- wp:columns {"verticalAlignment":"center","className":"lw16-hero__columns"} -->
	<div class="wp-block-columns are-vertically-aligned-center lw16-hero__columns">
		<!-- wp:column {"verticalAlignment":"center","width":"52%","layout":{"type":"flex","orientation":"vertical"}} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:52%">
			<!-- wp:paragraph {"className":"is-style-sticker"} -->
			<p class="is-style-sticker"><?php esc_html_e( 'Handgemacht in Haste', 'lindenweg16' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":1,"fontSize":"hero"} -->
			<h1 class="wp-block-heading has-hero-font-size"><?php
				printf(
					/* translators: %s: hervorgehobenes Wort (Produktname). */
					esc_html__( 'Ordnung für dein Rad – mit dem %s', 'lindenweg16' ),
					'<mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color">' . esc_html__( 'Haster Haken', 'lindenweg16' ) . '</mark>'
				);
			?></h1>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"textColor":"text-muted","fontSize":"large"} -->
			<p class="has-text-muted-color has-text-color has-large-font-size"><?php esc_html_e( 'Eine Holzschiene, viele Module: Helm, Schloss, Handschuhe und Werkzeug bekommen ihren festen Platz – direkt neben deinem Fahrrad. Kein Suchen mehr vor der Tour.', 'lindenweg16' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-primary"} -->
				<div class="wp-block-button is-style-primary"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $lindenweg16_shop ); ?>"><?php esc_html_e( 'Zum Shop', 'lindenweg16' ); ?></a></div>
				<!-- /wp:button -->

				<!-- wp:button {"className":"is-style-soft"} -->
				<div class="wp-block-button is-style-soft"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( lindenweg16_category_url( 'sets' ) ); ?>"><?php esc_html_e( 'Sets entdecken', 'lindenweg16' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"center","width":"48%"} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:48%">
			<!-- wp:group {"className":"lw16-hero__media","backgroundColor":"accent-3","layout":{"type":"default"}} -->
			<div class="wp-block-group lw16-hero__media has-accent-3-background-color has-background">
				<!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"lw16-hero__image"} -->
				<figure class="wp-block-image size-full lw16-hero__image"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/haster-haken-wand.svg' ) ); ?>" alt="<?php esc_attr_e( 'Der Haster Haken an der Wand: Holzschiene mit Helm, Bügelschloss und Handschuhen', 'lindenweg16' ); ?>"/></figure>
				<!-- /wp:image -->

				<!-- wp:paragraph {"className":"is-style-sticker lw16-hero__sticker lw16-hero__sticker--top lw16-icon lw16-icon--heart","backgroundColor":"accent-1"} -->
				<p class="is-style-sticker lw16-hero__sticker lw16-hero__sticker--top lw16-icon lw16-icon--heart has-accent-1-background-color has-background"><?php esc_html_e( 'Handgemacht', 'lindenweg16' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:paragraph {"className":"is-style-sticker lw16-hero__sticker lw16-hero__sticker--bottom lw16-icon lw16-icon--truck","backgroundColor":"accent-4"} -->
				<p class="is-style-sticker lw16-hero__sticker lw16-hero__sticker--bottom lw16-icon lw16-icon--truck has-accent-4-background-color has-background"><?php esc_html_e( 'Versand in 48 h', 'lindenweg16' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</section>
<!-- /wp:group -->
