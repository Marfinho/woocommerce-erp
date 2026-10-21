<?php
/**
 * ERP-Seite „Lohnabrechnungen“: aus den erfassten Zeiten je Person und Monat.
 *
 * Erreichbar über die Seite Löhne; dasselbe Recht (verwaltung.loehne). Die
 * Regeln stehen in Erp\Payroll, die Speicherung in Erp\Payslips.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Erp\Payroll;
use Lindenweg16\Erp\PayslipPdf;
use Lindenweg16\Erp\Payslips;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\TimeRepository;
use Lindenweg16\Modules\Betrieb\WageRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen der Lohnabrechnungen.
 */
class Lohn {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns   = RestApi::NAMESPACE_V1;
		$gate = $api->needs( array( 'verwaltung.loehne' ) );

		register_rest_route( $ns, '/erp/lohn/monat', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'month' ), 'permission_callback' => $gate ) );
		register_rest_route( $ns, '/erp/lohn/grenzen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'limits' ), 'permission_callback' => $gate ) );
		register_rest_route( $ns, '/erp/lohn/vorschau', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'preview' ), 'permission_callback' => $gate ) );
		register_rest_route( $ns, '/erp/lohn/abrechnung', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create' ), 'permission_callback' => $gate ) );
		register_rest_route( $ns, '/erp/lohn/abrechnung/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'slip' ), 'permission_callback' => $gate ) );
		register_rest_route( $ns, '/erp/lohn/abrechnung/(?P<id>\d+)/(?P<action>speichern|festschreiben|bezahlt|storno|loeschen)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'act' ), 'permission_callback' => $gate ) );
	}

	/**
	 * Antwort aus einem Ergebnis von Payslips.
	 *
	 * @param array $result {ok, message, id}.
	 * @return WP_REST_Response
	 */
	protected static function reply( array $result ) {
		return new WP_REST_Response( $result, $result['ok'] ? 200 : ( ! empty( $result['needs_confirm'] ) ? 409 : 422 ) );
	}

	/**
	 * Obergrenzen einer Person speichern.
	 *
	 * @param WP_REST_Request $request Anfrage (user_id, gross_month, hours_month, gross_year).
	 * @return WP_REST_Response
	 */
	public static function limits( WP_REST_Request $request ) {
		return self::reply(
			Payslips::set_limits(
				(int) $request->get_param( 'user_id' ),
				array(
					'gross_month' => $request->get_param( 'gross_month' ),
					'hours_month' => $request->get_param( 'hours_month' ),
					'gross_year'  => $request->get_param( 'gross_year' ),
				)
			)
		);
	}

	/**
	 * Eine Abrechnung für die Oberfläche.
	 *
	 * @param array $slip Abrechnung.
	 * @return array
	 */
	public static function slip_view( array $slip ) {
		$labels = Payslips::status_labels();

		return array(
			'id'          => $slip['id'],
			'number'      => (string) $slip['number'],
			'status'      => $slip['status'],
			'status_text' => $labels[ $slip['status'] ],
			'name'        => $slip['employee_name'],
			'von'         => $slip['period_von'],
			'bis'         => $slip['period_bis'],
			'hours'       => $slip['hours'],
			'gross'       => $slip['gross'],
			'adjustments' => $slip['adjustments'],
			'adj_total'   => $slip['adjustments_total'],
			'total'       => $slip['total'],
			'note'        => (string) $slip['note'],
			'paid_on'     => $slip['paid_on'],
			'void_reason' => (string) $slip['void_reason'],
			'drift'       => Payslips::drift( $slip ),
			'pdf'         => PayslipPdf::pdf_url( $slip['id'] ),
		);
	}

	/**
	 * Alle Personen eines Monats mit ihrer Abrechnung.
	 *
	 * @param WP_REST_Request $request Anfrage (monat).
	 * @return WP_REST_Response
	 */
	public static function month( WP_REST_Request $request ) {
		$month  = (string) $request->get_param( 'monat' );
		$month  = '' === $month ? gmdate( 'Y-m' ) : $month;
		$bounds = Payroll::month_bounds( $month );

		if ( ! $bounds ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Der Monat ist ungültig. Bitte im Format Jahr-Monat wählen, zum Beispiel 2026-10.', 'lindenweg16' ) ), 422 );
		}

		$entries = TimeRepository::between( $bounds['von'], $bounds['bis'] );
		$wages   = WageRepository::all();
		$slips   = Payslips::between( $bounds['von'], $bounds['bis'] );
		$ids     = array();

		foreach ( Roles::betrieb_users() as $user ) {
			$ids[ (int) $user->ID ] = $user;
		}

		foreach ( $entries as $entry ) {
			$uid = (int) $entry['created_by'];

			if ( $uid && ! isset( $ids[ $uid ] ) && get_userdata( $uid ) ) {
				$ids[ $uid ] = get_userdata( $uid );
			}
		}

		$people = array();
		$sum    = array( 'hours' => 0.0, 'gross' => 0.0, 'total' => 0.0 );

		foreach ( $ids as $uid => $user ) {
			$calc  = Payroll::build( $entries, $wages, $uid, $bounds['von'], $bounds['bis'] );
			$slip  = null;
			$voids = 0;

			foreach ( $slips as $candidate ) {
				if ( $candidate['user_id'] !== $uid ) {
					continue;
				}

				if ( Payslips::VOID === $candidate['status'] ) {
					++$voids;
					continue;
				}

				$slip = $candidate;
			}

			if ( ! $slip && $calc['hours'] <= 0 ) {
				continue;
			}

			if ( $slip && Payslips::VOID !== $slip['status'] ) {
				$sum['hours'] += $slip['hours'];
				$sum['gross'] += $slip['gross'];
				$sum['total'] += $slip['total'];
			}

			$checks = Payslips::check( $uid, $bounds['von'], $slip ? $slip['total'] : $calc['gross'], $slip ? $slip['hours'] : $calc['hours'], $slip ? $slip['id'] : 0 );

			$people[] = array(
				'limits'         => Payslips::limits( $uid ),
				'checks'         => $checks,
				'id'             => $uid,
				'name'           => $user->display_name,
				'email'          => $user->user_email,
				'rate'           => WageRepository::current( $uid, $bounds['bis'] ),
				'hours'          => $calc['hours'],
				'unpriced_hours' => $calc['unpriced_hours'],
				'gross'          => $calc['gross'],
				'slip'           => $slip ? self::slip_view( $slip ) : null,
				'voided'         => $voids,
			);
		}

		usort(
			$people,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return new WP_REST_Response(
			array(
				'month'  => $month,
				'label'  => $bounds['label'],
				'von'    => $bounds['von'],
				'bis'    => $bounds['bis'],
				'people' => $people,
				'totals' => array_map(
					static function ( $n ) {
						return round( $n, 2 );
					},
					$sum
				),
				'csv'    => PayslipPdf::csv_url( $month ),
			)
		);
	}

	/**
	 * Rechnung einer Person aus den aktuellen Zeiten, mit den Tagen.
	 *
	 * @param WP_REST_Request $request Anfrage (user_id, monat).
	 * @return WP_REST_Response
	 */
	public static function preview( WP_REST_Request $request ) {
		$bounds = Payroll::month_bounds( (string) $request->get_param( 'monat' ) );
		$user   = get_userdata( (int) $request->get_param( 'user_id' ) );

		if ( ! $bounds || ! $user ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Person oder Monat sind ungültig.', 'lindenweg16' ) ), 422 );
		}

		$labels = TimeRepository::category_labels();
		$calc   = Payslips::compute( $user->ID, $bounds['von'], $bounds['bis'] );

		foreach ( $calc['days'] as $i => $day ) {
			$calc['days'][ $i ]['category_text'] = isset( $labels[ $day['category'] ] ) ? $labels[ $day['category'] ] : $day['category'];
		}

		return new WP_REST_Response( array( 'name' => $user->display_name, 'label' => $bounds['label'], 'calc' => $calc, 'limits' => Payslips::limits( $user->ID ), 'year_before' => Payslips::year_before( $user->ID, $bounds['von'] ) ) );
	}

	/**
	 * Eine Abrechnung mit der Rechnung aus den heutigen Zeiten (für den Dialog).
	 *
	 * @param WP_REST_Request $request Anfrage (id).
	 * @return WP_REST_Response
	 */
	public static function slip( WP_REST_Request $request ) {
		$slip = Payslips::find( (int) $request['id'] );

		if ( ! $slip ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Diese Abrechnung gibt es nicht (mehr).', 'lindenweg16' ) ), 404 );
		}

		$labels = TimeRepository::category_labels();
		$days   = Payslips::DRAFT === $slip['status'] ? Payslips::compute( $slip['user_id'], $slip['period_von'], $slip['period_bis'] ) : array( 'days' => $slip['days'], 'lines' => $slip['lines'], 'gross' => $slip['gross'], 'hours' => $slip['hours'], 'unpriced_hours' => 0 );

		foreach ( $days['days'] as $i => $day ) {
			$days['days'][ $i ]['category_text'] = isset( $labels[ $day['category'] ] ) ? $labels[ $day['category'] ] : $day['category'];
		}

		return new WP_REST_Response( array( 'slip' => self::slip_view( $slip ), 'calc' => $days, 'limits' => Payslips::limits( $slip['user_id'] ), 'year_before' => Payslips::year_before( $slip['user_id'], $slip['period_von'], $slip['id'] ) ) );
	}

	/**
	 * Entwurf anlegen.
	 *
	 * @param WP_REST_Request $request Anfrage (user_id, monat, adjustments, note).
	 * @return WP_REST_Response
	 */
	public static function create( WP_REST_Request $request ) {
		return self::reply(
			Payslips::create(
				(int) $request->get_param( 'user_id' ),
				(string) $request->get_param( 'monat' ),
				$request->get_param( 'adjustments' ),
				(string) $request->get_param( 'note' )
			)
		);
	}

	/**
	 * Aktion auf einer Abrechnung.
	 *
	 * @param WP_REST_Request $request Anfrage (id, action).
	 * @return WP_REST_Response
	 */
	public static function act( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		switch ( (string) $request['action'] ) {
			case 'speichern':
				return self::reply( Payslips::update( $id, $request->get_param( 'adjustments' ), (string) $request->get_param( 'note' ) ) );
			case 'festschreiben':
				return self::reply( Payslips::finalize( $id, (bool) $request->get_param( 'force' ) ) );
			case 'bezahlt':
				return self::reply( Payslips::mark_paid( $id, (string) $request->get_param( 'paid_on' ) ) );
			case 'storno':
				return self::reply( Payslips::void( $id, (string) $request->get_param( 'reason' ) ) );
			default:
				return self::reply( Payslips::delete_draft( $id ) );
		}
	}
}
