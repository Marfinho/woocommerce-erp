<?php
/**
 * ISO-Wochenberechnung, besonders an Jahresgrenzen.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\IsoWeek;

/**
 * Das ISO-Jahr ist nicht das Kalenderjahr – daran scheitern Wochenauswertungen
 * über den Jahreswechsel.
 */
class BetriebIsoWeekTest extends TestCase {

	/**
	 * Ein Datum mitten im Jahr.
	 */
	public function test_gewoehnliche_woche() {
		$result = IsoWeek::from_date( '2026-08-24' );

		$this->assertSame( 2026, $result['iso_year'] );
		$this->assertSame( 35, $result['iso_week'] );
	}

	/**
	 * Der 1. Januar 2027 ist ein Freitag und gehört noch zu Woche 53 des
	 * Jahres 2026.
	 */
	public function test_neujahr_gehoert_zum_vorjahr() {
		$result = IsoWeek::from_date( '2027-01-01' );

		$this->assertSame( 2026, $result['iso_year'] );
		$this->assertSame( 53, $result['iso_week'] );
	}

	/**
	 * Der 31. Dezember 2024 ist ein Dienstag und gehört schon zu Woche 1
	 * des Jahres 2025.
	 */
	public function test_silvester_gehoert_zum_folgejahr() {
		$result = IsoWeek::from_date( '2024-12-31' );

		$this->assertSame( 2025, $result['iso_year'] );
		$this->assertSame( 1, $result['iso_week'] );
	}

	/**
	 * Der 4. Januar liegt immer in Woche 1.
	 */
	public function test_vierter_januar_ist_immer_woche_eins() {
		foreach ( range( 2024, 2032 ) as $jahr ) {
			$result = IsoWeek::from_date( sprintf( '%d-01-04', $jahr ) );

			$this->assertSame( 1, $result['iso_week'], 'Jahr ' . $jahr );
			$this->assertSame( $jahr, $result['iso_year'], 'Jahr ' . $jahr );
		}
	}

	/**
	 * 2026 hat 53 Wochen, 2025 nur 52.
	 */
	public function test_wochen_je_jahr() {
		$this->assertSame( 53, IsoWeek::weeks_in_year( 2026 ) );
		$this->assertSame( 52, IsoWeek::weeks_in_year( 2025 ) );
		$this->assertSame( 52, IsoWeek::weeks_in_year( 2027 ) );
	}

	/**
	 * Wochengrenzen sind Montag bis Sonntag.
	 */
	public function test_wochengrenzen() {
		$bounds = IsoWeek::bounds( 2026, 35 );

		$this->assertSame( '2026-08-24', $bounds['von'] );
		$this->assertSame( '2026-08-30', $bounds['bis'] );
		$this->assertSame( 'Mon', gmdate( 'D', strtotime( $bounds['von'] . ' 12:00:00 UTC' ) ) );
		$this->assertSame( 'Sun', gmdate( 'D', strtotime( $bounds['bis'] . ' 12:00:00 UTC' ) ) );
	}

	/**
	 * Datum und Grenzen sind zueinander konsistent.
	 */
	public function test_grenzen_und_datum_passen_zusammen() {
		foreach ( array( '2026-01-01', '2026-12-31', '2027-01-01', '2024-12-30' ) as $datum ) {
			$iso    = IsoWeek::from_date( $datum );
			$bounds = IsoWeek::bounds( $iso['iso_year'], $iso['iso_week'] );

			$this->assertGreaterThanOrEqual( $bounds['von'], $datum, $datum );
			$this->assertLessThanOrEqual( $bounds['bis'], $datum, $datum );
		}
	}

	/**
	 * Eine Wochenfolge läuft korrekt über den Jahreswechsel – auch über ein
	 * Jahr mit 53 Wochen.
	 */
	public function test_folge_ueber_den_jahreswechsel() {
		$weeks = IsoWeek::sequence( 2026, 52, 4 );

		$this->assertSame( array( 'iso_year' => 2026, 'iso_week' => 52 ), $weeks[0] );
		$this->assertSame( array( 'iso_year' => 2026, 'iso_week' => 53 ), $weeks[1], '2026 hat eine 53. Woche.' );
		$this->assertSame( array( 'iso_year' => 2027, 'iso_week' => 1 ), $weeks[2] );
		$this->assertSame( array( 'iso_year' => 2027, 'iso_week' => 2 ), $weeks[3] );
	}

	/**
	 * Rückwärts über die Jahresgrenze: Eine Woche vor KW 1/2027 ist
	 * KW 53/2026 – nicht KW 0 und nicht wieder KW 1.
	 */
	public function test_rueckwaerts_ueber_die_jahresgrenze() {
		$this->assertSame( array( 'iso_year' => 2026, 'iso_week' => 53 ), IsoWeek::shift( 2027, 1, -1 ) );
		$this->assertSame( array( 'iso_year' => 2026, 'iso_week' => 52 ), IsoWeek::shift( 2027, 1, -2 ) );
		$this->assertSame( array( 'iso_year' => 2025, 'iso_week' => 52 ), IsoWeek::shift( 2026, 1, -1 ) );
	}

	/**
	 * Acht Wochen zurück aus KW 3 landet im Vorjahr und liefert acht
	 * verschiedene Wochen – nicht mehrfach dieselbe.
	 */
	public function test_acht_wochen_zurueck_sind_acht_verschiedene() {
		$wochen = array();

		for ( $i = 0; $i < 8; $i++ ) {
			$week     = IsoWeek::shift( 2027, 3, -$i );
			$wochen[] = $week['iso_year'] . '-' . $week['iso_week'];
		}

		$this->assertCount( 8, array_unique( $wochen ) );
		$this->assertSame( '2026-49', end( $wochen ) );
	}

	/**
	 * Vorwärts verhält sich shift() wie die Folge.
	 */
	public function test_vorwaerts_entspricht_der_folge() {
		$folge = IsoWeek::sequence( 2026, 52, 3 );

		$this->assertSame( $folge[1], IsoWeek::shift( 2026, 52, 1 ) );
		$this->assertSame( $folge[2], IsoWeek::shift( 2026, 52, 2 ) );
	}

	/**
	 * Eine Verschiebung um null verändert nichts.
	 */
	public function test_verschiebung_um_null() {
		$this->assertSame( array( 'iso_year' => 2026, 'iso_week' => 35 ), IsoWeek::shift( 2026, 35, 0 ) );
	}

	/**
	 * In einem Jahr mit 52 Wochen springt die Folge direkt weiter.
	 */
	public function test_folge_bei_52_wochen() {
		$weeks = IsoWeek::sequence( 2025, 52, 2 );

		$this->assertSame( array( 'iso_year' => 2025, 'iso_week' => 52 ), $weeks[0] );
		$this->assertSame( array( 'iso_year' => 2026, 'iso_week' => 1 ), $weeks[1] );
	}
}
