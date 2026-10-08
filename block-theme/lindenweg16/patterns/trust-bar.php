<?php
/**
 * Title: Vertrauensleiste
 * Slug: lindenweg16/trust-bar
 * Categories: lindenweg16
 * Keywords: vertrauen, versand, rückgabe, zahlung, kontakt, chips
 * Viewport Width: 1200
 * Description: Vier Chips mit Symbol: Versand, Rückgabe, sichere Zahlung, persönlicher Kontakt.
 *
 * @package Lindenweg16
 */

$lindenweg16_chips = array(
	'truck'  => __( 'Versand in 48 h, ab 50 € kostenlos', 'lindenweg16' ),
	'return' => __( '30 Tage Rückgabe', 'lindenweg16' ),
	'lock'   => __( 'Sicher bezahlen', 'lindenweg16' ),
	'chat'   => __( 'Echte Menschen am Telefon', 'lindenweg16' ),
);
?>
<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section lw16-trust","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"center"}} -->
<section class="wp-block-group alignwide lw16-section lw16-trust">
<?php foreach ( $lindenweg16_chips as $lindenweg16_icon => $lindenweg16_text ) : ?>
	<!-- wp:paragraph {"className":"is-style-chip lw16-icon lw16-icon--<?php echo esc_attr( $lindenweg16_icon ); ?>"} -->
	<p class="is-style-chip lw16-icon lw16-icon--<?php echo esc_attr( $lindenweg16_icon ); ?>"><?php echo esc_html( $lindenweg16_text ); ?></p>
	<!-- /wp:paragraph -->
<?php endforeach; ?>
</section>
<!-- /wp:group -->
