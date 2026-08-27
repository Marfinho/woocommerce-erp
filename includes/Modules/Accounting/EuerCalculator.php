<?php
/**
 * EÜR-Berechnung: Einnahmen aus WooCommerce, Ausgaben aus Belegen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

/**
 * Berechnet Einnahmen (abzgl. Refunds) und Ausgaben für einen Zeitraum.
 * Einnahmen werden zur Laufzeit aus WooCommerce ermittelt, nie manuell erfasst.
 */
class EuerCalculator {

	/**
	 * Wie viele Bestellungen gleichzeitig im Speicher stehen dürfen.
	 */
	const STAPEL = 500;

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

		// Bewusst seitenweise: Ein Jahresabschluss holte sonst jede
		// abgeschlossene Bestellung des Zeitraums gleichzeitig als
		// vollständiges Objekt in den Speicher – samt Positionen und
		// Metadaten. Das hält so lange, bis der Shop groß genug ist, und
		// scheitert dann ausgerechnet beim Jahresabschluss. Gerechnet wird
		// unverändert Zeile für Zeile, nur nie mehr als ein Stapel auf
		// einmal.
		$seite = 1;

		do {
			$orders = wc_get_orders(
				array(
					'status'       => array( 'wc-completed' ),
					'date_created' => $from . '...' . $to,
					'limit'        => self::STAPEL,
					'paged'        => $seite,
					'orderby'      => 'ID',
					'order'        => 'ASC',
					'return'       => 'objects',
				)
			);

			foreach ( $orders as $order ) {
				// Bruttosumme abzüglich bereits erfasster Rückerstattungen.
				$summe += (float) $order->get_total() - (float) $order->get_total_refunded();
			}

			$seite++;
		} while ( count( $orders ) === self::STAPEL );

		set_transient( $cache_key, $summe, 30 * MINUTE_IN_SECONDS );
		return $summe;
	}

	/**
	 * Vollständige EÜR-Übersicht für einen Zeitraum.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array {einnahmen, ausgaben_kategorien, ausgaben_summe, gewinn}
	 */
	public static function report( $from, $to ) {
		$einnahmen = self::einnahmen( $from, $to );
		$ausgaben  = BelegRepository::sum_by_category( $from, $to );
		$ausg_sum  = array_sum( $ausgaben );

		return array(
			'from'                 => $from,
			'to'                   => $to,
			'einnahmen'            => $einnahmen,
			'ausgaben_kategorien'  => $ausgaben,
			'ausgaben_summe'       => $ausg_sum,
			'gewinn'               => $einnahmen - $ausg_sum,
		);
	}

	/**
	 * Steuer-Rücklage-Empfehlung für das aktuelle Kalenderjahr.
	 *
	 * @param float $steuersatz_prozent Geschätzter Steuersatz.
	 * @return array {gewinn, ruecklage}
	 */
	public static function ruecklage( $steuersatz_prozent ) {
		$jahr = (int) gmdate( 'Y' );
		$rep  = self::report( $jahr . '-01-01', $jahr . '-12-31' );
		$gewinn = max( 0, $rep['gewinn'] );
		return array(
			'gewinn'    => $gewinn,
			'ruecklage' => \Lindenweg16\Core\Calc::ruecklage( $gewinn, $steuersatz_prozent ),
		);
	}
}
