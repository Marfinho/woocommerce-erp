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
 *     „lindenweg16_mail_html“ ebenfalls hier ab – auch die persönlichen Mails
 *     der Mailbox (Kontext „personal“: Text, Signatur, Zitat).
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

#template_header h1 { font-size: 34px !important; font-weight: 800 !important; line-height: 1.08 !important; letter-spacing: -0.035em !important; }
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

/* Spaltenköpfe wie im Warenkorb der Website: DM Mono, Versalien, gesperrt */
#body_content table.td thead th, .email-order-details thead th {
	font-family: {$mono} !important;
	font-size: 11px !important;
	font-weight: 500 !important;
	letter-spacing: 0.14em;
	text-transform: uppercase;
	color: {$c['text-muted']} !important;
}

/* Gesamtsumme mit Textmarker */
.email-order-details .order-totals-total td .amount, #body_content table.td tfoot tr:last-child td .amount {
	background-color: {$c['accent-1']} !important;
	color: {$c['on-accent']} !important;
	border-radius: 8px;
	padding: 2px 8px;
}

/* Footer dunkel wie auf der Website */
#template_footer {
	background-color: {$c['ink']} !important;
	border-radius: 0 0 28px 28px;
}

#template_footer td, #credit, #credit p {
	color: {$c['on-ink']} !important;
	font-size: 12px !important;
	border: 0 !important;
}

#template_footer a, #credit a { color: {$c['on-ink']} !important; }
";

	// Handy-Größen (nur Programme, die eingebettete Stile verstehen).
	$css .= "
@media screen and (max-width: 600px) {
	#header_wrapper h1 { font-size: 28px !important; }
	#body_content_inner { font-size: 15px !important; }
}
";

	return $css;
}
add_filter( 'woocommerce_email_styles', 'lindenweg16_email_styles', 20 );

/**
 * Text des Stickers über der Überschrift: der Untertitel der Website
 * (Einstellungen → Allgemein), sonst „Handgemacht in Haste“.
 */
function lindenweg16_email_eyebrow(): string {
	$text = trim( (string) get_bloginfo( 'description' ) );

	if ( '' === $text ) {
		$text = __( 'Handgemacht in Haste', 'lindenweg16' );
	}

	/**
	 * Filtert den Sticker über der Überschrift. Leer = kein Sticker.
	 *
	 * @param string $text Text.
	 */
	return (string) apply_filters( 'lindenweg16_email_eyebrow', $text );
}

/**
 * Website-Look für die fertige Mail: Sticker über der Überschrift und das
 * letzte Wort der Überschrift mit Textmarker – wie „Haster Haken“ auf der
 * Startseite.
 *
 * Läuft nach dem Inlining; die Stile stehen deshalb direkt im HTML. Programme
 * ohne Verläufe zeigen den Marker als volle Fläche.
 *
 * @param string $html Fertiges HTML der Mail.
 */
