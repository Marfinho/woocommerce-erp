<?php
/**
 * Title: Über uns (Template)
 * Slug: lindenweg16/hidden-ueber-uns
 * Inserter: no
 * Description: Inhalt des Templates „Über uns“: Hero mit Foto (Beitragsbild), Text-Laufband, Geschichte (Seiteninhalt, erste Überschrift links, Text rechts), Werkstatt als Liste und eine Abschlusszeile zu Shop und Anfragen.
 *
 * Die Geschichte ist der Inhalt der Seite selbst und bleibt im Seiten-Editor
 * bearbeitbar; das Foto im Hero ist das Beitragsbild der Seite. Ohne
 * Beitragsbild zeigt der Hero das Produktfoto des Themes.
 *
 * @package Lindenweg16
 */

$lindenweg16_page_id   = (int) get_queried_object_id();
$lindenweg16_has_photo = $lindenweg16_page_id && has_post_thumbnail( $lindenweg16_page_id );
$lindenweg16_shop      = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
$lindenweg16_contact   = lindenweg16_contact_page_url();

$lindenweg16_words = array(
	__( 'Handgemacht', 'lindenweg16' ),
	__( '3D-Druck', 'lindenweg16' ),
	__( 'Holz', 'lindenweg16' ),
	__( 'Plotten', 'lindenweg16' ),
	__( 'Rad-Gear', 'lindenweg16' ),
	__( 'Lindenweg 16', 'lindenweg16' ),
	__( 'Haste', 'lindenweg16' ),
);

$lindenweg16_crafts = array(
	array(
		'title' => __( '3D-Druck', 'lindenweg16' ),
		'text'  => __( 'Alle Module des Haster Hakens drucken wir selbst – in kleiner Auflage, jedes für genau ein Teil konstruiert.', 'lindenweg16' ),
	),
	array(
		'title' => __( 'Holz', 'lindenweg16' ),
		'text'  => __( 'Die Basis jedes Haster Hakens ist eine Holzschiene. Auf sie kommen Wandanker und Module.', 'lindenweg16' ),
	),
	array(
		'title' => __( 'Plotten', 'lindenweg16' ),
		'text'  => __( 'Folie für Shirts, Mützen, Schirme und mehr – nach deinem Motiv.', 'lindenweg16' ),
	),
);
?>
<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section lw16-hero lw16-about-hero","layout":{"type":"default"}} -->
<section class="wp-block-group alignwide lw16-section lw16-hero lw16-about-hero">
	<!-- wp:columns {"verticalAlignment":"center","className":"lw16-hero__columns"} -->
	<div class="wp-block-columns are-vertically-aligned-center lw16-hero__columns">
		<!-- wp:column {"verticalAlignment":"center","width":"52%","layout":{"type":"flex","orientation":"vertical"}} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:52%">
			<!-- wp:paragraph {"className":"is-style-sticker"} -->
			<p class="is-style-sticker"><?php esc_html_e( 'Über uns', 'lindenweg16' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":1,"fontSize":"hero"} -->
			<h1 class="wp-block-heading has-hero-font-size"><?php
				printf(
					/* translators: %s: hervorgehobenes Wort (Ort). */
					esc_html__( 'Zwei Radfahrer, eine Werkstatt in %s.', 'lindenweg16' ),
					'<mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color">' . esc_html__( 'Haste', 'lindenweg16' ) . '</mark>'
				);
			?></h1>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"textColor":"text-muted","fontSize":"large"} -->
			<p class="has-text-muted-color has-text-color has-large-font-size"><?php esc_html_e( 'Wir sind Katrin und Sven vom Lindenweg 16 in Haste bei Wunstorf. Wir fahren Rad, wir bauen gern selbst – und aus beidem ist der Haster Haken entstanden.', 'lindenweg16' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-soft"} -->
				<div class="wp-block-button is-style-soft"><a class="wp-block-button__link wp-element-button" href="#geschichte"><?php esc_html_e( 'Unsere Geschichte', 'lindenweg16' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"center","width":"48%"} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:48%">
			<!-- wp:group {"className":"lw16-hero__media","backgroundColor":"accent-3","layout":{"type":"default"}} -->
			<div class="wp-block-group lw16-hero__media has-accent-3-background-color has-background">
<?php if ( $lindenweg16_has_photo ) : ?>
				<!-- wp:post-featured-image {"className":"lw16-about-hero__photo"} /-->
<?php else : ?>
				<!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"lw16-hero__image lw16-photo"} -->
				<figure class="wp-block-image size-full lw16-hero__image lw16-photo"><img width="1400" height="933" fetchpriority="high" src="<?php echo esc_url( get_theme_file_uri( 'assets/images/haster-haken-startseite.jpg' ) ); ?>" alt="<?php esc_attr_e( 'Der Haster Haken: Holzschiene mit Sonnenbrille, Ablage für Gels und Riegel sowie Trinkflasche', 'lindenweg16' ); ?>"/></figure>
				<!-- /wp:image -->
