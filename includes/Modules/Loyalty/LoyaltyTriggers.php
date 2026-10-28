<?php
/**
 * Bonuspunkte für Kundenkonto, Newsletter, Geburtstag und Bewertungen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Loyalty;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Calc;
use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Core\Scheduler;
use Lindenweg16\Core\Settings;

/**
 * Auslöser außerhalb der Bestellung. Wie viele Punkte es gibt, steht in den
 * Einstellungen (LoyaltyRules::fields()); 0 schaltet einen Auslöser aus.
 * Punkte für Bestellung, erste Bestellung und Empfehlungen vergibt
 * LoyaltyModule.
 */
class LoyaltyTriggers {

	/** Newsletter-Adressen ohne Konto, deren Punkte bei der Registrierung kommen. */
	const OPTION_NEWSLETTER_OFFEN = 'lw16_newsletter_bonus_offen';

	/** Gemerkte Newsletter-Adressen ohne Konto höchstens. */
	const NEWSLETTER_OFFEN_MAX = 5000;

	/** Ein frisch eingetragener Geburtstag zählt erst nach so vielen Tagen. */
	const GEBURTSTAG_KARENZ_TAGE = 30;

	/** Tag der ersten abgeschlossenen Bestellung (Y-m-d, „-“ = keine). */
	const META_ERSTE_BESTELLUNG = 'lw16_erste_bestellung';

	/** Für diese Frist wurde schon an den Verfall erinnert (Unix-Zeit). */
	const META_VERFALL_ERINNERT = 'lw16_verfall_erinnert';

