<?php
/**
 * Title: Bestellbestätigung – Dankeschön und nächste Schritte
 * Slug: lindenweg16/hidden-order-thanks
 * Inserter: no
 *
 * @package Lindenweg16
 */

?>
<!-- wp:heading {"level":2,"textAlign":"center","fontSize":"x-large"} -->
<h2 class="wp-block-heading has-text-align-center has-x-large-font-size"><?php esc_html_e( 'Wie geht’s jetzt weiter?', 'lindenweg16' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:html -->
<ol class="lw16-next-steps">
	<li><?php esc_html_e( 'Du bekommst gleich eine Bestellbestätigung per E-Mail. Schau zur Not auch im Spam-Ordner nach.', 'lindenweg16' ); ?></li>
	<li><?php esc_html_e( 'Wir bauen deinen Haster Haken in unserer Werkstatt zusammen und prüfen jedes Teil.', 'lindenweg16' ); ?></li>
	<li><?php esc_html_e( 'Sobald dein Paket unterwegs ist, schicken wir dir die Sendungsverfolgung.', 'lindenweg16' ); ?></li>
</ol>
<!-- /wp:html -->

<!-- wp:paragraph {"align":"center","textColor":"text-muted","fontSize":"small"} -->
<p class="has-text-align-center has-text-muted-color has-text-color has-small-font-size"><?php
	printf(
		/* translators: %s: Link „schreib uns“. */
		esc_html__( 'Fragen zu deiner Bestellung? %s – wir antworten persönlich.', 'lindenweg16' ),
		'<a href="' . esc_url( lindenweg16_page_url( 'kontakt' ) ) . '">' . esc_html__( 'Schreib uns', 'lindenweg16' ) . '</a>'
	);
?></p>
<!-- /wp:paragraph -->
