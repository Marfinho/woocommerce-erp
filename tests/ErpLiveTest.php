<?php
/**
 * Live-Dashboard: Seitenaufruf-Zähler und Zusammenstellung.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Erp\Traffic;

class ErpLiveTest extends TestCase {

	protected function read( $rel ) {
		return file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_bereiche_der_website() {
		$this->assertSame( 'start', Traffic::classify( '/' ) );
		$this->assertSame( 'start', Traffic::classify( '' ) );
		$this->assertSame( 'shop', Traffic::classify( '/shop/' ) );
		$this->assertSame( 'shop', Traffic::classify( '/produkt/haken-s' ) );
		$this->assertSame( 'warenkorb', Traffic::classify( '/warenkorb/' ) );
		$this->assertSame( 'kasse', Traffic::classify( '/kasse/bestellung-erhalten/12' ) );
		$this->assertSame( 'inhalt', Traffic::classify( '/ueber-uns' ) );
		$this->assertSame( 'inhalt', Traffic::classify( '/produktion-info' ), 'Nur der Pfadanfang zählt, nicht ein Wortanfang.' );
	}

	public function test_roboter_werden_nicht_gezaehlt() {
		$this->assertTrue( Traffic::is_bot( '' ) );
		$this->assertTrue( Traffic::is_bot( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) );
		$this->assertTrue( Traffic::is_bot( 'curl/8.4.0' ) );
		$this->assertTrue( Traffic::is_bot( 'HeadlessChrome/120' ) );
		$this->assertFalse( Traffic::is_bot( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1' ) );
	}

	public function test_auswertung_heute_gestern_und_letzte_30_minuten() {
		$now  = strtotime( '2026-10-22 12:34:00 UTC' );
		$rows = array(
			array( 'day' => '2026-10-22', 'hour' => 12, 'slot' => 3, 'section' => 'shop', 'views' => 5 ),     // 12:30, laufendes Fenster
			array( 'day' => '2026-10-22', 'hour' => 12, 'slot' => 1, 'section' => 'start', 'views' => 4 ),    // 12:10, gerade noch drin
			array( 'day' => '2026-10-22', 'hour' => 12, 'slot' => 0, 'section' => 'shop', 'views' => 9 ),     // 12:00, zu alt
			array( 'day' => '2026-10-22', 'hour' => 8, 'slot' => 2, 'section' => 'kasse', 'views' => 2 ),
			array( 'day' => '2026-10-21', 'hour' => 12, 'slot' => 3, 'section' => 'shop', 'views' => 7 ),
		);

		$s = Traffic::summarize( $rows, $now );

		$this->assertSame( 20, $s['today'] );
		$this->assertSame( 7, $s['yesterday'] );
		$this->assertSame( 9, $s['last30'] );
		$this->assertSame( 14, $s['sections']['shop'] );
		$this->assertSame( 2, $s['sections']['kasse'] );
		$this->assertSame( strtotime( '2026-10-22 12:30:00 UTC' ), $s['last_ts'] );
		$this->assertSame( 0, Traffic::summarize( array(), $now )['last_ts'], 'Ohne Aufrufe gibt es keinen letzten.' );
		$this->assertCount( 24, $s['series'] );
		$this->assertSame( 18, $s['series'][23]['views'], 'Die letzte Stunde ist 12 Uhr: 5 + 4 + 9.' );
		$this->assertSame( 2, $s['series'][19]['views'], 'Vier Stunden davor: 8 Uhr.' );
	}

	public function test_zaehler_speichert_nichts_ueber_personen() {
		$src = $this->read( 'includes/Erp/Traffic.php' );
		$sql = $this->read( 'includes/Erp/Schema.php' );

		$this->assertStringNotContainsString( 'REMOTE_ADDR', $src );
		$this->assertStringNotContainsString( 'setcookie', $src );
		preg_match( '/CREATE TABLE \{\$p\}traffic \((.*?)\)\s*\{\$charset_collate\}/s', $sql, $m );
		$this->assertNotEmpty( $m, 'Tabelle traffic fehlt im Schema.' );
		foreach ( array( 'ip', 'agent', 'path', 'url', 'user', 'cookie', 'referer' ) as $personal ) {
			$this->assertDoesNotMatchRegularExpression( '/\b' . $personal . '\w*\s+(VARCHAR|BIGINT|CHAR|TEXT)/i', $m[1], 'Die Tabelle darf keine Spalte „' . $personal . '“ haben.' );
		}
		$this->assertStringContainsString( 'is_user_logged_in()', $src, 'Angemeldete Personen tragen das Skript nicht.' );
		$this->assertStringContainsString( 'doNotTrack', $src );
		$this->assertStringContainsString( 'type:"text/plain"', $src, 'Kein JSON-Typ: sonst fragt der Browser vorab und fremde Ursprünge scheitern.' );
		$this->assertStringContainsString( "wp_parse_url( rest_url( 'lw16/v1/pv' ) )", $src, 'Adresse ohne Host, damit www und ohne www nicht auseinanderfallen.' );
	}

	public function test_jeder_block_hat_sein_recht() {
		$src = $this->read( 'includes/Erp/Pages/Live.php' );

		foreach ( array( "Access::can( 'verkauf.orders' )", "Access::can( 'finanzen.berichte' )", "Access::can( 'fertigung.workflow' )", "Access::can( 'verkauf.marketing' )" ) as $rule ) {
			$this->assertStringContainsString( $rule, $src );
		}

		$this->assertStringNotContainsString( "'wochenstunden'", substr( $src, strpos( $src, 'const KPIS' ), 260 ), 'Personenbezogene Kennzahlen gehören nicht an die Wand.' );
	}

	public function test_aufgaben_an_der_wand_ohne_persoenliche_mails() {
		$live  = $this->read( 'includes/Erp/Pages/Live.php' );
		$tasks = $this->read( 'includes/Erp/Tasks.php' );

		$this->assertStringContainsString( 'Tasks::collect( $everything )', $live );
		$this->assertStringContainsString( '$everything ? 0 : self::unread_mail()', $tasks, 'Die Mails einer Person gehören nicht an die Wand.' );
		$this->assertStringNotContainsString( "Access::can(", substr( $tasks, strpos( $tasks, 'public static function collect' ), 9000 ), 'Rechteprüfung nur über allowed().' );
	}

	public function test_live_anzeige_bleibt_ein_jahr_angemeldet() {
		$src = $this->read( 'includes/Erp/LiveViewer.php' );

		$this->assertStringContainsString( "'auth_cookie_expiration'", $src );
		$this->assertStringContainsString( 'const SESSION = YEAR_IN_SECONDS', $src );
		$this->assertStringContainsString( 'LiveViewer::register()', $this->read( 'includes/Erp/Shell.php' ) );
		$this->assertStringContainsString( "array( LiveViewer::class, 'can_open' )", $this->read( 'includes/Erp/Pages/Live.php' ) );
		$this->assertStringContainsString( "array( LiveViewer::class, 'can_open' )", $this->read( 'includes/Erp/RestApi.php' ) );
	}

	public function test_abgelaufenes_token_wird_erneuert() {
		$js = $this->read( 'assets/erp/erp.js' );

		$this->assertMatchesRegularExpression( "/return send\(path, opts\)\.catch.*?rest_cookie_invalid_nonce.*?refreshNonce\(\)/s", $js );
	}

	public function test_oberflaeche_ist_vom_kopf_aus_erreichbar() {
		$this->assertStringContainsString( "href: '#/live'", $this->read( 'assets/erp/erp.js' ) );
		$this->assertStringContainsString( "UI.routes['live']", $this->read( 'assets/erp/pages/live.js' ) );
		$this->assertStringContainsString( 'requestFullscreen', $this->read( 'assets/erp/pages/live.js' ) );
		$this->assertStringContainsString( 'wakeLock', $this->read( 'assets/erp/pages/live.js' ) );
	}
}
