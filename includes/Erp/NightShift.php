<?php
/**
 * Nachtschicht-Planer: Welche Platte kommt heute Abend auf welchen Drucker?
 *
 * Über Nacht wechselt niemand die Platte – jeder freie Drucker bekommt also
 * genau eine. Gesucht ist für jeden die Platte, die
 * - zum Material im AMS passt (sonst muss vor dem Feierabend umgerüstet werden),
 * - das Nachtfenster möglichst ausfüllt (lange Platten in die Nacht),
 * - dringend ist (Bestellungen warten, Bestand unter Soll),
 * - und genug Filament in der passenden Rolle hat.
 *
 * Reine Rechnung ohne Datenbank, getestet (tests/ErpNightShiftTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Nachtschicht.
 */
class NightShift {

	/**
	 * Gramm auf einer vollen Rolle (AMS meldet die Restmenge in Prozent).
	 */
	const ROLL_G = 1000;

	/**
	 * Nachtfenster in Minuten.
	 *
	 * @param string $now        Jetzt 'Y-m-d H:i'.
	 * @param string $leave      Feierabend 'H:i'.
	 * @param string $back       Wieder da 'H:i' (am nächsten Tag, wenn früher als Feierabend).
	 * @return array {start, end, minutes} start/end als 'Y-m-d H:i'.
	 */
	public static function window( $now, $leave, $back ) {
		$day   = substr( $now, 0, 10 );
		$start = strtotime( $day . ' ' . $leave . ' UTC' );
		$end   = strtotime( $day . ' ' . $back . ' UTC' );
		$cur   = strtotime( $now . ' UTC' );

		if ( $end <= $start ) {
			$end += 86400;
		}
		// Nach Mitternacht vor dem Aufstehen: das Fenster von gestern Abend läuft noch.
		if ( $cur < $start && $cur < $end - 86400 ) {
			$start -= 86400;
			$end   -= 86400;
		}
		$start = max( $start, $cur );

		return array(
			'start'   => gmdate( 'Y-m-d H:i', $start ),
			'end'     => gmdate( 'Y-m-d H:i', $end ),
			'minutes' => max( 0, (int) round( ( $end - $start ) / 60 ) ),
		);
	}

	/**
	 * Hex-Farbe (6 Zeichen) oder leer.
	 *
	 * @param string $raw Eingabe.
	 * @return string
	 */
	public static function hex( $raw ) {
		$h = strtolower( preg_replace( '/[^0-9a-f]/i', '', (string) $raw ) );

		return 6 === strlen( $h ) || 8 === strlen( $h ) ? substr( $h, 0, 6 ) : '';
	}

	/**
	 * Abstand zweier Farben (0 = gleich, ~441 = Schwarz/Weiß).
	 *
	 * @param string $a Hex.
	 * @param string $b Hex.
	 * @return float
	 */
	public static function color_distance( $a, $b ) {
		$a = self::hex( $a );
		$b = self::hex( $b );

		if ( '' === $a || '' === $b ) {
			return 999.0;
		}

		$d = 0.0;
		for ( $i = 0; $i < 3; $i++ ) {
			$d += pow( hexdec( substr( $a, $i * 2, 2 ) ) - hexdec( substr( $b, $i * 2, 2 ) ), 2 );
		}

		return sqrt( $d );
	}

	/**
	 * Passendes AMS-Fach für eine Platte.
	 *
	 * @param array $plate Platte {material, color_hex, grams}.
	 * @param array $trays Fächer {unit, tray, material, color, remain}.
	 * @return array|null {tray, enough, grams_left} – null = kein passendes Fach.
	 */
	public static function matching_tray( array $plate, array $trays ) {
		$mat  = strtoupper( trim( (string) $plate['material'] ) );
		$best = null;

		foreach ( $trays as $t ) {
			$tm = strtoupper( trim( (string) $t['material'] ) );
			if ( '' !== $mat && ( '' === $tm || ( false === strpos( $tm, $mat ) && false === strpos( $mat, $tm ) ) ) ) {
				continue;
			}
			$dist = '' !== self::hex( $plate['color_hex'] ) ? self::color_distance( $plate['color_hex'], $t['color'] ) : 0.0;
			if ( $dist > 60 ) {
				continue;
			}
			$left = null === $t['remain'] ? null : (int) round( (int) $t['remain'] / 100 * self::ROLL_G );
			$cand = array(
				'tray'       => $t,
				'enough'     => null === $left || $left >= (float) $plate['grams'] * 1.1,
				'grams_left' => $left,
				'dist'       => $dist,
			);
			if ( ! $best || ( $cand['enough'] && ! $best['enough'] ) || ( $cand['enough'] === $best['enough'] && $dist < $best['dist'] ) ) {
				$best = $cand;
			}
		}

		return $best;
	}

