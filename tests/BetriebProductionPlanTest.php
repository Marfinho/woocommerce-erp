<?php
/**
 * Produktionsplan: Reihenfolge, Aufwand, Wochenverteilung.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\ProductionPlan;
use PHPUnit\Framework\TestCase;

/**
 * Ein Plan, der die Maschinenzeit mit der eigenen Arbeitszeit verrechnet,
 * plant eine Woche, die es nicht gibt. Deshalb zwei getrennte Größen.
 */
class BetriebProductionPlanTest extends TestCase {

	/**
	 * Ein Druckteil belegt Drucker und Hände.
	 */
	public function test_druck_belegt_maschine_und_haende() {
		$aufwand = ProductionPlan::effort(
			array( 'net' => 20, 'action' => 'drucken' ),
			array( 'print_time_h' => 0.5, 'parts_per_plate' => 5, 'postprocessing_min' => 3 ),
			10
		);

		$this->assertSame( 10.0, $aufwand['machine_h'] );
		$this->assertSame( 4, $aufwand['plates'] );
		// 20 x 3 min Nacharbeit + 4 Platten x 10 min = 60 + 40 = 100 min.
		$this->assertSame( 1.67, $aufwand['operator_h'] );
	}

	/**
	 * Eine Montage belegt keinen Drucker.
	 */
	public function test_montage_belegt_keinen_drucker() {
		$aufwand = ProductionPlan::effort(
			array( 'net' => 10, 'action' => 'montieren' ),
			array( 'print_time_h' => 99, 'postprocessing_min' => 6 ),
			10
		);

		$this->assertSame( 0.0, $aufwand['machine_h'] );
		$this->assertSame( 0, $aufwand['plates'] );
		$this->assertSame( 1.0, $aufwand['operator_h'] );
	}

	/**
	 * Ohne Menge kein Aufwand.
	 */
	public function test_ohne_menge_kein_aufwand() {
		$aufwand = ProductionPlan::effort( array( 'net' => 0, 'action' => 'drucken' ), array( 'print_time_h' => 2 ), 10 );

		$this->assertSame( 0.0, $aufwand['machine_h'] );
		$this->assertSame( 0.0, $aufwand['operator_h'] );
	}

	/**
	 * Eine angebrochene Platte zählt voll – halbe Platten gibt es nicht.
	 */
	public function test_angebrochene_platte_zaehlt_voll() {
		$aufwand = ProductionPlan::effort(
			array( 'net' => 11, 'action' => 'drucken' ),
			array( 'print_time_h' => 1, 'parts_per_plate' => 5 ),
			0
		);

		$this->assertSame( 3, $aufwand['plates'] );
	}

	/**
	 * Was tiefer in der Stückliste steckt, kommt zuerst.
	 */
	public function test_tiefste_stufe_zuerst() {
		$geordnet = ProductionPlan::order(
			array(
				array( 'sku' => 'MODUL', 'depth' => 0, 'machine_h' => 1 ),
				array( 'sku' => 'ROHLING', 'depth' => 2, 'machine_h' => 1 ),
				array( 'sku' => 'HALBZEUG', 'depth' => 1, 'machine_h' => 1 ),
			)
		);

		$this->assertSame( array( 'ROHLING', 'HALBZEUG', 'MODUL' ), array_column( $geordnet, 'sku' ) );
	}

	/**
	 * Innerhalb einer Stufe zuerst, was am längsten läuft.
	 */
	public function test_langes_zuerst_in_der_stufe() {
		$geordnet = ProductionPlan::order(
			array(
				array( 'sku' => 'KURZ', 'depth' => 1, 'machine_h' => 2 ),
				array( 'sku' => 'LANG', 'depth' => 1, 'machine_h' => 9 ),
			)
		);

		$this->assertSame( 'LANG', $geordnet[0]['sku'] );
	}

	/**
	 * Ist die Woche voll, beginnt die nächste.
	 */
	public function test_volle_woche_schiebt_weiter() {
		$plan = ProductionPlan::schedule(
			array(
				array( 'sku' => 'A', 'machine_h' => 30, 'operator_h' => 5, 'plates' => 0 ),
				array( 'sku' => 'B', 'machine_h' => 30, 'operator_h' => 5, 'plates' => 0 ),
				array( 'sku' => 'C', 'machine_h' => 10, 'operator_h' => 2, 'plates' => 0 ),
			),
			40,
			20
		);

		$this->assertSame( array( 1, 2, 2 ), array_column( $plan, 'week' ) );
	}

	/**
	 * Auch die Handarbeit begrenzt – nicht nur der Drucker.
	 */
	public function test_haende_begrenzen_ebenfalls() {
		$plan = ProductionPlan::schedule(
			array(
				array( 'sku' => 'A', 'machine_h' => 1, 'operator_h' => 15, 'plates' => 0 ),
				array( 'sku' => 'B', 'machine_h' => 1, 'operator_h' => 15, 'plates' => 0 ),
			),
			100,
			20
		);

		$this->assertSame( array( 1, 2 ), array_column( $plan, 'week' ) );
	}

	/**
	 * Ein Schritt, der für sich größer ist als eine ganze Woche, bekommt
	 * trotzdem eine – sonst schöbe die Rechnung ihn endlos vor sich her.
	 */
	public function test_zu_grosser_schritt_bekommt_trotzdem_eine_woche() {
		$plan = ProductionPlan::schedule(
			array( array( 'sku' => 'RIESE', 'machine_h' => 500, 'operator_h' => 0, 'plates' => 0 ) ),
			40,
			20
		);

		$this->assertSame( 1, $plan[0]['week'] );
	}

	/**
	 * Summen je Woche und insgesamt.
	 */
	public function test_summen_je_woche() {
		$plan = array(
			array( 'week' => 1, 'machine_h' => 10, 'operator_h' => 2, 'plates' => 3 ),
			array( 'week' => 1, 'machine_h' => 5,  'operator_h' => 1, 'plates' => 2 ),
			array( 'week' => 2, 'machine_h' => 7,  'operator_h' => 4, 'plates' => 1 ),
		);

		$summen = ProductionPlan::totals( $plan );

		$this->assertSame( 22.0, $summen['total']['machine_h'] );
		$this->assertSame( 6, $summen['total']['plates'] );
		$this->assertSame( 3, $summen['total']['steps'] );
		$this->assertSame( 15.0, $summen['weeks'][1]['machine_h'] );
		$this->assertSame( 4.0, $summen['weeks'][2]['operator_h'] );
		$this->assertSame( 2, $summen['weeks'][1]['steps'] );
	}

	/**
	 * Ein leerer Bedarf ergibt einen leeren Plan, keinen Fehler.
	 */
	public function test_leerer_bedarf_leerer_plan() {
		$summen = ProductionPlan::totals( ProductionPlan::schedule( ProductionPlan::order( array() ), 40, 20 ) );

		$this->assertSame( 0, $summen['total']['steps'] );
		$this->assertSame( array(), $summen['weeks'] );
	}
}
