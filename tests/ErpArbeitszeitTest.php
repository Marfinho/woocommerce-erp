<?php
/**
 * Druckzeit ist Maschinenzeit, keine Arbeitszeit.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\TimeRepository;

class ErpArbeitszeitTest extends TestCase {

	protected function read( $rel ) {
		return file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_der_workflow_bucht_beim_melden_nur_die_ruestzeit() {
		$src = $this->read( 'includes/Erp/Workflow.php' );

		$this->assertStringContainsString( "self::book_time( 'ruestzeit', \$setup_h", $src );
		$this->assertStringNotContainsString( "self::book_time( 'druckzeit'", $src );
	}

	public function test_bedingung_nimmt_nur_platten_druckzeit_heraus() {
		$sql = TimeRepository::working_time_sql();

		$this->assertStringContainsString( "category = 'druckzeit'", $sql );
		$this->assertStringContainsString( 'run_id IS NOT NULL', $sql, 'Von Hand erfasste Druckzeit ohne Platte zählt weiter.' );
		$this->assertStringStartsWith( 'NOT', $sql );
	}

	public function test_jede_auswertung_der_arbeitszeit_nutzt_die_bedingung() {
		$src = $this->read( 'includes/Modules/Betrieb/TimeRepository.php' );

		// all, hours_by_week, hours_in_week, hours_by_category, between, for_user.
		$this->assertGreaterThanOrEqual( 6, substr_count( $src, 'self::working_time_sql()' ) );
	}
}
