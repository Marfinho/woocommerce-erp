<?php
/**
 * WooCommerce wrapper template.
 * Used for shop archive + product taxonomy pages.
 */
get_header();
?>

<main class="lw16-main">
	<div class="lw16-shop">
		<?php woocommerce_breadcrumb(); ?>
		<h1 class="lw16-shop__title"><?php woocommerce_page_title(); ?></h1>
		<div class="lw16-shop__layout">
			<aside class="lw16-shop__sidebar">
				<?php if ( is_active_sidebar( 'shop-sidebar' ) ) : ?>
					<?php dynamic_sidebar( 'shop-sidebar' ); ?>
				<?php else : ?>
					<div class="lw16-sidebar-section">
						<h3 class="lw16-sidebar__heading"><?php esc_html_e( 'Kategorien', 'lindenweg16' ); ?></h3>
						<?php
						wp_list_categories( array(
							'taxonomy'   => 'product_cat',
							'title_li'   => '',
							'show_count' => false,
							'orderby'    => 'name',
						) );
						?>
					</div>
					<?php if ( function_exists( 'wc_get_attribute_taxonomies' ) ) : ?>
					<div class="lw16-sidebar-section">
						<h3 class="lw16-sidebar__heading"><?php esc_html_e( 'Preis', 'lindenweg16' ); ?></h3>
						<?php the_widget( 'WC_Widget_Price_Filter' ); ?>
					</div>
					<?php endif; ?>
				<?php endif; ?>
			</aside>
			<div class="lw16-shop__content">
				<?php woocommerce_content(); ?>
			</div>
		</div>
	</div>
</main>

<?php get_footer(); ?>
