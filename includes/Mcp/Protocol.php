<?php
/**
 * MCP-Protokoll (Model Context Protocol) als reine Regel.
 *
 * Nimmt eine JSON-RPC-2.0-Nachricht (oder einen Stapel davon) entgegen und
 * gibt die Antwort zurück. Kennt keine WordPress-Funktionen: Werkzeuge und
 * Vorlagen werden als Liste mit Rückruf übergeben. So bleibt die
 * Protokolllogik ohne WordPress testbar (tests/McpProtocolTest.php).
 *
 * Transport ist „Streamable HTTP“ ohne Ereignisstrom: jede Anfrage ist ein
 * POST, die Antwort kommt direkt als JSON. Der Server ist zustandslos – es
 * gibt keine Sitzungskennung, jede Anfrage trägt ihren Schlüssel selbst.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * JSON-RPC-Verteiler.
 */
class Protocol {

	const PARSE_ERROR      = -32700;
	const INVALID_REQUEST  = -32600;
	const METHOD_NOT_FOUND = -32601;
	const INVALID_PARAMS   = -32602;
	const INTERNAL_ERROR   = -32603;

	/**
	 * Unterstützte Protokollversionen, neueste zuerst.
	 */
	const VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	/**
	 * Werkzeuge: Name => array{def: array, run: callable(array): mixed}.
	 *
	 * @var array
	 */
	protected $tools;

	/**
	 * Vorlagen: Name => array{def: array, get: callable(array): array}.
	 *
	 * @var array
	 */
	protected $prompts;

	/**
	 * Angaben zum Server (name, title, version).
	 *
	 * @var array
	 */
	protected $info;

	/**
	 * Hinweise an das Modell.
	 *
	 * @var string
	 */
	protected $instructions;

	/**
	 * Konstruktor.
	 *
	 * @param array  $tools        Werkzeuge.
	 * @param array  $prompts      Vorlagen.
	 * @param array  $info         Serverangaben.
	 * @param string $instructions Hinweise an das Modell.
	 */
	public function __construct( array $tools, array $prompts, array $info, $instructions = '' ) {
		$this->tools        = $tools;
		$this->prompts      = $prompts;
		$this->info         = $info;
		$this->instructions = (string) $instructions;
	}

	/**
	 * Rohen Anfragetext verarbeiten.
	 *
	 * @param string $body JSON.
	 * @return array|null Antwort; null = nur Benachrichtigungen (HTTP 202 ohne Inhalt).
	 */
	public function handle_raw( $body ) {
		$payload = json_decode( (string) $body, true );

		if ( null === $payload && 'null' !== trim( (string) $body ) ) {
			return self::error( null, self::PARSE_ERROR, 'Parse error' );
		}

		return $this->handle( $payload );
	}

	/**
	 * Entschlüsselte Nachricht oder Stapel verarbeiten.
	 *
	 * @param mixed $payload Nachricht.
	 * @return array|null
	 */
	public function handle( $payload ) {
		if ( is_array( $payload ) && self::is_list( $payload ) ) {
			if ( ! $payload ) {
				return self::error( null, self::INVALID_REQUEST, 'Invalid Request' );
			}

			$out = array();
			foreach ( $payload as $message ) {
				$answer = $this->message( $message );
				if ( null !== $answer ) {
					$out[] = $answer;
				}
			}

			return $out ? $out : null;
		}

		return $this->message( $payload );
	}

	/**
	 * Eine Nachricht.
	 *
	 * @param mixed $m Nachricht.
	 * @return array|null
	 */
	protected function message( $m ) {
		if ( ! is_array( $m ) || ! isset( $m['jsonrpc'] ) || '2.0' !== $m['jsonrpc'] || ! isset( $m['method'] ) || ! is_string( $m['method'] ) ) {
			// Antworten des Clients (ohne method) brauchen wir nicht – still schlucken.
			if ( is_array( $m ) && isset( $m['jsonrpc'] ) && ( array_key_exists( 'result', $m ) || array_key_exists( 'error', $m ) ) ) {
				return null;
			}

			return self::error( is_array( $m ) && isset( $m['id'] ) ? $m['id'] : null, self::INVALID_REQUEST, 'Invalid Request' );
		}

		$is_notification = ! array_key_exists( 'id', $m );
		$id              = $is_notification ? null : $m['id'];
		$params          = isset( $m['params'] ) && is_array( $m['params'] ) ? $m['params'] : array();

		if ( $is_notification ) {
			// notifications/initialized, notifications/cancelled … – nichts zu tun.
			return null;
		}

		try {
			switch ( $m['method'] ) {
				case 'initialize':
					return self::result( $id, $this->initialize( $params ) );

				case 'ping':
					return self::result( $id, new \stdClass() );

				case 'tools/list':
					return self::result( $id, array( 'tools' => $this->tool_defs() ) );

				case 'tools/call':
					return $this->call_tool( $id, $params );

				case 'prompts/list':
					return self::result( $id, array( 'prompts' => $this->prompt_defs() ) );

				case 'prompts/get':
					return $this->get_prompt( $id, $params );

				case 'resources/list':
					return self::result( $id, array( 'resources' => array() ) );

				case 'resources/templates/list':
					return self::result( $id, array( 'resourceTemplates' => array() ) );

				case 'logging/setLevel':
					return self::result( $id, new \stdClass() );

				default:
					return self::error( $id, self::METHOD_NOT_FOUND, 'Method not found: ' . $m['method'] );
			}
		} catch ( \Throwable $e ) {
			return self::error( $id, self::INTERNAL_ERROR, 'Internal error' );
		}
	}

