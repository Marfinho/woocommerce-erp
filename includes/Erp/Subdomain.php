<?php
/**
 * Betrieb der ERP-Oberfläche auf einer eigenen Subdomain.
 *
 * Einschalten: in wp-config.php
 *
 *     define( 'LW16_ERP_HOST', 'erp.lindenweg16.de' );
 *
 * und die Subdomain im Hosting auf dieselbe WordPress-Installation zeigen
 * lassen. Läuft eine Anfrage auf dieser Adresse, tut WordPress für ihre
 * Dauer so, als wäre sie die Website-Adresse: Anmeldeseite, wp-admin,
 * Schnittstelle und Plugin-Dateien liegen dann auf derselben Herkunft wie
 * die Oberfläche. Das ist nötig, weil die eingebetteten wp-admin-Seiten sonst
 * aus fremder Herkunft kämen und vom Browser blockiert würden.
 *
 * Die Anmeldung gilt dort für die Subdomain; wer sich einmal für beide
 * Adressen anmelden will, setzt zusätzlich COOKIE_DOMAIN auf
 * `.lindenweg16.de`. Der Shop auf der Hauptadresse bleibt unberührt.
 *
 * Geprüft wird die Anfragekennung exakt gegen die Konstante – kein
 * Joker, kein Teilvergleich.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Subdomain-Unterstützung.
 */
class Subdomain {

	/**
	 * Die eingestellte Adresse, klein geschrieben, ohne Port.
	 *
	 * @return string Leer, wenn nicht eingestellt.
	 */
	public static function host() {
		return defined( 'LW16_ERP_HOST' ) ? self::normalize( (string) LW16_ERP_HOST ) : '';
	}

	/**
	 * Adresse vereinheitlichen.
	 *
	 * @param string $host Adresse, ggf. mit Schema, Port oder Pfad.
	 * @return string
	 */
	public static function normalize( $host ) {
		$host = strtolower( trim( (string) $host ) );
		$host = preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $host );
		$host = preg_replace( '#[/?\#].*$#', '', $host );

		return (string) preg_replace( '#:\d+$#', '', $host );
	}

	/**
	 * Läuft die Anfrage auf der ERP-Subdomain?
	 *
	 * @param string $request_host Wert des Host-Kopfes.
	 * @return bool
	 */
	public static function is_request( $request_host ) {
		$configured = self::host();

		return '' !== $configured && self::normalize( $request_host ) === $configured;
	}

	/**
	 * Adresse in einer URL austauschen, Schema und Pfad bleiben.
	 *
	 * @param string $url  URL.
	 * @param string $host Neue Adresse.
	 * @return string
	 */
	public static function swap_host( $url, $host ) {
		if ( '' === (string) $host ) {
			return $url;
		}

		return (string) preg_replace( '#^((?:https?:)?//)[^/?\#]+#i', '$1' . self::normalize( $host ), (string) $url, 1 );
	}

	/**
	 * Hooks registrieren, sofern die Anfrage auf der Subdomain läuft.
	 */
	public function register() {
		$request_host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! self::is_request( $request_host ) ) {
			return;
		}

		$host = self::host();
		$swap = static function ( $value ) use ( $host ) {
			return is_string( $value ) ? Subdomain::swap_host( $value, $host ) : $value;
		};

		// Ohne WP_HOME/WP_SITEURL greift option_*, mit den Konstanten
		// liefert WordPress den Wert schon vorab (pre_option_*).
		add_filter( 'option_home', $swap, 99 );
		add_filter( 'option_siteurl', $swap, 99 );
		add_filter(
			'pre_option_home',
			static function ( $value ) use ( $swap ) {
				return false === $value ? $value : $swap( $value );
			},
			99
		);
		add_filter(
			'pre_option_siteurl',
			static function ( $value ) use ( $swap ) {
				return false === $value ? $value : $swap( $value );
			},
			99
		);
	}
}
