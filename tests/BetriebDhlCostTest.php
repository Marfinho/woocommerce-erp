<?php
/**
 * Prüft die Porto-Rechnung aus den Vertragspreisen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\Dhl\DhlCost;
use PHPUnit\Framework\TestCase;

/**
 * Porto-Tests.
 */
class BetriebDhlCostTest extends TestCase {

	/**
	 * Preise ohne Zuschläge, damit die Grundpreise einzeln geprüft werden.
	 *
	 * @return array
	 */
	protected function plain() {
		$cfg                         = DhlCost::defaults();
		$cfg['zuschlaege']['maut']  = 0;
		$cfg['zuschlaege']['peak']  = 0;
		$cfg['zuschlaege']['pip']   = 0;

		return $cfg;
	}

	public function test_paket_nach_gewichtsstufe() {
		$cfg = $this->plain();

		$this->assertSame( 5.00, DhlCost::estimate( $cfg, 'V01PAK', 'DE', 800, false, '2026-12-01' )['net'] );
		$this->assertSame( 5.00, DhlCost::estimate( $cfg, 'V01PAK', 'DE', 1000, false, '2026-12-01' )['net'], 'Genau 1 kg gehört noch in die erste Stufe.' );
		$this->assertSame( 5.80, DhlCost::estimate( $cfg, 'V01PAK', 'DE', 1001, false, '2026-12-01' )['net'] );
		$this->assertSame( 17.25, DhlCost::estimate( $cfg, 'V01PAK', 'DE', 31500, false, '2026-12-01' )['net'] );
	}

	public function test_zu_schwer_oder_ohne_gewicht_gibt_keinen_preis() {
		$cfg = $this->plain();

		$this->assertFalse( DhlCost::estimate( $cfg, 'V01PAK', 'DE', 31501, false, '2026-12-01' )['ok'] );
		$this->assertFalse( DhlCost::estimate( $cfg, 'V01PAK', 'DE', 0, false, '2026-12-01' )['ok'] );
	}

	public function test_europaket_nach_zone() {
		$cfg = $this->plain();

		$this->assertSame( 15.30, DhlCost::estimate( $cfg, 'V54EPAK', 'AT', 1200, false, '2026-12-01' )['net'], 'Österreich ist Zone 1.' );
		$this->assertSame( 18.80, DhlCost::estimate( $cfg, 'V54EPAK', 'GB', 4200, false, '2026-12-01' )['net'], 'Großbritannien ist Zone 2.' );
		$this->assertSame( 25.00, DhlCost::estimate( $cfg, 'V54EPAK', 'NO', 9900, false, '2026-12-01' )['net'], 'Norwegen ist Zone 3.' );
		$this->assertSame( 48.65, DhlCost::estimate( $cfg, 'V54EPAK', 'RO', 31500, false, '2026-12-01' )['net'] );
	}

	public function test_land_ohne_preis_in_der_liste() {
		$r = DhlCost::estimate( $this->plain(), 'V54EPAK', 'MT', 1000, false, '2026-12-01' );

		$this->assertFalse( $r['ok'] );
		$this->assertNull( $r['net'] );
	}

	public function test_sperrgut_kommt_dazu() {
		$cfg = $this->plain();

		$this->assertSame( 26.00, DhlCost::estimate( $cfg, 'V01PAK', 'DE', 800, true, '2026-12-01' )['net'] );
		$this->assertSame( 43.20, DhlCost::estimate( $cfg, 'V54EPAK', 'AT', 1000, true, '2026-12-01' )['net'] );
	}

	public function test_zuschlaege_maut_immer_peak_nur_in_den_peak_monaten() {
		$cfg = DhlCost::defaults();

		$this->assertSame( 5.38, DhlCost::estimate( $cfg, 'V01PAK', 'DE', 800, false, '2026-11-23' )['net'], 'Paket 5,00 + Maut 0,19 + Peak 0,19.' );
		$this->assertSame( 5.19, DhlCost::estimate( $cfg, 'V01PAK', 'DE', 800, false, '2027-03-10' )['net'], 'Außerhalb der Peak-Monate nur die Maut.' );
	}

	public function test_peak_in_peak_nur_im_eingetragenen_zeitraum() {
		$cfg                          = $this->plain();
		$cfg['zuschlaege']['pip']     = 0.50;
		$cfg['zuschlaege']['pip_von'] = '2026-12-01';
		$cfg['zuschlaege']['pip_bis'] = '2026-12-24';

		$this->assertSame( 5.50, DhlCost::estimate( $cfg, 'V01PAK', 'DE', 800, false, '2026-12-10' )['net'] );
		$this->assertSame( 5.00, DhlCost::estimate( $cfg, 'V01PAK', 'DE', 800, false, '2026-12-25' )['net'] );
	}

	public function test_energiezuschlag_in_prozent_vom_grundpreis() {
		$cfg                                 = $this->plain();
		$cfg['zuschlaege']['energie_prozent'] = 10.0;

		$this->assertSame( 5.50, DhlCost::estimate( $cfg, 'V01PAK', 'DE', 800, false, '2026-12-01' )['net'] );
	}

	public function test_nicht_vertragliches_produkt_hat_keinen_preis() {
		$this->assertFalse( DhlCost::estimate( $this->plain(), 'V62WP', 'DE', 500, false, '2026-12-01' )['ok'] );
	}

	public function test_bereinigen_ersetzt_ungueltiges_durch_vertragswerte() {
		$def   = DhlCost::defaults();
		$clean = DhlCost::sanitize(
			array(
				'paket'      => array( array( 1, '5,50' ), array( 3, 'abc' ) ),
				'zuschlaege' => array( 'peak_monate' => '12, 11, 99, x', 'pip_von' => 'morgen', 'energie_prozent' => '250' ),
			)
		);

		$this->assertSame( 5.50, $clean['paket'][0][1], 'Komma wird verstanden.' );
		$this->assertSame( $def['paket'][1][1], $clean['paket'][1][1], 'Keine Zahl: Vertragswert.' );
		$this->assertSame( '11,12', $clean['zuschlaege']['peak_monate'] );
		$this->assertSame( '', $clean['zuschlaege']['pip_von'] );
		$this->assertSame( 0.0, $clean['zuschlaege']['energie_prozent'], 'Über 100 Prozent gilt nicht.' );
		$this->assertSame( $def['euro'][1], $clean['euro'][1], 'Fehlende Tabellen bleiben beim Vertrag.' );
	}

	public function test_zonenliste_deckt_die_preisliste() {
		$this->assertSame( 1, DhlCost::zone_of( 'at' ) );
		$this->assertSame( 2, DhlCost::zone_of( 'IT' ) );
		$this->assertSame( 3, DhlCost::zone_of( 'PT' ) );
		$this->assertSame( 0, DhlCost::zone_of( 'US' ) );
	}
}
