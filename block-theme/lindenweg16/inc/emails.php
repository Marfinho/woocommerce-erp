<?php
/**
 * E-Mails im Look der Website.
 *
 * Ein zentrales Design für alle Mails – gespeist aus denselben Farbrollen wie
 * die Website (Website-Editor → Stile → Farben):
 *
 *  1. WooCommerce- und Germanized-Mails: Farben aus der Palette, Logo im Kopf
 *     und Lindenweg16-Feinschliff (Rundungen, Pillen-Buttons, Schriften).
 *  2. Reine Text-Mails (WordPress, Newsletter-Bestätigung, andere Plugins)
 *     werden in dasselbe Layout verpackt; Links werden klickbar, ein Link
 *     allein in einer Zeile wird zum Button.
 *  3. Das Lindenweg16-ERP-Plugin holt sich sein Layout über den Filter
 *     „lindenweg16_mail_html“ ebenfalls hier ab.
 *
 * Abschalten: add_filter( 'lindenweg16_email_design', '__return_false' );
 *
 * @package Lindenweg16
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ist das E-Mail-Design des Themes aktiv?
 */
function lindenweg16_email_design_enabled(): bool {
	return class_exists( 'WooCommerce' ) && (bool) apply_filters( 'lindenweg16_email_design', true );
}

/**
 * Farbe in #rrggbb umwandeln (#rgb, #rrggbb, rgb()/rgba()). Sonst null.
 */
