<?php
/**
 * Quartalsziele und ihre Herunterrechnung auf andere Zeiträume.
 *
 * Geplant wird im Quartal – das ist der Zeitraum, über den sich im Betrieb
 * überhaupt etwas sagen lässt. Der Verlaufsgraph zeigt aber Tage, Wochen,
 * Monate oder Jahre, und dann muss das Ziel mitkommen.
 *
 * Der Punkt, an dem eine naive Umrechnung falsch wird:
 *
 * - **Summengrößen** (Umsatz, Bestellungen) sammeln sich an. Ein
 *   Quartalsumsatz von 30.000 € ist im August anteilig 31 von 92 Tagen –
 *   also rund 10.100 €. Geteilt wird nach Tagen, nicht durch drei: Monate
 *   sind unterschiedlich lang, und eine Woche liegt oft in zwei Monaten.
 * - **Verhältnisgrößen** (Ausschussquote, Ø-Warenkorb, Auslastung,
 *   Arbeitsstunden je Woche) sammeln sich nicht an. Eine Ausschussquote von
 *   6 % im Quartal ist auch im August 6 % und an einem einzelnen Tag 6 %.
 *   Wer sie durch drei teilt, bekommt ein Ziel von 2 %, das niemand je
 *   gesetzt hat, und einen Alarm, der jeden Monat losgeht.
 *
 * Deshalb trägt jede Kennzahl in `Kpi::definitions()` ihre Art, und hier
 * wird danach unterschieden statt nach Gefühl.
 *
 * Frei von Datenbank und WordPress-Umgebung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Ziele je Quartal, umgerechnet auf beliebige Zeiträume.
 */
class KpiGoals {

	/**
	 * Größen, die sich über die Zeit ansammeln.
	 */
	const SUM = Kpi::SUM;

	/**
	 * Größen, die vom Zeitraum unabhängig sind.
	 */
	const RATIO = Kpi::RATIO;

	/**
	 * Ziel eines Zeitraums aus den Quartalszielen.
	 *
	 * Fehlt für ein berührtes Quartal das Ziel, gibt es für den Zeitraum
	 * keines. Das ist Absicht: Ein Jahresziel aus dem einen Quartal, für das
	 * jemand etwas eingetragen hat, wäre eine erfundene Zahl – und sie sähe
	 * aus wie eine gesetzte.
	 *
	 * @param array  $quarter_goals Quartalsschlüssel => Zielwert.
	 * @param string $von           Von (Y-m-d).
	 * @param string $bis           Bis (Y-m-d).
	 * @param string $kind          self::SUM oder self::RATIO.
	 * @return float|null
	 */
	public static function derive( array $quarter_goals, $von, $bis, $kind ) {
		$quartale = Period::quarters_between( $von, $bis );

		if ( ! $quartale ) {
			return null;
		}

		$summe   = 0.0;
		$gewicht = 0;
		$treffer = false;

		foreach ( $quartale as $key ) {
			$grenzen = Period::quarter_bounds( $key );

			if ( ! $grenzen ) {
				return null;
			}

			$tage = Period::overlap( $von, $bis, $grenzen['von'], $grenzen['bis'] );

			// Ein Quartal ohne gemeinsame Tage berührt den Zeitraum nicht
			// wirklich – es fällt weg, ohne das Ziel zu verhindern.
			if ( $tage <= 0 ) {
				continue;
			}

			if ( ! isset( $quarter_goals[ $key ] ) || null === $quarter_goals[ $key ] || '' === $quarter_goals[ $key ] ) {
				return null;
			}

			$ziel    = (float) $quarter_goals[ $key ];
			$treffer = true;

			if ( self::SUM === $kind ) {
				$summe += $ziel * $tage / max( 1, Period::days( $grenzen['von'], $grenzen['bis'] ) );
			} else {
				$summe   += $ziel * $tage;
				$gewicht += $tage;
			}
		}

		if ( ! $treffer ) {
			return null;
		}

		if ( self::SUM === $kind ) {
			return $summe;
		}

		return $gewicht > 0 ? $summe / $gewicht : null;
	}

	/**
	 * Ziel- und Alarmlinie eines Eimers.
	 *
	 * @param array  $goals  Quartalsschlüssel => {target, alarm}.
	 * @param array  $bucket Eimer aus Period.
	 * @param string $kind   Art der Kennzahl.
	 * @return array {target, alarm}
	 */
	public static function for_bucket( array $goals, array $bucket, $kind ) {
		return array(
			'target' => self::derive( self::column( $goals, 'target' ), $bucket['von'], $bucket['bis'], $kind ),
			'alarm'  => self::derive( self::column( $goals, 'alarm' ), $bucket['von'], $bucket['bis'], $kind ),
		);
	}

	/**
	 * Eine Spalte aus den gespeicherten Zielen ziehen.
	 *
	 * @param array  $goals  Quartalsschlüssel => {target, alarm}.
	 * @param string $column target oder alarm.
	 * @return array
	 */
	public static function column( array $goals, $column ) {
		$out = array();

		foreach ( $goals as $key => $werte ) {
			$werte = (array) $werte;

			if ( isset( $werte[ $column ] ) && null !== $werte[ $column ] && '' !== $werte[ $column ] ) {
				$out[ $key ] = (float) $werte[ $column ];
			}
		}

		return $out;
	}

	/**
	 * Erklärung, wie ein Ziel zustande kommt – für den Bericht.
	 *
	 * @param string $kind Art der Kennzahl.
	 * @return string
	 */
	public static function explain( $kind ) {
		if ( self::SUM === $kind ) {
			return __( 'Summengröße: Das Quartalsziel wird nach Tagen auf den Zeitraum verteilt.', 'lindenweg16' );
		}

		return __( 'Verhältnisgröße: Das Quartalsziel gilt in jedem Zeitraum unverändert.', 'lindenweg16' );
	}

	/**
	 * Eingegebene Ziele prüfen.
	 *
	 * Ein leeres Feld heisst „kein Ziel“ und wird zu null – nicht zu 0.
	 * Ein Ziel von 0 wäre eine Aussage, ein leeres Feld ist keine.
	 *
	 * @param array $input Kennzahl => {target, alarm}.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$erlaubt = array_keys( Kpi::definitions() );
		$out     = array();

		foreach ( $input as $key => $werte ) {
			if ( ! in_array( (string) $key, $erlaubt, true ) ) {
				continue;
			}

			$werte = (array) $werte;
			$zeile = array();

			foreach ( array( 'target', 'alarm' ) as $spalte ) {
				$roh = isset( $werte[ $spalte ] ) ? trim( (string) $werte[ $spalte ] ) : '';

				$zeile[ $spalte ] = '' === $roh ? null : (float) str_replace( ',', '.', $roh );
			}

			if ( null !== $zeile['target'] || null !== $zeile['alarm'] ) {
				$out[ (string) $key ] = $zeile;
			}
		}

		return $out;
	}

	/**
	 * Die Art einer Kennzahl.
	 *
	 * @param string $key Kennzahl.
	 * @return string
	 */
	public static function kind( $key ) {
		$definitions = Kpi::definitions();

		if ( isset( $definitions[ $key ]['kind'] ) ) {
			return $definitions[ $key ]['kind'];
		}

		// Im Zweifel Verhältnisgröße: Eine unverändert übernommene Grenze
		// ist ein sichtbar falsches Ziel, eine geteilte ein unsichtbares.
		return self::RATIO;
	}
}
