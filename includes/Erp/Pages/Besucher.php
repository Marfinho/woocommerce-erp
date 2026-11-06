<?php
/**
 * ERP-Seite „Besucher“: wer gerade auf der Website ist und wo.
 *
 * Liest nur `Presence` (kurzlebig, ohne Cookie, ohne IP-Adresse). Recht:
 * `verkauf.besucher` (WordPress-Recht `manage_woocommerce`).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\Flow;
use Lindenweg16\Erp\Insights;
use Lindenweg16\Erp\Presence;
use Lindenweg16\Erp\RestApi;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Besucher jetzt in der neuen Oberfläche.
 */
class Besucher {

	/**
	 * Route anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/besucher',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get' ),
				'permission_callback' => $api->needs( array( 'verkauf.besucher' ) ),
			)
		);

		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/besucher/wege',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'flow' ),
				'permission_callback' => $api->needs( array( 'verkauf.besucher' ) ),
			)
		);

		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/besucher/einblicke',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'insights' ),
				'permission_callback' => $api->needs( array( 'verkauf.besucher' ) ),
			)
		);
	}

	/**
	 * Einblicke: Produkt-Trichter, Kampagnen, Suche, Fehlerseiten.
	 *
	 * @param WP_REST_Request $request Anfrage (tage).
	 * @return WP_REST_Response
	 */
	public static function insights( WP_REST_Request $request ) {
		return new WP_REST_Response( Insights::snapshot( $request->get_param( 'tage' ) ) );
	}

	/**
	 * Wege: Übergänge zwischen Seiten im gewählten Zeitraum.
	 *
	 * @param WP_REST_Request $request Anfrage (tage, seite).
	 * @return WP_REST_Response
	 */
	public static function flow( WP_REST_Request $request ) {
		$page = Presence::clean_path( (string) $request->get_param( 'seite' ) );

		return new WP_REST_Response( Flow::snapshot( $request->get_param( 'tage' ), '' === (string) $request->get_param( 'seite' ) ? '' : $page ) );
	}

	/**
	 * Stand: Zahl, Seiten, Herkunft, Geräte, einzelne Besucher.
	 *
	 * @return WP_REST_Response
	 */
	public static function get() {
		$snap        = Presence::snapshot();
		$snap['now'] = time();

		return new WP_REST_Response( $snap );
	}
}
