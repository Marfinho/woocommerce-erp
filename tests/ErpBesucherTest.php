<?php
/**
 * Besucher jetzt: Bereinigung der Meldungen, Verdichtung und Datenschutz-Grenzen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Navigation;
use Lindenweg16\Erp\Permissions;
use Lindenweg16\Erp\Presence;
use Lindenweg16\Erp\Traffic;
use PHPUnit\Framework\TestCase;

/**
 * Tests der Anwesenheit.
 */
class ErpBesucherTest extends TestCase {

	private function read( $rel ) {
		return file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_kennung_nur_zufallszeichen() {
		$this->assertSame( 'abc123def4567', Presence::clean_token( 'abc123def4567' ) );
		$this->assertSame( '', Presence::clean_token( 'kurz' ) );
		$this->assertSame( '', Presence::clean_token( 'ABC123DEF4567' ) );
		$this->assertSame( '', Presence::clean_token( "abc123def456'; DROP" ) );
		$this->assertSame( '', Presence::clean_token( array( 'x' ) ) );
	}

	public function test_pfad_ohne_abfrage_und_ohne_bestellnummer() {
		$this->assertSame( '/produkt/haster-haken', Presence::clean_path( '/produkt/haster-haken/?utm_source=x#top' ) );
		$this->assertSame( '/', Presence::clean_path( '' ) );
		$this->assertSame( '/', Presence::clean_path( '/?s=suchwort' ) );
		$this->assertSame( '/kasse', Presence::clean_path( '/kasse/bestellung-erhalten/1234' ) );
		$this->assertSame( '/warenkorb', Presence::clean_path( '/warenkorb/' ) );
		$this->assertLessThanOrEqual( 190, strlen( Presence::clean_path( '/' . str_repeat( 'a', 500 ) ) ) );
	}

	public function test_titel_nicht_in_warenkorb_und_kasse() {
		$this->assertSame( 'Haster Haken – Lindenweg16', Presence::clean_title( "  Haster   Haken – Lindenweg16 ", 'shop' ) );
		$this->assertSame( '', Presence::clean_title( 'Kasse – Max Mustermann', 'kasse' ) );
		$this->assertSame( '', Presence::clean_title( 'Warenkorb', 'warenkorb' ) );
		$this->assertSame( '', Presence::clean_title( null, 'shop' ) );
		$this->assertSame( 100, strlen( Presence::clean_title( str_repeat( 'x', 300 ), 'inhalt' ) ) );
	}

	public function test_herkunft_nur_fremder_host() {
		$this->assertSame( 'google.com', Presence::clean_referrer( 'https://www.google.com/search?q=geheim', 'lindenweg16.de' ) );
		$this->assertSame( '', Presence::clean_referrer( 'https://www.lindenweg16.de/shop/', 'lindenweg16.de' ) );
		$this->assertSame( '', Presence::clean_referrer( '', 'lindenweg16.de' ) );
		$this->assertSame( '', Presence::clean_referrer( 'kein link', 'lindenweg16.de' ) );
	}

	public function test_geraet_aus_breite() {
		$this->assertSame( 'm', Presence::device( 390 ) );
		$this->assertSame( 'd', Presence::device( 1440 ) );
		$this->assertSame( '', Presence::device( 0 ) );
		$this->assertSame( '', Presence::device( 'breit' ) );
	}

	public function test_verdichtung() {
		$now  = 10000;
		$rows = array(
			array( 'first_ts' => 9700, 'seen_ts' => 9990, 'path' => '/produkt/a', 'title' => 'A', 'section' => 'shop', 'ref' => 'google.com', 'dev' => 'm' ),
			array( 'first_ts' => 9900, 'seen_ts' => 9980, 'path' => '/produkt/a', 'title' => 'A', 'section' => 'shop', 'ref' => '', 'dev' => 'd' ),
			array( 'first_ts' => 9950, 'seen_ts' => 9995, 'path' => '/kasse', 'title' => '', 'section' => 'kasse', 'ref' => '', 'dev' => 'd' ),
			array( 'first_ts' => 9000, 'seen_ts' => 9500, 'path' => '/alt', 'title' => '', 'section' => 'inhalt', 'ref' => '', 'dev' => 'd' ),
		);

		$s = Presence::summarize( $rows, $now );

		$this->assertSame( 3, $s['online'], 'Die Seite ohne Meldung seit 500 Sekunden zählt nicht.' );
		$this->assertSame( 2, $s['sections']['shop'] );
		$this->assertSame( 1, $s['sections']['kasse'] );
		$this->assertSame( 1, $s['devices']['m'] );
		$this->assertSame( 2, $s['devices']['d'] );
		$this->assertSame( '/produkt/a', $s['pages'][0]['path'] );
		$this->assertSame( 2, $s['pages'][0]['count'] );
		$this->assertSame( array( array( 'host' => 'google.com', 'count' => 1 ) ), $s['refs'] );
		$this->assertSame( 300, $s['visitors'][0]['since'], 'Wer am längsten da ist, steht oben.' );
		$this->assertArrayNotHasKey( 'token', $s['visitors'][0], 'Die Kennung verlässt den Server nicht.' );
		$this->assertSame( 0, Presence::summarize( array(), $now )['online'] );
	}

	public function test_meldung_wird_bereinigt() {
		$d = Traffic::presence_data(
			array(
				't' => 'Kasse von Max',
				'r' => 'https://www.instagram.com/p/xyz',
				'w' => 400,
			),
			'/kasse/bestellung-erhalten/55?key=wc_order_abc'
		);

		$this->assertSame( '/kasse', $d['path'] );
		$this->assertSame( '', $d['title'] );
		$this->assertSame( 'instagram.com', $d['ref'] );
		$this->assertSame( 'm', $d['dev'] );
	}

	public function test_keine_personendaten_und_kein_browserspeicher() {
		$presence = $this->read( 'includes/Erp/Presence.php' );
		$traffic  = $this->read( 'includes/Erp/Traffic.php' );
		$schema   = $this->read( 'includes/Erp/Schema.php' );

		foreach ( array( 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'setcookie', 'X_FORWARDED' ) as $word ) {
			$this->assertStringNotContainsString( $word, $presence, $word . ' darf in Presence nicht vorkommen.' );
		}

		foreach ( array( 'localStorage', 'sessionStorage', 'document.cookie', 'indexedDB' ) as $word ) {
			$this->assertStringNotContainsString( $word, $traffic, 'Das Skript darf nichts im Browser speichern: ' . $word );
		}

		preg_match( '/CREATE TABLE \{\$p\}presence \((.*?)\)\s*\{\$charset_collate\}/s', $schema, $m );
		$this->assertNotEmpty( $m, 'Tabelle presence fehlt im Schema.' );
		foreach ( array( 'ip', 'agent', 'user', 'cookie', 'email', 'query' ) as $personal ) {
			$this->assertDoesNotMatchRegularExpression( '/\b' . $personal . '\w*\s+(VARCHAR|BIGINT|CHAR|TEXT|INT)/i', $m[1], 'Die Tabelle darf keine Spalte „' . $personal . '“ haben.' );
		}

		$this->assertStringContainsString( 'is_user_logged_in()', $traffic );
		$this->assertStringContainsString( 'doNotTrack', $traffic );
		$this->assertStringContainsString( 'pagehide', $traffic, 'Beim Schließen verschwindet die Seite sofort aus der Anzeige.' );
		$this->assertLessThanOrEqual( 1800, Presence::KEEP );
	}

	public function test_recht_navigation_und_seite() {
		$catalog = Permissions::catalog();
		$items   = Navigation::items();

		$this->assertSame( array( 'manage_woocommerce' ), $catalog['verkauf.besucher']['caps'] );
		$this->assertSame( 'native', $items['besucher']['kind'] );
		$this->assertSame( 'verkauf.besucher', $items['besucher']['perm'] );
		$this->assertSame( 'verkauf', Navigation::place( 'besucher' )[0] );

		$js = $this->read( 'assets/erp/pages/besucher.js' );
		$this->assertStringContainsString( "UI.pages['besucher']", $js );
		$this->assertStringContainsString( "ui.api('besucher')", $js );
		$this->assertStringNotContainsString( 'innerHTML', $js, 'Seitentitel kommen von außen: nur als Text einsetzen.' );
	}
}
