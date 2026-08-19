<?php
/**
 * Zugversuche.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Zugversuche.
 */
class TensileRepository {

	use SanitizesInput;

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_tensile_tests';
	}

	/**
	 * Versuche einer Revision.
	 *
	 * @param int $revision_id Revision.
	 * @return array
	 */
	public static function for_revision( $revision_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE article_revision_id = %d ORDER BY sample_no ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $revision_id
			),
			ARRAY_A
		);
	}

	/**
	 * Alle Versuche, neueste zuerst.
	 *
	 * @param int $limit Anzahl.
	 * @return array
	 */
	public static function all( $limit = 200 ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY test_date DESC, id DESC LIMIT %d", (int) $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Eingaben prüfen.
	 *
	 * @param array $input Eingaben.
	 * @return array {data, errors}
	 */
	public static function validate( array $input ) {
		$errors      = array();
		$revision_id = self::int_or( $input, 'article_revision_id', 0 );
		$revision    = $revision_id ? RevisionRepository::find( $revision_id ) : null;

		if ( ! $revision ) {
			$errors[] = __( 'Bitte eine Revision wählen – ein Zugversuch ohne Profilversion ist wertlos.', 'lindenweg16' );
		}

		$load = self::decimal_or( $input, 'break_load_n', 0 );
		if ( $load <= 0 ) {
			$errors[] = __( 'Die Bruchlast muss größer als 0 N sein.', 'lindenweg16' );
		}

		return array(
			'data'   => array(
				'test_date'           => self::date_or_today( $input, 'test_date' ),
				'article_id'          => $revision ? (int) $revision['article_id'] : 0,
				'article_revision_id' => $revision_id,
				'filament_lot_id'     => self::int_or( $input, 'filament_lot_id', 0 ) ? self::int_or( $input, 'filament_lot_id', 0 ) : null,
				'sample_no'           => self::int_or( $input, 'sample_no', 1 ),
				'break_load_n'        => $load,
				'failure_mode'        => self::text( $input, 'failure_mode' ),
				'tester'              => self::text( $input, 'tester' ),
				'note'                => isset( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) : null,
			),
			'errors' => $errors,
		);
	}

	/**
	 * Versuch erfassen.
	 *
	 * @param array $data Geprüfte Daten.
	 * @return int
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'tensile_tests', $id, null, $data );

		return $id;
	}

	/**
	 * Versuch stornieren – durch Kennzeichnung, nie durch Löschen.
	 *
	 * @param int    $id     Versuch.
	 * @param string $reason Begründung.
	 * @return array {success, message}
	 */
	public static function void( $id, $reason ) {
		global $wpdb;

		$reason = trim( (string) $reason );
		if ( '' === $reason ) {
			return array(
				'success' => false,
				'message' => __( 'Ein Storno braucht eine Begründung.', 'lindenweg16' ),
			);
		}

		$table  = self::table();
		$before = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! $before ) {
			return array(
				'success' => false,
				'message' => __( 'Versuch nicht gefunden.', 'lindenweg16' ),
			);
		}

		$data = array(
			'voided_at'   => current_time( 'mysql', true ),
			'void_reason' => sanitize_text_field( $reason ),
		);

		$wpdb->update( $table, $data, array( 'id' => (int) $id ) );
		BetriebAudit::record( 'void', 'tensile_tests', (int) $id, $before, $data, $reason );

		return array(
			'success' => true,
			'message' => __( 'Versuch storniert. Er zählt nicht mehr zur Freigabe.', 'lindenweg16' ),
		);
	}
}
