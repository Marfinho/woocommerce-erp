<?php
/**
 * Bestellungen an Lieferanten: Bündeln, Summen, Lieferstand.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Lieferantenbestellungen as L;
use PHPUnit\Framework\TestCase;

class ErpLieferantenbestellungenTest extends TestCase {

	private function p( $id, $supplier, $status = 'bestellt', $qty = 10, $price = 0.5, $ship = 0 ) {
		return array( 'id' => $id, 'supplier' => $supplier, 'status' => $status, 'qty' => $qty, 'unit_price_net' => $price, 'shipping_net' => $ship );
	}

	public function test_offene_einkaeufe_nach_lieferant_ohne_verknuepfte() {
		$g = L::group_open( array( $this->p( 1, 'Würth' ), $this->p( 2, 'Bambu' ), $this->p( 3, 'würth' ), $this->p( 4, '' ), $this->p( 5, 'Bambu', 'geliefert' ), $this->p( 6, 'Bambu' ) ), array( 6 => true ) );

		$this->assertSame( array( 'Bambu', 'ohne Lieferant', 'Würth' ), array_keys( $g ) );
		$this->assertSame( array( 1, 3 ), array_column( $g['Würth'], 'id' ), 'Schreibweise egal' );
		$this->assertSame( array( 2 ), array_column( $g['Bambu'], 'id' ) );
	}

	public function test_summen_ohne_stornierte() {
		$t = L::totals( array( $this->p( 1, 'A', 'bestellt', 100, 0.25, 4.9 ), $this->p( 2, 'A', 'storniert', 10, 9, 1 ), $this->p( 3, 'A', 'geliefert', 2, 3 ) ) );
		$this->assertSame( array( 'goods' => 31.0, 'shipping' => 4.9, 'total' => 35.9 ), $t );
	}

	public function test_lieferstand() {
		$this->assertSame( 'bestaetigt', L::shown_status( 'bestaetigt', array( $this->p( 1, 'A' ), $this->p( 2, 'A' ) ) ) );
		$this->assertSame( 'teilgeliefert', L::shown_status( 'bestaetigt', array( $this->p( 1, 'A', 'geliefert' ), $this->p( 2, 'A' ) ) ) );
		$this->assertSame( 'geliefert', L::shown_status( 'versendet', array( $this->p( 1, 'A', 'geliefert' ), $this->p( 2, 'A', 'storniert' ) ) ) );
		$this->assertSame( 'storniert', L::shown_status( 'versendet', array( $this->p( 1, 'A', 'storniert' ) ) ) );
		$this->assertSame( 'entwurf', L::shown_status( 'entwurf', array() ) );
	}

	public function test_mailtext() {
		$m = L::mail_text( array( 'number' => 'BE-2026-0001', 'wanted_by' => '2026-11-20' ), 'Lindenweg16' );
		$this->assertStringContainsString( 'BE-2026-0001', $m['subject'] );
		$this->assertStringContainsString( '20.11.2026', $m['body'] );
	}
}
