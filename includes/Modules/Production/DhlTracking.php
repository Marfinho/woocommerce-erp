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
use Lindenweg16\Core\Time;

class DhlTracking {

	const API_BASE = 'https://api-eu.dhl.com/track/shipments';

	/**
	 * Maximale Zahl Sendungen pro Cron-Lauf (jede kostet einen HTTP-Request).
	 */
	const REFRESH_BATCH = 50;

	/**
	 * Merker, damit die Hook-Bereinigung pro Request nur einmal laeuft.
	 *
	 * @var bool
	 */
	protected $hooks_stripped = false;

	public function register() {
		add_action( Scheduler::HOOK_DHL_TRACKING, array( $this, 'refresh_all' ) );

		// Mein-Konto: Tracking in der Bestell-Detailansicht und Sendungsseite.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_tracking' ) );
		add_action( 'woocommerce_gzd_shipment_details', array( $this, 'render_tracking_for_shipment' ), 5 );
		add_action( 'woocommerce_gzd_view_shipment', array( $this, 'render_tracking_for_shipment' ), 5 );

		// Tracking-Status in der Bestell-Übersicht (Bestellliste).
		add_filter( 'woocommerce_my_account_my_orders_columns', array( $this, 'add_tracking_column' ) );
		add_action( 'woocommerce_my_account_my_orders_column_lw16-tracking', array( $this, 'render_tracking_column' ) );

		// Frontend-CSS laden.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// Externes DHL-Plugin Tracking-Anzeige im Kundenkonto entfernen.
		add_action( 'wp', array( $this, 'remove_external_dhl_tracking' ) );
		add_action( 'template_redirect', array( $this, 'remove_external_dhl_tracking' ) );
		add_action( 'woocommerce_before_account_orders', array( $this, 'remove_external_dhl_tracking_late' ) );
		add_action( 'woocommerce_order_details_before_order_table', array( $this, 'remove_external_dhl_tracking_late' ) );

		// Externe Tracking-Spalten und Actions in der Bestellliste entfernen.
		add_filter( 'woocommerce_my_account_my_orders_columns', array( $this, 'remove_external_tracking_columns' ), 999 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'remove_external_tracking_actions' ), 999, 2 );

		// Tracking-Nummer aus DHL-Label-Plugin übernehmen.
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_capture_tracking' ), 10, 3 );

