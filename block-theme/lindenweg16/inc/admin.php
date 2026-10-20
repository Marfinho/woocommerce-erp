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
		'lindenweg16-inhalte',
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
		wp_safe_redirect( add_query_arg( 'lw16-front', 'error', admin_url( 'themes.php?page=lindenweg16-inhalte' ) ) );
		exit;
	}

	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', (int) $page_id );

	wp_safe_redirect( add_query_arg( 'lw16-front', 'created', admin_url( 'themes.php?page=lindenweg16-inhalte' ) ) );
	exit;
}
add_action( 'admin_post_lindenweg16_create_front_page', 'lindenweg16_create_front_page' );

/**
 * Fotos der Startseite speichern (Formular auf der Theme-Seite).
 */
function lindenweg16_save_front_images(): void {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'lindenweg16_save_front_images' );

	foreach ( array_keys( lindenweg16_front_image_slots() ) as $slot ) {
		$id = isset( $_POST[ 'lw16_image_' . $slot ] ) ? absint( wp_unslash( $_POST[ 'lw16_image_' . $slot ] ) ) : 0;

		if ( $id && wp_attachment_is_image( $id ) ) {
			set_theme_mod( 'lindenweg16_image_' . $slot, $id );
		} else {
			remove_theme_mod( 'lindenweg16_image_' . $slot );
		}
	}

	// Gecachte Seiten zeigen sonst noch die alten Bilder.
	do_action( 'litespeed_purge_all' );

	wp_safe_redirect( add_query_arg( array( 'page' => 'lindenweg16-inhalte', 'lw16-images' => 'saved' ), admin_url( 'themes.php' ) ) );
	exit;
}
add_action( 'admin_post_lindenweg16_save_front_images', 'lindenweg16_save_front_images' );

/**
 * Farben speichern oder auf das Standardschema zurücksetzen.
 */
function lindenweg16_save_colors(): void {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'lindenweg16_save_colors' );

	if ( isset( $_POST['lw16_reset_colors'] ) ) {
		remove_theme_mod( 'lindenweg16_colors' );
	} else {
		$colors = array();
		foreach ( array_keys( lindenweg16_default_palette() ) as $slug ) {
			$color = isset( $_POST['lw16_color'][ $slug ] ) ? sanitize_hex_color( wp_unslash( (string) $_POST['lw16_color'][ $slug ] ) ) : '';
			if ( $color ) {
				$colors[ $slug ] = strtolower( $color );
			}
		}
		set_theme_mod( 'lindenweg16_colors', $colors );
	}

	if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
		wp_clean_theme_json_cache();
	}
	do_action( 'litespeed_purge_all' );

	wp_safe_redirect( add_query_arg( array( 'page' => 'lindenweg16', 'lw16-colors' => 'saved' ), admin_url( 'themes.php' ) ) . '#lw16-farben' );
	exit;
}
add_action( 'admin_post_lindenweg16_save_colors', 'lindenweg16_save_colors' );

/**
 * Zahlungsarten im Footer speichern.
 */
function lindenweg16_save_payments(): void {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'lindenweg16_save_payments' );

	if ( isset( $_POST['lw16_reset_payments'] ) ) {
		remove_theme_mod( 'lindenweg16_payments' );
	} else {
		$raw   = isset( $_POST['lw16_payments'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['lw16_payments'] ) ) : '';
		$items = array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
		set_theme_mod( 'lindenweg16_payments', array_slice( $items, 0, 20 ) );
	}

	do_action( 'litespeed_purge_all' );

	wp_safe_redirect( add_query_arg( array( 'page' => 'lindenweg16', 'lw16-payments' => 'saved' ), admin_url( 'themes.php' ) ) . '#lw16-zahlungsarten' );
	exit;
}
add_action( 'admin_post_lindenweg16_save_payments', 'lindenweg16_save_payments' );

/**
 * Beschreibung für Google (Startseite) speichern.
 */
