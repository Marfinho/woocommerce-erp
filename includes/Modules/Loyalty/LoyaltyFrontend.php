<?php
/**
 * Frontend der Loyalty: Checkout-Einlösung und Mein-Konto-Tab „Prämien".
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Loyalty;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;

use Lindenweg16\Core\Settings;
use Lindenweg16\Core\Calc;

/**
 * Bindet Guthaben-/Punkte-Einlösung im Checkout ein und stellt den
 * Prämien-Bereich im Kundenkonto dar. Margen/Formeln bleiben unsichtbar.
 */
class LoyaltyFrontend {

	const ENDPOINT = 'praemien';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Mein-Konto-Tab.
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_account' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );

		// Checkout-Einlösung.
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'render_redeem_ui' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_lw16_set_redeem', array( $this, 'ajax_set_redeem' ) );
		add_action( 'wp_ajax_nopriv_lw16_set_redeem', array( $this, 'ajax_set_redeem' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_redeem_fee' ) );

		// Produktseite: Wie viele Punkte bringt das hier?
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_points' ), 11 );

		// Verbuchung bei Bestellabschluss.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'record_redemption' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'record_redemption_block' ), 10, 1 );
	}

	/* --------------------------------------------------------------------
	 * Mein-Konto-Tab
	 * ------------------------------------------------------------------ */

	/**
	 * Endpoint registrieren.
	 */
	public function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Query-Var eintragen.
	 *
	 * @param array $vars Query-Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Menüpunkt einfügen (vor „Abmelden").
	 *
	 * @param array $items Menüpunkte.
	 * @return array
	 */
	public function menu_item( $items ) {
		$new = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Prämien', 'lindenweg16' );
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'Prämien', 'lindenweg16' );
		}
		return $new;
	}

	/**
	 * Prämien-Seite rendern. Zeigt nur fertige Beträge, nie Margen/Formeln.
	 */
	public function render_account() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$code    = LoyaltyRepository::get_or_create_code( $user_id );
		$credit  = LoyaltyRepository::get_credit( $user_id );
		$points  = LoyaltyRepository::get_points( $user_id );
		$link    = home_url( '/?' . LoyaltyModule::COOKIE . '=' . rawurlencode( $code ) );

		echo '<div class="lw16-praemien">';
		echo '<h3>' . esc_html__( 'Dein Empfehlungslink', 'lindenweg16' ) . '</h3>';
		echo '<div class="lw16-copy-row"><input type="text" readonly value="' . esc_attr( $link ) . '" id="lw16-ref-link" class="lw16-ref-input">';
		echo '<button type="button" class="button" id="lw16-copy-btn" data-target="lw16-ref-link">' . esc_html__( 'Kopieren', 'lindenweg16' ) . '</button></div>';

		echo '<div class="lw16-balances">';
		echo '<div class="lw16-balance"><span class="lw16-balance-label">' . esc_html__( 'Guthaben', 'lindenweg16' ) . '</span><span class="lw16-balance-value">' . esc_html( number_format( $credit, 2, ',', '.' ) ) . ' €</span></div>';
		echo '<div class="lw16-balance"><span class="lw16-balance-label">' . esc_html__( 'Punkte', 'lindenweg16' ) . '</span><span class="lw16-balance-value">' . esc_html( number_format( $points, 0, ',', '.' ) ) . '</span></div>';
		echo '</div>';

