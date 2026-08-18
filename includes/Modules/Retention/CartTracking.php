<?php
/**
 * Warenkorbabbrecher-Erfassung und Erinnerungs-Mails.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Retention;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;
use Lindenweg16\Core\Scheduler;
use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Core\Calc;
use Lindenweg16\Core\Time;

/**
 * Erfasst nur Warenkörbe mit bekannter E-Mail (kein anonymes Tracking) und
 * verschickt per Action Scheduler ein bis zwei Erinnerungen.
 */
class CartTracking {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Bei Cart-Änderungen den Snapshot aktualisieren.
		add_action( 'woocommerce_cart_updated', array( $this, 'capture' ) );
		// E-Mail-Eingabe im Checkout erfassen.
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'capture_from_checkout' ) );
		// Umwandlung bei Bestellung markieren.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'mark_converted' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'mark_converted' ), 10, 1 );
		// Cron-Erinnerungen.
		add_action( Scheduler::HOOK_CART_REMINDERS, array( $this, 'send_reminders' ) );
	}

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_cart_tracking';
	}

	/**
	 * Aktuelle Kunden-E-Mail ermitteln (eingeloggt oder Checkout-Session).
	 *
	 * @return string
	 */
	protected function current_email() {
		if ( is_user_logged_in() ) {
			$u = wp_get_current_user();
			return $u->user_email;
		}
		if ( WC()->customer ) {
			$mail = WC()->customer->get_billing_email();
			if ( $mail ) {
				return $mail;
			}
		}
		return '';
	}

	/**
	 * Warenkorb erfassen/aktualisieren – nur mit bekannter E-Mail.
	 */
	public function capture() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		// Entscheidungsregel liegt in der getesteten Calc::cart_should_track().
		$erlaubt = Calc::cart_should_track(
			$this->current_email(),
			WC()->cart->is_empty(),
			is_admin() && ! wp_doing_ajax()
		);
		if ( ! $erlaubt ) {
			return;
		}

		$this->store( $this->current_email() );
	}

	/**
	 * E-Mail aus dem Checkout-Review übernehmen.
	 *
	 * @param string $post_data Serialisierte Formulardaten.
	 */
	public function capture_from_checkout( $post_data ) {
		parse_str( (string) $post_data, $data );
		$email = isset( $data['billing_email'] ) ? sanitize_email( $data['billing_email'] ) : $this->current_email();
		if ( is_email( $email ) && WC()->cart && ! WC()->cart->is_empty() ) {
			$this->store( $email );
		}
	}

	/**
	 * Snapshot speichern (ein offener Datensatz je E-Mail).
	 *
	 * @param string $email Kunden-E-Mail.
	 */
	protected function store( $email ) {
		global $wpdb;

		// woocommerce_cart_updated feuert auf nahezu jedem Frontend-Request.
		// Ohne Daempfer schreibt jede Seitenansicht eines eingeloggten Kunden
		// in die Tabelle - und setzt dabei die Erinnerungsfristen zurueck.
		$lock = 'lw16_cart_seen_' . md5( $email );
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		$snapshot = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$snapshot[] = array(
				'product_id' => $item['product_id'],
				'name'       => isset( $item['data'] ) ? $item['data']->get_name() : '',
				'qty'        => $item['quantity'],
			);
		}

		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE email = %s AND umgewandelt = 0 ORDER BY id DESC LIMIT 1', $email ) ); // phpcs:ignore

		$data = array(
			'email'         => $email,
			'user_id'       => get_current_user_id() ?: null,
			'cart_snapshot' => wp_json_encode( $snapshot ),
			'erstellt_am'   => Time::now(),
		);

		if ( $existing ) {
			// Aktualisieren und Erinnerungs-Timer zurücksetzen.
			$wpdb->update(
				self::table(),
				array_merge( $data, array( 'erinnert_am' => null, 'zweite_erinnerung_am' => null ) ),
				array( 'id' => $existing->id )
			);
		} else {
			$wpdb->insert( self::table(), $data, array( '%s', '%d', '%s', '%s' ) );
		}
	}

	/**
	 * Bei Bestellung den Warenkorb als umgewandelt markieren.
	 *
	 * @param int $order_id Bestell-ID.
	 */
	public function mark_converted( $order_or_id ) {
		$order = is_numeric( $order_or_id ) ? wc_get_order( $order_or_id ) : $order_or_id;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET umgewandelt = 1 WHERE email = %s AND umgewandelt = 0', $email ) ); // phpcs:ignore
	}

	/**
	 * Cron: fällige Erinnerungen verschicken.
	 */
	public function send_reminders() {
		global $wpdb;
		$erste_frist  = (float) Settings::get( 'cart_erinnerung_1_stunden', 3 );
		$zweite_frist = (float) Settings::get( 'cart_erinnerung_2_stunden', 48 );

		// Erste Erinnerung.
		$grenze1 = Time::ago( $erste_frist * HOUR_IN_SECONDS );
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE umgewandelt = 0 AND erinnert_am IS NULL AND erstellt_am <= %s LIMIT 50', $grenze1 ) ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$this->send_mail( $row, 1 );
			$wpdb->update( self::table(), array( 'erinnert_am' => Time::now() ), array( 'id' => $row->id ) );
		}

		// Zweite Erinnerung.
		if ( $zweite_frist > 0 ) {
			$grenze2 = Time::ago( $zweite_frist * HOUR_IN_SECONDS );
			$rows2   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE umgewandelt = 0 AND erinnert_am IS NOT NULL AND zweite_erinnerung_am IS NULL AND erstellt_am <= %s LIMIT 50', $grenze2 ) ); // phpcs:ignore
			foreach ( $rows2 as $row ) {
				$this->send_mail( $row, 2 );
				$wpdb->update( self::table(), array( 'zweite_erinnerung_am' => Time::now() ), array( 'id' => $row->id ) );
			}
		}
	}

	/**
	 * Erinnerungs-Mail verschicken.
	 *
	 * @param object $row   Datensatz.
	 * @param int    $stufe 1 oder 2.
	 */
	protected function send_mail( $row, $stufe ) {
		$items = json_decode( (string) $row->cart_snapshot, true );
		if ( empty( $items ) ) {
			return;
		}
		$liste = '';
		foreach ( $items as $it ) {
			$liste .= '- ' . $it['name'] . ' x' . $it['qty'] . "\n";
		}
		$template_key = 1 === $stufe ? 'cart_erinnerung_1' : 'cart_erinnerung_2';
		MailTemplate::send( $row->email, $template_key, array(
			'{artikelliste}' => $liste,
			'{shop_url}'     => wc_get_cart_url(),
		) );
	}
}