function lindenweg16_email_brand( $html ) {
	$html = (string) $html;

	if ( ! lindenweg16_email_design_enabled() || false !== strpos( $html, 'lw16-marker' ) ) {
		return $html;
	}

	if ( ! preg_match( '#<h1([^>]*)>(.*?)</h1>#s', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		return $html;
	}

	$c       = lindenweg16_email_colors();
	$heading = $m[2][0];

	// Nur reiner Text: das letzte Wort markieren – ist es sehr kurz („Dich“ ja, „ab“ nicht), die letzten zwei.
	if ( false === strpos( $heading, '<' ) && preg_match( '/^(.*?)(\S+?)([!?.,:…]*)$/us', trim( $heading ), $w ) ) {
		$before = $w[1];
		$words  = $w[2];

		if ( mb_strlen( $words ) <= 3 && preg_match( '/^(.*?)(\S+\s+)$/us', $before, $prev ) ) {
			$before = $prev[1];
			$words  = $prev[2] . $words;
		}

		$heading = $before . sprintf(
			'<span class="lw16-marker" style="background-color:%1$s;color:%2$s;border-radius:8px;padding:0 0.12em;white-space:nowrap;">%3$s</span>',
			esc_attr( $c['accent-1'] ),
			esc_attr( $c['on-accent'] ),
			$words
		) . $w[3];
	}

	$eyebrow = lindenweg16_email_eyebrow();
	$sticker = '' === $eyebrow ? '' : sprintf(
		'<p class="lw16-mail-eyebrow" style="margin:0 0 16px;"><span style="display:inline-block;padding:6px 14px;border-radius:999px;background-color:%1$s;color:%2$s;font-family:\'DM Mono\', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;font-size:11px;font-weight:500;letter-spacing:0.14em;text-transform:uppercase;transform:rotate(-2deg);">%3$s</span></p>',
		esc_attr( $c['accent-1'] ),
		esc_attr( $c['on-accent'] ),
		esc_html( $eyebrow )
	);

	$replacement = $sticker . '<h1' . $m[1][0] . '>' . $heading . '</h1>';
	$html        = substr_replace( $html, $replacement, $m[0][1], strlen( $m[0][0] ) );

	// Textmarker nur in der unteren Hälfte wie auf der Website – für Programme, die
	// eingebettete Stile verstehen (Apple Mail, iOS, Gmail). Steht erst hier, weil
	// das Inlining Regeln für noch nicht vorhandene Elemente verwirft.
	$style = sprintf(
		'<style type="text/css">@media screen { .lw16-marker { background: linear-gradient(180deg, rgba(255,255,255,0) 46%%, %1$s 46%%) !important; border-radius: 6px !important; } }</style>',
		esc_attr( $c['accent-1'] )
	);

	return false !== stripos( $html, '</head>' ) ? (string) preg_replace( '#</head>#i', $style . '</head>', $html, 1 ) : $style . $html;
}
add_filter( 'woocommerce_mail_content', 'lindenweg16_email_brand', 20 );

/**
 * Klartext in einfaches E-Mail-HTML umwandeln: Absätze, klickbare Links und
 * ein Button für jede Zeile, die nur aus einem Link besteht.
 */
function lindenweg16_email_text_to_html( string $text ): string {
	$c     = lindenweg16_email_colors();
	$text  = str_replace( array( "\r\n", "\r" ), "\n", trim( $text ) );
	$lines = explode( "\n", $text );
	$out   = array();

	// Zitierte Zeilen ("> …", wie in Antworten der Mailbox) bilden einen eigenen Block.
	$quote = array();
	$flush = static function () use ( &$quote, &$out, $c ): void {
		if ( ! $quote ) {
			return;
		}

		$out[]  = sprintf(
			'<blockquote class="lw16-mail-quote" style="margin:12px 0;padding:2px 0 2px 14px;border-left:3px solid %1$s;color:%2$s;font-size:14px;">%3$s</blockquote>',
			esc_attr( $c['line'] ),
			esc_attr( $c['text-muted'] ),
			make_clickable( implode( '<br>', array_map( 'esc_html', $quote ) ) )
		);
		$quote = array();
	};

	foreach ( $lines as $line ) {
		if ( preg_match( '/^\s*>+ ?(.*)$/u', $line, $qm ) ) {
			$quote[] = $qm[1];
			continue;
		}

		$flush();

		$trimmed = trim( $line );

		// <https://…> (so schreibt WordPress manche Links) als normaler Link.
		if ( preg_match( '#^<(https?://[^\s>]+)>$#', $trimmed, $m ) ) {
			$trimmed = $m[1];
		}

		// Eine Zeile nur mit Link – oder „Jetzt bestellen: https://…“ – wird ein Knopf.
		$url   = '';
		$label = '';
		if ( preg_match( '#^https?://\S+$#', $trimmed ) ) {
			$url   = $trimmed;
			$label = lindenweg16_email_button_label( $trimmed );
		} elseif ( preg_match( '#^([^:\n]{2,40}):\s*(https?://\S+)$#u', $trimmed, $m ) ) {
			$url   = $m[2];
			$label = trim( $m[1] );
		}

		if ( '' !== $url ) {
			$out[] = sprintf(
				'<p style="margin:20px 0;"><a class="lw16-mail-button" href="%1$s" style="display:inline-block;padding:14px 26px;border-radius:999px;background-color:%2$s;color:%3$s;font-weight:700;text-decoration:none;">%4$s</a></p>',
				esc_url( $url ),
				esc_attr( $c['primary'] ),
				esc_attr( $c['on-primary'] ),
				esc_html( $label )
			);
			continue;
		}

		$out[] = make_clickable( esc_html( $line ) );
	}

	$flush();

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

	return lindenweg16_email_brand( $email->style_inline( $html ) );
}

/**
 * Was für eine Mail ist das?
 *
 * - skip: schon im Lindenweg16-/WooCommerce-Layout, oder multipart (z. B.
 *   Newsletter mit Text- und HTML-Teil) – bleibt, wie sie ist
 * - html: HTML eines anderen Plugins – Inhalt wird neu verpackt
 * - text: Klartext (auch wenn ausdrücklich als text/plain markiert)
 *
 * @param string|string[] $headers Header der Mail.
 * @param string          $message Inhalt.
 */
function lindenweg16_email_kind( $headers, string $message ): string {
	$header_text = is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;

	if ( preg_match( '/content-type:\s*multipart\//i', $header_text ) ) {
		return 'skip';
	}

	// WooCommerce-Mails (auch Germanized, Shiptastic, ERP) tragen dieses Gerüst.
	if ( false !== strpos( $message, 'template_container' ) || false !== strpos( $message, 'lw16-mail' ) ) {
		return 'skip';
	}

	$is_html = preg_match( '/content-type:\s*text\/html/i', $header_text )
		|| 'text/html' === apply_filters( 'wp_mail_content_type', 'text/plain' )
		|| preg_match( '/<(html|body|table|p|div|br|h[1-6]|ul|strong|a)[\s>\/]/i', $message );

	return $is_html ? 'html' : 'text';
}

/**
 * Nutzbaren Inhalt aus der HTML-Mail eines anderen Plugins holen: nur den
 * <body>, ohne Kopf, Stile, Skripte und ohne äußere Layout-Tabellen mit
 * fester Breite und Hintergrund.
 *
 * @param string $html HTML der Mail.
 */
function lindenweg16_email_foreign_content( string $html ): string {
	if ( preg_match( '#<body[^>]*>(.*)</body>#is', $html, $m ) ) {
		$html = $m[1];
	}

	$html = preg_replace( '#<(head|style|script|title|meta)[^>]*>.*?</\1>#is', '', $html );
	$html = preg_replace( '#<(meta|link)[^>]*>#i', '', (string) $html );
	// Fremde Hintergründe, Breiten und Schriften raus – das Lindenweg16-Layout bestimmt.
	$html = preg_replace( '#\s(bgcolor|background|width|align)="[^"]*"#i', '', (string) $html );
	$html = preg_replace_callback(
		'#\sstyle="([^"]*)"#i',
		static function ( array $m ): string {
			$keep = array();
			foreach ( explode( ';', $m[1] ) as $rule ) {
				$prop = strtolower( trim( (string) strtok( $rule, ':' ) ) );
				if ( in_array( $prop, array( 'font-weight', 'font-style', 'text-decoration', 'text-align', 'vertical-align', 'white-space' ), true ) ) {
					$keep[] = trim( $rule );
				}
			}
			return $keep ? ' style="' . esc_attr( implode( ';', $keep ) ) . '"' : '';
		},
		(string) $html
	);

	return trim( (string) $html );
}

/**
 * Alle Mails, die über wp_mail() gehen, im Lindenweg16-Design verschicken:
 * Klartext (WordPress, ERP, andere Plugins) wird gestaltet, HTML anderer
 * Plugins bekommt den Lindenweg16-Rahmen. Mails im WooCommerce-Layout
 * bleiben unverändert.
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

	if ( '' === trim( $message ) ) {
		return $args;
	}

	$kind = lindenweg16_email_kind( $headers, $message );

	/**
	 * Filtert, ob eine Mail ins E-Mail-Design verpackt wird.
	 *
	 * @param bool                 $wrap Verpacken?
	 * @param array<string, mixed> $args Argumente von wp_mail().
	 * @param string               $kind text, html oder skip.
	 */
	if ( 'skip' === $kind || ! apply_filters( 'lindenweg16_email_wrap_plain', true, $args, $kind ) ) {
		return $args;
	}

	$body = 'html' === $kind ? lindenweg16_email_foreign_content( $message ) : lindenweg16_email_text_to_html( $message );

	if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
		return $args;
	}

	// „[Lindenweg16] Passwort zurücksetzen“ → Überschrift „Passwort zurücksetzen“ (der Betreff bleibt).
	$heading = (string) preg_replace( '/^\s*\[[^\]]+\]\s*/u', '', (string) ( $args['subject'] ?? '' ) );

	$args['message'] = lindenweg16_email_wrap( $heading, $body );

	// Vorhandene Content-Type-Angaben ersetzen.
	if ( is_array( $headers ) ) {
		$headers = array_values( array_filter( $headers, static fn( $h ) => ! preg_match( '/^\s*content-type:/i', (string) $h ) ) );
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
	} else {
		$headers = preg_replace( '/^\s*content-type:.*$/im', '', (string) $headers );
		$headers = trim( (string) $headers . "\nContent-Type: text/html; charset=UTF-8" );
	}
	$args['headers'] = $headers;

	return $args;
}
add_filter( 'wp_mail', 'lindenweg16_email_wrap_plain', 20 );

