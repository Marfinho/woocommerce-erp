<?php
/**
 * Stufen des Prämienprogramms: mehr Punkte für treue Kunden.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Loyalty;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Speichert die Stufen (Name, Schwelle, Faktor) und ermittelt die Stufe eines
 * Kunden aus dem Umsatz abgeschlossener Bestellungen der letzten 12 Monate.
 * Die Regeln stehen in LoyaltyRules.
 */
class LoyaltyTiers {

	const OPTION = 'lw16_loyalty_stufen';

	/** Zwischengespeicherter Umsatz (Betrag und Zeitpunkt) am Kunden. */
	const META_UMSATZ = 'lw16_umsatz_12m';

	/** So lange gilt der zwischengespeicherte Umsatz. */
	const CACHE_SEK = 21600;

	/**
	 * Sind Stufen eingeschaltet?
	 *
	 * @return bool
	 */
	public static function active() {
		return (bool) (int) Settings::get( 'stufen_aktiv', 0 );
	}

	/**
	 * Gespeicherte Stufen, sonst die Vorschläge.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( is_array( $saved ) && $saved ) {
			$check = LoyaltyRules::sanitize_tiers( $saved );
			if ( $check['tiers'] ) {
				return $check['tiers'];
			}
		}
		return LoyaltyRules::default_tiers();
	}

	/**
	 * Geprüfte Stufen speichern.
	 *
	 * @param array $tiers Ergebnis von LoyaltyRules::sanitize_tiers().
	 */
	public static function save( array $tiers ) {
		update_option( self::OPTION, array_values( $tiers ), false );
	}

	/**
	 * Umsatz abgeschlossener Bestellungen der letzten 12 Monate (abzüglich
	 * Erstattungen).
	 *
	 * @param int  $user_id Kunde.
	 * @param int  $exclude Bestellung, die nicht mitzählt (die gerade abgeschlossene).
	 * @param bool $fresh   Zwischenspeicher übergehen.
	 * @return float
	 */
	public static function revenue( $user_id, $exclude = 0, $fresh = false ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! function_exists( 'wc_get_orders' ) ) {
			return 0.0;
		}
		$cache = get_user_meta( $user_id, self::META_UMSATZ, true );
		if ( ! $fresh && ! $exclude && is_array( $cache ) && time() - (int) $cache['ts'] < self::CACHE_SEK ) {
			return (float) $cache['sum'];
		}

		$orders = wc_get_orders(
			array(
				'customer_id'  => $user_id,
				'status'       => array( 'wc-completed' ),
				'date_created' => '>' . ( time() - YEAR_IN_SECONDS ),
				'limit'        => -1,
			)
		);
		$sum = 0.0;
		foreach ( $orders as $order ) {
			if ( (int) $order->get_id() === (int) $exclude ) {
				continue;
			}
			$sum += (float) $order->get_total() - (float) $order->get_total_refunded();
		}
		$sum = round( max( 0, $sum ), 2 );
		if ( ! $exclude ) {
			update_user_meta( $user_id, self::META_UMSATZ, array( 'sum' => $sum, 'ts' => time() ) );
		}
		return $sum;
	}

	/**
	 * Stufe eines Kunden (null, wenn Stufen aus sind).
	 *
	 * @param int $user_id Kunde.
	 * @param int $exclude Bestellung, die nicht mitzählt.
	 * @return array|null Wie LoyaltyRules::tier_for(), dazu revenue.
	 */
	public static function for_user( $user_id, $exclude = 0 ) {
		if ( ! self::active() || ! $user_id ) {
			return null;
		}
		$revenue        = self::revenue( $user_id, $exclude );
		$info           = LoyaltyRules::tier_for( $revenue, self::all() );
		$info['revenue'] = $revenue;
		return $info;
	}

	/**
	 * Faktor der Stufe (1, wenn Stufen aus sind).
	 *
	 * @param int $user_id Kunde.
	 * @param int $exclude Bestellung, die nicht mitzählt.
	 * @return float
	 */
	public static function factor( $user_id, $exclude = 0 ) {
		$info = self::for_user( $user_id, $exclude );
		return $info ? (float) $info['tier']['faktor'] : 1.0;
	}

	/**
	 * Zwischengespeicherten Umsatz verwerfen (nach Abschluss oder Storno).
	 *
	 * @param int $user_id Kunde.
	 */
	public static function forget( $user_id ) {
		delete_user_meta( (int) $user_id, self::META_UMSATZ );
	}
}
