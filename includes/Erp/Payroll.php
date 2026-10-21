<?php
/**
 * Lohnabrechnung aus erfassten Zeiten: die Rechnung, ohne Datenbank.
 *
 * Gerechnet wird der Bruttobetrag aus Stunden mal Stundenlohn, dazu frei
 * eingetragene Zuschläge und Abzüge. Lohnsteuer und Sozialversicherung
 * kennt diese Klasse nicht und rechnet sie nicht: Das ist Sache des
 * Steuerberaters oder eines Lohnprogramms. Die Abrechnung ist die saubere
 * Grundlage dafür.
 *
 * Der Stundenlohn gilt ab seinem Stichtag (LaborCost::rate_at). Ändert er
 * sich mitten im Monat, entstehen zwei Zeilen mit je eigenem Satz.
 * Stunden ohne hinterlegten Lohn werden nicht mit null bewertet, sondern
 * gemeldet: Eine Abrechnung mit Lücke wird nicht festgeschrieben.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\LaborCost;

/**
 * Reine Regeln der Abrechnung.
 */
class Payroll {

	/**
	 * Obergrenze für Zuschläge und Abzüge je Zeile (gegen Tippfehler).
	 */
	const MAX_ADJUSTMENT = 100000;

	/**
	 * Höchstzahl der Zeilen für Zuschläge und Abzüge.
	 */
	const MAX_ADJUSTMENTS = 20;

	/**
	 * Von und bis eines Monats.
	 *
	 * @param string $month Monat als JJJJ-MM.
	 * @return array|null {von, bis, label} oder null bei ungültiger Eingabe.
	 */
	public static function month_bounds( $month ) {
		if ( ! preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', (string) $month, $m ) ) {
			return null;
		}

		$names = array( '', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' );
		$last  = (int) gmdate( 't', gmmktime( 0, 0, 0, (int) $m[2], 1, (int) $m[1] ) );

		return array(
			'von'   => sprintf( '%04d-%02d-01', $m[1], $m[2] ),
			'bis'   => sprintf( '%04d-%02d-%02d', $m[1], $m[2], $last ),
			'label' => $names[ (int) $m[2] ] . ' ' . $m[1],
		);
	}

	/**
	 * Abrechnung einer Person für einen Zeitraum.
	 *
	 * @param array  $entries Zeitbuchungen (entry_date, hours, created_by, category, description).
	 * @param array  $wages   Lohnzeilen (user_id, rate_per_h, valid_from).
	 * @param int    $user_id Person.
	 * @param string $von     Von (Y-m-d).
	 * @param string $bis     Bis (Y-m-d).
	 * @return array {hours, gross, unpriced_hours, lines, days}
	 */
	public static function build( array $entries, array $wages, $user_id, $von, $bis ) {
		$user_id = (int) $user_id;
		$by_rate = array();
		$days    = array();
		$unpriced = 0.0;
		$hours    = 0.0;

		foreach ( $entries as $entry ) {
			$entry = (array) $entry;

			if ( (int) $entry['created_by'] !== $user_id || $entry['entry_date'] < $von || $entry['entry_date'] > $bis ) {
				continue;
			}

			$h    = round( (float) $entry['hours'], 2 );
			$rate = LaborCost::rate_at( $wages, $user_id, $entry['entry_date'] );

			$days[] = array(
				'date'        => (string) $entry['entry_date'],
				'category'    => isset( $entry['category'] ) ? (string) $entry['category'] : '',
				'description' => isset( $entry['description'] ) ? (string) $entry['description'] : '',
				'hours'       => $h,
				'rate'        => $rate,
			);

			if ( null === $rate ) {
				$unpriced += $h;
				continue;
			}

			$key = sprintf( '%.4F', $rate );

			if ( ! isset( $by_rate[ $key ] ) ) {
				$by_rate[ $key ] = array( 'rate' => (float) $rate, 'hours' => 0.0 );
			}

			$by_rate[ $key ]['hours'] += $h;
			$hours                    += $h;
		}

		ksort( $by_rate );

		$lines = array();
		$gross = 0.0;

		foreach ( $by_rate as $row ) {
			$hours_line = round( $row['hours'], 2 );
			$amount     = round( $hours_line * $row['rate'], 2 );
			$lines[]    = array( 'rate' => $row['rate'], 'hours' => $hours_line, 'amount' => $amount );
			$gross     += $amount;
		}

		usort(
			$days,
			static function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);

		return array(
			'hours'          => round( $hours + $unpriced, 2 ),
			'priced_hours'   => round( $hours, 2 ),
			'gross'          => round( $gross, 2 ),
			'unpriced_hours' => round( $unpriced, 2 ),
			'lines'          => $lines,
			'days'           => $days,
		);
	}

	/**
	 * Zuschläge und Abzüge aus der Eingabe säubern.
	 *
	 * @param mixed $raw Liste {label, amount}; amount darf ein Komma haben.
	 * @return array {rows, total, error}
	 */
	public static function adjustments( $raw ) {
		$rows  = array();
		$total = 0.0;

		if ( ! is_array( $raw ) ) {
			return array( 'rows' => array(), 'total' => 0.0, 'error' => '' );
		}

		foreach ( $raw as $row ) {
			$row    = (array) $row;
			$label  = isset( $row['label'] ) ? trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $row['label'] ) ) ) : '';
			$amount = isset( $row['amount'] ) ? self::number( $row['amount'] ) : null;

