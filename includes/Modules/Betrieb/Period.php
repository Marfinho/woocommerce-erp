<?php
/**
 * Zeitraster für Verlaufsgraphen: Tag, Woche, Monat, Quartal, Jahr.
 *
 * Ein Verlauf braucht Eimer, in die die Zahlen fallen, und die Eimer müssen
 * lückenlos aneinanderstoßen – sonst zeigt der Graph eine Delle, wo nur eine
 * Lücke im Raster ist.
 *
 * Zwei Fallen stecken darin, und beide sind hier erledigt:
 *
 * 1. Die Woche folgt ISO-8601 (Montag bis Sonntag, Woche 1 enthält den ersten
 *    Donnerstag). Eine ISO-Woche gehört nicht zwingend zum Kalenderjahr ihrer
 *    Tage – deshalb rechnet `IsoWeek` und nicht `date('W')` allein.
 * 2. Monats-, Quartals- und Jahresgrenzen werden nie durch Tageszählung
 *    genähert. Ein Monat hat 28 bis 31 Tage, ein Jahr 365 oder 366; wer mit
 *    30 rechnet, verschiebt den Verlauf über ein Jahr um mehrere Tage.
 *
 * Frei von Datenbank und WordPress-Umgebung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Zeiträume bilden, verschieben und überlappen.
 */
class Period {

	/**
	 * Die wählbaren Zeitraster, vom feinsten zum gröbsten.
	 */
	const GRANULARITIES = array( 'tag', 'woche', 'monat', 'quartal', 'jahr' );

	/**
	 * Obergrenze der Eimer je Graph.
	 *
	 * Jeder Eimer kostet eine volle Kennzahlenrechnung über die Bestellungen
	 * und das Druckjournal. Mehr als das wird weder schneller gelesen noch
	 * schneller berechnet.
	 */
	const MAX_BUCKETS = 40;

	/**
	 * Beschriftung der Raster.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'tag'     => __( 'Tag', 'lindenweg16' ),
			'woche'   => __( 'Woche', 'lindenweg16' ),
			'monat'   => __( 'Monat', 'lindenweg16' ),
			'quartal' => __( 'Quartal', 'lindenweg16' ),
			'jahr'    => __( 'Jahr', 'lindenweg16' ),
		);
	}

	/**
	 * Wie viele Eimer ein Raster ohne besondere Angabe zeigt.
	 *
	 * Die Zahlen sind so gewählt, dass der Verlauf einen Rhythmus zeigt:
	 * 14 Tage decken zwei Wochen ab, 13 Wochen ein Quartal, 12 Monate ein
	 * Jahr, 8 Quartale zwei Jahre.
	 *
	 * @param string $granularity Raster.
	 * @return int
	 */
	public static function default_count( $granularity ) {
		$counts = array(
			'tag'     => 14,
			'woche'   => 13,
			'monat'   => 12,
			'quartal' => 8,
			'jahr'    => 5,
		);

		return isset( $counts[ $granularity ] ) ? $counts[ $granularity ] : 12;
	}

	/**
	 * Ein gültiges Raster – Fremdeingaben fallen auf den Monat zurück.
	 *
	 * @param string $granularity Raster.
	 * @return string
	 */
	public static function clean( $granularity ) {
		$granularity = (string) $granularity;

		return in_array( $granularity, self::GRANULARITIES, true ) ? $granularity : 'monat';
	}

