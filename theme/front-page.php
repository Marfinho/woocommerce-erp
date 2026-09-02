<?php
/**
 * Template: Startseite
 */
get_header();

$hero_image_id = get_theme_mod( 'lw16_hero_image' );
$hero_url      = $hero_image_id ? wp_get_attachment_image_url( $hero_image_id, 'lw16-hero' ) : '';
$hero_eyebrow  = get_theme_mod( 'lw16_hero_eyebrow', 'Handgemacht in Haste' );
$hero_title    = get_theme_mod( 'lw16_hero_title', 'Ordnung für dein Fahrrad-Gear' );
$hero_text     = get_theme_mod( 'lw16_hero_text', 'Modulare Wandhalter aus Massivholz und 3D-Druck — individuell zusammengestellt für Helm, Schloss, Trikot und Werkzeug.' );
$shop_url      = function_exists( 'wc_get_page_id' ) ? get_permalink( wc_get_page_id( 'shop' ) ) : '#';
$about_page    = get_page_by_path( 'ueber-uns' );
$about_url     = $about_page ? get_permalink( $about_page ) : '#';
?>

<main class="lw16-main">

	<!-- Hero -->
	<section class="lw16-hero" <?php if ( $hero_url ) : ?>style="background-image:url('<?php echo esc_url( $hero_url ); ?>')"<?php endif; ?>>
		<div class="lw16-hero__overlay"></div>
		<div class="lw16-hero__content">
			<div class="lw16-hero__eyebrow"><?php echo esc_html( $hero_eyebrow ); ?></div>
			<h1 class="lw16-hero__title"><?php echo esc_html( $hero_title ); ?></h1>
			<p class="lw16-hero__text"><?php echo esc_html( $hero_text ); ?></p>
			<a href="<?php echo esc_url( $shop_url ); ?>" class="lw16-btn lw16-btn--pill"><?php esc_html_e( 'Jetzt shoppen', 'lindenweg16' ); ?></a>
		</div>
	</section>

	<!-- USP Row -->
	<section class="lw16-usp">
		<div class="lw16-usp__item">
			<div class="lw16-usp__title"><?php esc_html_e( 'Modular erweiterbar', 'lindenweg16' ); ?></div>
			<div class="lw16-usp__desc"><?php esc_html_e( 'Beginne klein und ergänze Module, sobald du sie brauchst.', 'lindenweg16' ); ?></div>
		</div>
		<div class="lw16-usp__item">
			<div class="lw16-usp__title"><?php esc_html_e( 'Holz & 3D-Druck', 'lindenweg16' ); ?></div>
			<div class="lw16-usp__desc"><?php esc_html_e( 'Massivholz-Halterungen mit präzisen, individuell gedruckten Adaptern.', 'lindenweg16' ); ?></div>
		</div>
		<div class="lw16-usp__item">
			<div class="lw16-usp__title"><?php esc_html_e( 'Gefertigt in Haste', 'lindenweg16' ); ?></div>
			<div class="lw16-usp__desc"><?php esc_html_e( 'Jedes Set entsteht in kleiner Auflage direkt am Lindenweg 16.', 'lindenweg16' ); ?></div>
		</div>
	</section>

	<!-- Featured Modules -->
	<?php
	$modules = lw16_featured_products( 'module', 5 );
	if ( $modules ) : ?>
	<section class="lw16-section">
		<div class="lw16-section__header">
			<h2 class="lw16-section__title"><?php esc_html_e( 'Module', 'lindenweg16' ); ?></h2>
			<a href="<?php echo esc_url( get_term_link( 'module', 'product_cat' ) ); ?>" class="lw16-section__link"><?php esc_html_e( 'Alle Module ansehen', 'lindenweg16' ); ?> &rarr;</a>
		</div>
		<div class="lw16-product-grid lw16-product-grid--5">
			<?php foreach ( $modules as $product ) : ?>
				<?php lw16_product_card( $product ); ?>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<!-- Featured Sets -->
	<?php
	$sets = lw16_featured_products( 'sets', 5 );
	if ( $sets ) : ?>
	<section class="lw16-section lw16-section--bottom">
		<div class="lw16-section__header">
			<h2 class="lw16-section__title"><?php esc_html_e( 'Sets', 'lindenweg16' ); ?></h2>
			<a href="<?php echo esc_url( get_term_link( 'sets', 'product_cat' ) ); ?>" class="lw16-section__link"><?php esc_html_e( 'Alle Sets ansehen', 'lindenweg16' ); ?> &rarr;</a>
		</div>
		<div class="lw16-product-grid lw16-product-grid--5">
			<?php foreach ( $sets as $product ) : ?>
				<?php lw16_product_card( $product ); ?>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<!-- Naechste Events -->
	<?php
	// Das Plugin liefert die Reihe. Fehlt es, faellt der Abschnitt weg,
	// statt die Startseite mit einem Fehler anzuhalten.
	$events_html = '';
	if ( class_exists( '\Lindenweg16\Modules\Events\EventsWidget' ) ) {
		// Ueberschrift und Link baut die Vorlage selbst, damit der Abschnitt
		// aussieht wie die uebrigen auf dieser Seite.
		$events_html = \Lindenweg16\Modules\Events\EventsWidget::render(
			array(
				'anzahl'    => 6,
				'titel'     => '',
				'alle_link' => false,
				'autoplay'  => true,
				'intervall' => 7,
			)
		);
	}

	if ( $events_html ) :
		$events_archiv = get_post_type_archive_link( 'lw16_event' );
		?>
	<section class="lw16-section">
		<div class="lw16-section__header">
			<h2 class="lw16-section__title"><?php esc_html_e( 'Nächste Termine', 'lindenweg16' ); ?></h2>
			<?php if ( $events_archiv ) : ?>
				<a href="<?php echo esc_url( $events_archiv ); ?>" class="lw16-section__link"><?php esc_html_e( 'Alle Termine ansehen', 'lindenweg16' ); ?> &rarr;</a>
			<?php endif; ?>
		</div>
		<?php echo $events_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</section>
	<?php endif; ?>

	<!-- Story Teaser -->
	<section class="lw16-story">
		<div class="lw16-story__image">
			<?php
			$teaser_img = get_theme_mod( 'lw16_about_teaser_image' );
			if ( $teaser_img ) {
				echo wp_get_attachment_image( $teaser_img, 'large' );
			} else {
				echo '<div class="lw16-placeholder">' . esc_html__( 'Werkstattfoto', 'lindenweg16' ) . '</div>';
			}
			?>
		</div>
		<div class="lw16-story__text">
			<div class="lw16-eyebrow"><?php esc_html_e( 'Unsere Geschichte', 'lindenweg16' ); ?></div>
			<h2 class="lw16-story__title"><?php esc_html_e( 'Vom eigenen Chaos zur eigenen Werkstatt', 'lindenweg16' ); ?></h2>
			<p><?php esc_html_e( 'Lindenweg16 ist aus dem eigenen Bedarf entstanden: ein durchdachtes System für Helm, Schloss und Zubehör, das mit jedem neuen Fahrrad mitwächst. Entworfen, gedruckt und montiert am Lindenweg 16 in Haste.', 'lindenweg16' ); ?></p>
			<a href="<?php echo esc_url( $about_url ); ?>" class="lw16-link"><?php esc_html_e( 'Mehr über uns', 'lindenweg16' ); ?> &rarr;</a>
		</div>
	</section>

	<!-- Newsletter -->
	<section class="lw16-newsletter">
		<h2 class="lw16-newsletter__title"><?php esc_html_e( 'Bleib auf dem Laufenden', 'lindenweg16' ); ?></h2>
		<p class="lw16-newsletter__desc"><?php esc_html_e( 'Neue Module, Werkstattfotos und exklusive Angebote direkt in dein Postfach.', 'lindenweg16' ); ?></p>
		<?php
		$nl_action = get_theme_mod( 'lw16_newsletter_action', '' );
		$form_action = $nl_action ? $nl_action : '#';
		?>
		<form class="lw16-newsletter__form" action="<?php echo esc_url( $form_action ); ?>" method="post">
			<input type="email" name="email" placeholder="<?php esc_attr_e( 'deine@email.de', 'lindenweg16' ); ?>" required>
			<button type="submit" class="lw16-btn lw16-btn--pill"><?php esc_html_e( 'Anmelden', 'lindenweg16' ); ?></button>
		</form>
	</section>

</main>

<?php get_footer(); ?>
