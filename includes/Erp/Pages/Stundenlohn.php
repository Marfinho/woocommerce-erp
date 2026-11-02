<?php
/**
 * ERP-Seite „Stundenlohn“: Was bleibt pro gearbeiteter Stunde?
 *
 * Sammelt die Verkäufe eines Zeitraums (Shop, Aufträge ohne Shop,
 * Marktkasse), die Herstellkosten der Kalkulation, die erfasste Arbeitszeit,
 * die Standzeiten der Märkte und deren Kampagnenkosten und rechnet mit
 * Erp\HourlyRate.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\HourlyRate;
use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\OrderOpsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Stundenlohn.
 */
class Stundenlohn {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		register_rest_route( RestApi::NAMESPACE_V1, '/erp/stundenlohn', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $api->needs( array( 'finanzen.stundenlohn' ) ) ) );
	}

	/**
	 * Zeitraum aus der Anfrage.
	 *
	 * @param string $mode  monat, quartal, jahr, 12m.
	 * @param string $today Heute.
	 * @return array {from, to, label}
	 */
	public static function period( $mode, $today ) {
		$y = (int) substr( $today, 0, 4 );
		$m = (int) substr( $today, 5, 2 );

		switch ( $mode ) {
			case 'monat':
				return array( 'from' => sprintf( '%04d-%02d-01', $y, $m ), 'to' => $today, 'label' => __( 'dieser Monat', 'lindenweg16' ) );
			case 'vormonat':
				$f = gmdate( 'Y-m-01', strtotime( sprintf( '%04d-%02d-01', $y, $m ) . ' -1 month' ) );
				return array( 'from' => $f, 'to' => gmdate( 'Y-m-t', strtotime( $f ) ), 'label' => __( 'letzter Monat', 'lindenweg16' ) );
			case 'jahr':
				return array( 'from' => $y . '-01-01', 'to' => $today, 'label' => __( 'dieses Jahr', 'lindenweg16' ) );
			case 'quartal':
				$q = (int) ceil( $m / 3 );
				return array( 'from' => sprintf( '%04d-%02d-01', $y, ( $q - 1 ) * 3 + 1 ), 'to' => $today, 'label' => __( 'dieses Quartal', 'lindenweg16' ) );
			default:
				return array( 'from' => gmdate( 'Y-m-d', strtotime( $today . ' -365 days' ) ), 'to' => $today, 'label' => __( 'letzte 12 Monate', 'lindenweg16' ) );
		}
	}

	/**
	 * Daten sammeln und rechnen.
	 *
	 * @param string $from Von.
	 * @param string $to   Bis.
	 * @return array
	 */
	public static function data( $from, $to ) {
		global $wpdb;

		$ku       = Kit::company()['kleinunternehmer'];
		$net      = function ( $gross ) use ( $ku ) {
			return $ku ? (float) $gross : (float) $gross / 1.19;
		};
		$articles = ArticleRepository::all_by_id();
		$by_wc    = array();
		foreach ( $articles as $a ) {
			if ( ! empty( $a['wc_product_id'] ) ) {
				$by_wc[ (int) $a['wc_product_id'] ] = (int) $a['id'];
			}
		}

		$ctx      = \Lindenweg16\Modules\Betrieb\CalculationContext::build();
		$settings = \Lindenweg16\Modules\Betrieb\CalculationContext::settings();
		$costs    = array();
		$cost_of  = function ( $id ) use ( &$costs, $ctx, $settings ) {
			if ( ! isset( $costs[ $id ] ) ) {
				$c            = \Lindenweg16\Modules\Betrieb\CostCalculator::manufacturing_cost( (int) $id, $ctx, $settings );
				$costs[ $id ] = array( 'hk' => (float) $c['herstellkosten'], 'min' => (float) $c['postprocessing_min'] );
			}
			return $costs[ $id ];
		};
		$label_of = function ( $id, $fallback ) use ( $articles ) {
			return isset( $articles[ $id ] ) ? trim( $articles[ $id ]['sku'] . ' · ' . $articles[ $id ]['name'], ' ·' ) : (string) $fallback;
		};

		$lines   = array();
		$orders  = array();
		$markets = array();

		// Shop-Bestellungen (abgeschlossen) im Zeitraum.
		if ( function_exists( 'wc_get_orders' ) ) {
			foreach ( (array) wc_get_orders( array( 'status' => array( 'wc-completed' ), 'date_created' => $from . '...' . $to, 'limit' => -1, 'return' => 'objects' ) ) as $order ) {
				$op      = OrderOpsRepository::find_by_order( (int) $order->get_id() );
				$channel = $op && '' !== (string) $op['channel'] ? (string) $op['channel'] : 'Shop';
				$key     = 'w' . (int) $order->get_id();
				$orders[] = array( 'key' => $key, 'channel' => $channel, 'market' => '', 'handling_min' => $op && null !== $op['handling_minutes'] ? (float) $op['handling_minutes'] : null );

				foreach ( $order->get_items() as $item ) {
					$pid = (int) $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
					$aid = isset( $by_wc[ $pid ] ) ? $by_wc[ $pid ] : ( isset( $by_wc[ (int) $item->get_product_id() ] ) ? $by_wc[ (int) $item->get_product_id() ] : 0 );
					$qty = (float) $item->get_quantity() - abs( (float) $order->get_qty_refunded_for_item( $item->get_id() ) );
					if ( $qty <= 0 ) {
						continue;
					}
					$c       = $aid ? $cost_of( $aid ) : array( 'hk' => 0.0, 'min' => 0.0 );
					$rev     = (float) $item->get_total() - abs( (float) $order->get_total_refunded_for_item( $item->get_id() ) );
					$lines[] = array( 'order' => $key, 'article_id' => $aid, 'label' => $aid ? $label_of( $aid, '' ) : (string) $item->get_name(), 'channel' => $channel, 'market' => '', 'qty' => $qty, 'revenue' => $rev, 'material' => $c['hk'] * $qty, 'hand_min' => $c['min'] );
				}
			}
		}

		// Aufträge ohne Shop-Bestellung.
		$t = OrderOpsRepository::table();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE wc_order_id IS NULL AND order_status = 'bestaetigt' AND manual_order_date >= %s AND manual_order_date <= %s", $from, $to ), ARRAY_A ) as $op ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$key      = 'm' . (int) $op['id'];
			$channel  = '' !== (string) $op['channel'] ? (string) $op['channel'] : 'Fachhandel';
			$orders[] = array( 'key' => $key, 'channel' => $channel, 'market' => '', 'handling_min' => null !== $op['handling_minutes'] ? (float) $op['handling_minutes'] : null );
			foreach ( OrderOpsRepository::items( (int) $op['id'] ) as $it ) {
				$aid     = (int) $it['article_id'];
				$qty     = (float) $it['qty'];
				$c       = $cost_of( $aid );
				$lines[] = array( 'order' => $key, 'article_id' => $aid, 'label' => $label_of( $aid, '#' . $aid ), 'channel' => $channel, 'market' => '', 'qty' => $qty, 'revenue' => $net( (float) $it['unit_price_gross'] * $qty ), 'material' => $c['hk'] * $qty, 'hand_min' => $c['min'] );
			}
		}

		// Marktkasse: Positionen, Standzeit, Kampagnenkosten.
		$kt = $wpdb->prefix . 'lw16_kasse_tage';
		$kp = $wpdb->prefix . 'lw16_kasse_positionen';
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$kt} WHERE status = 'abgeschlossen' AND datum >= %s AND datum <= %s", $from, $to ), ARRAY_A ) as $tag ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$name  = (string) $tag['markt_name'];
			$start = strtotime( (string) $tag['erstellt_am'] );
			$end   = $tag['abgeschlossen_am'] ? strtotime( (string) $tag['abgeschlossen_am'] ) : 0;
			$hours = $start && $end > $start ? min( 16, ( $end - $start ) / 3600 ) : 6.0;
			$cost  = 0.0;
			if ( (int) $tag['kampagne_id'] ) {
				$cost = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(b.betrag * kb.anteil_prozent / 100), 0) FROM ' . $wpdb->prefix . 'lw16_kampagne_beleg kb JOIN ' . \Lindenweg16\Modules\Accounting\BelegRepository::table() . ' b ON b.id = kb.beleg_id WHERE kb.kampagne_id = %d AND b.storniert = 0', (int) $tag['kampagne_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			$markets[] = array( 'name' => $name, 'hours' => $hours, 'costs' => $cost );
			$key       = 'k' . (int) $tag['id'];
			$orders[]  = array( 'key' => $key, 'channel' => 'vor Ort', 'market' => $name, 'handling_min' => 0.0 );

			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$kp} WHERE tag_id = %d AND storniert = 0", (int) $tag['id'] ), ARRAY_A ) as $pos ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$aid     = (int) $pos['article_id'];
				$qty     = (float) $pos['menge'];
				$c       = $aid ? $cost_of( $aid ) : array( 'hk' => 0.0, 'min' => 0.0 );
				$lines[] = array( 'order' => $key, 'article_id' => $aid, 'label' => $aid ? $label_of( $aid, '' ) : (string) $pos['artikel_name'], 'channel' => 'vor Ort', 'market' => $name, 'qty' => $qty, 'revenue' => $net( (float) $pos['betrag'] ), 'material' => $c['hk'] * $qty, 'hand_min' => $c['min'] );
			}
		}

		$time   = HourlyRate::time_buckets( (array) \Lindenweg16\Modules\Betrieb\TimeRepository::hours_by_category( $from, $to ) );
		$result = HourlyRate::compute( $lines, $orders, $markets, $time, array( 'default_handling_min' => 5 ) );

		$result['time']     = $time;
		$result['insights'] = HourlyRate::insights( $result );
		$result['basis']    = $ku ? 'brutto' : 'netto';

		return $result;
	}

	/**
	 * Übersicht.
	 *
	 * @param WP_REST_Request $request Anfrage (zeitraum).
	 * @return WP_REST_Response
	 */
	public static function overview( WP_REST_Request $request ) {
		$mode = sanitize_key( (string) $request->get_param( 'zeitraum' ) );
		$p    = self::period( '' !== $mode ? $mode : 'quartal', current_time( 'Y-m-d' ) );
		$key  = 'lw16_erp_stundenlohn_' . md5( $p['from'] . $p['to'] );
		$data = get_transient( $key );

		if ( false === $data || $request->get_param( 'neu' ) ) {
			$data = self::data( $p['from'], $p['to'] );
			set_transient( $key, $data, 15 * MINUTE_IN_SECONDS );
		}

		return new WP_REST_Response( array_merge( $data, array( 'period' => $p, 'mode' => '' !== $mode ? $mode : 'quartal' ) ) );
	}
}
