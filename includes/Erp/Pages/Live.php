<?php
/**
 * ERP-Seite „Live“: ein Dashboard für den Vollbildschirm.
 *
 * Eine Anfrage liefert, was der Person nach ihren Rechten gezeigt werden darf:
 * Verkauf heute, Kennzahlen gegen ihre Ziele, Produktion und Drucker,
 * Kampagnen, Seitenaufrufe der Website. Die Zahlen kommen aus den Quellen der
 * übrigen Seiten (Reporting, KampagnenReport, Drucker, Traffic); hier steht
 * nur das Zusammenstellen und ein kurzer Zwischenspeicher, damit ein Bildschirm,
 * der alle 15 Sekunden fragt, die Datenbank nicht belastet.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\LiveViewer;
use Lindenweg16\Erp\Material;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Erp\RunFlow;
use Lindenweg16\Erp\RunRepository;
use Lindenweg16\Erp\Screens;
use Lindenweg16\Erp\Traffic;
use Lindenweg16\Modules\ArView\Stats;
use Lindenweg16\Modules\Betrieb\Kpi;
use Lindenweg16\Modules\Betrieb\KpiGoalRepository;
use Lindenweg16\Modules\Betrieb\KpiMeter;
use Lindenweg16\Modules\Betrieb\Reporting;
use Lindenweg16\Modules\Marketing\KampagnenReport;
use Lindenweg16\Modules\Marketing\KampagnenRepository;
use WP_REST_Response;

/**
 * Route und Zusammenstellung.
 */
class Live {

	/**
	 * Kennzahlen auf dem Bildschirm, in dieser Reihenfolge.
	 *
	 * Ohne die personenbezogenen (Wochenstunden, Bedienzeit): Der Bildschirm hängt im Raum.
	 */
	const KPIS = array( 'umsatz', 'bestellungen', 'warenkorb', 'auslastung', 'ausschussquote', 'handlingzeit', 'reklamationsquote', 'wiederkaufsrate' );

	/**
	 * Route anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/live',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'live' ),
				'permission_callback' => array( LiveViewer::class, 'can_open' ),
			)
		);
	}

	/**
	 * Zwischenspeicher: gleiche Werte für alle, kurz gehalten.
	 *
	 * @param string   $key     Schlüssel.
	 * @param int      $seconds Dauer.
	 * @param callable $build   Erzeugt den Wert.
	 * @return mixed
	 */
	protected static function cached( $key, $seconds, $build ) {
		$hit = get_transient( 'lw16_live_' . $key );

		if ( false !== $hit ) {
			return $hit;
		}

		$value = call_user_func( $build );
		set_transient( 'lw16_live_' . $key, $value, $seconds );

		return $value;
	}

	/**
	 * Alle Blöcke, die diese Person sehen darf.
	 *
	 * @return WP_REST_Response
	 */
	public static function live() {
		$blocks = array();

		// Die Live-Anzeige ist für den Bildschirm da und zeigt alle Blöcke.
		$all = LiveViewer::is_viewer();

		if ( $all || Access::can( 'verkauf.orders' ) || Access::can( 'finanzen.dashboard' ) ) {
			$blocks['sales'] = self::cached( 'sales', 30, array( __CLASS__, 'sales' ) );
		}

		if ( $all || Access::can( 'finanzen.berichte' ) ) {
			$blocks['kpis']    = self::cached( 'kpis', 120, array( __CLASS__, 'kpis' ) );
			$blocks['traffic'] = self::traffic();
		}

		if ( $all || Access::can( 'fertigung.workflow' ) ) {
			$blocks['production'] = self::production();
		}

		if ( $all || Access::can( 'verkauf.marketing' ) ) {
			$blocks['campaigns'] = self::cached( 'campaigns', 300, array( __CLASS__, 'campaigns' ) );
		}

		return new WP_REST_Response( array( 'now' => time(), 'shop' => get_bloginfo( 'name' ), 'blocks' => $blocks ) );
	}

