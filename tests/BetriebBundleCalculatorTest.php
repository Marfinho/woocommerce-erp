<?php
/**
 * Bestellart-Rechner: Set gegen Einzelverkauf.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\BundleCalculator;

/**
 * Der Kern: Verpackung und Zahlungsgebühr fallen je Bestellung an, nicht je
 * Position.
 */
class BetriebBundleCalculatorTest extends TestCase {

	/**
	 * Annahmen.
	 *
	 * @return array
	 */
	protected function settings() {
		return array(
			'filament_price_per_kg' => 22.0,
			'machine_rate_per_h'    => 0.20,
			'labor_rate_per_h'      => 25.0,
			'payment_fee_pct'       => 0.0249,
			'payment_fee_fixed'     => 0.35,
			'packaging_cost'        => 0.75,
			'scrap_surcharge_pct'   => 0.05,
			'vat_rate'              => 0.0,
		);
	}

	/**
	 * Stange als Zukaufartikel plus drei gedruckte Module.
	 *
	 * @return array
	 */
	protected function ctx() {
		return array(
			'articles'   => array(
				100 => array( 'id' => 100, 'sku' => 'HH-STANGE', 'name' => 'Stange', 'sourcing_type' => 'zukauf', 'price_gross' => 34.90, 'postprocessing_min' => 6 ),
				1   => array( 'id' => 1, 'sku' => 'HH-001', 'name' => 'Classic', 'sourcing_type' => 'druck', 'price_gross' => 19.90, 'postprocessing_min' => 7 ),
				2   => array( 'id' => 2, 'sku' => 'HH-002', 'name' => 'Helmhalter', 'sourcing_type' => 'druck', 'price_gross' => 22.90, 'postprocessing_min' => 8 ),
			),
			'revisions'  => array(
				1 => array( 'article_id' => 1, 'weight_g' => 55, 'parts_per_plate' => 6, 'print_time_plate_h' => 9 ),
				2 => array( 'article_id' => 2, 'weight_g' => 70, 'parts_per_plate' => 4, 'print_time_plate_h' => 8 ),
			),
			'components' => array(),
			'bom'        => array(),
			'sourcing'   => array(
				100 => array( 'article_id' => 100, 'purchase_price_net' => 6.40, 'own_processing_min' => 4 ),
			),
		);
	}

	/**
	 * Positionen eines Einstiegssets.
	 *
	 * @return array
	 */
	protected function items() {
		return array(
			array( 'article_id' => 100, 'qty' => 1 ),
			array( 'article_id' => 1, 'qty' => 1 ),
			array( 'article_id' => 2, 'qty' => 1 ),
		);
	}

	/**
	 * Verpackung und Zahlungsgebühr genau einmal je Set.
	 */
	public function test_verpackung_und_gebuehr_genau_einmal() {
		$set = BundleCalculator::calculate( $this->items(), 69.00, $this->ctx(), $this->settings() );

		$this->assertEqualsWithDelta( 0.75, $set['verpackung'], 0.00001, 'Verpackung genau einmal.' );
		$this->assertEqualsWithDelta( 69.00 * 0.0249 + 0.35, $set['zahlung'], 0.00001, 'Fixgebühr genau einmal.' );
	}

	/**
	 * Material wird nach Druck und Zukauf getrennt ausgewiesen.
	 */
	public function test_material_getrennt_nach_druck_und_zukauf() {
		$set = BundleCalculator::calculate( $this->items(), 69.00, $this->ctx(), $this->settings() );

		// Zukauf: die Stange.
		$this->assertEqualsWithDelta( 6.40, $set['material_zukauf'], 0.00001 );
		// Druck: 55 g + 70 g Filament.
		$this->assertEqualsWithDelta( 1.21 + 1.54, $set['material_druck'], 0.00001 );
	}

