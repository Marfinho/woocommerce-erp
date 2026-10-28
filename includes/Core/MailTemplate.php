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
			'geburtstag'         => array(
				'label'       => __( 'Geburtstag (Prämienpunkte)', 'lindenweg16' ),
				'betreff'     => __( 'Alles Gute zum Geburtstag, {vorname}!', 'lindenweg16' ),
				'text'        => __( "Hallo {vorname},\n\nalles Gute zum Geburtstag! Als kleines Geschenk haben wir Dir {punkte} Prämienpunkte gutgeschrieben – Du hast jetzt {punkte_gesamt} Punkte, einlösbar bei Deiner nächsten Bestellung.\n\nDein Konto: {konto_url}\n\nFeier schön!", 'lindenweg16' ),
				'platzhalter' => '{vorname}, {punkte}, {punkte_gesamt}, {konto_url}',
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
			'event_bestaetigung' => array(
				'label'       => __( 'Event: Anmeldung bestätigen', 'lindenweg16' ),
				'betreff'     => __( 'Bitte bestätige Deine Anmeldung zu {event}', 'lindenweg16' ),
				'text'        => __( "Hallo {name},\n\nschön, dass Du bei \"{event}\" ({termin}) dabei sein möchtest.\n{personen}\n\nEin Klick fehlt noch – erst danach ist Deine Anmeldung gültig:\n{bestaetigen}\n\nÜber denselben Link kannst Du später auch wieder absagen. Der Link gilt sieben Tage.\n\nBis bald!", 'lindenweg16' ),
				'platzhalter' => '{name}, {event}, {termin}, {personen}, {bestaetigen}',
			),
			'event_zugesagt'     => array(
				'label'       => __( 'Event: Platz bestätigt', 'lindenweg16' ),
				'betreff'     => __( 'Dein Platz bei {event} ist sicher', 'lindenweg16' ),
				'text'        => __( "Hallo {name},\n\nDeine Anmeldung zu \"{event}\" ist bestätigt.\n{personen}\n\nWann: {termin}\nWo: {ort}\n\n{absagen}\n\nWir freuen uns auf Dich!", 'lindenweg16' ),
				'platzhalter' => '{name}, {event}, {termin}, {ort}, {personen}, {absagen}',
			),
			'event_warteliste'   => array(
				'label'       => __( 'Event: auf der Warteliste', 'lindenweg16' ),
				'betreff'     => __( 'Du stehst auf der Warteliste für {event}', 'lindenweg16' ),
				'text'        => __( "Hallo {name},\n\nfür \"{event}\" ({termin}) sind gerade alle Plätze vergeben. Du stehst auf der Warteliste und rückst automatisch nach, sobald jemand absagt – Du bekommst dann sofort eine E-Mail.\n{personen}\n\n{absagen}\n\nViele Grüße", 'lindenweg16' ),
				'platzhalter' => '{name}, {event}, {termin}, {ort}, {personen}, {absagen}',
			),
			'event_nachgerueckt' => array(
				'label'       => __( 'Event: von der Warteliste nachgerückt', 'lindenweg16' ),
				'betreff'     => __( 'Ein Platz bei {event} ist frei geworden', 'lindenweg16' ),
				'text'        => __( "Hallo {name},\n\ngute Nachricht: Bei \"{event}\" ({termin}) ist ein Platz frei geworden und Du bist nachgerückt. Deine Anmeldung ist damit gültig.\n{personen}\n\n{absagen}\n\nBis bald!", 'lindenweg16' ),
				'platzhalter' => '{name}, {event}, {termin}, {personen}, {absagen}',
			),
			'gutschein_mail'    => array(
				'label'       => __( 'Gutschein (nur als E-Mail)', 'lindenweg16' ),
				'betreff'     => __( '{von} hat Dir einen Gutschein geschenkt', 'lindenweg16' ),
				'text'        => __( "Hallo {an},\n\n{von} schenkt Dir einen Gutschein über {betrag}.\n\n{text}\n\nGutscheincode: {code}\nGültig bis: {gueltig_bis}\n\nEinfach im Warenkorb unter \"Gutschein\" eingeben. Viel Freude damit!", 'lindenweg16' ),
				'platzhalter' => '{von}, {an}, {text}, {code}, {betrag}, {gueltig_bis}',
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
	 * @param array        $context      Zusatzdaten für das Theme-Layout, z. B.
	 *                                   'items' => Artikel der Warenkorb-Erinnerung
	 *                                   (siehe wrap_html()).
	 * @return bool
	 */
	public static function send( $to, $template_key, array $placeholders = array(), $attachments = array(), array $context = array() ) {
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

		$context['template'] = $template_key;
		$html                = self::wrap_html( $body, $subject, $context );

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
	 * Ein Theme kann das Layout über den Filter `lindenweg16_mail_html`
	 * übernehmen (das Lindenweg16-Block-Theme tut das, damit alle Mails wie die
	 * Website aussehen). Ohne Rückgabe bleibt dieses Layout.
	 *
	 * Persönliche Mails aus der Mailbox kommen mit $context['personal'] = true,
	 * dazu 'signature' (Klartext) und 'quote' (zitierte Ursprungsmail): Sie
	 * stehen unter dem Text, und der Hinweis „automatisch versendet" entfällt.
	 *
	 * Über $context bekommt das Theme strukturierte Daten, um sie schöner
	 * darzustellen als im Klartext – etwa die Artikel einer Warenkorb-Erinnerung:
	 * 'items' => array( 'text' => Klartext-Liste im Body, 'list' => array( array(
	 * 'name', 'qty', 'url', 'image', 'price' ), … ) ).
	 *
	 * @param string $text    Klartext-Inhalt.
	 * @param string $subject Betreff (für die Überschrift im Theme-Layout).
	 * @param array  $context Zusatzdaten (Vorlage, Artikel …).
	 * @return string Vollständiges HTML-Dokument.
	 */
	public static function wrap_html( $text, $subject = '', array $context = array() ) {
		/**
		 * Erlaubt einem Theme, das komplette HTML der Mail zu liefern.
		 *
		 * @param string|null $html    null = Layout des Plugins verwenden.
		 * @param string      $text    Klartext-Inhalt.
		 * @param string      $subject Betreff.
		 * @param array       $context Zusatzdaten (Vorlage, Artikel …).
		 */
		$custom = apply_filters( 'lindenweg16_mail_html', null, (string) $text, (string) $subject, $context );
		if ( is_string( $custom ) && '' !== $custom ) {
			return $custom;
		}

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
		$personal  = ! empty( $context['personal'] );

		// Persönliche Mails (Mailbox): Signatur und Zitat unter dem Text.
		if ( $personal ) {
			if ( ! empty( $context['signature'] ) ) {
				$body_html .= '<div style="margin-top:24px;padding-top:14px;border-top:1px solid rgba(58,67,77,.12);font-size:13px;color:rgba(58,67,77,.8)">' . nl2br( esc_html( (string) $context['signature'] ) ) . '</div>';
			}
			if ( ! empty( $context['quote'] ) ) {
				$body_html .= '<div style="margin-top:20px;padding-left:12px;border-left:3px solid rgba(58,67,77,.2);font-size:13px;color:rgba(58,67,77,.65)">' . nl2br( esc_html( (string) $context['quote'] ) ) . '</div>';
			}
		}

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
		if ( ! $personal ) {
			$html .= '<div style="margin-top:4px">' . esc_html__( 'Diese E-Mail wurde automatisch versendet.', 'lindenweg16' ) . '</div>';
		}
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
