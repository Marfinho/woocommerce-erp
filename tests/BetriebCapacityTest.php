<?php
/**
 * Kapazitätsvorschau und Rückwärtsrechnung.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\CapacityPlanner;

/**
 * Die knappste der drei Ressourcen muss korrekt benannt werden – ohne diese
 * Angabe ist die Zahl nicht handlungsleitend.
 */
class BetriebCapacityTest extends TestCase {

	/**
	 * Auslastung und Ampelstufe.
	 */
	public function test_auslastung_und_ampel() {
		$result = CapacityPlanner::utilisation( 91.0, 2, 65.0 );

		$this->assertEqualsWithDelta( 130.0, $result['capacity_h'], 0.00001 );
		$this->assertEqualsWithDelta( 0.7, $result['utilisation'], 0.00001 );
		$this->assertSame( 'gruen', $result['level'] );
	}

	/**
	 * Ab 75 Prozent gelb.
	 */
	public function test_ampel_gelb_ab_75_prozent() {
		$this->assertSame( 'gelb', CapacityPlanner::utilisation( 97.5, 2, 65.0 )['level'] );
		$this->assertSame( 'gruen', CapacityPlanner::utilisation( 97.0, 2, 65.0 )['level'] );
	}

	/**
	 * Ab 90 Prozent rot.
	 */
	public function test_ampel_rot_ab_90_prozent() {
		$this->assertSame( 'rot', CapacityPlanner::utilisation( 117.0, 2, 65.0 )['level'] );
	}

	/**
	 * Ohne Geräte gibt es keine Kapazität, aber auch keinen Fehler.
	 */
	public function test_ohne_geraete() {
		$result = CapacityPlanner::utilisation( 50.0, 0, 65.0 );

		$this->assertEqualsWithDelta( 0.0, $result['capacity_h'], 0.00001 );
		$this->assertEqualsWithDelta( 0.0, $result['utilisation'], 0.00001 );
	}

	/**
	 * Bedienzeit aus Plattenwechseln.
	 */
	public function test_bedienzeit() {
		$this->assertEqualsWithDelta( 2.0, CapacityPlanner::operator_hours( 10, 12 ), 0.00001 );
	}

	/**
	 * Ist die Druckzeit der Engpass, wird sie benannt.
	 */
	public function test_druckzeit_ist_der_engpass() {
		$result = CapacityPlanner::backwards(
			array( 'print_time_h' => 5.0, 'postprocessing_min' => 20.0, 'purchased' => array( 100 => 1 ) ),
			array( 'print_h' => 50.0, 'work_h' => 14.0, 'stock' => array( 100 => 40 ) )
		);

		// Druckzeit: 50/5 = 10. Arbeit: 14/(20/60) = 42. Zukauf: 40/1 = 40.
		$this->assertSame( 10, $result['max_orders'] );
		$this->assertSame( 'druckzeit', $result['limiting'] );
	}

	/**
	 * Ist die eigene Arbeitszeit der Engpass, wird sie benannt.
	 */
	public function test_arbeitszeit_ist_der_engpass() {
		$result = CapacityPlanner::backwards(
			array( 'print_time_h' => 1.0, 'postprocessing_min' => 60.0, 'purchased' => array( 100 => 1 ) ),
			array( 'print_h' => 130.0, 'work_h' => 14.0, 'stock' => array( 100 => 100 ) )
		);

		// Druckzeit: 130. Arbeit: 14/1 = 14. Zukauf: 100.
		$this->assertSame( 14, $result['max_orders'] );
		$this->assertSame( 'arbeitszeit', $result['limiting'] );
	}

	/**
	 * Ist der Zukaufbestand der Engpass, wird auch der knappste Artikel
	 * benannt – sonst weiß man nicht, was zu bestellen ist.
	 */
	public function test_zukauf_ist_der_engpass() {
		$result = CapacityPlanner::backwards(
			array( 'print_time_h' => 1.0, 'postprocessing_min' => 20.0, 'purchased' => array( 100 => 1, 200 => 2 ) ),
			array( 'print_h' => 130.0, 'work_h' => 40.0, 'stock' => array( 100 => 30, 200 => 12 ) )
		);

		// Zukauf: Artikel 100 → 30, Artikel 200 → 12/2 = 6. Knappster: 200.
		$this->assertSame( 6, $result['max_orders'] );
		$this->assertSame( 'zukauf', $result['limiting'] );
		$this->assertSame( 200, $result['scarcest_article'] );
	}

