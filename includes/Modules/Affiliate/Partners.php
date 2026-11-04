<?php
/**
 * Affiliates: Speicher der Partner und Auszahlungen, Abgleich mit dem WooCommerce-Gutschein.
 *
 * Jeder Partner hat genau einen Rabattcode. Der Code ist ein ganz normaler
 * WooCommerce-Gutschein (Prozent auf den Warenkorb), damit Warenkorb, Kasse und
 * Rechnung ihn ohne Zutun kennen. Die Verbindung steht im Gutschein
 * (`_lw16_affiliate_id`) und im Partner (`coupon_id`).
 *
 * Partner werden nie gelöscht, nur abgeschaltet: Der Code steht in Videos und
 * Beschreibungen, und die Auswertung vergangener Monate braucht den Partner.
 * Auszahlungen werden nur angehängt; ein Fehler wird mit einer Korrektur
 * (negativer Betrag mit Notiz) berichtigt.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Affiliate;

defined( 'ABSPATH' ) || exit;

/**
 * Partner und Auszahlungen.
 */
class Partners {

	const OPTION_PARTNERS = 'lw16_affiliates';
	const OPTION_PAYOUTS  = 'lw16_affiliate_payouts';
	const OPTION_REQUESTS = 'lw16_affiliate_requests';
	const META_COUPON     = '_lw16_affiliate_id';

	/**
	 * Alle Partner.
	 *
	 * @return array Id => Partner.
	 */
	public static function all() {
		$stored = get_option( self::OPTION_PARTNERS, array() );
		$out    = array();

		foreach ( is_array( $stored ) ? $stored : array() as $p ) {
			if ( ! is_array( $p ) || empty( $p['id'] ) || empty( $p['code'] ) ) {
				continue;
			}

			$out[ (int) $p['id'] ] = array_merge(
				array(
					'name'      => '',
					'plattform' => '',
					'email'     => '',
					'rabatt'    => 0.0,
					'provision' => 0.0,
					'aktiv'     => true,
					'notiz'     => '',
					'angelegt'  => '',
					'coupon_id' => 0,
					'user_id'   => 0,
				),
				$p,
				array( 'id' => (int) $p['id'] )
			);
		}

		return $out;
	}

