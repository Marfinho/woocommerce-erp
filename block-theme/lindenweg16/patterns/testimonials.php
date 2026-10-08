<?php
/**
 * Title: Kundenstimmen
 * Slug: lindenweg16/testimonials
 * Categories: lindenweg16, testimonials
 * Keywords: bewertungen, zitate, kunden, sterne
 * Viewport Width: 1400
 * Description: Drei Karten mit Zitat, Name und Sternen.
 *
 * @package Lindenweg16
 */

$lindenweg16_quotes = array(
	array(
		'quote' => __( 'Endlich fliegt der Helm nicht mehr im Flur herum. Und der Haken sieht an der Wand sogar richtig schön aus.', 'lindenweg16' ),
		'name'  => __( 'Jana', 'lindenweg16' ),
		'meta'  => __( 'Pendelt mit dem Gravelbike', 'lindenweg16' ),
	),
	array(
		'quote' => __( 'Erst das Starter-Set, zwei Wochen später das Schlossmodul nachgekauft. Passt perfekt, ohne neu zu bohren.', 'lindenweg16' ),
		'name'  => __( 'Malte', 'lindenweg16' ),
		'meta'  => __( 'Lastenrad, Familie mit drei Kids', 'lindenweg16' ),
	),
	array(
		'quote' => __( 'Super Holzqualität und eine handgeschriebene Karte im Paket. So macht Bestellen Spaß.', 'lindenweg16' ),
		'name'  => __( 'Aylin', 'lindenweg16' ),
		'meta'  => __( 'Rennrad am Wochenende', 'lindenweg16' ),
	),
);
?>
<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section","layout":{"type":"default"}} -->
<section class="wp-block-group alignwide lw16-section">
	<!-- wp:group {"className":"lw16-section-head","layout":{"type":"flex","orientation":"vertical"}} -->
	<div class="wp-block-group lw16-section-head">
		<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
		<p class="is-style-eyebrow"><?php esc_html_e( 'Kundenstimmen', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- wp:heading -->
		<h2 class="wp-block-heading"><?php esc_html_e( 'Das sagen andere Radfahrer', 'lindenweg16' ); ?></h2>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:group -->

	<!-- wp:columns -->
	<div class="wp-block-columns">
<?php foreach ( $lindenweg16_quotes as $lindenweg16_quote ) : ?>
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:group {"className":"is-style-card lw16-testimonial","layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
			<div class="wp-block-group is-style-card lw16-testimonial">
				<!-- wp:quote -->
				<blockquote class="wp-block-quote">
					<!-- wp:paragraph -->
					<p><?php echo esc_html( $lindenweg16_quote['quote'] ); ?></p>
					<!-- /wp:paragraph -->
				</blockquote>
				<!-- /wp:quote -->

				<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"},"style":{"spacing":{"blockGap":"4px"}}} -->
				<div class="wp-block-group">
					<!-- wp:paragraph {"className":"lw16-stars"} -->
					<p class="lw16-stars"><?php esc_html_e( '5 von 5 Sternen', 'lindenweg16' ); ?></p>
					<!-- /wp:paragraph -->
					<!-- wp:paragraph {"className":"lw16-testimonial__name"} -->
					<p class="lw16-testimonial__name"><?php echo esc_html( $lindenweg16_quote['name'] ); ?></p>
					<!-- /wp:paragraph -->
					<!-- wp:paragraph {"className":"lw16-testimonial__meta"} -->
					<p class="lw16-testimonial__meta"><?php echo esc_html( $lindenweg16_quote['meta'] ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
<?php endforeach; ?>
	</div>
	<!-- /wp:columns -->
</section>
<!-- /wp:group -->
