<?php
/**
 * Angemeldete Geräte (Profilmenü): Übersicht der eigenen WordPress-Sitzungen.
 *
 * Jede Anmeldung (Handy, Rechner, App vom Home-Bildschirm …) ist eine eigene
 * Sitzung; eine neue Anmeldung beendet die anderen nicht. Hier sieht man sie
 * und kann alle anderen beenden. Jede Person sieht und beendet nur ihre eigenen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\RestApi;
use WP_REST_Response;
use WP_Session_Tokens;

/**
 * Sitzungen der angemeldeten Person.
 */
class Sitzungen {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns  = RestApi::NAMESPACE_V1;
		$use = array( $api, 'can_use' );

		register_rest_route( $ns, '/erp/sitzungen', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'liste' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, '/erp/sitzungen/andere-beenden', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'andere_beenden' ), 'permission_callback' => $use ) );
	}

	/**
	 * Alle Sitzungen der Person, die aktuelle zuerst.
	 *
	 * @return WP_REST_Response
	 */
	public static function liste() {
		$manager = WP_Session_Tokens::get_instance( get_current_user_id() );
		$current = $manager->get( wp_get_session_token() );
		$rows    = array();

		foreach ( (array) $manager->get_all() as $session ) {
			$rows[] = array(
				'login'      => isset( $session['login'] ) ? (int) $session['login'] : 0,
				'expiration' => isset( $session['expiration'] ) ? (int) $session['expiration'] : 0,
				'ip'         => isset( $session['ip'] ) ? (string) $session['ip'] : '',
				'ua'         => isset( $session['ua'] ) ? (string) $session['ua'] : '',
				'current'    => is_array( $current ) && $current === $session,
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return $a['current'] === $b['current'] ? $b['login'] <=> $a['login'] : ( $a['current'] ? -1 : 1 );
			}
		);

		return new WP_REST_Response( array( 'sitzungen' => $rows ) );
	}

	/**
	 * Alle Sitzungen außer dieser beenden.
	 *
	 * @return WP_REST_Response
	 */
	public static function andere_beenden() {
		WP_Session_Tokens::get_instance( get_current_user_id() )->destroy_others( wp_get_session_token() );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Alle anderen Geräte sind abgemeldet.', 'lindenweg16' ) ) );
	}
}
