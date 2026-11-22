<?php
/**
 * Empfehlungscodes: Vorname plus Zahl (zum Beispiel „Anna47“).
 *
 * Auch Gäste bekommen einen Code, gespeichert zu ihrer E-Mail-Adresse. Bestellt
 * ein Freund mit diesem Code, steht die Empfehlung beim Gast bereit; die
 * Belohnung wird erst nach der Wartezeit gutgeschrieben, wenn der Gast sich mit
 * derselben E-Mail-Adresse ein Konto angelegt hat („Einlösen“). Bis dahin bleibt
 * die Empfehlung offen.
 *
 * Die Regeln für das Aussehen des Codes sind reine Funktionen und testbar.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Loyalty;

defined( 'ABSPATH' ) || exit;

/**
 * Erzeugt, findet und überträgt Codes.
 */
class LoyaltyCodes {

	/**
	 * Alte Codes eines Kontos, die weiter gelten (Meta, mehrfach).
	 */
	const META_ALT = 'lw16_referral_code_alt';

	/**
	 * Merker: Umstellung der vorhandenen Codes ist gelaufen.
	 */
	const OPTION_MIGRATED = 'lw16_codes_vorname';

	/**
	 * Längster Namensteil.
	 */
	const MAX_NAME = 12;

	/**
	 * Tabelle der Gast-Codes.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'lw16_guest_codes';
	}

	/**
	 * Namensteil aus einem Vornamen – reine Regel.
	 *
	 * Umlaute werden aufgelöst, alles außer Buchstaben fällt weg, der erste
	 * Buchstabe wird groß geschrieben. Ohne brauchbaren Namen: „Freund“.
	 *
	 * @param string $first Vorname.
	 * @return string
	 */
	public static function base( $first ) {
		$map  = array(
			'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
			'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
			'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n',
			'É' => 'E', 'È' => 'E', 'Á' => 'A', 'À' => 'A', 'Ó' => 'O', 'Í' => 'I', 'Ú' => 'U', 'Ç' => 'C', 'Ñ' => 'N',
			'ø' => 'o', 'å' => 'a', 'æ' => 'ae', 'ł' => 'l', 'š' => 's', 'č' => 'c', 'ž' => 'z',
		);
		$name = preg_replace( '/[^A-Za-z]/', '', strtr( trim( (string) $first ), $map ) );

		if ( '' === $name ) {
			return 'Freund';
		}

		return substr( ucfirst( strtolower( $name ) ), 0, self::MAX_NAME );
	}

	/**
	 * Einen freien Code bauen – reine Regel mit eingesetzter Prüfung.
	 *
	 * Erst zweistellige Zahlen, bei Belegung dreistellige, dann vierstellige.
	 *
	 * @param string        $first Vorname.
	 * @param callable      $taken Prüfung: ist der Code schon vergeben?
	 * @param callable|null $rand  Zufall (min, max); für Tests.
	 * @return string
	 */
	public static function build( $first, callable $taken, $rand = null ) {
		$base = self::base( $first );
		$rand = $rand ? $rand : 'random_int';

		foreach ( array( 2 => 20, 3 => 30, 4 => 50 ) as $digits => $tries ) {
			for ( $i = 0; $i < $tries; $i++ ) {
				$code = $base . $rand( (int) pow( 10, $digits - 1 ), (int) pow( 10, $digits ) - 1 );

				if ( ! $taken( $code ) ) {
					return $code;
				}
			}
		}

		return $base . $rand( 100000, 999999 );
	}

	/**
	 * Hat der Code das alte Format (acht Großbuchstaben und Ziffern)?
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function is_old_format( $code ) {
		return 1 === preg_match( '/^[A-Z0-9]{8}$/', (string) $code );
	}

	/**
	 * Ist der Code bei einem Konto, einem alten Code oder einem Gast vergeben?
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function taken( $code ) {
		return LoyaltyRepository::user_id_by_code( $code ) > 0 || null !== self::guest_by_code( $code );
	}

	/**
	 * Vorname eines Kontos.
	 *
	 * @param int $user_id Konto.
	 * @return string
	 */
	public static function first_name_of_user( $user_id ) {
		foreach ( array( 'first_name', 'billing_first_name', 'shipping_first_name' ) as $key ) {
			$name = trim( (string) get_user_meta( $user_id, $key, true ) );

			if ( '' !== $name ) {
				return $name;
			}
		}

		$user = get_userdata( $user_id );

		return $user ? (string) strtok( trim( (string) $user->display_name ), ' ' ) : '';
	}

	/**
	 * Neuen Code für ein Konto bauen.
	 *
	 * @param int $user_id Konto.
	 * @return string
	 */
	public static function build_for_user( $user_id ) {
		return self::build( self::first_name_of_user( $user_id ), array( __CLASS__, 'taken' ) );
	}

