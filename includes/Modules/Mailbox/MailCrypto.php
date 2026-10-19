<?php
/**
 * Verschlüsselung der Postfach-Passwörter.
 *
 * Die Zugangsdaten der Postfächer liegen in der Datenbank – ein Dump oder
 * eine SQL-Injection soll daraus keine Mail-Zugänge machen. Deshalb werden
 * sie mit libsodium (XSalsa20-Poly1305) verschlüsselt. Der Schlüssel liegt
 * nicht in der Datenbank: Er stammt aus `LW16_MAIL_KEY` in der
 * `wp-config.php` oder, falls die Konstante fehlt, aus den WordPress-Salts.
 *
 * Ändern sich die Salts, sind die gespeicherten Passwörter unlesbar – die
 * Postfächer melden das und verlangen eine neue Eingabe.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

/**
 * Passwörter ver- und entschlüsseln.
 */
class MailCrypto {

	const PREFIX = 'v1:';

	/**
	 * Der Schlüssel (32 Byte).
	 *
	 * @return string
	 */
	protected static function key() {
		if ( defined( 'LW16_MAIL_KEY' ) && '' !== (string) LW16_MAIL_KEY ) {
			$secret = (string) LW16_MAIL_KEY;
		} elseif ( function_exists( 'wp_salt' ) ) {
			$secret = wp_salt( 'secure_auth' ) . wp_salt( 'auth' );
		} else {
			$secret = '';
		}

		return hash( 'sha256', 'lw16-mail|' . $secret, true );
	}

	/**
	 * Ob Verschlüsselung möglich ist.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'sodium_crypto_secretbox' );
	}

	/**
	 * Verschlüsseln.
	 *
	 * @param string $plain Klartext.
	 * @return string Leer bei leerer Eingabe oder wenn libsodium fehlt.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;

		if ( '' === $plain || ! self::available() ) {
			return '';
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );

		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Entschlüsseln.
	 *
	 * @param string $stored Gespeicherter Wert.
	 * @return string|null Klartext, null wenn nicht lesbar.
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;

		if ( 0 !== strpos( $stored, self::PREFIX ) || ! self::available() ) {
			return null;
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );

		return false === $plain ? null : $plain;
	}
}
