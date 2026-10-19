<?php
/**
 * Reine Regeln der Verwaltungs-Seiten im ERP.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Verwaltung;
use PHPUnit\Framework\TestCase;

/**
 * Tests für Verwaltung.
 */
class ErpVerwaltungTest extends TestCase {

	public function test_parse_number_reads_german_and_english() {
		$this->assertSame( 1.5, Verwaltung::parse_number( '1,5' ) );
		$this->assertSame( 1.5, Verwaltung::parse_number( '1.5' ) );
		$this->assertSame( 1000.5, Verwaltung::parse_number( '1.000,50' ) );
		$this->assertSame( 1000.0, Verwaltung::parse_number( '1.000' ) );
		$this->assertSame( 0.0249, Verwaltung::parse_number( '0.0249' ) );
		$this->assertSame( -3.0, Verwaltung::parse_number( ' -3 ' ) );
		$this->assertSame( 7.0, Verwaltung::parse_number( 7 ) );
		$this->assertNull( Verwaltung::parse_number( '' ) );
		$this->assertNull( Verwaltung::parse_number( 'abc' ) );
		$this->assertNull( Verwaltung::parse_number( '1,2,3' ) );
	}

	public function test_normalize_date_accepts_only_real_dates() {
		$this->assertSame( '2026-03-14', Verwaltung::normalize_date( '2026-03-14' ) );
		$this->assertSame( '2026-01-01', Verwaltung::normalize_date( '', '2026-01-01' ) );
		$this->assertNull( Verwaltung::normalize_date( '2026-02-30' ) );
		$this->assertNull( Verwaltung::normalize_date( '14.03.2026' ) );
	}

	public function test_period_defaults_and_swaps() {
		$this->assertSame( array( 'von' => '2026-03-01', 'bis' => '2026-03-14' ), Verwaltung::period( '', '', '2026-03-14' ) );
		$this->assertSame( array( 'von' => '2026-03-01', 'bis' => '2026-03-10' ), Verwaltung::period( '2026-03-10', '2026-03-01', '2026-03-14' ) );
		$this->assertSame( array( 'von' => '2026-03-01', 'bis' => '2026-03-14' ), Verwaltung::period( 'quatsch', 'quatsch', '2026-03-14' ) );
	}

	public function test_week_ampel_thresholds() {
		$this->assertSame( 'gruen', Verwaltung::week_ampel( 14 ) );
		$this->assertSame( 'gelb', Verwaltung::week_ampel( 14.1 ) );
		$this->assertSame( 'gelb', Verwaltung::week_ampel( 25 ) );
		$this->assertSame( 'rot', Verwaltung::week_ampel( 25.5 ) );
	}

	public function test_week_rows_sum_sort_and_limit() {
		$rows = Verwaltung::week_rows(
			array(
				array( 'iso_year' => 2025, 'iso_week' => 52, 'hours' => '2.5' ),
				array( 'iso_year' => 2026, 'iso_week' => 1, 'hours' => '3' ),
				array( 'iso_year' => 2026, 'iso_week' => 1, 'hours' => '12' ),
				array( 'iso_year' => 2026, 'iso_week' => 2, 'hours' => '1' ),
			),
			2
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( 2, $rows[0]['week'] );
		$this->assertSame( 15.0, $rows[1]['hours'] );
		$this->assertSame( 'gelb', $rows[1]['ampel'] );
		$this->assertSame( array(), Verwaltung::week_rows( array() ) );
	}

	public function test_stop_hint() {
		$this->assertSame( 'vergessen', Verwaltung::stop_hint( true, true )['code'] );
		$this->assertSame( 'kurz', Verwaltung::stop_hint( false, false )['code'] );
		$this->assertSame( '', Verwaltung::stop_hint( true, false )['code'] );
	}

	public function test_parse_assumption() {
		$this->assertSame( 0.35, Verwaltung::parse_assumption( '0,35' )['value'] );
		$this->assertSame( 0.0, Verwaltung::parse_assumption( '0' )['value'] );
		$this->assertNotSame( '', Verwaltung::parse_assumption( '' )['error'] );
		$this->assertNotSame( '', Verwaltung::parse_assumption( 'viel' )['error'] );
		$this->assertNotSame( '', Verwaltung::parse_assumption( '-1' )['error'] );
		$this->assertNull( Verwaltung::parse_assumption( '-1' )['value'] );
	}

	public function test_printer_id() {
		$this->assertSame( 'eg_dhl', Verwaltung::printer_id( ' eg_dhl ' )['id'] );
		$this->assertNotSame( '', Verwaltung::printer_id( '' )['error'] );
		$this->assertNotSame( '', Verwaltung::printer_id( 'EG Drucker' )['error'] );
	}

	public function test_job_actions_follow_status() {
		$this->assertSame( array( 'erneut' => false, 'abbrechen' => true ), Verwaltung::job_actions( 'queued' ) );
		$this->assertSame( array( 'erneut' => true, 'abbrechen' => false ), Verwaltung::job_actions( 'failed' ) );
		$this->assertSame( array( 'erneut' => true, 'abbrechen' => false ), Verwaltung::job_actions( 'cancelled' ) );
		$this->assertSame( array( 'erneut' => false, 'abbrechen' => false ), Verwaltung::job_actions( 'printing' ) );
		$this->assertSame( array( 'erneut' => false, 'abbrechen' => false ), Verwaltung::job_actions( 'claimed' ) );
	}

	public function test_secret_keys_are_recognised() {
		$this->assertTrue( Verwaltung::is_secret_key( 'dhl_versand_live_password', 'password' ) );
		$this->assertTrue( Verwaltung::is_secret_key( 'dhl_tracking_api_key', 'text' ) );
		$this->assertTrue( Verwaltung::is_secret_key( 'dhl_versand_live_api_secret', 'text' ) );
		$this->assertFalse( Verwaltung::is_secret_key( 'firma_name', 'text' ) );
		$this->assertFalse( Verwaltung::is_secret_key( 'dhl_versand_ekp', 'text' ) );
	}

	public function test_plugin_value_by_type() {
		$this->assertSame( 1, Verwaltung::plugin_value( 'checkbox', true )['value'] );
		$this->assertSame( 0, Verwaltung::plugin_value( 'checkbox', false )['value'] );
		$this->assertSame( 0.3, Verwaltung::plugin_value( 'number', '0,3' )['value'] );
		$this->assertFalse( Verwaltung::plugin_value( 'number', 'x' )['ok'] );
		$this->assertSame( '#8b6914', Verwaltung::plugin_value( 'color', '#8B6914' )['value'] );
		$this->assertFalse( Verwaltung::plugin_value( 'color', 'rot' )['ok'] );
		$this->assertSame( 'abc', Verwaltung::plugin_value( 'text', 'abc' )['value'] );
	}

	public function test_native_pages_are_registered_in_the_script() {
		$js = file_get_contents( dirname( __DIR__ ) . '/assets/erp/pages/verwaltung.js' );

		foreach ( array( 'zeit', 'loehne', 'etiketten', 'einstellungen' ) as $id ) {
			$this->assertStringContainsString( "UI.pages['$id']", $js );
		}
	}
}
