<?php
/**
 * Kurzzeitige Sperren über die Datenbank.
 *
 * An zwei Stellen im Plugin genügt „prüfen, dann handeln" nicht: beim
 * Anhängen an die GoBD-Hashkette und beim Lagerabgang einer Bestellung.
 * Beide lesen erst einen Zustand und schreiben dann auf seiner Grundlage –
 * laufen zwei Anfragen gleichzeitig, lesen beide denselben Zustand und
 * handeln beide. Einmal gabelt sich die Kette, einmal wird der Bestand
 * doppelt abgebucht.
 *
 * WordPress hat dafür nichts Verlässliches: Transients sind ebenfalls
 * „prüfen, dann setzen" und liegen je nach Anlage im Objektcache statt in
 * der Datenbank. `GET_LOCK` von MySQL ist unteilbar, an die Verbindung
 * gebunden und wird beim Abbruch von selbst freigegeben – genau das, was
 * eine Sperre über einen einzelnen Seitenaufruf braucht.
 *
 * Wichtig: Diese Sperre ist kein Ersatz für eine Transaktion. Sie ordnet
 * nur zwei Läufe hintereinander an, sie macht das Geschriebene nicht
 * rückgängig.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Benannte Sperren.
 */
class Lock {

	/**
	 * Wie lange auf eine Sperre gewartet wird.
	 */
	const TIMEOUT = 5;

	/**
	 * Sperre anfordern.
	 *
	 * @param string $key     Name, plugin-intern.
	 * @param int    $timeout Wartezeit in Sekunden.
	 * @return bool Ob die Sperre gehalten wird.
	 */
	public static function acquire( $key, $timeout = self::TIMEOUT ) {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::name( $key ), max( 0, (int) $timeout ) )
		);

		return '1' === (string) $result;
	}

	/**
	 * Sperre freigeben.
	 *
	 * @param string $key Name.
	 */
	public static function release( $key ) {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::name( $key ) ) );
	}

	/**
	 * Der Name, unter dem die Datenbank die Sperre führt.
	 *
	 * Die Datenbank steht im Namen, damit sich zwei Anlagen auf demselben
	 * Server nicht gegenseitig ausbremsen. MySQL erlaubt 64 Zeichen; ein
	 * längerer Name wird nicht etwa gekürzt, sondern abgewiesen.
	 *
	 * @param string $key Name.
	 * @return string
	 */
	public static function name( $key ) {
		$roh = 'lw16_' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '_' . preg_replace( '/[^a-z0-9_]/i', '_', (string) $key );

		if ( strlen( $roh ) <= 64 ) {
			return $roh;
		}

		// Zu lang: eindeutig kürzen statt hinten abzuschneiden – sonst
		// fielen zwei verschiedene Sperren auf denselben Namen.
		return substr( $roh, 0, 31 ) . '_' . substr( hash( 'sha256', $roh ), 0, 32 );
	}
}
