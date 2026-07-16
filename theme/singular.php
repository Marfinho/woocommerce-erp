<?php get_header(); ?>

<main class="lw16-main">
	<?php while ( have_posts() ) : the_post(); ?>
		<article class="lw16-page-section">
			<h1 class="lw16-page-title"><?php the_title(); ?></h1>
			<div class="lw16-content"><?php the_content(); ?></div>
		</article>
	<?php endwhile; ?>
</main>

<?php get_footer(); ?>
