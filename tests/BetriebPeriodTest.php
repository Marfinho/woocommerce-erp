<?php
/**
 * Prüft das Zeitraster der Verlaufsgraphen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\Period;
use PHPUnit\Framework\TestCase;

/**
 * Zeitraum-Tests.
 */
class BetriebPeriodTest extends TestCase {

	/**
	 * Ein Monat endet an seinem letzten Tag, nicht nach dreissig Tagen.
	 */
	public function test_monatsgrenzen_kennen_den_kalender() {
		$februar = Period::bucket( 'monat', '2028-02-14' );

		$this->assertSame( '2028-02-01', $februar['von'] );
		$this->assertSame( '2028-02-29', $februar['bis'], 'Schaltjahr.' );

		$normal = Period::bucket( 'monat', '2026-02-14' );
		$this->assertSame( '2026-02-28', $normal['bis'] );
	}

	/**
	 * Ein Quartal umfasst drei ganze Monate.
	 */
	public function test_quartal_umfasst_drei_monate() {
		$q = Period::bucket( 'quartal', '2026-08-15' );

		$this->assertSame( '2026-Q3', $q['key'] );
		$this->assertSame( '2026-07-01', $q['von'] );
		$this->assertSame( '2026-09-30', $q['bis'] );
	}

	/**
	 * Die Woche folgt ISO-8601 – auch über den Jahreswechsel.
	 *
	 * Der 1. Januar 2027 ist ein Freitag und gehört zur Woche 53 des Jahres
	 * 2026. Wer hier das Kalenderjahr nimmt, legt die Woche ins falsche Jahr.
	 */
	public function test_woche_folgt_iso_ueber_den_jahreswechsel() {
		$woche = Period::bucket( 'woche', '2027-01-01' );

		$this->assertSame( '2026-W53', $woche['key'] );
		$this->assertSame( '2026-12-28', $woche['von'] );
		$this->assertSame( '2027-01-03', $woche['bis'] );
	}

	/**
	 * Eine Reihe endet am gewählten Datum und stösst lückenlos aneinander.
	 *
	 * Eine Lücke zwischen zwei Eimern zeigt der Graph als Delle, obwohl nur
	 * das Raster kaputt ist.
	 */
	public function test_reihe_ist_lueckenlos_und_endet_am_stichtag() {
		foreach ( Period::GRANULARITIES as $raster ) {
			$reihe = Period::series( $raster, '2026-08-15', 6 );

			$this->assertCount( 6, $reihe, $raster );
			$this->assertTrue( $reihe[5]['von'] <= '2026-08-15', $raster );
			$this->assertTrue( $reihe[5]['bis'] >= '2026-08-15', $raster );

			for ( $i = 1; $i < 6; $i++ ) {
				$naechster = gmdate( 'Y-m-d', strtotime( $reihe[ $i - 1 ]['bis'] . ' 12:00:00 UTC' ) + DAY_IN_SECONDS );

				$this->assertSame( $naechster, $reihe[ $i ]['von'], $raster . ': Lücke im Raster.' );
			}
		}
	}

	/**
	 * Mehr Eimer als die Obergrenze gibt es nicht – jeder kostet eine volle
	 * Kennzahlenrechnung.
	 */
	public function test_die_anzahl_ist_begrenzt() {
		$this->assertCount( Period::MAX_BUCKETS, Period::series( 'tag', '2026-08-15', 500 ) );
		$this->assertCount( 1, Period::series( 'tag', '2026-08-15', 0 ) );
	}

	/**
	 * Tage werden einschliesslich beider Ränder gezählt.
	 */
	public function test_tage_zaehlen_beide_raender() {
		$this->assertSame( 1, Period::days( '2026-08-15', '2026-08-15' ) );
		$this->assertSame( 31, Period::days( '2026-08-01', '2026-08-31' ) );
		$this->assertSame( 92, Period::days( '2026-07-01', '2026-09-30' ), 'Drittes Quartal.' );
		$this->assertSame( 0, Period::days( '2026-08-15', '2026-08-14' ) );
	}

	/**
	 * Die Überschneidung zweier Zeiträume.
	 */
	public function test_ueberschneidung() {
		$this->assertSame( 31, Period::overlap( '2026-08-01', '2026-08-31', '2026-07-01', '2026-09-30' ) );
		$this->assertSame( 3, Period::overlap( '2026-09-28', '2026-10-04', '2026-07-01', '2026-09-30' ) );
		$this->assertSame( 0, Period::overlap( '2026-10-01', '2026-10-31', '2026-07-01', '2026-09-30' ) );
	}

	/**
	 * Alle berührten Quartale, auch über den Jahreswechsel.
	 */
	public function test_beruehrte_quartale() {
		$this->assertSame( array( '2026-Q3' ), Period::quarters_between( '2026-08-01', '2026-08-31' ) );

		$this->assertSame(
			array( '2026-Q3', '2026-Q4' ),
			Period::quarters_between( '2026-09-28', '2026-10-04' )
		);

		$this->assertSame(
			array( '2026-Q4', '2027-Q1' ),
			Period::quarters_between( '2026-12-28', '2027-01-03' )
		);

		$this->assertCount( 4, Period::quarters_between( '2026-01-01', '2026-12-31' ) );
	}

	/**
	 * Ein Quartalsschlüssel lässt sich wieder in seine Grenzen auflösen.
	 */
	public function test_quartalsschluessel_und_grenzen_passen_zusammen() {
		foreach ( array( '2026-Q1', '2026-Q2', '2026-Q3', '2026-Q4' ) as $key ) {
			$grenzen = Period::quarter_bounds( $key );

			$this->assertSame( $key, $grenzen['key'] );
			$this->assertSame( $key, Period::bucket( 'quartal', $grenzen['von'] )['key'] );
			$this->assertSame( $key, Period::bucket( 'quartal', $grenzen['bis'] )['key'] );
		}

		$this->assertSame( array(), Period::quarter_bounds( '2026-Q5' ) );
		$this->assertSame( array(), Period::quarter_bounds( 'unsinn' ) );
	}

	/**
	 * Ein unbekanntes Raster fällt auf den Monat zurück, statt zu scheitern.
	 */
	public function test_unbekanntes_raster_faellt_zurueck() {
		$this->assertSame( 'monat', Period::clean( 'jahrzehnt' ) );
		$this->assertSame( 'woche', Period::clean( 'woche' ) );
	}

	/**
	 * Jedes Raster hat eine Beschriftung und eine Vorgabeanzahl.
	 */
	public function test_jedes_raster_ist_beschriftet() {
		$this->assertSame( Period::GRANULARITIES, array_keys( Period::labels() ) );

		foreach ( Period::GRANULARITIES as $raster ) {
			$this->assertGreaterThan( 1, Period::default_count( $raster ), $raster );
			$this->assertLessThanOrEqual( Period::MAX_BUCKETS, Period::default_count( $raster ), $raster );
		}
	}
}
