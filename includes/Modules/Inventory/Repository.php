<?php
/**
 * Datenzugriffsschicht für die Lagerverwaltung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Inventory;

defined( 'ABSPATH' ) || exit;

/**
 * Kapselt alle DB-Zugriffe rund um Produkte, Stücklisten, Rezepturen,
 * Lagerbestand und Lagerbewegungen. Alle Statements sind prepared.
 */
class Repository {

	/**
	 * Tabellenname mit Präfix.
	 *
	 * @param string $name Kurzname ohne Präfix (z. B. produkte).
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'lw16_' . $name;
	}

	/* --------------------------------------------------------------------
	 * Produkte
	 * ------------------------------------------------------------------ */

	/**
	 * Produkt anlegen.
	 *
	 * @param array $data Produktdaten.
	 * @return int Neue ID.
	 */
	public static function create_product( array $data ) {
		global $wpdb;
		$data['erstellt_am'] = current_time( 'mysql' );
		$wpdb->insert( self::table( 'produkte' ), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Produkt aktualisieren.
	 *
	 * @param int   $id   Produkt-ID.
	 * @param array $data Zu ändernde Felder.
	 */
	public static function update_product( $id, array $data ) {
		global $wpdb;
		$wpdb->update( self::table( 'produkte' ), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Produkt per ID holen.
	 *
	 * @param int $id Produkt-ID.
	 * @return object|null
	 */
	public static function get_product( $id ) {
		global $wpdb;
		$t = self::table( 'produkte' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore
	}

	/**
	 * Produkt anhand der WooCommerce-Produkt-ID holen.
	 *
	 * @param int $woo_id WooCommerce-Produkt-ID.
	 * @return object|null
	 */
	public static function get_by_woo_id( $woo_id ) {
		global $wpdb;
		$t = self::table( 'produkte' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE woo_product_id = %d", $woo_id ) ); // phpcs:ignore
	}

	/**
	 * Produkte nach Typ holen.
	 *
	 * @param string|null $typ set|modul|rohmaterial oder null für alle.
	 * @return array
	 */
	public static function get_products( $typ = null ) {
		global $wpdb;
		$t = self::table( 'produkte' );
		if ( $typ ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE typ = %s ORDER BY name ASC", $typ ) ); // phpcs:ignore
		}
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY typ, name ASC" ); // phpcs:ignore
	}

	/**
	 * Produkt löschen (samt abhängiger Stückliste/Rezeptur).
	 *
	 * @param int $id Produkt-ID.
	 */
	public static function delete_product( $id ) {
		global $wpdb;
		$id = (int) $id;
		$wpdb->delete( self::table( 'produkte' ), array( 'id' => $id ) );
		$wpdb->delete( self::table( 'stueckliste' ), array( 'set_id' => $id ) );
		$wpdb->delete( self::table( 'stueckliste' ), array( 'modul_id' => $id ) );
		$wpdb->delete( self::table( 'rezeptur' ), array( 'modul_id' => $id ) );
		$wpdb->delete( self::table( 'rezeptur' ), array( 'rohmaterial_id' => $id ) );
		$wpdb->delete( self::table( 'lagerbestand' ), array( 'produkt_id' => $id ) );
	}

	/* --------------------------------------------------------------------
	 * Stückliste (Set -> Module)
	 * ------------------------------------------------------------------ */

	/**
	 * Stücklistenpositionen eines Sets holen.
	 *
	 * @param int $set_id Set-Produkt-ID.
	 * @return array Zeilen mit modul_id und menge.
	 */
	public static function get_stueckliste( $set_id ) {
		global $wpdb;
		$t = self::table( 'stueckliste' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE set_id = %d", $set_id ) ); // phpcs:ignore
	}

	/**
	 * Komplette Stückliste eines Sets neu setzen.
	 *
	 * @param int   $set_id    Set-Produkt-ID.
	 * @param array $positions Array aus [modul_id => menge].
	 */
	public static function set_stueckliste( $set_id, array $positions ) {
		global $wpdb;
		$wpdb->delete( self::table( 'stueckliste' ), array( 'set_id' => (int) $set_id ) );
		foreach ( $positions as $modul_id => $menge ) {
			if ( (float) $menge <= 0 ) {
				continue;
			}
			$wpdb->insert(
				self::table( 'stueckliste' ),
				array(
					'set_id'   => (int) $set_id,
					'modul_id' => (int) $modul_id,
					'menge'    => (float) $menge,
				),
				array( '%d', '%d', '%f' )
			);
		}
	}

	/* --------------------------------------------------------------------
	 * Rezeptur (Modul -> Rohmaterial)
	 * ------------------------------------------------------------------ */

	/**
	 * Rezeptur eines Moduls holen.
	 *
	 * @param int $modul_id Modul-Produkt-ID.
	 * @return array
	 */
	public static function get_rezeptur( $modul_id ) {
		global $wpdb;
		$t = self::table( 'rezeptur' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE modul_id = %d", $modul_id ) ); // phpcs:ignore
	}

	/**
	 * Rezeptur eines Moduls neu setzen.
	 *
	 * @param int   $modul_id  Modul-Produkt-ID.
	 * @param array $positions Array aus [rohmaterial_id => menge].
	 */
	public static function set_rezeptur( $modul_id, array $positions ) {
		global $wpdb;
		$wpdb->delete( self::table( 'rezeptur' ), array( 'modul_id' => (int) $modul_id ) );
		foreach ( $positions as $roh_id => $menge ) {
			if ( (float) $menge <= 0 ) {
				continue;
			}
			$wpdb->insert(
				self::table( 'rezeptur' ),
				array(
					'modul_id'       => (int) $modul_id,
					'rohmaterial_id' => (int) $roh_id,
					'menge'          => (float) $menge,
				),
				array( '%d', '%d', '%f' )
			);
		}
	}

	/* --------------------------------------------------------------------
	 * Lagerbestand
	 * ------------------------------------------------------------------ */

	/**
	 * Aktuellen Bestand eines Produkts holen (Basis-Einheit).
	 *
	 * @param int $produkt_id Produkt-ID.
	 * @return float
	 */
	public static function get_stock( $produkt_id ) {
		global $wpdb;
		$t = self::table( 'lagerbestand' );
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT menge FROM {$t} WHERE produkt_id = %d", $produkt_id ) ); // phpcs:ignore
		return null === $v ? 0.0 : (float) $v;
	}

	/**
	 * Bestand direkt setzen (nur intern nach Bewegungsverbuchung).
	 *
	 * @param int   $produkt_id Produkt-ID.
	 * @param float $menge      Neuer Bestand.
	 */
	public static function set_stock( $produkt_id, $menge ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO " . self::table( 'lagerbestand' ) . " (produkt_id, menge, aktualisiert_am) VALUES (%d, %f, %s)
				 ON DUPLICATE KEY UPDATE menge = VALUES(menge), aktualisiert_am = VALUES(aktualisiert_am)", // phpcs:ignore
				(int) $produkt_id,
				(float) $menge,
				current_time( 'mysql' )
			)
		);
	}
}
