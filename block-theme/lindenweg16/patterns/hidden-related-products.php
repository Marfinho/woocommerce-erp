<?php
/**
 * Title: Produktseite – Das passt dazu
 * Slug: lindenweg16/hidden-related-products
 * Inserter: no
 *
 * @package Lindenweg16
 */

?>
<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section lw16-related","layout":{"type":"default"}} -->
<section class="wp-block-group alignwide lw16-section lw16-related">
	<!-- wp:woocommerce/product-collection {"queryId":2,"query":{"perPage":4,"pages":1,"offset":0,"postType":"product","order":"asc","orderBy":"title","search":"","exclude":[],"inherit":false,"taxQuery":{},"isProductCollectionBlock":true,"featured":false,"woocommerceOnSale":false,"woocommerceStockStatus":["instock","onbackorder"],"woocommerceAttributes":[],"woocommerceHandPickedProducts":[],"filterable":false,"relatedBy":{"categories":true,"tags":true}},"tagName":"div","displayLayout":{"type":"flex","columns":4,"shrinkColumns":true},"dimensions":{"widthType":"fill"},"collection":"woocommerce/product-collection/related","hideControls":["inherit"],"queryContextIncludes":["collection"],"className":"lw16-products lw16-products--4"} -->
	<div class="wp-block-woocommerce-product-collection lw16-products lw16-products--4">
		<!-- wp:heading -->
		<h2 class="wp-block-heading"><?php esc_html_e( 'Das passt dazu', 'lindenweg16' ); ?></h2>
		<!-- /wp:heading -->
		<?php echo lindenweg16_product_card_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- feste Blockvorlage. ?>
	</div>
	<!-- /wp:woocommerce/product-collection -->
</section>
<!-- /wp:group -->
