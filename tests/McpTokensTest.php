<?php
/**
 * Schlüssel und Werkzeugregeln des MCP-Zugangs.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Mcp\Tokens;
use Lindenweg16\Mcp\Tools;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/Mcp/Tokens.php';
require_once dirname( __DIR__ ) . '/includes/Mcp/Tools.php';

/**
 * Tests für Mcp\Tokens und die reinen Regeln aus Mcp\Tools.
 */
class McpTokensTest extends TestCase {

	public function test_schluessel_form_und_vergleich() {
		$a = Tokens::generate();
		$b = Tokens::generate();

		$this->assertNotSame( $a, $b );
		$this->assertTrue( Tokens::looks_valid( $a ) );
		$this->assertFalse( Tokens::looks_valid( 'lw16mcp_zu-kurz' ) );

		$list = array(
			'x' => array( 'id' => 'x', 'hash' => Tokens::hash( $a ), 'user_id' => 1 ),
			'y' => array( 'id' => 'y', 'hash' => Tokens::hash( $b ), 'user_id' => 2, 'revoked' => true ),
		);

		$this->assertSame( 'x', Tokens::match( $list, $a )['id'] );
		$this->assertNull( Tokens::match( $list, $b ), 'gesperrt' );
		$this->assertNull( Tokens::match( $list, Tokens::generate() ) );
		$this->assertNull( Tokens::match( $list, '' ) );
		$this->assertNull( Tokens::match( $list, Tokens::hash( $a ) ), 'der gespeicherte Wert selbst öffnet nichts' );
	}

	public function test_schluessel_aus_kopfzeile_oder_adresse() {
		$this->assertSame( 'abc', Tokens::extract( 'Bearer abc' ) );
		$this->assertSame( 'abc', Tokens::extract( 'bearer  abc ' ) );
		$this->assertSame( 'def', Tokens::extract( 'Basic Zm9vOmJhcg==', 'def' ) );
		$this->assertSame( 'ghi', Tokens::extract( '', '', 'ghi' ) );
		$this->assertSame( '', Tokens::extract( '', '', '' ) );
	}

	public function test_gesperrte_routen_beim_schreiben() {
		$this->assertTrue( Tools::write_blocked( '/lw16/v1/erp/users/3/permissions' ) );
		$this->assertTrue( Tools::write_blocked( '/wp/v2/users/1' ) );
		$this->assertTrue( Tools::write_blocked( '/wp/v2/plugins/foo' ) );
		$this->assertTrue( Tools::write_blocked( '/WC/V3/settings/general?x=1' ) );
		$this->assertTrue( Tools::write_blocked( '/lw16/v1/mcp' ) );
		$this->assertFalse( Tools::write_blocked( '/lw16/v1/erp/auftraege/12/bestaetigen' ) );
		$this->assertFalse( Tools::write_blocked( '/wc/v3/orders/12' ) );
	}

	public function test_pfade_werden_vereinheitlicht() {
		$this->assertSame( '/lw16/v1/erp/dashboard', Tools::normalize_path( 'lw16/v1/erp/dashboard' ) );
		$this->assertSame( '/wc/v3/orders?status=processing', Tools::normalize_path( 'https://lindenweg16.de/wp-json/wc/v3/orders?status=processing' ) );
		$this->assertSame( '/wc/v3/orders', Tools::normalize_path( '/wp-json/wc/v3/orders' ) );
		$this->assertSame( '/wc/v3/orders?page=2', Tools::normalize_path( '/?rest_route=/wc/v3/orders&page=2' ) );
		$this->assertSame( '/', Tools::normalize_path( '/lw16/v1/../../etc' ) );
	}

	public function test_vergleichszeitraeume() {
		$p = Tools::periods( '2026-11-02' );
		$this->assertSame( array( '2026-11-01', '2026-11-01' ), $p['gestern'] );
		$this->assertSame( array( '2026-10-26', '2026-11-01' ), $p['letzte_7_tage'] );
		$this->assertSame( array( '2026-10-19', '2026-10-25' ), $p['vorwoche'] );
		$this->assertSame( array( '2026-11-01', '2026-11-02' ), $p['monat_bisher'] );
		$this->assertSame( '2027-02-28', Tools::minus_year( '2028-02-29' ) );
		$this->assertSame( '2025-11-02', Tools::minus_year( '2026-11-02' ) );
	}

	public function test_tagesauftrag() {
		$this->assertStringContainsString( 'tagesueberblick', Tools::daily_prompt() );
		$this->assertStringContainsString( 'Liquidität', Tools::daily_prompt( 'Liquidität' ) );
	}
}
