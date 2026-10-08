<?php
/**
 * Newsletter-Anmeldung mit Double-Opt-in – ohne Plugin.
 *
 * Ablauf:
 *  1. Das Formular (Pattern „Newsletter“) schickt die Adresse an admin-post.php.
 *  2. Das Theme speichert noch nichts, sondern verschickt eine Mail mit einem
 *     signierten Bestätigungslink (7 Tage gültig).
 *  3. Erst der Klick auf den Link trägt die Adresse mit Zeitpunkt ein.
 *  4. Unter Werkzeuge → Newsletter siehst du die Liste, exportierst sie als
 *     CSV für dein Versand-Tool und löschst einzelne Einträge.
 *
 * Versendet werden die eigentlichen Newsletter nicht vom Theme.
 * Wer das Ganze nicht will: add_filter( 'lindenweg16_newsletter_enabled', '__return_false' );
 *
 * @package Lindenweg16
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LINDENWEG16_NEWSLETTER_OPTION = 'lindenweg16_newsletter_subscribers';
const LINDENWEG16_NEWSLETTER_TTL    = 7 * DAY_IN_SECONDS;

/**
 * Ist die eingebaute Anmeldung aktiv?
 */
function lindenweg16_newsletter_enabled(): bool {
	return (bool) apply_filters( 'lindenweg16_newsletter_enabled', true );
}

/**
 * Signierter Token für eine Adresse und einen Zweck (confirm / unsubscribe).
 */
function lindenweg16_newsletter_token( string $email, string $purpose, ?int $time = null ): string {
	$time    = $time ?? time();
	$payload = $purpose . '|' . strtolower( $email ) . '|' . $time;
	$sig     = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );

	return rtrim( strtr( base64_encode( $payload . '|' . $sig ), '+/', '-_' ), '=' );
}

/**
 * Prüft einen Token. Gibt die Adresse zurück oder null.
 */
function lindenweg16_newsletter_verify( string $token, string $purpose, int $max_age = 0 ): ?string {
	$raw = base64_decode( strtr( $token, '-_', '+/' ), true );

	if ( false === $raw || substr_count( $raw, '|' ) !== 3 ) {
		return null;
	}

	[ $got_purpose, $email, $time, $sig ] = explode( '|', $raw );
	$expected = hash_hmac( 'sha256', $got_purpose . '|' . $email . '|' . $time, wp_salt( 'auth' ) );

	if ( ! hash_equals( $expected, $sig ) || $got_purpose !== $purpose || ! is_email( $email ) ) {
		return null;
	}

	if ( $max_age > 0 && ( time() - (int) $time ) > $max_age ) {
		return null;
	}

	return $email;
}

/**
 * Wohin nach dem Absenden zurückgeleitet wird – mit Status im Query-String.
 */
function lindenweg16_newsletter_redirect( string $status ): void {
	$target = wp_get_referer() ?: home_url( '/' );
	$target = remove_query_arg( 'lw16-newsletter', $target );

	wp_safe_redirect( add_query_arg( 'lw16-newsletter', $status, $target ) . '#newsletter' );
	exit;
}

/**
 * Schritt 1: Formular absenden.
 */
