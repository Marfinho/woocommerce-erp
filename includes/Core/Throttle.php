<?php
/**
 * Drosselung für offene Schnittstellen.
 *
 * Zwei Endpunkte sind ohne Anmeldung erreichbar: der Besucherzähler und der
 * Abruf der Push-Meldungen. Beide schreiben beziehungsweise lesen in der
 * Datenbank - ein einzelner Rechner, der sie im Sekundentakt aufruft, würde
 * den Shop spürbar belasten. Die Drosselung zählt Aufrufe je Adresse und
 * Minute und lässt darüber hinaus nichts mehr durch.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Aufrufe je Adresse und Minute zählen.
 */
class Throttle {

	/**
	 * Darf dieser Aufruf noch durch? Reine Regel.
	 *
	 * @param int $count Aufrufe in diesem Zeitfenster, diesen mitgezählt.
	 * @param int $limit Erlaubte Aufrufe.
	 * @return bool
	 */
	public static function allowed( $count, $limit ) {
		return (int) $count <= max( 1, (int) $limit );
	}

	/**
	 * Aufruf zählen und prüfen.
	 *
	 * @param string $bucket Name des Endpunkts.
	 * @param int    $limit  Erlaubte Aufrufe je Minute und Adresse.
	 * @return bool True, wenn er durchdarf.
	 */
	public static function hit( $bucket, $limit ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key = 'lw16_thr_' . substr( md5( $bucket . '|' . $ip . '|' . floor( time() / 60 ) ), 0, 20 );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'lw16_throttle', 90 );
			$count = (int) wp_cache_incr( $key, 1, 'lw16_throttle' );
		} else {
			$count = (int) get_transient( $key ) + 1;
			set_transient( $key, $count, 90 );
		}

		return self::allowed( $count, $limit );
	}
}
