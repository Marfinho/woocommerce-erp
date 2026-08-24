<?php
/**
 * Prüft Stundenlöhne und Ist-Lohnkosten.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\LaborCost;
use PHPUnit\Framework\TestCase;

/**
 * Lohn-Tests.
 */
class BetriebLaborCostTest extends TestCase {

	/**
	 * Zwei Nutzer, einer mit Erhöhung zur Jahresmitte.
	 *
	 * @return array
	 */
	protected function loehne() {
		return array(
			array( 'user_id' => 7, 'rate_per_h' => 14.0, 'valid_from' => '2026-01-01' ),
			array( 'user_id' => 7, 'rate_per_h' => 15.5, 'valid_from' => '2026-07-01' ),
			array( 'user_id' => 9, 'rate_per_h' => 22.0, 'valid_from' => '2026-03-01' ),
		);
	}

	/**
	 * Es gilt der Lohn, der am Tag der Arbeit galt.
	 */
	public function test_der_lohn_gilt_ab_seinem_stichtag() {
		$rows = $this->loehne();

		$this->assertSame( 14.0, LaborCost::rate_at( $rows, 7, '2026-06-30' ) );
		$this->assertSame( 15.5, LaborCost::rate_at( $rows, 7, '2026-07-01' ) );
		$this->assertSame( 15.5, LaborCost::rate_at( $rows, 7, '2026-12-31' ) );
	}

	/**
	 * Eine Erhöhung ändert die Vergangenheit nicht. Sonst wäre jeder
	 * Vorjahresvergleich wertlos.
	 */
	public function test_eine_erhoehung_wirkt_nicht_rueckwirkend() {
		$mai = LaborCost::entry_cost(
			array( 'created_by' => 7, 'entry_date' => '2026-05-04', 'hours' => 10.0 ),
			$this->loehne()
		);

		$this->assertSame( 140.0, $mai );
	}

	/**
	 * Vor dem ersten Stichtag gibt es keinen Lohn – und keine erfundene
	 * Null.
	 */
	public function test_vor_dem_ersten_stichtag_gibt_es_keinen_lohn() {
		$this->assertNull( LaborCost::rate_at( $this->loehne(), 9, '2026-02-28' ) );
		$this->assertNull( LaborCost::rate_at( $this->loehne(), 99, '2026-08-01' ) );
	}

	/**
	 * Stunden ohne hinterlegten Lohn werden getrennt ausgewiesen statt mit
	 * null bewertet – sonst sähe der Betrieb billiger aus, als er ist.
	 */
	public function test_stunden_ohne_lohn_werden_getrennt_gezaehlt() {
		$summe = LaborCost::total(
			array(
				array( 'created_by' => 7, 'entry_date' => '2026-08-03', 'hours' => 4.0 ),
				array( 'created_by' => 42, 'entry_date' => '2026-08-03', 'hours' => 6.0 ),
			),
			$this->loehne()
		);

		$this->assertSame( 62.0, $summe['cost'] );
		$this->assertSame( 4.0, $summe['hours'] );
		$this->assertSame( 6.0, $summe['hours_unpriced'] );
		$this->assertSame( 15.5, $summe['rate'] );
	}

	/**
	 * Ohne bewertbare Stunden gibt es keinen Mischsatz.
	 */
	public function test_ohne_bewertete_stunden_kein_mischsatz() {
		$summe = LaborCost::total(
			array( array( 'created_by' => 42, 'entry_date' => '2026-08-03', 'hours' => 6.0 ) ),
			$this->loehne()
		);

		$this->assertNull( $summe['rate'] );
		$this->assertSame( 0.0, $summe['cost'] );
	}

	/**
	 * Der Mischsatz ist nach Stunden gewichtet, nicht der Mittelwert der
	 * Löhne.
	 */
	public function test_der_mischsatz_ist_nach_stunden_gewichtet() {
		$summe = LaborCost::total(
			array(
				array( 'created_by' => 7, 'entry_date' => '2026-08-03', 'hours' => 9.0 ),
				array( 'created_by' => 9, 'entry_date' => '2026-08-03', 'hours' => 1.0 ),
			),
			$this->loehne()
		);

		$this->assertSame( 10.0, $summe['hours'] );
		$this->assertEqualsWithDelta( 16.15, $summe['rate'], 0.0001 );
	}

	/**
	 * Kosten je Nutzer.
	 */
	public function test_kosten_je_nutzer() {
		$je = LaborCost::by_user(
			array(
				array( 'created_by' => 7, 'entry_date' => '2026-08-03', 'hours' => 2.0 ),
				array( 'created_by' => 7, 'entry_date' => '2026-08-04', 'hours' => 3.0 ),
				array( 'created_by' => 9, 'entry_date' => '2026-08-04', 'hours' => 1.0 ),
			),
			$this->loehne()
		);

		$this->assertSame( 77.5, $je[7]['cost'] );
		$this->assertSame( 22.0, $je[9]['cost'] );
	}

	/**
	 * Kosten je Auftrag – Buchungen ohne Auftrag zählen nicht mit.
	 */
	public function test_kosten_je_auftrag() {
		$je = LaborCost::by_work_order(
			array(
				array( 'created_by' => 7, 'entry_date' => '2026-08-03', 'hours' => 2.0, 'work_order_id' => 5 ),
				array( 'created_by' => 9, 'entry_date' => '2026-08-03', 'hours' => 1.0, 'work_order_id' => 5 ),
				array( 'created_by' => 7, 'entry_date' => '2026-08-03', 'hours' => 8.0, 'work_order_id' => 0 ),
			),
			$this->loehne()
		);

		$this->assertSame( array( 5 ), array_keys( $je ) );
		$this->assertSame( 53.0, $je[5]['cost'] );
		$this->assertSame( 3.0, $je[5]['hours'] );
	}

	/**
	 * Der Vergleich mit dem kalkulatorischen Satz stuft die Abweichung ein.
	 */
	public function test_vergleich_stuft_die_abweichung_ein() {
		$this->assertSame( 'gruen', LaborCost::compare( 36.0, 35.0 )['level'] );
		$this->assertSame( 'gelb', LaborCost::compare( 41.0, 35.0 )['level'] );
		$this->assertSame( 'rot', LaborCost::compare( 50.0, 35.0 )['level'] );

		// Auch nach unten: Ein zu hoch angesetzter Satz verteuert die Preise
		// ohne Grund und gehört genauso bemerkt.
		$this->assertSame( 'rot', LaborCost::compare( 20.0, 35.0 )['level'] );
	}

	/**
	 * Ohne Ist-Satz wird nicht verglichen, sondern geschwiegen.
	 */
	public function test_ohne_ist_satz_kein_vergleich() {
		$vergleich = LaborCost::compare( null, 35.0 );

		$this->assertFalse( $vergleich['known'] );
		$this->assertNull( $vergleich['diff'] );
		$this->assertSame( 'neutral', $vergleich['level'] );
	}

	/**
	 * Auch ohne kalkulatorischen Satz gibt es nichts zu vergleichen.
	 */
	public function test_ohne_kalkulatorischen_satz_kein_vergleich() {
		$this->assertFalse( LaborCost::compare( 18.0, 0 )['known'] );
	}

	/**
	 * Die Abweichung wird auch beziffert, nicht nur eingefärbt.
	 */
	public function test_die_abweichung_wird_beziffert() {
		$vergleich = LaborCost::compare( 42.0, 35.0 );

		$this->assertSame( 7.0, $vergleich['diff'] );
		$this->assertEqualsWithDelta( 0.2, $vergleich['diff_pct'], 0.0001 );
	}
}