function lindenweg16_save_meta_description(): void {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'lindenweg16_save_meta_description' );

	$text = isset( $_POST['lw16_meta_description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['lw16_meta_description'] ) ) : '';
	$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

	if ( '' === $text || lindenweg16_default_front_description() === $text ) {
		remove_theme_mod( 'lindenweg16_meta_description' );
	} else {
		set_theme_mod( 'lindenweg16_meta_description', $text );
	}

	do_action( 'litespeed_purge_all' );

	wp_safe_redirect( add_query_arg( array( 'page' => 'lindenweg16', 'lw16-seo' => 'saved' ), admin_url( 'themes.php' ) ) . '#lw16-google' );
	exit;
}
add_action( 'admin_post_lindenweg16_save_meta_description', 'lindenweg16_save_meta_description' );

/**
 * Mediathek-Auswahl nur auf der Theme-Seite laden.
 *
 * @param string $hook Aktuelle Admin-Seite.
 */
function lindenweg16_admin_assets( string $hook ): void {
	if ( 'appearance_page_lindenweg16-inhalte' !== $hook ) {
		return;
	}

	wp_enqueue_media();
	wp_add_inline_script(
		'media-editor',
		"document.addEventListener('click',function(e){var b=e.target.closest('[data-lw16-pick],[data-lw16-clear]');if(!b)return;e.preventDefault();var box=b.closest('.lw16-slot'),input=box.querySelector('input'),img=box.querySelector('img');if(b.hasAttribute('data-lw16-clear')){input.value='';img.removeAttribute('src');img.hidden=true;return;}var f=wp.media({title:b.dataset.title,library:{type:'image'},multiple:false,button:{text:b.dataset.button}});f.on('select',function(){var a=f.state().get('selection').first().toJSON();input.value=a.id;img.src=(a.sizes&&(a.sizes.medium||a.sizes.full)||a).url;img.hidden=false;});f.open();});"
	);
}
add_action( 'admin_enqueue_scripts', 'lindenweg16_admin_assets' );

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


	echo '<div class="card" style="max-width:760px">';
	echo '<h2>' . esc_html__( 'Fotos der Startseite', 'lindenweg16' ) . '</h2>';
	if ( 'saved' === ( isset( $_GET['lw16-images'] ) ? sanitize_key( wp_unslash( (string) $_GET['lw16-images'] ) ) : '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige.
		echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Gespeichert. Die Startseite zeigt jetzt deine Fotos.', 'lindenweg16' ) . '</p></div>';
	}
	echo '<p>' . esc_html__( 'Ohne eigenes Foto zeigt die Startseite die Zeichnungen des Themes. Am besten wirken helle Produktfotos; für das große Bild oben eignet sich ein Hochformat oder Quadrat.', 'lindenweg16' ) . '</p>';
	if ( $front_id ) {
		echo '<p><em>' . esc_html__( 'Deine Startseite ist eine eigene Seite – die Fotos dort tauschst du im Seiten-Editor. Diese Auswahl gilt für die Theme-Startseite und neu angelegte Startseiten.', 'lindenweg16' ) . '</em></p>';
	}
	printf( '<form method="post" action="%s"><input type="hidden" name="action" value="lindenweg16_save_front_images">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'lindenweg16_save_front_images' );
	echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px">';
	foreach ( lindenweg16_front_image_slots() as $slot => $label ) {
		$current = lindenweg16_front_image( $slot );
		printf(
			'<div class="lw16-slot"><p><strong>%1$s</strong></p><img alt="" style="display:block;width:100%%;aspect-ratio:1;object-fit:cover;border-radius:8px;background:#f0f0f1;margin-bottom:8px" %2$s><input type="hidden" name="lw16_image_%3$s" value="%4$s"><button type="button" class="button" data-lw16-pick data-title="%1$s" data-button="%5$s">%6$s</button> <button type="button" class="button-link" data-lw16-clear>%7$s</button></div>',
			esc_html( $label ),
			$current ? 'src="' . esc_url( (string) wp_get_attachment_image_url( $current['id'], 'medium' ) ) . '"' : 'hidden',
			esc_attr( $slot ),
			esc_attr( $current ? (string) $current['id'] : '' ),
			esc_attr__( 'Dieses Foto verwenden', 'lindenweg16' ),
			esc_html__( 'Foto wählen', 'lindenweg16' ),
			esc_html__( 'Zeichnung verwenden', 'lindenweg16' )
		);
	}
	echo '</div>';
	echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Fotos speichern', 'lindenweg16' ) . '</button></p></form>';
	echo '</div>';


	$groups = array(
		__( 'Grundflächen und Text', 'lindenweg16' ) => array( 'background', 'surface', 'surface-muted', 'text', 'text-muted', 'line' ),
		__( 'Marke', 'lindenweg16' )                  => array( 'primary', 'on-primary', 'ink', 'on-ink' ),
		__( 'Akzente (Sticker, Kacheln, Sterne)', 'lindenweg16' ) => array( 'accent-1', 'accent-2', 'accent-3', 'accent-4', 'on-accent' ),
		__( 'Hinweise', 'lindenweg16' )               => array( 'sale', 'success', 'warning', 'error' ),
	);
	$palette = lindenweg16_default_palette();

	echo '<div class="card" id="lw16-farben" style="max-width:760px">';
	echo '<h2>' . esc_html__( 'Farben', 'lindenweg16' ) . '</h2>';
	if ( 'saved' === ( isset( $_GET['lw16-colors'] ) ? sanitize_key( wp_unslash( (string) $_GET['lw16-colors'] ) ) : '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige.
		echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Farben gespeichert. Website, Editor und E-Mails nutzen jetzt die neuen Farben.', 'lindenweg16' ) . '</p></div>';
	}
	echo '<p>' . esc_html__( 'Das Standardschema passt zum Logo: Schiefergrau als Hauptfarbe, warmes Papierweiß, Akzente in Eichenhonig, Nussbaum, hellem Schiefer und Ton. Jede Farbe hat eine feste Aufgabe – änderst du sie hier, ändert sie sich überall.', 'lindenweg16' ) . '</p>';

	$warnings = array();
	foreach ( lindenweg16_contrast_pairs() as $pair ) {
		$ratio = lindenweg16_contrast( lindenweg16_color( $pair[0] ), lindenweg16_color( $pair[1] ) );
		if ( $ratio < $pair[2] ) {
			$warnings[] = sprintf(
				/* translators: 1: Schriftfarbe, 2: Fläche, 3: Kontrast, 4: Mindestwert. */
				__( '„%1$s“ auf „%2$s“: Kontrast %3$s:1 (mindestens %4$s:1)', 'lindenweg16' ),
				$palette[ $pair[0] ]['name'] ?? $pair[0],
				$palette[ $pair[1] ]['name'] ?? $pair[1],
				number_format_i18n( $ratio, 1 ),
				number_format_i18n( $pair[2], 1 )
			);
		}
	}
	if ( $warnings ) {
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Schlecht lesbar:', 'lindenweg16' ) . '</strong></p><ul style="list-style:disc;margin-left:1.5em">';
		foreach ( $warnings as $warning ) {
			echo '<li>' . esc_html( $warning ) . '</li>';
		}
		echo '</ul></div>';
	}

	printf( '<form method="post" action="%s"><input type="hidden" name="action" value="lindenweg16_save_colors">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'lindenweg16_save_colors' );
	foreach ( $groups as $title => $slugs ) {
		echo '<h3>' . esc_html( $title ) . '</h3><table class="form-table" role="presentation"><tbody>';
		foreach ( $slugs as $slug ) {
			if ( ! isset( $palette[ $slug ] ) ) {
				continue;
			}
			printf(
				'<tr><th scope="row"><label for="lw16-color-%1$s">%2$s</label></th><td><input type="color" class="lw16-color" id="lw16-color-%1$s" name="lw16_color[%1$s]" value="%3$s" style="width:64px;height:36px;padding:2px;vertical-align:middle"> <code>%3$s</code> <span class="description">%5$s</span></td></tr>',
				esc_attr( $slug ),
				esc_html( $palette[ $slug ]['name'] ),
				esc_attr( lindenweg16_color( $slug ) ),
				esc_attr( $palette[ $slug ]['color'] ),
				lindenweg16_color( $slug ) !== $palette[ $slug ]['color']
					/* translators: %s: Standardfarbe. */
					? esc_html( sprintf( __( 'Standard: %s', 'lindenweg16' ), $palette[ $slug ]['color'] ) )
					: ''
			);
		}
		echo '</tbody></table>';
	}
	echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Farben speichern', 'lindenweg16' ) . '</button> ';
	echo '<button type="submit" name="lw16_reset_colors" value="1" class="button" onclick="return confirm(\'' . esc_js( __( 'Alle Farben auf das Standardschema zurücksetzen?', 'lindenweg16' ) ) . '\')">' . esc_html__( 'Standardschema wiederherstellen', 'lindenweg16' ) . '</button></p></form>';
	echo '<p class="description">' . esc_html__( 'Hast du im Website-Editor unter Stile eigene Farben gesetzt, gehen diese vor.', 'lindenweg16' ) . '</p>';
	echo '</div>';


	echo '<div class="card" id="lw16-zahlungsarten" style="max-width:760px">';
	echo '<h2>' . esc_html__( 'Zahlungsarten im Footer', 'lindenweg16' ) . '</h2>';
	if ( 'saved' === ( isset( $_GET['lw16-payments'] ) ? sanitize_key( wp_unslash( (string) $_GET['lw16-payments'] ) ) : '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige.
		echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Gespeichert. Der Footer zeigt jetzt diese Zahlungsarten.', 'lindenweg16' ) . '</p></div>';
	}
	echo '<p>' . esc_html__( 'Eine Zahlungsart pro Zeile, in der gewünschten Reihenfolge. Leer lassen blendet die Pillen aus. Nenne nur, was im Shop wirklich angeboten wird.', 'lindenweg16' ) . '</p>';
	printf( '<form method="post" action="%s"><input type="hidden" name="action" value="lindenweg16_save_payments">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'lindenweg16_save_payments' );
	printf(
		'<textarea name="lw16_payments" rows="8" class="large-text code" style="max-width:360px">%s</textarea>',
		esc_textarea( implode( "\n", lindenweg16_payment_methods() ) )
	);
	echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Zahlungsarten speichern', 'lindenweg16' ) . '</button> ';
	echo '<button type="submit" name="lw16_reset_payments" value="1" class="button">' . esc_html__( 'Standard wiederherstellen', 'lindenweg16' ) . '</button></p></form>';
	echo '</div>';


	echo '<div class="card" id="lw16-google" style="max-width:760px">';
	echo '<h2>' . esc_html__( 'Beschreibung für Google (Startseite)', 'lindenweg16' ) . '</h2>';
	if ( 'saved' === ( isset( $_GET['lw16-seo'] ) ? sanitize_key( wp_unslash( (string) $_GET['lw16-seo'] ) ) : '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige.
		echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Gespeichert.', 'lindenweg16' ) . '</p></div>';
	}
	if ( lindenweg16_has_seo_plugin() ) {
		echo '<p>' . esc_html__( 'Ein SEO-Plugin ist aktiv – die Beschreibung pflegst du dort.', 'lindenweg16' ) . '</p>';
	} else {
		echo '<p>' . esc_html__( 'Dieser Text erscheint in Suchergebnissen unter dem Titel deiner Startseite. Ideal sind 120 bis 155 Zeichen. Produkte und Seiten bekommen automatisch ihre Kurzbeschreibung bzw. ihren Textanfang.', 'lindenweg16' ) . '</p>';
		printf( '<form method="post" action="%s"><input type="hidden" name="action" value="lindenweg16_save_meta_description">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'lindenweg16_save_meta_description' );
		$current = (string) get_theme_mod( 'lindenweg16_meta_description', '' );
		printf(
			'<textarea name="lw16_meta_description" rows="3" class="large-text" maxlength="300" oninput="this.nextElementSibling.textContent=this.value.length+\' Zeichen\'">%1$s</textarea><p class="description">%2$s</p>',
			esc_textarea( '' !== $current ? $current : lindenweg16_default_front_description() ),
			esc_html( mb_strlen( '' !== $current ? $current : lindenweg16_default_front_description() ) . ' ' . __( 'Zeichen', 'lindenweg16' ) )
		);
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Beschreibung speichern', 'lindenweg16' ) . '</button></p></form>';
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
