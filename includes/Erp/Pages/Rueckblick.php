<?php
/**
 * ERP-Seite „Rückblick“: die Woche als kurze Geschichte, freitags per Mail.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Erp\Review;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Wochenrückblick.
 */
class Rueckblick {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns    = RestApi::NAMESPACE_V1;
		$needs = $api->needs( array( 'finanzen.rueckblick' ) );

		register_rest_route( $ns, '/erp/rueckblick', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/rueckblick/einstellungen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/rueckblick/senden', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'send_now' ), 'permission_callback' => $needs ) );
	}

	/**
	 * Montag bis Sonntag einer Woche.
	 *
	 * @param string $day Ein Tag der Woche.
	 * @return array {from, to, label}
	 */
	public static function week_of( $day ) {
		$ts     = strtotime( $day . ' 12:00:00 UTC' );
		$monday = $ts - ( (int) gmdate( 'N', $ts ) - 1 ) * 86400;

		return array(
			'from'  => gmdate( 'Y-m-d', $monday ),
			'to'    => gmdate( 'Y-m-d', $monday + 6 * 86400 ),
			'label' => 'KW ' . (int) gmdate( 'W', $monday ) . '/' . gmdate( 'o', $monday ),
		);
	}

	/**
	 * Fakten einer Woche sammeln.
	 *
	 * @param array $w Woche.
	 * @return array
	 */
	public static function facts( array $w ) {
		global $wpdb;

		$prev  = self::week_of( Kit::add_days( $w['from'], -7 ) );
		$to    = min( $w['to'], current_time( 'Y-m-d' ) );
		$now   = Stundenlohn::data( $w['from'], $to );
		$then  = Stundenlohn::data( $prev['from'], $prev['to'] );
		$count = function ( $from, $to ) {
			if ( ! function_exists( 'wc_get_orders' ) ) {
				return 0;
			}
			return count( (array) wc_get_orders( array( 'status' => array( 'wc-completed', 'wc-processing' ), 'date_created' => $from . '...' . $to, 'limit' => -1, 'return' => 'ids' ) ) );
		};

		$best = $now['products'];
		usort(
			$best,
			function ( $a, $b ) {
				return $b['qty'] <=> $a['qty'];
			}
		);
		$markets  = array_values( array_filter( $now['markets'], function ( $m ) { return $m['revenue'] > 0; } ) );
		usort( $markets, function ( $a, $b ) { return $b['db'] <=> $a['db']; } );
		$channels = array_values( array_filter( $now['channels'], function ( $c ) { return null !== $c['rate']; } ) );
		$rated    = array_values( array_filter( $now['products'], function ( $p ) { return null !== $p['rate'] && $p['hours'] >= 1; } ) );

		// Drucker: gemeldete Platten der Woche, mit Ausschuss.
		$printers = array();
		$names    = array();
		foreach ( (array) \Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository::all() as $p ) {
			$names[ (string) $p->printer_id ] = (string) $p->name;
		}
		$rt = \Lindenweg16\Erp\RunRepository::table();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT bambu_printer, COUNT(*) AS plates, SUM(CASE WHEN failure_reason IS NOT NULL AND failure_reason <> 'keine' THEN 1 ELSE 0 END) AS scrap FROM {$rt} WHERE reported_at >= %s AND reported_at <= %s GROUP BY bambu_printer", get_gmt_from_date( $w['from'] . ' 00:00:00' ), get_gmt_from_date( $w['to'] . ' 23:59:59' ) ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$printers[] = array( 'name' => isset( $names[ (string) $r['bambu_printer'] ] ) ? $names[ (string) $r['bambu_printer'] ] : ( '' !== (string) $r['bambu_printer'] ? (string) $r['bambu_printer'] : __( 'ohne Drucker', 'lindenweg16' ) ), 'plates' => (int) $r['plates'], 'scrap' => (int) $r['scrap'] );
		}

		// Lager.
		$empty   = 0;
		$below   = 0;
		$bal     = \Lindenweg16\Modules\Betrieb\StockRepository::balances();
		foreach ( \Lindenweg16\Modules\Betrieb\StockRepository::targets() as $t ) {
			$have = isset( $bal[ (int) $t['article_id'] ][ (string) $t['color'] ]['lager'] ) ? (float) $bal[ (int) $t['article_id'] ][ (string) $t['color'] ]['lager'] : 0.0;
			if ( $have <= 0 ) {
				++$empty;
			} elseif ( $have < (float) $t['target_qty'] ) {
				++$below;
			}
		}

		// Nachkauf.
		$soon = array();
		$lost = 0;
		foreach ( Nachkauf::customers() as $c ) {
			if ( 'bald' === $c['state'] && $c['confidence'] >= 0.5 ) {
				$soon[] = $c['name'];
			} elseif ( 'verloren' === $c['state'] && ! $c['b2b'] ) {
				++$lost;
			}
		}

