<?php
/**
 * Zugang zur Versandschnittstelle der DHL.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Dhl;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Die einzige Stelle, die mit der DHL spricht.
 *
 * Getrennt vom Aufbau des Auftrags und von der Auswertung der Antwort:
 * Was hier steht, lässt sich nicht ohne Netz prüfen – also steht hier
 * auch sonst nichts.
 */
class DhlClient {

	const HOST_LIVE    = 'https://api-eu.dhl.com';
	const HOST_SANDBOX = 'https://api-sandbox.dhl.com';

	const PATH = '/parcel/de/shipping/v2';

	/**
	 * Sind alle Zugangsdaten da?
	 *
	 * @return bool
	 */
	public static function configured() {
		return '' === implode( '', self::missing_credentials() );
	}

	/**
	 * Welche Zugangsdaten fehlen? Klartext für die Einstellungsseite.
	 *
	 * Geprüft wird immer nur der gerade aktive Modus (Sandbox oder Live) –
	 * beide haben eigene Zugangsdaten, die DHL-Sandbox akzeptiert kein
	 * echtes Geschäftskundenportal-Login und umgekehrt.
	 *
	 * @return array
	 */
	public static function missing_credentials() {
		$fehlt  = array();
		$modus  = self::sandbox() ? __( 'Sandbox', 'lindenweg16' ) : __( 'Live', 'lindenweg16' );

		$pflicht = array(
			self::credential_key( 'api_key' )    => __( 'API-Key der Anwendung', 'lindenweg16' ),
			self::credential_key( 'api_secret' ) => __( 'API-Secret der Anwendung', 'lindenweg16' ),
			self::credential_key( 'user' )       => __( 'Benutzer des Geschäftskundenportals', 'lindenweg16' ),
			self::credential_key( 'password' )   => __( 'Passwort des Geschäftskundenportals', 'lindenweg16' ),
			self::credential_key( 'ekp' )        => __( 'Kundennummer (EKP)', 'lindenweg16' ),
		);

		foreach ( $pflicht as $key => $klartext ) {
			if ( '' === trim( (string) Settings::get( $key, '' ) ) ) {
				/* translators: 1: Feldname, 2: Sandbox oder Live. */
				$fehlt[] = sprintf( __( '%1$s (%2$s)', 'lindenweg16' ), $klartext, $modus );
			}
		}

		return $fehlt;
	}

	/**
	 * Läuft der Zugang gegen die Sandbox?
	 *
	 * @return bool
	 */
	public static function sandbox() {
		return 1 === (int) Settings::get( 'dhl_versand_sandbox', 1 );
	}

	/**
	 * Grundadresse.
	 *
	 * @return string
	 */
	public static function base() {
		return self::base_host() . self::PATH;
	}

	/**
	 * Grundadresse ohne Pfad – auch die Anmeldung hängt daran.
	 *
	 * @return string
	 */
	public static function base_host() {
		return self::sandbox() ? self::HOST_SANDBOX : self::HOST_LIVE;
	}

	/**
	 * Einstellungsschlüssel für ein umgebungsabhängiges DHL-Feld im
	 * gerade aktiven Modus. Sandbox und Live haben getrennte Zugangsdaten
	 * UND getrennte Kundennummer/Teilnahme – die DHL-Sandbox weist ein
	 * echtes Geschäftskundenportal-Login mit „invalid_grant" zurück, und
	 * eine echte Abrechnungsnummer mit „Die ausgewählte Abrechnungsnummer
	 * steht nicht zur Verfügung" (Bad Request); umgekehrt lehnt der
	 * Live-Betrieb die Sandbox-Testdaten ab.
	 *
	 * @param string $feld api_key, api_secret, user, password, ekp oder teilnahme.
	 * @return string
	 */
	public static function credential_key( $feld ) {
		return 'dhl_versand_' . ( self::sandbox() ? 'sandbox_' : 'live_' ) . $feld;
	}

	/**
	 * Kopfzeilen samt Anmeldung.
	 *
	 * Die DHL will beides: den API-Key der Anwendung und die Anmeldung
	 * des Geschäftskunden. Der Key allein sagt nur, wer fragt – nicht,
	 * auf wessen Vertrag gebucht wird. Beides kommt aus den Zugangsdaten
	 * des gerade aktiven Modus (Sandbox oder Live).
	 *
	 * @return array
	 */
	protected static function headers( $token = '' ) {
		$headers = array(
			'Accept'          => 'application/json',
			'Accept-Language' => 'de-DE',
			'Content-Type'    => 'application/json',
		);

		// Der neue Weg: ein Token, das API-Key und API-Secret der
		// Anwendung zusammen mit der Anmeldung des Geschäftskunden
		// eingetauscht haben. Es trägt beide Ebenen in sich, ein
		// zusätzlicher Key wäre überflüssig.
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;

			return $headers;
		}

