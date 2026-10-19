<?php
/**
 * ERP-Seite „Support“: Kundenanfragen (Tickets) lesen, beantworten, schließen, Gutschrift.
 *
 * Gleiche Rechte (`lw16_mod_support` über das ERP-Recht verwaltung.support),
 * gleiche Abläufe, gleiche Protokolleinträge und Kundenmails wie
 * `SupportModule::handle_ticket_action`.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Loyalty\LoyaltyRepository;
use Lindenweg16\Modules\Support\SupportModule;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tickets in der neuen Oberfläche.
 */
class Support {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$needs = $api->needs( array( 'verwaltung.support' ) );

		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/support',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'tickets' ),
				'permission_callback' => $needs,
			)
		);

		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/support/(?P<id>\d+)/aktion',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'act' ),
				'permission_callback' => $needs,
			)
		);
	}

	/**
	 * Ticket-Status, den eine Aktion setzt; leer für alles andere.
	 *
	 * @param string $do Aktion.
	 * @return string
	 */
	public static function status_for( $do ) {
		return in_array( $do, array( 'in_bearbeitung', 'geloest' ), true ) ? $do : '';
	}

	/**
	 * Gutschrift-Betrag aus der Eingabe: nur positive Zahlen, auf Cent gerundet.
	 * Komma als Dezimaltrenner ist erlaubt.
	 *
	 * @param mixed $raw Eingabe.
	 * @return float 0, wenn ungültig.
	 */
	public static function credit_amount( $raw ) {
		$raw = str_replace( ',', '.', trim( (string) $raw ) );

		if ( ! is_numeric( $raw ) ) {
			return 0.0;
		}

		$value = round( (float) $raw, 2 );

		return $value > 0 ? $value : 0.0;
	}

	/**
	 * Lesbare Bezeichnung eines Status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'offen'          => __( 'Offen', 'lindenweg16' ),
			'in_bearbeitung' => __( 'In Bearbeitung', 'lindenweg16' ),
			'geloest'        => __( 'Gelöst', 'lindenweg16' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Tickets (offene; mit alle=1 auch gelöste).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function tickets( WP_REST_Request $request ) {
		global $wpdb;

		$all   = ! empty( $request->get_param( 'alle' ) );
		$where = $all ? '' : " WHERE status <> 'geloest'";
		$rows  = $wpdb->get_results( 'SELECT * FROM ' . SupportModule::table() . $where . ' ORDER BY id DESC LIMIT 200' ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out   = array();

		foreach ( (array) $rows as $t ) {
			$user  = get_userdata( (int) $t->user_id );
			$out[] = array(
				'id'       => (int) $t->id,
				'customer' => $user ? $user->display_name : __( 'Unbekannter Kunde', 'lindenweg16' ),
				'email'    => $user ? $user->user_email : '',
				'order'    => $t->order_id ? (int) $t->order_id : 0,
				'subject'  => (string) $t->betreff,
				'message'  => (string) $t->nachricht,
				'status'   => (string) $t->status,
				'label'    => self::status_label( $t->status ),
				'ts'       => $t->erstellt_am ? (int) strtotime( $t->erstellt_am ) : 0,
				'answered' => ! empty( $t->beantwortet_am ),
			);
		}

		return new WP_REST_Response(
			array(
				'tickets' => $out,
				'all'     => $all,
			)
		);
	}

	/**
	 * Aktion: Status setzen, antworten oder Gutschrift buchen.
	 *
	 * @param WP_REST_Request $request Anfrage (id, do, text, betrag, grund).
	 * @return WP_REST_Response
	 */
	public static function act( WP_REST_Request $request ) {
		global $wpdb;

		$id     = (int) $request['id'];
		$do     = sanitize_key( (string) $request->get_param( 'do' ) );
		$ticket = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SupportModule::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( ! $ticket ) {
			return self::fail( __( 'Diese Anfrage gibt es nicht mehr.', 'lindenweg16' ), 404 );
		}

		if ( 'antwort' === $do ) {
			$text = sanitize_textarea_field( (string) $request->get_param( 'text' ) );
			if ( '' === trim( $text ) ) {
				return self::fail( __( 'Bitte zuerst die Antwort an den Kunden schreiben.', 'lindenweg16' ) );
			}
			$wpdb->update( SupportModule::table(), array( 'status' => 'in_bearbeitung', 'beantwortet_am' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			self::notify_customer(
				$ticket,
				sprintf(
					/* translators: 1: Betreff, 2: Antworttext */
					__( "Betreff: %1\$s\n\n%2\$s", 'lindenweg16' ),
					$ticket->betreff,
					$text
				)
			);

			return self::ok( __( 'Antwort gesendet.', 'lindenweg16' ) );
		}

		if ( 'gutschrift' === $do ) {
			$betrag = self::credit_amount( $request->get_param( 'betrag' ) );
			$grund  = sanitize_text_field( (string) $request->get_param( 'grund' ) );
			if ( $betrag <= 0 ) {
				return self::fail( __( 'Bitte einen Betrag größer als 0 eintragen, zum Beispiel 12,50.', 'lindenweg16' ) );
			}
			LoyaltyRepository::adjust_credit( (int) $ticket->user_id, $betrag );
			AuditLog::record( 'gutschrift_reklamation', 'ticket', $id, array( 'betrag' => $betrag, 'grund' => $grund, 'user' => $ticket->user_id ) );
			self::notify_customer( $ticket, sprintf( /* translators: %s: Betrag */ __( 'Wir haben Dir eine Gutschrift von %s € auf Dein Guthaben gebucht.', 'lindenweg16' ), number_format( $betrag, 2, ',', '.' ) ) );

			return self::ok( __( 'Gutschrift gebucht.', 'lindenweg16' ) );
		}

		$status = self::status_for( $do );
		if ( '' !== $status ) {
			$wpdb->update( SupportModule::table(), array( 'status' => $status, 'beantwortet_am' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			self::notify_customer( $ticket, 'geloest' === $status ? __( 'Wir haben Dein Anliegen bearbeitet.', 'lindenweg16' ) : __( 'Wir kümmern uns um Dein Anliegen.', 'lindenweg16' ) );

			return self::ok( __( 'Status aktualisiert.', 'lindenweg16' ) );
		}

		return self::fail( __( 'Diese Aktion kennt der Support nicht.', 'lindenweg16' ) );
	}

	/**
	 * Kurze Benachrichtigung an den Kunden.
	 *
	 * @param object $ticket  Ticket.
	 * @param string $message Nachricht.
	 */
	protected static function notify_customer( $ticket, $message ) {
		$user = get_userdata( (int) $ticket->user_id );

		if ( $user && is_email( $user->user_email ) ) {
			MailTemplate::send( $user->user_email, 'ticket_update', array( '{nachricht}' => $message ) );
		}
	}

	/**
	 * Erfolgsantwort.
	 *
	 * @param string $message Text.
	 * @return WP_REST_Response
	 */
	protected static function ok( $message ) {
		return new WP_REST_Response( array( 'ok' => true, 'message' => $message ) );
	}

	/**
	 * Fehlerantwort.
	 *
	 * @param string $message Text.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}
}
