<?php
/**
 * Liquidität: Wochen, Termine, Vorschau.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Liquiditaet;
use PHPUnit\Framework\TestCase;

class ErpLiquiditaetTest extends TestCase {

	public function test_wochen_beginnen_montags() {
		$w = Liquiditaet::weeks( '2026-11-01', 3 ); // Sonntag.
		$this->assertSame( '2026-10-26', $w[0]['from'] );
		$this->assertSame( '2026-11-01', $w[0]['to'] );
		$this->assertSame( '2026-11-02', $w[1]['from'] );
		$this->assertSame( 'KW 44', $w[0]['label'] );
	}

	public function test_termine_einer_dauerbuchung() {
		$this->assertSame( array( '2026-11-15', '2026-12-15', '2027-01-15' ), Liquiditaet::occurrences( '2026-11-15', 'monatlich', '2027-01-31' ) );
		$this->assertSame( array( '2026-11-15' ), Liquiditaet::occurrences( '2026-11-15', 'monatlich', '2027-01-31', '2026-12-01' ), 'Ende der Dauerbuchung zählt' );
		$this->assertSame( array( '2026-11-01', '2027-02-01' ), Liquiditaet::occurrences( '2026-11-01', 'quartalsweise', '2027-03-01' ) );
		$this->assertSame( array(), Liquiditaet::occurrences( '2027-05-01', 'jaehrlich', '2027-01-31' ) );
	}

	public function test_vorschau_mit_ueberfaelligem_in_woche_eins() {
		$weeks = Liquiditaet::weeks( '2026-11-02', 2 );
		$rows  = Liquiditaet::project(
			1000,
			$weeks,
			array(
				array( 'date' => '2026-10-15', 'amount' => 200, 'label' => 'alt', 'kind' => 'ueberfaellig' ),
				array( 'date' => '2026-11-03', 'amount' => -1500, 'label' => 'Miete', 'kind' => 'dauer' ),
				array( 'date' => '2026-11-10', 'amount' => 400, 'label' => 'RE', 'kind' => 'rechnung' ),
				array( 'date' => '2026-12-31', 'amount' => 999, 'label' => 'später', 'kind' => 'rechnung' ),
			)
		);
		$this->assertSame( 200.0, $rows[0]['in'] );
		$this->assertSame( -1500.0, $rows[0]['out'] );
		$this->assertSame( -300.0, $rows[0]['balance'] );
		$this->assertSame( 100.0, $rows[1]['balance'] );
		$this->assertCount( 2, $rows[0]['items'] );
		$this->assertSame( 0, Liquiditaet::first_below( $rows, 0 ) );
		$this->assertSame( -1, Liquiditaet::first_below( $rows, -500 ) );
	}
}