	/**
	 * Druckzeit und Nachbearbeitung summieren sich über das ganze Set.
	 */
	public function test_zeiten_summieren_sich() {
		$set = BundleCalculator::calculate( $this->items(), 69.00, $this->ctx(), $this->settings() );

		// 9/6 = 1,5 h + 8/4 = 2,0 h.
		$this->assertEqualsWithDelta( 3.5, $set['print_time_h'], 0.00001 );
		// Stange 6 + 4 aus der Beschaffung, Module 7 + 8.
		$this->assertEqualsWithDelta( 25.0, $set['postprocessing_min'], 0.00001 );
	}

	/**
	 * Das Set trägt sich besser als dieselben Positionen einzeln bestellt –
	 * genau um die eingesparten Verpackungen und Fixgebühren.
	 */
	public function test_set_schlaegt_einzelbestellungen() {
		$einzelsumme = 34.90 + 19.90 + 22.90;
		$vergleich   = BundleCalculator::compare( $this->items(), $einzelsumme, $this->ctx(), $this->settings() );

		$this->assertSame( 3, $vergleich['einzeln']['bestellungen'] );

		// Bei gleichem Erlös bleibt genau die Differenz der Fixkosten:
		// zwei eingesparte Verpackungen und zwei Fixgebühren.
		$this->assertEqualsWithDelta( 2 * ( 0.75 + 0.35 ), $vergleich['differenz_db1'], 0.00001 );
		$this->assertEqualsWithDelta( 2 * ( 0.75 + 0.35 ), $vergleich['ersparte_gebuehren'], 0.00001 );
	}

	/**
	 * Die Nachbearbeitungszeit ist in beiden Fällen gleich – der Vorteil des
	 * Sets liegt allein bei den Fixkosten je Bestellung.
	 */
	public function test_handling_bleibt_gleich() {
		$einzelsumme = 34.90 + 19.90 + 22.90;
		$vergleich   = BundleCalculator::compare( $this->items(), $einzelsumme, $this->ctx(), $this->settings() );

		$this->assertEqualsWithDelta(
			$vergleich['differenz_db1'],
			$vergleich['differenz_db2'],
			0.00001,
			'Handling fällt in beiden Fällen identisch an.'
		);
	}

	/**
	 * Ein Setrabatt schmälert den Vorteil, hebt ihn aber nicht zwingend auf.
	 */
	public function test_setpreis_unter_einzelsumme() {
		$set = BundleCalculator::calculate( $this->items(), 69.00, $this->ctx(), $this->settings() );

		$this->assertEqualsWithDelta( 69.00, $set['netto_erloes'], 0.00001 );
		$this->assertGreaterThan( 0, $set['db2'] );
		$this->assertLessThan( 1.0, $set['db1_pct'] );
	}

	/**
	 * Mehrfache Mengen einer Position werden korrekt vervielfacht.
	 */
	public function test_mengen_vervielfachen_sich() {
		$items = array( array( 'article_id' => 1, 'qty' => 3 ) );
		$set   = BundleCalculator::calculate( $items, 55.00, $this->ctx(), $this->settings() );

		$this->assertEqualsWithDelta( 3 * 1.51, $set['herstellkosten'], 0.00001 );
		$this->assertEqualsWithDelta( 3 * 1.5, $set['print_time_h'], 0.00001 );
		$this->assertEqualsWithDelta( 21.0, $set['postprocessing_min'], 0.00001 );
	}

	/**
	 * Leere Positionen liefern eine leere, aber rechenbare Kalkulation.
	 */
	public function test_leeres_set() {
		$set = BundleCalculator::calculate( array(), 0.0, $this->ctx(), $this->settings() );

		$this->assertSame( 0.0, $set['herstellkosten'] );
		// Verpackung und Fixgebühr fallen trotzdem an – eine Bestellung bleibt eine Bestellung.
		$this->assertEqualsWithDelta( -1.10, $set['db1'], 0.00001 );
	}
}
