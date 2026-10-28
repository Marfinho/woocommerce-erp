<?php
/**
 * Der Nutzer für den Bildschirm an der Wand: nur die Live-Ansicht, dafür ohne Abmeldung.
 *
 * Normale Anmeldungen laufen nach zwei Tagen (mit „Angemeldet bleiben“ nach
 * vierzehn) ab. Ein Bildschirm, der niemanden vor sich hat, soll sich nicht
 * alle paar Tage neu anmelden müssen. Die Rolle lw16_live hat deshalb eine
 * Anmeldung von einem Jahr und sieht nur die Live-Seite, sonst nichts im ERP.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Rolle, Anmeldedauer und Zugriff der Live-Anzeige.
 */
class LiveViewer {

	/**
	 * Rolle der Live-Anzeige.
	 */
	const ROLE = 'lw16_live';

	/**
	 * Dauer der Anmeldung.
	 */
	const SESSION = YEAR_IN_SECONDS;

	/**
	 * Hooks registrieren.
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'ensure_role' ) );
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'session_length' ), 10, 2 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 30, 3 );
	}

	/**
	 * Rolle anlegen, falls es sie noch nicht gibt.
	 */
	public static function ensure_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'Lindenweg16 Live-Anzeige', 'lindenweg16' ), array( 'read' => true ) );
		}
	}

	/**
	 * Ist die Person eine Live-Anzeige?
	 *
	 * Wer zusätzlich normale ERP-Rechte hat, ist keine: Die Rolle öffnet
	 * nichts, sie ergänzt nur die Live-Seite für Konten ohne sonstige Rechte.
	 *
	 * @param int $user_id Nutzer; 0 = angemeldeter.
	 * @return bool
	 */
	public static function is_viewer( $user_id = 0 ) {
		$user = $user_id ? get_userdata( (int) $user_id ) : wp_get_current_user();

		return $user && $user->exists() && in_array( self::ROLE, (array) $user->roles, true );
	}

	/**
	 * Darf die Person die Live-Seite öffnen?
	 *
	 * @param int $user_id Nutzer; 0 = angemeldeter.
	 * @return bool
	 */
	public static function can_open( $user_id = 0 ) {
		return Access::has_any( $user_id ) || self::is_viewer( $user_id );
	}

	/**
	 * Anmeldedauer: ein Jahr für die Live-Anzeige, sonst unverändert.
	 *
	 * @param int $seconds Bisherige Dauer.
	 * @param int $user_id Nutzer.
	 * @return int
	 */
	public static function session_length( $seconds, $user_id ) {
		return self::is_viewer( (int) $user_id ) ? self::SESSION : $seconds;
	}

	/**
	 * Nach der Anmeldung direkt in die Vollbild-Ansicht.
	 *
	 * @param string $to   Bisheriges Ziel.
	 * @param string $from Angeforderte Adresse.
	 * @param mixed  $user Angemeldete Person (oder Fehler).
	 * @return string
	 */
	public static function login_redirect( $to, $from, $user ) {
		if ( $user instanceof \WP_User && self::is_viewer( $user->ID ) && ! Access::has_any( $user->ID ) ) {
			return Shell::url() . '#/live/tv';
		}

		return $to;
	}
}
