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

use Lindenweg16\Erp\Presence;
use Lindenweg16\Erp\RestApi;
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
