<?php
/**
 * Title: Stichwort-Laufband
 * Slug: lindenweg16/marquee
 * Categories: lindenweg16
 * Keywords: laufband, marquee, stichworte, logos, pillen
 * Viewport Width: 1400
 * Description: Pillen in einer Endlos-Animation, an den Rändern ausgeblendet. Ohne JavaScript oder bei reduzierter Bewegung eine ruhige Pillen-Reihe.
 *
 * @package Lindenweg16
 */

$lindenweg16_words = array(
	__( 'Helm', 'lindenweg16' ),
	__( 'Bügelschloss', 'lindenweg16' ),
	__( 'Handschuhe', 'lindenweg16' ),
	__( 'Multitool', 'lindenweg16' ),
	__( 'Rücklicht', 'lindenweg16' ),
	__( 'Pumpe', 'lindenweg16' ),
	__( 'Regenjacke', 'lindenweg16' ),
	__( 'Trinkflasche', 'lindenweg16' ),
	__( 'Schlüssel', 'lindenweg16' ),
);
$lindenweg16_colors = array( 'accent-1', 'accent-3', 'accent-4', 'surface' );
?>
<!-- wp:group {"tagName":"section","align":"full","className":"lw16-section lw16-marquee","layout":{"type":"default"}} -->
<section class="wp-block-group alignfull lw16-section lw16-marquee">
	<!-- wp:group {"className":"lw16-marquee__track","layout":{"type":"flex","flexWrap":"nowrap"}} -->
	<div class="wp-block-group lw16-marquee__track">
<?php foreach ( $lindenweg16_words as $lindenweg16_i => $lindenweg16_word ) : ?>
<?php $lindenweg16_color = $lindenweg16_colors[ $lindenweg16_i % count( $lindenweg16_colors ) ]; ?>
		<!-- wp:paragraph {"className":"is-style-chip","backgroundColor":"<?php echo esc_attr( $lindenweg16_color ); ?>","fontSize":"large"} -->
		<p class="is-style-chip has-<?php echo esc_attr( $lindenweg16_color ); ?>-background-color has-background has-large-font-size"><?php echo esc_html( $lindenweg16_word ); ?></p>
		<!-- /wp:paragraph -->
<?php endforeach; ?>
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->