/**
 * WPForms: Benachrichtigungen als Klartext erzeugen lassen – das Theme
 * verpackt sie dann im Lindenweg16-Design (statt WPForms' eigenem Layout).
 *
 * @param mixed  $value Einstellung.
 * @param string $key   Name der Einstellung.
 * @return mixed
 */
function lindenweg16_email_wpforms_template( $value, $key = '' ) {
	if ( 'email-template' === $key && lindenweg16_email_design_enabled() ) {
		return 'none';
	}

	return $value;
}
add_filter( 'wpforms_setting', 'lindenweg16_email_wpforms_template', 10, 2 );

/**
 * Artikel als Karte wie in der Bestellübersicht: Bild, Name (verlinkt), Menge
 * und Preis. Tabellen statt Flexbox, damit es auch in Outlook hält.
 *
 * @param array<int, array<string, mixed>> $items Artikel (name, qty, url, image, price).
 */
function lindenweg16_email_items_card( array $items ): string {
	$c     = lindenweg16_email_colors();
	$mono  = "'DM Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace";
	$line  = lindenweg16_email_mix( $c['line'], $c['surface-muted'], 70 );
	$rows  = '';
	$count = count( $items );

	foreach ( array_values( $items ) as $i => $item ) {
		$name  = (string) ( $item['name'] ?? '' );
		$url   = (string) ( $item['url'] ?? '' );
		$image = (string) ( $item['image'] ?? '' );
		$qty   = max( 1, (int) ( $item['qty'] ?? 1 ) );
		$price = isset( $item['price'] ) && is_numeric( $item['price'] ) ? html_entity_decode( wp_strip_all_tags( wc_price( (float) $item['price'] ) ), ENT_QUOTES, 'UTF-8' ) : '';

		if ( '' === $name ) {
			continue;
		}

		$border = $i < $count - 1 ? 'border-bottom:1px solid ' . esc_attr( $line ) . ';' : '';
		$title  = '' !== $url
			? sprintf( '<a href="%1$s" style="color:%2$s;text-decoration:none;font-weight:700;">%3$s</a>', esc_url( $url ), esc_attr( $c['text'] ), esc_html( $name ) )
			: sprintf( '<strong style="color:%1$s;">%2$s</strong>', esc_attr( $c['text'] ), esc_html( $name ) );
		$thumb  = '' !== $image
			? sprintf( '<img src="%1$s" alt="" width="56" height="56" style="display:block;width:56px;height:56px;border-radius:12px;object-fit:cover;border:0;">', esc_url( $image ) )
			: sprintf( '<div style="width:56px;height:56px;border-radius:12px;background-color:%s;"></div>', esc_attr( $c['accent-3'] ) );

		$rows .= sprintf(
			'<tr>'
			. '<td width="56" style="padding:14px 0 14px 16px;%1$s" valign="middle">%2$s</td>'
			. '<td style="padding:14px 12px;font-size:15px;line-height:1.35;%1$s" valign="middle">%3$s<div style="margin-top:4px;font-family:%4$s;font-size:13px;color:%5$s;">× %6$d</div></td>'
			. '<td align="right" style="padding:14px 16px 14px 0;font-size:15px;color:%7$s;white-space:nowrap;%1$s" valign="middle"><span style="font-family:%4$s;">%8$s</span></td>'
			. '</tr>',
			$border,
			$thumb,
			$title,
			$mono,
			esc_attr( $c['text-muted'] ),
			$qty,
			esc_attr( $c['text'] ),
			esc_html( $price )
		);
	}

	if ( '' === $rows ) {
		return '';
	}

	return sprintf(
		'<table class="lw16-mail-items" role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" style="width:100%%;margin:8px 0 20px;border-collapse:separate;border-radius:20px;background-color:%1$s;">%2$s</table>',
		esc_attr( $c['surface-muted'] ),
		$rows
	);
}

