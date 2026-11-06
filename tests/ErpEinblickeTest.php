<?php
/**
 * Einblicke: Produkt-Trichter, Kampagnen, Suche, Fehlerseiten – reine Regeln und Datenschutz-Grenzen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Insights;
use Lindenweg16\Erp\Stat;
use PHPUnit\Framework\TestCase;

/**
 * Tests der Einblicke.
 */
class ErpEinblickeTest extends TestCase {

	private function read( $rel ) {
		return file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_suchbegriff() {
		$this->assertSame( 'haken wand', Insights::clean_term( '  Haken   WAND ' ) );
		$this->assertSame( '', Insights::clean_term( 'max@example.com' ) );
		$this->assertSame( '', Insights::clean_term( 'https://x.de' ) );
		$this->assertSame( '', Insights::clean_term( 'bestellung 12345678' ) );
		$this->assertSame( '', Insights::clean_term( 'a' ) );
		$this->assertSame( '', Insights::clean_term( str_repeat( 'x', 80 ) ) );
		$this->assertSame( '', Insights::clean_term( array( 'x' ) ) );
	}

	public function test_kampagne_aus_adresse() {
		$this->assertSame( array( 'source' => 'instagram', 'campaign' => 'herbst-2026' ), Insights::campaign( '?utm_source=Instagram&utm_campaign=herbst-2026' ) );
		$this->assertSame( array( 'source' => 'beilage', 'campaign' => '' ), Insights::campaign( '?utm_source=beilage' ) );
		$this->assertSame( array( 'source' => 'ohne-quelle', 'campaign' => 'x' ), Insights::campaign( '?utm_campaign=x' ) );
		$this->assertNull( Insights::campaign( '' ) );
		$this->assertNull( Insights::campaign( '?a=b' ) );
		$this->assertNull( Insights::campaign( 123 ) );

		$c = Insights::campaign( '?utm_source=a<script>b&utm_campaign=' . str_repeat( 'z', 100 ) );
		$this->assertSame( 'ascriptb', $c['source'], 'Nur Buchstaben, Ziffern und _.- bleiben.' );
		$this->assertSame( 40, strlen( $c['campaign'] ) );
	}

	public function test_dateien_sind_keine_kaputten_links() {
		foreach ( array( '/favicon.ico', '/wp-content/uploads/a.jpg', '/wp-login.php', '/robots.txt', '/xmlrpc.php', '/.env', '/.git/config' ) as $p ) {
			$this->assertTrue( Insights::is_noise_path( $p ), $p );
		}

		foreach ( array( '/produkt/alter-haken', '/montage', '/' ) as $p ) {
			$this->assertFalse( Insights::is_noise_path( $p ), $p );
		}
	}

	public function test_trichter() {
		$f = Insights::funnel(
			array( 1 => 100, 2 => 20, 3 => 12, 4 => 2 ),
			array( 1 => 10, 2 => 0, 3 => 6 ),
			array( 1 => 5, 3 => 1, 9 => 4 ),
			array( 1 => 'Haken S', 3 => 'Haken L' )
		);

		$this->assertSame( 1, $f[0]['id'] );
		$this->assertSame( 10.0, $f[0]['cart_rate'] );
		$this->assertSame( 5.0, $f[0]['buy_rate'] );
		$this->assertSame( '', $f[0]['hint'] );
		$this->assertSame( 'nie_im_warenkorb', $f[1]['hint'], 'Produkt 2: 20 Ansichten, 0 im Warenkorb.' );
		$this->assertSame( 'warenkorb_abbruch', $f[2]['hint'], 'Produkt 3: 6 im Warenkorb, 1 gekauft.' );
		$this->assertSame( '#9', $f[4]['name'] );
		$this->assertNull( $f[4]['cart_rate'], 'Ohne Ansichten keine Quote.' );
	}

	public function test_mindestzahl() {
		$v = Insights::visible(
			array(
				array( 'label' => 'haken', 'extra' => '', 'n' => 5 ),
				array( 'label' => 'max mustermann', 'extra' => '', 'n' => 1 ),
			)
		);

		$this->assertCount( 1, $v['rows'] );
		$this->assertSame( 'haken', $v['rows'][0]['label'] );
		$this->assertSame( 1, $v['hidden'] );
	}

	public function test_kampagnen_mit_bestellungen() {
		$c = Insights::campaigns(
			array(
				array( 'label' => 'instagram', 'extra' => 'herbst', 'n' => 40 ),
				array( 'label' => 'instagram', 'extra' => 'sommer', 'n' => 10 ),
				array( 'label' => 'beilage', 'extra' => '', 'n' => 20 ),
			),
			array(
				'instagram' => array( 'anzahl' => 5, 'umsatz' => 250.5 ),
				'unbekannt' => array( 'anzahl' => 30, 'umsatz' => 900.0 ),
			)
		);

		$this->assertSame( 'instagram', $c[0]['source'] );
		$this->assertSame( 50, $c[0]['visits'] );
		$this->assertSame( 5, $c[0]['orders'] );
		$this->assertSame( 10.0, $c[0]['rate'] );
		$this->assertCount( 2, $c[0]['campaigns'] );
		$this->assertSame( 'beilage', $c[1]['source'] );
		$this->assertSame( 0.0, $c[1]['rate'], 'Besuche ohne Bestellung: Quote 0.' );
		$this->assertSame( 'unbekannt', $c[2]['source'], 'Bestellungen ohne Besuche stehen trotzdem da.' );
		$this->assertNull( $c[2]['rate'] );
	}

	public function test_keine_personendaten() {
		$files = $this->read( 'includes/Erp/Insights.php' ) . $this->read( 'includes/Erp/Stat.php' );

		foreach ( array( 'REMOTE_ADDR', 'setcookie', 'localStorage' ) as $word ) {
			$this->assertStringNotContainsString( $word, $files );
		}

		$this->assertStringContainsString( 'is_user_logged_in()', $files, 'Angemeldete Personen zählen nicht.' );
		$this->assertStringContainsString( 'is_bot', $files );

		preg_match( '/CREATE TABLE \{\$p\}stat \((.*?)\)\s*\{\$charset_collate\}/s', $this->read( 'includes/Erp/Schema.php' ), $m );
		$this->assertNotEmpty( $m, 'Tabelle stat fehlt im Schema.' );
		foreach ( array( 'ip', 'agent', 'user', 'cookie', 'token', 'visitor', 'session', 'email' ) as $personal ) {
			$this->assertDoesNotMatchRegularExpression( '/\b' . $personal . '\w*\s+(VARCHAR|BIGINT|CHAR|TEXT|INT)/i', $m[1] );
		}

		$this->assertLessThanOrEqual( 90, Stat::KEEP_DAYS );
		$this->assertGreaterThanOrEqual( 2, Insights::MIN_SHOW );
	}

	public function test_oberflaeche_und_beacon() {
		$js  = $this->read( 'assets/erp/pages/besucher.js' );
		$trf = $this->read( 'includes/Erp/Traffic.php' );

		$this->assertStringContainsString( "ui.api('besucher/einblicke", $js );
		$this->assertStringNotContainsString( 'innerHTML', $js );
		$this->assertStringContainsString( 'utm_', $trf, 'Das Skript meldet nur utm-Angaben, nicht die ganze Abfrage.' );
		$this->assertStringNotContainsString( 'location.search,', $trf );
	}
}
