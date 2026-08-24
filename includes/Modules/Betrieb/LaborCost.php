<?php
/**
 * Stundenlöhne und die Lohnkosten der erfassten Arbeit.
 *
 * Ein Lohn gilt ab einem Stichtag und wird nicht rückwirkend geändert –
 * genauso wie die Kalkulationsgrundlagen. Wer im Mai 14 € bekam und im Juli
 * 15 €, dessen Maiarbeit hat 14 € gekostet. Eine Erhöhung, die alte Zahlen
 * mit verändert, macht jeden Vorjahresvergleich wertlos.
 *
 * Wichtig für das Verständnis der Zahlen: Diese Klasse rechnet die
 * **Ist-Kosten** der tatsächlich erfassten Arbeit. Sie ersetzt nicht den
 * kalkulatorischen Stundensatz aus den Einstellungen, mit dem die Preise
 * gerechnet werden. Beides nebeneinander zu haben ist der eigentliche
 * Nutzen: Der kalkulatorische Satz sagt, womit man gerechnet hat, der
 * Ist-Satz, was es geworden ist.
 *
 * Frei von Datenbank und WordPress.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Reine Lohnrechnung.
 */
class LaborCost {

	/**
	 * Den zum Stichtag gültigen Lohn eines Nutzers finden.
	 *
	 * @param array  $rows     Lohnzeilen mit user_id, rate_per_h, valid_from.
	 * @param int    $user_id  Nutzer.
	 * @param string $stichtag Datum als Y-m-d.
	 * @return float|null Null, wenn für diesen Tag kein Lohn hinterlegt ist.
	 */
	public static function rate_at( array $rows, $user_id, $stichtag ) {
		$user_id  = (int) $user_id;
		$stichtag = substr( (string) $stichtag, 0, 10 );

		$best = '';
		$rate = null;

		foreach ( $rows as $row ) {
			$row = (array) $row;

			if ( (int) $row['user_id'] !== $user_id ) {
				continue;
			}

			$from = substr( (string) $row['valid_from'], 0, 10 );

			if ( '' === $from || $from > $stichtag || $from < $best ) {
				continue;
			}

			$best = $from;
			$rate = (float) $row['rate_per_h'];
		}

		return $rate;
	}

	/**
	 * Kosten einer einzelnen Zeitbuchung.
	 *
	 * @param array $entry Eintrag mit hours, entry_date, created_by.
	 * @param array $rows  Lohnzeilen.
	 * @return float|null Null, wenn kein Lohn hinterlegt ist.
	 */
	public static function entry_cost( array $entry, array $rows ) {
		$rate = self::rate_at(
			$rows,
			isset( $entry['created_by'] ) ? (int) $entry['created_by'] : 0,
			isset( $entry['entry_date'] ) ? $entry['entry_date'] : ''
		);

		if ( null === $rate ) {
			return null;
		}

		return $rate * (float) $entry['hours'];
	}

	/**
	 * Summe über viele Zeitbuchungen.
	 *
	 * Stunden ohne hinterlegten Lohn werden getrennt gezählt, statt sie mit
	 * null zu bewerten: Eine Lohnsumme, in der die halbe Belegschaft mit 0 €
	 * steckt, sieht wie ein günstiger Betrieb aus und ist eine Lücke.
	 *
	 * @param array $entries Zeitbuchungen.
	 * @param array $rows    Lohnzeilen.
	 * @return array {cost, hours, hours_unpriced, rate}
	 */
	public static function total( array $entries, array $rows ) {
		$cost      = 0.0;
		$hours     = 0.0;
		$unpriced  = 0.0;

		foreach ( $entries as $entry ) {
			$entry = (array) $entry;
			$h     = (float) $entry['hours'];
			$einze = self::entry_cost( $entry, $rows );

			if ( null === $einze ) {
				$unpriced += $h;
				continue;
			}

			$cost  += $einze;
			$hours += $h;
		}

		return array(
			'cost'           => $cost,
			'hours'          => $hours,
			'hours_unpriced' => $unpriced,
			'rate'           => $hours > 0 ? $cost / $hours : null,
		);
	}

	/**
	 * Lohnkosten je Nutzer.
	 *
	 * @param array $entries Zeitbuchungen.
	 * @param array $rows    Lohnzeilen.
	 * @return array user_id => {cost, hours, hours_unpriced, rate}
	 */
	public static function by_user( array $entries, array $rows ) {
		$grouped = array();

		foreach ( $entries as $entry ) {
			$entry = (array) $entry;
			$user  = isset( $entry['created_by'] ) ? (int) $entry['created_by'] : 0;

			$grouped[ $user ][] = $entry;
		}

		$out = array();
		foreach ( $grouped as $user => $rows_of_user ) {
			$out[ $user ] = self::total( $rows_of_user, $rows );
		}

		return $out;
	}

	/**
	 * Lohnkosten je Fertigungsauftrag.
	 *
	 * @param array $entries Zeitbuchungen mit work_order_id.
	 * @param array $rows    Lohnzeilen.
	 * @return array work_order_id => {cost, hours, hours_unpriced, rate}
	 */
	public static function by_work_order( array $entries, array $rows ) {
		$grouped = array();

		foreach ( $entries as $entry ) {
			$entry = (array) $entry;
			$order = isset( $entry['work_order_id'] ) ? (int) $entry['work_order_id'] : 0;

			if ( ! $order ) {
				continue;
			}

			$grouped[ $order ][] = $entry;
		}

		$out = array();
		foreach ( $grouped as $order => $rows_of_order ) {
			$out[ $order ] = self::total( $rows_of_order, $rows );
		}

		return $out;
	}

	/**
	 * Der tatsächliche Mischsatz gegen den kalkulatorischen Stundensatz.
	 *
	 * Das ist die Zahl, die entscheidet, ob die Kalkulation noch stimmt.
	 * Bewusst als Vergleich und nicht als automatische Übernahme: Ein
	 * Stundensatz, der sich jeden Monat von selbst verschiebt, verschiebt
	 * jeden Preis mit – das gehört entschieden, nicht errechnet.
	 *
	 * @param float|null $actual     Tatsächlicher Mischsatz.
	 * @param float      $calculated Kalkulatorischer Stundensatz.
	 * @return array {known, actual, calculated, diff, diff_pct, level}
	 */
	public static function compare( $actual, $calculated ) {
		$calculated = (float) $calculated;

		if ( null === $actual || $calculated <= 0 ) {
			return array(
				'known'      => false,
				'actual'     => $actual,
				'calculated' => $calculated,
				'diff'       => null,
				'diff_pct'   => null,
				'level'      => 'neutral',
			);
		}

		$actual = (float) $actual;
		$diff   = $actual - $calculated;
		$pct    = $diff / $calculated;

		// Bis zehn Prozent Abweichung ist ein Stundensatz eine Annahme und
		// keine Fehlkalkulation.
		$level = 'gruen';
		if ( abs( $pct ) > 0.25 ) {
			$level = 'rot';
		} elseif ( abs( $pct ) > 0.10 ) {
			$level = 'gelb';
		}

		return array(
			'known'      => true,
			'actual'     => $actual,
			'calculated' => $calculated,
			'diff'       => $diff,
			'diff_pct'   => $pct,
			'level'      => $level,
		);
	}
}
