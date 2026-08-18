<?php
/**
 * Datenzugriff für Loyalty (Referrals + Punkte-Ledger).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Loyalty;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Time;

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
		$data['created_at'] = Time::now();
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
			$data['completed_at'] = Time::now();
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
		$grenze = Time::ago( $wartezeit_tage * DAY_IN_SECONDS );
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
		$data['created_at'] = Time::now();
		$wpdb->insert( self::table( 'points_ledger' ), $data );
		return (int) $wpdb->insert_id;
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
		$now = Time::now();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE type = 'earned' AND status = 'pending' AND available_at <= %s", $now ) ); // phpcs:ignore
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
