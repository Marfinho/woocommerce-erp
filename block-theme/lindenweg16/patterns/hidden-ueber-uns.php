<?php
/**
 * Title: Über uns (Template)
 * Slug: lindenweg16/hidden-ueber-uns
 * Inserter: no
 * Description: Inhalt des Templates „Über uns“: Einleitung, Geschichte (Beitragsbild und Seiteninhalt), Werkstatt-Kacheln, Entstehung in vier Schritten und zwei Karten zu Shop und Anfragen.
 *
 * Die Geschichte ist der Inhalt der Seite selbst und bleibt im Seiten-Editor
 * bearbeitbar; das Foto daneben ist das Beitragsbild der Seite. Ohne
 * Beitragsbild steht der Text allein in voller Breite.
 *
 * @package Lindenweg16
 */

$lindenweg16_page_id   = (int) get_queried_object_id();
$lindenweg16_has_photo = $lindenweg16_page_id && has_post_thumbnail( $lindenweg16_page_id );
$lindenweg16_shop      = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
$lindenweg16_contact   = lindenweg16_contact_page_url();

$lindenweg16_crafts = array(
	array(
		'color' => 'accent-2',
		'title' => __( '3D-Druck', 'lindenweg16' ),
		'text'  => __( 'Alle Module des Haster Hakens drucken wir selbst – in kleiner Auflage, jedes für genau ein Teil konstruiert.', 'lindenweg16' ),
	),
	array(
		'color' => 'accent-4',
		'title' => __( 'Holz', 'lindenweg16' ),
		'text'  => __( 'Die Basis jedes Haster Hakens ist eine Holzschiene. Auf sie kommen Wandanker und Module.', 'lindenweg16' ),
	),
	array(
		'color' => 'surface-muted',
		'title' => __( 'Gips- & Betonguss', 'lindenweg16' ),
		'text'  => __( 'Von Hand gegossen – keine zwei Stücke sehen genau gleich aus.', 'lindenweg16' ),
	),
	array(
		'color' => 'surface',
		'title' => __( 'Plotten', 'lindenweg16' ),
		'text'  => __( 'Folie für Shirts, Mützen, Schirme und mehr – nach deinem Motiv.', 'lindenweg16' ),
	),
);

$lindenweg16_steps = array(
	array(
		'color' => 'accent-1',
		'title' => __( 'Entwurf', 'lindenweg16' ),
		'text'  => __( 'Jedes Modul ist für ein Teil konstruiert: Helm, Brille, Flasche oder Schuhe.', 'lindenweg16' ),
	),
	array(
		'color' => 'accent-2',
		'title' => __( '3D-Druck', 'lindenweg16' ),
		'text'  => __( 'Gedruckt wird in unserer Werkstatt in Haste, Schicht für Schicht.', 'lindenweg16' ),
	),
	array(
		'color' => 'accent-4',
		'title' => __( 'Montage', 'lindenweg16' ),
		'text'  => __( 'Messing-Gewindeeinsätze setzen wir von Hand ein – so lassen sich die Module immer wieder versetzen.', 'lindenweg16' ),
	),
	array(
		'color' => 'accent-3',
		'title' => __( 'Versand', 'lindenweg16' ),
		'text'  => __( 'Verpackt in Papier und Karton, mit DHL zu dir.', 'lindenweg16' ),
	),
);
?>
<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section lw16-about-intro","layout":{"type":"flex","orientation":"vertical"}} -->
<section class="wp-block-group alignwide lw16-section lw16-about-intro">
	<!-- wp:paragraph {"className":"is-style-sticker"} -->
	<p class="is-style-sticker"><?php esc_html_e( 'Lindenweg 16 · Haste', 'lindenweg16' ); ?></p>
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

	<!-- wp:paragraph {"textColor":"text-muted","fontSize":"large","className":"lw16-about-intro__lead"} -->
	<p class="lw16-about-intro__lead has-text-muted-color has-text-color has-large-font-size"><?php esc_html_e( 'Wir sind Katrin und Sven vom Lindenweg 16 in Haste bei Wunstorf. Wir fahren Rad, wir bauen gern selbst – und aus beidem ist der Haster Haken entstanden.', 'lindenweg16' ); ?></p>
	<!-- /wp:paragraph -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section lw16-about-story<?php echo $lindenweg16_has_photo ? '' : ' lw16-about-story--text'; ?>","layout":{"type":"default"}} -->
<section class="wp-block-group alignwide lw16-section lw16-about-story<?php echo $lindenweg16_has_photo ? '' : ' lw16-about-story--text'; ?>">
<?php if ( $lindenweg16_has_photo ) : ?>
	<!-- wp:columns {"verticalAlignment":"stretch","className":"lw16-about-story__columns"} -->
	<div class="wp-block-columns are-vertically-aligned-stretch lw16-about-story__columns">
		<!-- wp:column {"verticalAlignment":"stretch","width":"45%"} -->
		<div class="wp-block-column is-vertically-aligned-stretch" style="flex-basis:45%">
			<!-- wp:post-featured-image {"className":"lw16-about-story__photo"} /-->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"stretch","width":"55%"} -->
		<div class="wp-block-column is-vertically-aligned-stretch" style="flex-basis:55%">
<?php endif; ?>
			<!-- wp:group {"className":"is-style-card lw16-about-story__card","layout":{"type":"default"}} -->
			<div class="wp-block-group is-style-card lw16-about-story__card">
				<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
				<p class="is-style-eyebrow"><?php esc_html_e( 'Unsere Geschichte', 'lindenweg16' ); ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:post-content {"className":"lw16-about-story__content","layout":{"type":"default"}} /-->
			</div>
			<!-- /wp:group -->
