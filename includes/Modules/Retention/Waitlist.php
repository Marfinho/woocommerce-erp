<?php
/**
 * „Wieder verfügbar"-Warteliste.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Retention;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Modules\Inventory\Repository;

/**
 * Formular auf ausverkauften Produktseiten und automatische Benachrichtigung,
 * sobald der Bestand wieder über 0 steigt (jede Anmeldung nur einmal).
 */
class Waitlist {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Formular unter dem ausverkauften Produkt.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_form' ), 35 );
		add_action( 'admin_post_lw16_waitlist_signup', array( $this, 'handle_signup' ) );
		add_action( 'admin_post_nopriv_lw16_waitlist_signup', array( $this, 'handle_signup' ) );

		// Auslöser: Lagerzugang über 0 (aus StockManager).
		add_action( 'lw16_stock_replenished', array( $this, 'notify' ), 10, 2 );
	}

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_warteliste';
	}

	/**
	 * Formular nur anzeigen, wenn das verknüpfte Modul ausverkauft ist.
	 */
	public function render_form() {
		global $product;
		if ( ! $product ) {
			return;
		}
		$produkt = Repository::get_by_woo_id( $product->get_id() );
		if ( ! $produkt ) {
			return;
		}
		if ( Repository::get_stock( $produkt->id ) > 0 ) {
			return;
		}

		$email = '';
		if ( is_user_logged_in() ) {
			$u     = wp_get_current_user();
			$email = $u->user_email;
		}

		echo '<div class="lw16-waitlist">';
		echo '<h4>' . esc_html__( 'Zurzeit nicht auf Lager', 'lindenweg16' ) . '</h4>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="lw16_waitlist_signup">';
		echo '<input type="hidden" name="produkt_id" value="' . (int) $produkt->id . '">';
		echo '<input type="hidden" name="redirect" value="' . esc_url( get_permalink( $product->get_id() ) ) . '">';
		wp_nonce_field( 'lw16_waitlist' );
		echo '<input type="email" name="email" required placeholder="' . esc_attr__( 'Deine E-Mail', 'lindenweg16' ) . '" value="' . esc_attr( $email ) . '"> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Benachrichtige mich, wenn wieder verfügbar', 'lindenweg16' ) . '</button>';
		echo '<p class="lw16-privacy"><small>' . esc_html__( 'Deine E-Mail-Adresse wird ausschließlich für diese eine Benachrichtigung verwendet, keine sonstige Nutzung ohne separates Opt-in.', 'lindenweg16' ) . '</small></p>';
		echo '</form></div>';
	}

	/**
	 * Anmeldung speichern.
	 */
	public function handle_signup() {
		check_admin_referer( 'lw16_waitlist' );
		global $wpdb;

		$produkt_id = (int) ( $_POST['produkt_id'] ?? 0 );
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$redirect   = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : home_url();

		if ( $produkt_id && is_email( $email ) ) {
			// Doppelte offene Anmeldung vermeiden.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE produkt_id = %d AND email = %s AND benachrichtigt = 0', $produkt_id, $email ) ); // phpcs:ignore
			if ( ! $exists ) {
				$wpdb->insert(
					self::table(),
					array(
						'produkt_id'    => $produkt_id,
						'email'         => $email,
						'user_id'       => get_current_user_id() ?: null,
						'benachrichtigt' => 0,
						'erstellt_am'   => current_time( 'mysql' ),
					),
					array( '%d', '%s', '%d', '%d', '%s' )
				);
			}
		}

		wp_safe_redirect( add_query_arg( 'lw16_waitlist', 'ok', $redirect ) );
		exit;
	}

	/**
	 * Alle offenen Anmeldungen für ein Produkt benachrichtigen.
	 *
	 * @param int   $produkt_id    Produkt-ID.
	 * @param float $neuer_bestand Bestand.
	 */
	public function notify( $produkt_id, $neuer_bestand ) {
		global $wpdb;
		$produkt = Repository::get_product( $produkt_id );
		if ( ! $produkt || empty( $produkt->woo_product_id ) ) {
			return;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE produkt_id = %d AND benachrichtigt = 0', $produkt_id ) ); // phpcs:ignore
		if ( empty( $rows ) ) {
			return;
		}

		$wc   = wc_get_product( $produkt->woo_product_id );
		$name = $wc ? $wc->get_name() : $produkt->name;
		$link = $wc ? get_permalink( $produkt->woo_product_id ) : home_url();

		foreach ( $rows as $row ) {
			MailTemplate::send( $row->email, 'wieder_verfuegbar', array(
				'{produktname}' => $name,
				'{produktlink}' => $link,
			) );
			$wpdb->update( self::table(), array( 'benachrichtigt' => 1 ), array( 'id' => $row->id ) );
		}
	}
}
