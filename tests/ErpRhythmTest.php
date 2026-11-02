<?php
/**
 * Nachkauf-Gefühl: Rhythmus, Zustand, Favoriten, Mail.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Rhythm as R;
use PHPUnit\Framework\TestCase;

class ErpRhythmTest extends TestCase {

	public function test_median() {
		$this->assertSame( 2.0, R::median( array( 3, 1, 2 ) ) );
		$this->assertSame( 2.5, R::median( array( 4, 1, 2, 3 ) ) );
		$this->assertNull( R::median( array() ) );
	}

	public function test_regelmaessiger_haendler_bald_faellig() {
		$r = R::analyse( array( '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01' ), '2026-10-25' );
		$this->assertSame( 6, $r['orders'] );
		$this->assertSame( 31, $r['interval'] );
		$this->assertSame( '2026-11-01', $r['next'] );
		$this->assertSame( 7, $r['days_to'] );
		$this->assertSame( 'bald', $r['state'] );
		$this->assertGreaterThan( 0.9, $r['confidence'] );
	}

	public function test_ueberfaellig_und_verloren() {
		$dates = array( '2026-01-01', '2026-02-01', '2026-03-01' );
		$this->assertSame( 'ueberfaellig', R::analyse( $dates, '2026-04-20' )['state'] );
		$this->assertSame( 'verloren', R::analyse( $dates, '2026-07-01' )['state'] );
		$this->assertSame( 'zeit', R::analyse( array( '2025-01-01', '2026-01-01' ), '2026-03-01' )['state'] );
		$this->assertNull( R::analyse( array( '2026-01-01', '2026-01-01' ), '2026-03-01' ), 'Gleicher Tag zählt einmal' );
	}

	public function test_ausreisser_stoeren_den_median_nicht() {
		$r = R::analyse( array( '2026-01-01', '2026-01-15', '2026-01-29', '2026-05-01', '2026-05-15' ), '2026-05-20' );
		$this->assertSame( 14, $r['interval'] );
		$this->assertLessThan( 0.9, $r['confidence'], 'Die Lücke kostet Sicherheit' );
	}

	public function test_favoriten_und_mail() {
		$f = R::favourites( array( array( 'article_id' => 1, 'label' => 'Vase', 'qty' => 5 ), array( 'article_id' => 2, 'label' => 'Haken', 'qty' => 50 ), array( 'article_id' => 1, 'label' => 'Vase', 'qty' => 7 ) ) );
		$this->assertSame( array( 'Vase', 'Haken' ), array_column( $f, 'label' ) );
		$this->assertSame( 12.0, $f[0]['qty'] );

		$m = R::mail_text( array( 'person' => 'Frau Meier', 'favourites' => $f, 'b2b' => true, 'link' => '[Nachbestell-Link]' ), 'Lindenweg16' );
		$this->assertStringContainsString( 'Zeit für Nachschub', $m['subject'] );
		$this->assertStringContainsString( '– Vase (zuletzt 6 Stück)', $m['body'] );
		$this->assertStringContainsString( '[Nachbestell-Link]', $m['body'] );
		$this->assertStringStartsWith( 'Hallo Frau Meier,', $m['body'] );
	}
}
