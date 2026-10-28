<?php
/**
 * Datenzugriff für Loyalty (Referrals + Punkte-Ledger).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Loyalty;

defined( 'ABSPATH' ) || exit;

/**
 * Kapselt Referrals, Punkte-Ledger und die User-Meta-Salden.
 */
class LoyaltyRepository {

	const META_CODE    = 'lw16_referral_code';
	const META_CREDIT  = 'lw16_credit_balance';
	const META_POINTS  = 'lw16_points_balance';

	/**
	 * Tabellenname.
	 *
	 * @param string $name referrals|points_ledger.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'lw16_' . $name;
	}

	/* --------------------------------------------------------------------
	 * Referral-Codes
	 * ------------------------------------------------------------------ */

	/**
	 * Referral-Code eines Nutzers holen oder erzeugen.
	 *
	 * @param int $user_id Nutzer-ID.
	 * @return string
	 */
	public static function get_or_create_code( $user_id ) {
		$code = get_user_meta( $user_id, self::META_CODE, true );
		if ( $code ) {
			return $code;
		}
		do {
			$code   = strtoupper( wp_generate_password( 8, false ) );
			$exists = self::user_id_by_code( $code );
		} while ( $exists );
		update_user_meta( $user_id, self::META_CODE, $code );
		return $code;
	}

	/**
	 * Nutzer-ID zu einem Referral-Code finden.
	 *
	 * @param string $code Code.
	 * @return int 0, wenn nicht gefunden.
	 */
	public static function user_id_by_code( $code ) {
		$users = get_users(
			array(
				'meta_key'   => self::META_CODE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $code, // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'     => 1,
				'fields'     => array( 'ID' ),
			)
		);
		return $users ? (int) $users[0]->ID : 0;
	}

	/* --------------------------------------------------------------------
	 * Referrals
	 * ------------------------------------------------------------------ */

