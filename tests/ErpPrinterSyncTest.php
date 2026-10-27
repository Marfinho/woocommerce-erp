<?php
/**
 * Drucker-Abgleich: wann gilt ein Druck als gestartet oder beendet.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\RunFlow;
use PHPUnit\Framework\TestCase;

class ErpPrinterSyncTest extends TestCase {

	public function test_start_zustaende() {
		$this->assertTrue( RunFlow::printer_started( 'running' ) );
		$this->assertTrue( RunFlow::printer_started( 'PREPARE' ) );
		$this->assertFalse( RunFlow::printer_started( 'idle' ) );
		$this->assertFalse( RunFlow::printer_started( 'finish' ) );
	}

	public function test_ende_zaehlt_auch_bei_fehlschlag() {
		$this->assertTrue( RunFlow::printer_ended( 'finish' ) );
		$this->assertTrue( RunFlow::printer_ended( 'FAILED' ) );
		$this->assertFalse( RunFlow::printer_ended( 'running' ) );
		$this->assertFalse( RunFlow::printer_ended( 'pause' ) );
		$this->assertFalse( RunFlow::printer_ended( '' ) );
	}

	public function test_der_heartbeat_gleicht_die_platten_ab() {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Bambu/BambuRestApi.php' );
		$this->assertStringContainsString( 'Workflow::sync_printers()', $src );
		$this->assertStringContainsString( "'lw16_sync_printers'", $src );
	}
}
