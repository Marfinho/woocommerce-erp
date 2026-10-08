<?php
/**
 * Title: Blog-Kopf
 * Slug: lindenweg16/hidden-blog-head
 * Inserter: no
 *
 * @package Lindenweg16
 */

?>
<!-- wp:group {"align":"wide","className":"lw16-archive-head","layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group alignwide lw16-archive-head">
	<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
	<p class="is-style-eyebrow"><?php esc_html_e( 'Journal', 'lindenweg16' ); ?></p>
	<!-- /wp:paragraph -->
	<!-- wp:heading {"level":1,"fontSize":"hero"} -->
	<h1 class="wp-block-heading has-hero-font-size"><?php
		printf(
			/* translators: %s: hervorgehobenes Wort. */
			esc_html__( 'Aus der %s', 'lindenweg16' ),
			'<mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color">' . esc_html__( 'Werkstatt', 'lindenweg16' ) . '</mark>'
		);
	?></h1>
	<!-- /wp:heading -->
	<!-- wp:paragraph {"textColor":"text-muted"} -->
	<p class="has-text-muted-color has-text-color"><?php esc_html_e( 'Geschichten vom Hobeln und Drucken, Tipps für dein Rad und alles Neue rund um den Haster Haken.', 'lindenweg16' ); ?></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
