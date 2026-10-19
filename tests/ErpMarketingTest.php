<?php
/**
 * Marketing im ERP: reine Regeln des Schreibens (Kampagne, Kilometer, Anteil, Zuordnung).
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Marketing;
use PHPUnit\Framework\TestCase;

class ErpMarketingTest extends TestCase {

	private function in( array $over = array() ) {
		return array_merge( array( 'name' => 'Weihnachtsmarkt', 'typ' => 'event' ), $over );
	}

	public function test_zahlen_deutsch_und_englisch() {
		$this->assertSame( 12.5, Marketing::parse_number( '12,5' ) );
		$this->assertSame( 12.5, Marketing::parse_number( '12.5' ) );
		$this->assertSame( 1250.0, Marketing::parse_number( '1.250' ) );
		$this->assertSame( 1234.5, Marketing::parse_number( '1.234,5' ) );
		$this->assertSame( 1234.5, Marketing::parse_number( '1,234.5' ) );
		$this->assertSame( 250.0, Marketing::parse_number( '250 €' ) );
		$this->assertSame( 7.0, Marketing::parse_number( 7 ) );
		$this->assertNull( Marketing::parse_number( '' ) );
		$this->assertNull( Marketing::parse_number( 'abc' ) );
		$this->assertNull( Marketing::parse_number( array( 1 ) ) );
	}

	public function test_datum_wird_wirklich_geprueft() {
		$this->assertTrue( Marketing::is_date( '2026-02-28' ) );
		$this->assertFalse( Marketing::is_date( '2026-02-30' ) );
		$this->assertFalse( Marketing::is_date( '28.02.2026' ) );
		$this->assertFalse( Marketing::is_date( '' ) );
	}

	public function test_minimale_kampagne_bekommt_vorgaben() {
		$r = Marketing::validate_campaign( $this->in(), '2026-10-19' );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( '2026-10-19', $r['data']['start_datum'] );
		$this->assertNull( $r['data']['ende_datum'] );
		$this->assertNull( $r['data']['event_datum'] );
		$this->assertSame( 0.0, $r['data']['budget_geplant'] );
		$this->assertSame( 'geplant', $r['data']['status'] );
	}

	public function test_typ_leer_wird_event_wie_frueher() {
		$r = Marketing::validate_campaign( $this->in( array( 'typ' => '' ) ), '2026-10-19' );
		$this->assertSame( 'event', $r['data']['typ'] );
	}

	public function test_name_ist_pflicht() {
		$r = Marketing::validate_campaign( $this->in( array( 'name' => '   ' ) ), '2026-10-19' );
		$this->assertCount( 1, $r['errors'] );
		$this->assertStringContainsString( 'Namen', $r['errors'][0] );
	}

	public function test_unbekannte_art_und_status_werden_abgelehnt() {
		$r = Marketing::validate_campaign( $this->in( array( 'typ' => 'zirkus', 'status' => 'vorbei' ) ), '2026-10-19' );
		$this->assertCount( 2, $r['errors'] );
	}

	public function test_budget_deutsch_gerundet_und_nicht_negativ() {
		$r = Marketing::validate_campaign( $this->in( array( 'budget_geplant' => '1.250,555' ) ), '2026-10-19' );
		$this->assertSame( 1250.56, $r['data']['budget_geplant'] );
		$r = Marketing::validate_campaign( $this->in( array( 'budget_geplant' => '-5' ) ), '2026-10-19' );
		$this->assertSame( 0.0, $r['data']['budget_geplant'] );
		$r = Marketing::validate_campaign( $this->in( array( 'budget_geplant' => 'viel' ) ), '2026-10-19' );
		$this->assertStringContainsString( 'Budget', $r['errors'][0] );
		$r = Marketing::validate_campaign( $this->in( array( 'budget_geplant' => '99999999' ) ), '2026-10-19' );
		$this->assertStringContainsString( 'hoch', $r['errors'][0] );
	}

	public function test_kaputtes_datum_und_ende_vor_beginn() {
		$r = Marketing::validate_campaign( $this->in( array( 'start_datum' => '31.12.2026' ) ), '2026-10-19' );
		$this->assertStringContainsString( 'Beginn', $r['errors'][0] );

		$r = Marketing::validate_campaign( $this->in( array( 'start_datum' => '2026-12-01', 'ende_datum' => '2026-11-30' ) ), '2026-10-19' );
		$this->assertStringContainsString( 'Ende liegt vor dem Beginn', $r['errors'][0] );

		$r = Marketing::validate_campaign( $this->in( array( 'start_datum' => '2026-12-01', 'ende_datum' => '2026-12-01', 'event_datum' => '2026-12-01' ) ), '2026-10-19' );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( '2026-12-01', $r['data']['event_datum'] );
	}

	public function test_kilometer() {
		$this->assertSame( 42.5, Marketing::validate_km( '42,5' )['km'] );
		$this->assertSame( '', Marketing::validate_km( '0' )['error'] );
		$this->assertSame( 0.0, Marketing::validate_km( '0' )['km'] );
		$this->assertNotSame( '', Marketing::validate_km( '' )['error'] );
		$this->assertNotSame( '', Marketing::validate_km( 'weit' )['error'] );
		$this->assertStringContainsString( 'negativ', Marketing::validate_km( '-3' )['error'] );
		$this->assertNotSame( '', Marketing::validate_km( '500000' )['error'] );
	}

	public function test_anteil() {
		$this->assertSame( 100.0, Marketing::validate_share( null )['anteil'] );
		$this->assertSame( 100.0, Marketing::validate_share( '' )['anteil'] );
		$this->assertSame( 33.3, Marketing::validate_share( '33,3' )['anteil'] );
		$this->assertNotSame( '', Marketing::validate_share( '0' )['error'] );
		$this->assertNotSame( '', Marketing::validate_share( '101' )['error'] );
		$this->assertNotSame( '', Marketing::validate_share( 'x' )['error'] );
	}

	public function test_stornierte_belege_sind_gesperrt() {
		$this->assertSame( '', Marketing::beleg_blocker( (object) array( 'storniert' => 0, 'quelle' => 'upload' ) ) );
		$this->assertNotSame( '', Marketing::beleg_blocker( (object) array( 'storniert' => 1, 'quelle' => 'upload' ) ) );
		$this->assertNotSame( '', Marketing::beleg_blocker( (object) array( 'storniert' => 0, 'quelle' => 'storno' ) ) );
		$this->assertNotSame( '', Marketing::beleg_blocker( null ) );
	}

	public function test_kilometerbeleg_nur_ueber_kilometer_loesbar() {
		$this->assertNotSame( '', Marketing::loesen_blocker( 5, 5 ) );
		$this->assertSame( '', Marketing::loesen_blocker( 5, 6 ) );
		$this->assertSame( '', Marketing::loesen_blocker( 5, 0 ) );
	}

	public function test_kilometerdatum() {
		$this->assertSame( '2026-05-01', Marketing::km_datum( array( 'event_datum' => '2026-05-01', 'start_datum' => '2026-04-01' ), '2026-10-19' ) );
		$this->assertSame( '2026-04-01', Marketing::km_datum( array( 'event_datum' => null, 'start_datum' => '2026-04-01' ), '2026-10-19' ) );
		$this->assertSame( '2026-10-19', Marketing::km_datum( array(), '2026-10-19' ) );
	}
}