	/**
	 * Verkauf heute: Umsatz, Bestellungen, Verlauf nach Stunde, letzte Bestellungen.
	 *
	 * @return array
	 */
	public static function sales() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( 'available' => false );
		}

		$tz       = wp_timezone();
		$today    = new \DateTimeImmutable( 'today', $tz );
		$yday     = $today->modify( '-1 day' );
		$statuses = array( 'wc-processing', 'wc-on-hold', 'wc-completed' );
		$orders   = (array) wc_get_orders(
			array(
				'status'       => $statuses,
				'date_created' => $yday->getTimestamp() . '...' . ( $today->getTimestamp() + DAY_IN_SECONDS ),
				'limit'        => -1,
				'return'       => 'objects',
			)
		);

		$sum   = array( 'today' => 0.0, 'yday' => 0.0 );
		$count = array( 'today' => 0, 'yday' => 0 );
		$hours = array_fill( 0, 24, 0.0 );
		$last  = array();

		foreach ( $orders as $order ) {
			$created = $order->get_date_created();

			if ( ! $created ) {
				continue;
			}

			$ts    = $created->getTimestamp();
			$total = (float) $order->get_total();
			$key   = $ts >= $today->getTimestamp() ? 'today' : 'yday';

			$sum[ $key ]   += $total;
			$count[ $key ] += 1;

			if ( 'today' === $key ) {
				$hours[ (int) $created->setTimezone( $tz )->format( 'G' ) ] += $total;
			}

			$last[] = array( 'ts' => $ts, 'number' => (string) $order->get_order_number(), 'total' => $total );
		}

		usort(
			$last,
			static function ( $a, $b ) {
				return $b['ts'] <=> $a['ts'];
			}
		);

		return array(
			'available'   => true,
			'revenue'     => round( $sum['today'], 2 ),
			'revenue_yday' => round( $sum['yday'], 2 ),
			'orders'      => $count['today'],
			'orders_yday' => $count['yday'],
			'basket'      => $count['today'] ? round( $sum['today'] / $count['today'], 2 ) : null,
			'to_ship'     => function_exists( 'wc_orders_count' ) ? (int) wc_orders_count( 'processing' ) + (int) wc_orders_count( 'on-hold' ) : 0,
			'hours'       => array_map(
				static function ( $v ) {
					return round( $v, 2 );
				},
				$hours
			),
			'hour_now'    => (int) wp_date( 'G' ),
			'last'        => array_slice( $last, 0, 6 ),
		);
	}

	/**
	 * Kennzahlen des Monats gegen ihre Ziele.
	 *
	 * @return array
	 */
	public static function kpis() {
		$von         = gmdate( 'Y-m-01' );
		$bis         = gmdate( 'Y-m-d' );
		$zeitraum    = array( 'von' => $von, 'bis' => $bis );
		$metrics     = Reporting::metrics( $von, $bis );
		$definitions = Kpi::definitions();
		$goals       = KpiGoalRepository::all();
		$rows        = array();

		foreach ( self::KPIS as $key ) {
			if ( ! isset( $definitions[ $key ] ) ) {
				continue;
			}

			$value   = isset( $metrics[ $key ] ) ? $metrics[ $key ] : null;
			$limits  = Reporting::limits( $key, $zeitraum, isset( $goals[ $key ] ) ? $goals[ $key ] : array() );
			$eval    = Kpi::evaluate_against( $key, $value, $limits['target'], $limits['alarm'] );
			$rows[]  = array(
				'key'    => $key,
				'label'  => $definitions[ $key ]['label'],
				'text'   => Kpi::format( $key, $value ),
				'target' => null === $limits['target'] ? '' : Kpi::format( $key, $limits['target'] ),
				'level'  => $eval['level'],
				'meter'  => KpiMeter::scale( $value, $limits['target'], $limits['alarm'] ),
			);
		}

		return array( 'label' => wp_date( 'F Y' ), 'rows' => $rows );
	}

	/**
	 * Produktion: Platten nach Stand, Drucker, knappes Filament.
	 *
	 * @return array
	 */
	public static function production() {
		$counts = array( 'freigegeben' => 0, 'im_druck' => 0, 'zu_melden' => 0 );

		foreach ( RunRepository::active() as $run ) {
			$col = RunFlow::column( $run['status'] );

			if ( isset( $counts[ $col ] ) ) {
				++$counts[ $col ];
			}
		}

		$printers = array();

		foreach ( Screens::printer_farm() as $p ) {
			$printers[] = array(
				'name'      => $p['name'],
				'status'    => $p['status'],
				'text'      => $p['text'],
				'online'    => $p['online'],
				'progress'  => $p['progress'],
				'remaining' => $p['remaining'],
			);
		}

		$alerts = array();

		foreach ( Material::alerts() as $a ) {
			$alerts[] = array( 'label' => $a['label'], 'days' => null === $a['coverage_days'] ? null : round( $a['coverage_days'] ), 'threshold' => $a['threshold_days'] );
		}

		return array( 'runs' => $counts, 'printers' => $printers, 'alerts' => $alerts );
	}

	/**
	 * Laufende Kampagnen mit Kosten, Umsatz und Ertrag je eingesetztem Euro.
	 *
	 * @return array
	 */
	public static function campaigns() {
		$rows = array();

		foreach ( KampagnenRepository::all( array( 'status' => 'aktiv', 'limit' => 8 ) ) as $k ) {
			$r      = KampagnenReport::report( $k );
			$rows[] = array(
				'name'    => (string) $k->name,
				'channel' => (string) $k->kanal,
				'cost'    => round( (float) $r['kosten'], 2 ),
				'revenue' => round( (float) $r['umsatz'], 2 ),
				'roas'    => isset( $r['roas'] ) && null !== $r['roas'] ? round( (float) $r['roas'], 2 ) : null,
				'orders'  => (int) $r['shop']['anzahl'],
				'budget'  => $r['budget']['auslastung'],
				'light'   => $r['budget']['ampel'],
			);
		}

		return array( 'rows' => $rows );
	}

	/**
	 * Seitenaufrufe der Website und Aufrufe der AR-Ansicht.
	 *
	 * @return array
	 */
	public static function traffic() {
		$snap = Traffic::snapshot();
		$ar   = 0;

		foreach ( Stats::totals( 1 ) as $row ) {
			$ar += (int) $row['shown'];
		}

		$snap['ar_today'] = $ar;

		return $snap;
	}
}
