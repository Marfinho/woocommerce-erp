<?php get_header(); ?>

<main class="lw16-main">
	<section class="lw16-page-section">
		<h1 class="lw16-page-title">
			<?php printf( esc_html__( 'Suchergebnisse für: %s', 'lindenweg16' ), '<span>' . esc_html( get_search_query() ) . '</span>' ); ?>
		</h1>
		<?php if ( have_posts() ) : ?>
			<div class="lw16-product-grid lw16-product-grid--4">
				<?php while ( have_posts() ) : the_post(); ?>
					<?php
					if ( function_exists( 'wc_get_product' ) && 'product' === get_post_type() ) {
						$product = wc_get_product( get_the_ID() );
						if ( $product ) {
							lw16_product_card( $product );
							continue;
						}
					}
					?>
					<div class="lw16-product-card">
						<a href="<?php the_permalink(); ?>">
							<div class="lw16-product-card__name"><?php the_title(); ?></div>
							<div class="lw16-product-card__price"><?php the_excerpt(); ?></div>
						</a>
					</div>
				<?php endwhile; ?>
			</div>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Keine Ergebnisse gefunden.', 'lindenweg16' ); ?></p>
		<?php endif; ?>
	</section>
</main>

<?php get_footer(); ?>