	/**
	 * Punkte einer Platte für einen Drucker.
	 *
	 * @param array $plate   Platte {minutes, urgency 0..1, …}.
	 * @param array $tray    matching_tray() oder null.
	 * @param int   $window  Fenster in Minuten.
	 * @return float
	 */
	public static function score( array $plate, $tray, $window ) {
		$window = max( 60, (int) $window );
		$fill   = min( (int) $plate['minutes'], $window ) / $window; // 0..1: wie gut das Fenster genutzt wird.
		$score  = $fill * 100 + (float) $plate['urgency'] * 40;

		if ( (int) $plate['minutes'] < 120 ) {
			$score -= 30; // Kurze Platten gehören in den Tag.
		}
		if ( $tray ) {
			$score += $tray['enough'] ? 30 : -40;
		} else {
			$score -= 15; // Umrüsten vor dem Feierabend.
		}

		return round( $score, 1 );
	}

	/**
	 * Plan bauen: jedem freien Drucker höchstens eine Platte, beste Paare zuerst.
	 *
	 * @param array $printers Drucker {id, name, free, reason, trays[]}.
	 * @param array $plates   Platten {id, label, minutes, material, color_hex, grams, urgency, printer (fest zugeordnet oder '')}.
	 * @param int   $window   Fenster in Minuten.
	 * @return array {assignments[], idle[], left[]}
	 */
	public static function plan( array $printers, array $plates, $window ) {
		$pairs = array();

		foreach ( $printers as $p ) {
			if ( empty( $p['free'] ) ) {
				continue;
			}
			foreach ( $plates as $pl ) {
				if ( '' !== (string) $pl['printer'] && (string) $pl['printer'] !== (string) $p['id'] ) {
					continue;
				}
				$tray    = self::matching_tray( $pl, (array) $p['trays'] );
				$pairs[] = array( 'printer' => $p, 'plate' => $pl, 'tray' => $tray, 'score' => self::score( $pl, $tray, $window ) );
			}
		}

		usort(
			$pairs,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$used_printers = array();
		$used_plates   = array();
		$assign        = array();

		foreach ( $pairs as $pair ) {
			$pid = (string) $pair['printer']['id'];
			$lid = (int) $pair['plate']['id'];
			if ( isset( $used_printers[ $pid ] ) || isset( $used_plates[ $lid ] ) || $pair['score'] <= 0 ) {
				continue;
			}
			$used_printers[ $pid ] = true;
			$used_plates[ $lid ]   = true;

			$why = array();
			if ( $pair['tray'] ) {
				/* translators: 1: AMS-Einheit, 2: Fach. */
				$why[] = sprintf( __( 'Material liegt schon im AMS %1$d, Fach %2$d', 'lindenweg16' ), (int) $pair['tray']['tray']['unit'], (int) $pair['tray']['tray']['tray'] );
				if ( ! $pair['tray']['enough'] ) {
					/* translators: %d: Gramm. */
					$why[] = sprintf( __( 'Achtung: nur noch etwa %d g auf der Rolle', 'lindenweg16' ), (int) $pair['tray']['grams_left'] );
				}
			} else {
				$why[] = __( 'Vor dem Feierabend umrüsten', 'lindenweg16' );
			}
			if ( (float) $pair['plate']['urgency'] >= 0.5 ) {
				$why[] = __( 'dringend', 'lindenweg16' );
			}
			if ( (int) $pair['plate']['minutes'] > $window ) {
				/* translators: %d: Minuten. */
				$why[] = sprintf( __( 'läuft %d Minuten über das Fenster hinaus', 'lindenweg16' ), (int) $pair['plate']['minutes'] - (int) $window );
			}

			$assign[] = array(
				'printer'  => $pair['printer'],
				'plate'    => $pair['plate'],
				'tray'     => $pair['tray'] ? $pair['tray']['tray'] : null,
				'enough'   => $pair['tray'] ? $pair['tray']['enough'] : null,
				'refit'    => ! $pair['tray'],
				'fill'     => (int) round( min( (int) $pair['plate']['minutes'], max( 1, (int) $window ) ) / max( 1, (int) $window ) * 100 ),
				'score'    => $pair['score'],
				'why'      => $why,
			);
		}

		$idle = array();
		foreach ( $printers as $p ) {
			if ( ! isset( $used_printers[ (string) $p['id'] ] ) ) {
				$idle[] = array( 'printer' => $p, 'reason' => ! empty( $p['free'] ) ? __( 'Keine passende freigegebene Platte – im Workflow neue Platten freigeben.', 'lindenweg16' ) : (string) $p['reason'] );
			}
		}

		$left = array_values( array_filter( $plates, function ( $pl ) use ( $used_plates ) {
			return ! isset( $used_plates[ (int) $pl['id'] ] );
		} ) );

		return array( 'assignments' => $assign, 'idle' => $idle, 'left' => $left );
	}
}
