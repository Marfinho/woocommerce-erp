<?php
/**
 * Platten (Arbeitseinheiten der Werkstatt) in der Datenbank.
 *
 * Nur Ablage und Abfrage; die Regeln stehen in RunFlow, die Buchungen in
 * Workflow. Eine Platte wird nie gelöscht: Abgebrochenes bleibt als
 * „abgebrochen“ stehen, damit sich nachvollziehen lässt, was geplant war.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Zugriff auf lw16_runs.
 */
class RunRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'lw16_runs';
	}

	/**
	 * Eine Zeile für die Verwendung aufbereiten (Positionen entpackt).
	 *
	 * @param object|null $row Datensatz.
	 * @return array|null
	 */
	protected static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}

		$run          = (array) $row;
		$items        = json_decode( (string) $run['items'], true );
		$run['items'] = is_array( $items ) ? $items : array();
		$run['id']    = (int) $run['id'];

		return $run;
	}

	/**
	 * Neue Platte anlegen.
	 *
	 * @param array $data batch, printer_id, bambu_printer, plate_job_id, items, est_minutes.
	 * @return int Neue ID oder 0.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$ok = $wpdb->insert(
			self::table(),
			array(
				'batch'         => isset( $data['batch'] ) ? (string) $data['batch'] : '',
				'status'        => RunFlow::FREIGEGEBEN,
				'printer_id'    => ! empty( $data['printer_id'] ) ? (int) $data['printer_id'] : null,
				'bambu_printer' => ! empty( $data['bambu_printer'] ) ? sanitize_key( (string) $data['bambu_printer'] ) : null,
				'plate_job_id'  => ! empty( $data['plate_job_id'] ) ? (int) $data['plate_job_id'] : null,
				'items'         => wp_json_encode( array_values( (array) $data['items'] ) ),
				'est_minutes'   => isset( $data['est_minutes'] ) ? max( 0, (int) $data['est_minutes'] ) : 0,
				'released_at'   => current_time( 'mysql', true ),
				'created_by'    => get_current_user_id(),
			)
		);

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Eine Platte.
	 *
	 * @param int $id Platte.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;

		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Die Platte zu einer Plattendatei.
	 *
	 * @param int $plate_job_id Plattendatei.
	 * @return array|null
	 */
	public static function find_by_plate( $plate_job_id ) {
		global $wpdb;

		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE plate_job_id = %d ORDER BY id DESC LIMIT 1', (int) $plate_job_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Platten, die noch nicht abgeschlossen sind.
	 *
	 * @return array
	 */
	public static function active() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE status NOT IN (%s, %s) ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				RunFlow::ERLEDIGT,
				RunFlow::ABGEBROCHEN
			)
		);

		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Platten eines Druckers, neueste zuerst.
	 *
	 * @param string $bambu_id Bambu-Drucker.
	 * @param int    $limit    Wie viele.
	 * @return array
	 */
	public static function for_printer( $bambu_id, $limit = 20 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE bambu_printer = %s ORDER BY id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $bambu_id,
				(int) $limit
			)
		);

		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Heute erledigte Platten.
	 *
	 * @return array
	 */
	public static function done_today() {
		global $wpdb;

		$since = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE status = %s AND reported_at >= %s ORDER BY reported_at DESC LIMIT 50', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				RunFlow::ERLEDIGT,
				$since
			)
		);

		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Felder ändern.
	 *
	 * @param int   $id   Platte.
	 * @param array $data Spalte => Wert; items als Array.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			$data['items'] = wp_json_encode( array_values( $data['items'] ) );
		}

		$data['updated_at'] = current_time( 'mysql', true );

		return false !== $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Nächste Bezeichnung eines Plans, z. B. „2026-43-1“ (Jahr-Woche-Zähler).
	 *
	 * @return string
	 */
	public static function next_batch() {
		global $wpdb;

		$prefix = gmdate( 'o-W' ) . '-';
		$count  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT batch) FROM ' . self::table() . ' WHERE batch LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $prefix . ( $count + 1 );
	}

	/**
	 * Mengen, die in noch nicht abgeschlossenen Platten stecken.
	 *
	 * Ohne diese Verrechnung entstünde im Bedarf sofort wieder derselbe
	 * Vorschlag, obwohl die Platte schon im Drucker liegt.
	 *
	 * @return array article_id => Menge.
	 */
	public static function in_work() {
		$out = array();

		foreach ( self::active() as $run ) {
			foreach ( $run['items'] as $item ) {
				$id         = (int) $item['article_id'];
				$out[ $id ] = ( isset( $out[ $id ] ) ? $out[ $id ] : 0.0 ) + (float) $item['qty'];
			}
		}

		return $out;
	}
}
