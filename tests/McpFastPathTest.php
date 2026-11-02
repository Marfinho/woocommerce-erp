<?php
/**
 * Schnellweg für den Verbindungsaufbau des MCP-Zugangs.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Mcp\FastPath;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/Mcp/Tokens.php';
require_once dirname( __DIR__ ) . '/includes/Mcp/FastPath.php';

/**
 * Tests für Mcp\FastPath.
 */
class McpFastPathTest extends TestCase {

	protected function msg( $method, $id = 1 ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method );
	}

	public function test_verbindungsaufbau_geht_den_schnellweg() {
		$this->assertTrue( FastPath::cheap( $this->msg( 'initialize' ) ) );
		$this->assertTrue( FastPath::cheap( $this->msg( 'tools/list' ) ) );
		$this->assertTrue( FastPath::cheap( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ) );
		$this->assertTrue( FastPath::cheap( array( $this->msg( 'ping', 1 ), $this->msg( 'prompts/list', 2 ) ) ) );
		$this->assertTrue( FastPath::cheap( array( 'jsonrpc' => '2.0', 'id' => 5, 'result' => array() ) ) );
	}

	public function test_werkzeugaufrufe_und_unsinn_gehen_den_normalen_weg() {
		$this->assertFalse( FastPath::cheap( $this->msg( 'tools/call' ) ) );
		$this->assertFalse( FastPath::cheap( array( $this->msg( 'ping', 1 ), $this->msg( 'tools/call', 2 ) ) ) );
		$this->assertFalse( FastPath::cheap( null ) );
		$this->assertFalse( FastPath::cheap( array() ) );
		$this->assertFalse( FastPath::cheap( 'initialize' ) );
		$this->assertFalse( FastPath::cheap( array( 'id' => 1 ) ) );
		$this->assertFalse( FastPath::cheap( $this->msg( 'foo/bar' ) ) );
	}

	public function test_adresse_erkennen() {
		$key = 'lw16mcp_' . str_repeat( 'a1', 24 );

		$this->assertSame( '', FastPath::route_key( '/wp-json/lw16/v1/mcp' ) );
		$this->assertSame( '', FastPath::route_key( '/wp-json/lw16/v1/mcp/' ) );
		$this->assertSame( $key, FastPath::route_key( '/wp-json/lw16/v1/mcp/' . $key ) );
		$this->assertSame( $key, FastPath::route_key( '/shop/wp-json/lw16/v1/mcp/' . $key . '?x=1' ), 'WordPress im Unterordner' );
		$this->assertSame( '', FastPath::route_key( '/index.php', '/lw16/v1/mcp' ), 'Permalinks „Einfach“' );
		$this->assertSame( '', FastPath::route_key( '/api/lw16/v1/mcp', '', 'api' ), 'eigenes REST-Präfix' );

		$this->assertNull( FastPath::route_key( '/wp-json/lw16/v1/erp/dashboard' ) );
		$this->assertNull( FastPath::route_key( '/wp-json/lw16/v1/mcpx' ) );
		$this->assertNull( FastPath::route_key( '/wp-json/lw16/v1/mcp/falsch' ) );
		$this->assertNull( FastPath::route_key( '/lw16/v1/mcp' ), 'ohne REST-Präfix' );
		$this->assertNull( FastPath::route_key( '/' ) );
	}
}
