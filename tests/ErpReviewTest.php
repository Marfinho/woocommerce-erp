<?php
/**
 * Wochenrückblick: Sätze und genau ein Tipp.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Review;
use PHPUnit\Framework\TestCase;

class ErpReviewTest extends TestCase {

	private function f( array $over = array() ) {
		return array_merge(
			array(
				'revenue'       => 1200,
				'revenue_prev'  => 1000,
				'orders'        => 14,
				'orders_prev'   => 11,
				'best'          => array( array( 'label' => 'Vase', 'qty' => 9 ), array( 'label' => 'Haken', 'qty' => 30 ) ),
				'channel'       => array( 'label' => 'Shop', 'rate' => 34.5 ),
				'market'        => null,
				'rate'          => 28.4,
				'rate_prev'     => 25,
				'printers'      => array( array( 'name' => 'X1C', 'plates' => 10, 'scrap' => 1 ), array( 'name' => 'P1S', 'plates' => 6, 'scrap' => 2 ) ),
				'below_target'  => 3,
				'empty'         => 0,
				'reorder'       => array( 'Blumen Müller' ),
				'lost'          => 0,
				'cash_in4'      => 4200,
				'buffer'        => 1000,
				'worst_product' => null,
			),
			$over
		);
	}

	public function test_saetze() {
		$r = Review::compose( $this->f() );
		$this->assertSame( '1.200,00 € Umsatz aus 14 Verkäufen – 20 % mehr als letzte Woche', $r['headline'] );
		$this->assertContains( 'Am meisten verkauft: Vase (9×), Haken (30×).', $r['lines'] );
		$this->assertContains( 'Dein Stundenlohn diese Woche: 28,40 € (+3,40 €).', $r['lines'] );
		$this->assertContains( '16 Platten gedruckt. Sorgenkind: P1S mit Ausschuss auf 2 von 6 Platten.', $r['lines'] );
		$this->assertStringContainsString( 'P1S', $r['tip'], 'Drucker mit viel Ausschuss geht vor' );
	}

	public function test_tipp_reihenfolge() {
		$this->assertStringContainsString( 'Geld wird knapp', Review::compose( $this->f( array( 'cash_in4' => 500 ) ) )['tip'] );
		$ok = $this->f( array( 'printers' => array(), 'empty' => 2 ) );
		$this->assertStringContainsString( 'Leere Artikel', Review::compose( $ok )['tip'] );
		$this->assertStringContainsString( 'Blumen Müller', Review::compose( $this->f( array( 'printers' => array() ) ) )['tip'] );
		$this->assertStringContainsString( 'Läuft', Review::compose( $this->f( array( 'printers' => array(), 'reorder' => array() ) ) )['tip'] );
	}

	public function test_ohne_vorwoche_und_mail() {
		$r = Review::compose( $this->f( array( 'revenue_prev' => 0 ) ) );
		$this->assertStringNotContainsString( 'letzte Woche', $r['headline'] );
		$m = Review::mail( $r, 'Lindenweg16', 'KW 45/2026', 'https://erp/' );
		$this->assertSame( 'Lindenweg16 · Deine Woche (KW 45/2026)', $m['subject'] );
		$this->assertStringContainsString( 'Tipp für nächste Woche:', $m['body'] );
	}
}
