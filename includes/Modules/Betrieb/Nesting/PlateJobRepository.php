<?php
/**
 * Datenzugriff für angeordnete, aber noch ungeslicte Druckplatten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

/**
 * Eine Zeile je zusammengesetzter Projekt-3MF (siehe PlateComposer), mit
 * dem Anordnungsergebnis für die Vorschau und dem weiteren Lebenslauf bis
 * zum fertig geslicten Bambu-Job.
 */
class PlateJobRepository {

	/**
	 * Gültige Status.
	 */
	const STATUSES = array( 'entworfen', 'wird_geslict', 'geslict', 'fehlgeschlagen' );

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_bambu_plate_jobs';
	}

	/**
	 * Platte anlegen (status = entworfen).
	 *
	 * @param array $data {printer_id, file_path, file_name, plate_width_mm,
	 *                      plate_height_mm, placements (Array, wird als
	 *                      JSON gespeichert), note}
	 * @return int Neue ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'printer_id'      => sanitize_key( (string) $data['printer_id'] ),
				'status'          => 'entworfen',
				'file_path'       => (string) $data['file_path'],
				'file_name'       => (string) $data['file_name'],
				'plate_width_mm'  => (int) $data['plate_width_mm'],
				'plate_height_mm' => (int) $data['plate_height_mm'],
				'placements'      => wp_json_encode( isset( $data['placements'] ) ? $data['placements'] : array() ),
				'created_at'      => current_time( 'mysql', true ),
				'created_by'      => get_current_user_id(),
				'note'            => isset( $data['note'] ) ? sanitize_textarea_field( (string) $data['note'] ) : null,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Einzelne Platte.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Liste für die Admin-Übersicht.
	 *
	 * @param string $status Filter (leer = alle).
	 * @param int    $limit  Höchstzahl.
	 * @return array
	 */
	public static function list_for_admin( $status = '', $limit = 100 ) {
		global $wpdb;

		if ( $status && in_array( $status, self::STATUSES, true ) ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table() . ' WHERE status = %s ORDER BY id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$status,
					(int) $limit
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', (int) $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Status ändern.
	 *
	 * @param int    $id     Platte.
	 * @param string $status Neuer Status.
	 * @param array  $extra  Weitere Felder (z. B. bambu_job_id).
	 * @return bool
	 */
	public static function set_status( $id, $status, array $extra = array() ) {
		global $wpdb;

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		$data = array_merge( array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ), $extra );

		return false !== $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}
}
