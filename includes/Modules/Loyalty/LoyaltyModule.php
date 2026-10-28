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

/**
 * Verdrahtet Referral-Tracking, Reward-Berechnung mit Betrugsprüfung,
 * Punktevergabe, die Prüf-Warteschlange und den täglichen Reifeprozess.
 */
class LoyaltyModule {

	const COOKIE = 'lw16ref';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Cookie-Tracking (nur beim ersten Aufruf setzen).
		add_action( 'init', array( $this, 'maybe_set_referral_cookie' ) );

		// Empfehlungscode beim Bestellen merken (klassische und Block-Kasse).
		add_action( 'woocommerce_checkout_create_order', array( $this, 'remember_referral' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'remember_referral_block' ), 10, 1 );

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

		// Bonuspunkte für Konto, Newsletter, Geburtstag, Bewertungen.
		( new LoyaltyTriggers() )->register();
		( new ReviewPhotos() )->register();
		( new LoyaltyCart() )->register();
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

		$user_id   = (int) $order->get_customer_id();
		$wartezeit = (int) Settings::get( 'punkte_wartezeit_tage', 14 );

		// --- Punkte für den Bestellwert, mit laufenden Aktionen. ----------
		// Maßgeblich ist der Bestelltag, nicht der Tag der Fertigmeldung:
		// Wer während einer Aktion bestellt, bekommt ihre Punkte.
		if ( $user_id ) {
			$day    = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d' ) : current_time( 'Y-m-d' );
			$result = LoyaltyRules::order_points(
				(float) $order->get_total(),
				$this->order_lines( $order ),
				(float) Settings::get( 'punkte_pro_euro', 1 ),
				LoyaltyCampaigns::active( $day )
			);
			$extras = array();
			if ( $result['bonus'] > 0 ) {
				/* translators: 1: Aktionsname(n), 2: Bonuspunkte */
				$extras[] = sprintf( __( '%1$s (+%2$d)', 'lindenweg16' ), implode( ', ', $result['names'] ), $result['bonus'] );
			}
			// Stufe nach dem Umsatz vor dieser Bestellung.
			$tier   = LoyaltyTiers::for_user( $user_id, $order_id );
			$points = $result['points'];
			if ( $tier && $tier['tier']['faktor'] > 1 ) {
				$points   = LoyaltyRules::apply_tier( $result['points'], $tier['tier']['faktor'] );
				/* translators: 1: Stufe, 2: Bonuspunkte */
				$extras[] = sprintf( __( 'Stufe %1$s (+%2$d)', 'lindenweg16' ), $tier['tier']['name'], $points - $result['points'] );
			}
			$note = $extras ? __( 'Bestellung', 'lindenweg16' ) . ' · ' . implode( ' · ', $extras ) : __( 'Bestellung', 'lindenweg16' );
			LoyaltyRepository::award( $user_id, $points, 'earned', $note, $order_id, $wartezeit );
			LoyaltyTiers::forget( $user_id );

			// Erste abgeschlossene Bestellung: einmaliger Bonus, und ihr Tag ist
			// künftig der Jahrestag.
			if ( 1 === $this->completed_order_count( $user_id ) ) {
				update_user_meta( $user_id, LoyaltyTriggers::META_ERSTE_BESTELLUNG, $day );
				LoyaltyRepository::award_once( $user_id, 'erste_bestellung', (int) Settings::get( 'punkte_erste_bestellung', 0 ), 'first_order', __( 'Bonus für die erste Bestellung', 'lindenweg16' ), $wartezeit, $order_id );
			}
		}

		// --- Referral: Code aus der Bestellung auswerten. ------------------
		$referral = $this->maybe_create_referral( $order );

		// Willkommenspunkte für Geworbene mit Konto, nur bei der ersten Bestellung
		// und nicht, wenn die Empfehlung erst geprüft werden muss.
		if ( $referral && 'pending' === $referral['status'] && $user_id && 1 === $this->completed_order_count( $user_id ) ) {
			LoyaltyRepository::award_once( $user_id, 'empfehlung_willkommen', (int) Settings::get( 'punkte_empfehlung_neukunde', 0 ), 'referral_welcome', __( 'Willkommen über eine Empfehlung', 'lindenweg16' ), $wartezeit, $order_id );
		}

