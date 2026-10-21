<?php
/**
 * Zuordnung der Druckerdatei zur Platte im Workflow.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\RunFlow;
use PHPUnit\Framework\TestCase;

class ErpDateiZuordnungTest extends TestCase {

	private function src( $path ) {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $path );
	}

	public function test_namen_werden_auf_den_kern_gebracht() {
		$this->assertSame( 'platte-a-1', RunFlow::normalize_file( 'Platte-A-1.gcode.3mf' ) );
		$this->assertSame( 'platte-a-1', RunFlow::normalize_file( '/sdcard/Platte-A-1.3mf' ) );
		$this->assertSame( 'platte-a-1', RunFlow::normalize_file( 'lw16-42-platte-a-1.3mf' ) );
		$this->assertSame( '', RunFlow::normalize_file( '' ) );
	}

	public function test_treffer_gleich_oder_mit_suffix_an_der_wortgrenze() {
		$plate = 'Platte-a1-20260101-120000-1-abcd.3mf';

		$this->assertTrue( RunFlow::file_matches( 'Platte-a1-20260101-120000-1-abcd', $plate ) );
		$this->assertTrue( RunFlow::file_matches( 'platte-a1-20260101-120000-1-abcd.gcode.3mf', $plate ) );
		$this->assertTrue( RunFlow::file_matches( 'Platte-a1-20260101-120000-1-abcd_plate_1', $plate ) );
		$this->assertTrue( RunFlow::file_matches( 'lw16-7-Platte-a1-20260101-120000-1-abcd.3mf', $plate ) );
	}

	public function test_kein_treffer_bei_anderer_platte_oder_leerem_namen() {
		$this->assertFalse( RunFlow::file_matches( 'platte-a-10', 'platte-a-1.3mf' ), 'Ziffer ist keine Wortgrenze' );
		$this->assertFalse( RunFlow::file_matches( 'anderes-projekt', 'platte-a-1.3mf' ) );
		$this->assertFalse( RunFlow::file_matches( '', 'platte-a-1.3mf' ) );
		$this->assertFalse( RunFlow::file_matches( 'platte-a-1', '' ) );
	}

	public function test_verdrahtung_bridge_api_speicher_workflow() {
		$this->assertStringContainsString( 'subtask_name', $this->src( 'raspberry-pi/bambu_bridge.py' ) );
		$this->assertStringContainsString( '"current_file"', $this->src( 'raspberry-pi/bambu_bridge.py' ) );
		$this->assertStringContainsString( "'current_file'", $this->src( 'includes/Modules/Betrieb/Bambu/BambuRestApi.php' ) );
		$this->assertStringContainsString( "'current_file'", $this->src( 'includes/Modules/Betrieb/Bambu/BambuPrinterRepository.php' ) );
		$this->assertStringContainsString( 'current_file VARCHAR(255)', $this->src( 'includes/Modules/Betrieb/Schema.php' ) );
		$this->assertStringContainsString( 'RunFlow::file_matches', $this->src( 'includes/Erp/Workflow.php' ) );
	}
}
