<?php
/**
 * Title: 404 – Seite nicht gefunden
 * Slug: lindenweg16/hidden-404
 * Inserter: no
 *
 * @package Lindenweg16
 */

$lindenweg16_shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
?>
<!-- wp:group {"className":"is-style-card lw16-empty","layout":{"type":"flex","orientation":"vertical","justifyContent":"center"}} -->
<div class="wp-block-group is-style-card lw16-empty">
	<!-- wp:paragraph {"className":"is-style-sticker"} -->
	<p class="is-style-sticker"><?php esc_html_e( 'Fehler 404', 'lindenweg16' ); ?></p>
	<!-- /wp:paragraph -->
	<!-- wp:heading {"level":1,"textAlign":"center","fontSize":"hero"} -->
	<h1 class="wp-block-heading has-text-align-center has-hero-font-size"><?php
		printf(
			/* translators: %s: hervorgehobenes Wort. */
			esc_html__( 'Hier hängt %s.', 'lindenweg16' ),
			'<mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color">' . esc_html__( 'nichts', 'lindenweg16' ) . '</mark>'
		);
	?></h1>
	<!-- /wp:heading -->
	<!-- wp:paragraph {"align":"center","textColor":"text-muted"} -->
	<p class="has-text-align-center has-text-muted-color has-text-color"><?php esc_html_e( 'Die Seite gibt es nicht (mehr). Vielleicht findest du über die Suche, was du brauchst – oder du schaust direkt im Shop vorbei.', 'lindenweg16' ); ?></p>
	<!-- /wp:paragraph -->
	<!-- wp:search {"label":"<?php echo esc_attr__( 'Suche', 'lindenweg16' ); ?>","showLabel":false,"placeholder":"<?php echo esc_attr__( 'Wonach suchst du?', 'lindenweg16' ); ?>","buttonText":"<?php echo esc_attr__( 'Suchen', 'lindenweg16' ); ?>","buttonUseIcon":true,"query":{"post_type":"product"}} /-->
	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button {"className":"is-style-primary"} -->
		<div class="wp-block-button is-style-primary"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $lindenweg16_shop ); ?>"><?php esc_html_e( 'Zum Shop', 'lindenweg16' ); ?></a></div>
		<!-- /wp:button -->
		<!-- wp:button {"className":"is-style-soft"} -->
		<div class="wp-block-button is-style-soft"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Zur Startseite', 'lindenweg16' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
