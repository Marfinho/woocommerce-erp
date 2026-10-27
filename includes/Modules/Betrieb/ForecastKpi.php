<?php
/**
 * Prognose-Kennzahlen: Zielwahrscheinlichkeit, Ampel und Auslöser.
 *
 * Ein Quartalsziel ist erst am Quartalsende bekannt, aber schon heute lässt
 * sich sagen, wie wahrscheinlich es erreicht wird. Dazu wird der bisher
 * erreichte Stand (Ist) mit der Prognose der verbleibenden Wochen
 * verbunden und gegen das Ziel gelegt.
 *
 * - **Summen** (Umsatz, Bestellungen): Ist bis heute plus Erwartung der
 *   restlichen Wochen. Die Streuung der Wochen wird nicht als unabhängig
 *   angenommen - schlechte Wochen kommen gehäuft -, sondern mit einer
 *   Gleichlaufannahme (rho = 0,3) zusammengeführt. Das macht das Band
 *   breiter und die Aussage vorsichtiger als bei Unabhängigkeit.
 * - **Verhältnisse** (Ø-Warenkorb, Quoten): Erwartung und Band des nächsten
 *   Zeitraums, geprüft gegen Ziel und Alarmgrenze.
 *
 * Aus der Wahrscheinlichkeit folgen Ampel und Auslöser. Ein Auslöser wird
 * nur gemeldet, wenn sich die Stufe ändert - nicht jeden Tag neu.
 *
 * Reine Mathematik ohne Datenbank.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Bewertung von Prognosen gegen Ziele.
 */
class ForecastKpi {

	/**
	 * Gleichlauf der Wochenabweichungen.
	 */
	const RHO = 0.3;

	/**
	 * Schwellen: unter dieser Wahrscheinlichkeit für das Ziel gilt „Aufmerksamkeit“ ...
	 */
	const P_ATTENTION = 0.5;

	/**
	 * ... und unter dieser „kritisch“.
	 */
	const P_CRITICAL = 0.15;

	/**
	 * Schwere der Stufen.
	 *
	 * @return array
	 */
	public static function severity() {
		return array(
			'normal'    => 0,
			'attention' => 1,
			'critical'  => 2,
		);
	}

	/**
	 * Summe aus Ist und Prognose der restlichen Zeiträume.
	 *
	 * @param float $actual Bisher erreicht.
	 * @param array $points Restliche Zeiträume: je mean und sd (schon auf den Rest des Zeitraums skaliert).
	 * @param float $rho    Gleichlauf.
	 * @return array {mean, sd, lo80, hi80, lo95, hi95}
	 */
	public static function sum_projection( $actual, array $points, $rho = self::RHO ) {
		$mean   = (float) $actual;
		$sum_sd = 0.0;
		$sum_v  = 0.0;

		foreach ( $points as $p ) {
			$mean   += (float) $p['mean'];
			$sum_sd += (float) $p['sd'];
			$sum_v  += (float) $p['sd'] * (float) $p['sd'];
		}

		$var = ( 1 - $rho ) * $sum_v + $rho * $sum_sd * $sum_sd;
		$sd  = sqrt( max( 0.0, $var ) );

		// Was schon erreicht ist, geht nicht mehr verloren.
		$floor = (float) $actual;

		return array(
			'mean' => $mean,
			'sd'   => $sd,
			'lo80' => max( $floor, $mean - ForecastModel::Z80 * $sd ),
			'hi80' => $mean + ForecastModel::Z80 * $sd,
			'lo95' => max( $floor, $mean - ForecastModel::Z95 * $sd ),
			'hi95' => $mean + ForecastModel::Z95 * $sd,
		);
	}

	/**
	 * Wahrscheinlichkeit, mindestens x zu erreichen.
	 *
	 * @param float $mean Erwartung.
	 * @param float $sd   Streuung.
	 * @param float $x    Schwelle.
	 * @return float
	 */
	public static function prob_at_least( $mean, $sd, $x ) {
		if ( $sd <= 1e-9 ) {
			return $mean >= $x ? 1.0 : 0.0;
		}

		return 1.0 - ForecastModel::normal_cdf( ( $x - $mean ) / $sd );
	}

	/**
	 * Wahrscheinlichkeit, höchstens x zu erreichen.
	 *
	 * @param float $mean Erwartung.
	 * @param float $sd   Streuung.
	 * @param float $x    Schwelle.
	 * @return float
	 */
	public static function prob_at_most( $mean, $sd, $x ) {
		return 1.0 - self::prob_at_least( $mean, $sd, $x );
	}

	/**
	 * Ampel aus den Wahrscheinlichkeiten - reine Regel.
	 *
	 * @param float|null $p_goal  Wahrscheinlichkeit, das Ziel zu erreichen; null = kein Ziel.
	 * @param float|null $p_alarm Wahrscheinlichkeit, die Alarmgrenze zu verletzen; null = keine Grenze.
	 * @param array      $th      Schwellen attention und critical.
	 * @return string gruen, gelb, rot oder neutral
	 */
	public static function status( $p_goal, $p_alarm, array $th = array() ) {
		$th = array_merge( array( 'attention' => self::P_ATTENTION, 'critical' => self::P_CRITICAL ), $th );

		if ( null === $p_goal && null === $p_alarm ) {
			return 'neutral';
		}

		if ( ( null !== $p_alarm && $p_alarm >= 0.5 ) || ( null !== $p_goal && $p_goal < $th['critical'] ) ) {
			return 'rot';
		}

		if ( null !== $p_goal && $p_goal < $th['attention'] ) {
			return 'gelb';
		}

		return 'gruen';
	}

