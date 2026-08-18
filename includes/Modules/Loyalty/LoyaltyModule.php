<?php
/**
 * Modul 3: Loyalty (Referral + Punkte).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Loyalty;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;
use Lindenweg16\Core\Roles;
use Lindenweg16\Core\Scheduler;
use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Calc;
use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Core\Time;

/**
 * Verdrahtet Referral-Tracking, Reward-Berechnung mit Betrugsprüfung,
 * Punktevergabe, die Prüf-Warteschlange und den täglichen Reifeprozess.
 */
class LoyaltyModule {

	const COOKIE = 'lw16ref';

	/**
	 * Order-Meta, in dem der Werber-Code beim Checkout gesichert wird.
	 */
	const META_REF_CODE = '_lw16_ref_code';
	const META_REF_TS   = '_lw16_ref_ts';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Cookie-Tracking (nur beim ersten Aufruf setzen).
		add_action( 'init', array( $this, 'maybe_set_referral_cookie' ) );

		// Werber-Code im Checkout festhalten. Das MUSS hier passieren und
		// nicht beim Statuswechsel: 'completed' wird typischerweise im Admin
		// oder per Cron ausgelöst, wo das Cookie des Kunden gar nicht
		// vorliegt (bzw. das des Admins gelesen würde).
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture_referral_source' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture_referral_source' ), 10, 1 );

		// Bestellabschluss: schnelle, synchrone Aktionen.
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_order_completed' ), 10, 1 );

		// Refund/Storno: Referral zurückdrehen.
		add_action( 'woocommerce_order_status_refunded', array( $this, 'on_order_reversed' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_order_reversed' ), 10, 1 );

		// Täglicher Reifeprozess (Wartezeit -> completed, Punkte verfügbar).
		add_action( Scheduler::HOOK_DAILY_LOYALTY, array( $this, 'process_maturity' ) );

		// Referral-Code bei Registrierung erzeugen.
		add_action( 'user_register', array( LoyaltyRepository::class, 'get_or_create_code' ) );

		// Admin: Prüf-Warteschlange.
		add_action( 'lw16_render_page_lw16_loyalty', array( $this, 'render_admin' ) );
		add_action( 'admin_post_lw16_referral_decision', array( $this, 'handle_review_decision' ) );

		// Frontend: Checkout-Einlösung + Mein-Konto-Tab.
		( new LoyaltyFrontend() )->register();
	}

	/* --------------------------------------------------------------------
	 * Cookie-Tracking
	 * ------------------------------------------------------------------ */

	/**
	 * Referral-Cookie beim ersten Aufruf mit gültigem Code setzen.
	 * Schutz vor Cookie-Stuffing: nur setzen, wenn noch keins existiert.
	 */
	public function maybe_set_referral_cookie() {
		if ( is_admin() ) {
			return;
		}
		if ( empty( $_GET[ self::COOKIE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			return; // Bereits gesetzt – nicht überschreiben.
		}
		$code = sanitize_text_field( wp_unslash( $_GET[ self::COOKIE ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! LoyaltyRepository::user_id_by_code( $code ) ) {
			return; // Ungültiger Code.
		}
		$tage = (int) Settings::get( 'referral_cookie_tage', 30 );
		setcookie( self::COOKIE, $code, time() + $tage * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN );
		// Zeitstempel für Schnellkauf-Erkennung.
		setcookie( self::COOKIE . '_ts', (string) time(), time() + $tage * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN );
	}

	/* --------------------------------------------------------------------
	 * Bestellabschluss
	 * ------------------------------------------------------------------ */

	/**
	 * Bei abgeschlossener Bestellung: Punkte-Eintrag + ggf. Referral anlegen.
	 * Nur schnelle DB-Operationen, nichts Langsames im Request.
	 *
	 * @param int $order_id Bestell-ID.
	 */
	public function on_order_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( '_lw16_loyalty_done' ) ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();

		// --- Punkte: 1 Punkt pro vollem Euro (nur eingeloggte Kunden). ----
		if ( $user_id ) {
			$punkte_pro_euro = (float) Settings::get( 'punkte_pro_euro', 1 );
			$punkte          = Calc::points_for_amount( (float) $order->get_total(), $punkte_pro_euro );
			if ( $punkte > 0 ) {
				$wartezeit = (int) Settings::get( 'punkte_wartezeit_tage', 14 );
				LoyaltyRepository::add_ledger(
					array(
						'user_id'      => $user_id,
						'order_id'     => $order_id,
						'points'       => $punkte,
						'type'         => 'earned',
						'status'       => 'pending',
						'notiz'        => __( 'Bestellung', 'lindenweg16' ),
						'available_at' => Time::in( $wartezeit * DAY_IN_SECONDS ),
					)
				);
			}
		}

		// --- Referral: Cookie auswerten. ---------------------------------
		$this->maybe_create_referral( $order );

		$order->update_meta_data( '_lw16_loyalty_done', current_time( 'mysql' ) );
		$order->save();
	}

	/**
	 * Werber-Code und Zeitstempel beim Checkout auf der Bestellung sichern.
	 *
	 * @param int|\WC_Order $order_or_id Bestell-ID oder Bestellobjekt.
	 */
	public function capture_referral_source( $order_or_id ) {
		$order = is_numeric( $order_or_id ) ? wc_get_order( $order_or_id ) : $order_or_id;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}
		if ( $order->get_meta( self::META_REF_CODE ) ) {
			return;
		}

		$code = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		if ( ! $code ) {
			return;
		}

		$order->update_meta_data( self::META_REF_CODE, $code );
		if ( ! empty( $_COOKIE[ self::COOKIE . '_ts' ] ) ) {
			$order->update_meta_data( self::META_REF_TS, (int) $_COOKIE[ self::COOKIE . '_ts' ] );
		}
		$order->save();
	}

	/**
	 * Referral aus dem beim Checkout gesicherten Code erzeugen,
	 * inklusive Betrugsprüfung.
	 *
	 * @param \WC_Order $order Bestellung.
	 */
	protected function maybe_create_referral( $order ) {
		$code = (string) $order->get_meta( self::META_REF_CODE );
		if ( ! $code ) {
			return;
		}

		$referrer_id = LoyaltyRepository::user_id_by_code( $code );
		if ( ! $referrer_id ) {
			return;
		}
		$referred_id = (int) $order->get_customer_id();

		// --- Harte Regeln: automatisch blocken (Selbstwerbung). ----------
		$referrer      = get_userdata( $referrer_id );
		$referred_mail = $order->get_billing_email();
		if ( Calc::is_self_referral( $referrer_id, $referred_id, $referrer ? $referrer->user_email : '', (string) $referred_mail ) ) {
			return;
		}

		$reward = $this->calculate_reward( $order );
		if ( $reward <= 0 ) {
			return;
		}

		// --- Weiche Signale: ggf. in Review. -----------------------------
		$review_grund = $this->evaluate_soft_signals( $order, $referrer_id, $reward );
		$status       = $review_grund ? 'review' : 'pending';

		$referral_id = LoyaltyRepository::create_referral(
			array(
				'referrer_user_id' => $referrer_id,
				'referred_user_id' => $referred_id ? $referred_id : null,
				'referred_order_id' => $order->get_id(),
				'status'           => $status,
				'reward_amount'    => $reward,
				'review_grund'     => $review_grund ? $review_grund : null,
			)
		);

		if ( 'review' === $status && $referral_id ) {
			$this->notify_review( $referral_id, $review_grund, $reward );
		}
	}

	/**
	 * Reward berechnen: Positionssumme × Marge × Referral-%.
	 * Rein serverseitig, nie im Frontend sichtbar.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return float
	 */
	protected function calculate_reward( $order ) {
		$referral_prozent_wert = (float) Settings::get( 'referral_prozent', 5 );
		$margin_fallback_wert  = (float) Settings::get( 'referral_margin_fallback', 40 );

		$reward = 0.0;
		foreach ( $order->get_items() as $item ) {
			$line_total = (float) $item->get_total();
			$product_id = $item->get_product_id();
			$margin     = get_post_meta( $product_id, '_lw16_margin_percent', true );
			$margin     = ( '' === $margin || null === $margin ) ? $margin_fallback_wert : (float) $margin;
			$reward    += Calc::referral_reward( $line_total, $margin, $referral_prozent_wert );
		}
		return round( $reward, 2 );
	}

	/**
	 * Weiche Betrugssignale prüfen. Gibt Begründung zurück oder ''.
	 *
	 * @param \WC_Order $order       Bestellung.
	 * @param int       $referrer_id Werber.
	 * @param float     $reward      Reward-Betrag.
	 * @return string
	 */
	protected function evaluate_soft_signals( $order, $referrer_id, $reward ) {
		$gruende = array();

		// Ungewöhnlich hoher Reward.
		$schwelle = (float) Settings::get( 'referral_review_schwelle', 25 );
		if ( $reward >= $schwelle ) {
			$gruende[] = __( 'hoher Reward-Betrag', 'lindenweg16' );
		}

		// Gleiche Lieferadresse wie Werber.
		$referrer = get_userdata( $referrer_id );
		if ( $referrer ) {
			$ref_plz = get_user_meta( $referrer_id, 'shipping_postcode', true );
			$ref_adr = get_user_meta( $referrer_id, 'shipping_address_1', true );
			if ( $ref_plz && $ref_adr
				&& strtolower( trim( $ref_plz ) ) === strtolower( trim( (string) $order->get_shipping_postcode() ) )
				&& strtolower( trim( $ref_adr ) ) === strtolower( trim( (string) $order->get_shipping_address_1() ) ) ) {
				$gruende[] = __( 'gleiche Lieferadresse wie Werber', 'lindenweg16' );
			}
		}

		// Sehr schnelle Bestellung nach Cookie-Setzung. Gemessen wird gegen
		// den Bestellzeitpunkt, nicht gegen "jetzt" – der Reifeprozess läuft
		// asynchron und wäre sonst immer weit über der Grenze.
		$cookie_ts = (int) $order->get_meta( self::META_REF_TS );
		if ( $cookie_ts > 0 ) {
			$created = $order->get_date_created();
			$order_ts = $created ? $created->getTimestamp() : time();
			$diff     = $order_ts - $cookie_ts;
			$grenze   = (int) Settings::get( 'referral_schnellkauf_sek', 60 );
			if ( $diff >= 0 && $diff < $grenze ) {
				$gruende[] = __( 'sehr schnelle Bestellung nach Cookie-Setzung', 'lindenweg16' );
			}
		}

		return implode( ', ', $gruende );
	}

	/**
	 * Manager über einen Review-Fall informieren.
	 *
	 * @param int    $referral_id Referral-ID.
	 * @param string $grund       Begründung.
	 * @param float  $reward      Reward.
	 */
	protected function notify_review( $referral_id, $grund, $reward ) {
		$emails = Roles::get_manager_emails();
		if ( empty( $emails ) ) {
			return;
		}
		MailTemplate::send( $emails, 'referral_review', array(
			'{referral_id}' => (string) $referral_id,
			'{betrag}'      => number_format( $reward, 2, ',', '.' ),
			'{grund}'       => $grund,
		) );
	}

	/* --------------------------------------------------------------------
	 * Reversal & Reifeprozess
	 * ------------------------------------------------------------------ */

	/**
	 * Bei Rückgabe/Storno das zugehörige Referral zurückdrehen.
	 *
	 * @param int $order_id Bestell-ID.
	 */
	public function on_order_reversed( $order_id ) {
		global $wpdb;

		// Zuerst die im Checkout eingelöste Einlösung zurückgeben.
		$this->restore_redemption( $order_id );

		$t   = LoyaltyRepository::table( 'referrals' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE referred_order_id = %d", $order_id ) ); // phpcs:ignore
		if ( ! $row ) {
			return;
		}
		if ( 'completed' === $row->status ) {
			// Bereits gutgeschriebenes Guthaben zurückbuchen.
			LoyaltyRepository::adjust_credit( (int) $row->referrer_user_id, -1 * (float) $row->reward_amount );
		}
		LoyaltyRepository::set_referral_status( (int) $row->id, 'reversed' );

		// Punkte der Bestellung stornieren.
		$lt   = LoyaltyRepository::table( 'points_ledger' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$lt} WHERE order_id = %d AND type = 'earned'", $order_id ) ); // phpcs:ignore
		foreach ( $rows as $pl ) {
			if ( 'available' === $pl->status ) {
				LoyaltyRepository::adjust_points( (int) $pl->user_id, -1 * (int) $pl->points );
			}
			LoyaltyRepository::set_ledger_status( (int) $pl->id, 'reversed' );
		}
	}

	/**
	 * Im Checkout eingelöstes Guthaben und Punkte bei Storno zurückgeben.
	 *
	 * Ohne diesen Schritt behält der Kunde zwar die Rückerstattung, verliert
	 * aber das eingesetzte Guthaben und die Punkte ersatzlos.
	 *
	 * @param int $order_id Bestell-ID.
	 */
	protected function restore_redemption( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( '_lw16_redeem_restored' ) ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();
		$credit  = (float) $order->get_meta( '_lw16_redeemed_credit' );
		$points  = (int) $order->get_meta( '_lw16_redeemed_points' );

		if ( ! $user_id || ( $credit <= 0 && $points <= 0 ) ) {
			return;
		}

		if ( $credit > 0 ) {
			LoyaltyRepository::adjust_credit( $user_id, $credit );
		}
		if ( $points > 0 ) {
			LoyaltyRepository::adjust_points( $user_id, $points );
			LoyaltyRepository::add_ledger(
				array(
					'user_id'  => $user_id,
					'order_id' => $order_id,
					'points'   => $points,
					'type'     => 'redeem_reversed',
					'status'   => 'available',
					'notiz'    => __( 'Einlösung storniert', 'lindenweg16' ),
				)
			);
		}

		$order->update_meta_data( '_lw16_redeem_restored', Time::now() );
		$order->save();

		AuditLog::record(
			'einloesung_storniert',
			'order',
			$order_id,
			array(
				'user_id' => $user_id,
				'credit'  => $credit,
				'points'  => $points,
			)
		);
	}

	/**
	 * Täglicher Reifeprozess: fällige Referrals gutschreiben, Punkte verfügbar machen.
	 */
	public function process_maturity() {
		$wartezeit = (int) Settings::get( 'referral_wartezeit_tage', 14 );

		// Referrals: pending -> completed nach Wartezeit.
		foreach ( LoyaltyRepository::due_referrals( $wartezeit ) as $ref ) {
			// Bei zwischenzeitlicher Rückgabe wird der Status bereits auf
			// reversed gesetzt; hier nur echte pending gutschreiben.
			LoyaltyRepository::adjust_credit( (int) $ref->referrer_user_id, (float) $ref->reward_amount );
			LoyaltyRepository::set_referral_status( (int) $ref->id, 'completed' );
		}

		// Punkte: pending -> available nach available_at.
		foreach ( LoyaltyRepository::due_points() as $pl ) {
			LoyaltyRepository::adjust_points( (int) $pl->user_id, (int) $pl->points );
			LoyaltyRepository::set_ledger_status( (int) $pl->id, 'available' );
		}

		do_action( 'lw16_daily_maturity_done' );
	}

	/* --------------------------------------------------------------------
	 * Admin: Prüf-Warteschlange
	 * ------------------------------------------------------------------ */

	/**
	 * Loyalty-Admin-Seite: Review-Warteschlange.
	 */
	public function render_admin() {
		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Loyalty', 'lindenweg16' ) . '</h1>';

		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}

		echo '<h2>' . esc_html__( 'Prüf-Warteschlange', 'lindenweg16' ) . '</h2>';
		$reviews = LoyaltyRepository::referrals_by_status( 'review' );
		if ( empty( $reviews ) ) {
			echo '<p>' . esc_html__( 'Keine offenen Prüffälle. 👍', 'lindenweg16' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Werber', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Bestellung', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Reward', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Grund', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Aktion', 'lindenweg16' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $reviews as $r ) {
				$werber = get_userdata( $r->referrer_user_id );
				echo '<tr>';
				echo '<td>' . esc_html( $r->created_at ) . '</td>';
				echo '<td>' . esc_html( $werber ? $werber->display_name : '#' . $r->referrer_user_id ) . '</td>';
				echo '<td>#' . (int) $r->referred_order_id . '</td>';
				echo '<td>' . esc_html( number_format( (float) $r->reward_amount, 2, ',', '.' ) . ' €' ) . '</td>';
				echo '<td>' . esc_html( (string) $r->review_grund ) . '</td>';
				echo '<td>';
				$approve = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_referral_decision&id=' . $r->id . '&decision=approve' ), 'lw16_referral_' . $r->id );
				$reject  = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_referral_decision&id=' . $r->id . '&decision=reject' ), 'lw16_referral_' . $r->id );
				echo '<a class="button button-primary" href="' . esc_url( $approve ) . '">' . esc_html__( 'Freigeben', 'lindenweg16' ) . '</a> ';
				echo '<a class="button" href="' . esc_url( $reject ) . '">' . esc_html__( 'Ablehnen', 'lindenweg16' ) . '</a>';
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/**
	 * Freigabe/Ablehnung eines Review-Referrals (Ein-Klick).
	 */
	public function handle_review_decision() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_referral_' . $id );
		$decision = isset( $_GET['decision'] ) ? sanitize_key( wp_unslash( $_GET['decision'] ) ) : '';

		$ref = LoyaltyRepository::get_referral( $id );
		if ( ! $ref || 'review' !== $ref->status ) {
			$this->redirect( __( 'Referral nicht mehr in Prüfung.', 'lindenweg16' ) );
		}

		if ( 'approve' === $decision ) {
			// Freigeben: geht in den normalen Reifeprozess (pending).
			LoyaltyRepository::set_referral_status( $id, 'pending' );
			AuditLog::record( 'referral_freigegeben', 'referral', $id, array( 'reward' => $ref->reward_amount ) );
			$msg = __( 'Referral freigegeben.', 'lindenweg16' );
		} else {
			LoyaltyRepository::set_referral_status( $id, 'reversed' );
			AuditLog::record( 'referral_abgelehnt', 'referral', $id, array( 'reward' => $ref->reward_amount ) );
			$msg = __( 'Referral abgelehnt.', 'lindenweg16' );
		}
		$this->redirect( $msg );
	}

	/**
	 * Zurück zur Loyalty-Seite.
	 *
	 * @param string $msg Meldung.
	 */
	protected function redirect( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'lw16-loyalty', 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
