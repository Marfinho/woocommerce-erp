<?php
/**
 * Preisvorschlag aus einer gewünschten Marge.
 *
 * Die Rückwärtsrechnung ist heikel, weil die Zahlungsgebühr am Preis selbst
 * hängt: Ein höherer Preis erzeugt eine höhere Gebühr. Ein naiver Aufschlag
 * verfehlt die Marge deshalb immer ein Stück weit. Diese Tests prüfen die
 * Rechnung, indem sie das Ergebnis durch die Vorwärtsrechnung schicken.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\CostCalculator;

/**
 * Prüft, dass der vorgeschlagene Preis die Marge tatsächlich trifft.
 */
class BetriebPriceForMarginTest extends TestCase {

	/**
	 * Übliche Annahmen eines Kleinbetriebs.
	 *
	 * @return array
	 */
	protected function settings() {
		return array(
			'vat_rate'            => 0.19,
			'payment_fee_pct'     => 0.0249,
			'payment_fee_fixed'   => 0.35,
			'packaging_cost'      => 0.85,
			'scrap_surcharge_pct' => 0.05,
			'labor_rate_per_h'    => 18.0,
		);
	}

	/**
	 * Herstellkosten einer Beispielposition.
	 *
	 * @param float $herstell Herstellkosten.
	 * @param float $minuten  Nachbearbeitungszeit.
	 * @return array
	 */
	protected function cost( $herstell = 6.40, $minuten = 4 ) {
		return array(
			'herstellkosten'     => $herstell,
			'eigenmaterial'      => $herstell,
			'eigenmaschine'      => 0.0,
			'komponenten'        => 0.0,
			'unterbaugruppen'    => 0.0,
			'postprocessing_min' => $minuten,
			'print_time_h'       => 0.0,
			'positionen'         => array(),
			'errors'             => array(),
			'sku'                => 'TEST',
			'name'               => 'Testartikel',
			'sourcing_type'      => 'druck',
		);
	}

	/**
	 * Der vorgeschlagene Preis trifft die Marge, wenn man ihn durch die
	 * Vorwärtsrechnung schickt. Das ist die eigentliche Zusicherung.
	 */
	public function test_vorschlag_trifft_die_marge() {
		$settings = $this->settings();
		$cost     = $this->cost();

		foreach ( array( 0.30, 0.45, 0.60 ) as $margin ) {
			$preis = CostCalculator::price_for_margin( $cost, $settings, $margin );

			$this->assertNotNull( $preis, 'Marge ' . $margin );

			$ergebnis = CostCalculator::contribution_from_cost( $cost, $preis, $settings );

			$this->assertEqualsWithDelta(
				$margin,
				$ergebnis['db2_pct'],
				0.0005,
				sprintf( 'Marge %s wurde mit Preis %s nicht getroffen.', $margin, $preis )
			);
		}
	}

	/**
	 * Höhere Herstellkosten ergeben einen höheren Preis.
	 */
	public function test_hoehere_kosten_ergeben_hoeheren_preis() {
		$settings = $this->settings();

		$guenstig = CostCalculator::price_for_margin( $this->cost( 4.00 ), $settings, 0.45 );
		$teuer    = CostCalculator::price_for_margin( $this->cost( 9.00 ), $settings, 0.45 );

		$this->assertGreaterThan( $guenstig, $teuer );
	}

	/**
	 * Nachbearbeitungszeit schlägt auf den Preis durch – sie ist Teil des
	 * Deckungsbeitrags II.
	 */
	public function test_nachbearbeitung_erhoeht_den_preis() {
		$settings = $this->settings();

		$ohne = CostCalculator::price_for_margin( $this->cost( 6.40, 0 ), $settings, 0.45 );
		$mit  = CostCalculator::price_for_margin( $this->cost( 6.40, 10 ), $settings, 0.45 );

		$this->assertGreaterThan( $ohne, $mit );
	}

	/**
	 * Marge 0 deckt genau die Kosten – der Deckungsbeitrag II ist dann null.
	 */
	public function test_marge_null_deckt_genau_die_kosten() {
		$settings = $this->settings();
		$cost     = $this->cost();

		$preis    = CostCalculator::price_for_margin( $cost, $settings, 0 );
		$ergebnis = CostCalculator::contribution_from_cost( $cost, $preis, $settings );

		$this->assertEqualsWithDelta( 0.0, $ergebnis['db2'], 0.02 );
	}

	/**
	 * Unerreichbare Margen werden abgewiesen statt geraten.
	 */
	public function test_unmoegliche_margen_werden_abgewiesen() {
		$settings = $this->settings();

		$this->assertNull( CostCalculator::price_for_margin( $this->cost(), $settings, 1.0 ) );
		$this->assertNull( CostCalculator::price_for_margin( $this->cost(), $settings, 1.5 ) );
		$this->assertNull( CostCalculator::price_for_margin( $this->cost(), $settings, -0.1 ) );
	}

	/**
	 * Frisst die variable Gebühr die gewünschte Marge auf, gibt es keinen
	 * Preis – auch keinen unendlich hohen.
	 */
	public function test_unbezahlbare_gebuehr_ergibt_keinen_preis() {
		$settings                    = $this->settings();
		$settings['payment_fee_pct'] = 0.60;

		$this->assertNull( CostCalculator::price_for_margin( $this->cost(), $settings, 0.45 ) );
	}
}