	/**
	 * Stufe des Ereignisprotokolls zur Ampel.
	 *
	 * @param string $status Ampel.
	 * @return string normal, attention oder critical
	 */
	public static function level_of( $status ) {
		return array(
			'rot'  => 'critical',
			'gelb' => 'attention',
		)[ $status ] ?? 'normal';
	}

	/**
	 * Was pro Zeitraum noch nötig ist, um das Ziel zu erreichen.
	 *
	 * @param float $actual    Bisher erreicht.
	 * @param float $target    Ziel.
	 * @param float $remaining Anzahl verbleibender Zeiträume (auch gebrochen).
	 * @return float|null Null, wenn nichts mehr bleibt.
	 */
	public static function required_per_period( $actual, $target, $remaining ) {
		if ( $remaining <= 0 ) {
			return null;
		}

		return max( 0.0, ( (float) $target - (float) $actual ) / $remaining );
	}

	/**
	 * Meldung zu einer Bewertung.
	 *
	 * @param string $label   Kennzahl.
	 * @param array  $outlook p_goal, p_alarm, status.
	 * @return string
	 */
	public static function message( $label, array $outlook ) {
		$p = isset( $outlook['p_goal'] ) && null !== $outlook['p_goal'] ? round( $outlook['p_goal'] * 100 ) : null;

		if ( isset( $outlook['p_alarm'] ) && null !== $outlook['p_alarm'] && $outlook['p_alarm'] >= 0.5 ) {
			return sprintf(
				/* translators: 1: Kennzahl, 2: Prozent. */
				__( '%1$s: Die Alarmgrenze wird voraussichtlich verletzt (Wahrscheinlichkeit %2$d %%).', 'lindenweg16' ),
				$label,
				round( $outlook['p_alarm'] * 100 )
			);
		}

		if ( null === $p ) {
			return $label;
		}

		return sprintf(
			/* translators: 1: Kennzahl, 2: Prozent. */
			__( '%1$s: Das Ziel wird mit %2$d %% Wahrscheinlichkeit erreicht.', 'lindenweg16' ),
			$label,
			$p
		);
	}

	/**
	 * Veränderungen der Stufen gegenüber dem letzten Lauf.
	 *
	 * Gemeldet wird, was schlechter oder besser wurde - ein Zustand, der
	 * gleich bleibt, ist keine Neuigkeit.
	 *
	 * @param array $previous Kennzahl => Stufe beim letzten Lauf.
	 * @param array $current  Kennzahl => Stufe jetzt.
	 * @return array Zeilen: key, from, to, direction (raise|recover).
	 */
	public static function changes( array $previous, array $current ) {
		$sev = self::severity();
		$out = array();

		foreach ( $current as $key => $level ) {
			$from = isset( $previous[ $key ] ) ? $previous[ $key ] : 'normal';

			if ( $sev[ $level ] === $sev[ $from ] ) {
				continue;
			}

			$out[] = array(
				'key'       => (string) $key,
				'from'      => $from,
				'to'        => $level,
				'direction' => $sev[ $level ] > $sev[ $from ] ? 'raise' : 'recover',
			);
		}

		return $out;
	}

	/**
	 * Anteil eines Zeitraums, der noch vor einem Stichtag liegt.
	 *
	 * Die laufende Woche ist schon zum Teil im Ist; vom letzten Zeitraum des
	 * Quartals zählen nur die Tage bis zum Quartalsende.
	 *
	 * @param int $bucket_from  Erster Tag des Zeitraums (Zeitstempel).
	 * @param int $bucket_to    Letzter Tag des Zeitraums (Zeitstempel).
	 * @param int $window_from  Beginn des Fensters (Zeitstempel, meist morgen).
	 * @param int $window_to    Ende des Fensters (Zeitstempel, Quartalsende).
	 * @return float 0 bis 1
	 */
	public static function overlap_fraction( $bucket_from, $bucket_to, $window_from, $window_to ) {
		$days  = (int) round( ( $bucket_to - $bucket_from ) / 86400 ) + 1;
		$start = max( $bucket_from, $window_from );
		$end   = min( $bucket_to, $window_to );

		if ( $end < $start || $days <= 0 ) {
			return 0.0;
		}

		return (float) ( ( (int) round( ( $end - $start ) / 86400 ) + 1 ) / $days );
	}

	/**
	 * Faktor aus geplanten Ereignissen für einen Zeitraum.
	 *
	 * Ein Markt mit 40 % Aufschlag, der drei von sieben Tagen einer Woche
	 * überdeckt, hebt diese Woche um 40 % mal 3/7 an. Mehrere Ereignisse
	 * addieren sich.
	 *
	 * @param array $events    Ereignisse: von, bis (Y-m-d), pct.
	 * @param int   $bucket_from Erster Tag des Zeitraums (Zeitstempel).
	 * @param int   $bucket_to   Letzter Tag des Zeitraums (Zeitstempel).
	 * @return float
	 */
	public static function event_factor( array $events, $bucket_from, $bucket_to ) {
		$factor = 1.0;

		foreach ( $events as $event ) {
			$from = strtotime( $event['von'] . ' UTC' );
			$to   = strtotime( $event['bis'] . ' UTC' );

			if ( false === $from || false === $to ) {
				continue;
			}

			$factor += ( (float) $event['pct'] / 100 ) * self::overlap_fraction( $bucket_from, $bucket_to, $from, $to );
		}

		return max( 0.0, $factor );
	}
}
