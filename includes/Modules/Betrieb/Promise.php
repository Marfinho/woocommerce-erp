<?php
/**
 * Lieferzusage: Wann kann ich liefern?
 *
 * Beantwortet die Frage eines Kunden oder Händlers, ohne dass jemand erst
 * den Plan durchrechnen muss. Was im Lager liegt, geht sofort. Für den Rest
 * zählt, was vorher noch in der Warteschlange steht und wie viel Druck- und
 * Handarbeitszeit die Woche hergibt - die knappere von beiden bestimmt.
 *
 * Als Spanne, nicht als Punkt: Die Kapazität einer Woche wird nie ganz
 * erreicht. Das frühe Datum rechnet mit voller, das späte mit 70 % der
 * Kapazität. Wer ein Datum zusagt, nennt das späte.
 *
 * Reine Rechnung ohne Datenbank.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Frühester Liefertermin.
 */
class Promise {

	/**
	 * Wirksame Kapazität im ungünstigen Fall.
	 */
	const EFFICIENCY_LOW = 0.7;

	/**
	 * Tage für Verpackung und Versand nach der Fertigung.
	 */
	const SHIPPING_DAYS = 2;

	/**
	 * Frühester Termin für eine Menge.
	 *
	 * @param float $in_stock          Sofort verfügbar (Lager minus Reservierungen).
	 * @param float $qty               Gewünschte Menge.
	 * @param float $machine_h_each    Druckstunden je Stück.
	 * @param float $operator_h_each   Handarbeitsstunden je Stück.
	 * @param float $backlog_machine_h Druckstunden, die vorher schon anstehen.
	 * @param float $backlog_operator_h Handarbeitsstunden, die vorher schon anstehen.
	 * @param float $machine_cap       Druckstunden je Woche.
	 * @param float $operator_cap      Handarbeitsstunden je Woche.
	 * @param int   $today             Zeitstempel von heute.
	 * @return array {immediate, missing, weeks, date_from, date_to, limiting}
	 */
	public static function earliest( $in_stock, $qty, $machine_h_each, $operator_h_each, $backlog_machine_h, $backlog_operator_h, $machine_cap, $operator_cap, $today ) {
		$missing = max( 0.0, (float) $qty - max( 0.0, (float) $in_stock ) );

		if ( $missing <= 0.0001 ) {
			return array(
				'immediate' => true,
				'missing'   => 0.0,
				'weeks'     => 0.0,
				'date_from' => gmdate( 'Y-m-d', $today ),
				'date_to'   => gmdate( 'Y-m-d', $today ),
				'limiting'  => '',
			);
		}

		$need_m = $missing * max( 0.0, (float) $machine_h_each ) + max( 0.0, (float) $backlog_machine_h );
		$need_o = $missing * max( 0.0, (float) $operator_h_each ) + max( 0.0, (float) $backlog_operator_h );

		if ( ( $need_m > 0 && $machine_cap <= 0 ) || ( $need_o > 0 && $operator_cap <= 0 ) ) {
			return array(
				'immediate' => false,
				'missing'   => $missing,
				'weeks'     => null,
				'date_from' => null,
				'date_to'   => null,
				'limiting'  => 'unbekannt',
			);
		}

		$weeks_m = $machine_cap > 0 ? $need_m / $machine_cap : 0.0;
		$weeks_o = $operator_cap > 0 ? $need_o / $operator_cap : 0.0;
		$weeks   = max( $weeks_m, $weeks_o );

		return array(
			'immediate' => false,
			'missing'   => $missing,
			'weeks'     => round( $weeks, 2 ),
			'date_from' => gmdate( 'Y-m-d', $today + ( (int) ceil( $weeks * 7 ) + self::SHIPPING_DAYS ) * 86400 ),
			'date_to'   => gmdate( 'Y-m-d', $today + ( (int) ceil( $weeks / self::EFFICIENCY_LOW * 7 ) + self::SHIPPING_DAYS ) * 86400 ),
			'limiting'  => $weeks_m >= $weeks_o ? 'druckzeit' : 'arbeitszeit',
		);
	}

	/**
	 * Kurztext für die Produktseite im Shop - reine Regel.
	 *
	 * @param int        $available Sofort verfügbar.
	 * @param array|null $estimate  Ergebnis von earliest() für eine Einheit.
	 * @param int        $low       Bis zu dieser Menge heißt es „nur noch“.
	 * @return string
	 */
	public static function shop_text( $available, $estimate, $low = 3 ) {
		if ( $available > $low ) {
			return __( 'Auf Lager', 'lindenweg16' );
		}

		if ( $available > 0 ) {
			/* translators: %d: Menge. */
			return sprintf( __( 'Nur noch %d auf Lager', 'lindenweg16' ), $available );
		}

		if ( is_array( $estimate ) && ! empty( $estimate['date_to'] ) && null !== $estimate['weeks'] ) {
			$days = (int) ceil( $estimate['weeks'] / self::EFFICIENCY_LOW * 7 ) + self::SHIPPING_DAYS;

			/* translators: %d: Tage. */
			return sprintf( __( 'Zurzeit nicht auf Lager - lieferbar in ca. %d Tagen', 'lindenweg16' ), max( 1, $days ) );
		}

		return __( 'Zurzeit nicht auf Lager', 'lindenweg16' );
	}
}