	/**
	 * Begrüßung: Version aushandeln.
	 *
	 * @param array $params Parameter.
	 * @return array
	 */
	protected function initialize( array $params ) {
		return array(
			'protocolVersion' => self::negotiate( isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '' ),
			'capabilities'    => array(
				'tools'   => array( 'listChanged' => false ),
				'prompts' => array( 'listChanged' => false ),
			),
			'serverInfo'      => $this->info,
			'instructions'    => $this->instructions,
		);
	}

	/**
	 * Version wählen: die gewünschte, wenn wir sie kennen, sonst die neueste.
	 *
	 * @param string $wanted Gewünschte Version.
	 * @return string
	 */
	public static function negotiate( $wanted ) {
		return in_array( $wanted, self::VERSIONS, true ) ? $wanted : self::VERSIONS[0];
	}

	/**
	 * Werkzeugbeschreibungen.
	 *
	 * @return array
	 */
	protected function tool_defs() {
		$out = array();
		foreach ( $this->tools as $name => $tool ) {
			$out[] = array_merge( array( 'name' => $name ), $tool['def'] );
		}
		return $out;
	}

	/**
	 * Werkzeug aufrufen.
	 *
	 * Fachliche Fehler (falsche Eingabe, kein Recht) gehen als Ergebnis mit
	 * isError zurück, damit das Modell sie lesen und sich korrigieren kann.
	 *
	 * @param mixed $id     Anfrage-ID.
	 * @param array $params Parameter.
	 * @return array
	 */
	protected function call_tool( $id, array $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';

		if ( ! isset( $this->tools[ $name ] ) ) {
			return self::error( $id, self::INVALID_PARAMS, 'Unknown tool: ' . $name );
		}

		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		try {
			$data = call_user_func( $this->tools[ $name ]['run'], $args );
		} catch ( ToolError $e ) {
			return self::result( $id, self::tool_text( $e->getMessage(), true ) );
		} catch ( \Throwable $e ) {
			return self::result( $id, self::tool_text( 'Fehler im Werkzeug: ' . $e->getMessage(), true ) );
		}

		return self::result( $id, self::tool_result( $data ) );
	}

	/**
	 * Ergebnis eines Werkzeugs in MCP-Form.
	 *
	 * @param mixed $data Daten.
	 * @return array
	 */
	public static function tool_result( $data ) {
		if ( is_string( $data ) ) {
			return self::tool_text( $data, false );
		}

		$json = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );

		return self::tool_text( false === $json ? '' : self::clip( $json ), false );
	}

	/**
	 * Textergebnis.
	 *
	 * @param string $text     Text.
	 * @param bool   $is_error Fehler?
	 * @return array
	 */
	public static function tool_text( $text, $is_error ) {
		$out = array( 'content' => array( array( 'type' => 'text', 'text' => (string) $text ) ) );
		if ( $is_error ) {
			$out['isError'] = true;
		}
		return $out;
	}

	/**
	 * Sehr lange Ergebnisse kürzen, damit der Kontext des Modells nicht überläuft.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Zeichen.
	 * @return string
	 */
	public static function clip( $text, $limit = 120000 ) {
		if ( strlen( $text ) <= $limit ) {
			return $text;
		}

		$cut = function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit );

		return $cut . "\n… [gekürzt: " . ( strlen( $text ) - strlen( $cut ) ) . ' Zeichen weggelassen – Abfrage enger fassen, z. B. mit limit/per_page oder Filtern]';
	}

	/**
	 * Vorlagenbeschreibungen.
	 *
	 * @return array
	 */
	protected function prompt_defs() {
		$out = array();
		foreach ( $this->prompts as $name => $prompt ) {
			$out[] = array_merge( array( 'name' => $name ), $prompt['def'] );
		}
		return $out;
	}

	/**
	 * Vorlage holen.
	 *
	 * @param mixed $id     Anfrage-ID.
	 * @param array $params Parameter.
	 * @return array
	 */
	protected function get_prompt( $id, array $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';

		if ( ! isset( $this->prompts[ $name ] ) ) {
			return self::error( $id, self::INVALID_PARAMS, 'Unknown prompt: ' . $name );
		}

		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		return self::result( $id, call_user_func( $this->prompts[ $name ]['get'], $args ) );
	}

	/**
	 * Erfolgsantwort.
	 *
	 * @param mixed $id     ID.
	 * @param mixed $result Ergebnis.
	 * @return array
	 */
	public static function result( $id, $result ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
	}

	/**
	 * Fehlerantwort.
	 *
	 * @param mixed  $id      ID.
	 * @param int    $code    Code.
	 * @param string $message Meldung.
	 * @return array
	 */
	public static function error( $id, $code, $message ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => (int) $code, 'message' => (string) $message ) );
	}

	/**
	 * Ist das Feld eine Liste (0, 1, 2 …)?
	 *
	 * @param array $a Feld.
	 * @return bool
	 */
	protected static function is_list( array $a ) {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}
}
