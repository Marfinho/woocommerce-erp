<?php
/**
 * ERP-Seiten „Prognose & Planung“ und „Tagesplan“.
 *
 * Alle Rechnungen stehen in `Modules\Betrieb\Forecast*`, `Promise` und
 * `DayPlan`; diese Klasse reicht sie nur als JSON weiter und speichert die
 * Einstellungen. Rechte: `finanzen.prognose` und `fertigung.tagesplan`.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Log;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\ForecastService;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Prognose in der neuen Oberfläche.
 */
class Prognose {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns    = RestApi::NAMESPACE_V1;
		$needs = $api->needs( array( 'finanzen.prognose' ) );
		$plan  = $api->needs( array( 'fertigung.tagesplan' ) );

		register_rest_route( $ns, '/erp/prognose', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/prognose/artikel', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'articles' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/prognose/zusage', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'promise' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/prognose/einstellungen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_settings' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/prognose/ereignisse', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_events' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/prognose/pruefen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'run' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/tagesplan', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'day_plan' ), 'permission_callback' => $plan ) );
	}

	/**
	 * Kennzahlen mit Prognose, Einstellungen, Ereignisse.
	 *
	 * @return WP_REST_Response
	 */
	public static function overview() {
		return new WP_REST_Response(
			array(
				'kpis'     => ForecastService::kpi_overview(),
				'settings' => ForecastService::settings(),
				'events'   => ForecastService::events(),
				'market'   => ForecastService::market_hint(),
				'last_run' => (int) get_option( ForecastService::LAST_RUN, 0 ),
			)
		);
	}

	/**
	 * Bedarfsprognose je Artikel und Farbe.
	 *
	 * @return WP_REST_Response
	 */
	public static function articles() {
		return new WP_REST_Response(
			array(
				'rows'     => ForecastService::article_outlook(),
				'settings' => ForecastService::settings(),
				'articles' => array_values(
					array_map(
						static function ( $a ) {
							return array( 'id' => (int) $a['id'], 'label' => $a['sku'] . ' – ' . $a['name'] );
						},
						array_filter(
							ArticleRepository::all(),
							static function ( $a ) {
								return ! empty( $a['sellable'] ) && ArticleRepository::is_active( $a );
							}
						)
					)
				),
			)
		);
	}

	/**
	 * Lieferzusage für eine Anfrage.
	 *
	 * @param WP_REST_Request $request Anfrage (article_id, qty, color).
	 * @return WP_REST_Response
	 */
	public static function promise( WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'article_id' );
		$qty = (float) $request->get_param( 'qty' );

		if ( $id <= 0 || $qty <= 0 ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Bitte einen Artikel und eine Menge über null angeben.', 'lindenweg16' ) ), 422 );
		}

		$result = ForecastService::promise( $id, $qty, sanitize_key( (string) $request->get_param( 'color' ) ) );

		if ( isset( $result['error'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $result['error'] ), 404 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'result' => $result ) );
	}

	/**
	 * Schwellen und Schalter speichern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function save_settings( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		ForecastService::save_settings( is_array( $body ) ? $body : array() );
		Log::normal( 'prognose', 'Einstellungen der Prognose geändert' );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Gespeichert.', 'lindenweg16' ), 'settings' => ForecastService::settings() ) );
	}

	/**
	 * Geplante Ereignisse speichern.
	 *
	 * @param WP_REST_Request $request Anfrage (events).
	 * @return WP_REST_Response
	 */
	public static function save_events( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		ForecastService::save_events( is_array( $body ) && isset( $body['events'] ) && is_array( $body['events'] ) ? $body['events'] : array() );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Ereignisse gespeichert.', 'lindenweg16' ), 'events' => ForecastService::events() ) );
	}

	/**
	 * Auslöser jetzt prüfen.
	 *
	 * @return WP_REST_Response
	 */
	public static function run() {
		$r = ForecastService::run_triggers();

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: 1: neu ausgelöst, 2: entspannt. */
					__( 'Geprüft: %1$d neu ausgelöst, %2$d entspannt. Details im Ereignisprotokoll.', 'lindenweg16' ),
					$r['raised'],
					$r['recovered']
				),
			)
		);
	}

	/**
	 * Der Tagesplan.
	 *
	 * @return WP_REST_Response
	 */
	public static function day_plan() {
		$plan   = ForecastService::day_plan();
		$counts = Log::counts( 1 );

		return new WP_REST_Response(
			array(
				'plan'    => $plan['plan'],
				'totals'  => $plan['totals'],
				'weeks'   => $plan['weeks'],
				'cap'     => $plan['capacity'],
				'hands'   => $plan['hands'],
				'events'  => $counts,
				'orders'  => function_exists( 'wc_orders_count' ) ? (int) wc_orders_count( 'processing' ) + (int) wc_orders_count( 'on-hold' ) : 0,
				'kpis'    => array_map(
					static function ( $k ) {
						return array( 'label' => $k['label'], 'status' => $k['status'] );
					},
					ForecastService::kpi_overview()
				),
				'risks'   => array_slice(
					array_values(
						array_filter(
							ForecastService::article_outlook(),
							static function ( $r ) {
								return 'normal' !== $r['level'];
							}
						)
					),
					0,
					8
				),
			)
		);
	}
}
