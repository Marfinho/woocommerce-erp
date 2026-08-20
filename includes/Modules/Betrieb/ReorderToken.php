<?php
/**
 * Nachbestell-Link: Token-Verwaltung.
 *
 * Statt eines B2B-Portals bekommt jeder Händler einen persönlichen Link. Er
 * ersetzt kein Gespräch, er senkt nur die Hürde für die Routinebestellung.
 *
 * Sicherheitsgrundsätze:
 * - Das Token ist kryptografisch zufällig (32 Byte) und wird **nur als Hash**
 *   gespeichert. Ein Datenbankleck gibt damit keinen gültigen Link her.
 * - Ein gültiges Token erzeugt ausschließlich Bestellentwürfe für **seinen**
 *   Kontakt. Keine Kunden- oder Umsatzdaten, keine Konditionen anderer
 *   Händler, keine Bestandszahlen.
 * - Bei widerrufenem oder abgelaufenem Token erscheint eine neutrale Seite –
 *   keine Fehlermeldung, aus der sich ableiten ließe, ob das Token je gültig war.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Erzeugt, prüft und widerruft Nachbestell-Token.
 */
class ReorderToken {

	/**
	 * Standardlaufzeit in Tagen.
	 */
	const DEFAULT_LIFETIME_DAYS = 365;

	/**
	 * Erlaubte Aufrufe je Token und Stunde.
	 */
	const RATE_LIMIT_PER_HOUR = 60;

	/**
	 * Erlaubte Aufrufe je IP und Stunde.
	 */
	const RATE_LIMIT_PER_IP = 120;

