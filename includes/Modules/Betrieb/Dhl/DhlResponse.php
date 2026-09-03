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

		if ( ! $ergebnis['ok'] ) {
			$hinweis = self::http_hint( $ergebnis['http'] );

			if ( '' !== $hinweis ) {
				$ergebnis['messages'][] = $hinweis;
			}
		}

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

		// Ein abgelehnter Zugang antwortet gar nicht in der Struktur einer
		// Sendung: Kein `items`, kein `status` – der Grund steht flach im
		// Rumpf. Wer nur in die Sendungsstruktur schaut, findet nichts und
		// meldet „kein Grund genannt", obwohl die DHL einen genannt hat.
		$meldungen = array_merge( $meldungen, self::flat_messages( $body ) );

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

	/**
	 * Meldungen aus einem Fehlerrumpf ohne Sendungsstruktur.
	 *
	 * Die Schnittstelle und das Gateway davor antworten im Fehlerfall
	 * unterschiedlich: mal nach RFC 7807 mit `title` und `detail`, mal mit
	 * `message`, mal mit einer Liste unter `errors`. Alles davon ist mehr
	 * wert als „kein Grund genannt".
	 *
	 * @param array $body Antwortrumpf.
	 * @return array
	 */
	public static function flat_messages( array $body ) {
		$meldungen = array();

		foreach ( array( 'detail', 'title', 'message', 'error_description', 'error' ) as $feld ) {
			if ( isset( $body[ $feld ] ) && is_string( $body[ $feld ] ) && '' !== $body[ $feld ] ) {
				$meldungen[] = $body[ $feld ];
			}
		}

		if ( isset( $body['errors'] ) && is_array( $body['errors'] ) ) {
			foreach ( $body['errors'] as $fehler ) {
				if ( is_string( $fehler ) ) {
					$meldungen[] = $fehler;
					continue;
				}

				if ( is_array( $fehler ) ) {
					$meldungen = array_merge( $meldungen, self::flat_messages( $fehler ) );
				}
			}
		}

		return $meldungen;
	}

	/**
	 * Was ein HTTP-Status im Klartext bedeutet.
	 *
	 * Eine nackte 401 sagt dem, der am Packtisch steht, nichts. Sie sagt
	 * ihm aber genau, wo er nachsehen muss – wenn es jemand hinschreibt.
	 *
	 * @param int $code HTTP-Status.
	 * @return string Klartext oder leer.
	 */
	public static function http_hint( $code ) {
		$code = (int) $code;

		if ( 0 === $code ) {
			return __( 'Die DHL war nicht erreichbar.', 'lindenweg16' );
		}

		if ( 401 === $code || 403 === $code ) {
			return __( 'Die DHL hat den Zugang abgewiesen (401/403). Prüfe API-Key, API-Secret sowie Benutzer und Passwort des Geschäftskundenportals – und ob der Zugang für die gewählte Umgebung gilt: Sandbox-Daten funktionieren im Echtbetrieb nicht und umgekehrt.', 'lindenweg16' );
		}

		if ( 400 === $code || 422 === $code ) {
			return __( 'Die DHL hat den Auftrag als fehlerhaft zurückgewiesen (400). Meist stimmt etwas an Adresse, Gewicht oder Abrechnungsnummer nicht.', 'lindenweg16' );
		}

		if ( 429 === $code ) {
			return __( 'Zu viele Anfragen in kurzer Zeit (429). Kurz warten und noch einmal.', 'lindenweg16' );
		}

		if ( $code >= 500 ) {
			/* translators: %d: HTTP-Status. */
			return sprintf( __( 'Die DHL meldet eine Störung auf ihrer Seite (%d). Das liegt nicht an den Daten – später noch einmal.', 'lindenweg16' ), $code );
		}

		if ( $code >= 300 ) {
			/* translators: %d: HTTP-Status. */
			return sprintf( __( 'Unerwartete Antwort der DHL (HTTP %d).', 'lindenweg16' ), $code );
		}

		return '';
	}
}