	/** Kunden je Tageslauf für Verfall und Nachtragen des Jahrestags. */
	const BATCH = 500;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'woocommerce_created_customer', array( $this, 'on_registered' ), 20, 1 );

		// Newsletter des Lindenweg16-Themes; andere Newsletter-Werkzeuge können
		// dieselbe Aktion mit der bestätigten Adresse auslösen.
		add_action( 'lindenweg16_newsletter_confirmed', array( $this, 'on_newsletter' ), 10, 1 );
		add_action( 'lw16_loyalty_newsletter_confirmed', array( $this, 'on_newsletter' ), 10, 1 );

		// Geburtstag: Feld in den Kontodetails, Glückwunsch im Tageslauf.
		add_action( 'woocommerce_edit_account_form', array( $this, 'birthday_field' ) );
		add_action( 'woocommerce_save_account_details', array( $this, 'save_birthday' ), 10, 1 );
		add_action( Scheduler::HOOK_DAILY_LOYALTY, array( $this, 'process_birthdays' ) );

		// Profil vollständig: nach dem Speichern von Kontodetails oder Adresse.
		add_action( 'woocommerce_save_account_details', array( $this, 'check_profile' ), 20, 1 );
		add_action( 'woocommerce_customer_save_address', array( $this, 'check_profile' ), 20, 1 );

		// Jahrestag der ersten Bestellung und Verfall: täglich.
		add_action( Scheduler::HOOK_DAILY_LOYALTY, array( $this, 'process_anniversaries' ) );
		add_action( Scheduler::HOOK_DAILY_LOYALTY, array( $this, 'process_expiry' ) );

		// Bewertungen: Punkte erst, wenn die Bewertung sichtbar ist. Fotos
		// speichert ReviewPhotos vorher (Priorität 5).
		add_action( 'comment_post', array( $this, 'on_comment_post' ), 20, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status' ), 10, 3 );
	}

	/* --------------------------------------------------------------------
	 * Konto und Newsletter
	 * ------------------------------------------------------------------ */

	/**
	 * Neues Kundenkonto (Mein Konto oder Kasse).
	 *
	 * @param int $user_id Kunde.
	 */
	public function on_registered( $user_id ) {
		LoyaltyRepository::award_once( $user_id, 'registrierung', (int) Settings::get( 'punkte_registrierung', 0 ), 'signup', __( 'Willkommen im Kundenkonto', 'lindenweg16' ) );

		// Hat sich die Adresse schon vorher für den Newsletter bestätigt?
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$offen = self::newsletter_open();
		$key   = self::mail_key( $user->user_email );
		if ( isset( $offen[ $key ] ) ) {
			unset( $offen[ $key ] );
			update_option( self::OPTION_NEWSLETTER_OFFEN, $offen, false );
			$this->award_newsletter( $user_id );
		}
	}

	/**
	 * Newsletter bestätigt (Double-Opt-in abgeschlossen).
	 *
	 * @param string $email Adresse.
	 */
	public function on_newsletter( $email ) {
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) || (int) Settings::get( 'punkte_newsletter', 0 ) <= 0 ) {
			return;
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$this->award_newsletter( $user->ID );
			return;
		}

		// Noch kein Konto: merken, die Punkte kommen mit der Registrierung.
		// Nur ein Hash, keine Adresse – die Liste ist kein zweiter Verteiler.
		$offen = self::newsletter_open();
		if ( count( $offen ) >= self::NEWSLETTER_OFFEN_MAX ) {
			$offen = array_slice( $offen, -1 * ( self::NEWSLETTER_OFFEN_MAX - 1 ), null, true );
		}
		$offen[ self::mail_key( $email ) ] = time();
		update_option( self::OPTION_NEWSLETTER_OFFEN, $offen, false );
	}

	/**
	 * Newsletter-Punkte einmal je Konto.
	 *
	 * @param int $user_id Kunde.
	 */
	protected function award_newsletter( $user_id ) {
		LoyaltyRepository::award_once( $user_id, 'newsletter', (int) Settings::get( 'punkte_newsletter', 0 ), 'newsletter', __( 'Danke für die Newsletter-Anmeldung', 'lindenweg16' ) );
	}

	/**
	 * Gemerkte Newsletter-Adressen (Hash => Zeitpunkt).
	 *
	 * @return array
	 */
	protected static function newsletter_open() {
		$offen = get_option( self::OPTION_NEWSLETTER_OFFEN, array() );
		return is_array( $offen ) ? $offen : array();
	}

	/**
	 * Schlüssel für eine Adresse, ohne sie im Klartext zu speichern.
	 *
	 * @param string $email Adresse.
	 * @return string
	 */
	protected static function mail_key( $email ) {
		return hash_hmac( 'sha256', strtolower( trim( (string) $email ) ), wp_salt( 'auth' ) );
	}

	/* --------------------------------------------------------------------
	 * Geburtstag
	 * ------------------------------------------------------------------ */

	/**
	 * Feld in den Kontodetails – nur, wenn der Auslöser eingeschaltet ist.
	 */
	public function birthday_field() {
		if ( (int) Settings::get( 'punkte_geburtstag', 0 ) <= 0 ) {
			return;
		}
		$md    = (string) get_user_meta( get_current_user_id(), 'lw16_geburtstag', true );
		$day   = $md ? (int) substr( $md, 3, 2 ) : 0;
		$month = $md ? (int) substr( $md, 0, 2 ) : 0;
		$names = array( 1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' );

		echo '<fieldset class="lw16-geburtstag"><legend>' . esc_html__( 'Geburtstag (freiwillig)', 'lindenweg16' ) . '</legend>';
		/* translators: %d: Punkte */
		echo '<p class="lw16-geburtstag__hint">' . esc_html( sprintf( __( 'Zum Geburtstag schenken wir dir %d Punkte. Das Jahr brauchen wir nicht.', 'lindenweg16' ), (int) Settings::get( 'punkte_geburtstag', 0 ) ) ) . '</p>';
		echo '<p class="form-row form-row-first"><label for="lw16_geb_tag">' . esc_html__( 'Tag', 'lindenweg16' ) . '</label><select name="lw16_geb_tag" id="lw16_geb_tag"><option value="0">–</option>';
		for ( $d = 1; $d <= 31; $d++ ) {
			echo '<option value="' . (int) $d . '"' . selected( $day, $d, false ) . '>' . (int) $d . '.</option>';
		}
		echo '</select></p>';
		echo '<p class="form-row form-row-last"><label for="lw16_geb_monat">' . esc_html__( 'Monat', 'lindenweg16' ) . '</label><select name="lw16_geb_monat" id="lw16_geb_monat"><option value="0">–</option>';
		foreach ( $names as $m => $label ) {
			echo '<option value="' . (int) $m . '"' . selected( $month, $m, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><div class="clear"></div></fieldset>';
	}

	/**
	 * Geburtstag speichern (Monat-Tag). Der Nonce prüft WooCommerce vorher.
	 *
	 * @param int $user_id Kunde.
	 */
	public function save_birthday( $user_id ) {
		if ( ! isset( $_POST['lw16_geb_tag'], $_POST['lw16_geb_monat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$d   = (int) $_POST['lw16_geb_tag']; // phpcs:ignore WordPress.Security.NonceVerification
		$m   = (int) $_POST['lw16_geb_monat']; // phpcs:ignore WordPress.Security.NonceVerification
		$old = (string) get_user_meta( $user_id, 'lw16_geburtstag', true );

		if ( ! $d || ! $m || ! checkdate( $m, $d, 2000 ) ) {
			delete_user_meta( $user_id, 'lw16_geburtstag' );
			return;
		}
		$md = sprintf( '%02d-%02d', $m, $d );
		if ( $md !== $old ) {
			update_user_meta( $user_id, 'lw16_geburtstag', $md );
			// Wer den Geburtstag auf heute legt, bekommt nicht sofort Punkte.
			update_user_meta( $user_id, 'lw16_geburtstag_seit', time() );
		}
	}

	/**
	 * Täglich: Glückwunsch und Punkte an alle, die heute Geburtstag haben.
	 */
	public function process_birthdays() {
		$points = (int) Settings::get( 'punkte_geburtstag', 0 );
		if ( $points <= 0 ) {
			return;
		}
		$today = current_time( 'Y-m-d' );
		$md    = substr( $today, 5 );
		$keys  = array( $md );
		if ( '02-28' === $md && ! checkdate( 2, 29, (int) substr( $today, 0, 4 ) ) ) {
			$keys[] = '02-29';
		}

		$users = get_users(
			array(
				'meta_key'     => 'lw16_geburtstag', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'   => $keys, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'IN',
				'fields'       => 'ID',
				'number'       => 500,
			)
		);
		$year = substr( $today, 0, 4 );

		foreach ( $users as $user_id ) {
			$birthday = (string) get_user_meta( $user_id, 'lw16_geburtstag', true );
			$since    = (int) get_user_meta( $user_id, 'lw16_geburtstag_seit', true );
			if ( ! LoyaltyRules::is_birthday( $birthday, $today ) || ( $since && time() - $since < self::GEBURTSTAG_KARENZ_TAGE * DAY_IN_SECONDS ) ) {
				continue;
			}
			if ( ! LoyaltyRepository::award_once( $user_id, 'geburtstag_' . $year, $points, 'birthday', __( 'Alles Gute zum Geburtstag', 'lindenweg16' ) ) ) {
				continue;
			}
			$user = get_userdata( $user_id );
			if ( $user && is_email( $user->user_email ) ) {
				MailTemplate::send(
					$user->user_email,
					'geburtstag',
					array(
						'{vorname}' => $user->first_name ? $user->first_name : $user->display_name,
						'{punkte}'  => number_format_i18n( $points ),
						'{punkte_gesamt}' => number_format_i18n( LoyaltyRepository::get_points( $user_id ) ),
						'{konto_url}' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ),
					)
				);
			}
		}
	}

	/* --------------------------------------------------------------------
	 * Profil
	 * ------------------------------------------------------------------ */

	/**
	 * Was fehlt einem Kunden zum vollständigen Profil?
	 *
	 * @param int $user_id Kunde.
	 * @return string[]
	 */
	public static function profile_missing( $user_id ) {
		$c = new \WC_Customer( (int) $user_id );
		return LoyaltyRules::profile_missing(
			array(
				'first_name' => $c->get_billing_first_name() ? $c->get_billing_first_name() : $c->get_first_name(),
				'last_name'  => $c->get_billing_last_name() ? $c->get_billing_last_name() : $c->get_last_name(),
				'address_1'  => $c->get_billing_address_1(),
				'postcode'   => $c->get_billing_postcode(),
				'city'       => $c->get_billing_city(),
				'phone'      => $c->get_billing_phone(),
			)
		);
	}

	/**
	 * Bonus, sobald das Profil vollständig ist (einmal).
	 *
	 * @param int $user_id Kunde.
	 */
	public function check_profile( $user_id ) {
		$points = (int) Settings::get( 'punkte_profil', 0 );
		if ( $points <= 0 || get_user_meta( $user_id, 'lw16_bonus_profil', true ) || self::profile_missing( $user_id ) ) {
			return;
		}
		LoyaltyRepository::award_once( $user_id, 'profil', $points, 'profile', __( 'Danke fürs vollständige Profil', 'lindenweg16' ) );
	}

	/* --------------------------------------------------------------------
	 * Jahrestag
	 * ------------------------------------------------------------------ */

	/**
	 * Täglich: Jahrestag der ersten Bestellung. Vorher werden für ältere
	 * Kunden die Tage der ersten Bestellung nachgetragen (in Portionen).
	 */
	public function process_anniversaries() {
		$this->backfill_first_orders();

		$points = (int) Settings::get( 'punkte_jahrestag', 0 );
		if ( $points <= 0 ) {
			return;
		}
		$today = current_time( 'Y-m-d' );
		$md    = substr( $today, 4 ); // „-10-28“.
		$likes = array( $md );
		if ( '-02-28' === $md && ! checkdate( 2, 29, (int) substr( $today, 0, 4 ) ) ) {
			$likes[] = '-02-29';
		}

		foreach ( $likes as $like ) {
			$users = get_users(
				array(
					'meta_key'     => self::META_ERSTE_BESTELLUNG, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'   => $like, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_compare' => 'LIKE',
					'fields'       => 'ID',
					'number'       => self::BATCH,
				)
			);
			foreach ( $users as $user_id ) {
				$years = LoyaltyRules::anniversary_years( (string) get_user_meta( $user_id, self::META_ERSTE_BESTELLUNG, true ), $today );
				if ( $years < 1 ) {
					continue;
				}
				/* translators: %d: Jahre */
				$note = sprintf( _n( '%d Jahr dabei', '%d Jahre dabei', $years, 'lindenweg16' ), $years );
				if ( ! LoyaltyRepository::award_once( $user_id, 'jahrestag_' . substr( $today, 0, 4 ), $points, 'anniversary', $note ) ) {
					continue;
				}
				$user = get_userdata( $user_id );
				if ( $user && is_email( $user->user_email ) ) {
					MailTemplate::send(
						$user->user_email,
						'jahrestag',
						array(
							'{vorname}'       => $user->first_name ? $user->first_name : $user->display_name,
							'{jahre}'         => (string) $years,
							'{punkte}'        => number_format_i18n( $points ),
							'{punkte_gesamt}' => number_format_i18n( LoyaltyRepository::get_points( $user_id ) ),
							'{konto_url}'     => wc_get_page_permalink( 'myaccount' ),
						)
					);
				}
			}
		}
	}

	/**
	 * Tag der ersten Bestellung für Kunden nachtragen, die vor diesem Update
	 * bestellt haben – je Tageslauf eine Portion.
	 */
	protected function backfill_first_orders() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$users = get_users(
			array(
				'role__in'   => array( 'customer', 'subscriber' ),
				'meta_query' => array( array( 'key' => self::META_ERSTE_BESTELLUNG, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'     => 'ID',
				'number'     => self::BATCH,
			)
		);
		foreach ( $users as $user_id ) {
			$first = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'status'      => array( 'wc-completed' ),
					'orderby'     => 'date',
					'order'       => 'ASC',
					'limit'       => 1,
				)
			);
			$date = $first && $first[0]->get_date_created() ? $first[0]->get_date_created()->date_i18n( 'Y-m-d' ) : '-';
			update_user_meta( $user_id, self::META_ERSTE_BESTELLUNG, $date );
		}
	}

	/* --------------------------------------------------------------------
	 * Verfall
	 * ------------------------------------------------------------------ */

	/**
	 * Verfallsdatum der Punkte eines Kunden (0 = verfallen nicht).
	 *
	 * @param int $user_id Kunde.
	 * @return int Unix-Zeit.
	 */
	public static function expires_at( $user_id ) {
		$state = LoyaltyRules::expiry_state(
			LoyaltyRepository::last_activity( $user_id ),
			(int) Settings::get( 'punkte_verfall_start', 0 ),
			(int) Settings::get( 'punkte_verfall_monate', 0 ),
			0,
			time()
		);
		return (int) $state['expires'];
	}

	/**
	 * Täglich: erinnern und verfallen lassen.
	 */
	public function process_expiry() {
		$months = (int) Settings::get( 'punkte_verfall_monate', 0 );
		if ( $months <= 0 ) {
			return;
		}
		$start = (int) Settings::get( 'punkte_verfall_start', 0 );
		if ( ! $start ) {
			// Eingeschaltet, aber ohne Stichtag (z. B. über die alte
			// Einstellungsseite): Die Frist beginnt für alle heute.
			Settings::update( array( 'punkte_verfall_start' => time() ) );
			return;
		}
		$remind = (int) Settings::get( 'punkte_verfall_erinnerung_tage', 30 );
		$now    = time();

		$users = get_users(
			array(
				'meta_key'     => LoyaltyRepository::META_POINTS, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'   => 0, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => '>',
				'meta_type'    => 'NUMERIC',
				'fields'       => 'ID',
				'number'       => self::BATCH * 4,
			)
		);

		foreach ( $users as $user_id ) {
			$points = LoyaltyRepository::get_points( $user_id );
			if ( $points <= 0 ) {
				continue;
			}
			$state = LoyaltyRules::expiry_state( LoyaltyRepository::last_activity( $user_id ), $start, $months, $remind, $now );

			if ( 'expire' === $state['state'] ) {
				LoyaltyRepository::adjust_points( $user_id, -1 * $points );
				LoyaltyRepository::add_ledger(
					array(
						'user_id' => $user_id,
						'points'  => -1 * $points,
						'type'    => 'expired',
						'status'  => 'available',
						/* translators: %d: Monate */
						'notiz'   => sprintf( __( 'Verfallen nach %d Monaten ohne Aktivität', 'lindenweg16' ), $months ),
					)
				);
				delete_user_meta( $user_id, self::META_VERFALL_ERINNERT );
				continue;
			}

			if ( 'remind' === $state['state'] && (int) get_user_meta( $user_id, self::META_VERFALL_ERINNERT, true ) !== $state['expires'] ) {
				update_user_meta( $user_id, self::META_VERFALL_ERINNERT, $state['expires'] );
				$user = get_userdata( $user_id );
				if ( $user && is_email( $user->user_email ) ) {
					$step = max( 1, (int) Settings::get( 'punkte_einloesewert', 100 ) );
					MailTemplate::send(
						$user->user_email,
						'punkte_verfall',
						array(
							'{vorname}'   => $user->first_name ? $user->first_name : $user->display_name,
							'{punkte}'    => number_format_i18n( $points ),
							'{wert}'      => html_entity_decode( wp_strip_all_tags( wc_price( Calc::points_to_euro( $points, $step ) ) ), ENT_QUOTES, 'UTF-8' ),
							'{datum}'     => date_i18n( 'j. F Y', $state['expires'] ),
							'{shop_url}'  => wc_get_page_permalink( 'shop' ),
						)
					);
				}
			}
		}
	}

	/* --------------------------------------------------------------------
	 * Bewertungen
	 * ------------------------------------------------------------------ */

	/**
	 * Neue Bewertung: nur sofort sichtbare zählen gleich.
	 *
	 * @param int        $comment_id Kommentar.
	 * @param int|string $approved   1 = sichtbar.
	 */
	public function on_comment_post( $comment_id, $approved ) {
		if ( 1 === (int) $approved ) {
			$this->award_review( $comment_id );
		}
	}

	/**
	 * Bewertung freigeschaltet.
	 *
	 * @param string      $new     Neuer Status.
	 * @param string      $old     Alter Status.
	 * @param \WP_Comment $comment Kommentar.
	 */
	public function on_comment_status( $new, $old, $comment ) {
		if ( 'approved' === $new && 'approved' !== $old && $comment ) {
			$this->award_review( (int) $comment->comment_ID );
		}
	}

	/**
	 * Punkte für eine sichtbare Produktbewertung, plus Foto-Bonus. Einmal je
	 * Kunde und Produkt.
	 *
	 * @param int $comment_id Kommentar.
	 */
	public function award_review( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment || ! in_array( (string) $comment->comment_type, array( 'review', 'comment', '' ), true ) ) {
			return;
		}
		$user_id    = (int) $comment->user_id;
		$product_id = (int) $comment->comment_post_ID;
		$flag       = 'lw16_review_bonus_' . $product_id;

		$eligible = Calc::review_bonus_eligible(
			get_post_type( $product_id ),
			function_exists( 'mb_strlen' ) ? mb_strlen( trim( $comment->comment_content ) ) : strlen( trim( $comment->comment_content ) ),
			(int) Settings::get( 'review_min_zeichen', 15 ),
			$user_id,
			$user_id ? (bool) get_user_meta( $user_id, $flag, true ) : false
		);
		if ( ! $eligible ) {
			return;
		}
		if ( (int) Settings::get( 'review_nur_kaeufer', 1 ) && function_exists( 'wc_customer_bought_product' )
			&& ! wc_customer_bought_product( '', $user_id, $product_id ) ) {
			return;
		}

		update_user_meta( $user_id, $flag, 1 );
		$name = get_the_title( $product_id );
		/* translators: %s: Produktname */
		LoyaltyRepository::award( $user_id, (int) Settings::get( 'review_bonus_punkte', 20 ), 'review_bonus', sprintf( __( 'Bewertung: %s', 'lindenweg16' ), $name ) );

		$photos = (array) get_comment_meta( $comment_id, ReviewPhotos::META, true );
		if ( array_filter( $photos ) ) {
			/* translators: %s: Produktname */
			LoyaltyRepository::award( $user_id, (int) Settings::get( 'punkte_bewertung_foto', 0 ), 'review_photo', sprintf( __( 'Foto zur Bewertung: %s', 'lindenweg16' ), $name ) );
		}
	}
}