	/**
	 * Referral-Datensatz anlegen.
	 *
	 * @param array $data Referral-Daten.
	 * @return int
	 */
	public static function create_referral( array $data ) {
		global $wpdb;
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( self::table( 'referrals' ), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Referral-Status setzen.
	 *
	 * @param int    $id     Referral-ID.
	 * @param string $status Neuer Status.
	 */
	public static function set_referral_status( $id, $status ) {
		global $wpdb;
		$data = array( 'status' => $status );
		if ( 'completed' === $status ) {
			$data['completed_at'] = current_time( 'mysql' );
		}
		$wpdb->update( self::table( 'referrals' ), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Referral per ID.
	 *
	 * @param int $id Referral-ID.
	 * @return object|null
	 */
	public static function get_referral( $id ) {
		global $wpdb;
		$t = self::table( 'referrals' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore
	}

	/**
	 * Referrals nach Status.
	 *
	 * @param string $status Status.
	 * @return array
	 */
	public static function referrals_by_status( $status ) {
		global $wpdb;
		$t = self::table( 'referrals' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = %s ORDER BY created_at ASC", $status ) ); // phpcs:ignore
	}

	/**
	 * Referrals eines Werbers (für Mein-Konto).
	 *
	 * @param int $user_id Werber.
	 * @return array
	 */
	public static function referrals_of( $user_id ) {
		global $wpdb;
		$t = self::table( 'referrals' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE referrer_user_id = %d ORDER BY created_at DESC", $user_id ) ); // phpcs:ignore
	}

	/**
	 * Fällige pending/review-freigegebene Referrals, deren Wartezeit abgelaufen ist.
	 *
	 * @param int $wartezeit_tage Wartezeit.
	 * @return array
	 */
	public static function due_referrals( $wartezeit_tage ) {
		global $wpdb;
		$t     = self::table( 'referrals' );
		// created_at steht in lokaler Zeit (create_referral, current_time) -
		// die Grenze deshalb ebenfalls lokal rechnen, sonst verschiebt sich
		// die Reifung um den Zeitzonenversatz.
		$grenze = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $wartezeit_tage * DAY_IN_SECONDS );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'pending' AND created_at <= %s", $grenze ) ); // phpcs:ignore
	}

	/* --------------------------------------------------------------------
	 * Punkte-Ledger
	 * ------------------------------------------------------------------ */

	/**
	 * Ledger-Eintrag anlegen.
	 *
	 * @param array $data Ledger-Daten.
	 * @return int
	 */
	public static function add_ledger( array $data ) {
		global $wpdb;
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( self::table( 'points_ledger' ), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Punkte gutschreiben – sofort oder nach einer Wartezeit.
	 *
	 * Sofort: Saldo erhöhen und Eintrag „verfügbar“. Mit Wartezeit: Eintrag
	 * „ausstehend“ mit Freigabedatum; der tägliche Reifeprozess bucht ihn.
	 *
	 * @param int      $user_id   Kunde.
	 * @param int      $points    Punkte (> 0).
	 * @param string   $type      Art (siehe ledger_types()).
	 * @param string   $note      Notiz für Verlauf und ERP.
	 * @param int|null $order_id  Zugehörige Bestellung (wird bei Storno mit zurückgenommen).
	 * @param int      $wait_days Wartezeit in Tagen (0 = sofort).
	 * @return int Gutgeschriebene Punkte.
	 */
	public static function award( $user_id, $points, $type, $note, $order_id = null, $wait_days = 0 ) {
		$user_id = (int) $user_id;
		$points  = (int) $points;
		if ( $user_id <= 0 || $points <= 0 ) {
			return 0;
		}

		$row = array(
			'user_id'  => $user_id,
			'order_id' => $order_id ? (int) $order_id : null,
			'points'   => $points,
			'type'     => $type,
			'notiz'    => $note,
		);

		if ( $wait_days > 0 ) {
			$row['status']       = 'pending';
			$row['available_at'] = gmdate( 'Y-m-d H:i:s', time() + (int) $wait_days * DAY_IN_SECONDS );
		} else {
			$row['status'] = 'available';
			self::adjust_points( $user_id, $points );
		}

		self::add_ledger( $row );
		self::touch( $user_id );
		return $points;
	}

	/** Letzte Aktivität im Prämienprogramm (Unix-Zeit) am Kunden. */
	const META_AKTIV = 'lw16_punkte_aktiv';

	/**
	 * Aktivität vermerken – setzt die Verfallsfrist neu.
	 *
	 * @param int $user_id Kunde.
	 */
	public static function touch( $user_id ) {
		update_user_meta( (int) $user_id, self::META_AKTIV, time() );
	}

	/**
	 * Letzte Aktivität: vermerkt, sonst der jüngste Eintrag im Punkteverlauf.
	 *
	 * @param int $user_id Kunde.
	 * @return int Unix-Zeit (0 = keine).
	 */
	public static function last_activity( $user_id ) {
		$ts = (int) get_user_meta( (int) $user_id, self::META_AKTIV, true );
		if ( $ts ) {
			return $ts;
		}
		global $wpdb;
		$t    = self::table( 'points_ledger' );
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(created_at) FROM {$t} WHERE user_id = %d AND type <> 'expired'", $user_id ) ); // phpcs:ignore
		return $last ? (int) strtotime( get_gmt_from_date( $last ) ) : 0;
	}

	/**
	 * Einmal-Bonus: nur wenn der Kunde ihn unter diesem Schlüssel noch nicht hat.
	 *
	 * @param int    $user_id Kunde.
	 * @param string $key     Kennung (z. B. „registrierung“).
	 * @param int    $points  Punkte.
	 * @param string $type    Art.
	 * @param string $note    Notiz.
	 * @param int    $wait    Wartezeit in Tagen.
	 * @param int    $order   Bestellung.
	 * @return int Gutgeschriebene Punkte (0, wenn schon vergeben oder ausgeschaltet).
	 */
	public static function award_once( $user_id, $key, $points, $type, $note, $wait = 0, $order = 0 ) {
		$flag = 'lw16_bonus_' . $key;
		if ( $points <= 0 || ! $user_id || get_user_meta( $user_id, $flag, true ) ) {
			return 0;
		}
		update_user_meta( $user_id, $flag, current_time( 'mysql' ) );
		return self::award( $user_id, $points, $type, $note, $order ? $order : null, $wait );
	}

	/**
	 * Arten von Punktebewegungen mit Bezeichnung für Kunde und ERP.
	 *
	 * @return array Art => Bezeichnung.
	 */
	public static function ledger_types() {
		return array(
			'earned'            => __( 'Bestellung', 'lindenweg16' ),
			'redeemed'          => __( 'Eingelöst', 'lindenweg16' ),
			'reversed'          => __( 'Storniert', 'lindenweg16' ),
			'manual_adjustment' => __( 'Manuelle Anpassung', 'lindenweg16' ),
			'signup'            => __( 'Willkommen im Kundenkonto', 'lindenweg16' ),
			'newsletter'        => __( 'Newsletter-Anmeldung', 'lindenweg16' ),
			'first_order'       => __( 'Erste Bestellung', 'lindenweg16' ),
			'birthday'          => __( 'Geburtstag', 'lindenweg16' ),
			'review_bonus'      => __( 'Produktbewertung', 'lindenweg16' ),
			'review_photo'      => __( 'Foto in der Bewertung', 'lindenweg16' ),
			'referral_bonus'    => __( 'Freund geworben', 'lindenweg16' ),
			'referral_welcome'  => __( 'Willkommen über Empfehlung', 'lindenweg16' ),
			'profile'           => __( 'Profil vervollständigt', 'lindenweg16' ),
			'anniversary'       => __( 'Jahrestag', 'lindenweg16' ),
			'expired'           => __( 'Verfallen', 'lindenweg16' ),
		);
	}

	/**
	 * Ledger-Historie eines Nutzers (paginiert).
	 *
	 * @param int $user_id  Nutzer.
	 * @param int $per_page Pro Seite.
	 * @param int $page     Seite.
	 * @return array
	 */
	public static function ledger_of( $user_id, $per_page = 25, $page = 1 ) {
		global $wpdb;
		$t      = self::table( 'points_ledger' );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $user_id, $per_page, $offset ) ); // phpcs:ignore
	}

	/**
	 * Anzahl der Ledger-Einträge eines Nutzers.
	 *
	 * @param int $user_id Nutzer.
	 * @return int
	 */
	public static function ledger_count( $user_id ) {
		global $wpdb;
		$t = self::table( 'points_ledger' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d", $user_id ) ); // phpcs:ignore
	}

	/**
	 * Fällige, noch nicht verfügbare Punkte-Einträge.
	 *
	 * @return array
	 */
	public static function due_points() {
		global $wpdb;
		$t = self::table( 'points_ledger' );
		// available_at wird in GMT geschrieben (LoyaltyModule::on_order_completed,
		// gmdate) - der Vergleich muss deshalb ebenfalls in GMT laufen. Mit
		// der lokalen Zeit (current_time) reiften Punkte in Deutschland ein
		// bis zwei Stunden zu frueh.
		$now = gmdate( 'Y-m-d H:i:s' );
		// Alle wartenden Gutschriften, nicht nur Bestellpunkte: Erstbestellungs-
		// und Willkommensbonus warten ebenfalls die Frist ab.
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'pending' AND points > 0 AND available_at IS NOT NULL AND available_at <= %s", $now ) ); // phpcs:ignore
	}

	/**
	 * Noch nicht verfügbare Punkte eines Nutzers (Wartezeit nach Bestellung).
	 *
	 * @param int $user_id Nutzer.
	 * @return array{points:int,next_at:?string} Summe und frühester Freigabezeitpunkt (GMT).
	 */
	public static function pending_points( $user_id ) {
		global $wpdb;
		$t   = self::table( 'points_ledger' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(points), 0) AS points, MIN(available_at) AS next_at FROM {$t} WHERE user_id = %d AND type = 'earned' AND status = 'pending'", $user_id ) ); // phpcs:ignore
		return array(
			'points'  => $row ? (int) $row->points : 0,
			'next_at' => $row && $row->next_at ? (string) $row->next_at : null,
		);
	}

	/**
	 * Punkte-Ledger-Status setzen.
	 *
	 * @param int    $id     Eintrag-ID.
	 * @param string $status Neuer Status.
	 */
	public static function set_ledger_status( $id, $status ) {
		global $wpdb;
		$wpdb->update( self::table( 'points_ledger' ), array( 'status' => $status ), array( 'id' => (int) $id ) );
	}

	/* --------------------------------------------------------------------
	 * Salden (User-Meta)
	 * ------------------------------------------------------------------ */

	/**
	 * Guthaben-Saldo holen.
	 *
	 * @param int $user_id Nutzer.
	 * @return float
	 */
	public static function get_credit( $user_id ) {
		return (float) get_user_meta( $user_id, self::META_CREDIT, true );
	}

	/**
	 * Guthaben-Saldo verändern.
	 *
	 * @param int   $user_id Nutzer.
	 * @param float $delta   Änderung (positiv/negativ).
	 */
	public static function adjust_credit( $user_id, $delta ) {
		$new = max( 0, self::get_credit( $user_id ) + (float) $delta );
		update_user_meta( $user_id, self::META_CREDIT, round( $new, 2 ) );
	}

	/**
	 * Punkte-Saldo holen.
	 *
	 * @param int $user_id Nutzer.
	 * @return int
	 */
	public static function get_points( $user_id ) {
		return (int) get_user_meta( $user_id, self::META_POINTS, true );
	}

	/**
	 * Punkte-Saldo verändern.
	 *
	 * @param int $user_id Nutzer.
	 * @param int $delta   Änderung.
	 */
	public static function adjust_points( $user_id, $delta ) {
		$new = max( 0, self::get_points( $user_id ) + (int) $delta );
		update_user_meta( $user_id, self::META_POINTS, $new );
	}
}
