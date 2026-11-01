<?php
/**
 * Inventur: Bestand zu einem Zeitpunkt, Auswertung, Korrektur.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Inventur;
use PHPUnit\Framework\TestCase;

class ErpInventurTest extends TestCase {

	private function movs() {
		return array(
			array( 'article_id' => 7, 'color' => 'weiss', 'location' => 'lager', 'qty' => 10, 'moved_at' => '2026-10-01 08:00:00' ),
			array( 'article_id' => 7, 'color' => 'weiss', 'location' => 'lager', 'qty' => -2, 'moved_at' => '2026-10-05 09:00:00' ),
			array( 'article_id' => 7, 'color' => 'weiss', 'location' => 'kommission', 'qty' => 2, 'moved_at' => '2026-10-05 09:00:00' ),
			array( 'article_id' => 7, 'color' => 'schwarz', 'location' => 'lager', 'qty' => 4, 'moved_at' => '2026-10-02 10:00:00' ),
			array( 'article_id' => 7, 'color' => '', 'location' => 'lager', 'qty' => 1, 'moved_at' => '2026-10-02 10:00:00' ),
			array( 'article_id' => 8, 'color' => 'weiss', 'location' => 'lager', 'qty' => 3, 'moved_at' => '2026-10-02 10:00:00' ),
			array( 'article_id' => 8, 'color' => 'weiss', 'location' => 'lager', 'qty' => -3, 'moved_at' => '2026-10-03 10:00:00' ),
			array( 'article_id' => 7, 'color' => 'weiss', 'location' => 'lager', 'qty' => -1, 'moved_at' => '2026-10-20 10:00:00' ),
		);
	}

	public function test_bestand_zum_zeitpunkt() {
		$this->assertSame( 10.0, Inventur::balance_at( $this->movs(), 7, 'weiss', 'lager', '2026-10-04 23:59:59' ) );
		$this->assertSame( 8.0, Inventur::balance_at( $this->movs(), 7, 'weiss', 'lager', '2026-10-05 09:00:00' ), 'Gleicher Zeitpunkt zählt mit' );
		$this->assertSame( 7.0, Inventur::balance_at( $this->movs(), 7, 'weiss', 'lager', '2026-10-31 00:00:00' ) );
		$this->assertSame( 1.0, Inventur::balance_at( $this->movs(), 7, 'ungeklaert', 'lager', '2026-10-31 00:00:00' ), 'Leere Farbe ist ungeklärt' );
	}

	public function test_alle_bestaende_ohne_nullen() {
		$b = Inventur::balances_at( $this->movs(), '2026-10-10 00:00:00' );
		$this->assertSame( 8.0, $b[7]['weiss']['lager'] );
		$this->assertSame( 2.0, $b[7]['weiss']['kommission'] );
		$this->assertSame( 4.0, $b[7]['schwarz']['lager'] );
		$this->assertArrayNotHasKey( 8, $b, 'Artikel mit Bestand 0 fällt weg' );
	}

	public function test_auswertung_der_zaehlliste() {
		$s = Inventur::summary(
			array(
				array( 'expected_qty' => 10, 'counted_qty' => 8, 'unit_value' => 2.5 ),
				array( 'expected_qty' => 5, 'counted_qty' => 5, 'unit_value' => 1 ),
				array( 'expected_qty' => 3, 'counted_qty' => null, 'unit_value' => 4 ),
				array( 'expected_qty' => 0, 'counted_qty' => 2, 'unit_value' => null ),
			)
		);
		$this->assertSame( 4, $s['lines'] );
		$this->assertSame( 3, $s['counted'] );
		$this->assertSame( 1, $s['open'] );
		$this->assertSame( 2, $s['with_diff'] );
		$this->assertSame( 0.0, $s['diff_qty'] );
		$this->assertSame( -5.0, $s['diff_value'] );
		$this->assertSame( 42.0, $s['expected_value'] );
		$this->assertSame( 37.0, $s['counted_value'] );
	}

	public function test_korrektur() {
		$this->assertSame( -2.0, Inventur::correction( 6, 8 ) );
		$this->assertSame( 0.5, Inventur::correction( 8.5, 8 ) );
	}
}
