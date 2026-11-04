<?php
/**
 * ERP-Seite „Affiliates“: Influencer mit eigenem Rabattcode.
 *
 * Ein Partner bekommt einen Code (ein WooCommerce-Gutschein in Prozent), der Kunde
 * spart den Rabatt, der Partner bekommt Provision auf den Warenwert. Die Seite zeigt
 * Bestellungen, Umsatz, Provision und das auszahlbare Guthaben je Partner und hält
 * Auszahlungen fest. Recht: `verkauf.affiliates` (WordPress-Recht `manage_woocommerce`),
 * Auszahlungen (und ihr Storno) zusätzlich `verkauf.affiliates.auszahlen`.
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
use Lindenweg16\Modules\Accounting\BelegRepository;
use Lindenweg16\Modules\Affiliate\AutoApply;
use Lindenweg16\Modules\Affiliate\Booking;
use Lindenweg16\Modules\Affiliate\Partners;
use Lindenweg16\Modules\Affiliate\Rules;
use Lindenweg16\Modules\Affiliate\Sales;
use Lindenweg16\Modules\Affiliate\Welcome;
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

		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/affiliates/storno',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cancel' ),
				'permission_callback' => $api->needs( array( 'verkauf.affiliates', 'verkauf.affiliates.auszahlen' ) ),
			)
		);

		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/affiliates/ablehnen',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'decline' ),
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

			$konto = $p['user_id'] ? get_userdata( $p['user_id'] ) : false;

			$list[] = array(
				'konto'     => $konto ? $konto->user_email : '',
				'id'        => $id,
				'name'      => $p['name'],
				'plattform' => $p['plattform'],
				'email'     => $p['email'],
				'code'      => $p['code'],
				'link'      => AutoApply::link( $p['code'] ),
				'willkommen' => $p['willkommen_ts'] ? wp_date( 'Y-m-d', (int) $p['willkommen_ts'] ) : '',
				'gesamt'    => (float) $p['gesamt'],
				'teilen'    => (bool) $p['teilen'],
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

			$beleg = ! empty( $row['beleg'] ) ? BelegRepository::get( (int) $row['beleg'] ) : null;

			$pay[] = array(
				'id'        => (int) $row['id'],
				'datum'     => $row['datum'],
				'partner'   => isset( $partners[ $row['partner'] ] ) ? $partners[ $row['partner'] ]['name'] : '–',
				'betrag'    => (float) $row['betrag'],
				'notiz'     => $row['notiz'],
				'beleg'     => $beleg && ! empty( $beleg->belegnummer ) ? $beleg->belegnummer : '',
				'storniert' => ! empty( $row['storniert'] ),
				'grund'     => isset( $row['grund'] ) ? $row['grund'] : '',
			);
		}

		$requests = array();
		foreach ( Partners::requests() as $r ) {
			if ( 'offen' === $r['status'] && isset( $partners[ $r['partner'] ] ) ) {
				$requests[] = array(
					'partner' => (int) $r['partner'],
					'name'    => $partners[ $r['partner'] ]['name'],
					'betrag'  => (float) $r['betrag'],
					'ziel'    => $r['ziel'],
					'datum'   => wp_date( 'Y-m-d', $r['ts'] ),
				);
			}
		}

		foreach ( $total as $k => $v ) {
			$total[ $k ] = is_float( $v ) ? round( $v, 2 ) : $v;
		}

		return new WP_REST_Response(
			array(
				'partners' => $list,
				'summe'    => $total,
				'payouts'  => $pay,
				'requests' => $requests,
				'days'     => $days,
				'ranges'   => Rules::ranges(),
				'wartezeit' => self::wait_days(),
				'can_pay'  => Access::can( 'verkauf.affiliates.auszahlen' ),
				'buchen'   => Booking::enabled(),
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

		$konto = array();
		if ( array_key_exists( 'konto_email', $in ) ) {
			$konto = array(
				'email'   => is_string( $in['konto_email'] ) ? sanitize_email( $in['konto_email'] ) : '',
				'anlegen' => ! empty( $in['konto_anlegen'] ) && 'false' !== $in['konto_anlegen'],
			);
		}

		$result = $id > 0 ? Partners::update( $id, $in, $konto ) : Partners::create( $in, $konto );

		if ( ! $result['ok'] ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => self::error_message( $result['errors'] ),
				),
				422
			);
		}

		$saved   = $id > 0 ? $id : $result['id'];
		$message = $id > 0 ? __( 'Gespeichert.', 'lindenweg16' ) : __( 'Affiliate angelegt. Der Rabattcode gilt ab sofort im Shop.', 'lindenweg16' );
		AuditLog::record( $id > 0 ? 'affiliate_geaendert' : 'affiliate_angelegt', 'affiliate', $saved );

		// Willkommensmail, wenn gewünscht.
		if ( ! empty( $in['willkommen'] ) && 'false' !== $in['willkommen'] ) {
			$p = Partners::get( $saved );

			if ( $p && Welcome::send( $p, ! empty( $result['konto_neu'] ) ) ) {
				AuditLog::record( 'affiliate_willkommen', 'affiliate', $saved );
				$message .= ' ' . __( 'Die Willkommensmail ist raus.', 'lindenweg16' );
			} elseif ( $p ) {
				$message .= ' ' . __( 'Die Willkommensmail konnte nicht gesendet werden: Es ist keine gültige E-Mail-Adresse hinterlegt (Kontaktadresse oder Konto).', 'lindenweg16' );
			}
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => $message,
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
		$check   = Rules::check_payout( $request->get_param( 'betrag' ), $figures[ $id ]['guthaben'] );

		if ( $check['errors'] ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => implode( ' ', $check['errors'] ) ), 422 );
		}

		$beleg = Booking::enabled() ? Booking::book( $partners[ $id ], $check['betrag'], $datum ) : 0;
		$row   = Partners::add_payout( $id, $check['betrag'], $datum, $notiz, $beleg );
		Partners::close_requests( $id, 'erledigt' );
		AuditLog::record( 'affiliate_auszahlung', 'affiliate', $id, array( 'betrag' => $row['betrag'], 'datum' => $datum, 'beleg' => $beleg ) );

		if ( $beleg ) {
			$message = __( 'Auszahlung festgehalten und als Beleg gebucht. Die Überweisung machst du selbst bei deiner Bank.', 'lindenweg16' );
		} elseif ( Booking::enabled() ) {
			$message = __( 'Auszahlung festgehalten, aber nicht gebucht – der Beleg konnte nicht angelegt werden. Bitte von Hand buchen.', 'lindenweg16' );
		} else {
			$message = __( 'Auszahlung festgehalten. Gebucht wird sie nicht (Einstellung); die Überweisung machst du selbst bei deiner Bank.', 'lindenweg16' );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => $message,
			)
		);
	}

	/**
	 * Eine offene Anforderung ablehnen. Die Begründung sieht der Ambassador unter „Mein Konto“.
	 *
	 * @param WP_REST_Request $request Anfrage (partner, notiz).
	 * @return WP_REST_Response
	 */
	public static function decline( WP_REST_Request $request ) {
		$id    = (int) $request->get_param( 'partner' );
		$notiz = sanitize_text_field( (string) $request->get_param( 'notiz' ) );

		if ( ! Partners::open_request( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Es liegt keine offene Anforderung vor. Bitte die Seite neu laden.', 'lindenweg16' ) ), 422 );
		}

		if ( '' === $notiz ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Bitte kurz begründen, warum nicht ausgezahlt wird. Der Ambassador sieht den Text.', 'lindenweg16' ) ), 422 );
		}

		Partners::close_requests( $id, 'abgelehnt', $notiz );
		AuditLog::record( 'affiliate_anforderung_abgelehnt', 'affiliate', $id );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Anforderung abgelehnt.', 'lindenweg16' ) ) );
	}

	/**
	 * Eine Auszahlung stornieren: Der Beleg bekommt einen Gegenbeleg, das Guthaben steigt wieder.
	 *
	 * @param WP_REST_Request $request Anfrage (auszahlung, grund).
	 * @return WP_REST_Response
	 */
	public static function cancel( WP_REST_Request $request ) {
		$pid   = (int) $request->get_param( 'auszahlung' );
		$grund = sanitize_text_field( (string) $request->get_param( 'grund' ) );

		if ( '' === $grund ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Bitte kurz begründen, warum die Auszahlung storniert wird.', 'lindenweg16' ) ), 422 );
		}

		$row = Partners::cancel_payout( $pid, $grund );

		if ( ! $row ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Diese Auszahlung gibt es nicht oder sie ist schon storniert. Bitte die Seite neu laden.', 'lindenweg16' ) ), 422 );
		}

		$p = Partners::get( $row['partner'] );
		if ( $p && ! empty( $row['beleg'] ) ) {
			Booking::cancel( $p, (int) $row['beleg'], $grund );
		}

		AuditLog::record( 'affiliate_auszahlung_storniert', 'affiliate', (int) $row['partner'], array( 'betrag' => $row['betrag'], 'grund' => $grund ) );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => ! empty( $row['beleg'] )
					? __( 'Auszahlung storniert, der Beleg ist mit einem Gegenbeleg zurückgenommen.', 'lindenweg16' )
					: __( 'Auszahlung storniert. Sie war nicht gebucht, in der Buchhaltung ändert sich nichts.', 'lindenweg16' ),
			)
		);
	}
}
