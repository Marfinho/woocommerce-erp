<?php
/**
 * ERP-Seite „Nachkauf“: Wer bestellt bald wieder – und wer bleibt weg?
 *
 * Händler und Firmen (Aufträge mit Kontakt) und Stammkunden aus dem Shop
 * (mindestens zwei Bestellungen mit derselben Mailadresse). Für jeden der
 * Rhythmus (Erp\Rhythm), die Lieblingsartikel und ein Mailvorschlag – bei
 * Händlern mit frischem Nachbestell-Link.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Erp\Rhythm;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\ContactRepository;
use Lindenweg16\Modules\Betrieb\OrderOpsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Nachkauf.
 */
class Nachkauf {

	/**
	 * Merker: wann wer zuletzt angeschrieben wurde, und wer bis wann ruht.
	 */
	const OPTION = 'lw16_erp_nachkauf';

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns    = RestApi::NAMESPACE_V1;
		$needs = $api->needs( array( 'verkauf.nachkauf' ) );

		register_rest_route( $ns, '/erp/nachkauf', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/nachkauf/(?P<key>[bc]-[a-z0-9]+)/mail', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'draft' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/nachkauf/(?P<key>[bc]-[a-z0-9]+)/senden', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'send' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/nachkauf/(?P<key>[bc]-[a-z0-9]+)/ruhe', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'snooze' ), 'permission_callback' => $needs ) );
	}

