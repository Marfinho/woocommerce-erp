<?php
/**
 * Türklingel zu den Raspberry-Pi-Programmen über Home Assistant.
 *
 * Statt dass Bambu-Bridge, Slicing-Worker und Etikettendrucker-Client alle
 * paar Sekunden fragen „gibt es etwas?“ (jede Frage startet auf dem Webspace
 * WordPress komplett), klingelt das Plugin, wenn es etwas gibt: ein kurzer
 * HTTPS-Aufruf an einen Webhook von Home Assistant, der die Klingel im
 * Heimnetz per MQTT an den Pi weitergibt. Der Pi holt sich daraufhin den Job
 * wie bisher mit seinem Token. Die Klingel enthält keine Daten, nur den
 * Kanal (bambu, slicing, etikett, kamera) und ggf. die Drucker-ID.
 *
 * Fehlt die Webhook-Adresse, passiert nichts – die Pi-Programme fragen dann
 * wie gewohnt selbst nach.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Klingeln sammeln und am Ende des Aufrufs abschicken.
 */
class Doorbell {

	/**
	 * Option mit der Webhook-Adresse.
	 */
	const OPTION = 'lw16_doorbell_url';

	/**
	 * Erlaubte Kanäle (= Endungen der MQTT-Topics lw16/klingel/<kanal>).
	 */
	const CHANNELS = array( 'bambu', 'slicing', 'etikett', 'kamera' );

	/**
	 * Gesammelte Klingeln dieses Aufrufs: "kanal|drucker" => array.
	 *
	 * @var array<string, array>
	 */
	private static $pending = array();

	/**
	 * Ist der shutdown-Hook schon angemeldet?
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Webhook-Adresse (leer = Klingel aus).
	 *
	 * @return string
	 */
	public static function url() {
		$url = get_option( self::OPTION, '' );
		return is_string( $url ) ? trim( $url ) : '';
	}

	/**
	 * Ist eine Adresse brauchbar? Nur HTTPS – die Adresse ist das Geheimnis.
	 *
	 * @param string $url Adresse.
	 * @return bool
	 */
	public static function valid_url( $url ) {
		$url = trim( (string) $url );
		return '' === $url || ( 0 === strpos( $url, 'https://' ) && false !== filter_var( $url, FILTER_VALIDATE_URL ) );
	}

	/**
	 * Klingeln (wird am Ende des Aufrufs gesendet, mehrfaches Klingeln zählt einmal).
	 *
	 * @param string $channel    bambu, slicing, etikett oder kamera.
	 * @param string $printer_id Drucker-ID (optional).
	 */
	public static function ring( $channel, $printer_id = '' ) {
		$channel = (string) $channel;
		if ( ! in_array( $channel, self::CHANNELS, true ) || '' === self::url() ) {
			return;
		}

		$printer_id                                   = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $printer_id ) );
		self::$pending[ $channel . '|' . $printer_id ] = array(
			'channel' => $channel,
			'printer' => $printer_id,
		);

		if ( ! self::$hooked && function_exists( 'add_action' ) ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Was gesendet würde (für Tests und die Testklingel).
	 *
	 * @return array[]
	 */
	public static function pending() {
		return array_values( self::$pending );
	}

	/**
	 * Gesammelte Klingeln abschicken – ohne auf die Antwort zu warten, damit
	 * ein langsames oder nicht erreichbares Home Assistant WordPress nie
	 * ausbremst.
	 *
	 * @param bool $blocking Auf die Antwort warten (nur für die Testklingel).
	 * @return array Ergebnis je Klingel: true oder Fehlermeldung.
	 */
	public static function flush( $blocking = false ) {
		$url     = self::url();
		$pending = self::$pending;
		$result  = array();

		self::$pending = array();

		if ( '' === $url || ! function_exists( 'wp_remote_post' ) ) {
			return $result;
		}

		foreach ( $pending as $key => $ring ) {
			$response = wp_remote_post(
				$url,
				array(
					'blocking' => (bool) $blocking,
					'timeout'  => $blocking ? 8 : 2,
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'body'     => wp_json_encode( $ring ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$result[ $key ] = $response->get_error_message();
			} elseif ( $blocking ) {
				$code           = (int) wp_remote_retrieve_response_code( $response );
				$result[ $key ] = $code >= 200 && $code < 300 ? true : sprintf( 'HTTP %d', $code );
			} else {
				$result[ $key ] = true;
			}
		}

		return $result;
	}

	/**
	 * Nur für Tests: gesammelte Klingeln verwerfen.
	 */
	public static function reset() {
		self::$pending = array();
	}
}
