<?php
/**
 * EÜR-Berechnung: Einnahmen aus WooCommerce, Ausgaben aus Belegen.
 *
 * Gegliedert wird nach dem Kontenrahmen (SKR03), nicht nach Kostenstellen:
 * Die Kostenstelle sagt, wofür etwas ausgegeben wurde, das Konto, was für
 * eine Ausgabe es war – und nur Letzteres interessiert das Finanzamt.
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
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array {einnahmen, ausgaben_kategorien, ausgaben_summe, gewinn}
	 */
	public static function report( $from, $to ) {
		$einnahmen = self::einnahmen( $from, $to );
		$ausgaben  = BelegRepository::sum_by_category( $from, $to );
		$konten    = BelegRepository::sum_by_konto( $from, $to );

		// Maßgeblich für den Gewinn ist der abziehbare Anteil, nicht der
		// Belegbetrag: Bewirtung zählt nur zu 70 %.
		$brutto    = 0.0;
		$abziehbar = 0.0;
		$gruppen   = array();

		foreach ( $konten as $konto => $summen ) {
			$brutto    += $summen['brutto'];
			$abziehbar += $summen['abziehbar'];

			$gruppe = Kontenrahmen::gruppe( $konto );
			if ( ! isset( $gruppen[ $gruppe ] ) ) {
				$gruppen[ $gruppe ] = 0.0;
			}
			$gruppen[ $gruppe ] += $summen['abziehbar'];
		}

		$abziehbar = round( $abziehbar, 2 );

		return array(
			'from'                 => $from,
			'to'                   => $to,
			'einnahmen'            => $einnahmen,
			'ausgaben_kategorien'  => $ausgaben,
			'ausgaben_konten'      => $konten,
			'ausgaben_gruppen'     => $gruppen,
			'ausgaben_brutto'      => round( $brutto, 2 ),
			'ausgaben_kuerzung'    => round( $brutto - $abziehbar, 2 ),
			'ausgaben_summe'       => $abziehbar,
			'gewinn'               => round( $einnahmen - $abziehbar, 2 ),
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
