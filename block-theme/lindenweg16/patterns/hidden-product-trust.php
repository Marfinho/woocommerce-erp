<?php
/**
 * Title: Produktseite – Lieferzeit und Vertrauens-Chips
 * Slug: lindenweg16/hidden-product-trust
 * Inserter: no
 *
 * Ist Germanized oder German Market aktiv, kommt die Lieferzeit von dort –
 * dann zeigt das Theme nur die drei Chips.
 *
 * @package Lindenweg16
 */

?>
<!-- wp:group {"className":"lw16-product-trust","layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group lw16-product-trust">
<?php if ( ! lindenweg16_has_legal_plugin() ) : ?>
	<!-- wp:paragraph {"className":"lw16-delivery lw16-icon lw16-icon--clock"} -->
	<p class="lw16-delivery lw16-icon lw16-icon--clock"><?php
		printf(
			/* translators: %s: Lieferzeit, z. B. „2–4 Werktage“. */
			esc_html__( 'Lieferzeit: %s', 'lindenweg16' ),
			'<strong>' . esc_html( (string) apply_filters( 'lindenweg16_delivery_time', __( '2–4 Werktage', 'lindenweg16' ) ) ) . '</strong>'
		);
	?></p>
	<!-- /wp:paragraph -->
<?php endif; ?>
	<!-- wp:group {"className":"lw16-trust-chips","layout":{"type":"flex","flexWrap":"wrap"}} -->
	<div class="wp-block-group lw16-trust-chips">
		<!-- wp:paragraph {"className":"is-style-chip lw16-icon lw16-icon--truck"} -->
		<p class="is-style-chip lw16-icon lw16-icon--truck"><?php esc_html_e( 'Versand aus Haste', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- wp:paragraph {"className":"is-style-chip lw16-icon lw16-icon--return"} -->
		<p class="is-style-chip lw16-icon lw16-icon--return"><?php esc_html_e( '30 Tage Rückgabe', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- wp:paragraph {"className":"is-style-chip lw16-icon lw16-icon--lock"} -->
		<p class="is-style-chip lw16-icon lw16-icon--lock"><?php esc_html_e( 'Sichere Zahlung', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
