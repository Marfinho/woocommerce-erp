<?php
/**
 * Kleine Verträglichkeitsregeln für die Store-API der Block-Kasse.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Die Block-Kasse schickt beim Öffnen und beim Wechsel der Zahlungsart eine
 * Neuberechnung an `/wc/store/v1/checkout?__experimental_calc_totals=true` – ohne
 * Rechnungsadresse und oft ohne Zahlungsart. WooCommerce lehnt sie hier mit 400 ab
 * („Fehlende(r) Parameter: billing_address“), die Kasse zeigt das jedem Kunden als
 * roten Fehler. Eine ergänzte Adresse (0.97.4–0.97.6) schob die Anfrage nur weiter in
 * die Prüfung der Bestellung („Keine Zahlungsart angegeben“, „gültige E-Mail-Adresse
 * erforderlich“).
 *
 * Gebraucht wird von dieser Anfrage nur der aktuelle Stand der Kasse samt Summen.
 * Genau den liefert `GET /wc/store/v1/checkout` in derselben Form. Fehlt die
 * Rechnungsadresse, antwortet diese Klasse deshalb mit diesem Stand, ohne die
 * Anfrage an die Kassen-Route zu geben: keine Prüfung, kein Bestellpfad, nichts wird
 * gespeichert. Die eigentliche Bestellung (ohne `__experimental_calc_totals`) und
 * jede Neuberechnung mit Rechnungsadresse bleiben unberührt.
 */
class StoreApiCompat {

	const ROUTE = '/wc/store/v1/checkout';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_filter( 'rest_pre_dispatch', array( $this, 'answer_calc_totals' ), 10, 3 );
	}

	/**
	 * Neuberechnung ohne Rechnungsadresse mit dem aktuellen Kassenstand beantworten.
	 *
	 * @param mixed            $result  Bisheriges Ergebnis.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Anfrage.
	 * @return mixed Unverändertes Ergebnis oder die Antwort von GET /checkout.
	 */
	public function answer_calc_totals( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		// Die Kasse schickt die Neuberechnung als POST mit „X-HTTP-Method-Override: PUT“.
		if ( ! self::handles_method( $request->get_method() ) ) {
			return $result;
		}
		if ( self::ROUTE !== untrailingslashit( (string) $request->get_route() ) ) {
			return $result;
		}
		if ( ! self::needs_billing( (array) $request->get_params() ) ) {
			return $result;
		}
		if ( ! function_exists( 'rest_do_request' ) ) {
			return $result;
		}

		$get = new \WP_REST_Request( 'GET', self::ROUTE );
		$get->set_query_params( self::query_for_state( (array) $request->get_query_params() ) );

		return rest_do_request( $get );
	}

	/**
	 * Abfrageparameter für den Kassenstand: alles außer der Neuberechnung – reine Regel.
	 *
	 * @param array<string, mixed> $query Abfrageparameter der Neuberechnung.
	 * @return array<string, mixed>
	 */
	public static function query_for_state( array $query ) {
		unset( $query['__experimental_calc_totals'], $query['_method'] );
		return $query;
	}

	/**
	 * Methoden der Kassen-Route, über die eine Neuberechnung kommt – reine Regel.
	 *
	 * @param string $method HTTP-Methode (nach Method-Override).
	 * @return bool
	 */
	public static function handles_method( $method ) {
		return in_array( strtoupper( (string) $method ), array( 'POST', 'PUT', 'PATCH' ), true );
	}

	/**
	 * Ist das eine Neuberechnung ohne Rechnungsadresse?
	 *
	 * @param array<string, mixed> $params Parameter der Anfrage.
	 * @return bool
	 */
	public static function needs_billing( array $params ) {
		if ( ! isset( $params['__experimental_calc_totals'] ) || ! filter_var( $params['__experimental_calc_totals'], FILTER_VALIDATE_BOOLEAN ) ) {
			return false;
		}
		return empty( $params['billing_address'] );
	}
}
