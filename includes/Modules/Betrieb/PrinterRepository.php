<?php
/**
 * Geräteverwaltung der Druckfarm.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Drucker samt Protokollierung.
 */
class PrinterRepository {

	use SanitizesInput;

	/**
	 * Erlaubte Gerätestatus.
	 */
	const STATUS = array( 'betrieb', 'wartung', 'defekt', 'reserve' );

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_printers';
	}

	/**
	 * Statusbezeichnungen für die Oberfläche.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'betrieb' => __( 'in Betrieb', 'lindenweg16' ),
			'wartung' => __( 'in Wartung', 'lindenweg16' ),
			'defekt'  => __( 'defekt', 'lindenweg16' ),
			'reserve' => __( 'Reserve', 'lindenweg16' ),
		);
	}

	/**
	 * Alle Geräte, nach Kürzel sortiert.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY code ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Ein Gerät holen.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Gerät über sein Kürzel holen.
	 *
	 * @param string $code Kürzel.
	 * @return array|null
	 */
	public static function find_by_code( $code ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", (string) $code ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Eingaben prüfen und normalisieren.
	 *
	 * Leere Zahlenfelder werden NULL, nicht 0 – sonst wäre „nicht gepflegt"
	 * von „kostet nichts" nicht zu unterscheiden und der Stundensatz
	 * stillschweigend falsch.
	 *
	 * @param array    $input Rohe Eingaben.
	 * @param int|null $id    Bei Bearbeitung die eigene ID.
	 * @return array {data, errors}
	 */
	public static function validate( array $input, $id = null ) {
		$errors = array();

		$code = isset( $input['code'] ) ? strtoupper( sanitize_text_field( $input['code'] ) ) : '';
		if ( '' === $code ) {
			$errors[] = __( 'Ein Gerätekürzel ist Pflicht (z. B. P1).', 'lindenweg16' );
		} elseif ( ! preg_match( '/^[A-Z0-9\-]{1,20}$/', $code ) ) {
			$errors[] = __( 'Das Kürzel darf nur Buchstaben, Ziffern und Bindestriche enthalten.', 'lindenweg16' );
		} else {
			$existing = self::find_by_code( $code );
			if ( $existing && (int) $existing['id'] !== (int) $id ) {
				$errors[] = sprintf(
					/* translators: %s: Gerätekürzel. */
					__( 'Das Kürzel %s ist bereits vergeben.', 'lindenweg16' ),
					$code
				);
			}
		}

		$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'betrieb';
		if ( ! in_array( $status, self::STATUS, true ) ) {
			$status = 'betrieb';
		}

		$data = array(
			'code'                   => $code,
			'manufacturer'           => self::text( $input, 'manufacturer' ),
			'model'                  => self::text( $input, 'model' ),
			'serial'                 => self::text( $input, 'serial' ),
			'acquired_on'            => self::date( $input, 'acquired_on' ),
			'acquisition_cost'       => self::decimal( $input, 'acquisition_cost' ),
			'lifetime_h'             => self::decimal( $input, 'lifetime_h' ),
			'power_cost_per_h'       => self::decimal( $input, 'power_cost_per_h' ),
			'power_kw'               => self::decimal( $input, 'power_kw' ),
			'spare_parts_per_year'   => self::decimal( $input, 'spare_parts_per_year' ),
			'annual_operating_hours' => self::decimal( $input, 'annual_operating_hours' ),
			'nozzle_mm'              => self::decimal( $input, 'nozzle_mm' ),
			'status'                 => $status,
			'hours_at_last_nozzle_change' => self::decimal( $input, 'hours_at_last_nozzle_change' ),
			'notes'                  => isset( $input['notes'] ) ? sanitize_textarea_field( $input['notes'] ) : null,
		);

		foreach ( array( 'acquisition_cost', 'lifetime_h', 'power_cost_per_h', 'power_kw', 'spare_parts_per_year', 'annual_operating_hours', 'nozzle_mm' ) as $field ) {
			if ( null !== $data[ $field ] && $data[ $field ] < 0 ) {
				$errors[] = __( 'Negative Werte sind hier nicht sinnvoll.', 'lindenweg16' );
				break;
			}
		}

		if ( null !== $data['annual_operating_hours'] && 0.0 === (float) $data['annual_operating_hours'] ) {
			$errors[] = __( 'Betriebsstunden je Jahr dürfen nicht 0 sein – sonst lässt sich der Ersatzteilanteil nicht umlegen.', 'lindenweg16' );
		}

		return array(
			'data'   => $data,
			'errors' => $errors,
		);
	}

	/**
	 * Gerät anlegen.
	 *
	 * @param array $data Geprüfte Daten.
	 * @return int Neue ID oder 0.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'printers', $id, null, $data );
		return $id;
	}

	/**
	 * Gerät ändern.
	 *
	 * @param int    $id     ID.
	 * @param array  $data   Geprüfte Daten.
	 * @param string $reason Begründung.
	 * @return bool
	 */
	public static function update( $id, array $data, $reason = '' ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql', true );

		if ( false === $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ) ) {
			return false;
		}
		BetriebAudit::record( 'update', 'printers', (int) $id, $before, $data, $reason );
		return true;
	}

}
