<?php
/**
 * MCP-Protokoll: Begrüßung, Werkzeuge, Fehler, Stapel, Benachrichtigungen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Mcp\Protocol;
use Lindenweg16\Mcp\ToolError;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/Mcp/Protocol.php';
require_once dirname( __DIR__ ) . '/includes/Mcp/ToolError.php';

/**
 * Tests für Mcp\Protocol.
 */
class McpProtocolTest extends TestCase {

	protected function protocol() {
		return new Protocol(
			array(
				'echo'  => array(
					'def' => array( 'description' => 'gibt zurück', 'inputSchema' => array( 'type' => 'object', 'properties' => new \stdClass() ) ),
					'run' => static function ( array $args ) {
						return array( 'du_sagtest' => isset( $args['text'] ) ? $args['text'] : '' );
					},
				),
				'kaputt' => array(
					'def' => array( 'description' => 'wirft', 'inputSchema' => array( 'type' => 'object' ) ),
					'run' => static function () {
						throw new ToolError( 'Falsche Eingabe.' );
					},
				),
			),
			array(
				'bericht' => array(
					'def' => array( 'description' => 'Vorlage' ),
					'get' => static function ( array $args ) {
						return array( 'messages' => array( array( 'role' => 'user', 'content' => array( 'type' => 'text', 'text' => 'Hallo ' . ( isset( $args['x'] ) ? $args['x'] : '' ) ) ) ) );
					},
				),
			),
			array( 'name' => 'test', 'version' => '1' ),
			'Hinweis'
		);
	}

	protected function call( $method, $params = array(), $id = 1 ) {
		return $this->protocol()->handle( array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ) );
	}

	public function test_initialize_handelt_version_aus() {
		$r = $this->call( 'initialize', array( 'protocolVersion' => '2025-06-18', 'capabilities' => array(), 'clientInfo' => array( 'name' => 'claude' ) ) );
		$this->assertSame( '2025-06-18', $r['result']['protocolVersion'] );
		$this->assertSame( 'test', $r['result']['serverInfo']['name'] );
		$this->assertSame( 'Hinweis', $r['result']['instructions'] );
		$this->assertArrayHasKey( 'tools', $r['result']['capabilities'] );

		$r = $this->call( 'initialize', array( 'protocolVersion' => '1999-01-01' ) );
		$this->assertSame( Protocol::VERSIONS[0], $r['result']['protocolVersion'] );
	}

	public function test_tools_list_und_call() {
		$r = $this->call( 'tools/list' );
		$this->assertSame( array( 'echo', 'kaputt' ), array_column( $r['result']['tools'], 'name' ) );

		$r = $this->call( 'tools/call', array( 'name' => 'echo', 'arguments' => array( 'text' => 'Grüß Gott' ) ) );
		$this->assertArrayNotHasKey( 'isError', $r['result'] );
		$this->assertSame( 'text', $r['result']['content'][0]['type'] );
		$this->assertSame( array( 'du_sagtest' => 'Grüß Gott' ), json_decode( $r['result']['content'][0]['text'], true ) );
	}

	public function test_werkzeugfehler_geht_als_ergebnis_zurueck() {
		$r = $this->call( 'tools/call', array( 'name' => 'kaputt' ) );
		$this->assertTrue( $r['result']['isError'] );
		$this->assertSame( 'Falsche Eingabe.', $r['result']['content'][0]['text'] );
	}

	public function test_unbekanntes_werkzeug_und_methode() {
		$r = $this->call( 'tools/call', array( 'name' => 'gibtsnicht' ) );
		$this->assertSame( Protocol::INVALID_PARAMS, $r['error']['code'] );

		$r = $this->call( 'foo/bar' );
		$this->assertSame( Protocol::METHOD_NOT_FOUND, $r['error']['code'] );
		$this->assertSame( 1, $r['id'] );
	}

	public function test_ping_liefert_leeres_objekt() {
		$r = $this->call( 'ping', array(), 'abc' );
		$this->assertSame( 'abc', $r['id'] );
		$this->assertSame( '{}', json_encode( $r['result'] ) );
	}

	public function test_benachrichtigung_ohne_antwort() {
		$this->assertNull( $this->protocol()->handle( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ) );
		$this->assertNull( $this->protocol()->handle_raw( '{"jsonrpc":"2.0","method":"notifications/initialized"}' ) );
	}

	public function test_stapel() {
		$r = $this->protocol()->handle(
			array(
				array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ),
				array( 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping' ),
				array( 'jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/list' ),
			)
		);
		$this->assertCount( 2, $r );
		$this->assertSame( array( 7, 8 ), array_column( $r, 'id' ) );

		$this->assertNull( $this->protocol()->handle( array( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ) ) );
	}

	public function test_kaputtes_json_und_ungueltige_anfrage() {
		$r = $this->protocol()->handle_raw( '{nicht json' );
		$this->assertSame( Protocol::PARSE_ERROR, $r['error']['code'] );
		$this->assertNull( $r['id'] );

		$r = $this->protocol()->handle( array( 'id' => 3, 'method' => 'ping' ) );
		$this->assertSame( Protocol::INVALID_REQUEST, $r['error']['code'] );
		$this->assertSame( 3, $r['id'] );

		$r = $this->protocol()->handle( array() );
		$this->assertSame( Protocol::INVALID_REQUEST, $r['error']['code'] );
	}

	public function test_antworten_des_clients_werden_geschluckt() {
		$this->assertNull( $this->protocol()->handle( array( 'jsonrpc' => '2.0', 'id' => 4, 'result' => array() ) ) );
	}

	public function test_prompts() {
		$r = $this->call( 'prompts/list' );
		$this->assertSame( 'bericht', $r['result']['prompts'][0]['name'] );

		$r = $this->call( 'prompts/get', array( 'name' => 'bericht', 'arguments' => array( 'x' => 'Welt' ) ) );
		$this->assertSame( 'Hallo Welt', $r['result']['messages'][0]['content']['text'] );
	}

	public function test_lange_ergebnisse_werden_gekuerzt() {
		$text = str_repeat( 'ä', 100 );
		$cut  = Protocol::clip( $text, 51 );
		$this->assertStringContainsString( 'gekürzt', $cut );
		$this->assertTrue( (bool) preg_match( '//u', $cut ), 'gültiges UTF-8' );
		$this->assertSame( $text, Protocol::clip( $text, 1000 ) );
	}
}
