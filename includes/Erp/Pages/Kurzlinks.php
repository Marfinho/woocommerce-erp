<?php
/**
 * ERP-Seite „Kurzlinks“: Links verwalten, Aufrufe und Bestellungen nach Herkunft.
 *
 * Gleiche Prüfungen (`Links::process`), gleiche Zähler (`Stats`) und gleiche
 * Auswertung (`OrderOrigin`) wie die wp-admin-Seite. Recht: `verkauf.kurzlinks`
 * (WordPress-Recht `manage_woocommerce`).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Kurzlinks\Links;
use Lindenweg16\Modules\Kurzlinks\OrderOrigin;
use Lindenweg16\Modules\Kurzlinks\Stats;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Kurzlinks in der neuen Oberfläche.
 */
class Kurzlinks {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$needs = $api->needs( array( 'verkauf.kurzlinks' ) );

		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/kurzlinks',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get' ),
					'permission_callback' => $needs,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save' ),
					'permission_callback' => $needs,
				),
			)
		);
	}

	/**
	 * Gewählten Zeitraum auf die bekannten begrenzen (Vorgabe 90 Tage).
	 *
	 * @param mixed $days Eingabe.
	 * @return int
	 */
	public static function clean_days( $days ) {
		$days = is_numeric( $days ) ? (int) $days : 90;

		return array_key_exists( $days, OrderOrigin::ranges() ) ? $days : 90;
	}

	/**
	 * Fehler je Zeile zu einer Meldung zusammenfassen.
	 *
	 * @param array $errors Zeilennummer => Meldungen.
	 * @return string
	 */
	public static function error_message( array $errors ) {
		$parts = array();

		foreach ( $errors as $msgs ) {
			foreach ( (array) $msgs as $msg ) {
				$parts[] = $msg;
			}
		}

		return __( 'Nichts gespeichert. ', 'lindenweg16' ) . implode( ' ', $parts );
	}

	/**
	 * Links, Seiten, Zähler und Herkunft.
	 *
	 * @param WP_REST_Request $request Anfrage (herkunft).
	 * @return WP_REST_Response
	 */
	public static function get( WP_REST_Request $request ) {
		$days = self::clean_days( $request->get_param( 'herkunft' ) );

		$pages = array();
		foreach ( (array) get_pages( array( 'post_status' => 'publish' ) ) as $p ) {
			$pages[] = array(
				'id'    => (int) $p->ID,
				'title' => (string) $p->post_title,
			);
		}

		$links = array();
		foreach ( Links::all() as $link ) {
			$warning = '';
			if ( $link['aktiv'] && Links::content_exists( $link['slug'] ) ) {
				$warning = __( 'Unter diesem Pfad gibt es auch eine Seite oder einen Beitrag. Der Kurzlink hat Vorrang und verdeckt sie.', 'lindenweg16' );
			} elseif ( $link['seite_id'] > 0 && 'publish' !== get_post_status( $link['seite_id'] ) ) {
				$warning = __( 'Die gewählte Seite ist nicht veröffentlicht – bis dahin geht der Link auf die Startseite.', 'lindenweg16' );
			}

			$links[] = array(
				'slug'         => $link['slug'],
				'address'      => home_url( '/' . $link['slug'] ),
				'beschreibung' => $link['beschreibung'],
				'seite_id'     => $link['seite_id'],
				'url'          => $link['url'],
				'aktiv'        => $link['aktiv'],
				'placeholder'  => 0 === $link['seite_id'] && '' === $link['url'],
				'warning'      => $warning,
			);
		}

		$scans = array();
		foreach ( Stats::group( Stats::totals() ) as $slug => $g ) {
			$scans[] = array_merge( array( 'slug' => $slug ), $g );
		}

		$origin = array();
		$sum_n  = 0;
		$sum_u  = 0.0;
		foreach ( OrderOrigin::aggregate( OrderOrigin::collect( $days ) ) as $source => $r ) {
			$sum_n   += $r['anzahl'];
			$sum_u   += $r['umsatz'];
			$origin[] = array(
				'quelle' => $source,
				'anzahl' => $r['anzahl'],
				'umsatz' => round( $r['umsatz'], 2 ),
			);
		}

		return new WP_REST_Response(
			array(
				'home'       => home_url( '/' ),
				'links'      => $links,
				'pages'      => $pages,
				'scans'      => $scans,
				'days'       => $days,
				'ranges'     => OrderOrigin::ranges(),
				'origin'     => $origin,
				'origin_sum' => array(
					'anzahl' => $sum_n,
					'umsatz' => round( $sum_u, 2 ),
				),
			)
		);
	}

	/**
	 * Links speichern. Bei einem Fehler wird nichts gespeichert.
	 *
	 * @param WP_REST_Request $request Anfrage (rows).
	 * @return WP_REST_Response
	 */
	public static function save( WP_REST_Request $request ) {
		$rows = $request->get_param( 'rows' );

		if ( ! is_array( $rows ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Es kamen keine Daten an. Bitte die Seite neu laden und noch einmal speichern.', 'lindenweg16' ) ), 422 );
		}

		$result = Links::process(
			$rows,
			Links::all(),
			Links::reserved_slugs_for_site(),
			array( Links::class, 'content_exists' ),
			(string) wp_parse_url( home_url(), PHP_URL_HOST )
		);

		if ( $result['errors'] ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => self::error_message( $result['errors'] ),
					'errors'  => $result['errors'],
				),
				422
			);
		}

		// Die Rewrite-Regel gleicht sich beim nächsten Aufruf selbst ab (Redirect::add_rewrite()).
		Links::save( $result['links'] );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Kurzlinks gespeichert.', 'lindenweg16' ),
			)
		);
	}
}
