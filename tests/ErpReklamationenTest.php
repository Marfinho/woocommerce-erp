<?php
/**
 * Reklamationen: Wareneingang prüfen, Auswertung, Verlauf.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Reklamationen as R;
use PHPUnit\Framework\TestCase;

class ErpReklamationenTest extends TestCase {

	public function test_wareneingang_pruefen() {
		$this->assertSame( '', R::receive_error( 3, 0, 2, 1 ) );
		$this->assertNotSame( '', R::receive_error( 3, 0, 0, 0 ), 'Nichts eingetragen' );
		$this->assertNotSame( '', R::receive_error( 3, 2, 2, 0 ), 'Mehr als reklamiert' );
		$this->assertNotSame( '', R::receive_error( 3, 0, -1, 2 ), 'Negativ' );
		$this->assertSame( '', R::receive_error( 3, 2, 1, 0 ), 'Rest passt genau' );
	}

	public function test_auswertung() {
		$s = R::stats(
			array(
				array( 'reason' => 'bruch', 'article_id' => 7, 'qty' => 1 ),
				array( 'reason' => 'bruch', 'article_id' => 7, 'qty' => 2 ),
				array( 'reason' => 'defekt', 'article_id' => 8, 'qty' => 1 ),
				array( 'reason' => 'unbekannt', 'article_id' => 0, 'qty' => 1 ),
			),
			array( 7 => array( 'sku' => 'VASE' ), 8 => array( 'sku' => 'HAKEN' ) )
		);

		$this->assertSame( array( 'bruch', 'Transportschaden', 2, 3.0 ), $s['by_reason'][0] );
		$this->assertCount( 3, $s['by_reason'], 'Unbekannter Grund zählt als Sonstiges' );
		$this->assertSame( array( array( 'VASE', 2 ), array( 'HAKEN', 1 ) ), $s['top_articles'] );
	}

	public function test_verlauf() {
		$j = R::add_history( '', 'Fall angelegt', 'Sven', 100 );
		$j = R::add_history( $j, 'Ware zurück', 'Sven', 200 );
		$l = json_decode( $j, true );

		$this->assertCount( 2, $l );
		$this->assertSame( array( 'ts' => 200, 'who' => 'Sven', 'text' => 'Ware zurück' ), $l[1] );
		$this->assertCount( 1, json_decode( R::add_history( 'kaputt', 'x', '', 1 ), true ), 'Kaputter Verlauf beginnt neu' );
	}

	public function test_kundenmails() {
		$c = array( 'number' => 'RK-2026-0001', 'customer' => 'Frau Meier' );
		$e = R::customer_mail( 'eingang', $c, 'Lindenweg16' );
		$this->assertStringContainsString( 'RK-2026-0001', $e['subject'] );
		$this->assertStringStartsWith( 'Hallo Frau Meier,', $e['body'] );

		$l = R::customer_mail( 'loesung', $c, 'Lindenweg16', 'gutschrift', 24.9 );
		$this->assertStringContainsString( 'Wir erstatten Ihnen 24,90 €', $l['body'] );
		$this->assertStringContainsString( 'nicht anerkennen', R::customer_mail( 'loesung', $c, 'L', 'abgelehnt' )['body'] );
	}
}
