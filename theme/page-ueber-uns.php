<?php
/**
 * Template Name: Über uns
 * Slug: ueber-uns
 */
get_header();
?>

<main class="lw16-main">
	<section class="lw16-about-hero">
		<div class="lw16-eyebrow"><?php esc_html_e( 'Über uns', 'lindenweg16' ); ?></div>
		<h1 class="lw16-about-hero__title"><?php esc_html_e( 'Handgemacht am Lindenweg 16 in Haste', 'lindenweg16' ); ?></h1>
	</section>

	<section class="lw16-about-story">
		<div class="lw16-about-story__image">
			<?php if ( has_post_thumbnail() ) : ?>
				<?php the_post_thumbnail( 'large' ); ?>
			<?php else : ?>
				<div class="lw16-placeholder"><?php esc_html_e( 'Werkstattfoto', 'lindenweg16' ); ?></div>
			<?php endif; ?>
		</div>
		<div class="lw16-about-story__text">
			<?php
			if ( have_posts() ) : while ( have_posts() ) : the_post();
				if ( get_the_content() ) {
					the_content();
				} else {
					?>
					<p><?php esc_html_e( 'Lindenweg16 entstand aus dem eigenen Wunsch nach Ordnung: Helm, Schloss, Trikot und Werkzeug sollten einen festen Platz haben, statt im Flur zu liegen.', 'lindenweg16' ); ?></p>
					<p><?php esc_html_e( 'Heute entwerfen, drucken und montieren wir jedes Modul selbst — in kleiner Auflage, aus Massivholz und langlebigen 3D-Druck-Komponenten.', 'lindenweg16' ); ?></p>
					<?php
				}
			endwhile; endif;
			?>
		</div>
	</section>

	<section class="lw16-process">
		<h2 class="lw16-process__title"><?php esc_html_e( 'Wie ein Modul entsteht', 'lindenweg16' ); ?></h2>
		<div class="lw16-process__grid">
			<div class="lw16-process__step">
				<div class="lw16-process__number">01</div>
				<div class="lw16-process__name"><?php esc_html_e( 'Entwurf', 'lindenweg16' ); ?></div>
				<div class="lw16-process__desc"><?php esc_html_e( 'Maße und Form werden für jedes Zubehörteil passgenau geplant.', 'lindenweg16' ); ?></div>
			</div>
			<div class="lw16-process__step">
				<div class="lw16-process__number">02</div>
				<div class="lw16-process__name"><?php esc_html_e( '3D-Druck', 'lindenweg16' ); ?></div>
				<div class="lw16-process__desc"><?php esc_html_e( 'Die Adapter entstehen im eigenen 3D-Druck, Schicht für Schicht.', 'lindenweg16' ); ?></div>
			</div>
			<div class="lw16-process__step">
				<div class="lw16-process__number">03</div>
				<div class="lw16-process__name"><?php esc_html_e( 'Handmontage', 'lindenweg16' ); ?></div>
				<div class="lw16-process__desc"><?php esc_html_e( 'Holzkorpus und Druckteil werden von Hand zusammengefügt.', 'lindenweg16' ); ?></div>
			</div>
			<div class="lw16-process__step">
				<div class="lw16-process__number">04</div>
				<div class="lw16-process__name"><?php esc_html_e( 'Versand', 'lindenweg16' ); ?></div>
				<div class="lw16-process__desc"><?php esc_html_e( 'Sorgfältig verpackt geht dein Modul auf die Reise zu dir.', 'lindenweg16' ); ?></div>
			</div>
		</div>
	</section>
</main>

<?php get_footer(); ?>
