<?php
/**
 * Erkennung des Versandlabel-Wegs.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Wer erzeugt hier das DHL-Label?
 *
 * Dieses Plugin erzeugt keine Labels. Es liest die Sendungsnummer, die ein
 * Versandplugin an der Bestellung hinterlässt, und verfolgt die Sendung
 * damit weiter (siehe DhlTracking). Ein eigenes Label bräuchte den
 * Geschäftskundenzugang der DHL – Kundennummer, Teilnahmenummern,
 * API-Zugang – und einen revisionssicheren Ablageort für die PDFs.
 *
 * Die Versandseite muss deshalb ehrlich sagen können, was gerade da ist:
 * ein erkanntes Versandplugin, das das Label erzeugt, oder gar keins.
 */
class ShippingLabel {

	/**
	 * Bekannte Versandplugins: Kennung => [Anzeigename, Art, Prüfwert].
	 *
	 * Geprüft wird auf eine Klasse oder Funktion, die das jeweilige
	 * Plugin nur im aktiven Zustand definiert.
	 *
	 * @return array
	 */
	public static function candidates() {
		return array(
			'dhl_for_woocommerce' => array( __( 'DHL für WooCommerce', 'lindenweg16' ), 'class', 'PR_DHL_WC' ),
			'germanized_shipments' => array( __( 'Germanized Versand', 'lindenweg16' ), 'function', 'wc_gzd_get_shipments_by_order' ),
			'shipment_tracking'   => array( __( 'WooCommerce Shipment Tracking', 'lindenweg16' ), 'class', 'WC_Shipment_Tracking' ),
		);
	}

	/**
	 * Welches der Plugins ist zuständig?
	 *
	 * Reine Logik über eine Verfügbarkeitstabelle, damit sie ohne
	 * WordPress prüfbar bleibt. Bei mehreren aktiven Plugins gewinnt das
	 * erste in der Reihenfolge oben – sie steht von „erzeugt DHL-Labels"
	 * nach „verwaltet nur Sendungsnummern".
	 *
	 * @param array $verfuegbar Kennung => bool.
	 * @return string Kennung oder leer.
	 */
	public static function detect( array $verfuegbar ) {
		foreach ( array_keys( self::candidates() ) as $kennung ) {
			if ( ! empty( $verfuegbar[ $kennung ] ) ) {
				return $kennung;
			}
		}

		return '';
	}

	/**
	 * Kennung des aktiven Versandplugins.
	 *
	 * @return string
	 */
	public static function active() {
		$verfuegbar = array();

		foreach ( self::candidates() as $kennung => $eintrag ) {
			list( , $art, $pruefwert ) = $eintrag;

			$verfuegbar[ $kennung ] = 'class' === $art ? class_exists( $pruefwert ) : function_exists( $pruefwert );
		}

		return self::detect( $verfuegbar );
	}

	/**
	 * Anzeigename eines Versandplugins.
	 *
	 * @param string $kennung Kennung aus detect().
	 * @return string
	 */
	public static function label( $kennung ) {
		$kandidaten = self::candidates();

		return isset( $kandidaten[ $kennung ] ) ? $kandidaten[ $kennung ][0] : '';
	}
}
