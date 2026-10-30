<?php
/**
 * Title: Footer
 * Slug: lindenweg16/footer
 * Categories: footer
 * Block Types: core/template-part/footer
 * Inserter: no
 * Description: Logo mit einem Satz über den Shop, drei Link-Spalten, Zahlungsarten und Copyright.
 *
 * @package Lindenweg16
 */

$lindenweg16_links = array(
	'footer-shop'    => array(
		'title' => __( 'Shop', 'lindenweg16' ),
		'items' => array(
			__( 'Alle Produkte', 'lindenweg16' )   => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ),
			__( 'Haster-Haken-Sets', 'lindenweg16' ) => lindenweg16_category_url( 'sets' ),
			__( 'Module', 'lindenweg16' )          => lindenweg16_category_url( 'module' ),
			__( 'Neuheiten', 'lindenweg16' )       => add_query_arg( 'orderby', 'date', function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ) ),
		),
	),
	'footer-service' => array(
		'title' => __( 'Service', 'lindenweg16' ),
		'items' => array(
			__( 'Kontakt', 'lindenweg16' )            => lindenweg16_page_url( 'kontakt' ),
			__( 'Mein Konto', 'lindenweg16' )         => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/mein-konto/' ),
			__( 'Versand und Zahlung', 'lindenweg16' ) => lindenweg16_page_url( 'versand-und-zahlung' ),
			__( 'Über uns', 'lindenweg16' )           => lindenweg16_page_url( 'ueber-uns' ),
		),
	),
	'footer-legal'   => array(
		'title' => __( 'Rechtliches', 'lindenweg16' ),
		'items' => array(
			__( 'Impressum', 'lindenweg16' )           => lindenweg16_page_url( 'impressum' ),
			__( 'Datenschutz', 'lindenweg16' )         => get_privacy_policy_url() ? get_privacy_policy_url() : lindenweg16_page_url( 'datenschutz' ),
			__( 'AGB', 'lindenweg16' )                 => function_exists( 'wc_terms_and_conditions_page_id' ) && wc_terms_and_conditions_page_id() ? get_permalink( wc_terms_and_conditions_page_id() ) : lindenweg16_page_url( 'agb' ),
			__( 'Widerruf', 'lindenweg16' )            => lindenweg16_page_url( 'widerruf' ),
			__( 'Versand und Zahlung', 'lindenweg16' ) => lindenweg16_page_url( 'versand-und-zahlung' ),
		),
	),
);

$lindenweg16_payments = lindenweg16_payment_methods(); // Design → Lindenweg16 → Zahlungsarten.
?>
<!-- wp:group {"tagName":"div","className":"lw16-footer","layout":{"type":"constrained"}} -->
<div class="wp-block-group lw16-footer">
	<!-- wp:columns {"align":"wide","className":"lw16-footer__columns"} -->
	<div class="wp-block-columns alignwide lw16-footer__columns">
		<!-- wp:column {"width":"36%"} -->
		<div class="wp-block-column" style="flex-basis:36%">
			<!-- wp:site-logo {"width":150,"shouldSyncIcon":false} /-->
			<!-- wp:site-title {"level":0} /-->
			<!-- wp:paragraph {"className":"lw16-footer__about"} -->
			<p class="lw16-footer__about"><?php esc_html_e( 'Handgemachte Fahrrad-Organisation aus Haste: Mit dem Haster Haken bekommen Helm, Schloss und Werkzeug endlich ihren festen Platz.', 'lindenweg16' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
<?php foreach ( $lindenweg16_links as $lindenweg16_key => $lindenweg16_column ) : ?>
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:heading {"level":2,"className":"lw16-footer__heading","fontSize":"medium"} -->
			<h2 class="wp-block-heading lw16-footer__heading has-medium-font-size"><?php echo esc_html( lindenweg16_menu_name( $lindenweg16_key, $lindenweg16_column['title'] ) ); ?></h2>
			<!-- /wp:heading -->
			<!-- wp:navigation {"overlayMenu":"never","className":"lw16-footer__links","layout":{"type":"flex","orientation":"vertical"},"ariaLabel":"<?php echo esc_attr( lindenweg16_menu_name( $lindenweg16_key, $lindenweg16_column['title'] ) ); ?>"} -->
<?php echo lindenweg16_menu_link_blocks( $lindenweg16_key, $lindenweg16_column['items'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Blockmarkup, Werte per wp_json_encode kodiert. ?>
			<!-- /wp:navigation -->
		</div>
		<!-- /wp:column -->
<?php endforeach; ?>
	</div>
	<!-- /wp:columns -->

<?php if ( $lindenweg16_payments ) : ?>
	<!-- wp:group {"align":"wide","className":"lw16-payments","layout":{"type":"flex","flexWrap":"wrap"}} -->
	<div class="wp-block-group alignwide lw16-payments">
<?php foreach ( $lindenweg16_payments as $lindenweg16_payment ) : ?>
		<!-- wp:paragraph {"className":"is-style-chip"} -->
		<p class="is-style-chip"><?php echo esc_html( $lindenweg16_payment ); ?></p>
		<!-- /wp:paragraph -->
<?php endforeach; ?>
	</div>
	<!-- /wp:group -->
<?php endif; ?>

	<!-- wp:group {"align":"wide","className":"lw16-footer__bottom","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group alignwide lw16-footer__bottom">
		<!-- wp:paragraph -->
		<p><?php
			$lindenweg16_tax_note = lindenweg16_tax_note();
			echo esc_html( sprintf( /* translators: %s: Jahreszahl. */ __( '© %s Lindenweg16', 'lindenweg16' ), wp_date( 'Y' ) ) . ( '' !== $lindenweg16_tax_note ? ' · ' . $lindenweg16_tax_note : '' ) );
		?></p>
		<!-- /wp:paragraph -->
		<!-- wp:paragraph -->
		<p><?php esc_html_e( 'Mit Liebe in Haste gebaut', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
