<?php
/**
 * Verbrauchsmaterial ohne Stücklistenbezug.
 *
 * Kartons, Holzwolle, Seidenpapier, Papiertüten, Klebeband – sie hängen an
 * keiner Stückliste, werden aber je Bestellung verbraucht.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben des Verbrauchsmaterials.
 */
class SupplyRepository {

	use SanitizesInput;

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_supplies';
	}

	/**
	 * Alle Positionen.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Eine Position holen.
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
	 * Verbrauchsmaterial löschen.
	 *
	 * Erlaubt, weil es Stammdaten sind und keine Buchung: Ein Karton, den
	 * es nicht mehr gibt, soll auch nicht mehr je Bestellung abgebucht
	 * werden. Was in der Vergangenheit verbraucht wurde, steht im
	 * Änderungsprotokoll und bleibt dort.
	 *
	 * @param int $id Datensatz.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$before = self::find( $id );

		if ( ! $before ) {
			return false;
		}

		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		BetriebAudit::record( 'delete', 'supplies', (int) $id, $before, null );

		return true;
	}

	/**
	 * Position anlegen oder ändern.
	 *
	 * @param array $input Eingaben.
	 * @return array {id, errors}
	 */
	public static function save( array $input ) {
		global $wpdb;

		$errors = array();
		$name   = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';

		if ( '' === $name ) {
			$errors[] = __( 'Eine Bezeichnung ist Pflicht.', 'lindenweg16' );
		}

		if ( $errors ) {
			return array(
				'id'     => 0,
				'errors' => $errors,
			);
		}

		$data = array(
			'name'                  => $name,
			'unit'                  => self::text( $input, 'unit' ) ? self::text( $input, 'unit' ) : 'Stück',
			'stock_qty'             => self::decimal_or( $input, 'stock_qty', 0 ),
			'min_order_qty'         => self::decimal_or( $input, 'min_order_qty', 1 ),
			'lead_time_days'        => self::int_or( $input, 'lead_time_days', 0 ),
			'supplier'              => self::text( $input, 'supplier' ),
			'price_per_unit'        => self::decimal_or( $input, 'price_per_unit', 0 ),
			'consumption_per_order' => self::decimal_or( $input, 'consumption_per_order', 0 ),
			'safety_days'           => self::int_or( $input, 'safety_days', 7 ),
		);

		// Nur setzen, wenn das Formular das Feld kennt – sonst bliebe ein gepflegter Link nicht erhalten.
		if ( isset( $input['order_url'] ) ) {
			$data['order_url'] = Purchase::clean_url( $input['order_url'] );
		}

		$id = self::int_or( $input, 'supply_id', 0 );

		if ( $id ) {
			$before             = self::find( $id );
			$data['updated_at'] = current_time( 'mysql', true );
			$wpdb->update( self::table(), $data, array( 'id' => $id ) );
			BetriebAudit::record( 'update', 'supplies', $id, $before, $data );

			return array(
				'id'     => $id,
				'errors' => array(),
			);
		}

		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();
		$wpdb->insert( self::table(), $data );

		$new_id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'supplies', $new_id, null, $data );

		return array(
			'id'     => $new_id,
			'errors' => array(),
		);
	}

	/**
	 * Bestand um eine Menge ändern – Korrektur mit Begründung.
	 *
	 * Die Datenbank rechnet (siehe ComponentConsumption::subtract), und das
	 * Änderungsprotokoll hält Stand vorher und nachher fest.
	 *
	 * @param int    $id     Datensatz.
	 * @param float  $delta  Menge, positiv erhöht, negativ senkt.
	 * @param string $reason Begründung.
	 * @return bool
	 */
	public static function adjust_stock( $id, $delta, $reason = '' ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return false;
		}

		$table = self::table();
		$now   = current_time( 'mysql', true );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET stock_qty = stock_qty + %f, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(float) $delta,
				$now,
				(int) $id
			)
		);

		$after = self::find( $id );
		BetriebAudit::record( 'update', 'supplies', (int) $id, $before, $after, $reason );
		return true;
	}
}