<?php if ( $lindenweg16_has_photo ) : ?>
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
<?php endif; ?>
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

	<!-- wp:group {"className":"lw16-bento lw16-about-crafts__grid","layout":{"type":"default"}} -->
	<div class="wp-block-group lw16-bento lw16-about-crafts__grid">
<?php foreach ( $lindenweg16_crafts as $lindenweg16_i => $lindenweg16_craft ) : ?>
		<!-- wp:group {"className":"lw16-bento__tile","backgroundColor":"<?php echo esc_attr( $lindenweg16_craft['color'] ); ?>","layout":{"type":"default"}} -->
		<div class="wp-block-group lw16-bento__tile has-<?php echo esc_attr( $lindenweg16_craft['color'] ); ?>-background-color has-background">
			<!-- wp:paragraph {"className":"lw16-about-crafts__number"} -->
			<p class="lw16-about-crafts__number"><?php echo esc_html( sprintf( '%02d', $lindenweg16_i + 1 ) ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} -->
			<div class="wp-block-group">
				<!-- wp:heading {"level":3} -->
				<h3 class="wp-block-heading"><?php echo esc_html( $lindenweg16_craft['title'] ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html( $lindenweg16_craft['text'] ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
<?php endforeach; ?>
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section lw16-about-process","layout":{"type":"constrained","contentSize":"1100px"}} -->
<section class="wp-block-group alignwide lw16-section lw16-about-process">
	<!-- wp:group {"className":"lw16-section-head","layout":{"type":"flex","orientation":"vertical","justifyContent":"center"}} -->
	<div class="wp-block-group lw16-section-head">
		<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
		<p class="is-style-eyebrow"><?php esc_html_e( 'Handgemacht', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- wp:heading {"textAlign":"center"} -->
		<h2 class="wp-block-heading has-text-align-center"><?php esc_html_e( 'Wie ein Haster Haken entsteht', 'lindenweg16' ); ?></h2>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:group -->

	<!-- wp:columns {"className":"lw16-steps"} -->
	<div class="wp-block-columns lw16-steps">
<?php foreach ( $lindenweg16_steps as $lindenweg16_i => $lindenweg16_step ) : ?>
		<!-- wp:column {"className":"lw16-step"} -->
		<div class="wp-block-column lw16-step">
			<!-- wp:paragraph {"className":"lw16-step__number","backgroundColor":"<?php echo esc_attr( $lindenweg16_step['color'] ); ?>"} -->
			<p class="lw16-step__number has-<?php echo esc_attr( $lindenweg16_step['color'] ); ?>-background-color has-background"><?php echo esc_html( sprintf( '%02d', $lindenweg16_i + 1 ) ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"large"} -->
			<h3 class="wp-block-heading has-large-font-size"><?php echo esc_html( $lindenweg16_step['title'] ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"text-muted"} -->
			<p class="has-text-muted-color has-text-color"><?php echo esc_html( $lindenweg16_step['text'] ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
<?php endforeach; ?>
	</div>
	<!-- /wp:columns -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"wide","className":"lw16-section lw16-about-next","layout":{"type":"default"}} -->
<section class="wp-block-group alignwide lw16-section lw16-about-next">
	<!-- wp:columns {"className":"lw16-about-next__columns"} -->
	<div class="wp-block-columns lw16-about-next__columns">
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:group {"className":"is-style-card lw16-about-next__card","backgroundColor":"accent-2","layout":{"type":"default"}} -->
			<div class="wp-block-group is-style-card lw16-about-next__card has-accent-2-background-color has-background">
				<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
				<p class="is-style-eyebrow"><?php esc_html_e( 'Unser erstes Produkt', 'lindenweg16' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:heading {"level":3,"fontSize":"x-large"} -->
				<h3 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'Der Haster Haken', 'lindenweg16' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'Eine Schiene, fünf Module – dein Rad-Gear bekommt seinen festen Platz direkt neben dem Rad.', 'lindenweg16' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:buttons -->
				<div class="wp-block-buttons">
					<!-- wp:button {"className":"is-style-primary"} -->
					<div class="wp-block-button is-style-primary"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $lindenweg16_shop ); ?>"><?php esc_html_e( 'Zum Shop', 'lindenweg16' ); ?></a></div>
					<!-- /wp:button -->
				</div>
				<!-- /wp:buttons -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:group {"className":"is-style-card lw16-about-next__card","layout":{"type":"default"}} -->
			<div class="wp-block-group is-style-card lw16-about-next__card">
				<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
				<p class="is-style-eyebrow"><?php esc_html_e( 'Auf Anfrage', 'lindenweg16' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:heading {"level":3,"fontSize":"x-large"} -->
				<h3 class="wp-block-heading has-x-large-font-size"><?php esc_html_e( 'Eigene Wünsche', 'lindenweg16' ); ?></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php esc_html_e( 'Plott auf Shirt, Mütze oder Schirm, ein eigenes Motiv oder eine Idee für ein neues Modul? Erzähl uns davon.', 'lindenweg16' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:buttons -->
				<div class="wp-block-buttons">
					<!-- wp:button {"className":"is-style-soft"} -->
					<div class="wp-block-button is-style-soft"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $lindenweg16_contact ); ?>"><?php esc_html_e( 'Anfrage schreiben', 'lindenweg16' ); ?></a></div>
					<!-- /wp:button -->
				</div>
				<!-- /wp:buttons -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</section>
<!-- /wp:group -->
