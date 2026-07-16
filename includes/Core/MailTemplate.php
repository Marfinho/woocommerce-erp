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
	 * @param string $text Klartext-Inhalt.
	 * @return string Vollständiges HTML-Dokument.
	 */
	public static function wrap_html( $text ) {
		$firma     = esc_html( (string) Settings::get( 'firma_name' ) );
		$farbe     = self::sanitize_color( Settings::get( 'ci_farbe', '#1d2327' ) );
		$footer    = esc_html( (string) Settings::get( 'email_footer', '' ) );
		$strasse   = esc_html( (string) Settings::get( 'firma_strasse', '' ) );
		$plz_ort   = esc_html( (string) Settings::get( 'firma_plz_ort', '' ) );

		$logo_html = '';
		$logo_id   = (int) Settings::get( 'ci_logo_id' );
		if ( $logo_id ) {
			$logo_url = wp_get_attachment_url( $logo_id );
			if ( $logo_url ) {
				$logo_html = '<img src="' . esc_url( $logo_url ) . '" alt="' . $firma . '" style="max-width:180px;max-height:60px;margin-bottom:8px;display:block;margin-left:auto;margin-right:auto">';
			}
		}

		$body_html = nl2br( esc_html( $text ) );

		$footer_parts = array_filter( array( $firma, $strasse, $plz_ort ) );
		$footer_line  = implode( ' &middot; ', $footer_parts );

		$logo_margin = $logo_html ? '8' : '0';

		$html  = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
		$html .= '<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif">';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation"><tr><td align="center" style="padding:24px 8px">';
		$html .= '<table width="600" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%">';

		// Header.
		$html .= '<tr><td style="background:' . esc_attr( $farbe ) . ';padding:24px;text-align:center">';
		if ( $logo_html ) {
			$html .= '<div>' . $logo_html . '</div>';
		}
		$html .= '<div style="color:#ffffff;font-size:18px;font-weight:700;margin-top:' . $logo_margin . 'px">' . $firma . '</div>';
		$html .= '</td></tr>';

		// Body.
		$html .= '<tr><td style="padding:32px 24px;font-size:15px;line-height:1.6;color:#333333">';
		$html .= $body_html;
		$html .= '</td></tr>';

		// Footer.
		$html .= '<tr><td style="padding:16px 24px;border-top:1px solid #eeeeee;font-size:12px;color:#999999;text-align:center;line-height:1.5">';
		if ( $footer ) {
			$html .= '<div style="margin-bottom:8px">' . nl2br( esc_html( $footer ) ) . '</div>';
		}
		$html .= '<div>' . $footer_line . '</div>';
		$html .= '</td></tr>';

		$html .= '</table></td></tr></table></body></html>';

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
		return '#1d2327';
	}
}