function lindenweg16_newsletter_subscribe(): void {
	if ( ! lindenweg16_newsletter_enabled() ) {
		lindenweg16_newsletter_redirect( 'error' );
	}

	// Honigtopf: Menschen sehen das Feld nicht, Bots füllen es aus.
	if ( ! empty( $_POST['lw16_website'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- öffentliches Formular, Schutz über Honigtopf, Bremse und Double-Opt-in.
		lindenweg16_newsletter_redirect( 'check' );
	}

	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( '' === $email || ! is_email( $email ) ) {
		lindenweg16_newsletter_redirect( 'invalid' );
	}

	// Bremse: höchstens fünf Anmeldungen je IP und Stunde.
	$ip_hash = 'lw16_nl_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt( 'nonce' ) );
	$count   = (int) get_transient( $ip_hash );
	if ( $count >= 5 ) {
		lindenweg16_newsletter_redirect( 'error' );
	}
	set_transient( $ip_hash, $count + 1, HOUR_IN_SECONDS );

	$subscribers = get_option( LINDENWEG16_NEWSLETTER_OPTION, array() );
	if ( isset( $subscribers[ strtolower( $email ) ] ) ) {
		// Schon bestätigt – kein Hinweis darauf, wer eingetragen ist.
		lindenweg16_newsletter_redirect( 'check' );
	}

	$link = add_query_arg(
		array(
			'action' => 'lindenweg16_newsletter_confirm',
			't'      => lindenweg16_newsletter_token( $email, 'confirm' ),
		),
		admin_url( 'admin-post.php' )
	);

	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

	/* translators: %s: Name der Website. */
	$subject = sprintf( __( 'Bitte bestätige deine Anmeldung bei %s', 'lindenweg16' ), $site );

	$message = sprintf(
		/* translators: 1: Name der Website, 2: Bestätigungslink. */
		__( "Hallo,\n\nschön, dass du Post aus der Werkstatt von %1\$s bekommen möchtest!\n\nBitte bestätige deine Anmeldung mit einem Klick auf diesen Link:\n%2\$s\n\nDer Link ist sieben Tage gültig. Wenn du dich nicht angemeldet hast, ignoriere diese Mail einfach – dann passiert nichts.\n\nLiebe Grüße aus Haste", 'lindenweg16' ),
		$site,
		$link
	);

	wp_mail( $email, $subject, $message );

	lindenweg16_newsletter_redirect( 'check' );
}
add_action( 'admin_post_nopriv_lindenweg16_newsletter', 'lindenweg16_newsletter_subscribe' );
add_action( 'admin_post_lindenweg16_newsletter', 'lindenweg16_newsletter_subscribe' );

/**
 * Schritt 3: Bestätigungslink.
 */
function lindenweg16_newsletter_confirm(): void {
	$token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- der signierte Token ersetzt die Nonce.
	$email = lindenweg16_newsletter_verify( $token, 'confirm', LINDENWEG16_NEWSLETTER_TTL );

	if ( null === $email || ! lindenweg16_newsletter_enabled() ) {
		wp_safe_redirect( add_query_arg( 'lw16-newsletter', 'expired', home_url( '/' ) ) . '#newsletter' );
		exit;
	}

	$subscribers = get_option( LINDENWEG16_NEWSLETTER_OPTION, array() );
	$key         = strtolower( $email );

	if ( ! isset( $subscribers[ $key ] ) ) {
		$subscribers[ $key ] = array(
			'email'     => $email,
			'confirmed' => time(),
		);
		update_option( LINDENWEG16_NEWSLETTER_OPTION, $subscribers, false );

		/**
		 * Eine Adresse wurde bestätigt – etwa, um sie an ein Versand-Tool weiterzugeben.
		 *
		 * @param string $email Bestätigte Adresse.
		 */
		do_action( 'lindenweg16_newsletter_confirmed', $email );
	}

	wp_safe_redirect( add_query_arg( 'lw16-newsletter', 'confirmed', home_url( '/' ) ) . '#newsletter' );
	exit;
}
add_action( 'admin_post_nopriv_lindenweg16_newsletter_confirm', 'lindenweg16_newsletter_confirm' );
add_action( 'admin_post_lindenweg16_newsletter_confirm', 'lindenweg16_newsletter_confirm' );

/**
 * Abmeldelink (steht im CSV-Export für dein Versand-Tool).
 */
function lindenweg16_newsletter_unsubscribe_url( string $email ): string {
	return add_query_arg(
		array(
			'action' => 'lindenweg16_newsletter_unsubscribe',
			't'      => lindenweg16_newsletter_token( $email, 'unsubscribe', 0 ),
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * Abmelden per Link.
 */
function lindenweg16_newsletter_unsubscribe(): void {
	$token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signierter Token.
	$email = lindenweg16_newsletter_verify( $token, 'unsubscribe' );

	if ( null !== $email ) {
		lindenweg16_newsletter_remove( $email );
	}

	wp_safe_redirect( add_query_arg( 'lw16-newsletter', 'unsubscribed', home_url( '/' ) ) . '#newsletter' );
	exit;
}
add_action( 'admin_post_nopriv_lindenweg16_newsletter_unsubscribe', 'lindenweg16_newsletter_unsubscribe' );
add_action( 'admin_post_lindenweg16_newsletter_unsubscribe', 'lindenweg16_newsletter_unsubscribe' );

/**
 * Entfernt eine Adresse aus der Liste.
 */
function lindenweg16_newsletter_remove( string $email ): bool {
	$subscribers = get_option( LINDENWEG16_NEWSLETTER_OPTION, array() );
	$key         = strtolower( $email );

	if ( ! isset( $subscribers[ $key ] ) ) {
		return false;
	}

	unset( $subscribers[ $key ] );
	update_option( LINDENWEG16_NEWSLETTER_OPTION, $subscribers, false );

	return true;
}

/**
 * Statusmeldung unter dem Formular nach dem Zurückleiten.
 *
 * @param string $content Gerenderter HTML-Block.
 */
function lindenweg16_newsletter_status( string $content ): string {
	if ( false === strpos( $content, 'lw16-newsletter__form' ) || empty( $_GET['lw16-newsletter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige.
		return $content;
	}

	$messages = array(
		'check'        => array( 'success', __( 'Fast geschafft! Schau in dein Postfach und bestätige deine Anmeldung.', 'lindenweg16' ) ),
		'confirmed'    => array( 'success', __( 'Danke! Deine Anmeldung ist bestätigt.', 'lindenweg16' ) ),
		'unsubscribed' => array( 'success', __( 'Du bist abgemeldet. Schade – aber danke, dass du dabei warst.', 'lindenweg16' ) ),
		'invalid'      => array( 'error', __( 'Das sieht nicht nach einer gültigen E-Mail-Adresse aus. Magst du sie noch einmal prüfen?', 'lindenweg16' ) ),
		'expired'      => array( 'error', __( 'Der Bestätigungslink ist abgelaufen oder ungültig. Melde dich einfach noch einmal an.', 'lindenweg16' ) ),
		'error'        => array( 'error', __( 'Das hat gerade nicht geklappt. Bitte versuch es später noch einmal.', 'lindenweg16' ) ),
	);

	$key = sanitize_key( wp_unslash( (string) $_GET['lw16-newsletter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( ! isset( $messages[ $key ] ) ) {
		return $content;
	}

	[ $type, $text ] = $messages[ $key ];

	return $content . sprintf(
		'<p class="lw16-newsletter__status is-%1$s" role="status">%2$s</p>',
		esc_attr( $type ),
		esc_html( $text )
	);
}
add_filter( 'render_block_core/html', 'lindenweg16_newsletter_status' );

/**
 * Werkzeuge → Newsletter: Liste, CSV-Export, Löschen.
 */
function lindenweg16_newsletter_admin_menu(): void {
	if ( ! lindenweg16_newsletter_enabled() ) {
		return;
	}

	add_management_page(
		__( 'Newsletter-Anmeldungen', 'lindenweg16' ),
		__( 'Newsletter', 'lindenweg16' ),
		'manage_options',
		'lindenweg16-newsletter',
		'lindenweg16_newsletter_admin_page'
	);
}
add_action( 'admin_menu', 'lindenweg16_newsletter_admin_menu' );

/**
 * CSV-Export und Löschen, bevor die Seite ausgegeben wird.
 */
function lindenweg16_newsletter_admin_actions(): void {
	if ( ! isset( $_GET['page'] ) || 'lindenweg16-newsletter' !== $_GET['page'] || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	if ( isset( $_GET['lw16-export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'lindenweg16_newsletter_export' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=newsletter-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'email', 'confirmed_at', 'unsubscribe_url' ) );
		foreach ( get_option( LINDENWEG16_NEWSLETTER_OPTION, array() ) as $row ) {
			fputcsv( $out, array( $row['email'], gmdate( 'c', (int) $row['confirmed'] ), lindenweg16_newsletter_unsubscribe_url( $row['email'] ) ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	if ( isset( $_GET['lw16-delete'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'lindenweg16_newsletter_delete' );
		lindenweg16_newsletter_remove( sanitize_email( wp_unslash( (string) $_GET['lw16-delete'] ) ) );
		wp_safe_redirect( admin_url( 'tools.php?page=lindenweg16-newsletter&deleted=1' ) );
		exit;
	}
}
add_action( 'admin_init', 'lindenweg16_newsletter_admin_actions' );

/**
 * Admin-Seite.
 */
function lindenweg16_newsletter_admin_page(): void {
	$subscribers = get_option( LINDENWEG16_NEWSLETTER_OPTION, array() );
	$export_url  = wp_nonce_url( admin_url( 'tools.php?page=lindenweg16-newsletter&lw16-export=1' ), 'lindenweg16_newsletter_export' );

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Newsletter-Anmeldungen', 'lindenweg16' ) . '</h1>';
	echo '<p>' . esc_html__( 'Hier stehen alle Adressen, die ihre Anmeldung per Link bestätigt haben (Double-Opt-in). Den Newsletter selbst verschickst du mit deinem Versand-Tool – exportiere die Liste dafür als CSV.', 'lindenweg16' ) . '</p>';

	if ( isset( $_GET['deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Eintrag gelöscht.', 'lindenweg16' ) . '</p></div>';
	}

	printf(
		'<p><a class="button button-primary" href="%1$s">%2$s</a> <span>%3$s</span></p>',
		esc_url( $export_url ),
		esc_html__( 'Als CSV exportieren', 'lindenweg16' ),
		/* translators: %d: Anzahl der Anmeldungen. */
		esc_html( sprintf( _n( '%d Anmeldung', '%d Anmeldungen', count( $subscribers ), 'lindenweg16' ), count( $subscribers ) ) )
	);

	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'E-Mail', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Bestätigt am', 'lindenweg16' ) . '</th><th></th></tr></thead><tbody>';

	if ( ! $subscribers ) {
		echo '<tr><td colspan="3">' . esc_html__( 'Noch keine bestätigten Anmeldungen.', 'lindenweg16' ) . '</td></tr>';
	}

	foreach ( $subscribers as $row ) {
		$delete = wp_nonce_url( add_query_arg( array( 'page' => 'lindenweg16-newsletter', 'lw16-delete' => rawurlencode( $row['email'] ) ), admin_url( 'tools.php' ) ), 'lindenweg16_newsletter_delete' );

		printf(
			'<tr><td>%1$s</td><td>%2$s</td><td><a href="%3$s" class="delete">%4$s</a></td></tr>',
			esc_html( $row['email'] ),
			esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['confirmed'] ) ),
			esc_url( $delete ),
			esc_html__( 'Löschen', 'lindenweg16' )
		);
	}

	echo '</tbody></table></div>';
}

/**
 * DSGVO: Auskunft und Löschung über Werkzeuge → Personenbezogene Daten.
 */
function lindenweg16_newsletter_privacy_exporter( array $exporters ): array {
	$exporters['lindenweg16-newsletter'] = array(
		'exporter_friendly_name' => __( 'Newsletter (Lindenweg16)', 'lindenweg16' ),
		'callback'               => static function ( string $email ): array {
			$subscribers = get_option( LINDENWEG16_NEWSLETTER_OPTION, array() );
			$data        = array();

			if ( isset( $subscribers[ strtolower( $email ) ] ) ) {
				$data[] = array(
					'group_id'    => 'lindenweg16-newsletter',
					'group_label' => __( 'Newsletter', 'lindenweg16' ),
					'item_id'     => 'newsletter-' . md5( strtolower( $email ) ),
					'data'        => array(
						array(
							'name'  => __( 'E-Mail', 'lindenweg16' ),
							'value' => $email,
						),
						array(
							'name'  => __( 'Bestätigt am', 'lindenweg16' ),
							'value' => wp_date( 'c', (int) $subscribers[ strtolower( $email ) ]['confirmed'] ),
						),
					),
				);
			}

			return array(
				'data' => $data,
				'done' => true,
			);
		},
	);

	return $exporters;
}
add_filter( 'wp_privacy_personal_data_exporters', 'lindenweg16_newsletter_privacy_exporter' );

/**
 * DSGVO: Löschen.
 */
function lindenweg16_newsletter_privacy_eraser( array $erasers ): array {
	$erasers['lindenweg16-newsletter'] = array(
		'eraser_friendly_name' => __( 'Newsletter (Lindenweg16)', 'lindenweg16' ),
		'callback'             => static function ( string $email ): array {
			$removed = lindenweg16_newsletter_remove( $email );

			return array(
				'items_removed'  => $removed,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		},
	);

	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'lindenweg16_newsletter_privacy_eraser' );
