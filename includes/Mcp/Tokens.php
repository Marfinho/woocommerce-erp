<?php
/**
 * Zugangsschlüssel für den MCP-Zugang.
 *
 * Ein Schlüssel gehört zu einem WordPress-Konto: Claude arbeitet mit genau
 * dessen Rechten. Gespeichert wird nur der SHA-256-Wert – den Schlüssel
 * selbst sieht man einmal beim Anlegen, danach nie wieder.
 *
 * Je Schlüssel: Bezeichnung, Konto, „darf schreiben“ ja/nein, angelegt,
 * zuletzt benutzt. Die Regeln (Erzeugen, Erkennen, Prüfen) sind ohne
 * WordPress testbar (tests/McpTokensTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Schlüsselverwaltung.
 */
class Tokens {

	const OPTION = 'lw16_mcp_tokens';
	const PREFIX = 'lw16mcp_';

	/**
	 * Neuen Schlüssel erzeugen (Klartext).
	 *
	 * @return string
	 */
	public static function generate() {
		return self::PREFIX . bin2hex( random_bytes( 24 ) );
	}

	/**
	 * Hat der Text die Form eines Schlüssels?
	 *
	 * @param string $token Text.
	 * @return bool
	 */
	public static function looks_valid( $token ) {
		return (bool) preg_match( '/^' . self::PREFIX . '[a-f0-9]{48}$/', (string) $token );
	}

	/**
	 * Gespeicherter Wert.
	 *
	 * @param string $token Klartext.
	 * @return string
	 */
	public static function hash( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Schlüssel in der Liste finden.
	 *
	 * @param array  $list  Gespeicherte Einträge.
	 * @param string $token Klartext.
	 * @return array|null Eintrag.
	 */
	public static function match( array $list, $token ) {
		if ( ! self::looks_valid( $token ) ) {
			return null;
		}

		$hash  = self::hash( $token );
		$found = null;

		// Alle Einträge vergleichen, damit die Laufzeit nichts verrät.
		foreach ( $list as $entry ) {
			if ( isset( $entry['hash'] ) && hash_equals( (string) $entry['hash'], $hash ) && empty( $entry['revoked'] ) ) {
				$found = $entry;
			}
		}

		return $found;
	}

	/**
	 * Schlüssel aus den Anfrage-Kopfzeilen lesen.
	 *
	 * @param string $authorization Kopfzeile Authorization.
	 * @param string $header        Kopfzeile X-LW16-Key.
	 * @param string $url_key       Schlüssel aus der Adresse.
	 * @return string
	 */
	public static function extract( $authorization, $header = '', $url_key = '' ) {
		if ( preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', (string) $authorization, $m ) ) {
			return $m[1];
		}

		if ( '' !== trim( (string) $header ) ) {
			return trim( (string) $header );
		}

		return trim( (string) $url_key );
	}

	/**
	 * Kurzkennung zum Anzeigen (erste Zeichen nach dem Präfix).
	 *
	 * @param string $token Klartext.
	 * @return string
	 */
	public static function hint( $token ) {
		return substr( (string) $token, strlen( self::PREFIX ), 6 ) . '…';
	}

	/**
	 * Alle Einträge.
	 *
	 * @return array
	 */
	public static function all() {
		$list = get_option( self::OPTION, array() );

		return is_array( $list ) ? $list : array();
	}

	/**
	 * Schlüssel anlegen.
	 *
	 * @param string $label   Bezeichnung.
	 * @param int    $user_id Konto.
	 * @param bool   $write   Darf schreiben?
	 * @return string Klartext – nur jetzt sichtbar.
	 */
	public static function create( $label, $user_id, $write ) {
		$token = self::generate();
		$list  = self::all();
		$id    = substr( self::hash( $token . microtime( true ) ), 0, 12 );

		$list[ $id ] = array(
			'id'        => $id,
			'label'     => '' !== trim( (string) $label ) ? sanitize_text_field( $label ) : 'Claude',
			'hash'      => self::hash( $token ),
			'hint'      => self::hint( $token ),
			'user_id'   => (int) $user_id,
			'write'     => (bool) $write,
			'created'   => time(),
			'last_used' => 0,
		);

		update_option( self::OPTION, $list, false );

		return $token;
	}

	/**
	 * Schlüssel löschen.
	 *
	 * @param string $id Kennung.
	 */
	public static function revoke( $id ) {
		$list = self::all();
		unset( $list[ (string) $id ] );
		update_option( self::OPTION, $list, false );
	}

	/**
	 * Schlüssel prüfen.
	 *
	 * @param string $token Klartext.
	 * @return array|null Eintrag.
	 */
	public static function verify( $token ) {
		return self::match( self::all(), $token );
	}

	/**
	 * Nutzung vermerken – höchstens alle fünf Minuten ein Schreibzugriff.
	 *
	 * @param array $entry Eintrag.
	 */
	public static function touch( array $entry ) {
		$list = self::all();
		$id   = (string) $entry['id'];

		if ( ! isset( $list[ $id ] ) ) {
			return;
		}

		if ( time() - (int) $list[ $id ]['last_used'] < 300 ) {
			return;
		}

		$list[ $id ]['last_used'] = time();
		update_option( self::OPTION, $list, false );
	}
}
