<?php
/**
 * EÜR-Berechnung: Einnahmen aus WooCommerce, Ausgaben aus Belegen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Time;

/**
 * Berechnet Einnahmen (abzgl. Refunds) und Ausgaben für einen Zeitraum.
 * Einnahmen werden zur Laufzeit aus WooCommerce ermittelt, nie manuell erfasst.
 */
class EuerCalculator {

	/**
	 * Netto-Einnahmen (abgeschlossene Bestellungen abzgl. Rückerstattungen)
	 * für den Zeitraum berechnen. Ergebnis wird per Transient gecacht.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return float
	 */
	public static function einnahmen( $from, $to ) {
		$cache_key = 'lw16_einnahmen_' . md5( $from . '|' . $to );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (float) $cached;
		}

		$summe = 0.0;

		$orders = wc_get_orders(
			array(
				'status'       => array( 'wc-completed' ),
				'date_created' => $from . '...' . $to,
				'limit'        => -1,
				'return'       => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			// Bruttosumme abzüglich bereits erfasster Rückerstattungen.
			$summe += (float) $order->get_total() - (float) $order->get_total_refunded();
		}

		set_transient( $cache_key, $summe, 30 * MINUTE_IN_SECONDS );
		return $summe;
	}

	/**
	 * Vollständige EÜR-Übersicht für einen Zeitraum.
	 *
	 * Einnahmen setzen sich aus zwei Quellen zusammen: den WooCommerce-
	 * Bestellungen und den Einnahmebelegen (Tagesabschlüsse der Marktkasse).
	 * Letztere wurden vorher als Ausgabe behandelt und damit vom Gewinn
	 * abgezogen statt addiert.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array Kennzahlen des Zeitraums.
	 */
	public static function report( $from, $to ) {
		$einnahmen_shop  = self::einnahmen( $from, $to );
		$einnahmen_beleg = BelegRepository::sum_einnahmen( $from, $to );
		$einnahmen       = $einnahmen_shop + $einnahmen_beleg;

		$ausgaben = BelegRepository::sum_by_category( $from, $to, 'ausgabe' );
		$ausg_sum = array_sum( $ausgaben );

		return array(
			'from'                 => $from,
			'to'                   => $to,
			'einnahmen'            => $einnahmen,
			'einnahmen_shop'       => $einnahmen_shop,
			'einnahmen_beleg'      => $einnahmen_beleg,
			'ausgaben_kategorien'  => $ausgaben,
			'ausgaben_summe'       => $ausg_sum,
			'gewinn'               => $einnahmen - $ausg_sum,
			'belege_offen'         => BelegRepository::count_offen( $from, $to ),
		);
	}

	/**
	 * Steuer-Rücklage-Empfehlung für das aktuelle Kalenderjahr.
	 *
	 * @param float $steuersatz_prozent Geschätzter Steuersatz.
	 * @return array {gewinn, ruecklage}
	 */
	public static function ruecklage( $steuersatz_prozent ) {
		$jahr = Time::year();
		$rep  = self::report( $jahr . '-01-01', $jahr . '-12-31' );
		$gewinn = max( 0, $rep['gewinn'] );
		return array(
			'gewinn'    => $gewinn,
			'ruecklage' => \Lindenweg16\Core\Calc::ruecklage( $gewinn, $steuersatz_prozent ),
		);
	}
}