	/**
	 * Pfad der öffentlichen Route.
	 */
	const ROUTE = 'nachbestellen';

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_reorder_tokens';
	}

	/**
	 * Token hashen – reine Logik.
	 *
	 * @param string $token Klartext-Token.
	 * @return string
	 */
	public static function hash( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Neues Token für einen Kontakt erzeugen.
	 *
	 * Gibt das Klartext-Token genau einmal zurück – danach existiert nur noch
	 * der Hash.
	 *
	 * @param int $contact_id     Kontakt.
	 * @param int $lifetime_days  Laufzeit.
	 * @return array {token, url, id}
	 */
	public static function issue( $contact_id, $lifetime_days = self::DEFAULT_LIFETIME_DAYS ) {
		global $wpdb;

		$contact_id = (int) $contact_id;
		if ( ! $contact_id || ! ContactRepository::find( $contact_id ) ) {
			return array(
				'token' => '',
				'url'   => '',
				'id'    => 0,
			);
		}

		// Bestehende Token desselben Kontakts widerrufen – ein Händler hat
		// genau einen gültigen Link.
		self::revoke_all( $contact_id, __( 'Durch neuen Link ersetzt.', 'lindenweg16' ) );

		$token = bin2hex( random_bytes( 32 ) );

		$data = array(
			'contact_id' => $contact_id,
			'token_hash' => self::hash( $token ),
			'created_at' => current_time( 'mysql', true ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + (int) $lifetime_days * DAY_IN_SECONDS ),
			'use_count'  => 0,
			'created_by' => get_current_user_id(),
		);

		$wpdb->insert( self::table(), $data );
		$id = (int) $wpdb->insert_id;

		// Im Protokoll steht der Hash, nie das Token.
		BetriebAudit::record( 'create', 'reorder_tokens', $id, null, $data );

		return array(
			'token' => $token,
			'url'   => self::url( $token ),
			'id'    => $id,
		);
	}

	/**
	 * Öffentliche URL eines Tokens.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function url( $token ) {
		if ( ! DeviceLabel::pretty_permalinks() ) {
			return add_query_arg(
				array(
					'lw16_reorder' => '1',
					'lw16_token'   => rawurlencode( $token ),
				),
				home_url( '/' )
			);
		}

		return home_url( '/' . self::ROUTE . '/' . rawurlencode( $token ) . '/' );
	}

	/**
	 * Token prüfen – reine Zustandslogik.
	 *
	 * @param array|null $row Datensatz oder null.
	 * @param int        $now Zeitstempel.
	 * @return array {valid, reason}
	 */
	public static function check( $row, $now = 0 ) {
		$now = $now ? (int) $now : time();

		if ( ! $row ) {
			return array(
				'valid'  => false,
				'reason' => 'unbekannt',
			);
		}

		if ( ! empty( $row['revoked_at'] ) ) {
			return array(
				'valid'  => false,
				'reason' => 'widerrufen',
			);
		}

		if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] . ' UTC' ) < $now ) {
			return array(
				'valid'  => false,
				'reason' => 'abgelaufen',
			);
		}

		return array(
			'valid'  => true,
			'reason' => '',
		);
	}

	/**
	 * Token auflösen und prüfen.
	 *
	 * @param string $token Klartext-Token.
	 * @return array {valid, reason, row, contact}
	 */
	public static function resolve( $token ) {
		global $wpdb;

		$token = (string) $token;

		// Formprüfung vor der Abfrage: 64 Hexzeichen, sonst gar nicht erst
		// nachschlagen.
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return array(
				'valid'   => false,
				'reason'  => 'unbekannt',
				'row'     => null,
				'contact' => null,
			);
		}

		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", self::hash( $token ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$check = self::check( $row );

		return array(
			'valid'   => $check['valid'],
			'reason'  => $check['reason'],
			'row'     => $row,
			'contact' => $check['valid'] ? ContactRepository::find( (int) $row['contact_id'] ) : null,
		);
	}

	/**
	 * Aufruf zählen.
	 *
	 * @param int $id Token.
	 */
	public static function touch( $id ) {
		global $wpdb;

		$table = self::table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET use_count = use_count + 1, last_used_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				(int) $id
			)
		);
	}

	/**
	 * Rate-Limit prüfen – je Token und je IP.
	 *
	 * @param int    $token_id Token.
	 * @param string $ip       IP-Adresse.
	 * @return bool True, wenn der Aufruf erlaubt ist.
	 */
	public static function within_rate_limit( $token_id, $ip ) {
		$token_key = 'lw16_rl_t_' . (int) $token_id;
		$ip_key    = 'lw16_rl_i_' . md5( (string) $ip );

		$token_hits = (int) get_transient( $token_key );
		$ip_hits    = (int) get_transient( $ip_key );

		if ( $token_hits >= self::RATE_LIMIT_PER_HOUR || $ip_hits >= self::RATE_LIMIT_PER_IP ) {
			return false;
		}

		set_transient( $token_key, $token_hits + 1, HOUR_IN_SECONDS );
		set_transient( $ip_key, $ip_hits + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Token eines Kontakts.
	 *
	 * @param int $contact_id Kontakt.
	 * @return array
	 */
	public static function for_contact( $contact_id ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE contact_id = %d ORDER BY id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $contact_id
			),
			ARRAY_A
		);
	}

	/**
	 * Ein Token widerrufen.
	 *
	 * @param int    $id     Token.
	 * @param string $reason Begründung.
	 * @return bool
	 */
	public static function revoke( $id, $reason = '' ) {
		global $wpdb;

		$table  = self::table();
		$before = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! $before || $before['revoked_at'] ) {
			return false;
		}

		$data = array( 'revoked_at' => current_time( 'mysql', true ) );

		$wpdb->update( $table, $data, array( 'id' => (int) $id ) );
		BetriebAudit::record( 'update', 'reorder_tokens', (int) $id, $before, $data, $reason );

		return true;
	}

	/**
	 * Alle Token eines Kontakts widerrufen.
	 *
	 * @param int    $contact_id Kontakt.
	 * @param string $reason     Begründung.
	 * @return int Anzahl.
	 */
	public static function revoke_all( $contact_id, $reason = '' ) {
		$count = 0;

		foreach ( self::for_contact( $contact_id ) as $row ) {
			if ( $row['revoked_at'] ) {
				continue;
			}
			if ( self::revoke( (int) $row['id'], $reason ) ) {
				$count++;
			}
		}

		return $count;
	}
}