	/**
	 * Einen Partner holen.
	 *
	 * @param int $id Kennung.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();

		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	/**
	 * Gehört der Code (egal ob groß oder klein) schon einem Partner?
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function code_used( $code ) {
		foreach ( self::all() as $p ) {
			if ( 0 === strcasecmp( $p['code'], $code ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Neuen Partner anlegen und den Gutschein dazu erzeugen.
	 *
	 * @param array $in    Eingaben, siehe Rules::clean_partner().
	 * @param array $konto Benutzerkonto: `email` und `anlegen` (siehe resolve_konto()).
	 * @return array [ 'ok' => bool, 'errors' => Meldungen, 'id' => int ].
	 */
	public static function create( array $in, array $konto = array() ) {
		$clean  = Rules::clean_partner( $in, true );
		$errors = $clean['errors'];
		$row    = $clean['row'];
		$user   = self::resolve_konto( $konto, 0 );
		$errors = array_merge( $errors, $user['errors'] );

		if ( ! $errors ) {
			if ( self::code_used( $row['code'] ) || ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $row['code'] ) ) ) {
				$errors[] = __( 'Diesen Code gibt es schon – als Affiliate oder als anderen Gutschein im Shop. Bitte einen anderen wählen.', 'lindenweg16' );
			}
		}

		if ( $errors ) {
			return array(
				'ok'     => false,
				'errors' => $errors,
				'id'     => 0,
			);
		}

		$user_id = self::materialize_konto( $user, $row['name'], $errors );
		if ( $errors ) {
			return array(
				'ok'     => false,
				'errors' => $errors,
				'id'     => 0,
			);
		}

		$all      = self::all();
		$id       = $all ? max( array_keys( $all ) ) + 1 : 1;
		$row      = array_merge(
			$row,
			array(
				'id'        => $id,
				'angelegt'  => wp_date( 'Y-m-d' ),
				'coupon_id' => 0,
				'user_id'   => $user_id,
			)
		);
		$coupon   = self::sync_coupon( $row );
		$row['coupon_id'] = $coupon;

		if ( $coupon <= 0 ) {
			return array(
				'ok'     => false,
				'errors' => array( __( 'Der Gutschein im Shop konnte nicht angelegt werden. WooCommerce ist nicht erreichbar.', 'lindenweg16' ) ),
				'id'     => 0,
			);
		}

		$all[ $id ] = $row;
		update_option( self::OPTION_PARTNERS, array_values( $all ), false );
		self::sync_role( 0, $user_id );

		return array(
			'ok'     => true,
			'errors' => array(),
			'id'     => $id,
		);
	}

	/**
	 * Einen bestehenden Partner ändern (der Code bleibt).
	 *
	 * @param int   $id    Kennung.
	 * @param array $in    Eingaben.
	 * @param array $konto Benutzerkonto: `email` und `anlegen`; ohne den Schlüssel `email` bleibt das Konto, wie es ist.
	 * @return array [ 'ok' => bool, 'errors' => Meldungen ].
	 */
	public static function update( $id, array $in, array $konto = array() ) {
		$all = self::all();

		if ( ! isset( $all[ (int) $id ] ) ) {
			return array(
				'ok'     => false,
				'errors' => array( __( 'Diesen Affiliate gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ) ),
			);
		}

		$clean  = Rules::clean_partner( $in, false );
		$errors = $clean['errors'];
		$old    = (int) $all[ (int) $id ]['user_id'];
		$user   = array_key_exists( 'email', $konto )
			? self::resolve_konto( $konto, (int) $id )
			: array(
				'user_id' => $old,
				'create'  => false,
				'email'   => '',
				'errors'  => array(),
			);
		$errors = array_merge( $errors, $user['errors'] );

		if ( $errors ) {
			return array(
				'ok'     => false,
				'errors' => $errors,
			);
		}

		$user_id = self::materialize_konto( $user, $clean['row']['name'], $errors );
		if ( $errors ) {
			return array(
				'ok'     => false,
				'errors' => $errors,
			);
		}

		$merged              = array_merge( $all[ (int) $id ], $clean['row'], array( 'user_id' => $user_id ) );
		$merged['coupon_id'] = self::sync_coupon( $merged );

		$all[ (int) $id ] = $merged;
		update_option( self::OPTION_PARTNERS, array_values( $all ), false );
		self::sync_role( $old, $user_id );

		return array(
			'ok'     => true,
			'errors' => array(),
		);
	}

	/**
	 * Der Partner, der zu einem Benutzerkonto gehört.
	 *
	 * @param int $user_id Benutzer.
	 * @return array|null
	 */
	public static function for_user( $user_id ) {
		if ( (int) $user_id <= 0 ) {
			return null;
		}

		foreach ( self::all() as $p ) {
			if ( (int) $p['user_id'] === (int) $user_id ) {
				return $p;
			}
		}

		return null;
	}

	/**
	 * Benutzerkonto zu einer E-Mail-Adresse suchen und prüfen, ohne etwas anzulegen.
	 *
	 * @param array $konto      `email` und `anlegen` (Konto anlegen, wenn es keins gibt).
	 * @param int   $partner_id Partner, der gerade bearbeitet wird (0 = neu).
	 * @return array [ 'user_id' => int, 'create' => bool, 'email' => string, 'errors' => Meldungen ].
	 */
	public static function resolve_konto( array $konto, $partner_id ) {
		$out   = array(
			'user_id' => 0,
			'create'  => false,
			'email'   => '',
			'errors'  => array(),
		);
		$email = isset( $konto['email'] ) && is_string( $konto['email'] ) ? sanitize_email( trim( $konto['email'] ) ) : '';

		if ( '' === $email ) {
			return $out;
		}

		if ( ! is_email( $email ) ) {
			$out['errors'][] = __( 'Die E-Mail-Adresse des Kontos ist nicht gültig.', 'lindenweg16' );
			return $out;
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			if ( empty( $konto['anlegen'] ) ) {
				$out['errors'][] = __( 'Zu dieser Adresse gibt es kein Konto im Shop. Setze den Haken „Konto anlegen und Einladung mailen“, oder bitte den Influencer, sich im Shop unter „Mein Konto“ zu registrieren.', 'lindenweg16' );
			} else {
				$out['create'] = true;
				$out['email']  = $email;
			}

			return $out;
		}

		foreach ( self::all() as $p ) {
			if ( (int) $p['user_id'] === (int) $user->ID && (int) $p['id'] !== (int) $partner_id ) {
				/* translators: %s: Name des anderen Affiliates. */
				$out['errors'][] = sprintf( __( 'Dieses Konto gehört schon zu %s.', 'lindenweg16' ), $p['name'] );
				return $out;
			}
		}

		$out['user_id'] = (int) $user->ID;

		return $out;
	}

	/**
	 * Das Konto bei Bedarf anlegen. Die Einladung geht als WordPress-Mail mit Link zum Passwort setzen raus.
	 *
	 * @param array  $user   Ergebnis von resolve_konto().
	 * @param string $name   Name des Influencers.
	 * @param array  $errors Fehlerliste (wird ergänzt).
	 * @return int Benutzerkennung oder 0.
	 */
	protected static function materialize_konto( array $user, $name, array &$errors ) {
		if ( ! $user['create'] ) {
			return (int) $user['user_id'];
		}

		$base  = sanitize_user( current( explode( '@', $user['email'] ) ), true );
		$base  = '' !== $base ? $base : 'ambassador';
		$login = $base;
		$n     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . ++$n;
		}

		$id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $user['email'],
				'user_pass'    => wp_generate_password( 24 ),
				'display_name' => $name,
				'role'         => get_role( 'customer' ) ? 'customer' : get_option( 'default_role', 'subscriber' ),
			)
		);

		if ( is_wp_error( $id ) ) {
			$errors[] = $id->get_error_message();
			return 0;
		}

		wp_new_user_notification( $id, null, 'user' );

		return (int) $id;
	}

	/**
	 * Rolle „Ambassador“ vom alten Konto nehmen (wenn kein anderer Partner daran hängt) und dem neuen geben.
	 *
	 * @param int $old Bisheriges Konto.
	 * @param int $new Neues Konto.
	 */
	protected static function sync_role( $old, $new ) {
		if ( (int) $old === (int) $new ) {
			if ( $new ) {
				self::give_role( $new );
			}
			return;
		}

		if ( $old && ! self::for_user( $old ) ) {
			$u = get_userdata( $old );
			if ( $u ) {
				$u->remove_role( \Lindenweg16\Core\Roles::AMBASSADOR_ROLE );
			}
		}

		if ( $new ) {
			self::give_role( $new );
		}
	}

	/**
	 * Einem Konto die Rolle Ambassador dazugeben.
	 *
	 * @param int $user_id Benutzer.
	 */
	protected static function give_role( $user_id ) {
		\Lindenweg16\Core\Roles::add_ambassador_role();
		$u = get_userdata( $user_id );
		if ( $u && ! in_array( \Lindenweg16\Core\Roles::AMBASSADOR_ROLE, (array) $u->roles, true ) ) {
			$u->add_role( \Lindenweg16\Core\Roles::AMBASSADOR_ROLE );
		}
	}

	/**
	 * Konto wurde gelöscht: Verknüpfung lösen, der Partner bleibt.
	 *
	 * @param int $user_id Gelöschter Benutzer.
	 */
	public static function forget_user( $user_id ) {
		$all     = self::all();
		$changed = false;

		foreach ( $all as $id => $p ) {
			if ( (int) $p['user_id'] === (int) $user_id ) {
				$all[ $id ]['user_id'] = 0;
				$changed               = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION_PARTNERS, array_values( $all ), false );
		}
	}

	/**
	 * Gutschein im Shop anlegen oder angleichen: Rabatt in Prozent und Status.
	 *
	 * @param array $p Partner.
	 * @return int Gutschein-Kennung oder 0.
	 */
	public static function sync_coupon( array $p ) {
		if ( ! class_exists( '\WC_Coupon' ) ) {
			return 0;
		}

		$existing = function_exists( 'wc_get_coupon_id_by_code' ) ? (int) wc_get_coupon_id_by_code( $p['code'] ) : 0;
		$coupon   = new \WC_Coupon( $existing );

		if ( ! $existing ) {
			$coupon->set_code( $p['code'] );
			$coupon->set_discount_type( 'percent' );
		}

		$coupon->set_amount( $p['rabatt'] );
		$coupon->set_status( $p['aktiv'] ? 'publish' : 'draft' );
		/* translators: 1: Name, 2: Kanal. */
		$coupon->set_description( sprintf( __( 'Affiliate: %1$s %2$s', 'lindenweg16' ), $p['name'], $p['plattform'] ? '(' . $p['plattform'] . ')' : '' ) );
		$coupon->update_meta_data( self::META_COUPON, (int) $p['id'] );

		return (int) $coupon->save();
	}

	/**
	 * Alle Auszahlungen, älteste zuerst.
	 *
	 * @return array
	 */
	public static function payouts() {
		$stored = get_option( self::OPTION_PAYOUTS, array() );

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : array();
	}

	/**
	 * Eine Auszahlung anhängen.
	 *
	 * @param int    $partner Kennung.
	 * @param float  $betrag  Betrag in Euro (negativ = Korrektur).
	 * @param string $datum   Datum Y-m-d.
	 * @param string $notiz   Notiz.
	 * @return array Die gespeicherte Zeile.
	 */
	public static function add_payout( $partner, $betrag, $datum, $notiz ) {
		$list   = self::payouts();
		$ids    = array_map(
			function ( $r ) {
				return (int) $r['id'];
			},
			$list
		);
		$row    = array(
			'id'      => $ids ? max( $ids ) + 1 : 1,
			'partner' => (int) $partner,
			'betrag'  => round( (float) $betrag, 2 ),
			'datum'   => $datum,
			'notiz'   => $notiz,
			'user'    => get_current_user_id(),
		);
		$list[] = $row;

		update_option( self::OPTION_PAYOUTS, $list, false );

		return $row;
	}

	/**
	 * Alle Auszahlungsanforderungen, älteste zuerst.
	 *
	 * @return array
	 */
	public static function requests() {
		$stored = get_option( self::OPTION_REQUESTS, array() );

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : array();
	}

	/**
	 * Die offene Anforderung eines Partners.
	 *
	 * @param int $partner Kennung.
	 * @return array|null
	 */
	public static function open_request( $partner ) {
		foreach ( self::requests() as $r ) {
			if ( (int) $r['partner'] === (int) $partner && 'offen' === $r['status'] ) {
				return $r;
			}
		}

		return null;
	}

	/**
	 * Eine Anforderung anhängen.
	 *
	 * @param int    $partner Kennung.
	 * @param float  $betrag  Angeforderter Betrag.
	 * @param string $ziel    Zahlungsangabe.
	 * @return array Die gespeicherte Zeile.
	 */
	public static function add_request( $partner, $betrag, $ziel ) {
		$list   = self::requests();
		$ids    = array_map(
			function ( $r ) {
				return (int) $r['id'];
			},
			$list
		);
		$row    = array(
			'id'      => $ids ? max( $ids ) + 1 : 1,
			'partner' => (int) $partner,
			'betrag'  => round( (float) $betrag, 2 ),
			'ziel'    => $ziel,
			'status'  => 'offen',
			'ts'      => time(),
			'ende_ts' => 0,
			'notiz'   => '',
		);
		$list[] = $row;

		update_option( self::OPTION_REQUESTS, $list, false );

		return $row;
	}

	/**
	 * Offene Anforderungen eines Partners abschließen.
	 *
	 * @param int    $partner Kennung.
	 * @param string $status  erledigt | abgelehnt.
	 * @param string $notiz   Begründung (bei „abgelehnt“).
	 */
	public static function close_requests( $partner, $status, $notiz = '' ) {
		$list    = self::requests();
		$changed = false;

		foreach ( $list as $i => $r ) {
			if ( (int) $r['partner'] === (int) $partner && 'offen' === $r['status'] ) {
				$list[ $i ]['status']  = $status;
				$list[ $i ]['ende_ts'] = time();
				$list[ $i ]['notiz']   = $notiz;
				$changed               = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION_REQUESTS, $list, false );
		}
	}
}
