<?php
/**
 * Title: Häufige Fragen (FAQ)
 * Slug: lindenweg16/faq
 * Categories: lindenweg16
 * Keywords: faq, fragen, antworten, hilfe, akkordeon
 * Viewport Width: 1200
 * Description: Aufklappbare Fragen und Antworten (Details-Block, ohne JavaScript). Fragen lassen sich im Editor ergänzen, ändern und umsortieren.
 *
 * @package Lindenweg16
 */

$lindenweg16_faq = array(
	array(
		__( 'Was ist der Unterschied zwischen Sets und Modulen?', 'lindenweg16' ),
		__( 'Ein Set enthält die Holzschiene und eine Auswahl an Modulen – damit kannst du sofort loslegen. Module kaufst du einzeln, wenn du deinen Haster Haken erweitern oder umbauen willst.', 'lindenweg16' ),
	),
	array(
		__( 'Kann ich später weitere Module nachkaufen?', 'lindenweg16' ),
		__( 'Ja. Der Haster Haken ist modular aufgebaut: Du startest mit dem, was du gerade brauchst, und ergänzt Module, wenn neues Rad-Gear dazukommt.', 'lindenweg16' ),
	),
	array(
		__( 'Wie wird der Haster Haken montiert?', 'lindenweg16' ),
		__( 'Die Holzschiene wird an der Wand verschraubt, die Module hängst du anschließend einfach ein. Eine kurze Anleitung liegt jedem Paket bei.', 'lindenweg16' ),
	),
	array(
		__( 'Welche Dübel brauche ich für meine Wand?', 'lindenweg16' ),
		__( 'Das hängt von deiner Wand ab: Für Beton und Mauerwerk passen Universaldübel, für Gipskarton brauchst du Hohlraumdübel. Im Zweifel hilft dir der Baumarkt um die Ecke – oder du schreibst uns.', 'lindenweg16' ),
	),
	array(
		__( 'Wie lange dauert der Versand?', 'lindenweg16' ),
		__( 'Die Lieferzeit steht auf jeder Produktseite direkt unter dem Preis. Sobald dein Paket unterwegs ist, bekommst du eine Sendungsverfolgung per E-Mail.', 'lindenweg16' ),
	),
	array(
		__( 'Kann ich meine Bestellung zurückgeben?', 'lindenweg16' ),
		__( 'Ja, du hast ein gesetzliches Widerrufsrecht von 14 Tagen. Alle Details findest du in unserer Widerrufsbelehrung.', 'lindenweg16' ),
	),
	array(
		__( 'Wie pflege ich das Holz?', 'lindenweg16' ),
		__( 'Ein trockenes oder leicht feuchtes Tuch genügt. Bitte keine scharfen Reiniger verwenden.', 'lindenweg16' ),
	),
);
?>
<!-- wp:group {"tagName":"section","anchor":"faq","align":"wide","className":"lw16-section lw16-faq","layout":{"type":"constrained","contentSize":"880px"}} -->
<section class="wp-block-group alignwide lw16-section lw16-faq" id="faq">
	<!-- wp:group {"className":"lw16-section-head","layout":{"type":"flex","orientation":"vertical"}} -->
	<div class="wp-block-group lw16-section-head">
		<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
		<p class="is-style-eyebrow"><?php esc_html_e( 'FAQ', 'lindenweg16' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- wp:heading -->
		<h2 class="wp-block-heading"><?php esc_html_e( 'Häufige Fragen', 'lindenweg16' ); ?></h2>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"className":"lw16-faq__list","layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
	<div class="wp-block-group lw16-faq__list">
<?php foreach ( $lindenweg16_faq as $lindenweg16_item ) : ?>
		<!-- wp:details {"className":"lw16-faq__item"} -->
		<details class="wp-block-details lw16-faq__item"><summary><?php echo esc_html( $lindenweg16_item[0] ); ?></summary><!-- wp:paragraph -->
		<p><?php echo esc_html( $lindenweg16_item[1] ); ?></p>
		<!-- /wp:paragraph --></details>
		<!-- /wp:details -->
<?php endforeach; ?>
	</div>
	<!-- /wp:group -->
</section>
<!-- /wp:group -->
