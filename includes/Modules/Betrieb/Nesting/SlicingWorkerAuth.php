<?php
/**
 * Zugangsdaten des Slicing-Workers.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Anders als die Etikettendrucker oder die Bambu-Drucker gibt es hier
 * typischerweise nur EINEN Slicing-Worker (ein Rechner/Pi mit OrcaSlicer) –
 * ein eigenes Token je Gerät wie bei den Warteschlangen oben wäre
 * Verwaltungsaufwand ohne Nutzen. Deshalb ein einziges, geteiltes Token,
 * genau wie die übrigen Plugin-Einstellungen über Settings gespeichert -
 * auch hier wird nur der SHA-256-Hash abgelegt, nie das Token selbst.
 */
class SlicingWorkerAuth {

	const SETTING_HASH       = 'slicing_worker_token_hash';
	const SETTING_CREATED_AT = 'slicing_worker_token_created_at';

	/**
	 * Neues, zufälliges Bearer-Token erzeugen.
	 *
	 * @return string 64 Zeichen Hex (256 Bit Entropie).
	 */
	public static function generate_token() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Token hashen – reine Logik, ohne Datenbank.
	 *
	 * @param string $token Klartext-Token.
	 * @return string SHA-256-Hex.
	 */
	public static function hash_token( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Zeitsicherer Vergleich (siehe Bambu\BambuPrinterRepository für die
	 * ausführliche Begründung).
	 *
	 * @param string $token      Vorgelegtes Klartext-Token.
	 * @param string $token_hash Gespeicherter Hash.
	 * @return bool
	 */
	public static function token_matches( $token, $token_hash ) {
		if ( '' === (string) $token || '' === (string) $token_hash ) {
			return false;
		}
		return hash_equals( (string) $token_hash, self::hash_token( $token ) );
	}

	/**
	 * Neues Token erzeugen, speichern (nur der Hash) und im Klartext
	 * zurückgeben – wird nur hier einmalig sichtbar.
	 *
	 * @return string
	 */
	public static function regenerate() {
		$token = self::generate_token();

		Settings::update(
			array(
				self::SETTING_HASH       => self::hash_token( $token ),
				self::SETTING_CREATED_AT => current_time( 'mysql', true ),
			)
		);

		return $token;
	}

	/**
	 * Ist überhaupt ein Token vergeben?
	 *
	 * @return bool
	 */
	public static function has_token() {
		return '' !== (string) Settings::get( self::SETTING_HASH, '' );
	}

	/**
	 * Vorgelegtes Token gegen das gespeicherte prüfen.
	 *
	 * @param string $token Vorgelegtes Klartext-Token.
	 * @return bool
	 */
	public static function verify( $token ) {
		return self::token_matches( $token, (string) Settings::get( self::SETTING_HASH, '' ) );
	}
}
