<?php
/**
 * Nachbestellungen lesen und schreiben.
 *
 * Der Wareneingang ist die einzige Stelle, an der eine Bestellung den
 * Bestand verändert – und er tut es genau einmal. Danach ist die Bestellung
 * geliefert und kann nicht erneut eingebucht werden.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Datenzugriff für Nachbestellungen.
 */
class PurchaseRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_purchases';
	}

	/**
	 * Alle Bestellungen, neueste zuerst.
	 *
	 * @param string $status Optionaler Filter.
	 * @return array
	 */
	public static function all( $status = '' ) {
		global $wpdb;
		$table = self::table();

		if ( '' === $status ) {
			return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY ordered_on DESC, id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY ordered_on DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$status
			),
			ARRAY_A
		);
	}

	/**
	 * Alles, was noch unterwegs ist.
	 *
	 * @return array
	 */
	public static function open() {
		return self::all( 'bestellt' );
	}

	/**
	 * Eine Bestellung.
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
	 * Bestellung anlegen.
	 *
	 * @param array $input Eingaben.
	 * @return array {success, id, errors}
	 */
	public static function create( array $input ) {
		global $wpdb;

		$result = Purchase::validate( $input, gmdate( 'Y-m-d' ) );

		if ( $result['errors'] ) {
			return array( 'success' => false, 'id' => 0, 'errors' => $result['errors'] );
		}

		$data = $result['data'];

		$data['supplier']   = $data['supplier'] ? sanitize_text_field( $data['supplier'] ) : null;
		$data['order_ref']  = $data['order_ref'] ? sanitize_text_field( $data['order_ref'] ) : null;
		$data['note']       = $data['note'] ? sanitize_textarea_field( $data['note'] ) : null;
		$data['created_at'] = current_time( 'mysql', true );
		$data['created_by'] = get_current_user_id();

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return array(
				'success' => false,
				'id'      => 0,
				'errors'  => array( __( 'Die Bestellung ließ sich nicht speichern.', 'lindenweg16' ) ),
			);
		}

		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'purchases', $id, null, $data );

		return array( 'success' => true, 'id' => $id, 'errors' => array() );
	}

	/**
	 * Wareneingang buchen: Bestand erhöhen, Bestellung schliessen.
	 *
	 * Die tatsächlich gelieferte Menge kann von der bestellten abweichen –
	 * gebucht wird, was ankam, nicht was bestellt war.
	 *
	 * @param int    $id    Bestellung.
	 * @param float  $qty   Gelieferte Menge.
	 * @param string $datum Lieferdatum.
	 * @return array {success, message}
	 */
	public static function receive( $id, $qty, $datum = '' ) {
		global $wpdb;

		$purchase = self::find( $id );

		if ( ! $purchase ) {
			return array( 'success' => false, 'message' => __( 'Diese Bestellung gibt es nicht.', 'lindenweg16' ) );
		}

		if ( ! Purchase::is_open( $purchase ) ) {
			return array(
				'success' => false,
				'message' => __( 'Diese Bestellung ist bereits abgeschlossen – ein zweiter Wareneingang würde den Bestand verdoppeln.', 'lindenweg16' ),
			);
		}

		$qty = (float) str_replace( ',', '.', (string) $qty );

		if ( $qty <= 0 ) {
			$qty = (float) $purchase['qty'];
		}

		$datum = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $datum ) ? $datum : gmdate( 'Y-m-d' );

		$data = array(
			'status'       => 'geliefert',
			'received_on'  => $datum,
			'received_qty' => $qty,
			'updated_at'   => current_time( 'mysql', true ),
		);

		if ( false === $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ) ) {
			return array( 'success' => false, 'message' => __( 'Der Wareneingang ließ sich nicht speichern.', 'lindenweg16' ) );
		}

		BetriebAudit::record( 'update', 'purchases', (int) $id, $purchase, $data, __( 'Wareneingang gebucht.', 'lindenweg16' ) );

		self::add_stock( $purchase['item_type'], (int) $purchase['item_id'], $qty );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: Menge. */
				__( 'Wareneingang gebucht: %s Stück im Bestand.', 'lindenweg16' ),
				number_format_i18n( $qty, 0 )
			),
		);
	}

	/**
	 * Bestellung stornieren – ohne Bestandswirkung, denn es kam nichts an.
	 *
	 * @param int    $id     Bestellung.
	 * @param string $reason Begründung.
	 * @return array {success, message}
	 */
	public static function cancel( $id, $reason = '' ) {
		global $wpdb;

		$purchase = self::find( $id );

		if ( ! $purchase || ! Purchase::is_open( $purchase ) ) {
			return array( 'success' => false, 'message' => __( 'Diese Bestellung lässt sich nicht mehr stornieren.', 'lindenweg16' ) );
		}

		$data = array(
			'status'     => 'storniert',
			'updated_at' => current_time( 'mysql', true ),
		);

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		BetriebAudit::record( 'update', 'purchases', (int) $id, $purchase, $data, $reason ? sanitize_text_field( $reason ) : __( 'Bestellung storniert.', 'lindenweg16' ) );

		return array( 'success' => true, 'message' => __( 'Bestellung storniert.', 'lindenweg16' ) );
	}

	/**
	 * Den Bestand des bestellten Teils erhöhen.
	 *
	 * Kleinteile und Verbrauchsmaterial führen ihren Bestand als Feld, nicht
	 * als Bewegungskonto – anders als die Artikel. Deshalb hier ein
	 * Aufschlag statt einer Buchung.
	 *
	 * @param string $type    component oder supply.
	 * @param int    $item_id Teil.
	 * @param float  $qty     Menge.
	 */
	protected static function add_stock( $type, $item_id, $qty ) {
		global $wpdb;

		$table = 'supply' === $type
			? $wpdb->prefix . 'lw16_supplies'
			: $wpdb->prefix . 'lw16_components';

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET stock_qty = stock_qty + %f WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(float) $qty,
				(int) $item_id
			)
		);
	}

	/**
	 * Bestellungen zu einem Teil, neueste zuerst.
	 *
	 * @param string $type    component oder supply.
	 * @param int    $item_id Teil.
	 * @return array
	 */
	public static function for_item( $type, $item_id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE item_type = %s AND item_id = %d ORDER BY ordered_on DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $type,
				(int) $item_id
			),
			ARRAY_A
		);
	}
}
