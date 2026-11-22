<?php
/**
 * Prüft die Regeln für Beileger und die Aufteilung mehrerer Sendungsnummern.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\Dhl\DhlShipping;
use Lindenweg16\Modules\Betrieb\OrderInserts;
use PHPUnit\Framework\TestCase;

/**
 * Beileger-Tests.
 */
class BetriebOrderInsertsTest extends TestCase {

	protected function ids( array $items ) {
		return array_column( $items, 'id' );
	}

	public function test_ohne_basismodul_nur_der_danke_zettel() {
		$items = OrderInserts::compute( OrderInserts::defaults(), array( array( 'role' => '', 'sku' => 'X', 'qty' => 2 ) ) );

		$this->assertSame( array( 'danke' ), $this->ids( $items ) );
	}

	public function test_mit_basismodul_kommen_anleitung_und_montageset_dazu() {
		$items = OrderInserts::compute( OrderInserts::defaults(), array( array( 'role' => 'basis', 'sku' => 'B', 'qty' => 1 ) ) );

		$this->assertSame( array( 'danke', 'bsm-anleitung', 'montageset' ), $this->ids( $items ) );
	}

	public function test_modulanleitung_einmal_egal_wie_viele_module() {
		$items = OrderInserts::compute(
			OrderInserts::defaults(),
			array(
				array( 'role' => 'basis', 'sku' => 'B', 'qty' => 1 ),
				array( 'role' => 'modul', 'sku' => 'M1', 'qty' => 3 ),
				array( 'role' => 'modul', 'sku' => 'M2', 'qty' => 2 ),
			)
		);
		$by = array_column( $items, 'qty', 'id' );

		$this->assertSame( 1, $by['modul-anleitung'] );
		$this->assertSame( 1, $by['bsm-anleitung'] );
	}

	public function test_je_stueck_zaehlt_die_basismodule() {
		$items = OrderInserts::compute( OrderInserts::defaults(), array( array( 'role' => 'basis', 'sku' => 'B', 'qty' => 2 ) ) );
		$by    = array_column( $items, 'qty', 'id' );

		$this->assertSame( 2, $by['montageset'] );
		$this->assertSame( 1, $by['bsm-anleitung'] );
	}

	public function test_artikelnummer_als_bedingung_ohne_gross_und_kleinschreibung() {
		$rules = array( array( 'id' => 'x', 'name' => 'Flyer', 'wenn' => 'sku', 'sku' => 'ab-1, cd-2', 'menge' => 'einmal', 'pflicht' => false, 'code' => false ) );

		$this->assertSame( array( 'x' ), $this->ids( OrderInserts::compute( $rules, array( array( 'role' => '', 'sku' => 'CD-2', 'qty' => 1 ) ) ) ) );
		$this->assertSame( array(), OrderInserts::compute( $rules, array( array( 'role' => '', 'sku' => 'ZZ', 'qty' => 1 ) ) ) );
	}

	public function test_nur_fehlende_pflicht_beileger_werden_gemeldet() {
		$items = array(
			array( 'id' => 'a', 'name' => 'A', 'required' => true ),
			array( 'id' => 'b', 'name' => 'B', 'required' => true ),
			array( 'id' => 'c', 'name' => 'C', 'required' => false ),
		);

		$this->assertSame( array( 'B' ), OrderInserts::missing( $items, array( 'a' ) ) );
		$this->assertSame( array(), OrderInserts::missing( $items, array( 'a', 'b' ) ) );
	}

	public function test_bereinigen_wirft_leere_zeilen_raus_und_vergibt_doppelte_kennungen_neu() {
		$clean = OrderInserts::sanitize(
			array(
				array( 'id' => 'a', 'name' => ' Zettel ', 'wenn' => 'immer', 'pflicht' => '1' ),
				array( 'id' => 'a', 'name' => 'Zweiter', 'wenn' => 'unsinn', 'menge' => 'je_stueck' ),
				array( 'id' => 'c', 'name' => '   ' ),
			)
		);

		$this->assertCount( 2, $clean );
		$this->assertSame( 'Zettel', $clean[0]['name'] );
		$this->assertTrue( $clean[0]['pflicht'] );
		$this->assertNotSame( $clean[0]['id'], $clean[1]['id'] );
		$this->assertSame( 'immer', $clean[1]['wenn'], 'Unbekannte Bedingung wird zu „immer“.' );
		$this->assertSame( 'je_stueck', $clean[1]['menge'] );
		$this->assertFalse( $clean[1]['pflicht'] );
	}

	public function test_mehrere_sendungsnummern_erste_ist_die_hauptnummer() {
		$this->assertSame( array( 'primary' => '', 'more' => array() ), DhlShipping::split_numbers( array() ) );
		$this->assertSame( array( 'primary' => '111', 'more' => array() ), DhlShipping::split_numbers( array( '111' ) ) );
		$this->assertSame( array( 'primary' => '111', 'more' => array( '222', '333' ) ), DhlShipping::split_numbers( array( ' 111 ', '222', '', '111', '333' ) ) );
	}
}
