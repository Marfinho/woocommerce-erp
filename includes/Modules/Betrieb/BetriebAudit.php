<?php
/**
 * Protokollierung der Betrieb-Datensätze im bestehenden GoBD-Audit-Log.
 *
 * Das Plugin hat bereits ein hashverkettetes Audit-Log (wp_lw16_audit_log)
 * inklusive Prüffunktion. Statt eine zweite Journaltabelle danebenzustellen,
 * schreibt das Modul Betrieb in dieselbe Kette und ergänzt sie um Vorher-/
 * Nachher-Abbild und Begründung.
 *
 * Wichtig: Die Nutzdaten stehen zusätzlich im Feld `details`, weil nur dieses
 * in HashChain::HASH_FIELDS['audit_log'] eingeht und damit gegen nachträgliche
 * Änderung gesichert ist. Die eigenen Spalten sind die abfragbare Kopie. Die
 * Hash-Felder selbst bleiben unverändert – sonst würde die Verifikation aller
 * Altdatensätze brechen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\HashChain;

/**
 * Schreibt und liest Audit-Einträge des Moduls Betrieb.
 */
class BetriebAudit {

	/**
	 * Erlaubte Aktionen.
	 */
	const ACTIONS = array( 'create', 'update', 'void' );

	/**
	 * Tabellenname des Audit-Logs.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_audit_log';
	}

	/**
	 * Einen Vorgang protokollieren.
	 *
	 * @param string     $action    create, update oder void.
	 * @param string     $table     Kurzname der betroffenen Tabelle (ohne Präfix).
	 * @param int        $record_id Datensatz-ID.
	 * @param array|null $before    Zustand vorher.
	 * @param array|null $after     Zustand nachher.
	 * @param string     $reason    Begründung (bei void Pflicht).
	 * @return bool
	 */
	public static function record( $action, $table, $record_id, $before = null, $after = null, $reason = '' ) {
		$action = in_array( $action, self::ACTIONS, true ) ? $action : 'update';
		$table  = sanitize_key( $table );
		$reason = $reason ? sanitize_text_field( $reason ) : '';

		$before_json = null === $before ? null : wp_json_encode( self::scrub( $before ) );
		$after_json  = null === $after ? null : wp_json_encode( self::scrub( $after ) );

		$data = array(
			'user_id'     => get_current_user_id(),
			'aktion'      => 'betrieb_' . $action,
			'objekt_typ'  => $table,
			'objekt_id'   => $record_id ? (int) $record_id : null,
			'details'     => wp_json_encode(
				array(
					'before' => null === $before ? null : self::scrub( $before ),
					'after'  => null === $after ? null : self::scrub( $after ),
					'reason' => $reason,
				)
			),
			'erstellt_am' => current_time( 'mysql', true ),
		);

		// Die abfragbare Kopie geht als Beiwerk mit – sie ist nicht Teil der
		// Hash-Berechnung und darf sie deshalb auch nicht berühren.
		return 0 !== HashChain::insert(
			'audit_log',
			$data,
			array(
				'payload_before_json' => $before_json,
				'payload_after_json'  => $after_json,
				'grund'               => $reason ? $reason : null,
			)
		);
	}

	/**
	 * Hash-Kette des Audit-Logs prüfen.
	 *
	 * @return array Fehlerliste, leer = unversehrt.
	 */
	public static function verify_chain() {
		return HashChain::verify( self::table() );
	}

	/**
	 * Einträge zu einem Datensatz, neueste zuerst.
	 *
	 * @param string $table     Kurzname der Tabelle.
	 * @param int    $record_id Datensatz-ID.
	 * @return array
	 */
	public static function for_record( $table, $record_id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE objekt_typ = %s AND objekt_id = %d ORDER BY id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				sanitize_key( $table ),
				(int) $record_id
			),
			ARRAY_A
		);
	}

	/**
	 * Interne Felder aus dem Abbild entfernen, damit das Protokoll lesbar
	 * bleibt und keine Hashes doppelt gespeichert werden.
	 *
	 * @param array|object $row Datensatz.
	 * @return array
	 */
	protected static function scrub( $row ) {
		$row = (array) $row;
		unset( $row['hash'], $row['vorheriger_hash'] );
		return $row;
	}
}
