<?php
/**
 * Zentrales E-Mail-Template-System mit CI-Header/Footer und Platzhaltern.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

class MailTemplate {

	/**
	 * Alle verfügbaren E-Mail-Vorlagen mit Defaults und Platzhalter-Doku.
	 *
	 * @return array
	 */
	public static function templates() {
		return array(
			'cart_erinnerung_1'  => array(
				'label'       => __( '1. Warenkorb-Erinnerung', 'lindenweg16' ),
				'betreff'     => __( 'Du hast etwas in Deinem Warenkorb vergessen', 'lindenweg16' ),
				'text'        => __( "Hallo,\n\nin Deinem Warenkorb liegen noch:\n\n{artikelliste}\nJetzt bestellen: {shop_url}\n\nViele Grüße", 'lindenweg16' ),
				'platzhalter' => '{artikelliste}, {shop_url}',
			),
			'cart_erinnerung_2'  => array(
				'label'       => __( '2. Warenkorb-Erinnerung', 'lindenweg16' ),
				'betreff'     => __( 'Dein Warenkorb wartet noch auf Dich', 'lindenweg16' ),
				'text'        => __( "Hallo,\n\nin Deinem Warenkorb liegen noch:\n\n{artikelliste}\nJetzt bestellen: {shop_url}\n\nViele Grüße", 'lindenweg16' ),
				'platzhalter' => '{artikelliste}, {shop_url}',
			),
			'danke'              => array(
				'label'       => __( 'Dankeschön nach Bestellung', 'lindenweg16' ),
				'betreff'     => __( 'Danke für Deinen Einkauf bei {firmenname}', 'lindenweg16' ),
				'text'        => __( "Hallo {vorname},\n\nvielen Dank für Deine Bestellung! Wir hoffen, Du hast Freude an Deinen Produkten.\n\nHerzliche Grüße", 'lindenweg16' ),
				'platzhalter' => '{vorname}',
			),
			'review_anfrage'     => array(
				'label'       => __( 'Bewertungsanfrage', 'lindenweg16' ),
				'betreff'     => __( 'Wie zufrieden bist Du? Jetzt bewerten und Punkte sichern', 'lindenweg16' ),
				'text'        => __( "Hallo {vorname},\n\nDu hast Deine Bestellung sicher schon ausprobiert. Über eine ehrliche Bewertung würden wir uns sehr freuen – als Dankeschön schreiben wir Dir {bonus_punkte} Punkte gut, sobald Deine Bewertung online ist.\n\n{produktlinks}\n\nDanke und viele Grüße", 'lindenweg16' ),
				'platzhalter' => '{vorname}, {bonus_punkte}, {produktlinks}',
			),
			'wieder_verfuegbar'  => array(
				'label'       => __( 'Wieder verfügbar (Warteliste)', 'lindenweg16' ),
				'betreff'     => __( '{produktname} ist wieder verfügbar', 'lindenweg16' ),
				'text'        => __( "Gute Nachricht! \"{produktname}\" ist wieder auf Lager.\n\nHier ansehen: {produktlink}", 'lindenweg16' ),
				'platzhalter' => '{produktname}, {produktlink}',
			),
			'mindestbestand'     => array(
				'label'       => __( 'Mindestbestand unterschritten', 'lindenweg16' ),
				'betreff'     => __( '[{firmenname}] Mindestbestand unterschritten: {produktname}', 'lindenweg16' ),
				'text'        => __( "Das Produkt \"{produktname}\" hat den Mindestbestand unterschritten.\n\nAktueller Bestand: {bestand} {einheit}\nMindestbestand: {mindestbestand} {einheit}", 'lindenweg16' ),
				'platzhalter' => '{produktname}, {bestand}, {mindestbestand}, {einheit}',
			),
			'bestandsreport'     => array(
				'label'       => __( 'Täglicher Bestandsreport', 'lindenweg16' ),
				'betreff'     => __( '[{firmenname}] Täglicher Bestandsreport', 'lindenweg16' ),
				'text'        => __( "Täglicher Bestandsreport – folgende Produkte liegen unter dem Mindestbestand:\n\n{warnungen}\n\nBitte zeitnah nachbestellen.", 'lindenweg16' ),
				'platzhalter' => '{warnungen}',
			),
			'neues_ticket'       => array(
				'label'       => __( 'Neues Support-Ticket (an Manager)', 'lindenweg16' ),
				'betreff'     => __( '[{firmenname}] Neues Support-Ticket', 'lindenweg16' ),
				'text'        => __( "Neues Ticket #{ticket_id}: {betreff}\n\nIm Backend unter Support bearbeiten.", 'lindenweg16' ),
				'platzhalter' => '{ticket_id}, {betreff}',
			),
			'ticket_update'      => array(
				'label'       => __( 'Ticket-Update an Kunden', 'lindenweg16' ),
				'betreff'     => __( '[{firmenname}] Update zu Deiner Anfrage', 'lindenweg16' ),
				'text'        => __( "Hallo,\n\nzu Deiner Support-Anfrage gibt es ein Update:\n\n{nachricht}\n\nViele Grüße", 'lindenweg16' ),
				'platzhalter' => '{nachricht}',
			),
			'wochen_digest'      => array(
				'label'       => __( 'Wochen-Digest (an Manager)', 'lindenweg16' ),
				'betreff'     => __( '[{firmenname}] Wochen-Digest', 'lindenweg16' ),
				'text'        => __( "Wochen-Digest {firmenname}\n\nNeue Bestellungen (7 Tage): {bestellungen} (Umsatz {umsatz} €)\nNeue Referrals: {referrals}\nNeue Produktbewertungen: {bewertungen}\n\nLagerwarnungen:\n{lagerwarnungen}", 'lindenweg16' ),
				'platzhalter' => '{bestellungen}, {umsatz}, {referrals}, {bewertungen}, {lagerwarnungen}',
			),
			'datenexport'        => array(
				'label'       => __( 'Wöchentlicher Datenexport', 'lindenweg16' ),
				'betreff'     => __( '[{firmenname}] Wöchentlicher Datenexport', 'lindenweg16' ),
				'text'        => __( "Automatischer Wochen-Export der Buchhaltungs- und Lagerdaten (CSV) im Anhang.\n\nDies ist eine zusätzliche Absicherung unabhängig vom Server-Backup.", 'lindenweg16' ),
				'platzhalter' => '',
			),
			'referral_review'    => array(
				'label'       => __( 'Referral zur Prüfung (an Manager)', 'lindenweg16' ),
				'betreff'     => __( '[{firmenname}] Referral zur Prüfung', 'lindenweg16' ),
				'text'        => __( "Ein Referral (#{referral_id}, Reward {betrag} €) wurde zur Prüfung markiert.\n\nGrund: {grund}\n\nBitte im Backend unter Loyalty freigeben oder ablehnen.", 'lindenweg16' ),
				'platzhalter' => '{referral_id}, {betrag}, {grund}',
			),
		);
	}

	/**
	 * E-Mail über das Template-System versenden.
	 *
	 * @param string|array $to           Empfänger.
	 * @param string       $template_key Schlüssel aus templates().
	 * @param array        $placeholders Assoziativ {platzhalter} => Wert.
	 * @param array        $attachments  Dateianhänge.
	 * @return bool
	 */
	public static function send( $to, $template_key, array $placeholders = array(), $attachments = array() ) {
		$templates = self::templates();
		if ( ! isset( $templates[ $template_key ] ) ) {
			return false;
		}
		$tpl = $templates[ $template_key ];

		$placeholders['{firmenname}'] = Settings::get( 'firma_name' );

		$subject = Settings::get( 'mail_' . $template_key . '_betreff', '' );
		if ( '' === $subject ) {
			$subject = $tpl['betreff'];
		}
		$body = Settings::get( 'mail_' . $template_key . '_text', '' );
		if ( '' === $body ) {
			$body = $tpl['text'];
		}

		$subject = strtr( $subject, $placeholders );
		$body    = strtr( $body, $placeholders );

		$html = self::wrap_html( $body );

		$absender   = Settings::get( 'email_absender_name', get_bloginfo( 'name' ) );
		$from_email = Settings::get( 'firma_email', get_bloginfo( 'admin_email' ) );
		$headers    = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $absender . ' <' . $from_email . '>',
		);

		return wp_mail( $to, $subject, $html, $headers, $attachments );
	}

	/**
	 * Plaintext-Body in HTML mit CI-Header und Footer einbetten.
	 *
	 * Design: Lindenweg16 CI — zentriertes Logo, Cognac-Akzente,
	 * heller Hintergrund, Footer mit Adresse.
	 *
	 * @param string $text Klartext-Inhalt.
	 * @return string Vollständiges HTML-Dokument.
	 */
	public static function wrap_html( $text ) {
		$firma     = esc_html( (string) Settings::get( 'firma_name' ) );
		$farbe     = self::sanitize_color( Settings::get( 'ci_farbe', '#8B6914' ) );
		$footer_txt = esc_html( (string) Settings::get( 'email_footer', '' ) );
		$strasse   = esc_html( (string) Settings::get( 'firma_strasse', '' ) );
		$plz_ort   = esc_html( (string) Settings::get( 'firma_plz_ort', '' ) );

		$logo_html = '';
		$logo_id   = (int) Settings::get( 'ci_logo_id' );
		if ( $logo_id ) {
			$logo_url = wp_get_attachment_url( $logo_id );
			if ( $logo_url ) {
				$logo_html = '<img src="' . esc_url( $logo_url ) . '" alt="' . $firma . '" style="height:70px;max-width:240px;object-fit:contain;">';
			}
		}

		$body_html = nl2br( esc_html( $text ) );

		$footer_parts = array_filter( array( $firma, $strasse, $plz_ort ) );
		$footer_line  = implode( ' &middot; ', $footer_parts );

		$bg_color     = '#EFEBE3';
		$text_color   = '#3A434D';
		$text_light   = 'rgba(58,67,77,.72)';
		$border_light = 'rgba(58,67,77,.08)';
		$footer_color = 'rgba(58,67,77,.5)';

		$html  = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
		$html .= '<body style="margin:0;padding:0;background:' . $bg_color . ';font-family:\'Inter\',-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:' . $text_color . '">';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="background:' . $bg_color . '"><tr><td align="center" style="padding:32px 16px">';

		$html .= '<table width="556" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;max-width:556px;width:100%">';

		// Logo-Header.
		$html .= '<tr><td style="padding:32px 0;text-align:center;border-bottom:1px solid ' . $border_light . '">';
		if ( $logo_html ) {
			$html .= $logo_html;
		} else {
			$html .= '<div style="font-family:\'Poppins\',Helvetica,Arial,sans-serif;font-weight:600;font-size:20px;color:' . $text_color . '">' . $firma . '</div>';
		}
		$html .= '</td></tr>';

		// Body.
		$html .= '<tr><td style="padding:36px 40px;font-size:14px;line-height:1.6;color:' . $text_light . '">';
		$html .= $body_html;
		$html .= '</td></tr>';

		// Custom Footer Text.
		if ( $footer_txt ) {
			$html .= '<tr><td style="padding:0 40px 24px;font-size:13px;line-height:1.6;color:' . $text_light . '">';
			$html .= nl2br( esc_html( $footer_txt ) );
			$html .= '</td></tr>';
		}

		// Footer.
		$html .= '<tr><td style="padding:24px 40px 36px;text-align:center;font-size:12px;color:' . $footer_color . ';line-height:1.7;border-top:1px solid ' . $border_light . '">';
		$html .= '<div>' . $footer_line . '</div>';
		$html .= '<div style="margin-top:4px">' . esc_html__( 'Diese E-Mail wurde automatisch versendet.', 'lindenweg16' ) . '</div>';
		$html .= '</td></tr>';

		$html .= '</table>';
		$html .= '</td></tr></table></body></html>';

		return $html;
	}

	/**
	 * Hex-Farbwert validieren.
	 *
	 * @param string $color Farbwert.
	 * @return string Sauberer Hex-Wert oder Fallback.
	 */
	public static function sanitize_color( $color ) {
		$color = trim( (string) $color );
		if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
			return $color;
		}
		if ( preg_match( '/^#[0-9a-fA-F]{3}$/', $color ) ) {
			return $color;
		}
		return '#8B6914';
	}
}
