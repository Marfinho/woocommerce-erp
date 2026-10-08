<?php
/**
 * Title: Produkt-Highlights (Neuheiten)
 * Slug: lindenweg16/product-highlights
 * Categories: lindenweg16, woo-commerce
 * Keywords: produkte, neuheiten, bestseller, raster
 * Viewport Width: 1400
 * Description: Product Collection mit den neuesten Produkten als Karten-Raster und „Alle ansehen“-Link. In den Block-Einstellungen lässt sich die Sammlung z. B. auf Bestseller umstellen.
 *
 * @package Lindenweg16
 */

$lindenweg16_shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
?>
<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section","layout":{"type":"default"}} -->
<section class="wp-block-group alignwide lw16-section">
	<!-- wp:group {"className":"lw16-section-head","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
	<div class="wp-block-group lw16-section-head">
		<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
			<p class="is-style-eyebrow"><?php esc_html_e( 'Neu aus der Werkstatt', 'lindenweg16' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:heading -->
			<h2 class="wp-block-heading"><?php esc_html_e( 'Frisch vom Hobel', 'lindenweg16' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:group -->

		<!-- wp:paragraph -->
		<p><a href="<?php echo esc_url( add_query_arg( 'orderby', 'date', $lindenweg16_shop ) ); ?>"><?php esc_html_e( 'Alle ansehen →', 'lindenweg16' ); ?></a></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

	<!-- wp:woocommerce/product-collection {"queryId":1,"query":{"perPage":4,"pages":1,"offset":0,"postType":"product","order":"desc","orderBy":"date","search":"","exclude":[],"inherit":false,"taxQuery":{},"isProductCollectionBlock":true,"featured":false,"woocommerceOnSale":false,"woocommerceStockStatus":["instock","onbackorder"],"woocommerceAttributes":[],"woocommerceHandPickedProducts":[],"filterable":false,"relatedBy":{"categories":true,"tags":true}},"tagName":"div","displayLayout":{"type":"flex","columns":4,"shrinkColumns":true},"dimensions":{"widthType":"fill"},"collection":"woocommerce/product-collection/new-arrivals","hideControls":["inherit","order","filterable"],"queryContextIncludes":["collection"],"className":"lw16-products lw16-products--4"} -->
	<div class="wp-block-woocommerce-product-collection lw16-products lw16-products--4">
		<?php echo lindenweg16_product_card_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- feste Blockvorlage ohne Nutzerdaten. ?>
	</div>
	<!-- /wp:woocommerce/product-collection -->
</section>
<!-- /wp:group -->
