<?php
/**
 * Audit-Log für kritische Aktionen (GoBD-Nachvollziehbarkeit).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Schreibt und liest Einträge im Audit-Log. Wer, wann, was.
 */
class AuditLog {

	/**
	 * Tabellenname mit Präfix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_audit_log';
	}

	/**
	 * Einen Audit-Eintrag anlegen.
	 *
	 * @param string $aktion     Kurzbezeichnung der Aktion.
	 * @param string $objekt_typ Objekttyp (z. B. beleg, referral, guthaben).
	 * @param int    $objekt_id  Objekt-ID.
	 * @param array  $details    Zusätzliche Details.
	 */
	public static function record( $aktion, $objekt_typ = '', $objekt_id = 0, array $details = array() ) {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'user_id'    => get_current_user_id(),
				'aktion'     => $aktion,
				'objekt_typ' => $objekt_typ,
				'objekt_id'  => $objekt_id ? (int) $objekt_id : null,
				'details'    => $details ? wp_json_encode( $details ) : null,
				'erstellt_am' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Paginierte Liste der Audit-Einträge.
	 *
	 * @param int $per_page Einträge pro Seite.
	 * @param int $page     Seitenzahl (1-basiert).
	 * @return array
	 */
	public static function get_page( $per_page = 50, $page = 1 ) {
		global $wpdb;
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$table  = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore
				$per_page,
				$offset
			)
		);
	}

	/**
	 * Gesamtzahl der Einträge (für Pagination).
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
	}
}