		$order->update_meta_data( '_lw16_loyalty_done', current_time( 'mysql' ) );
		$order->save();
	}

	/**
	 * Referral aus Cookie erzeugen, inklusive Betrugsprüfung.
	 *
	 * @param \WC_Order $order Bestellung.
	 */
	protected function maybe_create_referral( $order ) {
		// Der Code steht seit 0.55.0 an der Bestellung (beim Bestellen gemerkt).
		// Fertig gemeldet wird die Bestellung meist im Backend – dort ist das
		// Cookie das des Mitarbeiters, nicht das des Kunden. Das Cookie zählt
		// nur noch für ältere Bestellungen ohne gemerkten Code.
		$code = (string) $order->get_meta( '_lw16_ref_code' );
		if ( '' === $code && ! $order->get_meta( '_lw16_ref_checked' ) && ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			$code = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		}
		if ( '' === $code ) {
			return null;
		}
		$referrer_id = LoyaltyRepository::user_id_by_code( $code );
		if ( ! $referrer_id ) {
			return null;
		}
		$referred_id = (int) $order->get_customer_id();

		// --- Harte Regeln: automatisch blocken (Selbstwerbung). ----------
		$referrer      = get_userdata( $referrer_id );
		$referred_mail = $order->get_billing_email();
		if ( Calc::is_self_referral( $referrer_id, $referred_id, $referrer ? $referrer->user_email : '', (string) $referred_mail ) ) {
			return null;
		}

		$reward = $this->calculate_reward( $order );
		if ( $reward <= 0 ) {
			return null;
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

		if ( 'review' === $status ) {
			$this->notify_review( $referral_id, $review_grund, $reward );
		}

		return array( 'id' => $referral_id, 'status' => $status );
	}

	/**
	 * Empfehlungscode beim Bestellen an die Bestellung schreiben (klassische
	 * Kasse und Block-Kasse), samt Zeitpunkt des Klicks für die Schnellkauf-Prüfung.
	 *
	 * @param \WC_Order $order Bestellung.
	 */
	public function remember_referral( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$order->update_meta_data( '_lw16_ref_checked', 1 );
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}
		$code = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		if ( ! LoyaltyRepository::user_id_by_code( $code ) ) {
			return;
		}
		$order->update_meta_data( '_lw16_ref_code', $code );
		if ( ! empty( $_COOKIE[ self::COOKIE . '_ts' ] ) ) {
			$order->update_meta_data( '_lw16_ref_ts', (int) $_COOKIE[ self::COOKIE . '_ts' ] );
		}
		$order->update_meta_data( '_lw16_ref_order_ts', time() );
	}

	/**
	 * Block-Kasse: wie remember_referral(), danach speichern – ob die Store-API
	 * nach diesem Hook noch speichert, ist nicht zugesichert.
	 *
	 * @param \WC_Order $order Bestellung.
	 */
	public function remember_referral_block( $order ) {
		$this->remember_referral( $order );
		if ( $order instanceof \WC_Order ) {
			$order->save();
		}
	}

	/**
	 * Positionen für die Aktionsrechnung: Betrag inkl. Steuer und Kategorien.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return array
	 */
	protected function order_lines( $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$cats       = $product_id ? wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) ) : array();
			$lines[]    = array(
				'total' => (float) $item->get_total() + (float) $item->get_total_tax(),
				'cats'  => is_wp_error( $cats ) ? array() : array_map( 'intval', $cats ),
			);
		}
		return $lines;
	}

	/**
	 * Wie viele abgeschlossene Bestellungen hat der Kunde?
	 *
	 * @param int $user_id Kunde.
	 * @return int
	 */
	protected function completed_order_count( $user_id ) {
		return count(
			wc_get_orders(
				array(
					'customer_id' => $user_id,
					'status'      => array( 'wc-completed' ),
					'limit'       => 2,
					'return'      => 'ids',
				)
			)
		);
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

		// Sehr schnelle Bestellung nach Cookie-Setzung (Zeitpunkte von der
		// Bestellung; ältere Bestellungen: Cookie wie bisher).
		$klick = (int) $order->get_meta( '_lw16_ref_ts' );
		$kauf  = (int) $order->get_meta( '_lw16_ref_order_ts' );
		if ( ! $klick && ! empty( $_COOKIE[ self::COOKIE . '_ts' ] ) ) {
			$klick = (int) $_COOKIE[ self::COOKIE . '_ts' ];
		}
		if ( $klick ) {
			$diff  = ( $kauf ? $kauf : time() ) - $klick;
			$grenze = (int) Settings::get( 'referral_schnellkauf_sek', 60 );
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

		// Punkte der Bestellung zurücknehmen – alle Gutschriften, die an ihr
		// hängen (Bestellpunkte, Erstbestellungs- und Willkommensbonus, Punkte
		// des Werbers), und auch ohne Empfehlung. Bisher geschah das nur, wenn
		// es zur Bestellung eine Empfehlung gab.
		$order = wc_get_order( $order_id );
		if ( $order && $order->get_customer_id() ) {
			LoyaltyTiers::forget( (int) $order->get_customer_id() );
		}

		$lt   = LoyaltyRepository::table( 'points_ledger' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$lt} WHERE order_id = %d AND points > 0 AND status <> 'reversed'", $order_id ) ); // phpcs:ignore
		foreach ( $rows as $pl ) {
			if ( 'available' === $pl->status ) {
				LoyaltyRepository::adjust_points( (int) $pl->user_id, -1 * (int) $pl->points );
			}
			LoyaltyRepository::set_ledger_status( (int) $pl->id, 'reversed' );
		}

		$t   = LoyaltyRepository::table( 'referrals' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE referred_order_id = %d", $order_id ) ); // phpcs:ignore
		if ( ! $row || 'reversed' === $row->status ) {
			return;
		}
		if ( 'completed' === $row->status ) {
			// Bereits gutgeschriebenes Guthaben zurückbuchen.
			LoyaltyRepository::adjust_credit( (int) $row->referrer_user_id, -1 * (float) $row->reward_amount );
		}
		LoyaltyRepository::set_referral_status( (int) $row->id, 'reversed' );
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
			// Punkte für den Werber zusätzlich zum Guthaben; hängen an der
			// Bestellung des Geworbenen und gehen bei deren Storno mit zurück.
			LoyaltyRepository::award( (int) $ref->referrer_user_id, (int) Settings::get( 'punkte_empfehlung_werber', 0 ), 'referral_bonus', __( 'Freund geworben', 'lindenweg16' ), (int) $ref->referred_order_id );
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
		if ( ! current_user_can( 'lw16_mod_loyalty' ) ) {
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
