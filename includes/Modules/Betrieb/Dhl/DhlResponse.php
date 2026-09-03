<?php
/**
 * Antwort der DHL auswerten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Dhl;

defined( 'ABSPATH' ) || exit;

/**
 * Aus der Antwort der Schnittstelle wird hier ein Satz, den man am
 * Packtisch lesen kann – reine Logik, damit auch der Fehlerfall prüfbar
 * ist, ohne dass jemand ein Label verschwendet.
 *
 * Die DHL antwortet auf zwei Ebenen: einem Gesamtstatus für den Aufruf
 * und einem eigenen je Sendung. Ein Aufruf kann „207" melden – teilweise
 * erfolgreich – und die Sendung darin trotzdem abgelehnt sein. Wer nur
 * den HTTP-Code prüft, hält so eine Ablehnung für ein Label.
 */
class DhlResponse {

	/**
	 * Antwort zerlegen.
	 *
	 * @param mixed $body      Dekodierter JSON-Rumpf (Array) oder Rohtext.
	 * @param int   $http_code HTTP-Status.
	 * @return array {ok, shipment_no, label_b64, label_url, messages, http}
	 */
	public static function parse( $body, $http_code = 0 ) {
		if ( is_string( $body ) ) {
			$body = json_decode( $body, true );
		}

		$ergebnis = array(
			'ok'          => false,
			'shipment_no' => '',
			'label_b64'   => '',
			'label_url'   => '',
			'messages'    => array(),
			'http'        => (int) $http_code,
		);

		if ( ! is_array( $body ) ) {
			$ergebnis['messages'][] = __( 'Die DHL hat keine lesbare Antwort geschickt.', 'lindenweg16' );

			return $ergebnis;
		}

		$item = isset( $body['items'][0] ) && is_array( $body['items'][0] ) ? $body['items'][0] : array();

		$ergebnis['shipment_no'] = isset( $item['shipmentNo'] ) ? (string) $item['shipmentNo'] : '';
		$ergebnis['label_b64']   = isset( $item['label']['b64'] ) ? (string) $item['label']['b64'] : '';
		$ergebnis['label_url']   = isset( $item['label']['url'] ) ? (string) $item['label']['url'] : '';
		$ergebnis['messages']    = self::messages( $body, $item );

		// Erfolg heißt: eine Sendungsnummer und ein Label. Ohne Label gibt
		// es nichts zu kleben, ohne Nummer nichts zu verfolgen - beides
		// wäre ein halber Vorgang, der später niemandem auffällt.
		$ergebnis['ok'] = '' !== $ergebnis['shipment_no']
			&& ( '' !== $ergebnis['label_b64'] || '' !== $ergebnis['label_url'] )
			&& self::status_ok( isset( $item['sstatus'] ) ? $item['sstatus'] : array() );

		if ( ! $ergebnis['ok'] && ! $ergebnis['messages'] ) {
			$ergebnis['messages'][] = __( 'Die DHL hat kein Label geliefert und keinen Grund genannt.', 'lindenweg16' );
		}

		return $ergebnis;
	}

	/**
	 * Ist der Status einer Sendung in Ordnung?
	 *
	 * Die DHL zählt hier in HTTP-Manier: 200 ist gut, 207 heißt „mit
	 * Hinweisen" – das Label existiert trotzdem.
	 *
	 * @param mixed $sstatus Statusobjekt der Sendung.
	 * @return bool
	 */
	public static function status_ok( $sstatus ) {
		if ( ! is_array( $sstatus ) ) {
			return true;
		}

		$code = isset( $sstatus['statusCode'] ) ? (int) $sstatus['statusCode'] : 0;

		return 0 === $code || ( $code >= 200 && $code < 300 );
	}

	/**
	 * Meldungen aus allen Ebenen einsammeln.
	 *
	 * @param array $body Antwort.
	 * @param array $item Sendung.
	 * @return array
	 */
	public static function messages( array $body, array $item ) {
		$meldungen = array();

		foreach ( array( isset( $body['status'] ) ? $body['status'] : null, isset( $item['sstatus'] ) ? $item['sstatus'] : null ) as $status ) {
			if ( ! is_array( $status ) ) {
				continue;
			}

			$code = isset( $status['statusCode'] ) ? (int) $status['statusCode'] : 0;

			if ( $code && $code < 300 ) {
				continue;
			}

			foreach ( array( 'detail', 'title' ) as $feld ) {
				if ( ! empty( $status[ $feld ] ) ) {
					$meldungen[] = (string) $status[ $feld ];
					break;
				}
			}
		}

		if ( ! empty( $item['validationMessages'] ) && is_array( $item['validationMessages'] ) ) {
			foreach ( $item['validationMessages'] as $meldung ) {
				if ( ! is_array( $meldung ) ) {
					continue;
				}

				$text  = isset( $meldung['validationMessage'] ) ? (string) $meldung['validationMessage'] : '';
				$feld  = isset( $meldung['property'] ) ? (string) $meldung['property'] : '';

				if ( '' === $text ) {
					continue;
				}

				$meldungen[] = '' === $feld ? $text : $feld . ': ' . $text;
			}
		}

		return array_values( array_unique( array_filter( $meldungen ) ) );
	}
}
