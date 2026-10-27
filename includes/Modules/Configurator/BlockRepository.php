<?php
/**
 * Sperrbereiche eines Basismoduls.
 *
 * Dort, wo der Haken an die Wand geschraubt wird, darf kein Modul sitzen –
 * es käme nie wieder an die Schraube. Diese Bereiche gehören zum
 * Basismodul und werden deshalb an dessen Teil geführt, nicht am Artikel:
 * Eine andere Schiene hat ihre Löcher woanders.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\BetriebAudit;

/**
 * Lesen und Schreiben der Sperrbereiche.
 */
class BlockRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_config_blocks';
	}

	/**
	 * Alle Sperrbereiche, nach Teil gruppiert.
	 *
	 * @return array part_id => Liste.
	 */
	public static function all_by_part() {
		global $wpdb;
		$table = self::table();

		$rows   = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY from_mm ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$blocks = array();

		foreach ( (array) $rows as $row ) {
			$blocks[ (int) $row['part_id'] ][] = array(
				'id'      => (int) $row['id'],
				'from_mm' => (float) $row['from_mm'],
				'to_mm'   => (float) $row['to_mm'],
				'label'   => (string) $row['label'],
			);
		}

		return $blocks;
	}

	/**
	 * Sperrbereiche eines Teils.
	 *
	 * @param int $part_id Teil.
	 * @return array
	 */
	public static function for_part( $part_id ) {
		$all = self::all_by_part();

		return isset( $all[ (int) $part_id ] ) ? $all[ (int) $part_id ] : array();
	}

	/**
	 * Sperrbereich anlegen.
	 *
	 * @param int    $part_id Teil.
	 * @param array  $input   Eingaben mit from_mm, to_mm, label.
	 * @param float  $rail    Länge des Basismoduls, zur Prüfung.
	 * @return array {id, errors}
	 */
	public static function create( $part_id, array $input, $rail ) {
		global $wpdb;

		$from  = self::measure( $input, 'from_mm' );
		$to    = self::measure( $input, 'to_mm' );
		$label = isset( $input['label'] ) ? sanitize_text_field( $input['label'] ) : '';

		$errors = array();

		if ( $to <= $from ) {
			$errors[] = __( 'Der Sperrbereich braucht ein Ende oberhalb seines Anfangs.', 'lindenweg16' );
		}

		if ( $rail > 0 && $to > $rail ) {
			$errors[] = sprintf(
				/* translators: %s: Länge des Basismoduls in Millimetern. */
				__( 'Der Sperrbereich liegt außerhalb des Basismoduls – es ist %s mm lang.', 'lindenweg16' ),
				Layout::format_mm( $rail )
			);
		}

		if ( $errors ) {
			return array(
				'id'     => 0,
				'errors' => $errors,
			);
		}

		$data = array(
			'part_id'    => (int) $part_id,
			'from_mm'    => $from,
			'to_mm'      => $to,
			'label'      => '' === $label ? null : $label,
			'created_at' => current_time( 'mysql', true ),
			'created_by' => get_current_user_id(),
		);

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return array(
				'id'     => 0,
				'errors' => array( __( 'Der Sperrbereich ließ sich nicht speichern.', 'lindenweg16' ) ),
			);
		}

		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'config_blocks', $id, null, $data );

		return array(
			'id'     => $id,
			'errors' => array(),
		);
	}

	/**
	 * Sperrbereich ändern.
	 *
	 * @param int   $id    ID.
	 * @param array $input Eingaben mit from_mm, to_mm, label.
	 * @param float $rail  Länge des Basismoduls, zur Prüfung.
	 * @return array {errors}
	 */
	public static function update( $id, array $input, $rail ) {
		global $wpdb;

		$before = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		if ( ! $before ) {
			return array( 'errors' => array( __( 'Diesen Sperrbereich gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ) ) );
		}

		$from  = self::measure( $input, 'from_mm' );
		$to    = self::measure( $input, 'to_mm' );
		$label = isset( $input['label'] ) ? sanitize_text_field( $input['label'] ) : '';

		if ( $to <= $from ) {
			return array( 'errors' => array( __( 'Der Sperrbereich braucht ein Ende oberhalb seines Anfangs.', 'lindenweg16' ) ) );
		}

		if ( $rail > 0 && $to > $rail ) {
			return array(
				'errors' => array(
					sprintf(
						/* translators: %s: Länge des Basismoduls in Millimetern. */
						__( 'Der Sperrbereich liegt außerhalb des Basismoduls – es ist %s mm lang.', 'lindenweg16' ),
						Layout::format_mm( $rail )
					),
				),
			);
		}

		$data = array(
			'from_mm' => $from,
			'to_mm'   => $to,
			'label'   => '' === $label ? null : $label,
		);

		if ( false === $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ) ) {
			return array( 'errors' => array( __( 'Der Sperrbereich ließ sich nicht speichern.', 'lindenweg16' ) ) );
		}

		BetriebAudit::record( 'update', 'config_blocks', (int) $id, $before, $data );

		return array( 'errors' => array() );
	}

	/**
	 * Sperrbereich löschen.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$before = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		if ( ! $before ) {
			return false;
		}

		if ( false === $wpdb->delete( self::table(), array( 'id' => (int) $id ) ) ) {
			return false;
		}

		BetriebAudit::record( 'delete', 'config_blocks', (int) $id, $before, null );

		return true;
	}

	/**
	 * Alle Sperrbereiche eines Teils löschen.
	 *
	 * @param int $part_id Teil.
	 */
	public static function delete_for_part( $part_id ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'part_id' => (int) $part_id ) );
	}

	/**
	 * Maßangabe aus dem Formular.
	 *
	 * @param array  $input Eingaben.
	 * @param string $key   Feld.
	 * @return float
	 */
	protected static function measure( array $input, $key ) {
		if ( ! isset( $input[ $key ] ) ) {
			return 0.0;
		}

		$value = str_replace( ',', '.', (string) $input[ $key ] );

		return max( 0.0, min( 99999.99, round( (float) $value, 2 ) ) );
	}
}