	/**
	 * Gast zu einem Code.
	 *
	 * @param string $code Code.
	 * @return object|null Zeile (id, email, code, claimed_user_id) oder null.
	 */
	public static function guest_by_code( $code ) {
		global $wpdb;

		$code = trim( (string) $code );

		if ( '' === $code ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE code = %s LIMIT 1', $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * Code für die Bestellung eines Kunden – Konto oder Gast.
	 *
	 * Hat der Käufer ein Konto (auch wenn er als Gast bestellt hat), gilt der
	 * Code seines Kontos. Sonst bekommt die E-Mail-Adresse einen Gast-Code,
	 * der bei der nächsten Bestellung derselbe bleibt.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return string Code oder leer, wenn nicht einmal eine E-Mail-Adresse da ist.
	 */
	public static function code_for_order( $order ) {
		$user_id = (int) $order->get_customer_id();
		$email   = strtolower( trim( (string) $order->get_billing_email() ) );

		if ( $user_id < 1 && '' !== $email ) {
			$user = get_user_by( 'email', $email );
			$user_id = $user ? (int) $user->ID : 0;
		}

		if ( $user_id > 0 ) {
			return LoyaltyRepository::get_or_create_code( $user_id );
		}

		if ( '' === $email ) {
			return '';
		}

		return self::guest_code( $email, (string) $order->get_billing_first_name() );
	}

	/**
	 * Gast-Code zu einer E-Mail-Adresse holen oder anlegen.
	 *
	 * @param string $email Adresse (klein geschrieben).
	 * @param string $first Vorname.
	 * @return string
	 */
	public static function guest_code( $email, $first ) {
		global $wpdb;

		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT code FROM ' . self::table() . ' WHERE email = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing ) {
			return (string) $existing;
		}

		$code = self::build( $first, array( __CLASS__, 'taken' ) );

		$wpdb->insert(
			self::table(),
			array(
				'email'      => $email,
				'code'       => $code,
				'first_name' => mb_substr( trim( $first ), 0, 60 ),
				'created_at' => current_time( 'mysql' ),
			)
		);

		// Zwei gleichzeitige Aufrufe: Gewonnen hat, wer zuerst eingetragen hat.
		$won = $wpdb->get_var( $wpdb->prepare( 'SELECT code FROM ' . self::table() . ' WHERE email = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $won ? (string) $won : $code;
	}

	/**
	 * Neues Konto: den Gast-Code der E-Mail-Adresse übernehmen.
	 *
	 * Das Konto bekommt den Code des Gastes (ein schon vergebener eigener Code
	 * gilt als alter Code weiter), und die offenen Empfehlungen des Gastes
	 * gehören ab jetzt dem Konto. Danach laufen sie durch die normale Wartezeit.
	 *
	 * @param int $user_id Neues Konto.
	 * @return bool Ob ein Gast-Code übernommen wurde.
	 */
	public static function claim_guest( $user_id ) {
		global $wpdb;

		$user = get_userdata( $user_id );

		if ( ! $user || '' === trim( (string) $user->user_email ) ) {
			return false;
		}

		$guest = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s AND claimed_user_id IS NULL', strtolower( trim( $user->user_email ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $guest ) {
			return false;
		}

		$own = (string) get_user_meta( $user_id, LoyaltyRepository::META_CODE, true );

		if ( '' !== $own ) {
			add_user_meta( $user_id, self::META_ALT, $own );
		}

		update_user_meta( $user_id, LoyaltyRepository::META_CODE, $guest->code );
		$wpdb->update( self::table(), array( 'claimed_user_id' => (int) $user_id ), array( 'id' => (int) $guest->id ) );

		$referrals = LoyaltyRepository::table( 'referrals' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$referrals} SET referrer_user_id = %d, referrer_guest_id = NULL WHERE referrer_guest_id = %d", $user_id, (int) $guest->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return true;
	}

	/**
	 * Bei der Registrierung: erst den Gast-Code übernehmen, sonst einen neuen bauen.
	 *
	 * @param int $user_id Neues Konto.
	 */
	public static function on_user_register( $user_id ) {
		if ( ! self::claim_guest( (int) $user_id ) ) {
			LoyaltyRepository::get_or_create_code( (int) $user_id );
		}
	}

	/**
	 * Alle vorhandenen Codes im alten Format einmalig auf Vorname plus Zahl umstellen.
	 *
	 * Der alte Code bleibt als zusätzlicher Code des Kontos gültig: schon
	 * verschickte Links und gedruckte Zettel funktionieren weiter.
	 */
	public static function maybe_migrate() {
		if ( get_option( self::OPTION_MIGRATED ) ) {
			return;
		}

		$users = get_users(
			array(
				'meta_key'     => LoyaltyRepository::META_CODE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'EXISTS',
				'number'       => -1,
				'fields'       => array( 'ID' ),
			)
		);

		foreach ( $users as $user ) {
			$old = (string) get_user_meta( (int) $user->ID, LoyaltyRepository::META_CODE, true );

			if ( ! self::is_old_format( $old ) ) {
				continue;
			}

			$new = self::build_for_user( (int) $user->ID );
			add_user_meta( (int) $user->ID, self::META_ALT, $old );
			update_user_meta( (int) $user->ID, LoyaltyRepository::META_CODE, $new );
		}

		update_option( self::OPTION_MIGRATED, current_time( 'mysql' ), false );
	}
}
