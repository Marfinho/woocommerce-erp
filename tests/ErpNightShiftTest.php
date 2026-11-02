<?php
/**
 * Nachtschicht-Planer: Fenster, Farben, Fach, Verteilung.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\NightShift as N;
use PHPUnit\Framework\TestCase;

class ErpNightShiftTest extends TestCase {

	private function printer( $id, array $trays, $free = true ) {
		return array( 'id' => $id, 'name' => 'Drucker ' . $id, 'free' => $free, 'reason' => $free ? '' : 'druckt', 'trays' => $trays );
	}

	private function plate( $id, $minutes, $material = 'PETG', $hex = 'ffffff', $grams = 100, $urgency = 0, $printer = '' ) {
		return array( 'id' => $id, 'batch' => 'P' . $id, 'label' => 'x', 'minutes' => $minutes, 'material' => $material, 'color' => '', 'color_hex' => $hex, 'grams' => $grams, 'urgency' => $urgency, 'printer' => $printer );
	}

	public function test_fenster() {
		$this->assertSame( array( 'start' => '2026-11-02 20:00', 'end' => '2026-11-03 07:00', 'minutes' => 660 ), N::window( '2026-11-02 17:30', '20:00', '07:00' ) );
		$this->assertSame( 600, N::window( '2026-11-02 21:00', '20:00', '07:00' )['minutes'], 'Schon nach Feierabend: ab jetzt' );
		$this->assertSame( '2026-11-03 07:00', N::window( '2026-11-03 01:00', '20:00', '07:00' )['end'], 'Nach Mitternacht läuft die Nacht noch' );
		$this->assertSame( 360, N::window( '2026-11-03 01:00', '20:00', '07:00' )['minutes'] );
	}

	public function test_farbe_und_fach() {
		$this->assertSame( 0.0, N::color_distance( 'FFFFFF', '#ffffffff' ) );
		$this->assertGreaterThan( 400, N::color_distance( '000000', 'ffffff' ) );
		$trays = array(
			array( 'unit' => 1, 'tray' => 1, 'material' => 'PLA', 'color' => 'ffffff', 'remain' => 80 ),
			array( 'unit' => 1, 'tray' => 2, 'material' => 'PETG HF', 'color' => 'fafafa', 'remain' => 5 ),
			array( 'unit' => 1, 'tray' => 3, 'material' => 'PETG', 'color' => 'f5f5f5', 'remain' => 70 ),
		);
		$t = N::matching_tray( array( 'material' => 'PETG', 'color_hex' => 'ffffff', 'grams' => 200 ), $trays );
		$this->assertSame( 3, $t['tray']['tray'], 'Volle Rolle schlägt die knappe' );
		$this->assertTrue( $t['enough'] );
		$this->assertNull( N::matching_tray( array( 'material' => 'ASA', 'color_hex' => '', 'grams' => 1 ), $trays ) );
	}

	public function test_verteilung() {
		$printers = array(
			$this->printer( 'A', array( array( 'unit' => 1, 'tray' => 1, 'material' => 'PETG', 'color' => 'ffffff', 'remain' => 90 ) ) ),
			$this->printer( 'B', array( array( 'unit' => 1, 'tray' => 1, 'material' => 'PLA', 'color' => '000000', 'remain' => 90 ) ) ),
			$this->printer( 'C', array(), false ),
		);
		$plates = array(
			$this->plate( 1, 600, 'PETG', 'ffffff' ),
			$this->plate( 2, 90, 'PETG', 'ffffff' ),
			$this->plate( 3, 480, 'PLA', '000000' ),
			$this->plate( 4, 500, 'PLA', '000000', 100, 0, 'C' ),
		);
		$p = N::plan( $printers, $plates, 660 );
		$map = array();
		foreach ( $p['assignments'] as $a ) {
			$map[ $a['printer']['id'] ] = $a['plate']['id'];
		}

		$this->assertSame( array( 'A' => 1, 'B' => 3 ), $map, 'Lange Platte mit passendem Material, kurze bleibt für den Tag' );
		$this->assertSame( array( 2, 4 ), array_column( $p['left'], 'id' ) );
		$this->assertSame( 'C', $p['idle'][0]['printer']['id'] );
		$this->assertFalse( $p['assignments'][0]['refit'] );
	}

	public function test_umruesten_wenn_nichts_passt() {
		$p = N::plan( array( $this->printer( 'A', array( array( 'unit' => 1, 'tray' => 1, 'material' => 'PLA', 'color' => '000000', 'remain' => 90 ) ) ) ), array( $this->plate( 1, 600, 'PETG', 'ffffff' ) ), 660 );
		$this->assertTrue( $p['assignments'][0]['refit'] );
		$this->assertContains( 'Vor dem Feierabend umrüsten', $p['assignments'][0]['why'] );
	}
}
