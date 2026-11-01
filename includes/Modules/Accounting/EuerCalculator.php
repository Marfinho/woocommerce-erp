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
	 * Einnahmen aus Teilen zusammensetzen – reine Regel.
	 *
	 * @param float $shop      Shop-Bestellungen (abgeschlossen, abzgl. Erstattungen).
	 * @param float $auftraege Bezahlte Aufträge ohne Shop-Bestellung (Zufluss am Zahltag).
	 * @param float $sonstige  Sonstige Einnahmen aus dem Journal (Mahngebühren, Verzugszinsen).
	 * @return array {shop, auftraege, sonstige, summe}
	 */
	public static function income_parts( $shop, $auftraege, $sonstige ) {
		$shop      = round( (float) $shop, 2 );
		$auftraege = round( (float) $auftraege, 2 );
		$sonstige  = round( (float) $sonstige, 2 );

		return array( 'shop' => $shop, 'auftraege' => $auftraege, 'sonstige' => $sonstige, 'summe' => round( $shop + $auftraege + $sonstige, 2 ) );
	}

	/**
	 * Bezahlte Aufträge ohne Shop-Bestellung (Fachhandel, aus Angeboten), nach Zahltag.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return float
	 */
	public static function einnahmen_auftraege( $from, $to ) {
		global $wpdb;

		$t = $wpdb->prefix . 'lw16_order_ops';

		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(manual_order_value), 0) FROM {$t} WHERE wc_order_id IS NULL AND invoice_status = 'bezahlt' AND order_status <> 'storniert' AND paid_date >= %s AND paid_date <= %s", $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Sonstige Einnahmen aus dem Journal: Mahngebühren und Verzugszinsen, abzüglich Stornos.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return float
	 */
	public static function einnahmen_sonstige( $from, $to ) {
		global $wpdb;

		$t    = BuchungRepository::table();
		$plus = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(betrag), 0) FROM {$t} WHERE typ = 'einnahme' AND referenz_typ = 'mahnung' AND buchungsdatum >= %s AND buchungsdatum <= %s", $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$back = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(s.betrag), 0) FROM {$t} s JOIN {$t} o ON o.id = s.storno_von_id WHERE s.typ = 'storno' AND o.referenz_typ = 'mahnung' AND s.buchungsdatum >= %s AND s.buchungsdatum <= %s", $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $plus - $back;
	}

	/**
	 * Vollständige EÜR-Übersicht für einen Zeitraum.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array {einnahmen, ausgaben_kategorien, ausgaben_summe, gewinn}
	 */
	public static function report( $from, $to ) {
		$parts     = self::income_parts( self::einnahmen( $from, $to ), self::einnahmen_auftraege( $from, $to ), self::einnahmen_sonstige( $from, $to ) );
		$einnahmen = $parts['summe'];
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
			'einnahmen_shop'       => $parts['shop'],
			'einnahmen_auftraege'  => $parts['auftraege'],
			'einnahmen_sonstige'   => $parts['sonstige'],
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