		// Geld in vier Wochen.
		$liq  = Liquiditaet::overview()->get_data();
		$cash = isset( $liq['rows'][3] ) ? (float) $liq['rows'][3]['balance'] : null;

		return array(
			'revenue'       => $now['totals']['revenue'],
			'revenue_prev'  => $then['totals']['revenue'],
			'orders'        => $count( $w['from'], $to ) + count( array_filter( $now['markets'], function ( $m ) { return $m['revenue'] > 0; } ) ),
			'orders_prev'   => $count( $prev['from'], $prev['to'] ),
			'best'          => array_slice( $best, 0, 3 ),
			'channel'       => $channels ? $channels[0] : null,
			'market'        => $markets ? $markets[0] : null,
			'rate'          => $now['totals']['rate_all'],
			'rate_prev'     => $then['totals']['rate_all'],
			'printers'      => $printers,
			'below_target'  => $below,
			'empty'         => $empty,
			'reorder'       => $soon,
			'lost'          => $lost,
			'cash_in4'      => $cash,
			'buffer'        => isset( $liq['buffer'] ) ? (float) $liq['buffer'] : 0.0,
			'worst_product' => $rated ? $rated[ count( $rated ) - 1 ] : null,
		);
	}

	/**
	 * Rückblick einer Woche.
	 *
	 * @param WP_REST_Request $request Anfrage (woche = 0 diese, 1 letzte …).
	 * @return WP_REST_Response
	 */
	public static function overview( WP_REST_Request $request ) {
		$back = max( 0, min( 12, (int) $request->get_param( 'woche' ) ) );
		$w    = self::week_of( Kit::add_days( current_time( 'Y-m-d' ), -7 * $back ) );
		$f    = self::facts( $w );

		return new WP_REST_Response( array_merge( Review::compose( $f ), array( 'week' => $w, 'facts' => $f, 'settings' => Kit::settings( 'rueckblick' ) ) ) );
	}

	/**
	 * Einstellungen.
	 *
	 * @param WP_REST_Request $request Anfrage {aktiv, stunde}.
	 * @return WP_REST_Response
	 */
	public static function save( WP_REST_Request $request ) {
		$in = Kit::input( $request );

		if ( isset( $in['stunde'] ) && ( ! is_numeric( $in['stunde'] ) || (int) $in['stunde'] < 0 || (int) $in['stunde'] > 23 ) ) {
			return Kit::fail( __( 'Uhrzeit bitte als volle Stunde (0–23).', 'lindenweg16' ) );
		}
		if ( array_key_exists( 'aktiv', $in ) ) {
			$in['aktiv'] = empty( $in['aktiv'] ) ? 0 : 1;
		}
		Kit::save_settings( 'rueckblick', $in );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Gespeichert.', 'lindenweg16' ) ) );
	}

	/**
	 * Mail bauen und verschicken.
	 *
	 * @param array $w Woche.
	 * @return bool
	 */
	protected static function deliver( array $w ) {
		$c   = Kit::company();
		$dg  = Kit::settings( 'digest' );
		$to  = is_email( (string) $dg['an'] ) ? (string) $dg['an'] : $c['email'];
		$url = \Lindenweg16\Erp\Subdomain::host() ? 'https://' . \Lindenweg16\Erp\Subdomain::host() . '/' : home_url( '/erp/' );
		$m   = Review::mail( Review::compose( self::facts( $w ) ), $c['name'], $w['label'], $url . '#/seite/rueckblick' );

		return is_email( $to ) && wp_mail( $to, $m['subject'], $m['body'] );
	}

	/**
	 * Jetzt per Mail schicken.
	 *
	 * @return WP_REST_Response
	 */
	public static function send_now() {
		return self::deliver( self::week_of( current_time( 'Y-m-d' ) ) )
			? new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Rückblick verschickt.', 'lindenweg16' ) ) )
			: Kit::fail( __( 'Die Mail konnte nicht verschickt werden. Bitte die Mail-Einstellungen und die Firmenadresse prüfen.', 'lindenweg16' ), 502 );
	}

	/**
	 * Stündlich: freitags zur eingestellten Stunde einmal verschicken.
	 */
	public static function maybe_send() {
		$s = Kit::settings( 'rueckblick' );

		if ( ! (int) $s['aktiv'] || 5 !== (int) current_time( 'N' ) || (int) current_time( 'G' ) !== (int) $s['stunde'] ) {
			return;
		}

		$w = self::week_of( current_time( 'Y-m-d' ) );
		if ( get_option( 'lw16_erp_rueckblick_gesendet' ) === $w['label'] ) {
			return;
		}
		update_option( 'lw16_erp_rueckblick_gesendet', $w['label'], false );
		self::deliver( $w );
	}
}
