<?php
/**
 * Druckerakte: Verdrahtung.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

class ErpDruckerAkteTest extends TestCase {

	public function test_akte_braucht_das_druckfarm_recht() {
		$rest = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/RestApi.php' );

		$this->assertMatchesRegularExpression( "#'/erp/drucker/\\(\\?P<id>.*?'fertigung\\.druckfarm'#s", $rest );
	}

	public function test_akte_nutzt_die_messung_aus_dem_workflow() {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Screens.php' );

		$this->assertStringContainsString( 'Variance::run_minutes', $src );
		$this->assertStringContainsString( 'MaintenanceRepository::reasons_for', $src );
	}
}
