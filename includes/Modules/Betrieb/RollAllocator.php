<?php
/**
 * Verbrauch einer Charge auf ihre Rollen verteilen - reine Rechnung.
 *
 * Die Druckjobs buchen Filament auf eine Charge, nicht auf eine Rolle: Wer
 * druckt, wählt die Charge, die Rolle dazu steckt im Drucker. Damit sich der
 * Bestand trotzdem je Rolle führen lässt, wird der Verbrauch der Charge der
 * Reihe nach auf ihre Rollen angerechnet - zuerst die Rollen im Drucker (die
 * angebrochenen), dann die neuen.
 *
 * Eine **leer gescannte** Rolle ist die Wahrheit, an der sich die Rechnung
 * korrigiert: Sie hat tatsächlich nichts mehr. Was die Rechnung ihr noch
 * zugeschrieben hatte, ist Schwund (Spülen, Fehldrucke, Stützen) und fällt
 * aus dem Bestand. Der Anteil des Verbrauchs, den die Rolle bis dahin
 * aufgenommen hat, wird eingefroren, damit spätere Rechnungen die Rolle
 * nicht noch einmal belasten.
 *
 * Eine **gewogene** Rolle bekommt eine Korrektur in Gramm: Danach stimmt ihr
 * Restgewicht mit der Waage überein, und weiterer Verbrauch zählt davon ab.
 *
 * Ohne Datenbank und WordPress.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Anrechnung des Verbrauchs auf Rollen.
 */
class RollAllocator {

	const STATUS_NEW    = 'neu';
	const STATUS_IN_USE = 'in_benutzung';
	const STATUS_EMPTY  = 'leer';

	/**
	 * Gewichte der Rollen einer Charge - reine Regel.
	 *
	 * @param float $kg_received Gelieferte Menge in Kilogramm.
	 * @param float $roll_g      Gewicht je Rolle in Gramm (0 = eine einzige Rolle).
	 * @return int[] Gramm je Rolle.
	 */
	public static function split( $kg_received, $roll_g ) {
		$total = (int) round( (float) $kg_received * 1000 );

		if ( $total <= 0 ) {
			return array();
		}

		$roll_g = (int) $roll_g;
		if ( $roll_g <= 0 || $roll_g >= $total ) {
			return array( $total );
		}

		$out  = array_fill( 0, intdiv( $total, $roll_g ), $roll_g );
		$rest = $total % $roll_g;

		if ( $rest > 0 ) {
			$out[] = $rest;
		}

		return $out;
	}

	/**
	 * Verbrauch der Charge auf die Rollen anrechnen.
	 *
	 * @param array $rolls          Rollen: id, status, start_g, corr_g, consumed_g_at_close, opened_ts.
	 * @param float $consumed_total Verbrauch der Charge in Gramm (aus den Druckjobs).
	 * @return array id => {absorbed_g, remaining_g}
	 */
	public static function allocate( array $rolls, $consumed_total ) {
		$out    = array();
		$frozen = 0.0;
		$open   = array();

		foreach ( $rolls as $roll ) {
			if ( self::STATUS_EMPTY === $roll['status'] ) {
				$absorbed             = (float) $roll['consumed_g_at_close'];
				$frozen              += $absorbed;
				$out[ (int) $roll['id'] ] = array( 'absorbed_g' => round( $absorbed, 1 ), 'remaining_g' => 0.0 );
				continue;
			}

			$open[] = $roll;
		}

		// Angebrochene zuerst (die im Drucker stecken), dann neue; jeweils die ältere zuerst.
		usort(
			$open,
			static function ( $a, $b ) {
				$ra = self::STATUS_IN_USE === $a['status'] ? 0 : 1;
				$rb = self::STATUS_IN_USE === $b['status'] ? 0 : 1;

				if ( $ra !== $rb ) {
					return $ra <=> $rb;
				}

				$ta = isset( $a['opened_ts'] ) ? (int) $a['opened_ts'] : 0;
				$tb = isset( $b['opened_ts'] ) ? (int) $b['opened_ts'] : 0;

				return $ta !== $tb ? $ta <=> $tb : (int) $a['id'] <=> (int) $b['id'];
			}
		);

		$rest = max( 0.0, (float) $consumed_total - $frozen );

		foreach ( $open as $roll ) {
			$cap  = max( 0.0, (float) $roll['start_g'] - (float) $roll['corr_g'] );
			$take = min( $rest, $cap );
			$rest -= $take;

			$out[ (int) $roll['id'] ] = array(
				'absorbed_g'  => round( $take, 1 ),
				'remaining_g' => round( $cap - $take, 1 ),
			);
		}

		return $out;
	}

	/**
	 * Restbestand einer Charge in Gramm: Summe der Rollen, die nicht leer sind.
	 *
	 * @param array $allocation Ergebnis von allocate().
	 * @return float
	 */
	public static function remaining_g( array $allocation ) {
		$sum = 0.0;

		foreach ( $allocation as $row ) {
			$sum += (float) $row['remaining_g'];
		}

		return round( $sum, 1 );
	}

	/**
	 * Korrektur, damit das Restgewicht der Rolle der Waage entspricht.
	 *
	 * @param float $estimated_g Rechnerisches Restgewicht.
	 * @param float $gross_g     Auf der Waage: Rolle samt Spule.
	 * @param float $tare_g      Gewicht der leeren Spule.
	 * @return array {measured_g, delta_g}
	 */
	public static function measurement( $estimated_g, $gross_g, $tare_g ) {
		$measured = max( 0.0, (float) $gross_g - max( 0.0, (float) $tare_g ) );

		return array(
			'measured_g' => round( $measured, 1 ),
			// Positiv: Es war weniger drauf als gerechnet, die Rolle gilt als stärker verbraucht.
			'delta_g'    => round( (float) $estimated_g - $measured, 1 ),
		);
	}

	/**
	 * Wie auffällig ist der Schwund beim Leermelden? Reine Regel.
	 *
	 * Eine Rolle, die rechnerisch noch zu einem Viertel voll war und leer ist,
	 * verbraucht im Betrieb mehr, als die Druckjobs ausweisen: Ausschuss wird
	 * nicht gemeldet, das Bauteilgewicht der Revision stimmt nicht, oder die
	 * Spülmenge fehlt.
	 *
	 * @param float $written_off_g Rechnerisch noch vorhanden beim Leermelden.
	 * @param float $start_g       Startgewicht.
	 * @return string normal oder attention
	 */
	public static function shrinkage_level( $written_off_g, $start_g ) {
		return $start_g > 0 && $written_off_g / $start_g >= 0.15 ? 'attention' : 'normal';
	}
}
