<?php
/**
 * Produktionsplan: aus dem Bedarf eine Reihenfolge mit Aufwand und Woche.
 *
 * Die Bedarfsseite beantwortet „was fehlt". Sie sagt nicht, wie lange es
 * dauert und wann es fertig sein kann. Genau das ist der Unterschied
 * zwischen einer Liste und einem Plan.
 *
 * Alles hier ist reine Rechnung: Zeilen rein, Plan raus. Keine Abfrage,
 * keine Uhr. Damit lässt sich der Plan prüfen, statt ihm zu glauben.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Reihenfolge, Aufwand und Wochenverteilung.
 */
class ProductionPlan {

	/**
	 * Aufwand einer Zeile schätzen.
	 *
	 * Zwei getrennte Größen, weil sie sich nicht ersetzen können: Der
	 * Drucker läuft ohne dich, die Nachbearbeitung nicht. Wer beides
	 * zusammenzählt, plant eine Woche, die es nicht gibt.
	 *
	 * @param array $row      Bedarfszeile.
	 * @param array $article  Angaben zum Artikel {print_time_h, parts_per_plate, postprocessing_min}.
	 * @param float $plate_min Bedienminuten je Plattenwechsel.
	 * @return array {machine_h, operator_h, plates}
	 */
	public static function effort( array $row, array $article, $plate_min ) {
		$qty = max( 0.0, (float) ( isset( $row['net'] ) ? $row['net'] : 0 ) );

		if ( $qty <= 0 ) {
			return array( 'machine_h' => 0.0, 'operator_h' => 0.0, 'plates' => 0 );
		}

		$action = isset( $row['action'] ) ? (string) $row['action'] : '';

		// Nur Gedrucktes belegt einen Drucker. Montage und Zukauf nicht –
		// sie kosten Hände beziehungsweise Lieferzeit.
		$machine = 0.0;
		$plates  = 0;

		if ( 'drucken' === $action ) {
			$per_piece = max( 0.0, (float) ( isset( $article['print_time_h'] ) ? $article['print_time_h'] : 0 ) );
			$machine   = $per_piece * $qty;

			$per_plate = max( 1, (int) ( isset( $article['parts_per_plate'] ) ? $article['parts_per_plate'] : 1 ) );
			$plates    = (int) ceil( $qty / $per_plate );
		}

		$post   = max( 0.0, (float) ( isset( $article['postprocessing_min'] ) ? $article['postprocessing_min'] : 0 ) );
		$hands  = ( $post * $qty ) / 60;
		$hands += ( $plates * max( 0.0, (float) $plate_min ) ) / 60;

		return array(
			'machine_h'  => round( $machine, 2 ),
			'operator_h' => round( $hands, 2 ),
			'plates'     => $plates,
		);
	}

	/**
	 * Zeilen in eine Arbeitsreihenfolge bringen.
	 *
	 * Was am tiefsten in der Stückliste steckt, kommt zuerst: Ein Modul
	 * lässt sich nicht montieren, solange sein Rohling fehlt. Innerhalb
	 * einer Stufe zuerst, was am längsten dauert – die lange Sache soll
	 * früh im Drucker liegen, nicht am Freitagabend.
	 *
	 * @param array $steps Schritte mit depth und machine_h.
	 * @return array
	 */
	public static function order( array $steps ) {
		usort(
			$steps,
			static function ( $a, $b ) {
				if ( $a['depth'] !== $b['depth'] ) {
					return $b['depth'] <=> $a['depth'];
				}

				if ( abs( $a['machine_h'] - $b['machine_h'] ) > 0.001 ) {
					return $b['machine_h'] <=> $a['machine_h'];
				}

				return strcmp( (string) $a['sku'], (string) $b['sku'] );
			}
		);

		return $steps;
	}

	/**
	 * Schritte auf Wochen verteilen.
	 *
	 * Zwei Töpfe je Woche, Maschine und Hände, und ein Schritt kommt erst
	 * in die Woche, in der beide reichen. Ein Schritt, der für sich allein
	 * größer ist als eine ganze Woche, bekommt trotzdem eine – sonst
	 * schöbe ihn die Rechnung endlos vor sich her.
	 *
	 * @param array $steps       Geordnete Schritte.
	 * @param float $machine_h   Maschinenstunden je Woche.
	 * @param float $operator_h  Arbeitsstunden je Woche.
	 * @param int   $max_weeks   Obergrenze, damit die Schleife endet.
	 * @return array Schritte mit week (1-basiert).
	 */
	public static function schedule( array $steps, $machine_h, $operator_h, $max_weeks = 26 ) {
		$machine_h  = max( 0.0, (float) $machine_h );
		$operator_h = max( 0.0, (float) $operator_h );

		$week      = 1;
		$rest_m    = $machine_h;
		$rest_o    = $operator_h;
		$geplant   = array();

		foreach ( $steps as $step ) {
			$m = (float) $step['machine_h'];
			$o = (float) $step['operator_h'];

			// Passt es in die laufende Woche? Sonst die nächste anfangen –
			// aber nur, solange in der laufenden schon etwas steht. Sonst
			// waere der Schritt fuer jede Woche zu gross.
			$voll = ( $m > $rest_m + 0.001 || $o > $rest_o + 0.001 );

			if ( $voll && ( $rest_m < $machine_h || $rest_o < $operator_h ) && $week < $max_weeks ) {
				$week++;
				$rest_m = $machine_h;
				$rest_o = $operator_h;
			}

			$step['week'] = $week;
			$rest_m       = max( 0.0, $rest_m - $m );
			$rest_o       = max( 0.0, $rest_o - $o );

			$geplant[] = $step;
		}

		return $geplant;
	}

	/**
	 * Summen je Woche und insgesamt.
	 *
	 * @param array $steps Geplante Schritte.
	 * @return array {total, weeks}
	 */
	public static function totals( array $steps ) {
		$total = array( 'machine_h' => 0.0, 'operator_h' => 0.0, 'plates' => 0, 'steps' => 0 );
		$weeks = array();

		foreach ( $steps as $step ) {
			$week = isset( $step['week'] ) ? (int) $step['week'] : 1;

			if ( ! isset( $weeks[ $week ] ) ) {
				$weeks[ $week ] = array( 'machine_h' => 0.0, 'operator_h' => 0.0, 'plates' => 0, 'steps' => 0 );
			}

			foreach ( array( 'machine_h', 'operator_h' ) as $feld ) {
				$weeks[ $week ][ $feld ] += (float) $step[ $feld ];
				$total[ $feld ]          += (float) $step[ $feld ];
			}

			$weeks[ $week ]['plates'] += (int) $step['plates'];
			$total['plates']          += (int) $step['plates'];
			$weeks[ $week ]['steps']++;
			$total['steps']++;
		}

		foreach ( array( 'machine_h', 'operator_h' ) as $feld ) {
			$total[ $feld ] = round( $total[ $feld ], 2 );
			foreach ( $weeks as $nr => $unused ) {
				$weeks[ $nr ][ $feld ] = round( $weeks[ $nr ][ $feld ], 2 );
			}
		}

		ksort( $weeks );

		return array( 'total' => $total, 'weeks' => $weeks );
	}
}