/**
 * Signatur unter einer persönlichen Mail: durch eine feine Linie abgesetzt,
 * leiser als der Text.
 *
 * @param string $signature Signatur als Klartext.
 */
function lindenweg16_email_signature_html( string $signature ): string {
	$signature = trim( $signature );

	if ( '' === $signature ) {
		return '';
	}

	$c = lindenweg16_email_colors();

	return sprintf(
		'<p class="lw16-mail-signature" style="margin:28px 0 0;padding-top:16px;border-top:1px solid %1$s;font-size:14px;line-height:1.55;color:%2$s;">%3$s</p>',
		esc_attr( $c['line'] ),
		esc_attr( $c['text-muted'] ),
		nl2br( make_clickable( esc_html( $signature ) ) )
	);
}

/**
 * Layout für die Mails des Lindenweg16-ERP-Plugins (MailTemplate).
 *
 * Bringt das Plugin die Artikel einer Warenkorb-Erinnerung mit
 * ($context['items']), ersetzt eine Karte die Klartext-Liste.
 *
 * @param string|null          $html    Bisheriges Ergebnis (null = Plugin-Layout).
 * @param string               $text    Klartext der Mail.
 * @param string               $subject Betreff.
 * @param array<string, mixed> $context Zusatzdaten des Plugins.
 */
