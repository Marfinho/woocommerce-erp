<?php
/**
 * Druckauftrag: geplanter Druck als eigener Auftrag.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\WorkOrderRepository;
use PHPUnit\Framework\TestCase;

/**
 * Welche Auftragsart zu welchem Artikel passt, entscheidet der
 * Beschaffungsweg – und nur der.
 */
class BetriebPrintOrderTest extends TestCase {

	/**
	 * Artikel mit einem Beschaffungsweg.
	 *
	 * @param string $type Beschaffungsweg.
	 * @return array
	 */
	protected function artikel( $type ) {
		return array( 'id' => 1, 'sku' => 'HH-001', 'sourcing_type' => $type );
	}

	/**
	 * Ein Rohling wird gedruckt, nicht montiert.
	 */
	public function test_rohling_nur_drucken() {
		$this->assertTrue( WorkOrderRepository::fits_kind( $this->artikel( 'druck' ), 'druck' ) );
		$this->assertFalse( WorkOrderRepository::fits_kind( $this->artikel( 'druck' ), 'montage' ) );
	}

	/**
	 * Ein Halbzeug wird montiert, nicht gedruckt.
	 */
	public function test_halbzeug_nur_montieren() {
		$this->assertTrue( WorkOrderRepository::fits_kind( $this->artikel( 'montage' ), 'montage' ) );
		$this->assertFalse( WorkOrderRepository::fits_kind( $this->artikel( 'montage' ), 'druck' ) );
	}

	/**
	 * Der Hybridartikel braucht beides: erst drucken, dann montieren.
	 */
	public function test_hybrid_kann_beides() {
		$this->assertTrue( WorkOrderRepository::fits_kind( $this->artikel( 'hybrid' ), 'druck' ) );
		$this->assertTrue( WorkOrderRepository::fits_kind( $this->artikel( 'hybrid' ), 'montage' ) );
	}

	/**
	 * Ein Zukaufartikel wird bestellt, nicht gefertigt.
	 */
	public function test_zukauf_gar_nicht() {
		$this->assertFalse( WorkOrderRepository::fits_kind( $this->artikel( 'zukauf' ), 'druck' ) );
		$this->assertFalse( WorkOrderRepository::fits_kind( $this->artikel( 'zukauf' ), 'montage' ) );
	}

	/**
	 * Beide Auftragsarten sind benannt – eine Art ohne Bezeichnung stünde
	 * in der Liste als roher Schlüssel.
	 */
	public function test_beide_arten_haben_eine_bezeichnung() {
		$kinds = WorkOrderRepository::kinds();

		$this->assertArrayHasKey( 'montage', $kinds );
		$this->assertArrayHasKey( 'druck', $kinds );
		$this->assertNotSame( '', trim( $kinds['druck'] ) );
	}

	/**
	 * Ein laufender Druckauftrag zählt wie jeder andere als offene Arbeit –
	 * sonst schlüge der Bedarf denselben Druck ein zweites Mal vor.
	 */
	public function test_druckauftrag_zaehlt_als_offene_arbeit() {
		$this->assertContains( 'laufend', WorkOrderRepository::OPEN_STATES );
		$this->assertContains( 'offen', WorkOrderRepository::OPEN_STATES );
	}
}