<?php endif; ?>

				<!-- wp:paragraph {"className":"is-style-sticker lw16-hero__sticker lw16-hero__sticker--top lw16-icon lw16-icon--heart","backgroundColor":"accent-1"} -->
				<p class="is-style-sticker lw16-hero__sticker lw16-hero__sticker--top lw16-icon lw16-icon--heart has-accent-1-background-color has-background"><?php esc_html_e( 'Katrin & Sven', 'lindenweg16' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:paragraph {"className":"is-style-sticker lw16-hero__sticker lw16-hero__sticker--bottom","backgroundColor":"accent-4"} -->
				<p class="is-style-sticker lw16-hero__sticker lw16-hero__sticker--bottom has-accent-4-background-color has-background"><?php esc_html_e( 'Lindenweg 16 · Haste', 'lindenweg16' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"full","className":"lw16-section lw16-marquee lw16-band","layout":{"type":"default"}} -->
<section class="wp-block-group alignfull lw16-section lw16-marquee lw16-band">
	<!-- wp:group {"className":"lw16-marquee__track","layout":{"type":"flex","flexWrap":"nowrap"}} -->
	<div class="wp-block-group lw16-marquee__track">
<?php foreach ( $lindenweg16_words as $lindenweg16_word ) : ?>
		<!-- wp:paragraph {"className":"lw16-band__word"} -->
		<p class="lw16-band__word"><?php echo esc_html( $lindenweg16_word ); ?></p>
		<!-- /wp:paragraph -->
<?php endforeach; ?>
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","anchor":"geschichte","align":"wide","className":"lw16-section lw16-about-story","layout":{"type":"default"}} -->
<section class="wp-block-group alignwide lw16-section lw16-about-story" id="geschichte">
	<!-- wp:group {"className":"lw16-about-story__inner","layout":{"type":"default"}} -->
	<div class="wp-block-group lw16-about-story__inner">
		<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
		<p class="is-style-eyebrow"><?php esc_html_e( 'Unsere Geschichte', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->

		<!-- wp:post-content {"className":"lw16-about-story__content","layout":{"type":"default"}} /-->
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section lw16-about-crafts","layout":{"type":"default"}} -->
<section class="wp-block-group alignwide lw16-section lw16-about-crafts">
	<!-- wp:group {"className":"lw16-section-head","layout":{"type":"flex","orientation":"vertical"}} -->
	<div class="wp-block-group lw16-section-head">
		<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
		<p class="is-style-eyebrow"><?php esc_html_e( 'Unsere Werkstatt', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- wp:heading -->
		<h2 class="wp-block-heading"><?php esc_html_e( 'Was bei uns entsteht', 'lindenweg16' ); ?></h2>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"lw16-ledger","layout":{"type":"default"}} -->
	<div class="wp-block-group lw16-ledger">
<?php foreach ( $lindenweg16_crafts as $lindenweg16_i => $lindenweg16_craft ) : ?>
		<!-- wp:group {"className":"lw16-ledger__row","layout":{"type":"default"}} -->
		<div class="wp-block-group lw16-ledger__row">
			<!-- wp:paragraph {"className":"lw16-ledger__number"} -->
			<p class="lw16-ledger__number"><?php echo esc_html( sprintf( '%02d', $lindenweg16_i + 1 ) ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"className":"lw16-ledger__title"} -->
			<h3 class="wp-block-heading lw16-ledger__title"><?php echo esc_html( $lindenweg16_craft['title'] ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"className":"lw16-ledger__text","textColor":"text-muted"} -->
			<p class="lw16-ledger__text has-text-muted-color has-text-color"><?php echo esc_html( $lindenweg16_craft['text'] ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
<?php endforeach; ?>
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section lw16-about-end","layout":{"type":"default"}} -->
<section class="wp-block-group alignwide lw16-section lw16-about-end">
	<!-- wp:group {"className":"lw16-about-end__text","layout":{"type":"flex","orientation":"vertical"}} -->
	<div class="wp-block-group lw16-about-end__text">
		<!-- wp:heading {"fontSize":"xx-large"} -->
		<h2 class="wp-block-heading has-xx-large-font-size"><?php esc_html_e( 'Lust auf Ordnung?', 'lindenweg16' ); ?></h2>
		<!-- /wp:heading -->
		<!-- wp:paragraph {"textColor":"text-muted"} -->
		<p class="has-text-muted-color has-text-color"><?php esc_html_e( 'Der Haster Haken wartet im Shop. Plott auf Shirt, Mütze oder Schirm, ein eigenes Motiv oder eine Idee für ein neues Modul? Erzähl uns davon.', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->

	<!-- wp:buttons {"className":"lw16-about-end__buttons"} -->
	<div class="wp-block-buttons lw16-about-end__buttons">
		<!-- wp:button {"className":"is-style-primary"} -->
		<div class="wp-block-button is-style-primary"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $lindenweg16_shop ); ?>"><?php esc_html_e( 'Zum Shop', 'lindenweg16' ); ?></a></div>
		<!-- /wp:button -->
		<!-- wp:button {"className":"is-style-soft"} -->
		<div class="wp-block-button is-style-soft"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $lindenweg16_contact ); ?>"><?php esc_html_e( 'Anfrage schreiben', 'lindenweg16' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
</section>
<!-- /wp:group -->
