<?php
/**
 * ERP-Seite „Claude-Zugang“ (Profilmenü): Schlüssel für den MCP-Zugang.
 *
 * Gleiche Schlüsselverwaltung wie die wp-admin-Seite (Mcp\Tokens). Recht:
 * `verwaltung.claude` – nur Vollzugriff, nicht vergebbar – und zusätzlich
 * das WordPress-Recht `manage_options`, denn ein Schlüssel öffnet den
 * ganzen Shop. Der MCP-Zugang selbst kann diese Routen nicht aufrufen
 * (Mcp\Tools::dispatch).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Mcp\Server;
use Lindenweg16\Mcp\Tokens;
use Lindenweg16\Mcp\Tools;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Claude-Zugang in der ERP-Oberfläche.
 */
class ClaudeZugang {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$needs = array( __CLASS__, 'can' );

		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/claude',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get' ), 'permission_callback' => $needs ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create' ), 'permission_callback' => $needs ),
			)
		);
		register_rest_route( RestApi::NAMESPACE_V1, '/erp/claude/(?P<id>[a-f0-9]{12})/loeschen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'revoke' ), 'permission_callback' => $needs ) );
	}

	/**
	 * Darf die Person Schlüssel verwalten?
	 *
	 * @return bool
	 */
	public static function can() {
		return Access::can( 'verwaltung.claude' ) && current_user_can( 'manage_options' );
	}

	/**
	 * Schlüssel, Adresse, Auftragstext.
	 *
	 * @return WP_REST_Response
	 */
	public static function get() {
		$rows = array();

		foreach ( Tokens::all() as $entry ) {
			$user   = get_userdata( (int) $entry['user_id'] );
			$rows[] = array(
				'id'        => $entry['id'],
				'label'     => $entry['label'],
				'hint'      => Tokens::PREFIX . $entry['hint'],
				'konto'     => $user ? $user->display_name : __( '(gelöscht)', 'lindenweg16' ),
				'write'     => ! empty( $entry['write'] ),
				'created'   => (int) $entry['created'],
				'last_used' => (int) $entry['last_used'],
			);
		}

		return new WP_REST_Response(
			array(
				'tokens' => $rows,
				'url'    => Server::url(),
				'konto'  => wp_get_current_user()->display_name,
				'prompt' => Tools::daily_prompt(),
			)
		);
	}

	/**
	 * Schlüssel anlegen – der Klartext geht nur in dieser Antwort mit.
	 *
	 * @param WP_REST_Request $request Anfrage (label, write).
	 * @return WP_REST_Response
	 */
	public static function create( WP_REST_Request $request ) {
		$in    = Kit::input( $request );
		$label = isset( $in['label'] ) ? sanitize_text_field( (string) $in['label'] ) : '';
		$write = ! empty( $in['write'] );

		$token = Tokens::create( $label, get_current_user_id(), $write );
		AuditLog::record( 'mcp_schluessel_angelegt', 'mcp', 0, array( 'label' => $label, 'schreiben' => $write ) );

		$response = new WP_REST_Response(
			array(
				'ok'      => true,
				'token'   => $token,
				'url_key' => Server::url( $token ),
				'cli'     => 'claude mcp add --transport http lindenweg16 ' . Server::url() . ' --header "Authorization: Bearer ' . $token . '"',
			)
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Schlüssel löschen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function revoke( WP_REST_Request $request ) {
		$id = (string) $request['id'];

		if ( ! isset( Tokens::all()[ $id ] ) ) {
			return Kit::fail( __( 'Diesen Schlüssel gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		Tokens::revoke( $id );
		AuditLog::record( 'mcp_schluessel_geloescht', 'mcp', 0, array( 'id' => $id ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Schlüssel gelöscht. Claude kommt damit nicht mehr hinein.', 'lindenweg16' ) ) );
	}
}
