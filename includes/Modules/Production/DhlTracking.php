<?php
/**
 * DHL-Sendungsverfolgung: Cron-Abruf, Cache, Zeitleiste im Kundenkonto,
 * Admin-UI für manuelle Tracking-Eingabe und Refresh.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Production;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;

use Lindenweg16\Core\Settings;
use Lindenweg16\Core\Scheduler;

class DhlTracking {

	const API_BASE = 'https://api-eu.dhl.com/track/shipments';

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
			wp_enqueue_style( 'lw16-frontend', LW16_PLUGIN_URL . 'assets/css/frontend.css', array(), Assets::version( 'assets/css/frontend.css' ) );
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

	protected function strip_external_tracking_hooks() {
		// DHL for WooCommerce (PR DHL).
		if ( function_exists( 'PR_DHL' ) ) {
			$dhl = PR_DHL();
			if ( is_object( $dhl ) && isset( $dhl->front_end ) ) {
				remove_action( 'woocommerce_order_details_after_order_table', array( $dhl->front_end, 'display_tracking_info' ) );
				remove_action( 'woocommerce_order_details_after_order_table', array( $dhl->front_end, 'add_tracking_info' ) );
				remove_action( 'woocommerce_view_order', array( $dhl->front_end, 'display_tracking_info' ) );
				remove_action( 'woocommerce_view_order', array( $dhl->front_end, 'add_tracking_info' ) );
			}
		}

		$hooks_to_clean = array(
			'woocommerce_order_details_after_order_table',
			'woocommerce_order_details_before_order_table',
			'woocommerce_view_order',
			'woocommerce_after_order_details',
			'woocommerce_order_details_after_order_table_items',
			'woocommerce_order_item_meta_end',
			'woocommerce_email_after_order_table',
			'woocommerce_gzd_shipment_details',
			'woocommerce_gzd_shipment_details_after_table',
			'woocommerce_gzd_view_shipment',
			'woocommerce_gzd_shipments_after_order_table',
		);

		$keywords = array( 'tracking', 'shipment', 'dhl', 'sendungsverfolgung', 'versand' );

		global $wp_filter;
		foreach ( $hooks_to_clean as $tag ) {
			if ( empty( $wp_filter[ $tag ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $tag ]->callbacks as $priority => $hooks ) {
				foreach ( $hooks as $key => $hook ) {
					if ( $this->is_own_callback( $hook['function'] ) ) {
						continue;
					}
					if ( $this->callback_matches_keywords( $hook['function'], $keywords ) ) {
						unset( $wp_filter[ $tag ]->callbacks[ $priority ][ $key ] );
					}
				}
			}
		}

		// Germanized: Shipment-Tabelle auf der Bestelldetailseite ausblenden.
		foreach ( array( 'woocommerce_order_details_after_order_table', 'woocommerce_view_order' ) as $tag ) {
			if ( empty( $wp_filter[ $tag ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $tag ]->callbacks as $priority => $hooks ) {
				foreach ( $hooks as $key => $hook ) {
					if ( $this->is_own_callback( $hook['function'] ) ) {
						continue;
					}
					if ( is_array( $hook['function'] ) && is_object( $hook['function'][0] ) ) {
						$class = strtolower( get_class( $hook['function'][0] ) );
						if ( strpos( $class, 'germanized' ) !== false || strpos( $class, 'gzd' ) !== false ) {
							$method = strtolower( $hook['function'][1] );
							if ( strpos( $method, 'shipment' ) !== false || strpos( $method, 'tracking' ) !== false ) {
								unset( $wp_filter[ $tag ]->callbacks[ $priority ][ $key ] );
							}
						}
					}
				}
			}
		}
	}

	protected function is_own_callback( $function ) {
		if ( is_array( $function ) && is_object( $function[0] ) ) {
			return $function[0] === $this;
		}
		return false;
	}

	protected function callback_matches_keywords( $function, $keywords ) {
		$haystack = '';
		if ( is_array( $function ) && is_object( $function[0] ) ) {
			$haystack = strtolower( get_class( $function[0] ) . $function[1] );
		} elseif ( is_array( $function ) && is_string( $function[0] ) ) {
			$haystack = strtolower( $function[0] . $function[1] );
		} elseif ( is_string( $function ) ) {
			$haystack = strtolower( $function );
		}
		if ( ! $haystack ) {
			return false;
		}
		foreach ( $keywords as $kw ) {
			if ( strpos( $haystack, $kw ) !== false ) {
				return true;
			}
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
		$step  = self::status_step( $row->aktueller_status ?: '' );
		$labels = array( '', __( 'Versendet', 'lindenweg16' ), __( 'Unterwegs', 'lindenweg16' ), __( 'In Zustellung', 'lindenweg16' ), __( 'Zugestellt', 'lindenweg16' ) );
		$colors = array( '', '#2271b1', '#ffb900', '#00a32a', '#00a32a' );
		$label  = $labels[ $step ] ?? $labels[1];
		$color  = $colors[ $step ] ?? $colors[1];
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
						admin_url( 'admin.php?page=lw16-betrieb-einstellungen&view=plugin' )
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
	 * Wohin nach dem Speichern oder Abrufen?
	 *
	 * Normalerweise zurück zur Bestellung. Kommt der Klick aus der
	 * Versandliste im Betrieb, gehört er auch dorthin zurück – sonst
	 * reisst jede eingetippte Sendungsnummer den Packer aus seiner Liste
	 * und er sucht sich die nächste Zeile neu.
	 *
	 * @param int $order_id Bestellung.
	 * @return string
	 */
	protected function after_action_url( $order_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- die Handler pruefen den Nonce vorher.
		$wunsch = isset( $_REQUEST['zurueck'] ) ? sanitize_key( wp_unslash( $_REQUEST['zurueck'] ) ) : '';

		if ( 'versand' === $wunsch ) {
			return admin_url( 'admin.php?page=lw16-betrieb-versand' );
		}

		if ( function_exists( 'wc_get_container' ) ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $order_id );
		}

		return admin_url( 'post.php?post=' . (int) $order_id . '&action=edit' );
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

		wp_safe_redirect( $this->after_action_url( $order_id ) );
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

		wp_safe_redirect( $this->after_action_url( $order_id ) );
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
			'_wc_shipment_tracking_items',
		);

		foreach ( $keys as $key ) {
			$val = $order->get_meta( $key );

			if ( $val ) {
				$number = self::flatten_tracking_meta( $val );

				if ( '' !== $number ) {
					return $number;
				}
			}
		}

		// Germanized Shipments fuehrt die Sendungsnummer nicht an der
		// Bestellung, sondern an der Sendung. Ohne diese Abfrage entfernt das
		// Plugin die Anzeige von Germanized und stellt nichts an ihre Stelle -
		// der Kunde saehe dann gar nichts mehr.
		if ( function_exists( 'wc_gzd_get_shipments_by_order' ) ) {
			foreach ( (array) wc_gzd_get_shipments_by_order( $order->get_id() ) as $shipment ) {
				if ( is_object( $shipment ) && method_exists( $shipment, 'get_tracking_id' ) ) {
					$id = (string) $shipment->get_tracking_id();

					if ( '' !== $id ) {
						return $id;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Sendungsnummer aus einem beliebig verschachtelten Metawert holen.
	 *
	 * Die Versandplugins legen ihre Nummern sehr verschieden ab: als
	 * Zeichenkette, als Liste, als Liste von Datensaetzen. Ein reset() auf die
	 * aeussere Ebene liefert dann ein Array statt einer Nummer.
	 *
	 * Reine Zeichenkettenlogik, damit sie pruefbar bleibt.
	 *
	 * @param mixed $value Metawert.
	 * @return string
	 */
	public static function flatten_tracking_meta( $value ) {
		if ( is_string( $value ) || is_numeric( $value ) ) {
			return trim( (string) $value );
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		// Bevorzugt benannte Felder, sonst der erste brauchbare Wert.
		foreach ( array( 'tracking_number', 'tracking_id', 'number', 'id' ) as $key ) {
			if ( isset( $value[ $key ] ) ) {
				$found = self::flatten_tracking_meta( $value[ $key ] );

				if ( '' !== $found ) {
					return $found;
				}
			}
		}

		foreach ( $value as $entry ) {
			$found = self::flatten_tracking_meta( $entry );

			if ( '' !== $found ) {
				return $found;
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
	 * Versandstatus-Stufe aus dem aktuellen Status ableiten (0–4).
	 */
	/**
	 * Fortschritt allein aus dem Bestellstatus – reine Logik.
	 *
	 * Solange keine Sendungsnummer vorliegt, ist der Bestellstatus die einzige
	 * ehrliche Auskunft, die sich dem Kunden geben lässt.
	 *
	 * @param object $order Bestellung.
	 * @return int|null Null, wenn keine Anzeige sinnvoll ist.
	 */
	public static function order_step( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) ) {
			return null;
		}

		return self::step_for_order_status( (string) $order->get_status() );
	}

	/**
	 * Zuordnung Bestellstatus zu Fortschrittsstufe – ohne WooCommerce.
	 *
	 * @param string $status Bestellstatus, mit oder ohne Präfix wc-.
	 * @return int|null Null bedeutet: gar nichts anzeigen.
	 */
	public static function step_for_order_status( $status ) {
		$status = strtolower( trim( (string) $status ) );
		$status = preg_replace( '/^wc-/', '', $status );

		// Bezahlt und in Arbeit: Stufe „Bestellt".
		if ( in_array( $status, array( 'processing', 'on-hold' ), true ) ) {
			return 0;
		}

		// In diesem Shop heißt „abgeschlossen" versendet.
		if ( 'completed' === $status ) {
			return 1;
		}

		// Alles andere – unbezahlt, storniert, erstattet, fehlgeschlagen –
		// bekommt keine Fortschrittsanzeige. Ein Fortschrittsbalken über einer
		// stornierten Bestellung wäre Hohn.
		return null;
	}

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
		return 1;
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

		// Ohne Sendungsnummer gab es frueher gar keine Anzeige. Der Kunde stand
		// dann vor einer leeren Seite und wusste nicht, ob seine Bestellung
		// ueberhaupt bearbeitet wird - gerade in den ersten Tagen, in denen es
		// noch keine Nummer gibt. Deshalb zeigt die Ansicht jetzt auch ohne
		// Sendungsnummer den Stand, abgeleitet aus dem Bestellstatus.
		$order_step = self::order_step( $order );

		if ( ! $row && null === $order_step ) {
			return;
		}

		$events = $row ? json_decode( (string) $row->events, true ) : array();

		if ( $row && $events ) {
			$step = self::status_step( $row->aktueller_status );
		} elseif ( $row ) {
			// Sendungsnummer da, aber noch kein Verlauf abgerufen.
			$step = 1;
		} else {
			$step = (int) $order_step;
		}

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

			<?php if ( $row && $row->voraussichtliches_lieferdatum && $step < 4 ) : ?>
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

			<?php if ( $row && $row->tracking_nummer ) : ?>
			<p class="lw16-tp-nr">
				<?php echo esc_html( sprintf( /* translators: %s: Sendungsnummer. */ __( 'Sendungsnummer: %s', 'lindenweg16' ), $row->tracking_nummer ) ); ?>
			</p>
			<?php endif; ?>

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
			<?php elseif ( $row ) : ?>
				<p class="lw16-tp-pending"><?php esc_html_e( 'Dein Paket wurde soeben versendet. Die Sendungsdetails werden in Kürze aktualisiert.', 'lindenweg16' ); ?></p>
			<?php else : ?>
				<p class="lw16-tp-pending"><?php esc_html_e( 'Sobald dein Paket unterwegs ist, erscheint hier die Sendungsnummer und der Verlauf.', 'lindenweg16' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}
}
