<?php
/**
 * Template Name: Kontakt
 * Slug: kontakt
 */
get_header();
?>

<main class="lw16-main">
	<section class="lw16-contact">
		<h1 class="lw16-contact__title"><?php esc_html_e( 'Kontakt', 'lindenweg16' ); ?></h1>
		<div class="lw16-contact__grid">
			<div class="lw16-contact__form">
				<?php
				if ( have_posts() ) : while ( have_posts() ) : the_post();
					the_content();
				endwhile; endif;

				if ( ! get_the_content() ) : ?>
					<form class="lw16-form" method="post">
						<input type="text" name="name" placeholder="<?php esc_attr_e( 'Name', 'lindenweg16' ); ?>" required>
						<input type="email" name="email" placeholder="<?php esc_attr_e( 'E-Mail', 'lindenweg16' ); ?>" required>
						<textarea name="message" rows="6" placeholder="<?php esc_attr_e( 'Deine Nachricht', 'lindenweg16' ); ?>" required></textarea>
						<button type="submit" class="lw16-btn lw16-btn--pill"><?php esc_html_e( 'Senden', 'lindenweg16' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
			<div class="lw16-contact__info">
				<?php if ( has_post_thumbnail() ) : ?>
					<div class="lw16-contact__map">
						<?php the_post_thumbnail( 'large' ); ?>
					</div>
				<?php endif; ?>
				<div class="lw16-contact__address">
					<strong>Lindenweg16</strong><br>
					Lindenweg 16<br>
					31559 Haste<br>
					Tel: 05722 123456<br>
					E-Mail: hallo@lindenweg16.de
				</div>
			</div>
		</div>
	</section>
</main>

<?php get_footer(); ?>
