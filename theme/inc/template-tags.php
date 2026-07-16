<?php
defined( 'ABSPATH' ) || exit;

function lw16_logo() {
	if ( has_custom_logo() ) {
		the_custom_logo();
	} else {
		echo '<a href="' . esc_url( home_url( '/' ) ) . '" class="lw16-logo">';
		echo '<img src="' . esc_url( get_template_directory_uri() . '/assets/images/lindenweg16-logo-tinte.png' ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" height="40">';
		echo '</a>';
	}
}

function lw16_cart_link() {
	if ( ! function_exists( 'WC' ) ) {
		return;
	}
	$count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	?>
	<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="lw16-header__cart">
		<?php printf( esc_html__( 'Warenkorb (%d)', 'lindenweg16' ), $count ); ?>
	</a>
	<?php
}

function lw16_featured_products( $category_slug, $limit = 5 ) {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return array();
	}
	$args = array(
		'limit'    => $limit,
		'status'   => 'publish',
		'orderby'  => 'menu_order',
		'order'    => 'ASC',
	);
	if ( $category_slug ) {
		$args['category'] = array( $category_slug );
	}
	return wc_get_products( $args );
}

function lw16_product_card( $product ) {
	?>
	<div class="lw16-product-card">
		<a href="<?php echo esc_url( $product->get_permalink() ); ?>">
			<div class="lw16-product-card__image">
				<?php echo $product->get_image( 'woocommerce_thumbnail' ); ?>
			</div>
			<?php
			$cats = wc_get_product_category_list( $product->get_id() );
			if ( $cats ) : ?>
				<div class="lw16-product-card__category"><?php echo wp_strip_all_tags( $cats ); ?></div>
			<?php endif; ?>
			<div class="lw16-product-card__name"><?php echo esc_html( $product->get_name() ); ?></div>
			<div class="lw16-product-card__price"><?php echo $product->get_price_html(); ?></div>
		</a>
	</div>
	<?php
}
