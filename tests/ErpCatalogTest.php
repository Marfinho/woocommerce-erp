<?php
/**
 * Produktliste: Welche Probleme hat ein Produkt?
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Catalog;
use PHPUnit\Framework\TestCase;

class ErpCatalogTest extends TestCase {

	/**
	 * @param array $over Abweichungen.
	 * @return array
	 */
	private function facts( array $over = array() ) {
		return array_merge(
			array(
				'sourcing'  => 'druck',
				'active'    => true,
				'sellable'  => true,
				'has_model' => true,
				'wc_id'     => 12,
				'price'     => 9.9,
				'margin'    => 0.45,
				'db2'       => 3.0,
			),
			$over
		);
	}

	public function test_ein_vollstaendiges_produkt_hat_keine_probleme() {
		$this->assertSame( array(), Catalog::flags( $this->facts() ) );
	}

	public function test_gedrucktes_ohne_projektdatei() {
		$this->assertSame( array( 'ohne_3mf' ), Catalog::flags( $this->facts( array( 'has_model' => false ) ) ) );
	}

	public function test_zukauf_braucht_keine_3mf() {
		$this->assertSame( array(), Catalog::flags( $this->facts( array( 'sourcing' => 'zukauf', 'has_model' => false ) ) ) );
	}

	public function test_kein_shop_eintrag_und_kein_preis() {
		$flags = Catalog::flags( $this->facts( array( 'wc_id' => 0, 'price' => null, 'margin' => null, 'db2' => null ) ) );

		$this->assertContains( 'nicht_im_shop', $flags );
		$this->assertContains( 'kein_preis', $flags );
		$this->assertNotContains( 'marge', $flags );
	}

	public function test_marge_unter_der_schwelle_oder_negativ() {
		$this->assertContains( 'marge', Catalog::flags( $this->facts( array( 'margin' => 0.12 ) ) ) );
		$this->assertContains( 'marge', Catalog::flags( $this->facts( array( 'margin' => 0.5, 'db2' => -0.1 ) ) ) );
		$this->assertNotContains( 'marge', Catalog::flags( $this->facts( array( 'margin' => 0.20 ) ) ) );
	}

	public function test_ausgelaufene_produkte_melden_nichts() {
		$this->assertSame( array(), Catalog::flags( $this->facts( array( 'active' => false, 'has_model' => false, 'wc_id' => 0 ) ) ) );
	}

	public function test_nicht_verkaufbares_braucht_weder_preis_noch_shop() {
		$this->assertSame( array(), Catalog::flags( $this->facts( array( 'sellable' => false, 'wc_id' => 0, 'price' => null, 'margin' => null, 'db2' => null ) ) ) );
	}

	public function test_jedes_problem_hat_einen_klartext() {
		$texts = Catalog::flag_texts();

		foreach ( array( 'ohne_3mf', 'marge', 'nicht_im_shop', 'kein_preis' ) as $flag ) {
			$this->assertArrayHasKey( $flag, $texts );
			$this->assertNotSame( '', $texts[ $flag ][1] );
		}
	}
}