function lindenweg16_email_erp_html( $html, string $text, string $subject = '', $context = array() ) {
	if ( ! lindenweg16_email_design_enabled() || ! function_exists( 'WC' ) ) {
		return $html;
	}

	// Persönliche Mail aus der Mailbox: Text, darunter Signatur, darunter das Zitat.
	if ( is_array( $context ) && ! empty( $context['personal'] ) ) {
		$body = lindenweg16_email_text_to_html( $text )
			. lindenweg16_email_signature_html( (string) ( $context['signature'] ?? '' ) )
			. ( ! empty( $context['quote'] ) ? '<div class="lw16-mail-quote-wrap" style="margin-top:28px;">' . lindenweg16_email_text_to_html( (string) $context['quote'] ) . '</div>' : '' );

		return lindenweg16_email_wrap( $subject, $body );
	}

	$items = is_array( $context ) ? ( $context['items'] ?? null ) : null;
	$list  = is_array( $items ) ? trim( (string) ( $items['text'] ?? '' ) ) : '';
	$card  = '' !== $list && ! empty( $items['list'] ) && is_array( $items['list'] ) ? lindenweg16_email_items_card( $items['list'] ) : '';

	if ( '' !== $card && false !== strpos( $text, $list ) ) {
		list( $before, $after ) = explode( $list, $text, 2 );

		$body = lindenweg16_email_text_to_html( $before ) . $card . lindenweg16_email_text_to_html( $after );
	} else {
		$body = lindenweg16_email_text_to_html( $text );
	}

	return lindenweg16_email_wrap( $subject, $body );
}
add_filter( 'lindenweg16_mail_html', 'lindenweg16_email_erp_html', 10, 4 );
