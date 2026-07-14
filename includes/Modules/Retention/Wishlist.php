<?php
/**
 * Wunschliste mit Mein-Konto-Tab und Admin-Report.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Retention;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Inventory\Repository;

/**
 * „Merken"-Funktion für eingeloggte Kunden, Mein-Konto-Tab und ein
 * einfacher „Meistgewünschte Produkte"-Report als Nachfragesignal.
 */
class Wishlist {

	const ENDPOINT = 'wunschliste';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_account' ) );

		// Merken-Button auf Übersicht + Detailseite.
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_button' ), 15 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_button' ), 33 );

		// AJAX toggle.
		add_action( 'wp_ajax_lw16_wishlist_toggle', array( $this, 'ajax_toggle' ) );

		// Assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_wishlist';
	}

	/**
	 * Endpoint registrieren.
	 */
	public function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Query-Var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Menüpunkt.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public function menu_item( $items ) {
		$new = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Wunschliste', 'lindenweg16' );
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'Wunschliste', 'lindenweg16' );
		}
		return $new;
	}

	/**
	 * Assets laden (nur wo gebraucht).
	 */
	public function enqueue() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( ! ( is_shop() || is_product() || is_product_category() || is_product_tag() || is_account_page() || is_woocommerce() ) ) {
			return;
		}
		wp_enqueue_style( 'lw16-frontend', LW16_PLUGIN_URL . 'assets/css/frontend.css', array(), LW16_VERSION );
		wp_enqueue_script( 'lw16-wishlist', LW16_PLUGIN_URL . 'assets/js/wishlist.js', array( 'jquery' ), LW16_VERSION, true );
		wp_localize_script(
			'lw16-wishlist',
			'LW16Wishlist',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lw16_wishlist' ),
			)
		);
	}

	/**
	 * Merken-Button rendern.
	 */
	public function render_button() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		global $product;
		if ( ! $product ) {
			return;
		}
		$pid    = $product->get_id();
		$active = $this->has( get_current_user_id(), $pid );
		echo '<button type="button" class="button lw16-wishlist-btn" data-product="' . (int) $pid . '" aria-pressed="' . ( $active ? 'true' : 'false' ) . '">';
		echo '<span class="lw16-heart">' . ( $active ? '♥' : '♡' ) . '</span> ' . esc_html__( 'Merken', 'lindenweg16' );
		echo '</button>';
	}

	/**
	 * AJAX: Wunschliste umschalten.
	 */
	public function ajax_toggle() {
		check_ajax_referer( 'lw16_wishlist', 'nonce' );
		$user_id = get_current_user_id();
		$pid     = absint( $_POST['product'] ?? 0 );
		if ( ! $user_id || ! $pid ) {
			wp_send_json_error();
		}
		global $wpdb;
		if ( $this->has( $user_id, $pid ) ) {
			$wpdb->delete( self::table(), array( 'user_id' => $user_id, 'woo_product_id' => $pid ) );
			wp_send_json_success( array( 'active' => false ) );
		}
		$wpdb->insert(
			self::table(),
			array( 'user_id' => $user_id, 'woo_product_id' => $pid, 'hinzugefuegt_am' => current_time( 'mysql' ) ),
			array( '%d', '%d', '%s' )
		);
		wp_send_json_success( array( 'active' => true ) );
	}

	/**
	 * Prüfen, ob Produkt gemerkt ist.
	 *
	 * @param int $user_id Nutzer.
	 * @param int $pid     Produkt.
	 * @return bool
	 */
	public function has( $user_id, $pid ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE user_id = %d AND woo_product_id = %d', $user_id, $pid ) ); // phpcs:ignore
	}

	/**
	 * Mein-Konto-Tab rendern.
	 */
	public function render_account() {
		$user_id = get_current_user_id();
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY hinzugefuegt_am DESC', $user_id ) ); // phpcs:ignore

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Deine Wunschliste ist noch leer.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<div class="lw16-wishlist-grid">';
		foreach ( $rows as $row ) {
			$wc = wc_get_product( $row->woo_product_id );
			if ( ! $wc ) {
				continue;
			}
			$produkt   = Repository::get_by_woo_id( $row->woo_product_id );
			$bestand   = $produkt ? Repository::get_stock( $produkt->id ) : null;
			$verfuegbar = null === $bestand ? $wc->is_in_stock() : ( $bestand > 0 );

			echo '<div class="lw16-wishlist-card">';
			echo '<a href="' . esc_url( get_permalink( $row->woo_product_id ) ) . '">' . $wc->get_image( 'woocommerce_thumbnail' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<h4>' . esc_html( $wc->get_name() ) . '</h4>';
			echo '<div class="lw16-wishlist-price">' . wp_kses_post( $wc->get_price_html() ) . '</div>';

			if ( $verfuegbar ) {
				echo '<p class="lw16-instock">' . esc_html__( 'Verfügbar', 'lindenweg16' ) . '</p>';
				echo '<a class="button" href="' . esc_url( '?add-to-cart=' . $row->woo_product_id ) . '">' . esc_html__( 'In den Warenkorb', 'lindenweg16' ) . '</a> ';
			} else {
				echo '<p class="lw16-outofstock">' . esc_html__( 'Nicht auf Lager', 'lindenweg16' ) . '</p>';
				echo '<a class="button" href="' . esc_url( get_permalink( $row->woo_product_id ) . '#lw16-waitlist' ) . '">' . esc_html__( 'Benachrichtigen', 'lindenweg16' ) . '</a> ';
			}
			echo '<button type="button" class="button lw16-wishlist-btn" data-product="' . (int) $row->woo_product_id . '" aria-pressed="true">' . esc_html__( 'Entfernen', 'lindenweg16' ) . '</button>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Admin-Report: meistgewünschte Produkte (Transient-Cache).
	 *
	 * @return array wooProductId => Anzahl.
	 */
	public static function most_wished( $limit = 20 ) {
		$cache = get_transient( 'lw16_most_wished' );
		if ( false !== $cache ) {
			return $cache;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT woo_product_id, COUNT(*) AS anzahl FROM ' . self::table() . ' GROUP BY woo_product_id ORDER BY anzahl DESC LIMIT %d', $limit ) ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->woo_product_id ] = (int) $r->anzahl;
		}
		set_transient( 'lw16_most_wished', $out, 30 * MINUTE_IN_SECONDS );
		return $out;
	}
}
