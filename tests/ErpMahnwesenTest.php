<?php
/**
 * Offene Posten und Mahnungen: Altersklassen, Stufen, Texte.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Mahnwesen;
use PHPUnit\Framework\TestCase;

class ErpMahnwesenTest extends TestCase {

	private function s() {
		return array( 'tage_erinnerung' => 0, 'tage_stufe2' => 14, 'tage_stufe3' => 14, 'frist_tage' => 10, 'gebuehr_stufe2' => 5.0, 'gebuehr_stufe3' => 10.0 );
	}

	public function test_altersklassen() {
		$this->assertSame( 'nicht_faellig', Mahnwesen::bucket( 0 ) );
		$this->assertSame( '1_30', Mahnwesen::bucket( 30 ) );
		$this->assertSame( '31_60', Mahnwesen::bucket( 31 ) );
		$this->assertSame( 'ueber_60', Mahnwesen::bucket( 61 ) );
	}

	public function test_erste_stufe_ab_tag_nach_faelligkeit() {
		$this->assertSame( array( 'level' => 0, 'due' => false, 'wait' => 0 ), Mahnwesen::next_level( 0, 0, '', '', $this->s(), '2026-11-01' ) );
		$this->assertSame( array( 'level' => 1, 'due' => true, 'wait' => 0 ), Mahnwesen::next_level( 1, 0, '', '', $this->s(), '2026-11-01' ) );

		$s                    = $this->s();
		$s['tage_erinnerung'] = 7;
		$this->assertSame( array( 'level' => 1, 'due' => false, 'wait' => 5 ), Mahnwesen::next_level( 3, 0, '', '', $s, '2026-11-01' ) );
	}

	public function test_naechste_stufe_wartet_auf_frist_und_abstand() {
		// Erinnerung am 20.10. mit Frist 30.10.; Abstand 14 Tage → ab 03.11.
		$n = Mahnwesen::next_level( 20, 1, '2026-10-20', '2026-10-30', $this->s(), '2026-11-01' );
		$this->assertSame( array( 'level' => 2, 'due' => false, 'wait' => 2 ), $n );
		$this->assertTrue( Mahnwesen::next_level( 30, 1, '2026-10-20', '2026-10-30', $this->s(), '2026-11-03' )['due'] );

		// Lange Frist: erst nach der Frist.
		$this->assertSame( 10, Mahnwesen::next_level( 30, 2, '2026-10-01', '2026-11-10', $this->s(), '2026-11-01' )['wait'] );
		$this->assertSame( 0, Mahnwesen::next_level( 90, 3, '2026-10-01', '2026-10-10', $this->s(), '2026-11-01' )['level'], 'Nach der letzten Mahnung kommt nichts mehr' );
	}

	public function test_texte_je_stufe() {
		$ctx = array(
			'company' => 'Blumen Müller',
			'person'  => 'Frau Müller',
			'shop'    => 'Lindenweg16',
			'iban'    => 'DE02120300000000202051',
			'items'   => array( array( 'number' => 'A-7', 'date' => '2026-09-01', 'value' => 100, 'due' => '2026-09-15' ) ),
			'fee'     => 5,
			'new_due' => '2026-11-11',
		);

		$t1 = Mahnwesen::text( 1, $ctx );
		$this->assertStringContainsString( 'Zahlungserinnerung', $t1['subject'] );
		$this->assertSame( 100.0, $t1['total'], 'Erinnerung ohne Gebühr' );
		$this->assertStringContainsString( 'Guten Tag Frau Müller,', $t1['body'] );
		$this->assertStringContainsString( '11.11.2026', $t1['body'] );
		$this->assertStringContainsString( 'DE02120300000000202051', $t1['body'] );

		$t2 = Mahnwesen::text( 2, $ctx );
		$this->assertSame( 105.0, $t2['total'] );
		$this->assertStringContainsString( 'Mahngebühr von 5,00 €', $t2['body'] );

		$this->assertStringContainsString( 'Letzte Mahnung', Mahnwesen::text( 3, $ctx )['subject'] );
	}

	public function test_verzugszinsen() {
		$this->assertSame( array( 'rate' => 10.27, 'amount' => 25.32 ), Mahnwesen::interest( 1000, 1.27, 90, true ) );
		$this->assertSame( 6.27, Mahnwesen::interest( 1000, 1.27, 90, false )['rate'], 'Verbraucher: plus 5 Punkte' );
		$this->assertSame( 0.0, Mahnwesen::interest( 1000, 1.27, 0, true )['amount'] );
	}

	public function test_zahlung_deckt_nebenforderungen() {
		$this->assertTrue( Mahnwesen::covers_extras( 105, 100, 5 ) );
		$this->assertFalse( Mahnwesen::covers_extras( 100, 100, 5 ) );
		$this->assertFalse( Mahnwesen::covers_extras( 200, 100, 0 ), 'Ohne Gebühr nichts zu buchen' );
	}

	public function test_letzte_mahnung_mit_zinsen_pauschale_und_frueheren_gebuehren() {
		$t = Mahnwesen::text(
			3,
			array(
				'company'  => 'Laden',
				'person'   => '',
				'shop'     => 'Lindenweg16',
				'iban'     => '',
				'items'    => array( array( 'number' => 'A-7', 'date' => '2026-08-01', 'value' => 1000, 'due' => '2026-08-15' ) ),
				'fee'      => 10,
				'interest' => 25.32,
				'rate'     => 10.27,
				'days'     => 90,
				'flat'     => 40,
				'earlier'  => 5,
				'new_due'  => '2026-11-11',
			)
		);
		$this->assertSame( 1080.32, $t['total'] );
		$this->assertStringContainsString( 'Verzugszinsen nach § 288 BGB (10,27 % p. a., 90 Tage): 25,32 €', $t['body'] );
		$this->assertStringContainsString( 'Verzugspauschale', $t['body'] );
		$this->assertStringContainsString( 'Aus früheren Mahnungen offen: 5,00 €', $t['body'] );
		$this->assertStringStartsWith( 'Guten Tag,', $t['body'] );
	}
}