	/**
	 * Alle Kunden mit erkennbarem Rhythmus (eine Stunde zwischengespeichert).
	 *
	 * @param bool $fresh Neu rechnen.
	 * @return array
	 */
	public static function customers( $fresh = false ) {
		$cached = get_transient( 'lw16_erp_nachkauf_liste' );
		if ( false !== $cached && ! $fresh ) {
			return $cached;
		}

		$today    = current_time( 'Y-m-d' );
		$articles = ArticleRepository::all_by_id();
		// Die Variante entscheidet den Artikel (Typ, Holz).
		$vctx  = \Lindenweg16\Modules\Betrieb\Variants::context( $articles, \Lindenweg16\Modules\Betrieb\BomRepository::all_by_parent() );
		$label = function ( $aid, $fallback ) use ( $articles ) {
			return $aid && isset( $articles[ $aid ] ) ? (string) $articles[ $aid ]['name'] : (string) $fallback;
		};

		$groups      = array();
		$wc_contacts = array();

		// Händler, Firmen, Vereine: Aufträge mit Kontakt.
		foreach ( OrderOpsRepository::all( array( 'limit' => 5000 ) ) as $op ) {
			if ( 'bestaetigt' !== $op['order_status'] || ! (int) $op['contact_id'] ) {
				continue;
			}
			$key = 'b-' . (int) $op['contact_id'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'dates' => array(), 'items' => array(), 'value' => 0.0 );
			}
			$groups[ $key ]['dates'][] = substr( (string) OrderOpsRepository::order_date( $op ), 0, 10 );
			$groups[ $key ]['value']  += OrderOpsRepository::order_value( $op );
			if ( (int) $op['wc_order_id'] ) {
				$wc_contacts[ (int) $op['wc_order_id'] ] = true;
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $op['wc_order_id'] ) : null;
				if ( $order ) {
					foreach ( $order->get_items() as $it ) {
						$aid                       = \Lindenweg16\Modules\Betrieb\OrderLines::article_for_item( $it, $vctx );
						$groups[ $key ]['items'][] = array( 'article_id' => $aid, 'label' => $label( $aid, $it->get_name() ), 'qty' => (float) $it->get_quantity() );
					}
				}
			} else {
				foreach ( OrderOpsRepository::items( (int) $op['id'] ) as $it ) {
					$groups[ $key ]['items'][] = array( 'article_id' => (int) $it['article_id'], 'label' => $label( (int) $it['article_id'], '#' . (int) $it['article_id'] ), 'qty' => (float) $it['qty'] );
				}
			}
		}

		// Stammkunden aus dem Shop: nach Mailadresse.
		$people = array();
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = (array) wc_get_orders(
				array(
					'status'       => array( 'wc-completed', 'wc-processing' ),
					'date_created' => '>' . ( time() - 3 * 365 * DAY_IN_SECONDS ),
					'limit'        => 3000,
					'return'       => 'objects',
				)
			);
			foreach ( $orders as $order ) {
				if ( isset( $wc_contacts[ (int) $order->get_id() ] ) ) {
					continue;
				}
				$mail = strtolower( trim( (string) $order->get_billing_email() ) );
				if ( ! is_email( $mail ) ) {
					continue;
				}
				$key = 'c-' . substr( md5( $mail ), 0, 16 );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array( 'dates' => array(), 'items' => array(), 'value' => 0.0 );
					$people[ $key ] = array( 'email' => $mail, 'name' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) );
				}
				$groups[ $key ]['dates'][] = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '';
				$groups[ $key ]['value']  += (float) $order->get_total();
				foreach ( $order->get_items() as $it ) {
					$aid                       = \Lindenweg16\Modules\Betrieb\OrderLines::article_for_item( $it, $vctx );
					$groups[ $key ]['items'][] = array( 'article_id' => $aid, 'label' => $label( $aid, $it->get_name() ), 'qty' => (float) $it->get_quantity() );
				}
			}
		}

		$out = array();
		foreach ( $groups as $key => $g ) {
			$r = Rhythm::analyse( $g['dates'], $today );
			if ( ! $r ) {
				continue;
			}
			if ( 'b' === $key[0] ) {
				$contact = ContactRepository::find( (int) substr( $key, 2 ) );
				if ( ! $contact ) {
					continue;
				}
				$profile = ContactRepository::profile( (int) $contact['id'] );
				$who     = array(
					'name'       => (string) $contact['company'],
					'person'     => (string) $contact['contact_person'],
					'email'      => $profile && is_email( (string) $profile['billing_email'] ) ? (string) $profile['billing_email'] : (string) $contact['email'],
					'contact_id' => (int) $contact['id'],
					'b2b'        => true,
				);
			} else {
				$who = array( 'name' => $people[ $key ]['name'] ? $people[ $key ]['name'] : $people[ $key ]['email'], 'person' => $people[ $key ]['name'], 'email' => $people[ $key ]['email'], 'contact_id' => 0, 'b2b' => false );
			}
			$out[] = array_merge(
				$who,
				$r,
				array(
					'key'        => $key,
					'favourites' => Rhythm::favourites( $g['items'] ),
					'value'      => round( $g['value'], 2 ),
					'avg'        => round( $g['value'] / max( 1, $r['orders'] ), 2 ),
				)
			);
		}

		set_transient( 'lw16_erp_nachkauf_liste', $out, HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * Merker lesen.
	 *
	 * @return array key => {mailed, snooze}
	 */
	protected static function marks() {
		$m = get_option( self::OPTION, array() );

		return is_array( $m ) ? $m : array();
	}

	/**
	 * Übersicht.
	 *
	 * @param WP_REST_Request $request Anfrage (neu).
	 * @return WP_REST_Response
	 */
	public static function overview( WP_REST_Request $request ) {
		$rows  = self::customers( (bool) $request->get_param( 'neu' ) );
		$marks = self::marks();
		$today = current_time( 'Y-m-d' );
		$order = array( 'bald' => 0, 'ueberfaellig' => 1, 'verloren' => 2, 'zeit' => 3 );

		foreach ( $rows as $i => $r ) {
			$m                    = isset( $marks[ $r['key'] ] ) ? $marks[ $r['key'] ] : array();
			$rows[ $i ]['mailed'] = isset( $m['mailed'] ) ? (string) $m['mailed'] : '';
			$rows[ $i ]['snooze'] = isset( $m['snooze'] ) && $m['snooze'] >= $today ? (string) $m['snooze'] : '';
			// Nach einer Mail seit der letzten Bestellung: erst mal Ruhe.
			$rows[ $i ]['done']   = '' !== $rows[ $i ]['snooze'] || ( '' !== $rows[ $i ]['mailed'] && $rows[ $i ]['mailed'] > $r['last'] );
		}

		usort(
			$rows,
			function ( $a, $b ) use ( $order ) {
				return ( $order[ $a['state'] ] <=> $order[ $b['state'] ] ) ?: ( $a['days_to'] <=> $b['days_to'] );
			}
		);

		$counts = array( 'bald' => 0, 'ueberfaellig' => 0, 'verloren' => 0, 'zeit' => 0 );
		foreach ( $rows as $r ) {
			++$counts[ $r['state'] ];
		}

		return new WP_REST_Response( array( 'rows' => $rows, 'counts' => $counts, 'shop' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) ) );
	}

	/**
	 * Eintrag zu einem Schlüssel.
	 *
	 * @param string $key Schlüssel.
	 * @return array|null
	 */
	protected static function find( $key ) {
		foreach ( self::customers() as $r ) {
			if ( $r['key'] === $key ) {
				return $r;
			}
		}

		return null;
	}

	/**
	 * Mailentwurf. Der Nachbestell-Link entsteht erst beim Senden.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function draft( WP_REST_Request $request ) {
		$r = self::find( (string) $request['key'] );

		if ( ! $r ) {
			return Kit::fail( __( 'Diesen Kunden gibt es in der Liste nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$link = $r['b2b'] ? '[Nachbestell-Link]' : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) );
		$text = Rhythm::mail_text( array_merge( $r, array( 'link' => $link ) ), Kit::company()['name'] );

		return new WP_REST_Response( array( 'to' => $r['email'], 'subject' => $text['subject'], 'body' => $text['body'], 'b2b' => $r['b2b'] ) );
	}

	/**
	 * Mail schicken (bei Händlern mit frischem Nachbestell-Link).
	 *
	 * @param WP_REST_Request $request Anfrage {to, subject, body}.
	 * @return WP_REST_Response
	 */
	public static function send( WP_REST_Request $request ) {
		$r  = self::find( (string) $request['key'] );
		$in = Kit::input( $request );

		if ( ! $r ) {
			return Kit::fail( __( 'Diesen Kunden gibt es in der Liste nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$to      = trim( (string) ( isset( $in['to'] ) ? $in['to'] : '' ) );
		$subject = sanitize_text_field( (string) ( isset( $in['subject'] ) ? $in['subject'] : '' ) );
		$body    = sanitize_textarea_field( (string) ( isset( $in['body'] ) ? $in['body'] : '' ) );

		if ( ! is_email( $to ) ) {
			return Kit::fail( __( 'Die E-Mail-Adresse stimmt nicht.', 'lindenweg16' ) );
		}
		if ( '' === $subject || '' === trim( $body ) ) {
			return Kit::fail( __( 'Betreff und Text dürfen nicht leer sein.', 'lindenweg16' ) );
		}

		if ( false !== strpos( $body, '[Nachbestell-Link]' ) ) {
			$token = $r['contact_id'] ? \Lindenweg16\Modules\Betrieb\ReorderToken::issue( (int) $r['contact_id'] ) : array();
			$body  = str_replace( '[Nachbestell-Link]', ! empty( $token['url'] ) ? $token['url'] : home_url( '/' ), $body );
		}

		if ( ! wp_mail( $to, $subject, $body ) ) {
			return Kit::fail( __( 'Die Mail konnte nicht verschickt werden. Bitte die Mail-Einstellungen der Website prüfen.', 'lindenweg16' ), 502 );
		}

		$marks                       = self::marks();
		$marks[ $r['key'] ]          = isset( $marks[ $r['key'] ] ) ? $marks[ $r['key'] ] : array();
		$marks[ $r['key'] ]['mailed'] = current_time( 'Y-m-d' );
		update_option( self::OPTION, $marks, false );

		if ( $r['contact_id'] ) {
			ContactRepository::add_activity(
				array(
					'contact_id'    => (int) $r['contact_id'],
					'activity_type' => 'mail',
					'summary'       => __( 'Nachkauf-Mail geschickt (Bestellrhythmus).', 'lindenweg16' ),
					'next_step'     => __( 'Nachbestellung abwarten', 'lindenweg16' ),
					'follow_up_on'  => Kit::add_days( current_time( 'Y-m-d' ), 10 ),
				)
			);
		}
		AuditLog::record( 'erp_nachkauf_mail', $r['contact_id'] ? 'contact' : 'kunde', (int) $r['contact_id'], array( 'an' => $to ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Mail verschickt.', 'lindenweg16' ) ) );
	}

	/**
	 * Ruhe bis zu einem Datum (z. B. „hat sich gemeldet“).
	 *
	 * @param WP_REST_Request $request Anfrage {until}.
	 * @return WP_REST_Response
	 */
	public static function snooze( WP_REST_Request $request ) {
		$key   = (string) $request['key'];
		$in    = Kit::input( $request );
		$until = Kit::date( isset( $in['until'] ) ? $in['until'] : '' );
		$until = '' !== $until ? $until : Kit::add_days( current_time( 'Y-m-d' ), 30 );
		$marks = self::marks();

		$marks[ $key ]           = isset( $marks[ $key ] ) ? $marks[ $key ] : array();
		$marks[ $key ]['snooze'] = $until;
		update_option( self::OPTION, $marks, false );

		/* translators: %s: Datum. */
		return new WP_REST_Response( array( 'ok' => true, 'message' => sprintf( __( 'Ruht bis %s.', 'lindenweg16' ), Kit::dmy( $until ) ) ) );
	}
}