			if ( '' === $label && ( null === $amount || 0.0 === $amount ) ) {
				continue;
			}

			if ( '' === $label ) {
				return array( 'rows' => array(), 'total' => 0.0, 'error' => __( 'Jede Zeile bei Zuschlägen und Abzügen braucht eine Bezeichnung, zum Beispiel „Vorschuss“.', 'lindenweg16' ) );
			}

			if ( null === $amount ) {
				return array(
					'rows'  => array(),
					'total' => 0.0,
					'error' => sprintf(
						/* translators: %s: Bezeichnung. */
						__( 'Der Betrag bei „%s“ ist keine Zahl. Bitte so eintragen: 50 oder -25,50 (Abzug mit Minus).', 'lindenweg16' ),
						$label
					),
				);
			}

			if ( abs( $amount ) > self::MAX_ADJUSTMENT ) {
				return array( 'rows' => array(), 'total' => 0.0, 'error' => __( 'Ein Betrag bei Zuschlägen und Abzügen ist größer als 100.000 €. Bitte prüfen.', 'lindenweg16' ) );
			}

			$rows[] = array( 'label' => mb_substr( $label, 0, 80 ), 'amount' => round( $amount, 2 ) );
			$total += round( $amount, 2 );
		}

		if ( count( $rows ) > self::MAX_ADJUSTMENTS ) {
			return array( 'rows' => array(), 'total' => 0.0, 'error' => __( 'Mehr als 20 Zeilen bei Zuschlägen und Abzügen sind zu viel. Bitte zusammenfassen.', 'lindenweg16' ) );
		}

		return array( 'rows' => $rows, 'total' => round( $total, 2 ), 'error' => '' );
	}

	/**
	 * Zahl aus Text mit deutschem Komma lesen.
	 *
	 * @param mixed $value Eingabe.
	 * @return float|null Null, wenn keine Zahl.
	 */
	public static function number( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}

		$text = trim( (string) $value );

		if ( '' === $text ) {
			return null;
		}

		// „1.234,56“ → 1234.56; „12,5“ → 12.5; „12.5“ bleibt 12.5.
		if ( false !== strpos( $text, ',' ) ) {
			$text = str_replace( '.', '', $text );
			$text = str_replace( ',', '.', $text );
		}

		return preg_match( '/^-?\d+(\.\d+)?$/', $text ) ? (float) $text : null;
	}

	/**
	 * Überschneiden sich zwei Zeiträume – reine Regel.
	 *
	 * @param string $von_a Von A.
	 * @param string $bis_a Bis A.
	 * @param string $von_b Von B.
	 * @param string $bis_b Bis B.
	 * @return bool
	 */
	public static function overlaps( $von_a, $bis_a, $von_b, $bis_b ) {
		return $von_a <= $bis_b && $von_b <= $bis_a;
	}

	/**
	 * Abrechnungsnummer.
	 *
	 * @param int $year Jahr.
	 * @param int $seq  Laufende Nummer im Jahr.
	 * @return string
	 */
	public static function number_text( $year, $seq ) {
		return sprintf( 'LA-%04d-%04d', (int) $year, (int) $seq );
	}

	/**
	 * Prüfsumme des Inhalts beim Festschreiben.
	 *
	 * @param array $slip Abrechnung mit user_id, von, bis, lines, days, adjustments, gross, total.
	 * @return string
	 */
	public static function checksum( array $slip ) {
		return hash(
			'sha256',
			wp_json_encode(
				array(
					(int) $slip['user_id'],
					(string) $slip['von'],
					(string) $slip['bis'],
					$slip['lines'],
					$slip['days'],
					$slip['adjustments'],
					round( (float) $slip['gross'], 2 ),
					round( (float) $slip['total'], 2 ),
				)
			)
		);
	}

	/**
	 * Ab diesem Anteil der Grenze gilt sie als fast erreicht.
	 */
	const NEAR = 0.9;

	/**
	 * Obergrenzen einer Person aus der Eingabe säubern.
	 *
	 * Leer heißt: keine Grenze. Gedacht für Minijobs und Aushilfen mit
	 * Verdienst- oder Stundenobergrenze; welche Zahl gilt, bestimmt der Admin.
	 *
	 * @param mixed $raw {gross_month, hours_month, gross_year}.
	 * @return array {limits, error}
	 */
	public static function limits_clean( $raw ) {
		$raw    = is_array( $raw ) ? $raw : array();
		$limits = array( 'gross_month' => null, 'hours_month' => null, 'gross_year' => null );
		$names  = array(
			'gross_month' => __( 'Verdienstgrenze je Monat', 'lindenweg16' ),
			'hours_month' => __( 'Stundengrenze je Monat', 'lindenweg16' ),
			'gross_year'  => __( 'Verdienstgrenze je Jahr', 'lindenweg16' ),
		);

		foreach ( $limits as $key => $unused ) {
			if ( ! isset( $raw[ $key ] ) || '' === trim( (string) $raw[ $key ] ) ) {
				continue;
			}

			$n = self::number( $raw[ $key ] );

			if ( null === $n || $n <= 0 || $n > 1000000 ) {
				return array(
					'limits' => $limits,
					'error'  => sprintf(
						/* translators: %s: Name der Grenze. */
						__( 'Bei „%s“ bitte eine Zahl größer als null eintragen, zum Beispiel 603 oder 43,5. Leer lassen heißt: keine Grenze.', 'lindenweg16' ),
						$names[ $key ]
					),
				);
			}

			$limits[ $key ] = round( $n, 2 );
		}

		return array( 'limits' => $limits, 'error' => '' );
	}

	/**
	 * Wie weit ist ein Wert an einer Grenze – reine Regel.
	 *
	 * @param float      $value Wert.
	 * @param float|null $limit Grenze (null = keine).
	 * @return string keine, ok, nah oder ueber.
	 */
	public static function limit_state( $value, $limit ) {
		if ( null === $limit || (float) $limit <= 0 ) {
			return 'keine';
		}

		if ( round( (float) $value, 2 ) > (float) $limit + 0.004 ) {
			return 'ueber';
		}

		return (float) $value >= (float) $limit * self::NEAR ? 'nah' : 'ok';
	}

	/**
	 * Alle Prüfungen gegen die Grenzen einer Person.
	 *
	 * @param array $limits    Grenzen aus limits_clean().
	 * @param float $gross     Verdienst im Monat (brutto, mit Zuschlägen).
	 * @param float $hours     Stunden im Monat.
	 * @param float $year_gross Verdienst im Jahr bis einschließlich dieser Abrechnung.
	 * @return array Liste {kind, label, unit, value, limit, state}; nur gesetzte Grenzen.
	 */
	public static function checks( array $limits, $gross, $hours, $year_gross ) {
		$rows = array(
			array( 'gross_month', __( 'Verdienst im Monat', 'lindenweg16' ), '€', $gross ),
			array( 'hours_month', __( 'Stunden im Monat', 'lindenweg16' ), 'h', $hours ),
			array( 'gross_year', __( 'Verdienst im Jahr', 'lindenweg16' ), '€', $year_gross ),
		);
		$out  = array();

		foreach ( $rows as $row ) {
			$limit = isset( $limits[ $row[0] ] ) ? $limits[ $row[0] ] : null;

			if ( null === $limit ) {
				continue;
			}

			$out[] = array(
				'kind'  => $row[0],
				'label' => $row[1],
				'unit'  => $row[2],
				'value' => round( (float) $row[3], 2 ),
				'limit' => (float) $limit,
				'state' => self::limit_state( $row[3], $limit ),
			);
		}

		return $out;
	}

	/**
	 * Satz zu einer überschrittenen Grenze.
	 *
	 * @param array $check Zeile aus checks().
	 * @return string
	 */
	public static function over_text( array $check ) {
		$fmt = static function ( $n, $unit ) {
			return '€' === $unit ? Payroll::eur( $n ) : Payroll::hours_text( $n ) . ' h';
		};

		return sprintf(
			/* translators: 1: Art der Grenze, 2: Wert, 3: Grenze. */
			__( '%1$s: %2$s, erlaubt sind %3$s', 'lindenweg16' ),
			$check['label'],
			$fmt( $check['value'], $check['unit'] ),
			$fmt( $check['limit'], $check['unit'] )
		);
	}

	/**
	 * Beträge deutsch: 1.234,50 €.
	 *
	 * @param float $n Betrag.
	 * @return string
	 */
	public static function eur( $n ) {
		return number_format( (float) $n, 2, ',', '.' ) . ' €';
	}

	/**
	 * Stunden deutsch: 12,50.
	 *
	 * @param float $n Stunden.
	 * @return string
	 */
	public static function hours_text( $n ) {
		return number_format( (float) $n, 2, ',', '.' );
	}

	/**
	 * Datum deutsch: 21.10.2026.
	 *
	 * @param string $ymd Datum Y-m-d.
	 * @return string
	 */
	public static function dmy( $ymd ) {
		return preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', (string) $ymd, $m ) ? $m[3] . '.' . $m[2] . '.' . $m[1] : (string) $ymd;
	}

	/**
	 * CSV-Zeile mit Semikolon, deutsch, gegen Formeln abgesichert.
	 *
	 * @param array $cells Zellen.
	 * @return string
	 */
	public static function csv_line( array $cells ) {
		$out = array();

		foreach ( $cells as $cell ) {
			$cell = (string) $cell;

			// Tabellenprogramme führen Zellen als Formel aus, die so beginnen.
			if ( '' !== $cell && ( false !== strpos( '=+@', $cell[0] ) || ( '-' === $cell[0] && ! preg_match( '/^-[\d.,]+( €)?$/', $cell ) ) ) ) {
				$cell = "'" . $cell;
			}

			$out[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}

		return implode( ';', $out ) . "\r\n";
	}
}
