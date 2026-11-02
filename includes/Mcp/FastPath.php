<?php
/**
 * Schnellweg für den Verbindungsaufbau des MCP-Zugangs.
 *
 * Beim Verbinden schickt Claude nacheinander „initialize“, eine
 * Benachrichtigung und „tools/list“ – mit 30 Sekunden Zeit für alles. Über
 * den normalen REST-Weg lädt jede dieser Anfragen erst WooCommerce, das
 * ganze ERP und das Theme. Auf einem ausgelasteten Webspace reichte das
 * nicht (Briefing am 02.11.2026 im Timeout).
 *
 * Diese Anfragen brauchen keine Shop-Daten. Sie werden deshalb schon beim
 * Laden der Plugin-Datei beantwortet, vor WooCommerce und vor dem Plugin-
 * Start. Alles andere – Werkzeugaufrufe, falscher Schlüssel, unbekannte
 * Adresse – läuft unverändert über Server (dort auch 401 und Drosselung).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Mcp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Throttle;

/**
 * Früh beantworten, was ohne Shop geht.
 */
class FastPath {

	/**
	 * Methoden, die ohne Shop-Daten auskommen.
	 */
	const CHEAP = array( 'initialize', 'ping', 'tools/list', 'prompts/list', 'prompts/get', 'resources/list', 'resources/templates/list', 'logging/setLevel' );

	/**
	 * Besteht die Nachricht (oder der Stapel) nur aus solchen Methoden? Reine Regel.
	 *
	 * @param mixed $payload Entschlüsselte Nachricht.
	 * @return bool
	 */
	public static function cheap( $payload ) {
		if ( ! is_array( $payload ) || ! $payload ) {
			return false;
		}

		$list = array_keys( $payload ) === range( 0, count( $payload ) - 1 ) ? $payload : array( $payload );

		foreach ( $list as $m ) {
			if ( ! is_array( $m ) ) {
				return false;
			}
			if ( ! isset( $m['method'] ) ) {
				// Antwort des Clients – braucht nichts.
				if ( array_key_exists( 'result', $m ) || array_key_exists( 'error', $m ) ) {
					continue;
				}
				return false;
			}
			$method = (string) $m['method'];
			if ( 0 !== strpos( $method, 'notifications/' ) && ! in_array( $method, self::CHEAP, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Ist das die Adresse des MCP-Zugangs? Reine Regel.
	 *
	 * @param string $uri        REQUEST_URI.
	 * @param string $rest_route Parameter rest_route (Permalinks „Einfach“).
	 * @param string $prefix     REST-Präfix (meist wp-json).
	 * @return string|null Schlüssel aus der Adresse ('' = keiner), null = andere Adresse.
	 */
	public static function route_key( $uri, $rest_route = '', $prefix = 'wp-json' ) {
		$re = '#^/lw16/v1/mcp(?:/(' . Tokens::PREFIX . '[a-f0-9]{48}))?/?$#';

		if ( '' !== (string) $rest_route ) {
			return preg_match( $re, (string) $rest_route, $m ) ? ( isset( $m[1] ) ? $m[1] : '' ) : null;
		}

		$path = (string) parse_url( (string) $uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$at   = strpos( $path, '/' . trim( $prefix, '/' ) . '/' );

		if ( false === $at ) {
			return null;
		}

		$rest = substr( $path, $at + strlen( '/' . trim( $prefix, '/' ) ) );

		return preg_match( $re, $rest, $m ) ? ( isset( $m[1] ) ? $m[1] : '' ) : null;
	}

	/**
	 * Wenn möglich sofort antworten und beenden. Sonst nichts tun.
	 */
	public static function maybe_serve() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		$route  = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : '';
		$key    = self::route_key( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', $route, $prefix );

		if ( null === $key ) {
			return;
		}

		try {
			$body    = (string) file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$payload = json_decode( $body, true );

			if ( ! self::cheap( $payload ) ) {
				return;
			}

			$auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '' );
			$hdr  = isset( $_SERVER['HTTP_X_LW16_KEY'] ) ? (string) $_SERVER['HTTP_X_LW16_KEY'] : '';
			$entry = Tokens::verify( Tokens::extract( wp_unslash( $auth ), wp_unslash( $hdr ), $key ) );

			// Falscher Schlüssel oder gedrosselt: der normale Weg antwortet (401/429).
			if ( ! $entry || ! Throttle::hit( 'mcp_' . $entry['id'], 240 ) ) {
				return;
			}

			$answer = Server::protocol( $entry )->handle( $payload );
			Tokens::touch( $entry );
		} catch ( \Throwable $e ) {
			return;
		}
		// phpcs:enable

		if ( null === $answer ) {
			status_header( 202 );
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Cache-Control: no-store' );
		echo wp_json_encode( $answer ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
