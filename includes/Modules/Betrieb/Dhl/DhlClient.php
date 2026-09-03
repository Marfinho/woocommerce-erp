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

	const BASE_LIVE    = 'https://api-eu.dhl.com/parcel/de/shipping/v2';
	const BASE_SANDBOX = 'https://api-sandbox.dhl.com/parcel/de/shipping/v2';

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
	 * @return array
	 */
	public static function missing_credentials() {
		$fehlt = array();

		$pflicht = array(
			'dhl_versand_api_key'  => __( 'API-Key der Anwendung', 'lindenweg16' ),
			'dhl_versand_user'     => __( 'Benutzer des Geschäftskundenportals', 'lindenweg16' ),
			'dhl_versand_password' => __( 'Passwort des Geschäftskundenportals', 'lindenweg16' ),
			'dhl_versand_ekp'      => __( 'Kundennummer (EKP)', 'lindenweg16' ),
		);

		foreach ( $pflicht as $key => $klartext ) {
			if ( '' === trim( (string) Settings::get( $key, '' ) ) ) {
				$fehlt[] = $klartext;
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
		return self::sandbox() ? self::BASE_SANDBOX : self::BASE_LIVE;
	}

	/**
	 * Kopfzeilen samt Anmeldung.
	 *
	 * Die DHL will beides: den API-Key der Anwendung und die Anmeldung
	 * des Geschäftskunden. Der Key allein sagt nur, wer fragt – nicht,
	 * auf wessen Vertrag gebucht wird.
	 *
	 * @return array
	 */
	protected static function headers() {
		return array(
			'dhl-api-key'     => (string) Settings::get( 'dhl_versand_api_key', '' ),
			'Authorization'   => 'Basic ' . base64_encode(
				Settings::get( 'dhl_versand_user', '' ) . ':' . Settings::get( 'dhl_versand_password', '' )
			),
			'Accept'          => 'application/json',
			'Accept-Language' => 'de-DE',
			'Content-Type'    => 'application/json',
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

		$antwort = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => self::headers(),
				'body'    => wp_json_encode( $auftrag ),
			)
		);

		return self::verarbeiten( $antwort );
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
		$antwort = wp_remote_post(
			add_query_arg( array( 'validate' => 'true' ), self::base() . '/orders' ),
			array(
				'timeout' => 30,
				'headers' => self::headers(),
				'body'    => wp_json_encode( $auftrag ),
			)
		);

		return self::verarbeiten( $antwort );
	}

	/**
	 * Sendung stornieren, solange sie nicht abgeholt ist.
	 *
	 * @param string $shipment_no Sendungsnummer.
	 * @param string $profile     Abrechnungsprofil.
	 * @return array {ok, messages}
	 */
	public static function void( $shipment_no, $profile ) {
		$antwort = wp_remote_request(
			add_query_arg(
				array(
					'profile'  => rawurlencode( (string) $profile ),
					'shipment' => rawurlencode( (string) $shipment_no ),
				),
				self::base() . '/orders'
			),
			array(
				'method'  => 'DELETE',
				'timeout' => 30,
				'headers' => self::headers(),
			)
		);

		if ( is_wp_error( $antwort ) ) {
			return array(
				'ok'       => false,
				'messages' => array( $antwort->get_error_message() ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $antwort );
		$body = json_decode( wp_remote_retrieve_body( $antwort ), true );

		return array(
			'ok'       => $code >= 200 && $code < 300,
			'messages' => is_array( $body ) ? DhlResponse::messages( $body, isset( $body['items'][0] ) ? (array) $body['items'][0] : array() ) : array(),
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
