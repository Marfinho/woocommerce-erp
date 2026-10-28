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
	 * Nach so vielen Tagen verschwindet eine nie freigegebene, nie geslicte
	 * Vorschau-Platte samt Datei. Jede Vorschau im Workflow legt sofort
	 * Plattendateien an; wer sie verwirft, ließ sie bisher für immer liegen.
	 */
	const DRAFT_RETENTION_DAYS = 14;

	/**
	 * Präfix der Plattendateien im Dokumentenordner (siehe PlateService).
	 */
	const FILE_PREFIX = 'Platte-';

	/* -----------------------------------------------------------------
	 * Reine Logik – ohne Datenbank, deshalb ohne WordPress testbar.
	 * ------------------------------------------------------------- */

	/**
	 * Darf eine Platte in diesem Status (erneut) zum automatischen
	 * Slicen eingereiht werden?
	 *
	 * Neben "entworfen" ausdrücklich auch "fehlgeschlagen": Ein
	 * gescheiterter Slice-Lauf (falsches Profil, Worker-Fehler, Netz)
	 * soll sich nach Behebung der Ursache mit einem Klick wiederholen
	 * lassen, statt dass die Platte neu angeordnet werden muss. Eine
	 * Platte, die gerade geslict wird oder schon in der Bambu-
	 * Warteschlange liegt, darf nicht doppelt eingereiht werden.
	 *
	 * @param string $status Aktueller Status.
	 * @return bool
	 */
	public static function can_enqueue_for_slicing( $status ) {
		return in_array( (string) $status, array( 'entworfen', 'fehlgeschlagen' ), true );
	}

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

	/**
	 * Verworfene Vorschau-Platten aufräumen: Status „entworfen“, älter als
	 * DRAFT_RETENTION_DAYS und von keiner Werkstatt-Platte (runs) benutzt.
	 * Eine solche Platte wurde nie geslict und nie freigegeben – an ihr
	 * hängt nichts. Gelöscht wird die Datei nur im Dokumentenordner und nur
	 * mit dem Plattenpräfix.
	 *
	 * @param string $runs_table Tabelle der Werkstatt-Platten.
	 * @param string $docs_dir   Dokumentenordner.
	 * @return int Anzahl gelöschter Platten.
	 */
	public static function cleanup_drafts( $runs_table, $docs_dir ) {
		global $wpdb;

		$grenze = gmdate( 'Y-m-d H:i:s', time() - self::DRAFT_RETENTION_DAYS * DAY_IN_SECONDS );

		$alte = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.id, p.file_path FROM ' . self::table() . ' p LEFT JOIN ' . $runs_table . " r ON r.plate_job_id = p.id WHERE p.status = 'entworfen' AND p.created_at < %s AND r.id IS NULL LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$grenze
			)
		);

		$geloescht = 0;

		foreach ( (array) $alte as $plate ) {
			if ( self::is_own_file( (string) $plate->file_path, $docs_dir ) ) {
				wp_delete_file( realpath( (string) $plate->file_path ) );
			}
			$geloescht += (int) $wpdb->delete( self::table(), array( 'id' => (int) $plate->id ) );
		}

		return $geloescht;
	}

	/**
	 * Liegt die Datei im Dokumentenordner und trägt das Plattenpräfix? Reine
	 * Pfadentscheidung, damit prüfbar – sie entscheidet über einen Löschvorgang.
	 *
	 * @param string $file_path Pfad.
	 * @param string $docs_dir  Dokumentenordner.
	 * @return bool
	 */
	public static function is_own_file( $file_path, $docs_dir ) {
		$real = '' !== (string) $file_path ? realpath( (string) $file_path ) : false;
		$docs = realpath( (string) $docs_dir );

		if ( ! $real || ! $docs || ! is_file( $real ) ) {
			return false;
		}

		return 0 === strpos( $real, rtrim( $docs, '/\\' ) . DIRECTORY_SEPARATOR ) && 0 === strpos( basename( $real ), self::FILE_PREFIX );
	}
}
