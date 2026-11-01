<?php
/**
 * Angebote: Summen, Prüfung, Zustände, Mailtext.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Angebote;
use PHPUnit\Framework\TestCase;

class ErpAngeboteTest extends TestCase {

	public function test_summen_mit_rabatt_und_versand() {
		$t = Angebote::totals(
			array(
				array( 'qty' => 2, 'unit_price_gross' => 49.9, 'discount_pct' => 0 ),
				array( 'qty' => 10, 'unit_price_gross' => 5, 'discount_pct' => 10 ),
			),
			5,
			4.9,
			false
		);
		$this->assertSame( array( 99.8, 45.0 ), $t['lines'] );
		$this->assertSame( 144.8, $t['subtotal'] );
		$this->assertSame( 7.24, $t['discount'] );
		$this->assertSame( 142.46, $t['total'] );
		$this->assertSame( 22.75, $t['vat'] );
		$this->assertSame( 0.0, Angebote::totals( array( array( 'qty' => 1, 'unit_price_gross' => 10, 'discount_pct' => 0 ) ), 0, 0, true )['vat'] );
	}

	public function test_pruefung_meldet_fehler_und_ueberspringt_leere_zeilen() {
		$r = Angebote::validate(
			array(
				'recipient'   => '',
				'quote_date'  => '2026-11-01',
				'valid_until' => '2026-10-01',
				'items'       => array(
					array( 'title' => '', 'qty' => '', 'unit_price_gross' => '' ),
					array( 'title' => 'Regal', 'qty' => '0', 'unit_price_gross' => '-1' ),
				),
			),
			'2026-11-01'
		);
		$this->assertCount( 4, $r['errors'] );
		$this->assertCount( 1, $r['items'] );
	}

	public function test_pruefung_vorgaben() {
		$r = Angebote::validate( array( 'recipient' => 'Laden', 'items' => array( array( 'title' => 'Haken', 'qty' => '3', 'unit_price_gross' => '4,50', 'article_id' => '7', 'color' => 'Weiss' ) ) ), '2026-11-01' );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( '2026-11-01', $r['data']['quote_date'] );
		$this->assertSame( '2026-12-01', $r['data']['valid_until'] );
		$this->assertSame( 4.5, $r['items'][0]['unit_price_gross'] );
		$this->assertSame( 7, $r['items'][0]['article_id'] );
		$this->assertSame( 'weiss', $r['items'][0]['color'] );
	}

	public function test_zustaende() {
		$this->assertSame( 'abgelaufen', Angebote::shown_status( 'versendet', '2026-10-01', '2026-11-01' ) );
		$this->assertSame( 'versendet', Angebote::shown_status( 'versendet', '2026-11-01', '2026-11-01' ) );
		$this->assertSame( 'angenommen', Angebote::shown_status( 'angenommen', '2026-10-01', '2026-11-01' ) );
		$this->assertTrue( Angebote::can_switch( 'versendet', 'abgelehnt' ) );
		$this->assertFalse( Angebote::can_switch( 'angenommen', 'entwurf' ) );
	}

	public function test_mailtext() {
		$m = Angebote::mail_text( array( 'number' => 'AN-2026-0001', 'recipient' => 'Blumen Müller', 'valid_until' => '2026-12-01' ), 'Lindenweg16' );
		$this->assertStringContainsString( 'AN-2026-0001', $m['subject'] );
		$this->assertStringContainsString( '01.12.2026', $m['body'] );
	}
}
