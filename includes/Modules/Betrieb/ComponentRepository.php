<?php
/**
 * Komponenten: Kleinteile und Normteile, die nicht einzeln verkauft werden.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Komponenten.
 */
class ComponentRepository {

	use SanitizesInput;

	/**
	 * Erlaubte Kategorien.
	 */
	const CATEGORIES = array( 'schraube', 'duebel', 'gewindeeinsatz', 'werkzeug', 'verpackung', 'sonstiges' );

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_components';
	}

	/**
	 * Kategoriebezeichnungen.
	 *
	 * @return array
	 */
	public static function category_labels() {
		return array(
			'schraube'       => __( 'Schraube', 'lindenweg16' ),
			'duebel'         => __( 'Dübel', 'lindenweg16' ),
			'gewindeeinsatz' => __( 'Gewindeeinsatz', 'lindenweg16' ),
			'werkzeug'       => __( 'Werkzeug', 'lindenweg16' ),
			'verpackung'     => __( 'Verpackung', 'lindenweg16' ),
			'sonstiges'      => __( 'Sonstiges', 'lindenweg16' ),
		);
	}

	/**
	 * Alle Komponenten.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY code ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Nach ID indiziert – Kontext für die Kalkulation.
	 *
	 * @return array
	 */
	public static function all_by_id() {
		$rows = array();
		foreach ( self::all() as $row ) {
			$rows[ (int) $row['id'] ] = $row;
		}
		return $rows;
	}

	/**
	 * Eine Komponente holen.
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
	 * Komponente anhand ihres Codes oder eines fremden Barcodes.
	 *
	 * @param string $code Code oder Barcode.
	 * @return array|null
	 */
	public static function find_by_code_or_barcode( $code ) {
		global $wpdb;

		$code = trim( (string) $code );
		if ( '' === $code ) {
			return null;
		}

		$table = self::table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s OR barcode = %s LIMIT 1", $code, $code ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Komponente über ihren Code holen.
	 *
	 * @param string $code Code.
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
	 * Eingaben prüfen.
	 *
	 * @param array    $input Eingaben.
	 * @param int|null $id    Eigene ID bei Bearbeitung.
	 * @return array {data, errors}
	 */
	public static function validate( array $input, $id = null ) {
		$errors = array();

		$code = isset( $input['code'] ) ? strtoupper( sanitize_text_field( $input['code'] ) ) : '';
		if ( '' === $code ) {
			$errors[] = __( 'Ein Code ist Pflicht (z. B. SCHR-M4-80).', 'lindenweg16' );
		} else {
			$existing = self::find_by_code( $code );
			if ( $existing && (int) $existing['id'] !== (int) $id ) {
				$errors[] = sprintf(
					/* translators: %s: Code. */
					__( 'Der Code %s ist bereits vergeben.', 'lindenweg16' ),
					$code
				);
			}
		}

		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		if ( '' === $name ) {
			$errors[] = __( 'Eine Bezeichnung ist Pflicht.', 'lindenweg16' );
		}

		$pack_qty = self::decimal_or( $input, 'pack_qty', 1 );
		if ( $pack_qty <= 0 ) {
			$errors[] = __( 'Die Gebindegröße muss größer als 0 sein – aus ihr wird der Stückpreis gerechnet.', 'lindenweg16' );
		}

		$data = array(
			'code'           => $code,
			'name'           => $name,
			'barcode'        => self::text( $input, 'barcode' ),
			'category'       => self::enum( $input, 'category', self::CATEGORIES, 'sonstiges' ),
			'supplier'       => self::text( $input, 'supplier' ),
			'pack_qty'       => $pack_qty,
			'pack_price_net' => self::decimal_or( $input, 'pack_price_net', 0 ),
			'order_url'      => Purchase::clean_url( isset( $input['order_url'] ) ? $input['order_url'] : '' ),
			'lead_time_days' => self::int_or( $input, 'lead_time_days', 0 ),
			'min_order_qty'  => self::decimal_or( $input, 'min_order_qty', 1 ),
			'stock_qty'      => self::decimal_or( $input, 'stock_qty', 0 ),
			'safety_days'    => self::int_or( $input, 'safety_days', 7 ),
			'default_bin_id' => self::bin_or_null( $input ),
			'note'           => isset( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) : null,
		);

		if ( $data['pack_price_net'] < 0 ) {
			$errors[] = __( 'Der Gebindepreis darf nicht negativ sein.', 'lindenweg16' );
		}

		return array(
			'data'   => $data,
			'errors' => $errors,
		);
	}

	/**
	 * Komponente anlegen.
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
		BetriebAudit::record( 'create', 'components', $id, null, $data );
		return $id;
	}

	/**
	 * Komponente ändern.
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
		BetriebAudit::record( 'update', 'components', (int) $id, $before, $data, $reason );
		return true;
	}
}
