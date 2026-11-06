<?php
/**
 * Wege auf der Website: Quelle, Verdichtung, Datenschutz-Grenzen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Flow;
use PHPUnit\Framework\TestCase;

/**
 * Tests der Übergangszähler.
 */
class ErpWegeTest extends TestCase {

	private function read( $rel ) {
		return file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_quelle() {
		$this->assertSame( '/shop', Flow::source( 'https://www.lindenweg16.de/shop/?orderby=x', 'lindenweg16.de' ) );
		$this->assertSame( '/', Flow::source( 'https://lindenweg16.de/', 'lindenweg16.de' ) );
		$this->assertSame( '@google.com', Flow::source( 'https://www.google.com/search?q=geheim', 'lindenweg16.de' ) );
		$this->assertSame( '', Flow::source( '', 'lindenweg16.de' ) );
		$this->assertSame( '', Flow::source( 'unsinn', 'lindenweg16.de' ) );
		$this->assertSame( '/kasse', Flow::source( 'https://lindenweg16.de/kasse/bestellung-erhalten/9?key=x', 'lindenweg16.de' ), 'Kasse bleibt ohne Bestellnummer.' );
	}

	public function test_zeitraum() {
		$this->assertSame( 30, Flow::clean_days( null ) );
		$this->assertSame( 7, Flow::clean_days( '7' ) );
		$this->assertSame( 90, Flow::clean_days( 90 ) );
		$this->assertSame( 30, Flow::clean_days( 5 ) );
	}

	public function test_verdichtung() {
		$rows = array(
			array( 'from_path' => '/', 'to_path' => '/shop', 'views' => 10 ),
			array( 'from_path' => '/shop', 'to_path' => '/produkt/a', 'views' => 6 ),
			array( 'from_path' => '/produkt/a', 'to_path' => '/warenkorb', 'views' => 4 ),
			array( 'from_path' => '/warenkorb', 'to_path' => '/kasse', 'views' => 3 ),
			array( 'from_path' => '/shop', 'to_path' => '/produkt/b', 'views' => 2 ),
			array( 'from_path' => '@google.com', 'to_path' => '/produkt/a', 'views' => 5 ),
			array( 'from_path' => '', 'to_path' => '/', 'views' => 7 ),
			array( 'from_path' => '@bing.com', 'to_path' => '/', 'views' => 1 ),
		);

		$s = Flow::summarize( $rows, '/produkt/a' );

		$this->assertSame( '/', $s['transitions'][0]['from'] );
		$this->assertSame( 10, $s['transitions'][0]['count'] );
		$this->assertSame( 2, $s['hidden'], 'Das Paar mit 2 Fällen wird nicht einzeln gezeigt.' );
		$this->assertCount( 4, $s['transitions'], 'Nur interne Paare mit mindestens 3 Fällen.' );
		$this->assertSame( array( 'path' => '/', 'count' => 8 ), $s['entries'][0], 'Direkt (7) und Bing (1) steigen auf der Startseite ein.' );
		$this->assertSame( array( 'path' => '/produkt/a', 'count' => 5 ), $s['entries'][1] );
		$this->assertSame( array( array( 'path' => '@google.com', 'count' => 5 ) ), $s['sources'], 'Seltene Quellen (unter 3) fehlen.' );
		$this->assertSame( 4, $s['cart']['in'] );
		$this->assertSame( 3, $s['cart']['to_kasse'] );
		$this->assertSame( '@google.com', $s['before'][0]['path'] );
		$this->assertSame( '/warenkorb', $s['after'][0]['path'] );
		$this->assertSame( array(), Flow::summarize( $rows )['before'] );
		$this->assertSame( array(), Flow::summarize( array() )['transitions'] );
	}

	public function test_keine_kennung_im_zaehler() {
		$src    = $this->read( 'includes/Erp/Flow.php' );
		$schema = $this->read( 'includes/Erp/Schema.php' );

		foreach ( array( 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'setcookie', 'token' ) as $word ) {
			$this->assertStringNotContainsString( $word, $src );
		}

		preg_match( '/CREATE TABLE \{\$p\}flow \((.*?)\)\s*\{\$charset_collate\}/s', $schema, $m );
		$this->assertNotEmpty( $m, 'Tabelle flow fehlt im Schema.' );
		foreach ( array( 'ip', 'agent', 'user', 'cookie', 'token', 'visitor', 'session' ) as $personal ) {
			$this->assertDoesNotMatchRegularExpression( '/\b' . $personal . '\w*\s+(VARCHAR|BIGINT|CHAR|TEXT|INT)/i', $m[1], 'Die Tabelle darf keine Spalte „' . $personal . '“ haben.' );
		}

		$this->assertLessThanOrEqual( 90, Flow::KEEP_DAYS );
		$this->assertGreaterThanOrEqual( 3, Flow::MIN_SHOW );
	}

	public function test_oberflaeche() {
		$js = $this->read( 'assets/erp/pages/besucher.js' );

		$this->assertStringContainsString( "ui.api('besucher/wege", $js );
		$this->assertStringNotContainsString( 'innerHTML', $js );
	}
}
