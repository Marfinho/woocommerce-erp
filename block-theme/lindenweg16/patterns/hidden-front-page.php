<?php
/**
 * Title: Startseite (Inhalt)
 * Slug: lindenweg16/hidden-front-page
 * Inserter: no
 *
 * Ist unter Einstellungen → Lesen eine statische Startseite gesetzt, die mit
 * den Lindenweg16-Abschnitten gebaut ist (z. B. über Design → Lindenweg16 →
 * „Startseite als Seite anlegen“), zeigt die Startseite deren Inhalt – so
 * lässt sie sich ganz normal unter Seiten bearbeiten, ohne Website-Editor.
 * Sonst erscheinen die Standard-Abschnitte des Themes.
 *
 * @package Lindenweg16
 */

if ( lindenweg16_editable_front_page_id() ) :
	?>
<!-- wp:post-content {"align":"full","layout":{"type":"constrained"}} /-->
	<?php
else :
	foreach ( lindenweg16_front_page_sections() as $lindenweg16_slug ) :
		?>
<!-- wp:pattern {"slug":"<?php echo esc_attr( $lindenweg16_slug ); ?>"} /-->
		<?php
	endforeach;
endif;
