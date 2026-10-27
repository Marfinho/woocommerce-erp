<?php
/**
 * Link, der auf einem Telefon den Konfigurator mit der AR-Ansicht öffnet.
 *
 * Am Rechner lässt sich nichts an die Wand stellen. Dort zeigt die AR-Ansicht
 * einen QR-Code, der die Seite mit derselben Konfiguration öffnet – am Telefon
 * startet dann die AR-Ansicht (`ar=1`). Diese Klasse baut den Link und achtet
 * darauf, dass er nur auf die eigene Seite zeigt: Sonst wäre die Adresse
 * für den QR-Code ein Weg, beliebige Ziele hinter einem Code des Shops zu
 * verstecken.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

/**
 * Baut den Link für den QR-Code.
 */
class ArLink {

	/**
	 * Parameter, der die AR-Ansicht beim Öffnen startet.
	 */
	const QUERY_VAR = 'ar';

	/**
	 * Der Link zur Konfiguration mit gestarteter AR-Ansicht.
	 *
	 * @param string $page_url Adresse der Seite mit dem Konfigurator.
	 * @param string $code     Konfigurationscode (ConfigCodec).
	 * @param string $home_url Adresse der Seite (Startadresse des Shops).
	 * @return string Leer, wenn die Seite nicht zur eigenen Adresse gehört oder der Code ungültig ist.
	 */
	public static function build( $page_url, $code, $home_url ) {
		$page = parse_url( (string) $page_url );
		$home = parse_url( (string) $home_url );

		if ( ! is_array( $page ) || ! is_array( $home ) || empty( $page['host'] ) || empty( $home['host'] ) ) {
			return '';
		}

		if ( strtolower( $page['host'] ) !== strtolower( $home['host'] ) ) {
			return '';
		}

		if ( ! isset( $page['scheme'] ) || ! in_array( strtolower( $page['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		if ( null === ConfigCodec::decode( $code ) ) {
			return '';
		}

		// Fragment und eine vorhandene Konfiguration fallen weg, der Rest
		// der Adresse (Pfad, andere Parameter) bleibt.
		$base = $page['scheme'] . '://' . $page['host'] . ( isset( $page['port'] ) ? ':' . $page['port'] : '' ) . ( isset( $page['path'] ) ? $page['path'] : '/' );
		$args = array();

		if ( isset( $page['query'] ) ) {
			parse_str( $page['query'], $args );
		}

		unset( $args[ ConfigCodec::QUERY_VAR ], $args[ self::QUERY_VAR ] );

		$args[ ConfigCodec::QUERY_VAR ] = $code;
		$args[ self::QUERY_VAR ]        = '1';

		return $base . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}
}
