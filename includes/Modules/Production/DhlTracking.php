<?php
/**
 * DHL-Sendungsverfolgung: Cron-Abruf, Cache, Zeitleiste im Kundenkonto,
 * Admin-UI für manuelle Tracking-Eingabe und Refresh.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Production;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;
use Lindenweg16\Core\Scheduler;

class DhlTracking {

	const API_BASE = 'https://api-eu.dhl.com/track/shipments';

	public function register() {
		add_action( Scheduler::HOOK_DHL_TRACKING, array( $this, 'refresh_all' ) );

		// Mein-Konto: Tracking in der Bestell-Detailansicht.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_tracking' ) );

		// Tracking-Nummer aus DHL-Label-Plugin übernehmen.
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_capture_tracking' ), 10, 3 );

		// Admin: Tracking-Metabox in der Bestellung.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_post_lw16_save_tracking', array( $this, 'handle_save_tracking' ) );
		add_action( 'admin_post_lw16_refresh_tracking', array( $this, 'handle_refresh_tracking' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_tracking_cache';
	}

	/**
	 * Tracking-Metabox auf der WooCommerce-Bestellseite.
	 */
	public function add_meta_box() {
		$screen = function_exists( 'wc_get_container' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box(
			'lw16_tracking',
			__( 'DHL Sendungsverfolgung', 'lindenweg16' ),
			array( $this, 'render_meta_box' ),
			$screen,
			'side',
			'default'
		);
		// Fallback für Classic Editor.
		add_meta_box(
			'lw16_tracking',
			__( 'DHL Sendungsverfolgung', 'lindenweg16' ),
			array( $this, 'render_meta_box' ),
			'shop_order',
			'side',
			'default'
		);
	}

	/**
	 * Tracking-Metabox rendern.
	 *
	 * @param \WP_Post|object $post_or_order Post oder Order-Objekt.
	 */
	public function render_meta_box( $post_or_order ) {
		$order_id = method_exists( $post_or_order, 'get_id' ) ? $post_or_order->get_id() : $post_or_order->ID;
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order_id ) ); // phpcs:ignore
		$tracking_nr = $row ? $row->tracking_nummer : self::extract_tracking_number( $order );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_save_tracking">
			<input type="hidden" name="order_id" value="<?php echo (int) $order_id; ?>">
			<?php wp_nonce_field( 'lw16_save_tracking_' . $order_id ); ?>

			<p>
				<label for="lw16_tracking_nr"><strong><?php esc_html_e( 'Tracking-Nummer:', 'lindenweg16' ); ?></strong></label><br>
				<input type="text" id="lw16_tracking_nr" name="tracking_nummer" value="<?php echo esc_attr( $tracking_nr ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'z. B. 00340434161094042557', 'lindenweg16' ); ?>">
			</p>

			<?php if ( $row && $row->aktueller_status ) : ?>
				<p>
					<strong><?php esc_html_e( 'Status:', 'lindenweg16' ); ?></strong>
					<?php echo esc_html( $row->aktueller_status ); ?>
				</p>
				<?php if ( $row->voraussichtliches_lieferdatum ) : ?>
				<p>
					<strong><?php esc_html_e( 'Voraussichtlich:', 'lindenweg16' ); ?></strong>
					<?php echo esc_html( date_i18n( 'd.m.Y', strtotime( $row->voraussichtliches_lieferdatum ) ) ); ?>
				</p>
				<?php endif; ?>
				<?php if ( $row->letzter_abruf ) : ?>
				<p class="description">
					<?php echo esc_html( sprintf( __( 'Letzter Abruf: %s', 'lindenweg16' ), date_i18n( 'd.m.Y H:i', strtotime( $row->letzter_abruf ) ) ) ); ?>
				</p>
				<?php endif; ?>
			<?php endif; ?>

			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Tracking speichern', 'lindenweg16' ); ?></button>
				<?php if ( $row && $tracking_nr ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lw16_refresh_tracking&order_id=' . $order_id ), 'lw16_refresh_tracking_' . $order_id ) ); ?>"><?php esc_html_e( 'Jetzt abrufen', 'lindenweg16' ); ?></a>
				<?php endif; ?>
			</p>

			<?php if ( ! Settings::get( 'dhl_tracking_api_key' ) ) : ?>
				<p class="description" style="color:#dc3232">
					<?php echo wp_kses_post( sprintf(
						__( 'Kein DHL API-Key hinterlegt. <a href="%s">Einstellungen &rarr;</a>', 'lindenweg16' ),
						admin_url( 'admin.php?page=lw16-einstellungen' )
					) ); ?>
				</p>
			<?php elseif ( (int) Settings::get( 'dhl_tracking_sandbox', 1 ) === 1 ) : ?>
				<p class="description" style="color:#ffb900">
					<?php esc_html_e( 'Sandbox-Modus aktiv — keine Live-Daten.', 'lindenweg16' ); ?>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Tracking-Nummer manuell speichern.
	 */
	public function handle_save_tracking() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		check_admin_referer( 'lw16_save_tracking_' . $order_id );

		$tracking = sanitize_text_field( wp_unslash( $_POST['tracking_nummer'] ?? '' ) );

		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE order_id = %d', $order_id ) ); // phpcs:ignore

		if ( $exists ) {
			if ( $tracking ) {
				$wpdb->update(
					self::table(),
					array( 'tracking_nummer' => $tracking ),
					array( 'id' => $exists )
				);
			} else {
				$wpdb->delete( self::table(), array( 'id' => $exists ) );
			}
		} elseif ( $tracking ) {
			$wpdb->insert(
				self::table(),
				array(
					'order_id'         => $order_id,
					'tracking_nummer'  => $tracking,
					'aktueller_status' => '',
					'events'           => wp_json_encode( array() ),
					'letzter_abruf'    => null,
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
		}

		// Zurück zur Bestellung.
		$edit_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		if ( function_exists( 'wc_get_container' ) ) {
			$edit_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}
		wp_safe_redirect( $edit_url );
		exit;
	}

	/**
	 * Tracking manuell sofort abrufen.
	 */
	public function handle_refresh_tracking() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$order_id = (int) ( $_GET['order_id'] ?? 0 );
		check_admin_referer( 'lw16_refresh_tracking_' . $order_id );

		$api_key = (string) Settings::get( 'dhl_tracking_api_key', '' );
		if ( ! $api_key ) {
			wp_die( esc_html__( 'Kein DHL API-Key hinterlegt.', 'lindenweg16' ) );
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order_id ) ); // phpcs:ignore
		if ( $row ) {
			$this->refresh_one( $row, $api_key );
		}

		$edit_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		if ( function_exists( 'wc_get_container' ) ) {
			$edit_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}
		wp_safe_redirect( $edit_url );
		exit;
	}

	/**
	 * Tracking-Nummer aus verbreiteten DHL-Plugin-Metafeldern lesen.
	 */
	public static function extract_tracking_number( $order ) {
		$keys = array(
			'_dhl_tracking_number',
			'_pr_shipment_dhl_label_tracking',
			'_shipment_tracking_number',
			'_tracking_number',
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
	 * Nach Statuswechsel Tracking-Nummer automatisch erfassen.
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
					'order_id'         => $order_id,
					'tracking_nummer'  => $tracking,
					'aktueller_status' => '',
					'events'           => wp_json_encode( array() ),
					'letzter_abruf'    => null,
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
		}
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
			"SELECT * FROM " . self::table() . " WHERE aktueller_status IS NULL OR aktueller_status NOT IN ('zugestellt', 'delivered')" // phpcs:ignore
		);

		foreach ( $rows as $row ) {
			$this->refresh_one( $row, $api_key );
		}
	}

	/**
	 * Eine Sendung abfragen und den Cache aktualisieren.
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

		$update_data = array( 'letzter_abruf' => current_time( 'mysql' ) );

		if ( is_wp_error( $response ) ) {
			$update_data['aktueller_status'] = __( 'Fehler: ', 'lindenweg16' ) . $response->get_error_message();
			$wpdb->update( self::table(), $update_data, array( 'id' => $row->id ) );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body_raw = wp_remote_retrieve_body( $response );
			$err      = json_decode( $body_raw, true );
			$err_msg  = isset( $err['detail'] ) ? $err['detail'] : ( isset( $err['title'] ) ? $err['title'] : 'HTTP ' . $code );
			$update_data['aktueller_status'] = __( 'API-Fehler: ', 'lindenweg16' ) . $err_msg;
			$wpdb->update( self::table(), $update_data, array( 'id' => $row->id ) );
			return;
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$parsed = self::parse_response( $body );

		$update_data['aktueller_status']              = $parsed['status'];
		$update_data['voraussichtliches_lieferdatum'] = $parsed['lieferdatum'];
		$update_data['events']                        = wp_json_encode( $parsed['events'] );

		$wpdb->update( self::table(), $update_data, array( 'id' => $row->id ) );
	}

	/**
	 * DHL-Antwort in einheitliches Format übersetzen.
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
					'status'      => isset( $ev['status'] ) ? (string) $ev['status'] : '',
					'ort'         => isset( $ev['location']['address']['addressLocality'] ) ? (string) $ev['location']['address']['addressLocality'] : '',
					'zeitstempel' => isset( $ev['timestamp'] ) ? (string) $ev['timestamp'] : '',
				);
			}
		}
		return $out;
	}

	/**
	 * Sendungsverfolgung im Kundenkonto.
	 */
	public function render_tracking( $order ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order->get_id() ) ); // phpcs:ignore
		if ( ! $row ) {
			return;
		}

		echo '<section class="lw16-tracking"><h2>' . esc_html__( 'Sendungsverfolgung', 'lindenweg16' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Tracking-Nummer:', 'lindenweg16' ) . '</strong> ' . esc_html( $row->tracking_nummer ) . '</p>';

		$events = json_decode( (string) $row->events, true );
		if ( empty( $events ) ) {
			if ( $row->aktueller_status && strpos( $row->aktueller_status, 'Fehler' ) !== false ) {
				echo '<p>' . esc_html__( 'Versandbestätigung erhalten, Sendungsdetails folgen in Kürze.', 'lindenweg16' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Versandbestätigung erhalten, Sendungsdetails folgen in Kürze.', 'lindenweg16' ) . '</p>';
			}
			echo '</section>';
			return;
		}

		if ( $row->voraussichtliches_lieferdatum ) {
			echo '<p class="lw16-eta"><strong>' . esc_html__( 'Voraussichtliche Zustellung:', 'lindenweg16' ) . '</strong> ' . esc_html( date_i18n( 'd.m.Y', strtotime( $row->voraussichtliches_lieferdatum ) ) ) . '</p>';
		}

		echo '<ul class="lw16-timeline">';
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