	/**
	 * Der Eimer, in den ein Datum fällt.
	 *
	 * @param string $granularity Raster.
	 * @param string $date        Datum (Y-m-d).
	 * @return array {key, label, von, bis}
	 */
	public static function bucket( $granularity, $date ) {
		$granularity = self::clean( $granularity );
		$date        = self::valid_date( $date );
		$year        = (int) substr( $date, 0, 4 );
		$month       = (int) substr( $date, 5, 2 );

		switch ( $granularity ) {
			case 'tag':
				return self::make( $date, self::day_label( $date ), $date, $date );

			case 'woche':
				$iso    = IsoWeek::from_date( $date );
				$bounds = IsoWeek::bounds( $iso['iso_year'], $iso['iso_week'] );

				return self::make(
					sprintf( '%04d-W%02d', $iso['iso_year'], $iso['iso_week'] ),
					IsoWeek::label( $iso['iso_year'], $iso['iso_week'] ),
					$bounds['von'],
					$bounds['bis']
				);

			case 'quartal':
				$quarter = (int) ceil( $month / 3 );
				$first   = $quarter * 3 - 2;

				return self::make(
					sprintf( '%04d-Q%d', $year, $quarter ),
					sprintf( 'Q%d/%04d', $quarter, $year ),
					sprintf( '%04d-%02d-01', $year, $first ),
					self::last_day( $year, $first + 2 )
				);

			case 'jahr':
				return self::make(
					sprintf( '%04d', $year ),
					sprintf( '%04d', $year ),
					sprintf( '%04d-01-01', $year ),
					sprintf( '%04d-12-31', $year )
				);
		}

		return self::make(
			sprintf( '%04d-%02d', $year, $month ),
			self::month_label( $month ) . ' ' . $year,
			sprintf( '%04d-%02d-01', $year, $month ),
			self::last_day( $year, $month )
		);
	}

	/**
	 * Eine Folge von Eimern, die mit dem Eimer um `$bis` endet.
	 *
	 * Rückwärts gezählt und dann gedreht: Der Leser will links die
	 * Vergangenheit und rechts das Heute.
	 *
	 * @param string $granularity Raster.
	 * @param string $bis         Letztes Datum.
	 * @param int    $count       Anzahl Eimer.
	 * @return array Liste aus {key, label, von, bis}.
	 */
	public static function series( $granularity, $bis, $count ) {
		$granularity = self::clean( $granularity );
		$count       = max( 1, min( self::MAX_BUCKETS, (int) $count ) );
		$current     = self::bucket( $granularity, $bis );
		$buckets     = array( $current );

		for ( $i = 1; $i < $count; $i++ ) {
			$current   = self::previous( $granularity, $current );
			$buckets[] = $current;
		}

		return array_reverse( $buckets );
	}

	/**
	 * Der Eimer davor.
	 *
	 * Ein Tag vor dem Beginn liegt immer im vorigen Eimer – das gilt für
	 * jedes der fünf Raster und spart fünf Sonderfälle.
	 *
	 * @param string $granularity Raster.
	 * @param array  $bucket      Eimer.
	 * @return array
	 */
	public static function previous( $granularity, array $bucket ) {
		$zeit = strtotime( $bucket['von'] . ' 12:00:00 UTC' ) - DAY_IN_SECONDS;

		return self::bucket( $granularity, gmdate( 'Y-m-d', $zeit ) );
	}

	/**
	 * Tage eines Zeitraums, beide Ränder eingeschlossen.
	 *
	 * Ein Zeitraum vom 1. bis zum 1. ist ein Tag, nicht null – sonst
	 * verschwindet ein Tagesziel bei der Verteilung.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return int
	 */
	public static function days( $von, $bis ) {
		$a = strtotime( self::valid_date( $von ) . ' 12:00:00 UTC' );
		$b = strtotime( self::valid_date( $bis ) . ' 12:00:00 UTC' );

		if ( $b < $a ) {
			return 0;
		}

		return (int) round( ( $b - $a ) / DAY_IN_SECONDS ) + 1;
	}

	/**
	 * Gemeinsame Tage zweier Zeiträume.
	 *
	 * @param string $a_von Erster Zeitraum von.
	 * @param string $a_bis Erster Zeitraum bis.
	 * @param string $b_von Zweiter Zeitraum von.
	 * @param string $b_bis Zweiter Zeitraum bis.
	 * @return int
	 */
	public static function overlap( $a_von, $a_bis, $b_von, $b_bis ) {
		$von = max( self::valid_date( $a_von ), self::valid_date( $b_von ) );
		$bis = min( self::valid_date( $a_bis ), self::valid_date( $b_bis ) );

		return self::days( $von, $bis );
	}

