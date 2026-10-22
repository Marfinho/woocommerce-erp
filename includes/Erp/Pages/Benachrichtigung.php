<?php
/**
 * Push-Benachrichtigungen: Routen für die Einstellung im Profilmenü und für den
 * Abruf durch den Service Worker.
 *
 * Eingerichtet wird von jeder angemeldeten ERP-Person für sich selbst. Der
 * Abruf läuft ohne Anmeldung (der Service Worker hat keine Sitzung bei der Anfrage),
 * ausgewiesen durch den Endpunkt des Geräts; dessen Adresse ist ein Geheimnis,
 * das nur Gerät und Server kennen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\Push;
use Lindenweg16\Erp\RestApi;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen.
 */
class Benachrichtigung {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns  = RestApi::NAMESPACE_V1;
		$use = array( $api, 'can_use' );

		register_rest_route( $ns, '/erp/push/status', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'status' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, '/erp/push/anmelden', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'anmelden' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, '/erp/push/abmelden', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'abmelden' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, '/erp/push/arten', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'arten' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, '/erp/push/test', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'test' ), 'permission_callback' => $use ) );
		// Ohne Anmeldung: der Service Worker holt hier die wartenden Meldungen ab.
		register_rest_route( $ns, '/erp/push/abruf', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'abruf' ), 'permission_callback' => '__return_true' ) );
	}

	/**
	 * Stand: Schlüssel, Meldungsarten, Geräte.
	 *
	 * @return WP_REST_Response
	 */
	public static function status() {
		$uid  = get_current_user_id();
		$keys = Push::keys();
		$prefs = Push::prefs( $uid );
		$arten = array();
		foreach ( Push::categories() as $key => $def ) {
			if ( Access::can( $def[1] ) ) {
				$arten[] = array( 'key' => $key, 'label' => $def[0], 'an' => $prefs[ $key ] );
			}
		}
		$geraete = array();
		foreach ( Push::devices( $uid ) as $id => $dev ) {
			$geraete[] = array( 'id' => $id, 'endpoint' => $dev['endpoint'], 'agent' => isset( $dev['agent'] ) ? $dev['agent'] : '', 'seit' => isset( $dev['since'] ) ? (int) $dev['since'] : 0 );
		}
		return new WP_REST_Response(
			array(
				'ok'        => true,
				'server'    => (bool) $keys,
				'publicKey' => $keys ? $keys['public'] : '',
				'arten'     => $arten,
				'geraete'   => $geraete,
			)
		);
	}

	/**
	 * Gerät anmelden.
	 *
	 * @param WP_REST_Request $request Anfrage mit endpoint.
	 * @return WP_REST_Response
	 */
	public static function anmelden( WP_REST_Request $request ) {
		$res = Push::subscribe( get_current_user_id(), (string) $request->get_param( 'endpoint' ), (string) $request->get_param( 'agent' ) );
		if ( true !== $res ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $res ), 400 );
		}
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Gerät abmelden.
	 *
	 * @param WP_REST_Request $request Anfrage mit endpoint.
	 * @return WP_REST_Response
	 */
	public static function abmelden( WP_REST_Request $request ) {
		Push::unsubscribe( get_current_user_id(), (string) $request->get_param( 'endpoint' ) );
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Meldungsarten wählen.
	 *
	 * @param WP_REST_Request $request Anfrage mit arten (Schlüssel => bool).
	 * @return WP_REST_Response
	 */
	public static function arten( WP_REST_Request $request ) {
		$in = $request->get_param( 'arten' );
		Push::save_prefs( get_current_user_id(), is_array( $in ) ? $in : array() );
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Testmeldung an die eigenen Geräte.
	 *
	 * @return WP_REST_Response
	 */
	public static function test() {
		$uid  = get_current_user_id();
		$devs = Push::devices( $uid );
		if ( ! $devs ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Auf diesem Konto ist noch kein Gerät angemeldet.', 'lindenweg16' ) ), 400 );
		}
		$msg    = Push::message( __( 'Testmeldung', 'lindenweg16' ), __( 'Benachrichtigungen funktionieren.', 'lindenweg16' ), '', 'test' );
		$sent   = 0;
		$failed = 0;
		foreach ( $devs as $id => $dev ) {
			$dev['inbox']   = isset( $dev['inbox'] ) && is_array( $dev['inbox'] ) ? $dev['inbox'] : array();
			$dev['inbox'][] = $msg;
			update_user_meta( $uid, Push::META_PREFIX . 'dev_' . $id, $dev );
			$code = Push::wake( $dev['endpoint'] );
			if ( 404 === $code || 410 === $code ) {
				delete_user_meta( $uid, Push::META_PREFIX . 'dev_' . $id );
				++$failed;
			} elseif ( $code >= 200 && $code < 300 ) {
				++$sent;
			} else {
				++$failed;
			}
		}
		return new WP_REST_Response( array( 'ok' => $sent > 0, 'gesendet' => $sent, 'fehler' => $failed, 'message' => $sent > 0 ? '' : __( 'Der Push-Dienst deines Browsers hat die Testmeldung nicht angenommen.', 'lindenweg16' ) ) );
	}

	/**
	 * Wartende Meldungen abholen (Service Worker).
	 *
	 * @param WP_REST_Request $request Anfrage mit endpoint.
	 * @return WP_REST_Response
	 */
	public static function abruf( WP_REST_Request $request ) {
		$msgs = Push::take_inbox( (string) $request->get_param( 'endpoint' ) );
		$resp = new WP_REST_Response( array( 'meldungen' => $msgs ) );
		$resp->header( 'Cache-Control', 'no-store' );
		return $resp;
	}
}
