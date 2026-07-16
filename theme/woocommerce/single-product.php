<?php
defined( 'ABSPATH' ) || exit;
get_header();
?>

<main class="lw16-main">
	<div class="lw16-product-detail">
		<?php woocommerce_breadcrumb(); ?>

		<?php while ( have_posts() ) : the_post(); ?>
			<?php wc_get_template_part( 'content', 'single-product' ); ?>
		<?php endwhile; ?>
	</div>
</main>

<?php get_footer(); ?>