function lindenweg16_email_hex( string $color ): ?string {
	$color = strtolower( trim( $color ) );

	if ( preg_match( '/^#([0-9a-f]{3})$/', $color, $m ) ) {
		return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
	}

	if ( preg_match( '/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $color, $m ) ) {
		return '#' . $m[1];
	}

	if ( preg_match( '/^rgba?\(\s*(\d{1,3})[\s,]+(\d{1,3})[\s,]+(\d{1,3})/', $color, $m ) ) {
		return sprintf( '#%02x%02x%02x', min( 255, (int) $m[1] ), min( 255, (int) $m[2] ), min( 255, (int) $m[3] ) );
	}

	return null;
}

/**
 * Mischt zwei Hex-Farben (wie color-mix() auf der Website).
 *
 * @param string $color   Erste Farbe.
 * @param string $with    Zweite Farbe.
 * @param int    $percent Anteil der ersten Farbe in Prozent.
 */
function lindenweg16_email_mix( string $color, string $with, int $percent ): string {
	$a = sscanf( $color, '#%02x%02x%02x' );
	$b = sscanf( $with, '#%02x%02x%02x' );
	$p = max( 0, min( 100, $percent ) ) / 100;

	return sprintf(
		'#%02x%02x%02x',
		(int) round( $a[0] * $p + $b[0] * ( 1 - $p ) ),
		(int) round( $a[1] * $p + $b[1] * ( 1 - $p ) ),
		(int) round( $a[2] * $p + $b[2] * ( 1 - $p ) )
	);
}

/**
 * Die Farbrollen der Website als Hex-Werte – inklusive deiner Änderungen im
 * Website-Editor.
 *
 * @return array<string, string> Rolle => #rrggbb.
 */
function lindenweg16_email_colors(): array {
	static $colors = null;

	if ( null !== $colors ) {
		return $colors;
	}

	// Rückfall: die Platzhalter aus theme.json.
	$colors = array(
		'background'    => '#f4f4f4',
		'surface'       => '#ffffff',
		'surface-muted' => '#ebebeb',
		'text'          => '#1c1c1c',
		'text-muted'    => '#595959',
		'line'          => '#d6d6d6',
		'primary'       => '#2e2e2e',
		'on-primary'    => '#ffffff',
		'accent-1'      => '#dedede',
		'accent-2'      => '#8a8a8a',
		'accent-3'      => '#c9c9c9',
		'accent-4'      => '#e8e8e8',
		'on-accent'     => '#1c1c1c',
		'sale'          => '#3b3b3b',
		'success'       => '#4d4d4d',
		'warning'       => '#6e6e6e',
		'error'         => '#262626',
		'ink'           => '#1f1f1f',
		'on-ink'        => '#f7f7f7',
	);

	$palette = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings( array( 'color', 'palette' ) ) : array();

	foreach ( array( 'theme', 'custom' ) as $origin ) {
		foreach ( (array) ( $palette[ $origin ] ?? array() ) as $entry ) {
			$slug = (string) ( $entry['slug'] ?? '' );
			$hex  = lindenweg16_email_hex( (string) ( $entry['color'] ?? '' ) );

			if ( isset( $colors[ $slug ] ) && $hex ) {
				$colors[ $slug ] = $hex;
			}
		}
	}

	/**
	 * Filtert die Farben für E-Mails.
	 *
	 * @param array<string, string> $colors Rolle => #rrggbb.
	 */
	$colors = (array) apply_filters( 'lindenweg16_email_colors', $colors );

	return $colors;
}

/**
 * WooCommerce-E-Mail-Farben aus der Palette. Die Werte in WooCommerce →
 * Einstellungen → E-Mails greifen dann nicht mehr; das Theme gibt den Ton an.
 */
function lindenweg16_email_register_color_options(): void {
	if ( ! lindenweg16_email_design_enabled() ) {
		return;
	}

	$map = array(
		'woocommerce_email_base_color'            => 'primary',
		'woocommerce_email_background_color'      => 'background',
		'woocommerce_email_body_background_color' => 'surface',
		'woocommerce_email_text_color'            => 'text',
		'woocommerce_email_footer_text_color'     => 'text-muted',
	);

	foreach ( $map as $option => $role ) {
		add_filter(
			'pre_option_' . $option,
			static function () use ( $role ) {
				return lindenweg16_email_colors()[ $role ];
			}
		);
	}

	// Logo der Website im Kopf, solange in WooCommerce kein eigenes Kopfbild gesetzt ist.
	add_filter(
		'option_woocommerce_email_header_image',
		static function ( $image ) {
			if ( ! empty( $image ) ) {
				return $image;
			}

			$logo = (int) get_theme_mod( 'custom_logo' );

			return $logo ? (string) wp_get_attachment_image_url( $logo, 'full' ) : $image;
		}
	);
}
add_action( 'init', 'lindenweg16_email_register_color_options' );

/**
 * Lindenweg16-Feinschliff für das WooCommerce-E-Mail-Template.
 *
 * Mailprogramme können kein color-mix(), keine Variablen und kein
 * backdrop-filter – Mischfarben werden deshalb hier ausgerechnet. Webfonts
 * zeigen nur manche Programme (z. B. Apple Mail); sonst greift die
 * Systemschrift.
 *
 * @param string $css CSS von WooCommerce.
 */
function lindenweg16_email_styles( string $css ): string {
	if ( ! lindenweg16_email_design_enabled() ) {
		return $css;
	}

	$c        = lindenweg16_email_colors();
	$fonts    = get_theme_file_uri( 'assets/fonts/' );
	$body     = "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
	$heading  = "'Bricolage Grotesque', Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";
	$mono     = "'DM Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace";
	$tint     = lindenweg16_email_mix( $c['accent-1'], $c['surface'], 30 );
	$line     = lindenweg16_email_mix( $c['line'], $c['surface'], 70 );
	$on_prim  = $c['on-primary'];

	$css .= "
@font-face { font-family: 'Inter'; font-style: normal; font-weight: 100 900; src: url('{$fonts}inter-latin-wght-normal.woff2') format('woff2'); }
@font-face { font-family: 'Bricolage Grotesque'; font-style: normal; font-weight: 200 800; src: url('{$fonts}bricolage-grotesque-latin-wght-normal.woff2') format('woff2'); }
@font-face { font-family: 'DM Mono'; font-style: normal; font-weight: 500; src: url('{$fonts}dm-mono-latin-500-normal.woff2') format('woff2'); }

body, #outer_wrapper, #wrapper, #body_content, #body_content_inner, #body_content_inner p, td, th, .td, .address, #template_footer, #credit {
	font-family: {$body} !important;
}

#template_container, #inner_wrapper {
	border-radius: 28px !important;
	border: 0 !important;
	box-shadow: none !important;
	overflow: hidden;
}

#template_header, #template_header_image { background-color: {$c['surface']} !important; }

#template_header_image img { max-height: 56px; width: auto; }