		// Empfehlungen.
		echo '<h3>' . esc_html__( 'Deine Empfehlungen', 'lindenweg16' ) . '</h3>';
		$refs = LoyaltyRepository::referrals_of( $user_id );
		if ( empty( $refs ) ) {
			echo '<p>' . esc_html__( 'Noch keine Empfehlungen.', 'lindenweg16' ) . '</p>';
		} else {
			echo '<table class="woocommerce-orders-table shop_table"><thead><tr>';
			echo '<th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Guthaben', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Käufer', 'lindenweg16' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $refs as $r ) {
				echo '<tr>';
				echo '<td>' . esc_html( date_i18n( 'd.m.Y', strtotime( $r->created_at ) ) ) . '</td>';
				echo '<td>' . esc_html( $this->status_label( $r->status ) ) . '</td>';
				echo '<td>' . esc_html( number_format( (float) $r->reward_amount, 2, ',', '.' ) ) . ' €</td>';
				echo '<td>' . esc_html( $this->anon_buyer( $r->referred_user_id ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		// Punkte-Verlauf (paginiert).
		echo '<h3>' . esc_html__( 'Punkte-Verlauf', 'lindenweg16' ) . '</h3>';
		$per_page    = 15;
		$ledger_page = isset( $_GET['lp'] ) ? max( 1, (int) $_GET['lp'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$ledger      = LoyaltyRepository::ledger_of( $user_id, $per_page, $ledger_page );
		$ledger_total = LoyaltyRepository::ledger_count( $user_id );
		if ( empty( $ledger ) ) {
			echo '<p>' . esc_html__( 'Noch keine Punktebewegungen.', 'lindenweg16' ) . '</p>';
		} else {
			echo '<table class="woocommerce-orders-table shop_table"><thead><tr>';
			echo '<th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Bestellung', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Punkte', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Grund', 'lindenweg16' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $ledger as $l ) {
				echo '<tr>';
				echo '<td>' . esc_html( date_i18n( 'd.m.Y', strtotime( $l->created_at ) ) ) . '</td>';
				echo '<td>' . ( $l->order_id ? '#' . (int) $l->order_id : '–' ) . '</td>';
				echo '<td>' . esc_html( ( $l->points > 0 ? '+' : '' ) . $l->points ) . '</td>';
				echo '<td>' . $this->ledger_status_badge( $l ) . '</td>';
				echo '<td>' . esc_html( $this->ledger_label( $l ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			$total_pages = (int) ceil( $ledger_total / $per_page );
			if ( $total_pages > 1 ) {
				$endpoint_url = wc_get_account_endpoint_url( self::ENDPOINT );
				echo '<div class="lw16-pagination">';
				for ( $i = 1; $i <= $total_pages; $i++ ) {
					if ( $i === $ledger_page ) {
						echo '<span class="current">' . (int) $i . '</span> ';
					} else {
						echo '<a href="' . esc_url( add_query_arg( 'lp', $i, $endpoint_url ) ) . '">' . (int) $i . '</a> ';
					}
				}
				echo '</div>';
			}
		}
		echo '</div>';
	}

	/**
	 * Hinweis unter dem Preis: Punkte für dieses Produkt, mit laufender Aktion.
	 */
	public function render_product_points() {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$per_euro = (float) Settings::get( 'punkte_pro_euro', 1 );
		$price    = (float) wc_get_price_including_tax( $product );
		if ( $per_euro <= 0 || $price <= 0 ) {
			return;
		}
		$cats   = array_map( 'intval', $product->get_category_ids() );
		$active = LoyaltyCampaigns::active();
		$result = LoyaltyRules::order_points( $price, array( array( 'total' => $price, 'cats' => $cats ) ), $per_euro, $active );
		$factor = LoyaltyTiers::factor( get_current_user_id() );
		if ( $factor > 1 ) {
			$result['points'] = LoyaltyRules::apply_tier( $result['points'], $factor );
		}
		if ( $result['points'] <= 0 ) {
			return;
		}

		echo '<p class="lw16-produkt-punkte' . ( $result['bonus'] > 0 ? ' is-aktion' : '' ) . '">';
		/* translators: %s: Punkte */
		echo '<span>' . esc_html( sprintf( __( '%s Prämienpunkte sammeln', 'lindenweg16' ), number_format_i18n( $result['points'] ) ) ) . '</span>';
		if ( $result['bonus'] > 0 ) {
			$until = '';
			foreach ( $active as $c ) {
				if ( in_array( $c['name'], $result['names'], true ) ) {
					$until = date_i18n( 'j. F', strtotime( $c['bis'] ) );
					break;
				}
			}
			/* translators: 1: Aktionsname, 2: Datum */
			echo ' <small>' . esc_html( sprintf( __( '%1$s – bis %2$s', 'lindenweg16' ), implode( ', ', $result['names'] ), $until ) ) . '</small>';
		}
		echo '</p>';
	}

	/* --------------------------------------------------------------------
	 * Checkout-Einlösung
	 * ------------------------------------------------------------------ */

	/**
	 * Assets nur im Checkout / Kundenkonto laden.
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_checkout' ) ) {
			return;
		}
		// Gestaltung von Punkten, Einlösen und Bewertungsfotos: auch auf
		// Produktseite und im Warenkorb.
		if ( is_checkout() || is_account_page() || is_cart() || is_product() ) {
			wp_enqueue_style( 'lw16-frontend', LW16_PLUGIN_URL . 'assets/css/frontend.css', array(), Assets::version( 'assets/css/frontend.css' ) );
		}
		if ( ! is_checkout() && ! is_account_page() ) {
			return;
		}
		wp_enqueue_script( 'lw16-loyalty', LW16_PLUGIN_URL . 'assets/js/loyalty.js', array( 'jquery' ), Assets::version( 'assets/js/loyalty.js' ), true );
		$credit_balance = 0;
		if ( is_user_logged_in() ) {
			$credit_balance = LoyaltyRepository::get_credit( get_current_user_id() );
		}
		wp_localize_script(
			'lw16-loyalty',
			'LW16Loyalty',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'lw16_redeem' ),
				'pointsPerEuro' => (int) Settings::get( 'punkte_einloesewert', 100 ),
				'creditBalance' => (string) $credit_balance,
			)
		);
	}

	/**
	 * Einlöse-Block im Checkout ausgeben.
	 */
	public function render_redeem_ui() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$credit = LoyaltyRepository::get_credit( $user_id );
		$points = LoyaltyRepository::get_points( $user_id );
		if ( $credit <= 0 && $points <= 0 ) {
			return;
		}

		$session = WC()->session ? WC()->session->get( 'lw16_redeem', array() ) : array();
		$use_credit = isset( $session['credit'] ) ? (float) $session['credit'] : 0;
		$use_points = isset( $session['points'] ) ? (int) $session['points'] : 0;

		echo '<tr class="lw16-redeem-row"><td colspan="2">';
		echo '<div class="lw16-redeem">';
		echo '<strong>' . esc_html__( 'Guthaben einlösen', 'lindenweg16' ) . '</strong>';
		if ( $credit > 0 ) {
			echo '<p><label><input type="checkbox" id="lw16-use-credit" ' . checked( $use_credit > 0, true, false ) . '> ';
			echo esc_html( sprintf( /* translators: %s: Betrag */ __( 'Referral-Guthaben einsetzen (%s € verfügbar)', 'lindenweg16' ), number_format( $credit, 2, ',', '.' ) ) ) . '</label></p>';
		}
		if ( $points > 0 ) {
			echo '<p><label>' . esc_html__( 'Punkte einlösen:', 'lindenweg16' ) . ' ';
			echo '<input type="number" id="lw16-use-points" min="0" max="' . esc_attr( $points ) . '" step="' . esc_attr( (int) Settings::get( 'punkte_einloesewert', 100 ) ) . '" value="' . esc_attr( $use_points ) . '"> ';
			echo esc_html( sprintf( /* translators: %d: Punkte */ __( 'von %d', 'lindenweg16' ), $points ) ) . '</label> ';
			echo '<span id="lw16-points-euro" class="lw16-points-euro"></span></p>';
		}
		echo '<button type="button" class="button" id="lw16-apply-redeem">' . esc_html__( 'Anwenden', 'lindenweg16' ) . '</button>';
		echo '</div></td></tr>';
	}

	/**
	 * AJAX: Einlöse-Wunsch in der Session speichern und Cart neu rechnen.
	 */
	public function ajax_set_redeem() {
		check_ajax_referer( 'lw16_redeem', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error();
		}

		// Guthaben ganz oder gar nicht, Punkte zwischen 0 und Kontostand – ein
		// negativer Wert aus dem Formular wäre ein Aufschlag statt Rabatt.
		self::set_redeem(
			$user_id,
			! empty( $_POST['use_credit'] ) && (float) $_POST['use_credit'] > 0,
			isset( $_POST['use_points'] ) ? absint( $_POST['use_points'] ) : 0
		);
		wp_send_json_success();
	}

	/**
	 * Einlösewunsch in der Sitzung merken (klassische Kasse per AJAX, Block-
	 * Kasse per Store-API, siehe LoyaltyCart::store_update()).
	 *
	 * @param int  $user_id     Kunde.
	 * @param bool $want_credit Guthaben einsetzen?
	 * @param int  $want_points Punkte einsetzen.
	 * @return array{credit:float,points:int} Was gemerkt wurde.
	 */
	public static function set_redeem( $user_id, $want_credit, $want_points ) {
		$use = LoyaltyRules::redeem_request( $want_credit, $want_points, LoyaltyRepository::get_credit( $user_id ), LoyaltyRepository::get_points( $user_id ) );
		if ( WC()->session ) {
			WC()->session->set( 'lw16_redeem', $use );
		}
		return $use;
	}

	/**
	 * Der Einlösewunsch aus der Sitzung, begrenzt auf den aktuellen Kontostand
	 * (der kann sich seit dem Merken geändert haben, z. B. durch Verfall oder
	 * eine Bestellung in einem anderen Tab).
	 *
	 * @param int $user_id Kunde.
	 * @return array{credit:float,points:int}
	 */
	public static function current_redeem( $user_id ) {
		$session = WC()->session ? (array) WC()->session->get( 'lw16_redeem', array() ) : array();
		return array(
			'credit' => min( max( 0, (float) ( $session['credit'] ?? 0 ) ), LoyaltyRepository::get_credit( $user_id ) ),
			'points' => min( max( 0, (int) ( $session['points'] ?? 0 ) ), LoyaltyRepository::get_points( $user_id ) ),
		);
	}

	/**
	 * Einlösung als negative Fee anwenden, gedeckelt auf die Bestellsumme.
	 *
	 * @param \WC_Cart $cart Warenkorb.
	 */
	public function apply_redeem_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id || ! WC()->session ) {
			return;
		}
		$use = self::current_redeem( $user_id );
		if ( $use['credit'] <= 0 && $use['points'] <= 0 ) {
			return;
		}
		if ( ! LoyaltyRules::redeem_allowed( (float) $cart->get_subtotal(), (float) Settings::get( 'einloesen_min_bestellwert', 0 ) ) ) {
			return;
		}

		$einloesewert = max( 1, (int) Settings::get( 'punkte_einloesewert', 100 ) );
		$points_euro  = Calc::points_to_euro( $use['points'], $einloesewert );

		// Deckeln auf die Warenkorbsumme (nie unter 0 €).
		$rabatt = Calc::cap_redemption( $use['credit'] + $points_euro, (float) $cart->get_subtotal() );
		if ( $rabatt > 0 ) {
			$cart->add_fee( __( 'Guthaben/Punkte eingelöst', 'lindenweg16' ), -1 * $rabatt );
		}
	}

	/**
	 * Klassische Kasse: Einlösung nach dem Bestellen verbuchen.
	 *
	 * @param int $order_id Bestell-ID.
	 */
	public function record_redemption( $order_id ) {
		$this->record_for_order( wc_get_order( $order_id ) );
	}

	/**
	 * Block-Kasse: dasselbe über die Store-API.
	 *
	 * @param \WC_Order $order Bestellung.
	 */
	public function record_redemption_block( $order ) {
		$this->record_for_order( $order );
	}

	/**
	 * Einlösung ins Ledger schreiben und Salden mindern – genau einmal je
	 * Bestellung, und nur so viel, wie der gedeckelte Rabatt wirklich
	 * ausmacht (bisher wurde bei großem Guthaben alles abgebucht, auch wenn
	 * der Rabatt auf die Warenkorbsumme begrenzt war).
	 *
	 * @param \WC_Order|false $order Bestellung.
	 */
	protected function record_for_order( $order ) {
		if ( ! $order instanceof \WC_Order || ! WC()->session || $order->get_meta( '_lw16_redeem_recorded' ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id || (int) $order->get_customer_id() !== $user_id ) {
			return;
		}
		$use = self::current_redeem( $user_id );
		if ( $use['credit'] <= 0 && $use['points'] <= 0 ) {
			return;
		}

		if ( ! LoyaltyRules::redeem_allowed( (float) $order->get_subtotal(), (float) Settings::get( 'einloesen_min_bestellwert', 0 ) ) ) {
			return;
		}

		$split = LoyaltyRules::redeem_split( $use['credit'], $use['points'], (int) Settings::get( 'punkte_einloesewert', 100 ), (float) $order->get_subtotal() );

		if ( $split['credit'] > 0 ) {
			LoyaltyRepository::adjust_credit( $user_id, -1 * $split['credit'] );
		}
		if ( $split['points'] > 0 ) {
			LoyaltyRepository::adjust_points( $user_id, -1 * $split['points'] );
			LoyaltyRepository::add_ledger(
				array(
					'user_id'  => $user_id,
					'order_id' => $order->get_id(),
					'points'   => -1 * $split['points'],
					'type'     => 'redeemed',
					'status'   => 'available',
					'notiz'    => __( 'Im Checkout eingelöst', 'lindenweg16' ),
				)
			);
		}

		// Einlösen ist Aktivität: die Verfallsfrist beginnt neu.
		LoyaltyRepository::touch( $user_id );

		$order->update_meta_data( '_lw16_redeem_recorded', wp_json_encode( $split ) );
		$order->save();

		// Reservierung aufheben.
		WC()->session->set( 'lw16_redeem', array() );
	}

	/* --------------------------------------------------------------------
	 * Helfer
	 * ------------------------------------------------------------------ */

	/**
	 * Käufernamen anonymisieren (Vorname + erster Buchstabe Nachname).
	 *
	 * @param int|null $user_id Käufer.
	 * @return string
	 */
	protected function anon_buyer( $user_id ) {
		if ( ! $user_id ) {
			return __( 'Gast', 'lindenweg16' );
		}
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return __( 'Kunde', 'lindenweg16' );
		}
		$first = $u->first_name ? $u->first_name : $u->display_name;
		$last  = $u->last_name ? mb_substr( $u->last_name, 0, 1 ) . '.' : '';
		return trim( $first . ' ' . $last );
	}

	/**
	 * Status-Label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	protected function status_label( $status ) {
		$map = array(
			'pending'   => __( 'in Prüfung', 'lindenweg16' ),
			'review'    => __( 'in Prüfung', 'lindenweg16' ),
			'completed' => __( 'gutgeschrieben', 'lindenweg16' ),
			'reversed'  => __( 'storniert', 'lindenweg16' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}

	/**
	 * Ledger-Grund-Label.
	 *
	 * @param object $l Ledger-Eintrag.
	 * @return string
	 */
	protected function ledger_label( $l ) {
		$map = LoyaltyRepository::ledger_types();
		$out = isset( $map[ $l->type ] ) ? $map[ $l->type ] : $l->type;
		// Aktionsbonus steht in der Notiz („Bestellung · Herbstaktion (+40)“).
		if ( 'earned' === $l->type && ! empty( $l->notiz ) && false !== strpos( (string) $l->notiz, '·' ) ) {
			$out = (string) $l->notiz;
		}
		return $out;
	}

	/**
	 * Status-Badge mit Farbe und Tooltip für Ledger-Eintrag.
	 */
	protected function ledger_status_badge( $l ) {
		$status = $l->status ?? 'available';

		if ( 'pending' === $status ) {
			$label = __( 'Ausstehend', 'lindenweg16' );
			$color = '#d97706';
			$bg    = '#fef3c7';
			$hint  = '';
			if ( ! empty( $l->available_at ) ) {
				$hint = sprintf(
					/* translators: %s: date */
					__( 'Verfügbar ab %s', 'lindenweg16' ),
					date_i18n( 'd.m.Y', strtotime( $l->available_at ) )
				);
			}
			return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;background:' . $bg . ';color:' . $color . '"'
				. ( $hint ? ' title="' . esc_attr( $hint ) . '"' : '' )
				. '>' . esc_html( $label ) . '</span>';
		}

		if ( 'reversed' === $status ) {
			return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;background:#fee2e2;color:#dc2626">'
				. esc_html__( 'Storniert', 'lindenweg16' ) . '</span>';
		}

		return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;background:#dcfce7;color:#16a34a">'
			. esc_html__( 'Verfügbar', 'lindenweg16' ) . '</span>';
	}
}