		// Der alte Weg für Zugänge ohne API-Secret. Die DHL ersetzt ihn
		// nach und nach durch das Token; solange er noch angenommen wird,
		// soll ein eingerichteter Zugang nicht aufhören zu laufen. Auch er
		// nutzt die Zugangsdaten des gerade aktiven Modus (Sandbox/Live).
		$headers['dhl-api-key']   = (string) Settings::get( self::credential_key( 'api_key' ), '' );
		$headers['Authorization'] = 'Basic ' . base64_encode(
			Settings::get( self::credential_key( 'user' ), '' ) . ':' . Settings::get( self::credential_key( 'password' ), '' )
		);

		return $headers;
	}

	/**
	 * Token besorgen, wenn der Zugang darauf ausgelegt ist.
	 *
	 * @return array {token, messages}
	 */
	protected static function auth() {
		if ( ! DhlAuth::available() ) {
			return array(
				'token'    => '',
				'messages' => array(),
			);
		}

		return DhlAuth::token();
	}

	/**
	 * Anfrage mit Anmeldung ausführen.
	 *
	 * Wird ein Token abgewiesen, wird es einmal verworfen und neu geholt:
	 * Es könnte in der Sekunde abgelaufen sein, in der die Anfrage lief.
	 * Ein zweiter Fehlschlag ist ein echter.
	 *
	 * @param callable $ruf Bekommt das Token und liefert die Rohantwort.
	 * @return array {antwort, messages}
	 */
	protected static function mit_anmeldung( callable $ruf ) {
		$anmeldung = self::auth();

		if ( DhlAuth::available() && '' === $anmeldung['token'] ) {
			return array(
				'antwort'  => null,
				'messages' => $anmeldung['messages'],
			);
		}

		$antwort = $ruf( $anmeldung['token'] );

		if ( ! is_wp_error( $antwort ) && DhlAuth::available()
			&& in_array( (int) wp_remote_retrieve_response_code( $antwort ), array( 401, 403 ), true ) ) {
			DhlAuth::forget();
			$erneut = DhlAuth::token( true );

			if ( '' !== $erneut['token'] ) {
				$antwort = $ruf( $erneut['token'] );
			}
		}

		return array(
			'antwort'  => $antwort,
			'messages' => array(),
		);
	}

	/**
	 * Sendung anlegen und Label holen.
	 *
	 * @param array $auftrag Ergebnis von DhlRequest::build().
	 * @return array Ergebnis von DhlResponse::parse().
	 */
	public static function create( array $auftrag ) {
		$args = array(
			'includeDocs' => 'include',
			'docFormat'   => 'PDF',
		);

		// Ein leeres Labelformat heißt „wie im Konto hinterlegt". Als
		// leerer Parameter mitgeschickt wäre es dagegen ein ungültiger
		// Wert und die ganze Sendung abgelehnt.
		$format = trim( (string) Settings::get( 'dhl_versand_printformat', '' ) );

		if ( '' !== $format ) {
			$args['printFormat'] = $format;
		}

		$url = add_query_arg( $args, self::base() . '/orders' );

		return self::senden( $url, $auftrag );
	}

	/**
	 * Auftrag abschicken – mit Anmeldung, Wiederholung und Auswertung.
	 *
	 * @param string $url     Adresse.
	 * @param array  $auftrag Rumpf.
	 * @return array
	 */
	protected static function senden( $url, array $auftrag ) {
		$lauf = self::mit_anmeldung(
			static function ( $token ) use ( $url, $auftrag ) {
				return wp_remote_post(
					$url,
					array(
						'timeout' => 30,
						'headers' => self::headers( $token ),
						'body'    => wp_json_encode( $auftrag ),
					)
				);
			}
		);

		if ( null === $lauf['antwort'] ) {
			return array(
				'ok'          => false,
				'shipment_no' => '',
				'label_b64'   => '',
				'label_url'   => '',
				'messages'    => $lauf['messages'],
				'http'        => 0,
			);
		}

		return self::verarbeiten( $lauf['antwort'] );
	}

	/**
	 * Sendung vor dem Erzeugen prüfen lassen.
	 *
	 * Kostet kein Label und keine Frankierung – der ehrliche Weg, einen
	 * neuen Zugang oder eine ungewohnte Adresse auszuprobieren.
	 *
	 * @param array $auftrag Ergebnis von DhlRequest::build().
	 * @return array
	 */
	public static function validate( array $auftrag ) {
		return self::senden( add_query_arg( array( 'validate' => 'true' ), self::base() . '/orders' ), $auftrag );
	}

	/**
	 * Sendung stornieren, solange sie nicht abgeholt ist.
	 *
	 * @param string $shipment_no Sendungsnummer.
	 * @param string $profile     Abrechnungsprofil.
	 * @return array {ok, messages}
	 */
	public static function void( $shipment_no, $profile ) {
		$url = add_query_arg(
			array(
				'profile'  => rawurlencode( (string) $profile ),
				'shipment' => rawurlencode( (string) $shipment_no ),
			),
			self::base() . '/orders'
		);

		$lauf = self::mit_anmeldung(
			static function ( $token ) use ( $url ) {
				return wp_remote_request(
					$url,
					array(
						'method'  => 'DELETE',
						'timeout' => 30,
						'headers' => self::headers( $token ),
					)
				);
			}
		);

		if ( null === $lauf['antwort'] ) {
			return array(
				'ok'       => false,
				'messages' => $lauf['messages'],
			);
		}

		$antwort = $lauf['antwort'];

		if ( is_wp_error( $antwort ) ) {
			return array(
				'ok'       => false,
				'messages' => array( $antwort->get_error_message() ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $antwort );
		$body = json_decode( wp_remote_retrieve_body( $antwort ), true );

		$ok        = $code >= 200 && $code < 300;
		$meldungen = is_array( $body ) ? DhlResponse::messages( $body, isset( $body['items'][0] ) ? (array) $body['items'][0] : array() ) : array();

		if ( ! $ok ) {
			$hinweis = DhlResponse::http_hint( $code );

			if ( '' !== $hinweis ) {
				$meldungen[] = $hinweis;
			}
		}

		return array(
			'ok'       => $ok,
			'messages' => array_values( array_unique( $meldungen ) ),
		);
	}

	/**
	 * Nur die Anmeldung prüfen, ohne etwas zu frankieren.
	 *
	 * Das Erste, was man wissen will, wenn ein Label nicht entsteht: Liegt
	 * es am Zugang oder an der Sendung? Diese Frage kostet sonst jedes Mal
	 * einen Frankierversuch.
	 *
	 * @return array {ok, message}
	 */
	public static function test() {
		$fehlt = self::missing_credentials();

		if ( $fehlt ) {
			return array(
				'ok'      => false,
				/* translators: %s: Liste fehlender Angaben. */
				'message' => sprintf( __( 'Der Zugang ist unvollständig – es fehlt: %s.', 'lindenweg16' ), implode( ', ', $fehlt ) ),
			);
		}

		if ( ! DhlAuth::available() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Ohne API-Secret kann die Anmeldung nicht geprüft werden. Die DHL verlangt beides: API-Key und API-Secret der Anwendung, dazu Benutzer und Passwort des Geschäftskundenportals.', 'lindenweg16' ),
			);
		}

		DhlAuth::forget();
		$anmeldung = DhlAuth::token( true );

		if ( '' === $anmeldung['token'] ) {
			return array(
				'ok'      => false,
				'message' => implode( ' ', $anmeldung['messages'] ),
			);
		}

		return array(
			'ok'      => true,
			'message' => self::sandbox()
				? __( 'Die Anmeldung an der DHL-Sandbox hat geklappt. Für den Echtbetrieb den Sandbox-Haken entfernen – dort gelten eigene Zugangsdaten.', 'lindenweg16' )
				: __( 'Die Anmeldung an der DHL hat geklappt. Labels können erzeugt werden.', 'lindenweg16' ),
		);
	}

	/**
	 * Antwort von WordPress in das eigene Format bringen.
	 *
	 * @param mixed $antwort Rückgabe von wp_remote_*.
	 * @return array
	 */
	protected static function verarbeiten( $antwort ) {
		if ( is_wp_error( $antwort ) ) {
			return array(
				'ok'          => false,
				'shipment_no' => '',
				'label_b64'   => '',
				'label_url'   => '',
				'messages'    => array( $antwort->get_error_message() ),
				'http'        => 0,
			);
		}

		return DhlResponse::parse(
			wp_remote_retrieve_body( $antwort ),
			(int) wp_remote_retrieve_response_code( $antwort )
		);
	}
}
