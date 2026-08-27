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

/**
 * Erfasst nur Warenkörbe mit bekannter E-Mail (kein anonymes Tracking) und
 * verschickt per Action Scheduler ein bis zwei Erinnerungen.
 */
class CartTracking {

	/**
	 * Fingerabdruck des zuletzt geschriebenen Warenkorbs in diesem Aufruf.
	 *
	 * @var string|null
	 */
	protected static $last_write = null;

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
		if ( is_admin() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		if ( WC()->cart->is_empty() ) {
			return;
		}
		$email = $this->current_email();
		if ( ! is_email( $email ) ) {
			return;
		}
		$this->store( $email );
	}

	/**
	 * E-Mail aus dem Checkout-Review übernehmen.
	 *
	 * @param string $post_data Serialisierte Formulardaten.
	 */
	public function capture_from_checkout( $post_data ) {
		// Dieser Haken laeuft in der AJAX-Antwort, aus der WooCommerce die
		// Bestelluebersicht samt Versandart-Auswahl neu aufbaut. Alles, was
		// hier eine Warnung oder Ausgabe erzeugt, zerstoert die Antwort - und
		// dann bleibt die Versandart ungesetzt, obwohl der Kunde alles
		// ausgefuellt hat. Deshalb hier ausdruecklich still und defensiv.
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		$data = array();
		parse_str( (string) $post_data, $data );

		$email = isset( $data['billing_email'] ) ? sanitize_email( $data['billing_email'] ) : $this->current_email();

		if ( is_email( $email ) ) {
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

		if ( ! self::table_exists() ) {
			return;
		}

		// Datenbankfehler nie ausgeben: Im Checkout landet die Ausgabe sonst
		// mitten in der JSON-Antwort.
		$show_errors = $wpdb->hide_errors();

		$snapshot = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$snapshot[] = array(
				'product_id' => $item['product_id'],
				'name'       => isset( $item['data'] ) ? $item['data']->get_name() : '',
				'qty'        => $item['quantity'],
			);
		}

		// `woocommerce_cart_updated` faellt mehrfach je Seitenaufbau an, und
		// im Checkout schickt WooCommerce bei jeder Eingabe eine neue
		// Uebersicht. Steht dasselbe schon in der Zeile, waeren Abfrage und
		// Schreibvorgang reine Last – gerade im Checkout, wo es auf jede
		// Millisekunde ankommt.
		$fingerprint = md5( $email . '|' . wp_json_encode( $snapshot ) );

		if ( self::$last_write === $fingerprint ) {
			if ( $show_errors ) {
				$wpdb->show_errors();
			}
			return;
		}

		self::$last_write = $fingerprint;

		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE email = %s AND umgewandelt = 0 ORDER BY id DESC LIMIT 1', $email ) ); // phpcs:ignore

		$data = array(
			'email'         => $email,
			'user_id'       => get_current_user_id() ?: null,
			'cart_snapshot' => wp_json_encode( $snapshot ),
			'erstellt_am'   => current_time( 'mysql' ),
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

		if ( $show_errors ) {
			$wpdb->show_errors();
		}
	}

	/**
	 * Gibt es die Tabelle überhaupt?
	 *
	 * Einmal je Aufruf geprüft und gemerkt. Ohne diese Prüfung liefe im
	 * Checkout bei jedem Tastendruck eine Abfrage gegen eine fehlende
	 * Tabelle – mit sichtbarer Fehlermeldung, sobald WP_DEBUG an ist.
	 *
	 * @return bool
	 */
	protected static function table_exists() {
		static $exists = null;

		if ( null !== $exists ) {
			return $exists;
		}

		global $wpdb;

		$table  = self::table();
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $exists;
	}

	/**
	 * Bei Bestellung den Warenkorb als umgewandelt markieren.
	 *
	 * @param int $order_id Bestell-ID.
	 */
	public function mark_converted( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
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
		$grenze1 = gmdate( 'Y-m-d H:i:s', time() - $erste_frist * HOUR_IN_SECONDS );
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE umgewandelt = 0 AND erinnert_am IS NULL AND erstellt_am <= %s LIMIT 50', $grenze1 ) ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$this->send_mail( $row, 1 );
			$wpdb->update( self::table(), array( 'erinnert_am' => current_time( 'mysql' ) ), array( 'id' => $row->id ) );
		}

		// Zweite Erinnerung.
		if ( $zweite_frist > 0 ) {
			$grenze2 = gmdate( 'Y-m-d H:i:s', time() - $zweite_frist * HOUR_IN_SECONDS );
			$rows2   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE umgewandelt = 0 AND erinnert_am IS NOT NULL AND zweite_erinnerung_am IS NULL AND erstellt_am <= %s LIMIT 50', $grenze2 ) ); // phpcs:ignore
			foreach ( $rows2 as $row ) {
				$this->send_mail( $row, 2 );
				$wpdb->update( self::table(), array( 'zweite_erinnerung_am' => current_time( 'mysql' ) ), array( 'id' => $row->id ) );
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
