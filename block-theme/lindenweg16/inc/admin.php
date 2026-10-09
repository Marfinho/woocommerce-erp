<?php
/**
 * Design → Lindenweg16: Einstieg ins Bearbeiten ohne Website-Editor.
 *
 * Der Website-Editor lädt sehr viele Dateien auf einmal; manche Hoster
 * blocken das (503). Alles Wichtige lässt sich deshalb auch über die
 * klassischen Bildschirme pflegen – diese Seite bündelt die Wege dorthin und
 * legt auf Wunsch die Startseite als normale, bearbeitbare Seite an.
 *
 * @package Lindenweg16
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menüpunkt unter Design.
 */
function lindenweg16_admin_menu(): void {
	add_theme_page(
		__( 'Lindenweg16 – Inhalte bearbeiten', 'lindenweg16' ),
		__( 'Lindenweg16', 'lindenweg16' ),
		'edit_theme_options',
		'lindenweg16',
		'lindenweg16_admin_page'
	);
}
add_action( 'admin_menu', 'lindenweg16_admin_menu' );

/**
 * Startseite als Seite anlegen (Formular auf der Theme-Seite).
 */
function lindenweg16_create_front_page(): void {
	if ( ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'publish_pages' ) ) {
		wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'lindenweg16' ) );
	}

	check_admin_referer( 'lindenweg16_create_front_page' );

	$registry = WP_Block_Patterns_Registry::get_instance();
	$content  = '';

	foreach ( lindenweg16_front_page_sections() as $slug ) {
		$pattern = $registry->get_registered( $slug );
		if ( $pattern && ! empty( $pattern['content'] ) ) {
			$content .= trim( (string) $pattern['content'] ) . "\n\n";
		}
	}

	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __( 'Startseite', 'lindenweg16' ),
			'post_name'    => 'startseite',
			'post_content' => $content,
			'meta_input'   => array( '_wp_page_template' => 'page-landing' ),
		),
		true
	);

	if ( is_wp_error( $page_id ) ) {
		wp_safe_redirect( add_query_arg( 'lw16-front', 'error', admin_url( 'themes.php?page=lindenweg16' ) ) );
		exit;
	}

	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', (int) $page_id );

	wp_safe_redirect( add_query_arg( 'lw16-front', 'created', admin_url( 'themes.php?page=lindenweg16' ) ) );
	exit;
}
add_action( 'admin_post_lindenweg16_create_front_page', 'lindenweg16_create_front_page' );

/**
 * Die Seite unter Design → Lindenweg16.
 */
function lindenweg16_admin_page(): void {
	$front_id = lindenweg16_editable_front_page_id();
	$status   = isset( $_GET['lw16-front'] ) ? sanitize_key( wp_unslash( (string) $_GET['lw16-front'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige.

	$links = array(
		array(
			__( 'Menüs', 'lindenweg16' ),
			__( 'Hauptmenü im Header und die drei Spalten im Footer. Unter „Positionen verwalten“ ordnest du deine Menüs den Plätzen zu.', 'lindenweg16' ),
			admin_url( 'nav-menus.php' ),
		),
		array(
			__( 'Kundenstimmen', 'lindenweg16' ),
			__( 'Kommen automatisch aus freigegebenen Produktbewertungen mit 4 oder 5 Sternen. Hier moderierst du sie.', 'lindenweg16' ),
			admin_url( 'edit.php?post_type=product&page=product-reviews' ),
		),
		array(
			__( 'Newsletter', 'lindenweg16' ),
			__( 'Bestätigte Anmeldungen und CSV-Export.', 'lindenweg16' ),
			admin_url( 'tools.php?page=lindenweg16-newsletter' ),
		),
		array(
			__( 'Logo und Website-Titel', 'lindenweg16' ),
			__( 'Website-Logo, Titel und Website-Icon.', 'lindenweg16' ),
			admin_url( 'options-general.php' ),
		),
	);

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Lindenweg16 – Inhalte bearbeiten', 'lindenweg16' ) . '</h1>';
	echo '<p>' . esc_html__( 'Alles Wichtige lässt sich auch ohne den Website-Editor pflegen.', 'lindenweg16' ) . '</p>';

	if ( 'created' === $status ) {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Die Startseite ist angelegt und als Startseite eingestellt.', 'lindenweg16' ) . '</p></div>';
	} elseif ( 'error' === $status ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Die Startseite konnte nicht angelegt werden.', 'lindenweg16' ) . '</p></div>';
	}

	echo '<div class="card" style="max-width:760px">';
	echo '<h2>' . esc_html__( 'Startseite', 'lindenweg16' ) . '</h2>';

	if ( $front_id ) {
		echo '<p>' . esc_html__( 'Deine Startseite ist eine normale Seite. Texte, Bilder, FAQ und die Reihenfolge der Abschnitte änderst du im Seiten-Editor.', 'lindenweg16' ) . '</p>';
		printf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a></p>',
			esc_url( (string) get_edit_post_link( $front_id ) ),
			esc_html__( 'Startseite bearbeiten', 'lindenweg16' ),
			esc_url( home_url( '/' ) ),
			esc_html__( 'Ansehen', 'lindenweg16' )
		);
	} else {
		echo '<p>' . esc_html__( 'Lege die Startseite als normale Seite an: Sie bekommt alle Abschnitte des Themes (Hero, Vertrauensleiste, Sets und Module, Neuheiten, So einfach geht’s, Kundenstimmen, FAQ, Newsletter, CTA) und wird unter Einstellungen → Lesen als Startseite eingestellt. Danach bearbeitest du sie wie jede andere Seite.', 'lindenweg16' ) . '</p>';
		if ( 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
			echo '<p>' . esc_html__( 'Hinweis: Deine bisherige Startseite bleibt erhalten, ist danach aber nicht mehr als Startseite eingestellt.', 'lindenweg16' ) . '</p>';
		}
		printf(
			'<form method="post" action="%1$s"><input type="hidden" name="action" value="lindenweg16_create_front_page">%2$s<p><button type="submit" class="button button-primary">%3$s</button></p></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'lindenweg16_create_front_page', '_wpnonce', true, false ),
			esc_html__( 'Startseite als Seite anlegen', 'lindenweg16' )
		);
	}
	echo '</div>';

	echo '<h2 style="margin-top:2em">' . esc_html__( 'Weitere Bereiche', 'lindenweg16' ) . '</h2>';
	echo '<table class="widefat striped" style="max-width:760px"><tbody>';
	foreach ( $links as $link ) {
		printf(
			'<tr><td style="width:30%%"><a href="%1$s"><strong>%2$s</strong></a></td><td>%3$s</td></tr>',
			esc_url( $link[2] ),
			esc_html( $link[0] ),
			esc_html( $link[1] )
		);
	}
	echo '</tbody></table>';

	echo '<p style="max-width:760px;margin-top:2em">' . esc_html__( 'Farben und Schriften stellst du unter Design → Editor → Stile ein. Lädt der Website-Editor bei dir nicht (weiße Seite, 503-Fehler in der Konsole), bremst dein Hoster die vielen gleichzeitigen Anfragen – frag dort nach einem höheren Limit für gleichzeitige Anfragen bzw. PHP-Prozesse.', 'lindenweg16' ) . '</p>';
	echo '</div>';
}