#template_header h1, #template_header h1 a, #body_content h2, #body_content h3, .email-logo-text, .email-logo-text a {
	font-family: {$heading} !important;
	font-weight: 800 !important;
	color: {$c['text']} !important;
	letter-spacing: -0.02em;
}

#template_header h1 { font-size: 30px !important; font-weight: 800 !important; line-height: 1.1 !important; }
#body_content h2 { font-size: 20px !important; font-weight: 800 !important; }

#body_content_inner { color: {$c['text']} !important; font-size: 15px !important; line-height: 1.6 !important; }

/* Bestellübersicht als ruhige Karte */
#body_content table.td, .email-order-details {
	border: 0 !important;
	border-radius: 20px !important;
	background-color: {$c['surface-muted']} !important;
	overflow: hidden;
}

#body_content table.td th, #body_content table.td td, .email-order-details th, .email-order-details td {
	border-color: {$line} !important;
}

#body_content table.td th:first-child, #body_content table.td td:first-child { padding-left: 16px !important; }
#body_content table.td th:last-child, #body_content table.td td:last-child { padding-right: 16px !important; }

.email-order-details .order-totals-total th, .email-order-details .order-totals-total td { font-size: 17px !important; }

/* Preise, Mengen, Bestellnummern in DM Mono */
.woocommerce-Price-amount, .amount, .email-order-item-meta .quantity, td.td:nth-child(2), td.td:nth-child(3) {
	font-family: {$mono} !important;
	font-variant-numeric: tabular-nums;
}

/* Adressen als Karte */
.address, address.address {
	border: 0 !important;
	border-radius: 20px !important;
	background-color: {$c['surface-muted']} !important;
	padding: 16px 18px !important;
	color: {$c['text']} !important;
}

/* Buttons als Pille (WooCommerce-Buttons und die Buttons aus Text-Mails) */
#body_content_inner a.lw16-mail-button, #body_content_inner p > a[style*=\"padding\"] {
	border-radius: 999px !important;
	background-color: {$c['primary']} !important;
	color: {$on_prim} !important;
	font-family: {$body} !important;
	font-weight: 700 !important;
	text-decoration: none !important;
}

/* Hinweise und Einleitung */
.email-introduction, .email-additional-content {
	color: {$c['text']} !important;
}

.lw16-mail-note {
	background-color: {$tint} !important;
	border-radius: 16px !important;
	padding: 14px 16px !important;
}

#template_footer, #template_footer td, #credit, #credit p {
	color: {$c['text-muted']} !important;
	font-size: 12px !important;
}

#template_footer a, #credit a { color: {$c['text-muted']} !important; }
";

	return $css;
}
add_filter( 'woocommerce_email_styles', 'lindenweg16_email_styles', 20 );

/**
 * Klartext in einfaches E-Mail-HTML umwandeln: Absätze, klickbare Links und
 * ein Button für jede Zeile, die nur aus einem Link besteht.
 */
function lindenweg16_email_text_to_html( string $text ): string {
	$c     = lindenweg16_email_colors();
	$text  = str_replace( array( "\r\n", "\r" ), "\n", trim( $text ) );
	$lines = explode( "\n", $text );
	$out   = array();

	foreach ( $lines as $line ) {
		$trimmed = trim( $line );

		// <https://…> (so schreibt WordPress manche Links) als normaler Link.
		if ( preg_match( '#^<(https?://[^\s>]+)>$#', $trimmed, $m ) ) {
			$trimmed = $m[1];
		}

		if ( preg_match( '#^https?://\S+$#', $trimmed ) ) {
			$out[] = sprintf(
				'<p style="margin:20px 0;"><a class="lw16-mail-button" href="%1$s" style="display:inline-block;padding:14px 26px;border-radius:999px;background-color:%2$s;color:%3$s;font-weight:700;text-decoration:none;">%4$s</a></p>',
				esc_url( $trimmed ),
				esc_attr( $c['primary'] ),
				esc_attr( $c['on-primary'] ),
				esc_html( lindenweg16_email_button_label( $trimmed ) )
			);
			continue;
		}

		$out[] = make_clickable( esc_html( $line ) );
	}

	return wpautop( implode( "\n", $out ) );
}

