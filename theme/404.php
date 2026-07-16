<?php get_header(); ?>

<main class="lw16-main">
	<section class="lw16-page-section" style="text-align:center; padding: 120px 56px;">
		<h1 class="lw16-page-title"><?php esc_html_e( 'Seite nicht gefunden', 'lindenweg16' ); ?></h1>
		<p><?php esc_html_e( 'Die gewünschte Seite existiert leider nicht.', 'lindenweg16' ); ?></p>
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="lw16-btn lw16-btn--pill" style="margin-top:24px;">
			<?php esc_html_e( 'Zurück zur Startseite', 'lindenweg16' ); ?>
		</a>
	</section>
</main>

<?php get_footer(); ?>
