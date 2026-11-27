<?php
/**
 * Geheimnisse aus Antworten des Claude-Zugangs entfernen.
 *
 * SqlGuard sperrt Abfragen, die `user_pass` oder `session_tokens` beim
 * Namen nennen. Ein `SELECT * FROM wp_users` nennt sie nicht und lieferte
 * trotzdem die Passwort-Hashes; `WHERE meta_key LIKE 'sess%'` die
 * Sitzungs-Token, `wp_options` die DHL-Zugangsdaten im Klartext. Darum wird
 * hier die Antwort geprüft, nicht die Frage: was nach einem Geheimnis
 * heißt oder aussieht, kommt als „[gesperrt]“ zurück – gleich, auf welchem
 * Weg es abgefragt wurde.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Maskiert Geheimnisse in Datenbankzeilen und REST-Antworten.
 */
class Redactor {

	/**
	 * Ersatztext.
	 */
	const MASK = '[gesperrt]';

	/**
	 * Namen von Feldern, Spalten, Optionen und Metadaten mit Geheimnissen.
	 */
	const SECRET_NAME = '/(pass(word|wort)?\b|passwd|_pass$|^pass_|pwd|secret|token|api[_-]?key|apikey|private[_-]?key|access[_-]?key|consumer[_-]?key|activation[_-]?key|auth[_-]?key|signing[_-]?key|credential|application_passwords)/i';

	/**
	 * Spalten, die den Namen der Zeile tragen (Optionen, Metadaten, Einstellungen).
	 */
	const NAME_COLUMNS = array( 'option_name', 'meta_key', 'setting_key', 'name', 'key' );

	/**
	 * Spalten mit dem Wert dazu.
	 */
	const VALUE_COLUMNS = array( 'option_value', 'meta_value', 'value', 'value_decimal', 'wert' );

	/**
	 * Heißt dieses Feld nach einem Geheimnis?
	 *
	 * @param string $name Feld-, Spalten- oder Optionsname.
	 * @return bool
	 */
	public static function is_secret_name( $name ) {
		return '' !== (string) $name && (bool) preg_match( self::SECRET_NAME, (string) $name );
	}

	/**
	 * Sieht der Wert nach einem Passwort-Hash aus?
	 *
	 * @param string $value Wert.
	 * @return bool
	 */
	public static function looks_like_hash( $value ) {
		return (bool) preg_match( '/^\$(P|H|wp\$2y|2[aby]|argon2id?)\$/', (string) $value );
	}

	/**
	 * Datenbankzeilen bereinigen.
	 *
	 * @param array $rows Zeilen (assoziative Arrays).
	 * @return array
	 */
	public static function rows( array $rows ) {
		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$named_secret = false;
			foreach ( self::NAME_COLUMNS as $col ) {
				if ( isset( $row[ $col ] ) && is_string( $row[ $col ] ) && self::is_secret_name( $row[ $col ] ) ) {
					$named_secret = true;
				}
			}

			foreach ( $row as $col => $value ) {
				if ( $named_secret && in_array( strtolower( (string) $col ), self::VALUE_COLUMNS, true ) ) {
					$row[ $col ] = self::mask( $value );
				}
			}

			$rows[ $i ] = self::scrub( $row );
		}

		return $rows;
	}

	/**
	 * Beliebige Daten rekursiv bereinigen.
	 *
	 * @param mixed $data Daten.
	 * @return mixed
	 */
	public static function scrub( $data ) {
		if ( is_object( $data ) ) {
			$data = get_object_vars( $data );
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = is_string( $key ) && self::is_secret_name( $key ) ? self::mask( $value ) : self::scrub( $value );
			}

			return $data;
		}

		if ( ! is_string( $data ) ) {
			return $data;
		}

		if ( self::looks_like_hash( $data ) ) {
			return self::MASK;
		}

		// Serialisierte Optionen (z. B. lw16_settings) auspacken und einzeln
		// prüfen. Objekte bleiben gesperrt: allowed_classes = false.
		if ( preg_match( '/^(a|O):\d+:/', $data ) ) {
			$decoded = @unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
			// Objekte kommen als __PHP_Incomplete_Class zurück; ihre Felder
			// werden genauso geprüft.
			if ( is_object( $decoded ) ) {
				$decoded = (array) $decoded;
			}
			if ( false !== $decoded && is_array( $decoded ) ) {
				$clean = self::scrub( $decoded );

				return $clean === $decoded ? $data : $clean;
			}
		}

		// JSON ebenso.
		if ( '' !== $data && ( '{' === $data[0] || '[' === $data[0] ) ) {
			$decoded = json_decode( $data, true );
			if ( is_array( $decoded ) ) {
				$clean = self::scrub( $decoded );

				return $clean === $decoded ? $data : $clean;
			}
		}

		return $data;
	}

	/**
	 * Wert maskieren; leere Werte bleiben leer (sie verraten nichts und
	 * zeigen, dass noch nichts eingetragen ist).
	 *
	 * @param mixed $value Wert.
	 * @return mixed
	 */
	protected static function mask( $value ) {
		if ( null === $value || '' === $value || array() === $value || false === $value ) {
			return $value;
		}

		return self::MASK;
	}
}
