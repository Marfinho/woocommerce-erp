<?php
/**
 * Deckungsbeitrag einer einzelnen Bestellposition.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\CostCalculator;

/**
 * Die Größe, die vor der Zusage an einen Händler auf dem Bildschirm steht.
 */
class BetriebPositionContributionTest extends TestCase {

	/**
	 * Annahmen.
	 *
	 * @return array
	 */
	protected function settings() {
		return array(
			'vat_rate'            => 0.0,
			'scrap_surcharge_pct' => 0.05,
			'payment_fee_pct'     => 0.0249,
			'payment_fee_fixed'   => 0.35,
			'packaging_cost'      => 0.75,
			'labor_rate_per_h'    => 25.0,
		);
	}

	/**
	 * Herstellkosten von HH-001 samt Kleinteilen.
	 *
	 * @return array
	 */
	protected function cost() {
		return array(
			'herstellkosten'     => 2.06,
			'eigenmaterial'      => 1.21,
			'eigenmaschine'      => 0.30,
			'komponenten'        => 0.55,
			'unterbaugruppen'    => 0.0,
			'print_time_h'       => 1.5,
			'postprocessing_min' => 7.0,
			'positionen'         => array(),
			'errors'             => array(),
		);
	}

	/**
	 * Zum UVP trägt sich die Position deutlich.
	 */
	public function test_zum_uvp() {
		$result = CostCalculator::position_contribution( $this->cost(), 19.90, $this->settings() );

		// 19,90 − (2,06 + 0,103 + 0,49551) = 17,24149; minus 2,91667 Handling.
		$this->assertEqualsWithDelta( 17.2415, $result['db1'], 0.0001 );
		$this->assertEqualsWithDelta( 14.3248, $result['db2'], 0.0001 );
	}

	/**
	 * Verpackung und feste Zahlungsgebühr fehlen bewusst – sie fallen je
	 * Bestellung an, nicht je Position. Sonst rechnete eine Bestellung mit
	 * fünf Positionen künstlich schlecht.
	 */
	public function test_ohne_kosten_je_bestellung() {
		$result = CostCalculator::position_contribution( $this->cost(), 19.90, $this->settings() );

		$this->assertArrayNotHasKey( 'verpackung', $result );

		// Der Unterschied zur vollen Rechnung ist genau Verpackung plus Fixgebühr.
		$voll = CostCalculator::contribution_from_cost( $this->cost(), 19.90, $this->settings() );
		$this->assertEqualsWithDelta( 0.75 + 0.35, $result['db2'] - $voll['db2'], 0.0001 );
	}

	/**
	 * Bei 50 % Händlerrabatt schrumpft der Beitrag deutlich, bleibt aber positiv.
	 */
	public function test_mit_haendlerrabatt() {
		$result = CostCalculator::position_contribution( $this->cost(), 9.95, $this->settings() );

		$this->assertGreaterThan( 0, $result['db2'] );
		$this->assertLessThan( 5.0, $result['db2'] );
	}

	/**
	 * Fällt der Beitrag unter die Warnschwelle, ist das an der Zahl ablesbar.
	 */
	public function test_unter_der_warnschwelle() {
		$result = CostCalculator::position_contribution( $this->cost(), 7.50, $this->settings() );

		$this->assertLessThan( 4.0, $result['db2'], 'Startwert der Warnschwelle ist 4,00 €.' );
	}

	/**
	 * Ein zu tiefer Preis kann den Beitrag negativ machen – genau davor soll
	 * die Warnung schützen.
	 */
	public function test_negativer_beitrag() {
		$result = CostCalculator::position_contribution( $this->cost(), 4.00, $this->settings() );

		$this->assertLessThan( 0, $result['db2'] );
	}

	/**
	 * Mit Regelbesteuerung sinkt der Nettoerlös und damit der Beitrag.
	 */
	public function test_mit_umsatzsteuer() {
		$settings             = $this->settings();
		$settings['vat_rate'] = 0.19;

		$mit  = CostCalculator::position_contribution( $this->cost(), 19.90, $settings );
		$ohne = CostCalculator::position_contribution( $this->cost(), 19.90, $this->settings() );

		$this->assertLessThan( $ohne['db2'], $mit['db2'] );
		$this->assertEqualsWithDelta( 16.7227, $mit['netto_erloes'], 0.0001 );
	}

	/**
	 * Ohne Nachbearbeitung sind DB I und DB II gleich.
	 */
	public function test_ohne_nachbearbeitung() {
		$cost                       = $this->cost();
		$cost['postprocessing_min'] = 0.0;

		$result = CostCalculator::position_contribution( $cost, 19.90, $this->settings() );

		$this->assertEqualsWithDelta( $result['db1'], $result['db2'], 0.00001 );
	}
}
