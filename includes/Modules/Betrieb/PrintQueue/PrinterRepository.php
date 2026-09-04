<?php
/**
 * Drucker (Raspberry-Pi-Clients) der Druck-Warteschlange.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\PrintQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Verwaltet die registrierten Drucker samt Bearer-Token.
 *
 * Das Token wird nie im Klartext gespeichert – nur sein SHA-256-Hash. Der
 * Klartext ist ausschließlich das Ergebnis von create() bzw.
 * regenerate_token() und wird sonst nirgends abgelegt oder geloggt.
 */
class PrinterRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_print_queue_printers';
	}

	/**
	 * Alle Drucker, neueste zuerst nicht relevant – nach Namen sortiert.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY name ASC' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Einzelnen Drucker per Datensatz-ID.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Drucker per stabiler printer_id (z. B. eg_dhl).
	 *
	 * @param string $printer_id Schlüssel.
	 * @return object|null
	 */
	public static function find_by_printer_id( $printer_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE printer_id = %s', (string) $printer_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Neues, zufälliges Bearer-Token erzeugen – lang genug, um nicht zu
	 * erraten zu sein, und in Hex, damit es in einem HTTP-Header ohne
	 * Zusatzkodierung transportiert werden kann.
	 *
	 * @return string 64 Zeichen Hex (256 Bit Entropie).
	 */
	public static function generate_token() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Token hashen – reine Logik, ohne Datenbank.
	 *
	 * @param string $token Klartext-Token.
	 * @return string SHA-256-Hex.
	 */
	public static function hash_token( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Zeitsicherer Vergleich: Stimmt das vorgelegte Token mit dem
	 * gespeicherten Hash überein?
	 *
	 * hash_equals() vermeidet, dass die Antwortzeit verrät, an welcher
	 * Stelle der Vergleich zuerst scheitert – sonst ließe sich ein Token
	 * Byte für Byte erraten.
	 *
	 * @param string $token      Vorgelegtes Klartext-Token.
	 * @param string $token_hash Gespeicherter Hash.
	 * @return bool
	 */
	public static function token_matches( $token, $token_hash ) {
		if ( '' === (string) $token || '' === (string) $token_hash ) {
			return false;
		}
		return hash_equals( (string) $token_hash, self::hash_token( $token ) );
	}

	/**
	 * Drucker anhand des vorgelegten Bearer-Tokens auflösen.
	 *
	 * Es gibt nur wenige Drucker, ein voller Scan ist unbedenklich – und
	 * nur so bleibt jeder einzelne Vergleich zeitsicher, statt sich auf
	 * einen Datenbank-Index (mit möglichen Timing-Unterschieden) zu
	 * verlassen.
	 *
	 * @param string $token Vorgelegtes Klartext-Token.
	 * @return object|null Nur aktive Drucker.
	 */
	public static function find_by_token( $token ) {
		if ( '' === trim( (string) $token ) ) {
			return null;
		}

		foreach ( self::all() as $printer ) {
			if ( ! (int) $printer->active ) {
				continue;
			}
			if ( self::token_matches( $token, $printer->token_hash ) ) {
				return $printer;
			}
		}

		return null;
	}

	/**
	 * Drucker anlegen.
	 *
	 * @param string $printer_id Stabiler Schlüssel (z. B. eg_dhl).
	 * @param string $name       Anzeigename.
	 * @return array {id, token} Das Token wird nur hier im Klartext zurückgegeben.
	 */
	public static function create( $printer_id, $name ) {
		global $wpdb;

		$token = self::generate_token();

		$wpdb->insert(
			self::table(),
			array(
				'printer_id'       => sanitize_key( $printer_id ),
				'name'             => sanitize_text_field( $name ),
				'token_hash'       => self::hash_token( $token ),
				'token_created_at' => current_time( 'mysql', true ),
				'active'           => 1,
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return array( 'id' => (int) $wpdb->insert_id, 'token' => $token );
	}

	/**
	 * Neues Token für einen bestehenden Drucker erzeugen. Das alte Token
	 * wird dadurch sofort ungültig.
	 *
	 * @param int $id Datensatz-ID.
	 * @return string Neues Klartext-Token, oder leer bei unbekanntem Drucker.
	 */
	public static function regenerate_token( $id ) {
		global $wpdb;

		if ( ! self::find( $id ) ) {
			return '';
		}

		$token = self::generate_token();

		$wpdb->update(
			self::table(),
			array(
				'token_hash'       => self::hash_token( $token ),
				'token_created_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);

		return $token;
	}

	/**
	 * Heartbeat: „zuletzt online" aktualisieren. Wird bei jeder
	 * erfolgreichen API-Kommunikation aufgerufen, nicht nur während ein
	 * Long-Poll offen ist – sonst wirkt ein Pi zwischen zwei Anfragen
	 * fälschlich offline.
	 *
	 * @param int    $id Datensatz-ID.
	 * @param string $ip Anfragende IP (nur zur Diagnose, nicht sicherheitskritisch).
	 */
	public static function touch( $id, $ip = '' ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'last_seen_at' => current_time( 'mysql', true ),
				'last_ip'      => substr( (string) $ip, 0, 45 ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Vom Client gemeldetes Druckermodell speichern (informativ, für die
	 * Admin-Übersicht).
	 *
	 * @param int    $id    Datensatz-ID.
	 * @param string $model Freitext, z. B. „Zebra GK420d".
	 */
	public static function set_model( $id, $model ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array( 'printer_model' => sanitize_text_field( (string) $model ) ),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Gilt ein Drucker als online? Ein Heartbeat aus den letzten
	 * $seconds Sekunden reicht – lang genug, um eine laufende Long-Poll-
	 * Wartezeit plus etwas Netzlaufzeit zu überstehen.
	 *
	 * @param object|null $printer Datensatz.
	 * @param int         $seconds Schwelle in Sekunden.
	 * @return bool
	 */
	public static function is_online( $printer, $seconds = 90 ) {
		if ( ! $printer || ! $printer->last_seen_at ) {
			return false;
		}

		$last = strtotime( $printer->last_seen_at . ' UTC' );

		if ( ! $last ) {
			return false;
		}

		return ( time() - $last ) <= (int) $seconds;
	}
}
