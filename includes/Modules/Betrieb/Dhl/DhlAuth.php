<?php
/**
 * Anmeldung an den Post-&-Paket-Schnittstellen der DHL.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Dhl;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Holt und verwahrt das Zugangstoken.
 *
 * Die DHL verlangt eine Anmeldung auf zwei Ebenen: die Anwendung weist
 * sich mit API-Key und API-Secret aus, der Geschäftskunde mit Benutzer und
 * Passwort aus dem Geschäftskundenportal. Erst beides zusammen ergibt ein
 * Token – deshalb reicht ein Key allein nicht, und deshalb kam vorher nur
 * eine Ablehnung ohne Label zurück.
 *
 * Das Token gilt eine halbe Stunde. Es jedes Mal neu zu holen wäre ein
 * zusätzlicher Aufruf je Label; es unbegrenzt zu behalten hiesse, mitten
 * im Frankieren auf ein abgelaufenes zu stossen. Es wird deshalb
 * zwischengespeichert und vor Ablauf verworfen.
 */
class DhlAuth {

	/**
	 * Wo das Token liegt.
	 */
	const CACHE_KEY = 'lw16_dhl_token';

	/**
	 * Sicherheitsabstand zum Ablauf in Sekunden.
	 *
	 * Ein Token, das in zehn Sekunden abläuft, ist für einen Aufruf, der
	 * dreissig dauern darf, keins mehr.
	 */
	const PUFFER = 120;

	/**
	 * Pfad des Token-Endpunkts.
	 */
	const PATH = '/parcel/de/account/auth/ropc/v1/token';

	/**
	 * Ist der neue Weg überhaupt eingerichtet?
	 *
	 * Ohne API-Secret bleibt es beim alten Verfahren – ein Zugang, der
	 * bisher lief, soll nicht dadurch aufhören, dass es einen neuen gibt.
	 *
	 * @return bool
	 */
	public static function available() {
		return '' !== trim( (string) Settings::get( 'dhl_versand_api_secret', '' ) );
	}

	/**
	 * Gültiges Token, notfalls frisch geholt.
	 *
	 * @param bool $force Zwischenspeicher übergehen.
	 * @return array {token, messages}
	 */
	public static function token( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::cache_key() );

			if ( is_string( $cached ) && '' !== $cached ) {
				return array(
					'token'    => $cached,
					'messages' => array(),
				);
			}
		}

		return self::fetch();
	}

	/**
	 * Zwischengespeichertes Token verwerfen.
	 *
	 * Nach einer Ablehnung: Ein Token, das die DHL nicht mehr annimmt,
	 * hilft beim zweiten Versuch auch nicht.
	 */
	public static function forget() {
		delete_transient( self::cache_key() );
	}

	/**
	 * Schlüssel des Zwischenspeichers.
	 *
	 * Sandbox und Echtbetrieb haben verschiedene Token. Unter demselben
	 * Schlüssel abgelegt würde ein Umschalten der Umgebung mit dem alten
	 * Token weiterlaufen – und die Ablehnung sähe aus wie ein falsches
	 * Passwort.
	 *
	 * @return string
	 */
	protected static function cache_key() {
		return self::CACHE_KEY . ( DhlClient::sandbox() ? '_sandbox' : '_live' );
	}

	/**
	 * Token beim Anmeldedienst holen.
	 *
	 * @return array {token, messages}
	 */
	protected static function fetch() {
		// In der Sandbox der Demo-Zugang der DHL – dort gibt es keinen
		// eigenen Benutzer, den man anlegen könnte.
		$zugang = DhlSandbox::credentials(
			DhlClient::sandbox(),
			array(
				'user'     => (string) Settings::get( 'dhl_versand_user', '' ),
				'password' => (string) Settings::get( 'dhl_versand_password', '' ),
			)
		);

		$antwort = wp_remote_post(
			DhlClient::base_host() . self::PATH,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'    => array(
					'grant_type'    => 'password',
					'username'      => $zugang['user'],
					'password'      => $zugang['password'],
					'client_id'     => (string) Settings::get( 'dhl_versand_api_key', '' ),
					'client_secret' => (string) Settings::get( 'dhl_versand_api_secret', '' ),
				),
			)
		);

		if ( is_wp_error( $antwort ) ) {
			return array(
				'token'    => '',
				'messages' => array( $antwort->get_error_message() ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $antwort );
		$body = json_decode( wp_remote_retrieve_body( $antwort ), true );

		$ergebnis = self::parse( is_array( $body ) ? $body : array(), $code );

		if ( '' !== $ergebnis['token'] && $ergebnis['ttl'] > 0 ) {
			set_transient( self::cache_key(), $ergebnis['token'], $ergebnis['ttl'] );
		}

		return array(
			'token'    => $ergebnis['token'],
			'messages' => $ergebnis['messages'],
		);
	}

	/**
	 * Antwort des Anmeldedienstes auswerten – reine Logik.
	 *
	 * @param array $body Rumpf.
	 * @param int   $code HTTP-Status.
	 * @return array {token, ttl, messages}
	 */
	public static function parse( array $body, $code = 0 ) {
		$token = isset( $body['access_token'] ) && is_string( $body['access_token'] ) ? $body['access_token'] : '';

		if ( '' !== $token ) {
			return array(
				'token'    => $token,
				'ttl'      => self::ttl( isset( $body['expires_in'] ) ? $body['expires_in'] : 0 ),
				'messages' => array(),
			);
		}

		$meldungen = DhlResponse::flat_messages( $body );
		$hinweis   = DhlResponse::http_hint( $code );

		if ( '' !== $hinweis ) {
			$meldungen[] = $hinweis;
		}

		if ( ! $meldungen ) {
			$meldungen[] = __( 'Die DHL hat kein Zugangstoken ausgegeben und keinen Grund genannt.', 'lindenweg16' );
		}

		return array(
			'token'    => '',
			'ttl'      => 0,
			'messages' => array_values( array_unique( $meldungen ) ),
		);
	}

	/**
	 * Wie lange das Token aufgehoben wird – reine Logik.
	 *
	 * Kürzer als die DHL sagt, damit es nicht mitten im Frankieren
	 * abläuft. Meldet sie gar nichts, gilt die halbe Stunde aus ihrer
	 * Beschreibung.
	 *
	 * @param mixed $expires_in Angabe der DHL in Sekunden.
	 * @return int Sekunden; 0 heißt „nicht aufheben".
	 */
	public static function ttl( $expires_in ) {
		$sekunden = (int) $expires_in;

		if ( $sekunden <= 0 ) {
			$sekunden = 1800;
		}

		return max( 0, $sekunden - self::PUFFER );
	}
}
