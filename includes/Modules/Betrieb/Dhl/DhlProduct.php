<?php
/**
 * Produktwahl und Abrechnungsnummer der DHL.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Dhl;

defined( 'ABSPATH' ) || exit;

/**
 * Welches DHL-Produkt, und auf welche Abrechnungsnummer?
 *
 * Reine Logik ohne Netz und ohne Datenbank. Die Abrechnungsnummer ist die
 * Stelle, an der ein Fehler teuer wird: Sie entscheidet, welches Konto
 * belastet und welcher Vertrag angewendet wird. Sie besteht aus der
 * Kundennummer (EKP, 10 Stellen), dem zweistelligen Verfahren – das
 * steckt im Produktschlüssel – und der zweistelligen Teilnahme aus dem
 * Geschäftskundenportal.
 */
class DhlProduct {

	/**
	 * Bekannte Produkte: Schlüssel => Bezeichnung.
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			'V01PAK'  => __( 'DHL Paket (national)', 'lindenweg16' ),
			'V62WP'   => __( 'DHL Warenpost / Kleinpaket (national)', 'lindenweg16' ),
			'V54EPAK' => __( 'DHL Europaket', 'lindenweg16' ),
			'V53WPAK' => __( 'DHL Paket International', 'lindenweg16' ),
			'V66WPI'  => __( 'Warenpost International', 'lindenweg16' ),
		);
	}

	/**
	 * Länder der Europäischen Union.
	 *
	 * Entscheidet über Europaket gegen Weltpaket und darüber, ob eine
	 * Zollinhaltserklärung nötig ist.
	 */
	const EU = array(
		'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
		'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL',
		'PT', 'RO', 'SE', 'SI', 'SK',
	);

	/**
	 * Gewichtsgrenze der Warenpost in Gramm.
	 *
	 * Darüber ist es ein Paket. Ein Warenpost-Label für 2 kg weist die
	 * DHL zurück – und zwar erst beim Erzeugen, wenn das Paket schon
	 * gepackt ist.
	 */
	const WARENPOST_MAX_G = 1000;

	/**
	 * Gehört das Land zur EU?
	 *
	 * @param string $country Zweibuchstabiges Länderkürzel.
	 * @return bool
	 */
	public static function is_eu( $country ) {
		return in_array( strtoupper( trim( (string) $country ) ), self::EU, true );
	}

	/**
	 * Braucht diese Sendung eine Zollinhaltserklärung?
	 *
	 * @param string $country Zielland.
	 * @return bool
	 */
	public static function needs_customs( $country ) {
		$country = strtoupper( trim( (string) $country ) );

		return '' !== $country && ! self::is_eu( $country );
	}

	/**
	 * Vorschlag für das Produkt anhand Ziel und Gewicht.
	 *
	 * Ein Vorschlag, keine Festlegung: Die Versandliste zeigt ihn im
	 * Auswahlfeld und lässt ihn ändern. Wer sperrige Ware verschickt,
	 * weiss mehr als das Gewicht verrät.
	 *
	 * @param string $country  Zielland.
	 * @param float  $weight_g Gewicht in Gramm.
	 * @param bool   $warenpost Warenpost überhaupt gebucht?
	 * @return string Produktschlüssel.
	 */
	public static function for_destination( $country, $weight_g, $warenpost = false ) {
		$country  = strtoupper( trim( (string) $country ) );
		$weight_g = (float) $weight_g;

		if ( '' === $country || 'DE' === $country ) {
			return $warenpost && $weight_g > 0 && $weight_g <= self::WARENPOST_MAX_G ? 'V62WP' : 'V01PAK';
		}

		if ( self::is_eu( $country ) ) {
			return 'V54EPAK';
		}

		return $warenpost && $weight_g > 0 && $weight_g <= self::WARENPOST_MAX_G ? 'V66WPI' : 'V53WPAK';
	}

	/**
	 * Verfahren eines Produkts – die zwei Ziffern im Schlüssel.
	 *
	 * @param string $product Produktschlüssel.
	 * @return string Zwei Ziffern oder leer.
	 */
	public static function procedure( $product ) {
		$product = strtoupper( trim( (string) $product ) );

		return preg_match( '/^V(\d{2})/', $product, $treffer ) ? $treffer[1] : '';
	}

	/**
	 * Abrechnungsnummer bauen: EKP + Verfahren + Teilnahme.
	 *
	 * @param string $ekp           Kundennummer, 10 Stellen.
	 * @param string $product       Produktschlüssel.
	 * @param string $participation Teilnahme, 2 Stellen.
	 * @return string Vierzehnstellige Nummer oder leer, wenn etwas fehlt.
	 */
	public static function billing_number( $ekp, $product, $participation ) {
		$ekp           = preg_replace( '/\D/', '', (string) $ekp );
		$participation = trim( (string) $participation );
		$procedure     = self::procedure( $product );

		if ( 10 !== strlen( $ekp ) || '' === $procedure || 2 !== strlen( $participation ) ) {
			return '';
		}

		return $ekp . $procedure . strtoupper( $participation );
	}

	/**
	 * Bezeichnung eines Produkts.
	 *
	 * @param string $product Produktschlüssel.
	 * @return string
	 */
	public static function label( $product ) {
		$alle = self::all();

		return isset( $alle[ $product ] ) ? $alle[ $product ] : (string) $product;
	}
}
