<?php
/**
 * Kleine Verträglichkeitsregeln für die Store-API der Block-Kasse.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Der reCAPTCHA-Schutz von WooCommerce PayPal Payments ruft beim Öffnen der Kasse
 * `/wc/store/v1/checkout?__experimental_calc_totals=true` ohne Rechnungsadresse auf.
 * WooCommerce verlangt dort `billing_address` und antwortet mit 400 („Fehlende(r)
 * Parameter: billing_address“). Die Kasse zeigt das jedem Kunden als roten Fehler
 * an. Diese Klasse ergänzt bei genau diesen Neuberechnungen die Adresse aus den
 * Kundendaten des Warenkorbs. Die eigentliche Bestellung (ohne
 * `__experimental_calc_totals`) bleibt unberührt.
 */
class StoreApiCompat {

	const ROUTE = '/wc/store/v1/checkout';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_filter( 'rest_pre_dispatch', array( $this, 'complete_calc_totals' ), 10, 3 );
	}

	/**
	 * Fehlende Rechnungsadresse bei einer Neuberechnung ergänzen.
	 *
	 * @param mixed            $result  Bisheriges Ergebnis (wird unverändert weitergereicht).
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Anfrage.
	 * @return mixed
	 */
	public function complete_calc_totals( $result, $server, $request ) {
		if ( ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() ) {
			return $result;
		}
		if ( self::ROUTE !== untrailingslashit( (string) $request->get_route() ) ) {
			return $result;
		}
		if ( ! self::needs_billing( (array) $request->get_params() ) ) {
			return $result;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return $result;
		}

		$customer = WC()->customer;
		$billing  = self::billing_from( (array) $customer->get_billing(), (array) $customer->get_shipping() );
		$request->set_param( 'billing_address', $billing );

		return $result;
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

	/**
	 * Rechnungsadresse aus den Kundendaten; leere Felder kommen aus der Lieferadresse.
	 *
	 * @param array<string, mixed> $billing  Rechnungsdaten des Kunden.
	 * @param array<string, mixed> $shipping Lieferdaten des Kunden.
	 * @return array<string, string>
	 */
	public static function billing_from( array $billing, array $shipping ) {
		$keys = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
		$out  = array();
		foreach ( $keys as $key ) {
			$value = isset( $billing[ $key ] ) ? (string) $billing[ $key ] : '';
			if ( '' === $value && isset( $shipping[ $key ] ) && ! in_array( $key, array( 'email' ), true ) ) {
				$value = (string) $shipping[ $key ];
			}
			$out[ $key ] = $value;
		}
		return $out;
	}
}
