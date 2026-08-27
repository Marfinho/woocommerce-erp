<?php
/**
 * Benannte Bestellarten (Sets).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Db;

/**
 * Lesen und Schreiben der Bestellarten samt Positionen.
 */
class BundleRepository {

	use SanitizesInput;

	/**
	 * Tabellenname der Bestellarten.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_bundles';
	}

	/**
	 * Tabellenname der Positionen.
	 *
	 * @return string
	 */
	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_bundle_items';
	}

	/**
	 * Alle Bestellarten.
	 *
	 * @param bool $only_active Nur aktive.
	 * @return array
	 */
	public static function all( $only_active = false ) {
		global $wpdb;
		$table = self::table();
		$where = $only_active ? 'WHERE active = 1' : '';
		return $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Eine Bestellart holen.
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
	 * Positionen einer Bestellart.
	 *
	 * @param int $bundle_id Bestellart.
	 * @return array
	 */
	public static function items( $bundle_id ) {
		global $wpdb;
		$table = self::items_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE bundle_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $bundle_id
			),
			ARRAY_A
		);
	}

	/**
	 * Bestellart mit Positionen speichern.
	 *
	 * @param string $name  Bezeichnung.
	 * @param float  $price Setpreis brutto.
	 * @param array  $items Positionen [article_id => qty].
	 * @return array {id, errors}
	 */
	public static function save( $name, $price, array $items ) {
		global $wpdb;

		$name   = sanitize_text_field( $name );
		$price  = (float) $price;
		$errors = array();

		if ( '' === $name ) {
			$errors[] = __( 'Eine Bezeichnung ist Pflicht.', 'lindenweg16' );
		}
		if ( $price <= 0 ) {
			$errors[] = __( 'Ein Setpreis größer als 0 ist Pflicht.', 'lindenweg16' );
		}

		$clean = array();
		foreach ( $items as $article_id => $qty ) {
			$article_id = (int) $article_id;
			$qty        = (float) $qty;
			if ( $article_id > 0 && $qty > 0 ) {
				$clean[ $article_id ] = $qty;
			}
		}
		if ( ! $clean ) {
			$errors[] = __( 'Eine Bestellart braucht mindestens eine Position.', 'lindenweg16' );
		}

		if ( $errors ) {
			return array(
				'id'     => 0,
				'errors' => $errors,
			);
		}

		$data = array(
			'name'               => $name,
			'bundle_price_gross' => $price,
			'active'             => 1,
			'valid_from'         => gmdate( 'Y-m-d' ),
			'created_at'         => current_time( 'mysql', true ),
			'created_by'         => get_current_user_id(),
		);

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return array(
				'id'     => 0,
				'errors' => array( __( 'Die Bestellart konnte nicht gespeichert werden.', 'lindenweg16' ) ),
			);
		}

		$id = (int) $wpdb->insert_id;

		$positionen = array();

		foreach ( $clean as $article_id => $qty ) {
			$positionen[] = array(
				'bundle_id'  => $id,
				'article_id' => $article_id,
				'qty'        => $qty,
			);
		}

		Db::insert_many( self::items_table(), $positionen );

		BetriebAudit::record( 'create', 'bundles', $id, null, array_merge( $data, array( 'items' => $clean ) ) );

		return array(
			'id'     => $id,
			'errors' => array(),
		);
	}

	/**
	 * Bestellart deaktivieren.
	 *
	 * @param int    $id     ID.
	 * @param string $reason Begründung.
	 * @return bool
	 */
	public static function deactivate( $id, $reason = '' ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return false;
		}

		$data = array(
			'active'     => 0,
			'updated_at' => current_time( 'mysql', true ),
		);

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		BetriebAudit::record( 'update', 'bundles', (int) $id, $before, $data, $reason );
		return true;
	}
}