	/**
	 * Alle Quartale, die einen Zeitraum berühren.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return array Liste der Quartalsschlüssel, aufsteigend.
	 */
	public static function quarters_between( $von, $bis ) {
		$von = self::valid_date( $von );
		$bis = self::valid_date( $bis );

		if ( $bis < $von ) {
			return array();
		}

		$quartale = array();
		$aktuell  = self::bucket( 'quartal', $von );

		// Höchstens vierzig Quartale – zehn Jahre reichen für jeden Bericht,
		// und eine Endlosschleife bei kaputten Daten reicht nie.
		for ( $i = 0; $i < 40; $i++ ) {
			$quartale[] = $aktuell['key'];

			if ( $aktuell['bis'] >= $bis ) {
				break;
			}

			$naechster = strtotime( $aktuell['bis'] . ' 12:00:00 UTC' ) + DAY_IN_SECONDS;
			$aktuell   = self::bucket( 'quartal', gmdate( 'Y-m-d', $naechster ) );
		}

		return $quartale;
	}

	/**
	 * Grenzen eines Quartalsschlüssels.
	 *
	 * @param string $key Schlüssel, etwa 2026-Q3.
	 * @return array {key, label, von, bis} oder leeres Feld.
	 */
	public static function quarter_bounds( $key ) {
		if ( ! preg_match( '/^(\d{4})-Q([1-4])$/', (string) $key, $treffer ) ) {
			return array();
		}

		return self::bucket( 'quartal', sprintf( '%04d-%02d-01', (int) $treffer[1], (int) $treffer[2] * 3 - 2 ) );
	}

	/**
	 * Eimer zusammensetzen.
	 *
	 * @param string $key   Schlüssel.
	 * @param string $label Beschriftung.
	 * @param string $von   Von.
	 * @param string $bis   Bis.
	 * @return array
	 */
	protected static function make( $key, $label, $von, $bis ) {
		return array(
			'key'   => $key,
			'label' => $label,
			'von'   => $von,
			'bis'   => $bis,
		);
	}

	/**
	 * Letzter Tag eines Monats.
	 *
	 * @param int $year  Jahr.
	 * @param int $month Monat.
	 * @return string
	 */
	protected static function last_day( $year, $month ) {
		return gmdate( 'Y-m-t', strtotime( sprintf( '%04d-%02d-01 12:00:00 UTC', (int) $year, (int) $month ) ) );
	}

	/**
	 * Ein Datum oder der heutige Tag.
	 *
	 * @param string $date Datum.
	 * @return string
	 */
	protected static function valid_date( $date ) {
		$date = (string) $date;

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : gmdate( 'Y-m-d' );
	}

	/**
	 * Beschriftung eines Tages.
	 *
	 * Ohne Jahr: In einer Achse mit vierzehn Tagen ist das Jahr überall
	 * gleich und stiehlt nur Platz.
	 *
	 * @param string $date Datum.
	 * @return string
	 */
	protected static function day_label( $date ) {
		return substr( $date, 8, 2 ) . '.' . substr( $date, 5, 2 ) . '.';
	}

	/**
	 * Kurzer Monatsname.
	 *
	 * @param int $month Monat.
	 * @return string
	 */
	protected static function month_label( $month ) {
		$names = array(
			1  => __( 'Jan.', 'lindenweg16' ),
			2  => __( 'Feb.', 'lindenweg16' ),
			3  => __( 'März', 'lindenweg16' ),
			4  => __( 'Apr.', 'lindenweg16' ),
			5  => __( 'Mai', 'lindenweg16' ),
			6  => __( 'Juni', 'lindenweg16' ),
			7  => __( 'Juli', 'lindenweg16' ),
			8  => __( 'Aug.', 'lindenweg16' ),
			9  => __( 'Sep.', 'lindenweg16' ),
			10 => __( 'Okt.', 'lindenweg16' ),
			11 => __( 'Nov.', 'lindenweg16' ),
			12 => __( 'Dez.', 'lindenweg16' ),
		);

		return isset( $names[ (int) $month ] ) ? $names[ (int) $month ] : (string) $month;
	}
}