/**
 * Beschriftung für einen Link-Button – passend zu bekannten Links.
 */
function lindenweg16_email_button_label( string $url ): string {
	if ( false !== strpos( $url, 'lindenweg16_newsletter_confirm' ) ) {
		return __( 'Anmeldung bestätigen', 'lindenweg16' );
	}

	if ( false !== strpos( $url, 'action=rp' ) || false !== strpos( $url, 'lostpassword' ) || false !== strpos( $url, 'reset-password' ) ) {
		return __( 'Neues Passwort festlegen', 'lindenweg16' );
	}

	if ( false !== strpos( $url, 'wp-login.php' ) ) {
		return __( 'Zum Login', 'lindenweg16' );
	}

	return __( 'Link öffnen', 'lindenweg16' );
}

/**
 * Fertig gestaltete Mail im WooCommerce-Template mit Lindenweg16-Look.
 *
 * @param string $heading   Überschrift (meist der Betreff).
 * @param string $body_html Inhalt als HTML.
 */
function lindenweg16_email_wrap( string $heading, string $body_html ): string {
	$mailer = WC()->mailer();
	$html   = $mailer->wrap_message( $heading, $body_html );

	$email = new WC_Email();

	return (string) $email->style_inline( $html );
}

/**
 * Ist die Mail schon HTML (oder ausdrücklich Text)? Dann bleibt sie, wie sie ist.
 *
 * @param string|string[] $headers Header der Mail.
 * @param string          $message Inhalt.
 */
function lindenweg16_email_has_explicit_type( $headers, string $message ): bool {
	$headers = is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;

	if ( preg_match( '/content-type:\s*(text\/html|multipart\/|text\/plain)/i', $headers ) ) {
		return true;
	}

	if ( 'text/plain' !== apply_filters( 'wp_mail_content_type', 'text/plain' ) ) {
		return true;
	}

	return (bool) preg_match( '/<(html|body|table|p|div|br)[\s>\/]/i', $message );
}

/**
 * Reine Text-Mails (WordPress, Newsletter-Bestätigung, andere Plugins) im
 * selben Design verschicken.
 *
 * @param array<string, mixed> $args Argumente von wp_mail().
 * @return array<string, mixed>
 */
function lindenweg16_email_wrap_plain( array $args ): array {
	if ( ! lindenweg16_email_design_enabled() || ! function_exists( 'WC' ) ) {
		return $args;
	}

	$message = (string) ( $args['message'] ?? '' );
	$headers = $args['headers'] ?? array();

	if ( '' === trim( $message ) || lindenweg16_email_has_explicit_type( $headers, $message ) ) {
		return $args;
	}

	/**
	 * Filtert, ob eine Text-Mail ins E-Mail-Design verpackt wird.
	 *
	 * @param bool                 $wrap Verpacken?
	 * @param array<string, mixed> $args Argumente von wp_mail().
	 */
	if ( ! apply_filters( 'lindenweg16_email_wrap_plain', true, $args ) ) {
		return $args;
	}

	$args['message'] = lindenweg16_email_wrap( (string) ( $args['subject'] ?? '' ), lindenweg16_email_text_to_html( $message ) );

	if ( is_array( $headers ) ) {
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
	} else {
		$headers = trim( (string) $headers . "\nContent-Type: text/html; charset=UTF-8" );
	}
	$args['headers'] = $headers;

	return $args;
}
add_filter( 'wp_mail', 'lindenweg16_email_wrap_plain', 20 );

/**
 * Layout für die Mails des Lindenweg16-ERP-Plugins (MailTemplate).
 *
 * @param string|null $html    Bisheriges Ergebnis (null = Plugin-Layout).
 * @param string      $text    Klartext der Mail.
 * @param string      $subject Betreff.
 */
function lindenweg16_email_erp_html( $html, string $text, string $subject = '' ) {
	if ( ! lindenweg16_email_design_enabled() || ! function_exists( 'WC' ) ) {
		return $html;
	}

	return lindenweg16_email_wrap( $subject, lindenweg16_email_text_to_html( $text ) );
}
add_filter( 'lindenweg16_mail_html', 'lindenweg16_email_erp_html', 10, 3 );
