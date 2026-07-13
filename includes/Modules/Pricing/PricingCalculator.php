<?php
/**
 * Preiskalkulation: Kosten- und Preisberechnung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Pricing;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Inventory\Repository;

/**
 * Kapselt die Kalkulationsformeln. Identische Logik wie im JS-Frontend,
 * damit „Preis übernehmen" serverseitig dieselben Werte erzeugt.
 */
class PricingCalculator {

	const OPTION = 'lw16_kalkulation_einstellungen';

	/**
	 * Globale Annahmen mit Defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$defaults = array(
			'drucker_anschaffungspreis'   => 500,
			'drucker_lebensdauer_stunden' => 5000,
			'drucker_ersatzteile_pro_stunde' => 0.02,
			'drucker_leistung_kw'         => 0.15,
			'strompreis_pro_kwh'          => 0.35,
			'filamentpreis_pro_kg'        => 20,
			'stundenlohn'                 => 15,
			'ausschuss_prozent'           => 5,
			'zielmarge_module_prozent'    => 50,
		);
		return wp_parse_args( get_option( self::OPTION, array() ), $defaults );
	}

	/**
	 * Einstellungen speichern.
	 *
	 * @param array $values Werte.
	 */
	public static function save_settings( array $values ) {
		$clean = array();
		foreach ( self::settings() as $k => $default ) {
			$clean[ $k ] = isset( $values[ $k ] ) ? (float) $values[ $k ] : (float) $default;
		}
		update_option( self::OPTION, $clean );
	}

	/**
	 * Druckerkosten pro Stunde.
	 *
	 * @return float
	 */
	public static function drucker_kosten_pro_stunde() {
		$s = self::settings();
		$lebensdauer = (float) $s['drucker_lebensdauer_stunden'];
		$abschreibung = $lebensdauer > 0 ? (float) $s['drucker_anschaffungspreis'] / $lebensdauer : 0;
		return $abschreibung
			+ (float) $s['drucker_ersatzteile_pro_stunde']
			+ (float) $s['drucker_leistung_kw'] * (float) $s['strompreis_pro_kwh'];
	}

	/**
	 * Kosten und Preisvorschlag für ein Modul berechnen.
	 *
	 * @param object $produkt Produktdatensatz (typ = modul).
	 * @return array {kosten, vorschlag, marge}
	 */
	public static function calc_modul( $produkt ) {
		$s = self::settings();

		$filament_kosten = ( (float) $produkt->filament_gramm / 1000 ) * (float) $s['filamentpreis_pro_kg'];
		$drucker_kosten  = self::drucker_kosten_pro_stunde() * (float) $produkt->druckzeit_stunden;
		$arbeit_kosten   = ( (float) $produkt->arbeitszeit_minuten / 60 ) * (float) $s['stundenlohn'];

		$zwischensumme = $filament_kosten + $drucker_kosten + $arbeit_kosten
			+ (float) $produkt->verpackung_euro + (float) $produkt->zusatzmaterial_euro;

		$kosten = $zwischensumme * ( 1 + (float) $s['ausschuss_prozent'] / 100 );

		$zielmarge = (float) $s['zielmarge_module_prozent'] / 100;
		$vorschlag = $zielmarge < 1 ? $kosten / ( 1 - $zielmarge ) : $kosten;
		$vorschlag = round( $vorschlag ); // ganze Euro.

		return array(
			'kosten'    => round( $kosten, 2 ),
			'vorschlag' => $vorschlag,
			'marge'     => self::marge_prozent( $kosten, $vorschlag ),
		);
	}

	/**
	 * Kosten und Preisvorschlag für ein Set berechnen.
	 *
	 * @param object $produkt Produktdatensatz (typ = set).
	 * @return array {einzelsumme, vorschlag, marge, kosten}
	 */
	public static function calc_set( $produkt ) {
		$einzelsumme = 0.0;
		$kosten_summe = 0.0;
		foreach ( Repository::get_stueckliste( $produkt->id ) as $pos ) {
			$modul = Repository::get_product( $pos->modul_id );
			if ( ! $modul ) {
				continue;
			}
			$mc          = self::calc_modul( $modul );
			$modulpreis  = self::aktueller_oder_vorschlag( $modul, $mc['vorschlag'] );
			$einzelsumme += $modulpreis * (float) $pos->menge;
			$kosten_summe += $mc['kosten'] * (float) $pos->menge;
		}

		$rabatt    = (float) $produkt->rabatt_prozent / 100;
		$vorschlag = round( $einzelsumme * ( 1 - $rabatt ) );

		return array(
			'einzelsumme' => round( $einzelsumme, 2 ),
			'kosten'      => round( $kosten_summe, 2 ),
			'vorschlag'   => $vorschlag,
			'marge'       => self::marge_prozent( $kosten_summe, $vorschlag ),
		);
	}

	/**
	 * Aktuellen Shop-Preis oder – falls keiner – den Vorschlag zurückgeben.
	 *
	 * @param object $produkt   Produkt.
	 * @param float  $vorschlag Vorschlag.
	 * @return float
	 */
	public static function aktueller_oder_vorschlag( $produkt, $vorschlag ) {
		$aktuell = self::aktueller_preis( $produkt );
		return $aktuell > 0 ? $aktuell : $vorschlag;
	}

	/**
	 * Aktueller Shop-Preis eines verknüpften WooCommerce-Produkts.
	 *
	 * @param object $produkt Produkt.
	 * @return float
	 */
	public static function aktueller_preis( $produkt ) {
		if ( empty( $produkt->woo_product_id ) ) {
			return 0.0;
		}
		$wc = wc_get_product( $produkt->woo_product_id );
		return $wc ? (float) $wc->get_regular_price() : 0.0;
	}

	/**
	 * Tatsächliche Marge in Prozent.
	 *
	 * @param float $kosten Kosten.
	 * @param float $preis  Verkaufspreis.
	 * @return float
	 */
	public static function marge_prozent( $kosten, $preis ) {
		if ( $preis <= 0 ) {
			return 0.0;
		}
		return round( ( $preis - $kosten ) / $preis * 100, 2 );
	}
}