	/**
	 * Ohne Zukaufteile begrenzt der Bestand nicht.
	 */
	public function test_ohne_zukaufteile() {
		$result = CapacityPlanner::backwards(
			array( 'print_time_h' => 2.0, 'postprocessing_min' => 30.0, 'purchased' => array() ),
			array( 'print_h' => 100.0, 'work_h' => 20.0, 'stock' => array() )
		);

		// Druckzeit: 50. Arbeit: 20/0,5 = 40.
		$this->assertSame( 40, $result['max_orders'] );
		$this->assertSame( 'arbeitszeit', $result['limiting'] );
	}

	/**
	 * Ein leerer Zukaufbestand begrenzt auf null Bestellungen.
	 */
	public function test_leerer_zukaufbestand() {
		$result = CapacityPlanner::backwards(
			array( 'print_time_h' => 1.0, 'postprocessing_min' => 10.0, 'purchased' => array( 100 => 1 ) ),
			array( 'print_h' => 130.0, 'work_h' => 40.0, 'stock' => array() )
		);

		$this->assertSame( 0, $result['max_orders'] );
		$this->assertSame( 'zukauf', $result['limiting'] );
	}

	/**
	 * Die begrenzende Ressource wird auf Deutsch benannt.
	 */
	public function test_bezeichnung_der_ressource() {
		$this->assertSame( 'deine Arbeitszeit', CapacityPlanner::limit_label( 'arbeitszeit' ) );
		$this->assertSame( 'Zukaufbestand', CapacityPlanner::limit_label( 'zukauf' ) );
	}

	/**
	 * Ein Tag ist ein Siebtel einer Woche.
	 *
	 * Der Fehler, den dieser Test festhält: Die Kapazität wurde früher auf
	 * mindestens eine Woche geklemmt. Ein Tagesbericht verglich damit einen
	 * Tag Druckzeit gegen eine ganze Woche Kapazität und meldete ein Siebtel
	 * der echten Auslastung – eine Zahl, die niemandem auffällt, weil sie
	 * plausibel klein ist.
	 */
	public function test_ein_tag_ist_ein_siebtel_einer_woche() {
		$this->assertEqualsWithDelta( 1 / 7, CapacityPlanner::weeks( '2026-08-15', '2026-08-15' ), 0.0001 );
		$this->assertEqualsWithDelta( 1.0, CapacityPlanner::weeks( '2026-08-10', '2026-08-16' ), 0.0001 );
	}

	/**
	 * Der letzte Tag zählt mit.
	 *
	 * Ein August hat 31 Tage, nicht 30. Die alte Rechnung nahm die Differenz
	 * zweier Zeitstempel und verlor damit stillschweigend einen Tag – gut
	 * zwei Prozent, jeden Monat, in dieselbe Richtung.
	 */
	public function test_der_letzte_tag_zaehlt_mit() {
		$this->assertEqualsWithDelta( 31 / 7, CapacityPlanner::weeks( '2026-08-01', '2026-08-31' ), 0.0001 );
		$this->assertEqualsWithDelta( 92 / 7, CapacityPlanner::weeks( '2026-07-01', '2026-09-30' ), 0.0001 );
	}

	/**
	 * Die Kapazität ist Geräte mal Nettostunden mal Wochen.
	 */
	public function test_die_kapazitaet_ist_das_produkt() {
		$this->assertEqualsWithDelta(
			3 * 40.0 * 4.0,
			CapacityPlanner::capacity_hours( 3, 40.0, 4.0 ),
			0.0001
		);

		// Ohne Gerät keine Kapazität – und keine negative.
		$this->assertSame( 0.0, CapacityPlanner::capacity_hours( 0, 40.0, 4.0 ) );
		$this->assertSame( 0.0, CapacityPlanner::capacity_hours( -3, 40.0, 4.0 ) );
	}
}
