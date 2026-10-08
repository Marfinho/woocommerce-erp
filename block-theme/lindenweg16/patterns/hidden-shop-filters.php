<?php
/**
 * Title: Shop – Filter
 * Slug: lindenweg16/hidden-shop-filters
 * Inserter: no
 *
 * WooCommerce-Filterblöcke: auf dem Desktop in der Seitenleiste, mobil als
 * ausklappbares Panel (Overlay des Filter-Blocks).
 *
 * @package Lindenweg16
 */

?>
<!-- wp:woocommerce/product-filters {"overlayMode":"mobile","overlayPosition":"right"} -->
<div class="wp-block-woocommerce-product-filters wc-block-product-filters">
	<!-- wp:heading {"level":2} -->
	<h2 class="wp-block-heading"><?php esc_html_e( 'Filter', 'lindenweg16' ); ?></h2>
	<!-- /wp:heading -->

	<!-- wp:woocommerce/product-filter-active -->
	<div class="wp-block-woocommerce-product-filter-active">
		<!-- wp:woocommerce/product-filter-removable-chips -->
		<div class="wp-block-woocommerce-product-filter-removable-chips wc-block-product-filter-removable-chips"></div>
		<!-- /wp:woocommerce/product-filter-removable-chips -->

		<!-- wp:woocommerce/product-filter-clear-button -->
		<!-- wp:buttons {"layout":{"type":"flex"}} -->
		<div class="wp-block-buttons">
			<!-- wp:button {"className":"wc-block-product-filter-clear-button is-style-soft"} -->
			<div class="wp-block-button wc-block-product-filter-clear-button is-style-soft"><a class="wp-block-button__link wp-element-button"><?php esc_html_e( 'Filter zurücksetzen', 'lindenweg16' ); ?></a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:buttons -->
		<!-- /wp:woocommerce/product-filter-clear-button -->
	</div>
	<!-- /wp:woocommerce/product-filter-active -->

	<!-- wp:woocommerce/product-filter-taxonomy {"taxonomy":"product_cat","displayStyle":"woocommerce/product-filter-chips"} -->
	<div class="wp-block-woocommerce-product-filter-taxonomy">
		<!-- wp:heading {"level":3} -->
		<h3 class="wp-block-heading"><?php esc_html_e( 'Kategorie', 'lindenweg16' ); ?></h3>
		<!-- /wp:heading -->
		<!-- wp:woocommerce/product-filter-chips -->
		<div class="wp-block-woocommerce-product-filter-chips wc-block-product-filter-chips"></div>
		<!-- /wp:woocommerce/product-filter-chips -->
	</div>
	<!-- /wp:woocommerce/product-filter-taxonomy -->

	<!-- wp:woocommerce/product-filter-price -->
	<div class="wp-block-woocommerce-product-filter-price">
		<!-- wp:heading {"level":3} -->
		<h3 class="wp-block-heading"><?php esc_html_e( 'Preis', 'lindenweg16' ); ?></h3>
		<!-- /wp:heading -->
		<!-- wp:woocommerce/product-filter-price-slider -->
		<div class="wp-block-woocommerce-product-filter-price-slider wc-block-product-filter-price-slider"></div>
		<!-- /wp:woocommerce/product-filter-price-slider -->
	</div>
	<!-- /wp:woocommerce/product-filter-price -->

	<!-- wp:woocommerce/product-filter-status -->
	<div class="wp-block-woocommerce-product-filter-status">
		<!-- wp:heading {"level":3} -->
		<h3 class="wp-block-heading"><?php esc_html_e( 'Verfügbarkeit', 'lindenweg16' ); ?></h3>
		<!-- /wp:heading -->
		<!-- wp:woocommerce/product-filter-checkbox-list -->
		<div class="wp-block-woocommerce-product-filter-checkbox-list wc-block-product-filter-checkbox-list"></div>
		<!-- /wp:woocommerce/product-filter-checkbox-list -->
	</div>
	<!-- /wp:woocommerce/product-filter-status -->
</div>
<!-- /wp:woocommerce/product-filters -->
