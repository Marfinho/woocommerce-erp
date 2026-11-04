<?php
/**
 * ERP-Seite „Affiliates“: Influencer mit eigenem Rabattcode.
 *
 * Ein Partner bekommt einen Code (ein WooCommerce-Gutschein in Prozent), der Kunde
 * spart den Rabatt, der Partner bekommt Provision auf den Warenwert. Die Seite zeigt
 * Bestellungen, Umsatz, Provision und das auszahlbare Guthaben je Partner und hält
 * Auszahlungen fest. Recht: `verkauf.affiliates` (WordPress-Recht `manage_woocommerce`),
 * Auszahlungen zusätzlich `verkauf.affiliates.auszahlen`.
 *
 * Regeln stehen in `Modules\Affiliate\Rules`, Speicher in `Partners`, Bestellungen in `Sales`.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Settings;
use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Affiliate\Partners;
use Lindenweg16\Modules\Affiliate\Rules;
use Lindenweg16\Modules\Affiliate\Sales;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Affiliates in der neuen Oberfläche.
 */
class Affiliates {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$needs = $api->needs( array( 'verkauf.affiliates' ) );

		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/affiliates',
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

		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/affiliates/auszahlung',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'payout' ),
				'permission_callback' => $api->needs( array( 'verkauf.affiliates', 'verkauf.affiliates.auszahlen' ) ),
			)
		);
	}

	/**
	 * Datum Y-m-d prüfen: ein echtes Datum, nicht in der Zukunft.
	 *
	 * @param mixed  $raw   Eingabe.
	 * @param string $today Heute als Y-m-d.
	 * @return string Das Datum oder leer.
	 */
	public static function clean_date( $raw, $today ) {
		if ( ! is_string( $raw ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) ) {
			return '';
		}

		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || $raw > $today ) {
			return '';
		}

		return $raw;
	}

	/**
	 * Fehlerliste zu einer Meldung zusammenfassen.
	 *
	 * @param array $errors Meldungen.
	 * @return string
	 */
	public static function error_message( array $errors ) {
		return __( 'Nichts gespeichert. ', 'lindenweg16' ) . implode( ' ', $errors );
	}

	/**
	 * Wartezeit der Provision in Tagen.
	 *
	 * @return int
	 */
	protected static function wait_days() {
		return max( 0, (int) Settings::get( 'affiliate_wartezeit_tage', 30 ) );
	}

	/**
	 * Zahlen aller Partner (Zeitraum und Gesamtstand).
	 *
	 * @param array $partners Partner.
	 * @param int   $days     Zeitraum.
	 * @return array Id => Kennzahlen.
	 */
	protected static function figures( array $partners, $days ) {
		$since = '';
		foreach ( $partners as $p ) {
			if ( '' === $since || $p['angelegt'] < $since ) {
				$since = $p['angelegt'];
			}
		}

		return Rules::summarize(
			$partners,
			Sales::collect( $partners, $since ),
			Partners::payouts(),
			time(),
			self::wait_days(),
			$days
		);
	}

	/**
	 * Partner, Zahlen und Auszahlungen.
	 *
	 * @param WP_REST_Request $request Anfrage (tage).
	 * @return WP_REST_Response
	 */
	public static function get( WP_REST_Request $request ) {
		$days     = Rules::clean_days( $request->get_param( 'tage' ) );
		$partners = Partners::all();
		$figures  = self::figures( $partners, $days );

		$list  = array();
		$total = array(
			'anzahl'    => 0,
			'umsatz'    => 0.0,
			'provision' => 0.0,
			'guthaben'  => 0.0,
			'wartend'   => 0.0,
		);

		foreach ( $partners as $id => $p ) {
			$f = $figures[ $id ];

			$total['anzahl']    += $f['anzahl'];
			$total['umsatz']    += $f['umsatz'];
			$total['provision'] += $f['provision'];
			$total['wartend']   += $f['wartend'];
			$total['guthaben']  += max( 0.0, $f['guthaben'] );

			$list[] = array(
				'id'        => $id,
				'name'      => $p['name'],
				'plattform' => $p['plattform'],
				'email'     => $p['email'],
				'code'      => $p['code'],
				'rabatt'    => (float) $p['rabatt'],
				'provision' => (float) $p['provision'],
				'aktiv'     => (bool) $p['aktiv'],
				'notiz'     => $p['notiz'],
				'angelegt'  => $p['angelegt'],
				'stats'     => $f,
				'letzte'    => $f['letzte_ts'] ? wp_date( 'Y-m-d', $f['letzte_ts'] ) : '',
			);
		}

		// Aktive zuerst, dann nach Provision im Zeitraum.
		usort(
			$list,
			function ( $a, $b ) {
				if ( $a['aktiv'] !== $b['aktiv'] ) {
					return $a['aktiv'] ? -1 : 1;
				}

				return $b['stats']['provision'] <=> $a['stats']['provision'];
			}
		);

		$pay = array();
		foreach ( array_reverse( Partners::payouts() ) as $row ) {
			if ( count( $pay ) >= 100 ) {
				break;
			}

			$pay[] = array(
				'datum'   => $row['datum'],
				'partner' => isset( $partners[ $row['partner'] ] ) ? $partners[ $row['partner'] ]['name'] : '–',
				'betrag'  => (float) $row['betrag'],
				'notiz'   => $row['notiz'],
			);
		}

		foreach ( $total as $k => $v ) {
			$total[ $k ] = is_float( $v ) ? round( $v, 2 ) : $v;
		}

		return new WP_REST_Response(
			array(
				'partners' => $list,
				'summe'    => $total,
				'payouts'  => $pay,
				'days'     => $days,
				'ranges'   => Rules::ranges(),
				'wartezeit' => self::wait_days(),
				'can_pay'  => Access::can( 'verkauf.affiliates.auszahlen' ),
				'today'    => wp_date( 'Y-m-d' ),
				'sample'   => home_url( '/' ),
			)
		);
	}

	/**
	 * Partner anlegen (ohne `id`) oder ändern (mit `id`).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function save( WP_REST_Request $request ) {
		$in = $request->get_json_params();
		$in = is_array( $in ) ? $in : $request->get_params();
		$id = isset( $in['id'] ) ? (int) $in['id'] : 0;

		foreach ( array( 'name', 'plattform', 'email', 'code' ) as $key ) {
			if ( isset( $in[ $key ] ) && is_string( $in[ $key ] ) ) {
				$in[ $key ] = 'email' === $key ? sanitize_email( $in[ $key ] ) : sanitize_text_field( $in[ $key ] );
			}
		}
		if ( isset( $in['notiz'] ) && is_string( $in['notiz'] ) ) {
			$in['notiz'] = sanitize_textarea_field( $in['notiz'] );
		}

		if ( ! class_exists( '\WC_Coupon' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'WooCommerce ist nicht aktiv – ohne den Shop gibt es keine Rabattcodes.', 'lindenweg16' ) ), 422 );
		}

		$result = $id > 0 ? Partners::update( $id, $in ) : Partners::create( $in );

		if ( ! $result['ok'] ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => self::error_message( $result['errors'] ),
				),
				422
			);
		}

		$saved = $id > 0 ? $id : $result['id'];
		AuditLog::record( $id > 0 ? 'affiliate_geaendert' : 'affiliate_angelegt', 'affiliate', $saved );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => $id > 0 ? __( 'Gespeichert.', 'lindenweg16' ) : __( 'Affiliate angelegt. Der Rabattcode gilt ab sofort im Shop.', 'lindenweg16' ),
			)
		);
	}

	/**
	 * Eine Auszahlung festhalten.
	 *
	 * @param WP_REST_Request $request Anfrage (partner, betrag, datum, notiz).
	 * @return WP_REST_Response
	 */
	public static function payout( WP_REST_Request $request ) {
		$partners = Partners::all();
		$id       = (int) $request->get_param( 'partner' );

		if ( ! isset( $partners[ $id ] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Diesen Affiliate gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ) ), 422 );
		}

		$today = wp_date( 'Y-m-d' );
		$datum = self::clean_date( (string) $request->get_param( 'datum' ), $today );
		$notiz = sanitize_text_field( (string) $request->get_param( 'notiz' ) );

		if ( '' === $datum ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Bitte ein gültiges Datum eintragen, nicht in der Zukunft.', 'lindenweg16' ) ), 422 );
		}

		$figures = self::figures( array( $id => $partners[ $id ] ), 0 );
		$check   = Rules::check_payout( $request->get_param( 'betrag' ), $figures[ $id ]['guthaben'], $notiz );

		if ( $check['errors'] ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => implode( ' ', $check['errors'] ) ), 422 );
		}

		$row = Partners::add_payout( $id, $check['betrag'], $datum, $notiz );
		AuditLog::record( 'affiliate_auszahlung', 'affiliate', $id, array( 'betrag' => $row['betrag'], 'datum' => $datum ) );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Auszahlung festgehalten. Die Überweisung machst du selbst bei deiner Bank.', 'lindenweg16' ),
			)
		);
	}
}
