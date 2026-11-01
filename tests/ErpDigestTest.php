<?php
/**
 * Täglicher Überblick: Zusammenstellung der Mail.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Digest;
use PHPUnit\Framework\TestCase;

class ErpDigestTest extends TestCase {

	public function test_nur_abschnitte_mit_inhalt() {
		$m = Digest::compose(
			array(
				array( 'title' => 'Mahnungen fällig', 'lines' => array( '1. Mahnung für Laden', '' ) ),
				array( 'title' => 'Angebote', 'lines' => array() ),
				array( 'title' => 'Bank', 'lines' => array( '3 Umsätze offen' ) ),
			),
			'Lindenweg16',
			'https://erp.example/',
			'2026-11-01'
		);
		$this->assertSame( 2, $m['count'] );
		$this->assertSame( 'Lindenweg16 · Heute zu tun (01.11.2026): 2 Punkte', $m['subject'] );
		$this->assertStringContainsString( "Mahnungen fällig\n– 1. Mahnung für Laden", $m['body'] );
		$this->assertStringNotContainsString( 'Angebote', $m['body'] );
		$this->assertStringEndsWith( 'https://erp.example/', $m['body'] );
	}

	public function test_nichts_zu_tun_keine_mail() {
		$m = Digest::compose( array( array( 'title' => 'X', 'lines' => array() ) ), 'L', 'u', '2026-11-01' );
		$this->assertSame( 0, $m['count'] );
		$this->assertSame( '', $m['body'] );
	}

	public function test_lange_listen_werden_gekuerzt() {
		$m = Digest::compose( array( array( 'title' => 'Viel', 'lines' => array_fill( 0, 20, 'x' ) ) ), 'L', 'u', '2026-11-01' );
		$this->assertStringContainsString( '… 5 weitere', $m['body'] );
		$this->assertSame( 20, $m['count'] );
	}
}
