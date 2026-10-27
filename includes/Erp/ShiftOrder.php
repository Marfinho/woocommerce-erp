<?php
/**
 * Reihenfolge der bereiten Platten nach Tageszeit.
 *
 * Der Drucker läuft ohne dich, das Wechseln der Platte nicht. Deshalb gehört
 * eine lange Platte nicht an den Anfang des Tages, sondern an dessen Ende:
 * Sie wird kurz vor Feierabend gestartet und läuft über Nacht. Tagsüber, wenn
 * jemand da ist, kommen die kurzen Platten zuerst, die sich noch vor
 * Feierabend wechseln lassen.
 *
 * - **Tagsüber:** erst Platten, die vor Feierabend fertig werden (kurze zuerst),
 *   dann die übrigen kurzen, zuletzt die langen.
 * - **Ab einer Vorlaufzeit vor Feierabend (und nachts):** die längste zuerst -
 *   was jetzt startet, läuft ohnehin durch die Nacht.
 *
 * Reine Regel ohne Datenbank und Uhr: Die Zeit kommt als Minuten seit
 * Mitternacht herein.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Ordnet Platten nach Dauer und Tageszeit.
 */
class ShiftOrder {

	const DAY     = 'tag';
	const EVENING = 'abend';

	/**
	 * Feierabend als Minuten seit Mitternacht - reine Regel.
	 *
	 * @param string $time Uhrzeit "HH:MM".
	 * @return int
	 */
	public static function minutes_of( $time ) {
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', trim( (string) $time ), $m ) && (int) $m[1] < 24 && (int) $m[2] < 60 ) {
			return (int) $m[1] * 60 + (int) $m[2];
		}

		return 17 * 60;
	}

	/**
	 * Tag oder Abend - reine Regel.
	 *
	 * Vor sechs Uhr morgens gilt „Abend“: Wer um drei Uhr nachts noch eine
	 * Platte startet, will sie laufen lassen.
	 *
	 * @param int   $now_min Jetzt in Minuten seit Mitternacht.
	 * @param int   $end_min Feierabend in Minuten seit Mitternacht.
	 * @param float $lead_h  Vorlauf in Stunden vor Feierabend, ab dem die langen vorn stehen.
	 * @return string
	 */
	public static function phase( $now_min, $end_min, $lead_h ) {
		if ( $now_min < 6 * 60 ) {
			return self::EVENING;
		}

		return $now_min >= $end_min - (int) round( $lead_h * 60 ) ? self::EVENING : self::DAY;
	}

	/**
	 * Platten ordnen.
	 *
	 * @param array $runs    Platten mit id, est_minutes.
	 * @param int   $now_min Jetzt in Minuten seit Mitternacht.
	 * @param int   $end_min Feierabend in Minuten seit Mitternacht.
	 * @param float $lead_h  Vorlauf in Stunden.
	 * @param float $long_h  Ab so vielen Stunden gilt eine Platte als lang.
	 * @return array Platten mit zusätzlich long (bool) und hint (night_now, night_later oder leer).
	 */
	public static function order( array $runs, $now_min, $end_min, $lead_h = 2.0, $long_h = 4.0 ) {
		$phase  = self::phase( $now_min, $end_min, $lead_h );
		$left   = max( 0, $end_min - $now_min );
		$long_m = (int) round( $long_h * 60 );

		foreach ( $runs as $i => $run ) {
			$est               = max( 0, (int) $run['est_minutes'] );
			$runs[ $i ]['long'] = $long_m > 0 && $est >= $long_m;
			$runs[ $i ]['hint'] = '';

			if ( $runs[ $i ]['long'] ) {
				$runs[ $i ]['hint'] = self::EVENING === $phase ? 'night_now' : 'night_later';
			}
		}

		usort(
			$runs,
			static function ( $a, $b ) use ( $phase, $left ) {
				$ea = (int) $a['est_minutes'];
				$eb = (int) $b['est_minutes'];

				if ( self::EVENING === $phase ) {
					// Längste zuerst: Was jetzt startet, läuft über Nacht.
					return $ea !== $eb ? $eb <=> $ea : (int) $a['id'] <=> (int) $b['id'];
				}

				$ga = $a['long'] ? 2 : ( $ea <= $left ? 0 : 1 );
				$gb = $b['long'] ? 2 : ( $eb <= $left ? 0 : 1 );

				if ( $ga !== $gb ) {
					return $ga <=> $gb;
				}

				// Innerhalb der Gruppe: Kurze zuerst, die Langen mit der längsten voran.
				if ( 2 === $ga ) {
					return $ea !== $eb ? $eb <=> $ea : (int) $a['id'] <=> (int) $b['id'];
				}

				return $ea !== $eb ? $ea <=> $eb : (int) $a['id'] <=> (int) $b['id'];
			}
		);

		return $runs;
	}

	/**
	 * Dauer als Text - reine Regel.
	 *
	 * @param int $minutes Minuten.
	 * @return string
	 */
	public static function duration_text( $minutes ) {
		$minutes = (int) $minutes;

		if ( $minutes <= 0 ) {
			return '';
		}

		if ( $minutes < 60 ) {
			return $minutes . ' min';
		}

		$h = intdiv( $minutes, 60 );
		$m = $minutes % 60;

		return $m ? $h . ' h ' . $m . ' min' : $h . ' h';
	}

	/**
	 * Hinweis zur Platte als Text.
	 *
	 * @param string $hint      night_now, night_later oder leer.
	 * @param int    $minutes   Dauer.
	 * @param int    $start_min Ab wann sich der Start lohnt (Minuten seit Mitternacht).
	 * @return string
	 */
	public static function hint_text( $hint, $minutes, $start_min ) {
		$dur = self::duration_text( $minutes );

		if ( 'night_now' === $hint ) {
			/* translators: %s: Dauer. */
			return sprintf( __( '%s Druck – jetzt starten, läuft über Nacht', 'lindenweg16' ), $dur );
		}

		if ( 'night_later' === $hint ) {
			/* translators: 1: Dauer, 2: Uhrzeit. */
			return sprintf( __( '%1$s Druck – am besten ab %2$s starten, dann läuft sie über Nacht', 'lindenweg16' ), $dur, sprintf( '%02d:%02d', intdiv( $start_min, 60 ) % 24, $start_min % 60 ) );
		}

		return '' !== $dur ? sprintf( /* translators: %s: Dauer. */ __( '%s Druck', 'lindenweg16' ), $dur ) : '';
	}
}
