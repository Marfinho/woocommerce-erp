<?php
/**
 * Title: Header (schwebende Glas-Pille)
 * Slug: lindenweg16/header
 * Categories: header
 * Block Types: core/template-part/header
 * Inserter: no
 * Description: Logo links, Navigation in der Mitte, rechts Suche, Kundenkonto und Mini-Cart.
 *
 * @package Lindenweg16
 */

?>
<!-- wp:group {"className":"lw16-header","layout":{"type":"default"}} -->
<div class="wp-block-group lw16-header">
	<!-- wp:group {"className":"lw16-header__inner","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group lw16-header__inner">
		<!-- wp:group {"className":"lw16-header__brand","layout":{"type":"flex","flexWrap":"nowrap"}} -->
		<div class="wp-block-group lw16-header__brand">
			<!-- wp:site-logo {"width":140,"shouldSyncIcon":false} /-->
			<!-- wp:site-title {"level":0} /-->
		</div>
		<!-- /wp:group -->

		<!-- wp:navigation {"overlayMenu":"mobile","layout":{"type":"flex","justifyContent":"center"},"ariaLabel":"<?php echo esc_attr__( 'Hauptmenü', 'lindenweg16' ); ?>"} -->
<?php
		// Menü aus Design → Menüs (Ort „Hauptmenü“), sonst diese Standard-Links.
		echo lindenweg16_menu_link_blocks( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Blockmarkup, Werte per wp_json_encode kodiert.
			'primary',
			array(
				__( 'Shop', 'lindenweg16' )     => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ),
				__( 'Sets', 'lindenweg16' )     => lindenweg16_category_url( 'sets' ),
				__( 'Module', 'lindenweg16' )   => lindenweg16_category_url( 'module' ),
				__( 'Über uns', 'lindenweg16' ) => lindenweg16_page_url( 'ueber-uns' ),
				__( 'Kontakt', 'lindenweg16' )  => lindenweg16_page_url( 'kontakt' ),
			)
		);
		?>
		<!-- /wp:navigation -->

		<!-- wp:group {"className":"lw16-header__actions","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"right"}} -->
		<div class="wp-block-group lw16-header__actions">
			<!-- wp:search {"label":"<?php echo esc_attr__( 'Suche', 'lindenweg16' ); ?>","showLabel":false,"placeholder":"<?php echo esc_attr__( 'Wonach suchst du?', 'lindenweg16' ); ?>","buttonText":"<?php echo esc_attr__( 'Suchen', 'lindenweg16' ); ?>","buttonPosition":"button-only","buttonUseIcon":true,"isSearchFieldHidden":true,"query":{"post_type":"product"}} /-->
			<!-- wp:woocommerce/customer-account {"displayStyle":"icon_only","iconStyle":"line","iconClass":"wc-block-customer-account__account-icon"} /-->
			<!-- wp:woocommerce/mini-cart {"addToCartBehaviour":"open_drawer","hasHiddenPrice":true,"productCountVisibility":"greater_than_zero"} /-->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
