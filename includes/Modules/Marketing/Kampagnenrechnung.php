<?php
/**
 * Reine Rechenregeln der Kampagnenauswertung – ohne WordPress und ohne DB.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Budget, Anteilsverteilung und Kennzahlen einer Kostenstelle.
 */
class Kampagnenrechnung {

	/**
	 * Anteil eines Belegs, der auf eine Kostenstelle entfällt.
	 *
	 * @param float $betrag  Belegbetrag.
	 * @param float $prozent Anteil in Prozent.
	 * @return float
	 */
	public static function anteil_betrag( $betrag, $prozent ) {
		$prozent = min( 100, max( 0, (float) $prozent ) );
		return round( (float) $betrag * $prozent / 100, 2 );
	}

	/**
	 * Noch frei verteilbarer Anteil eines Belegs.
	 *
	 * Ein Beleg darf über alle Kostenstellen zusammen nie mehr als 100 %
	 * ergeben – sonst stünde dieselbe Ausgabe in zwei Auswertungen doppelt.
	 *
	 * @param float $bereits_zugeordnet Summe der schon vergebenen Prozente.
	 * @return float
	 */
	public static function freier_anteil( $bereits_zugeordnet ) {
		return round( max( 0, 100 - max( 0, (float) $bereits_zugeordnet ) ), 3 );
	}

	/**
	 * Gewünschten Anteil auf den noch freien Rest begrenzen.
	 *
	 * @param float $bereits_zugeordnet Summe der schon vergebenen Prozente.
	 * @param float $wunsch             Gewünschter Anteil in Prozent.
	 * @return float Zulässiger Anteil, 0 wenn nichts mehr frei ist.
	 */
	public static function zulaessiger_anteil( $bereits_zugeordnet, $wunsch ) {
		$frei   = self::freier_anteil( $bereits_zugeordnet );
		$wunsch = max( 0, (float) $wunsch );
		return round( min( $frei, $wunsch ), 3 );
	}

	/**
	 * Budgetstand einer Kostenstelle.
	 *
	 * @param float $budget Geplantes Budget.
	 * @param float $ist    Bereits gebuchte Kosten.
	 * @return array {budget, ist, rest, auslastung, ampel}
	 */
	public static function budget_status( $budget, $ist ) {
		$budget = max( 0, (float) $budget );
		$ist    = max( 0, (float) $ist );
		$rest   = round( $budget - $ist, 2 );

		if ( $budget <= 0 ) {
			// Ohne Budget gibt es keine Auslastung – nur Kosten.
			return array(
				'budget'     => 0.0,
				'ist'        => $ist,
				'rest'       => 0.0,
				'auslastung' => null,
				'ampel'      => 'kein_budget',
			);
		}

		$auslastung = round( $ist / $budget * 100, 1 );

		if ( $auslastung > 100 ) {
			$ampel = 'ueberzogen';
		} elseif ( $auslastung >= 80 ) {
			$ampel = 'knapp';
		} else {
			$ampel = 'im_rahmen';
		}

		return array(
			'budget'     => $budget,
			'ist'        => $ist,
			'rest'       => $rest,
			'auslastung' => $auslastung,
			'ampel'      => $ampel,
		);
	}

	/**
	 * Umsatz je eingesetztem Euro (ROAS).
	 *
	 * @param float $umsatz Zugeordneter Umsatz.
	 * @param float $kosten Zugeordnete Kosten.
	 * @return float|null null, wenn keine Kosten gebucht sind.
	 */
	public static function roas( $umsatz, $kosten ) {
		$kosten = (float) $kosten;
		if ( $kosten <= 0 ) {
			return null;
		}
		return round( (float) $umsatz / $kosten, 2 );
	}

	/**
	 * Kosten je zugeordneter Bestellung.
	 *
	 * @param float $kosten       Zugeordnete Kosten.
	 * @param int   $bestellungen Anzahl Bestellungen.
	 * @return float|null null ohne Bestellungen.
	 */
	public static function kosten_pro_bestellung( $kosten, $bestellungen ) {
		$bestellungen = (int) $bestellungen;
		if ( $bestellungen <= 0 ) {
			return null;
		}
		return round( (float) $kosten / $bestellungen, 2 );
	}

	/**
	 * Rohertrag der Kostenstelle: Umsatz minus Kosten.
	 *
	 * Bewusst ohne Wareneinsatz – der hängt am Artikel, nicht an der
	 * Kampagne. Wer den Deckungsbeitrag will, zieht ihn zusätzlich ab.
	 *
	 * @param float $umsatz Zugeordneter Umsatz.
	 * @param float $kosten Zugeordnete Kosten.
	 * @return float
	 */
	public static function ergebnis( $umsatz, $kosten ) {
		return round( (float) $umsatz - (float) $kosten, 2 );
	}
}
