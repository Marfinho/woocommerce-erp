<?php
/**
 * DHL-Sendungsverfolgung: Cron-Abruf, Cache, Zeitleiste im Kundenkonto.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Production;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;
use Lindenweg16\Core\Scheduler;

/**
 * Fragt die DHL Sendungsverfolgung API ausschließlich per Cron ab und
 * speichert die Ereignisse in lw16_tracking_cache. Kunden sehen nur den
 * zwischengespeicherten Stand – nie ein Live-Request im Kunden-Request.
 */
class DhlTracking {

	const API_BASE = 'https://api-eu.dhl.com/track/shipments';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( Scheduler::HOOK_DHL_TRACKING, array( $this, 'refresh_all' ) );

		// Mein-Konto-Abschnitt in der Bestell-Detailansicht.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_tracking' ) );

		// Tracking-Nummer aus dem DHL-Label-Plugin übernehmen (falls gesetzt).
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_capture_tracking' ), 10, 3 );
	}

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_tracking_cache';
	}

	/**
	 * Nach Statuswechsel prüfen, ob das DHL-Label-Plugin eine Tracking-Nummer
	 * hinterlegt hat, und diese in den Cache aufnehmen.
	 *
	 * @param int    $order_id   Bestell-ID.
	 * @param string $from       Alter Status.
	 * @param string $to         Neuer Status.
	 */
	public function maybe_capture_tracking( $order_id, $from, $to ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$tracking = self::extract_tracking_number( $order );
		if ( ! $tracking ) {
			return;
		}

		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE order_id = %d', $order_id ) ); // phpcs:ignore
		if ( ! $exists ) {
			$wpdb->insert(
				self::table(),
				array(
					'order_id'        => $order_id,
					'tracking_nummer' => $tracking,
					'aktueller_status' => '',
					'events'          => wp_json_encode( array() ),
					'letzter_abruf'   => null,
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Tracking-Nummer aus verbreiteten DHL-Plugin-Metafeldern lesen.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return string
	 */
	public static function extract_tracking_number( $order ) {
		$keys = array(
			'_dhl_tracking_number',
			'_pr_shipment_dhl_label_tracking',
			'_shipment_tracking_number',
		);
		foreach ( $keys as $key ) {
			$val = $order->get_meta( $key );
			if ( $val ) {
				return is_array( $val ) ? (string) reset( $val ) : (string) $val;
			}
		}
		return '';
	}

	/**
	 * Cron: alle noch nicht zugestellten Sendungen aktualisieren.
	 */
	public function refresh_all() {
		global $wpdb;
		$api_key = (string) Settings::get( 'dhl_tracking_api_key', '' );
		if ( ! $api_key ) {
			return;
		}

		$rows = $wpdb->get_results(
			"SELECT * FROM " . self::table() . " WHERE aktueller_status IS NULL OR aktueller_status <> 'zugestellt'" // phpcs:ignore
		);

		foreach ( $rows as $row ) {
			$this->refresh_one( $row, $api_key );
		}
	}

	/**
	 * Eine Sendung abfragen und den Cache aktualisieren.
	 *
	 * @param object $row     Cache-Zeile.
	 * @param string $api_key API-Key.
	 */
	protected function refresh_one( $row, $api_key ) {
		global $wpdb;

		$url = add_query_arg(
			array( 'trackingNumber' => rawurlencode( $row->tracking_nummer ) ),
			self::API_BASE
		);
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'DHL-API-Key' => $api_key,
				'Accept'      => 'application/json',
			),
		);
		if ( (int) Settings::get( 'dhl_tracking_sandbox', 1 ) === 1 ) {
			$args['headers']['DHL-Test-Mode'] = 'true';
		}

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Fehler still ignorieren – nächster Cron-Lauf versucht es erneut.
			$wpdb->update( self::table(), array( 'letzter_abruf' => current_time( 'mysql' ) ), array( 'id' => $row->id ) );
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$parsed = self::parse_response( $body );

		$wpdb->update(
			self::table(),
			array(
				'aktueller_status'              => $parsed['status'],
				'voraussichtliches_lieferdatum' => $parsed['lieferdatum'],
				'events'                        => wp_json_encode( $parsed['events'] ),
				'letzter_abruf'                 => current_time( 'mysql' ),
			),
			array( 'id' => $row->id )
		);
	}

	/**
	 * DHL-Antwort in ein einheitliches Format übersetzen.
	 *
	 * @param array|null $body Dekodierte Antwort.
	 * @return array {status, lieferdatum, events}
	 */
	public static function parse_response( $body ) {
		$out = array( 'status' => '', 'lieferdatum' => null, 'events' => array() );
		if ( empty( $body['shipments'][0] ) ) {
			return $out;
		}
		$shipment = $body['shipments'][0];

		if ( ! empty( $shipment['status']['statusCode'] ) ) {
			$out['status'] = 'delivered' === $shipment['status']['statusCode'] ? 'zugestellt' : (string) $shipment['status']['status'];
		} elseif ( ! empty( $shipment['status']['status'] ) ) {
			$out['status'] = (string) $shipment['status']['status'];
		}

		if ( ! empty( $shipment['estimatedTimeOfDelivery'] ) ) {
			$out['lieferdatum'] = gmdate( 'Y-m-d', strtotime( $shipment['estimatedTimeOfDelivery'] ) );
		}

		if ( ! empty( $shipment['events'] ) && is_array( $shipment['events'] ) ) {
			foreach ( $shipment['events'] as $ev ) {
				$out['events'][] = array(
					'status'     => isset( $ev['status'] ) ? (string) $ev['status'] : '',
					'ort'        => isset( $ev['location']['address']['addressLocality'] ) ? (string) $ev['location']['address']['addressLocality'] : '',
					'zeitstempel' => isset( $ev['timestamp'] ) ? (string) $ev['timestamp'] : '',
				);
			}
		}
		return $out;
	}

	/**
	 * Sendungsverfolgung im Kundenkonto (nur zwischengespeicherter Stand).
	 *
	 * @param \WC_Order $order Bestellung.
	 */
	public function render_tracking( $order ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order->get_id() ) ); // phpcs:ignore
		if ( ! $row ) {
			return;
		}

		echo '<section class="lw16-tracking"><h2>' . esc_html__( 'Sendungsverfolgung', 'lindenweg16' ) . '</h2>';

		$events = json_decode( (string) $row->events, true );
		if ( empty( $events ) ) {
			echo '<p>' . esc_html__( 'Versandbestätigung erhalten, Sendungsdetails folgen in Kürze.', 'lindenweg16' ) . '</p></section>';
			return;
		}

		if ( $row->voraussichtliches_lieferdatum ) {
			echo '<p class="lw16-eta"><strong>' . esc_html__( 'Voraussichtliche Zustellung:', 'lindenweg16' ) . '</strong> ' . esc_html( date_i18n( 'd.m.Y', strtotime( $row->voraussichtliches_lieferdatum ) ) ) . '</p>';
		}

		echo '<ul class="lw16-timeline">';
		// Neueste zuerst.
		foreach ( array_reverse( $events ) as $ev ) {
			echo '<li>';
			echo '<div>' . esc_html( $ev['status'] );
			if ( ! empty( $ev['ort'] ) ) {
				echo ' – ' . esc_html( $ev['ort'] );
			}
			echo '</div>';
			if ( ! empty( $ev['zeitstempel'] ) ) {
				echo '<div class="lw16-event-time">' . esc_html( date_i18n( 'd.m.Y H:i', strtotime( $ev['zeitstempel'] ) ) ) . '</div>';
			}
			echo '</li>';
		}
		echo '</ul></section>';
	}
}
