<?php
/**
 * Title: So einfach geht’s
 * Slug: lindenweg16/how-it-works
 * Categories: lindenweg16
 * Keywords: schritte, ablauf, anleitung, nummern
 * Viewport Width: 1200
 * Description: Drei nummerierte Schritte (Aussuchen, Bestellen, Auspacken) mit farbigen runden Nummern und gestrichelter Verbindungslinie.
 *
 * @package Lindenweg16
 */

$lindenweg16_steps = array(
	array(
		'color' => 'accent-1',
		'title' => __( 'Aussuchen', 'lindenweg16' ),
		'text'  => __( 'Starter-Set oder einzelne Module – du stellst deinen Haken so zusammen, wie dein Alltag mit dem Rad aussieht.', 'lindenweg16' ),
	),
	array(
		'color' => 'accent-3',
		'title' => __( 'Bestellen', 'lindenweg16' ),
		'text'  => __( 'Sicher bezahlen, und wir bauen dein Set in unserer Werkstatt in Haste zusammen.', 'lindenweg16' ),
	),
	array(
		'color' => 'accent-4',
		'title' => __( 'Auspacken', 'lindenweg16' ),
		'text'  => __( 'Zwei Schrauben, zehn Minuten – und dein Rad-Gear hat endlich ein Zuhause.', 'lindenweg16' ),
	),
);
?>
<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section","layout":{"type":"constrained","contentSize":"1100px"}} -->
<section class="wp-block-group alignwide lw16-section">
	<!-- wp:group {"className":"lw16-section-head","layout":{"type":"flex","orientation":"vertical","justifyContent":"center"}} -->
	<div class="wp-block-group lw16-section-head">
		<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
		<p class="is-style-eyebrow"><?php esc_html_e( 'So einfach geht’s', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- wp:heading {"textAlign":"center"} -->
		<h2 class="wp-block-heading has-text-align-center"><?php esc_html_e( 'In drei Schritten zur aufgeräumten Wand', 'lindenweg16' ); ?></h2>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:group -->

	<!-- wp:columns {"className":"lw16-steps"} -->
	<div class="wp-block-columns lw16-steps">
<?php foreach ( $lindenweg16_steps as $lindenweg16_i => $lindenweg16_step ) : ?>
		<!-- wp:column {"className":"lw16-step"} -->
		<div class="wp-block-column lw16-step">
			<!-- wp:paragraph {"className":"lw16-step__number","backgroundColor":"<?php echo esc_attr( $lindenweg16_step['color'] ); ?>"} -->
			<p class="lw16-step__number has-<?php echo esc_attr( $lindenweg16_step['color'] ); ?>-background-color has-background"><?php echo esc_html( sprintf( '%02d', $lindenweg16_i + 1 ) ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"large"} -->
			<h3 class="wp-block-heading has-large-font-size"><?php echo esc_html( $lindenweg16_step['title'] ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"text-muted"} -->
			<p class="has-text-muted-color has-text-color"><?php echo esc_html( $lindenweg16_step['text'] ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
<?php endforeach; ?>
	</div>
	<!-- /wp:columns -->
</section>
<!-- /wp:group -->
