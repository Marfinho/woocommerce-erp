<?php
/**
 * Öffentliche Eventseite: Eckdaten, Zusatzfelder und Anmeldung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Events;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\MailTemplate;

/**
 * Hängt die Eventangaben an den Inhalt und nimmt Anmeldungen entgegen.
 */
class EventFrontend {

	/**
	 * Nonce-Aktion des Anmeldeformulars.
	 */
	const NONCE = 'lw16_event_rsvp';

	/**
	 * Höchstzahl an Anmeldeversuchen je IP und Stunde.
	 */
	const LIMIT_PRO_STUNDE = 10;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'handle_request' ) );
		// Nach wpautop: Der Beitragstext soll dessen Absatzformatierung
		// bekommen, unser eigenes Markup aber nicht durch sie hindurch.
		add_filter( 'the_content', array( $this, 'append_event' ), 20 );
	}

	/* --------------------------------------------------------------------
	 * Eingehende Aktionen
	 * ------------------------------------------------------------------ */

	/**
	 * Anmeldung, Bestätigung und Absage abarbeiten.
	 */
	public function handle_request() {
		if ( ! is_singular( EventPostType::POST_TYPE ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['lw16_rsvp_token'] ) ? sanitize_text_field( wp_unslash( $_GET['lw16_rsvp_token'] ) ) : '';
		if ( $token ) {
			$this->handle_token( $token );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['lw16_rsvp_submit'] ) ) {
			$this->handle_submit();
		}
	}

	/**
	 * Bestätigungs- oder Abmeldelink verarbeiten.
	 *
	 * @param string $token Klartext-Token.
	 */
	protected function handle_token( $token ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$aktion = isset( $_GET['lw16_rsvp'] ) ? sanitize_key( wp_unslash( $_GET['lw16_rsvp'] ) ) : 'bestaetigen';
		$rsvp   = RsvpRepository::find_by_token( $token );
		$post_id = get_queried_object_id();

		if ( ! $rsvp || (int) $rsvp->event_id !== (int) $post_id ) {
			$this->redirect_mit_meldung( $post_id, 'ungueltig' );
		}

		$daten = EventPostType::daten( $post_id );

		if ( 'absagen' === $aktion ) {
			RsvpRepository::absagen( $rsvp );

			// Der frei gewordene Platz geht sofort an die Warteliste.
			foreach ( RsvpRepository::nachruecken( $post_id, $daten['max_teilnehmer'] ) as $nach ) {
				$this->mail_nachgerueckt( $post_id, $nach );
			}

			$this->redirect_mit_meldung( $post_id, 'abgesagt' );
		}

		// Abgelaufener Bestätigungslink.
		if ( RsvpRechnung::OFFEN === $rsvp->status && $rsvp->token_ablauf && $rsvp->token_ablauf < gmdate( 'Y-m-d H:i:s' ) ) {
			$this->redirect_mit_meldung( $post_id, 'abgelaufen' );
		}

		$status = RsvpRepository::bestaetigen( $rsvp, $daten['max_teilnehmer'] );
		$this->mail_status( $post_id, $rsvp, $status );

		$this->redirect_mit_meldung( $post_id, RsvpRechnung::WARTELISTE === $status ? 'warteliste' : 'zugesagt' );
	}

	/**
	 * Abgesendetes Anmeldeformular verarbeiten.
	 */
	protected function handle_submit() {
		$post_id = get_queried_object_id();

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			$this->redirect_mit_meldung( $post_id, 'fehler' );
		}

		// Honigtopf: Ein Feld, das kein Mensch ausfüllt, weil er es nicht
		// sieht. Ist es befüllt, war es ein Skript – wir tun freundlich und
		// tun nichts.
		if ( ! empty( $_POST['lw16_website'] ) ) {
			$this->redirect_mit_meldung( $post_id, 'gesendet' );
		}

		if ( ! $this->im_limit() ) {
			$this->redirect_mit_meldung( $post_id, 'zuviel' );
		}

		$daten = EventPostType::daten( $post_id );
		$status = RsvpRechnung::formular_status(
			$daten['rsvp_aktiv'],
			$this->ist_vorbei( $daten ),
			$daten['max_teilnehmer'],
			RsvpRepository::plaetze( $post_id, RsvpRechnung::ZUGESAGT )
		);

		if ( 'aus' === $status || 'vorbei' === $status ) {
			$this->redirect_mit_meldung( $post_id, 'geschlossen' );
		}

		$name  = sanitize_text_field( wp_unslash( $_POST['lw16_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['lw16_email'] ?? '' ) );
		$notiz = sanitize_textarea_field( wp_unslash( $_POST['lw16_notiz'] ?? '' ) );

		if ( '' === $name || ! is_email( $email ) ) {
			$this->redirect_mit_meldung( $post_id, 'unvollstaendig' );
		}

		if ( empty( $_POST['lw16_einwilligung'] ) ) {
			$this->redirect_mit_meldung( $post_id, 'einwilligung' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$roh_begleiter = isset( $_POST['lw16_begleiter'] ) && is_array( $_POST['lw16_begleiter'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['lw16_begleiter'] ) )
			: array();

		$begleiter = RsvpRechnung::begleiter_liste( $roh_begleiter );

		$ergebnis = RsvpRepository::anmelden( $post_id, $name, $email, $notiz, $begleiter );

		if ( $ergebnis['bereits_bestaetigt'] ) {
			$this->redirect_mit_meldung( $post_id, 'schon_angemeldet' );
		}

		$this->mail_bestaetigung( $post_id, $name, $email, $ergebnis['token'] );
		$this->redirect_mit_meldung( $post_id, 'gesendet' );
	}

	/* --------------------------------------------------------------------
	 * Ausgabe
	 * ------------------------------------------------------------------ */

	/**
	 * Eventangaben an den Inhalt hängen.
	 *
	 * @param string $content Beitragsinhalt.
	 * @return string
	 */
	public function append_event( $content ) {
		if ( ! is_singular( EventPostType::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();
		$daten   = EventPostType::daten( $post_id );

		$out  = $this->eckdaten_html( $daten );
		$out .= EventFields::render( $post_id );
		$out .= $this->meldung_html();
		$out .= $this->rsvp_html( $post_id, $daten );

		return $content . '<div class="lw16-event">' . $out . '</div>';
	}

	/**
	 * Termin, Ort und Treffpunkt.
	 *
	 * @param array $daten Eckdaten.
	 * @return string
	 */
	protected function eckdaten_html( array $daten ) {
		$zeilen = '';

		if ( $daten['beginn'] ) {
			$zeilen .= '<dt>' . esc_html__( 'Wann', 'lindenweg16' ) . '</dt><dd>'
				. esc_html( self::termin_text( $daten['beginn'], $daten['ende'] ) ) . '</dd>';
		}
		if ( $daten['ort'] ) {
			$zeilen .= '<dt>' . esc_html__( 'Wo', 'lindenweg16' ) . '</dt><dd>' . esc_html( $daten['ort'] ) . '</dd>';
		}
		if ( $daten['treffpunkt'] ) {
			$zeilen .= '<dt>' . esc_html__( 'Treffpunkt', 'lindenweg16' ) . '</dt><dd>' . esc_html( $daten['treffpunkt'] ) . '</dd>';
		}

		return $zeilen ? '<dl class="lw16-event-eckdaten">' . $zeilen . '</dl>' : '';
	}

	/**
	 * Termin als lesbarer Text.
	 *
	 * @param string $beginn Beginn (Y-m-d H:i).
	 * @param string $ende   Ende, optional.
	 * @return string
	 */
	public static function termin_text( $beginn, $ende = '' ) {
		$start = strtotime( $beginn );
		if ( ! $start ) {
			return (string) $beginn;
		}

		$format = get_option( 'date_format' ) . ', ' . get_option( 'time_format' );
		$text   = date_i18n( $format, $start );

		$stop = $ende ? strtotime( $ende ) : 0;
		if ( ! $stop ) {
			return $text;
		}

		// Am selben Tag reicht die Uhrzeit als Ende.
		$gleicher_tag = gmdate( 'Y-m-d', $start ) === gmdate( 'Y-m-d', $stop );
		return $text . ' – ' . date_i18n( $gleicher_tag ? get_option( 'time_format' ) : $format, $stop );
	}

	/**
	 * Anmeldebereich.
	 *
	 * @param int   $post_id Event.
	 * @param array $daten   Eckdaten.
	 * @return string
	 */
	protected function rsvp_html( $post_id, array $daten ) {
		$zugesagt = RsvpRepository::plaetze( $post_id, RsvpRechnung::ZUGESAGT );
		$status   = RsvpRechnung::formular_status(
			$daten['rsvp_aktiv'],
			$this->ist_vorbei( $daten ),
			$daten['max_teilnehmer'],
			$zugesagt
		);

		if ( 'aus' === $status ) {
			return '';
		}

		$out = '<div class="lw16-event-rsvp"><h3>' . esc_html__( 'Anmeldung', 'lindenweg16' ) . '</h3>';
		$out .= '<p class="lw16-event-belegung">' . esc_html( RsvpRechnung::belegung_text( $daten['max_teilnehmer'], $zugesagt ) ) . '</p>';

		if ( 'vorbei' === $status ) {
			return $out . '<p>' . esc_html__( 'Dieses Event liegt bereits hinter uns.', 'lindenweg16' ) . '</p></div>';
		}

		if ( 'warteliste' === $status ) {
			$out .= '<p>' . esc_html__( 'Alle Plätze sind vergeben. Du kannst Dich auf die Warteliste setzen lassen und rückst automatisch nach, wenn jemand absagt.', 'lindenweg16' ) . '</p>';
		}

		$knopf = 'warteliste' === $status
			? __( 'Auf die Warteliste', 'lindenweg16' )
			: __( 'Verbindlich anmelden', 'lindenweg16' );

		$user  = wp_get_current_user();
		$name  = $user->exists() ? $user->display_name : '';
		$email = $user->exists() ? $user->user_email : '';

		$out .= '<form method="post" class="lw16-rsvp-form">';
		$out .= wp_nonce_field( self::NONCE, '_wpnonce', true, false );
		$out .= '<input type="hidden" name="lw16_rsvp_submit" value="1">';

		$out .= '<p><label>' . esc_html__( 'Name', 'lindenweg16' )
			. '<input type="text" name="lw16_name" required value="' . esc_attr( $name ) . '"></label></p>';
		$out .= '<p><label>' . esc_html__( 'E-Mail', 'lindenweg16' )
			. '<input type="email" name="lw16_email" required value="' . esc_attr( $email ) . '"></label></p>';
		$out .= $this->begleiter_html();

		$out .= '<p><label>' . esc_html__( 'Anmerkung (optional)', 'lindenweg16' )
			. '<textarea name="lw16_notiz" rows="2"></textarea></label></p>';

		// Für Menschen unsichtbar, für einfache Skripte verlockend.
		$out .= '<p class="lw16-hp" aria-hidden="true" style="position:absolute;left:-9999px">'
			. '<label>' . esc_html__( 'Website', 'lindenweg16' )
			. '<input type="text" name="lw16_website" tabindex="-1" autocomplete="off"></label></p>';

		$out .= '<p><label><input type="checkbox" name="lw16_einwilligung" value="1" required> '
			. esc_html__( 'Meine Angaben und die Namen der genannten Begleitpersonen dürfen zur Organisation dieses Events gespeichert werden. Ich habe die genannten Personen darüber informiert.', 'lindenweg16' )
			. '</label></p>';

		$out .= '<p><button type="submit">' . esc_html( $knopf ) . '</button></p>';
		$out .= '<p class="lw16-event-hinweis">' . esc_html__( 'Du bekommst eine E-Mail mit einem Bestätigungslink. Erst danach ist die Anmeldung gültig – über denselben Link kannst Du später auch wieder absagen.', 'lindenweg16' ) . '</p>';
		$out .= '</form></div>';

		return $out;
	}

	/**
	 * Eingabe der Begleitpersonen: je Klick eine weitere Namenszeile.
	 *
	 * Ohne JavaScript bleibt eine Zeile stehen und lässt sich ausfüllen –
	 * dann kommt eben eine Begleitperson mit statt fünf. Ein Formular, das
	 * ohne Skript gar nichts kann, wäre hier der schlechtere Tausch.
	 *
	 * @return string
	 */
	protected function begleiter_html() {
		$out  = '<fieldset class="lw16-begleiter">';
		$out .= '<legend>' . esc_html__( 'Begleitpersonen', 'lindenweg16' ) . '</legend>';
		$out .= '<p class="lw16-event-hinweis">' . esc_html(
			sprintf(
				/* translators: %d: Höchstzahl an Begleitpersonen. */
				__( 'Jede Begleitperson braucht einen Namen – sie belegt einen eigenen Platz. Höchstens %d.', 'lindenweg16' ),
				RsvpRechnung::MAX_BEGLEITER
			)
		) . '</p>';

		$out .= '<div class="lw16-begleiter-zeilen">';
		$out .= '<p class="lw16-begleiter-zeile"><label>' . esc_html__( 'Name der Begleitperson', 'lindenweg16' )
			. '<input type="text" name="lw16_begleiter[]" autocomplete="off"></label></p>';
		$out .= '</div>';

		$out .= '<p><button type="button" class="lw16-begleiter-plus">'
			. esc_html__( '+ weitere Person', 'lindenweg16' ) . '</button></p>';
		$out .= '</fieldset>';

		$out .= "<script>\n(function(){\n"
			. "var wrap=document.currentScript.previousElementSibling;\n"
			. "if(!wrap||!wrap.classList.contains('lw16-begleiter'))return;\n"
			. "var zeilen=wrap.querySelector('.lw16-begleiter-zeilen');\n"
			. "var plus=wrap.querySelector('.lw16-begleiter-plus');\n"
			. 'var max=' . (int) RsvpRechnung::MAX_BEGLEITER . ";\n"
			. "plus.addEventListener('click',function(){\n"
			. "if(zeilen.children.length>=max)return;\n"
			. "var neu=zeilen.firstElementChild.cloneNode(true);\n"
			. "var feld=neu.querySelector('input');\n"
			. "feld.value='';\n"
			. "zeilen.appendChild(neu);\n"
			. "feld.focus();\n"
			. "if(zeilen.children.length>=max)plus.disabled=true;\n"
			. "});\n"
			. "})();\n</script>";

		return $out;
	}

	/**
	 * Rückmeldung nach einer Aktion.
	 *
	 * @return string
	 */
	protected function meldung_html() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( $_GET['lw16_rsvp_msg'] ) ? sanitize_key( wp_unslash( $_GET['lw16_rsvp_msg'] ) ) : '';
		if ( ! $key ) {
			return '';
		}

		$meldungen = array(
			'gesendet'         => __( 'Fast geschafft: Wir haben Dir eine E-Mail mit einem Bestätigungslink geschickt. Erst mit dem Klick darauf ist Deine Anmeldung gültig.', 'lindenweg16' ),
			'zugesagt'         => __( 'Deine Anmeldung ist bestätigt. Wir freuen uns auf Dich!', 'lindenweg16' ),
			'warteliste'       => __( 'Du stehst auf der Warteliste. Wird ein Platz frei, rückst Du automatisch nach und bekommst eine E-Mail.', 'lindenweg16' ),
			'abgesagt'         => __( 'Deine Anmeldung wurde zurückgezogen. Schade – vielleicht beim nächsten Mal.', 'lindenweg16' ),
			'schon_angemeldet' => __( 'Mit dieser Adresse bist Du bereits angemeldet.', 'lindenweg16' ),
			'ungueltig'        => __( 'Dieser Link ist nicht gültig.', 'lindenweg16' ),
			'abgelaufen'       => __( 'Dieser Bestätigungslink ist abgelaufen. Melde Dich einfach noch einmal an.', 'lindenweg16' ),
			'geschlossen'      => __( 'Für dieses Event ist die Anmeldung geschlossen.', 'lindenweg16' ),
			'unvollstaendig'   => __( 'Bitte Namen und eine gültige E-Mail-Adresse angeben.', 'lindenweg16' ),
			'einwilligung'     => __( 'Ohne Einwilligung zur Speicherung können wir die Anmeldung nicht annehmen.', 'lindenweg16' ),
			'zuviel'           => __( 'Das waren zu viele Versuche in kurzer Zeit. Bitte später noch einmal probieren.', 'lindenweg16' ),
			'fehler'           => __( 'Das hat nicht geklappt. Bitte lade die Seite neu und versuche es erneut.', 'lindenweg16' ),
		);

		if ( ! isset( $meldungen[ $key ] ) ) {
			return '';
		}

		$art = in_array( $key, array( 'zugesagt', 'warteliste', 'gesendet', 'abgesagt' ), true ) ? 'ok' : 'hinweis';

		return '<p class="lw16-event-meldung lw16-event-meldung--' . esc_attr( $art ) . '">'
			. esc_html( $meldungen[ $key ] ) . '</p>';
	}

	/* --------------------------------------------------------------------
	 * E-Mails
	 * ------------------------------------------------------------------ */

	/**
	 * Bestätigungslink verschicken.
	 *
	 * @param int    $post_id Event.
	 * @param string $name    Name.
	 * @param string $email   Adresse.
	 * @param string $token   Klartext-Token.
	 */
	protected function mail_bestaetigung( $post_id, $name, $email, $token ) {
		MailTemplate::send( $email, 'event_bestaetigung', array(
			'{name}'        => $name,
			'{personen}'    => self::personen_text( $post_id, $email ),
			'{event}'       => get_the_title( $post_id ),
			'{termin}'      => self::termin_text(
				(string) get_post_meta( $post_id, EventPostType::META['beginn'], true )
			),
			'{bestaetigen}' => self::token_url( $post_id, $token, 'bestaetigen' ),
		) );
	}

	/**
	 * Ergebnis der Bestätigung mitteilen.
	 *
	 * @param int    $post_id Event.
	 * @param object $rsvp    Anmeldung.
	 * @param string $status  Neuer Status.
	 */
	protected function mail_status( $post_id, $rsvp, $status ) {
		$key = RsvpRechnung::WARTELISTE === $status ? 'event_warteliste' : 'event_zugesagt';

		MailTemplate::send( $rsvp->email, $key, array(
			'{name}'      => $rsvp->name,
			'{personen}'  => self::begleiter_text( $rsvp ),
			'{event}'   => get_the_title( $post_id ),
			'{termin}'  => self::termin_text( (string) get_post_meta( $post_id, EventPostType::META['beginn'], true ) ),
			'{ort}'     => (string) get_post_meta( $post_id, EventPostType::META['ort'], true ),
			'{absagen}' => self::absage_hinweis( $post_id, $rsvp ),
		) );
	}

	/**
	 * Nachrücker benachrichtigen.
	 *
	 * @param int    $post_id Event.
	 * @param object $rsvp    Anmeldung.
	 */
	protected function mail_nachgerueckt( $post_id, $rsvp ) {
		MailTemplate::send( $rsvp->email, 'event_nachgerueckt', array(
			'{name}'      => $rsvp->name,
			'{personen}'  => self::begleiter_text( $rsvp ),
			'{event}'   => get_the_title( $post_id ),
			'{termin}'  => self::termin_text( (string) get_post_meta( $post_id, EventPostType::META['beginn'], true ) ),
			'{absagen}' => self::absage_hinweis( $post_id, $rsvp ),
		) );
	}

	/**
	 * Hinweis auf den Abmeldeweg.
	 *
	 * Das Token steht nach der Bestätigung nur noch gehasht in der
	 * Datenbank – einen fertigen Abmeldelink kann die Mail deshalb nicht
	 * mehr enthalten. Der Link aus der ersten Mail funktioniert weiter.
	 *
	 * @param int    $post_id Event.
	 * @param object $rsvp    Anmeldung.
	 * @return string
	 */
	protected static function absage_hinweis( $post_id, $rsvp ) {
		unset( $rsvp );
		return sprintf(
			/* translators: %s: Adresse der Eventseite. */
			__( 'Absagen kannst Du über den Link aus Deiner Bestätigungs-E-Mail oder mit einer kurzen Nachricht an uns. Zum Event: %s', 'lindenweg16' ),
			get_permalink( $post_id )
		);
	}

	/* --------------------------------------------------------------------
	 * Helfer
	 * ------------------------------------------------------------------ */

	/**
	 * Wer alles angemeldet ist, als Satz für die E-Mail.
	 *
	 * @param object $rsvp Anmeldung.
	 * @return string
	 */
	protected static function begleiter_text( $rsvp ) {
		$namen = RsvpRepository::begleiter( $rsvp );
		if ( ! $namen ) {
			return '';
		}

		return sprintf(
			/* translators: 1: Anzahl Personen insgesamt, 2: Namen der Begleitpersonen. */
			__( 'Angemeldet für %1$d Personen – mit dabei: %2$s.', 'lindenweg16' ),
			count( $namen ) + 1,
			implode( ', ', $namen )
		);
	}

	/**
	 * Dasselbe, aber für die frisch angelegte Anmeldung.
	 *
	 * @param int    $post_id Event.
	 * @param string $email   Adresse.
	 * @return string
	 */
	protected static function personen_text( $post_id, $email ) {
		$rsvp = RsvpRepository::find_by_email( $post_id, $email );
		return $rsvp ? self::begleiter_text( $rsvp ) : '';
	}

	/**
	 * Adresse eines Bestätigungs- oder Abmeldelinks.
	 *
	 * @param int    $post_id Event.
	 * @param string $token   Klartext-Token.
	 * @param string $aktion  bestaetigen|absagen.
	 * @return string
	 */
	public static function token_url( $post_id, $token, $aktion = 'bestaetigen' ) {
		return add_query_arg(
			array(
				'lw16_rsvp'       => $aktion,
				'lw16_rsvp_token' => rawurlencode( $token ),
			),
			get_permalink( $post_id )
		);
	}

	/**
	 * Liegt der Beginn in der Vergangenheit?
	 *
	 * @param array $daten Eckdaten.
	 * @return bool
	 */
	protected function ist_vorbei( array $daten ) {
		$bezug = $daten['ende'] ? $daten['ende'] : $daten['beginn'];
		if ( ! $bezug ) {
			return false;
		}
		return strtotime( $bezug ) < current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	}

	/**
	 * Einfache Bremse gegen automatisierte Anmeldungen.
	 *
	 * @return bool
	 */
	protected function im_limit() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return true;
		}

		$key    = 'lw16_rsvp_limit_' . md5( $ip );
		$anzahl = (int) get_transient( $key );

		if ( $anzahl >= self::LIMIT_PRO_STUNDE ) {
			return false;
		}

		set_transient( $key, $anzahl + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Zur Eventseite mit einer Rückmeldung umleiten.
	 *
	 * @param int    $post_id Event.
	 * @param string $key     Meldungsschlüssel.
	 */
	protected function redirect_mit_meldung( $post_id, $key ) {
		wp_safe_redirect( add_query_arg( 'lw16_rsvp_msg', $key, get_permalink( $post_id ) ) );
		exit;
	}
}