		// Admin: Tracking-Metabox in der Bestellung.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_post_lw16_save_tracking', array( $this, 'handle_save_tracking' ) );
		add_action( 'admin_post_lw16_refresh_tracking', array( $this, 'handle_refresh_tracking' ) );
	}

	public function enqueue_frontend_assets() {
		if ( is_account_page() || is_order_received_page() || $this->is_view_shipment_page() ) {
			wp_enqueue_style( 'lw16-frontend', LW16_PLUGIN_URL . 'assets/css/frontend.css', array(), LW16_VERSION );
		}
	}

	public function remove_external_dhl_tracking() {
		if ( ! is_account_page() && ! $this->is_view_shipment_page() ) {
			return;
		}
		$this->strip_external_tracking_hooks();
	}

	public function remove_external_dhl_tracking_late() {
		$this->strip_external_tracking_hooks();
	}

	protected function is_view_shipment_page() {
		global $wp;
		if ( isset( $wp->query_vars['view-shipment'] ) ) {
			return true;
		}
		if ( isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], 'view-shipment' ) !== false ) {
			return true;
		}
		return false;
	}

	/**
	 * Tracking-Ausgaben bekannter Fremd-Plugins entfernen.
	 *
	 * Vorher wurden ueber elf Hooks hinweg *alle* Callbacks entfernt, deren
	 * Klassen- oder Funktionsname eines der Schlagworte enthielt – darunter
	 * "versand". In einem deutschsprachigen Shop trifft das zuverlaessig
	 * fremden Code, der mit Sendungsverfolgung nichts zu tun hat. Entfernt
	 * werden jetzt nur noch Callbacks aus namentlich bekannten Plugins.
	 */
	protected function strip_external_tracking_hooks() {
		if ( $this->hooks_stripped ) {
			return;
		}
		$this->hooks_stripped = true;

		// DHL for WooCommerce (PR DHL).
		if ( function_exists( 'PR_DHL' ) ) {
			$dhl = PR_DHL();
			if ( is_object( $dhl ) && isset( $dhl->front_end ) ) {
				foreach ( array( 'display_tracking_info', 'add_tracking_info' ) as $method ) {
					remove_action( 'woocommerce_order_details_after_order_table', array( $dhl->front_end, $method ) );
					remove_action( 'woocommerce_view_order', array( $dhl->front_end, $method ) );
				}
			}
		}

		// Germanized Shipments: nur Callbacks aus dem GZD-Namensraum, und nur
		// solche, die tatsaechlich Sendungs-/Tracking-Ausgaben erzeugen.
		$tags = array(
			'woocommerce_order_details_after_order_table',
			'woocommerce_view_order',
		);

		global $wp_filter;
		foreach ( $tags as $tag ) {
			if ( empty( $wp_filter[ $tag ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $tag ]->callbacks as $priority => $hooks ) {
				foreach ( $hooks as $key => $hook ) {
					if ( $this->is_own_callback( $hook['function'] ) ) {
						continue;
					}
					if ( ! is_array( $hook['function'] ) || ! is_object( $hook['function'][0] ) ) {
						continue;
					}
					$class = strtolower( get_class( $hook['function'][0] ) );
					if ( false === strpos( $class, 'germanized' ) && false === strpos( $class, 'gzd' ) ) {
						continue;
					}
					$method = strtolower( (string) $hook['function'][1] );
					if ( false !== strpos( $method, 'shipment' ) || false !== strpos( $method, 'tracking' ) ) {
						unset( $wp_filter[ $tag ]->callbacks[ $priority ][ $key ] );
					}
				}
			}
		}
	}

	/**
	 * Prüfen, ob ein Callback zu dieser Instanz gehört.
	 *
	 * @param mixed $function Callback.
	 * @return bool
	 */
	protected function is_own_callback( $function ) {
		if ( is_array( $function ) && isset( $function[0] ) && is_object( $function[0] ) ) {
			return $function[0] === $this;
		}
		return false;
	}

	public function remove_external_tracking_actions( $actions, $order ) {
		$external_keys = array( 'shipment', 'tracking', 'dhl', 'sendungsverfolgung', 'view-shipment' );
		foreach ( $actions as $key => $action ) {
			foreach ( $external_keys as $kw ) {
				if ( stripos( $key, $kw ) !== false ) {
					unset( $actions[ $key ] );
					break;
				}
				if ( isset( $action['url'] ) && stripos( $action['url'], 'view-shipment' ) !== false ) {
					unset( $actions[ $key ] );
					break;
				}
			}
		}
		return $actions;
	}

	public function remove_external_tracking_columns( $columns ) {
		$external_keys = array( 'shipment', 'tracking', 'dhl', 'sendungsverfolgung' );
		foreach ( $columns as $key => $label ) {
			if ( 'lw16-tracking' === $key ) {
				continue;
			}
			foreach ( $external_keys as $kw ) {
				if ( stripos( $key, $kw ) !== false ) {
					unset( $columns[ $key ] );
					break;
				}
			}
		}
		return $columns;
	}

	public function add_tracking_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order-status' === $key ) {
				$new['lw16-tracking'] = __( 'Versand', 'lindenweg16' );
			}
		}
		return $new;
	}

	public function render_tracking_column( $order ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order->get_id() ) ); // phpcs:ignore
		if ( ! $row ) {
			echo '<span style="color:#8c8f94">—</span>';
			return;
		}
		$step = self::status_step( $row->aktueller_status ?: '' );
		if ( 0 === $step ) {
			// Noch keine belastbare Statusmeldung – lieber nichts sagen als
			// einen Versand behaupten, den die API nie bestaetigt hat.
			echo '<span style="color:#8c8f94">—</span>';
			return;
		}
		$labels = array( '', __( 'Versendet', 'lindenweg16' ), __( 'Unterwegs', 'lindenweg16' ), __( 'In Zustellung', 'lindenweg16' ), __( 'Zugestellt', 'lindenweg16' ) );
		$colors = array( '', '#2271b1', '#ffb900', '#00a32a', '#00a32a' );
		$label  = isset( $labels[ $step ] ) ? $labels[ $step ] : $labels[1];
		$color  = isset( $colors[ $step ] ) ? $colors[ $step ] : $colors[1];
		$url    = $order->get_view_order_url();
		printf( '<a href="%s" style="color:%s;font-weight:600;text-decoration:none">%s</a>', esc_url( $url ), esc_attr( $color ), esc_html( $label ) );
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
				<?php if ( ! empty( $row->letzter_fehler ) ) : ?>
				<p class="description" style="color:#dc3232">
					<?php echo esc_html( sprintf( __( 'Letzter Abruf-Fehler: %s', 'lindenweg16' ), $row->letzter_fehler ) ); ?>
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

		// Begrenzen: jede Zeile kostet einen HTTP-Request mit 15 s Timeout.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . "
				 WHERE aktueller_status IS NULL OR aktueller_status NOT IN ('zugestellt', 'delivered')
				 ORDER BY COALESCE(letzter_abruf, '1970-01-01') ASC
				 LIMIT %d", // phpcs:ignore
				self::REFRESH_BATCH
			)
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

		$update_data = array( 'letzter_abruf' => Time::now() );

		// Fehler landen in einer eigenen Spalte. Frueher wurden sie in
		// aktueller_status geschrieben - und weil status_step() alles
		// Unbekannte als "Versendet" einstufte, sah der Kunde bei kaputtem
		// API-Key eine erfundene Versandmeldung.
		if ( is_wp_error( $response ) ) {
			$update_data['letzter_fehler'] = $response->get_error_message();
			$wpdb->update( self::table(), $update_data, array( 'id' => $row->id ) );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body_raw = wp_remote_retrieve_body( $response );
			$err      = json_decode( $body_raw, true );
			$err_msg  = isset( $err['detail'] ) ? $err['detail'] : ( isset( $err['title'] ) ? $err['title'] : 'HTTP ' . $code );
			$update_data['letzter_fehler'] = 'HTTP ' . $code . ': ' . $err_msg;
			$wpdb->update( self::table(), $update_data, array( 'id' => $row->id ) );
			return;
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$parsed = self::parse_response( $body );

		$update_data['aktueller_status']              = $parsed['status'];
		$update_data['voraussichtliches_lieferdatum'] = $parsed['lieferdatum'];
		$update_data['events']                        = wp_json_encode( $parsed['events'] );
		$update_data['letzter_fehler']                = null;

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
	 * Versandstatus-Stufe aus dem aktuellen Status ableiten (0–4).
	 */
	protected static function status_step( $status ) {
		$s = strtolower( $status );
		if ( in_array( $s, array( 'zugestellt', 'delivered' ), true ) ) {
			return 4;
		}
		if ( false !== strpos( $s, 'zustellung' ) || false !== strpos( $s, 'delivery' ) || false !== strpos( $s, 'out for' ) ) {
			return 3;
		}
		if ( false !== strpos( $s, 'transit' ) || false !== strpos( $s, 'transport' ) || false !== strpos( $s, 'sort' ) || false !== strpos( $s, 'hub' ) || false !== strpos( $s, 'center' ) ) {
			return 2;
		}
		if ( false !== strpos( $s, 'angenommen' ) || false !== strpos( $s, 'picked' ) || false !== strpos( $s, 'shipment' ) || false !== strpos( $s, 'electronic' ) ) {
			return 1;
		}
		// Unbekannter oder leerer Status: keine Aussage treffen.
		return 0;
	}

	/**
	 * Tracking auf der Germanized view-shipment Seite.
	 */
	public function render_tracking_for_shipment( $shipment = null ) {
		$this->strip_external_tracking_hooks();

		$order_id = 0;
		if ( is_object( $shipment ) && method_exists( $shipment, 'get_order_id' ) ) {
			$order_id = $shipment->get_order_id();
		} elseif ( is_object( $shipment ) && method_exists( $shipment, 'get_order' ) ) {
			$parent = $shipment->get_order();
			$order_id = $parent ? $parent->get_id() : 0;
		}

		if ( ! $order_id ) {
			global $wp;
			if ( isset( $wp->query_vars['view-shipment'] ) ) {
				$shipment_id = absint( $wp->query_vars['view-shipment'] );
				if ( $shipment_id && function_exists( 'wc_gzd_get_shipment' ) ) {
					$s = wc_gzd_get_shipment( $shipment_id );
					if ( $s ) {
						$order_id = $s->get_order_id();
					}
				}
			}
		}

		if ( ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$this->render_tracking( $order );
	}

	/**
	 * Sendungsverfolgung im Kundenkonto – Amazon/Temu-Style.
	 */
	public function render_tracking( $order ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order->get_id() ) ); // phpcs:ignore
		if ( ! $row ) {
			return;
		}

		$events = json_decode( (string) $row->events, true );
		$step   = $events ? self::status_step( $row->aktueller_status ) : 0;
		$steps  = array(
			__( 'Bestellt', 'lindenweg16' ),
			__( 'Versendet', 'lindenweg16' ),
			__( 'Unterwegs', 'lindenweg16' ),
			__( 'In Zustellung', 'lindenweg16' ),
			__( 'Zugestellt', 'lindenweg16' ),
		);
		?>
		<section class="lw16-tracking-page">
			<h2><?php esc_html_e( 'Wo ist mein Paket?', 'lindenweg16' ); ?></h2>

			<?php if ( $row->voraussichtliches_lieferdatum && $step < 4 ) : ?>
				<div class="lw16-tp-eta">
					<span class="lw16-tp-eta-label"><?php esc_html_e( 'Voraussichtliche Lieferung', 'lindenweg16' ); ?></span>
					<span class="lw16-tp-eta-date"><?php echo esc_html( date_i18n( 'l, d. F Y', strtotime( $row->voraussichtliches_lieferdatum ) ) ); ?></span>
				</div>
			<?php elseif ( 4 === $step ) : ?>
				<div class="lw16-tp-eta lw16-tp-delivered">
					<span class="lw16-tp-eta-date"><?php esc_html_e( 'Dein Paket wurde zugestellt!', 'lindenweg16' ); ?></span>
				</div>
			<?php endif; ?>

			<div class="lw16-tp-progress">
				<?php foreach ( $steps as $i => $label ) : ?>
					<div class="lw16-tp-step <?php echo $i <= $step ? 'lw16-tp-done' : ''; ?> <?php echo $i === $step ? 'lw16-tp-current' : ''; ?>">
						<div class="lw16-tp-dot"></div>
						<span class="lw16-tp-label"><?php echo esc_html( $label ); ?></span>
					</div>
					<?php if ( $i < count( $steps ) - 1 ) : ?>
						<div class="lw16-tp-bar <?php echo $i < $step ? 'lw16-tp-done' : ''; ?>"></div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>

			<p class="lw16-tp-nr">
				<?php echo esc_html( sprintf( __( 'Sendungsnummer: %s', 'lindenweg16' ), $row->tracking_nummer ) ); ?>
			</p>

			<?php if ( ! empty( $events ) ) : ?>
			<div class="lw16-tp-details">
				<h3><?php esc_html_e( 'Sendungsverlauf', 'lindenweg16' ); ?></h3>
				<div class="lw16-tp-events">
					<?php foreach ( $events as $i => $ev ) : ?>
						<div class="lw16-tp-event <?php echo 0 === $i ? 'lw16-tp-event-latest' : ''; ?>">
							<div class="lw16-tp-event-dot"></div>
							<div class="lw16-tp-event-content">
								<div class="lw16-tp-event-status"><?php echo esc_html( $ev['status'] ); ?></div>
								<?php if ( ! empty( $ev['ort'] ) ) : ?>
									<div class="lw16-tp-event-loc"><?php echo esc_html( $ev['ort'] ); ?></div>
								<?php endif; ?>
								<?php if ( ! empty( $ev['zeitstempel'] ) ) : ?>
									<div class="lw16-tp-event-time"><?php echo esc_html( date_i18n( 'd.m.Y, H:i \U\h\r', strtotime( $ev['zeitstempel'] ) ) ); ?></div>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php elseif ( ! $events ) : ?>
				<p class="lw16-tp-pending"><?php esc_html_e( 'Dein Paket wurde soeben versendet. Die Sendungsdetails werden in Kürze aktualisiert.', 'lindenweg16' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}
}
