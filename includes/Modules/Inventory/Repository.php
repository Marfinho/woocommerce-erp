<?php
/**
 * Datenzugriffsschicht für die Lagerverwaltung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Inventory;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Time;

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
		$data['erstellt_am'] = Time::now();
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
	 * Produkt löschen (samt aller abhängigen Datensätze).
	 *
	 * Räumt bewusst *alle* Kindtabellen ab. Ohne die letzten drei Zeilen
	 * bleiben Ortsbestände, Bewegungen und Picklistenpositionen als Waisen
	 * zurück und verfälschen später Auswertungen.
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
		$wpdb->delete( self::table( 'lagerbestand_ort' ), array( 'produkt_id' => $id ) );
		$wpdb->delete( self::table( 'lagerbewegung' ), array( 'produkt_id' => $id ) );
		$wpdb->delete( self::table( 'pickliste_pos' ), array( 'produkt_id' => $id ) );
	}

	/**
	 * Ein anderes Produkt mit derselben WooCommerce-ID finden.
	 *
	 * Die Spalte trägt bewusst keinen UNIQUE-Index (bestehende Installationen
	 * könnten bereits Duplikate enthalten und dbDelta würde still scheitern),
	 * deshalb wird die Eindeutigkeit hier im Code geprüft.
	 *
	 * @param int $woo_id     WooCommerce-Produkt-ID.
	 * @param int $exclude_id Eigene Produkt-ID, die ignoriert wird.
	 * @return object|null
	 */
	public static function find_woo_conflict( $woo_id, $exclude_id = 0 ) {
		global $wpdb;
		$woo_id = (int) $woo_id;
		if ( ! $woo_id ) {
			return null;
		}
		$t = self::table( 'produkte' );
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE woo_product_id = %d AND id <> %d LIMIT 1", // phpcs:ignore
				$woo_id,
				(int) $exclude_id
			)
		);
	}

	/**
	 * Alle Produkte samt Bestand in einer einzigen Abfrage.
	 *
	 * Ersetzt das Muster „alle Produkte laden, dann pro Produkt get_stock()".
	 *
	 * @return array Produktzeilen mit zusätzlichem Feld `bestand`.
	 */
	public static function get_products_with_stock() {
		global $wpdb;
		$tp = self::table( 'produkte' );
		$tb = self::table( 'lagerbestand' );
		return $wpdb->get_results(
			"SELECT p.*, COALESCE(b.menge, 0) AS bestand
			 FROM {$tp} p
			 LEFT JOIN {$tb} b ON b.produkt_id = p.id
			 ORDER BY p.typ, p.name ASC" // phpcs:ignore
		);
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
				Time::now()
			)
		);
	}

	/**
	 * Bestand atomar um einen Delta-Wert verändern und den neuen Wert liefern.
	 *
	 * Rechnet die Addition in der Datenbank (menge = menge + delta), statt den
	 * Bestand in PHP zu lesen, zu addieren und absolut zurückzuschreiben. Nur
	 * so gehen bei gleichzeitigen Bestellungen keine Buchungen verloren.
	 *
	 * @param int   $produkt_id Produkt-ID.
	 * @param float $delta      Veränderung (signiert).
	 * @return float Neuer Bestand.
	 */
	public static function adjust_stock( $produkt_id, $delta ) {
		global $wpdb;
		$produkt_id = (int) $produkt_id;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO " . self::table( 'lagerbestand' ) . " (produkt_id, menge, aktualisiert_am) VALUES (%d, %f, %s)
				 ON DUPLICATE KEY UPDATE menge = menge + VALUES(menge), aktualisiert_am = VALUES(aktualisiert_am)", // phpcs:ignore
				$produkt_id,
				(float) $delta,
				Time::now()
			)
		);
		return self::get_stock( $produkt_id );
	}

	/**
	 * Ortsbestand atomar um einen Delta-Wert verändern.
	 *
	 * @param int   $produkt_id  Produkt-ID.
	 * @param int   $lagerort_id Lagerort-ID.
	 * @param float $delta       Veränderung (signiert).
	 * @return float Neuer Ortsbestand.
	 */
	public static function adjust_stock_at_location( $produkt_id, $lagerort_id, $delta ) {
		global $wpdb;
		$produkt_id  = (int) $produkt_id;
		$lagerort_id = (int) $lagerort_id;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO " . self::table( 'lagerbestand_ort' ) . " (produkt_id, lagerort_id, menge, aktualisiert_am) VALUES (%d, %d, %f, %s)
				 ON DUPLICATE KEY UPDATE menge = menge + VALUES(menge), aktualisiert_am = VALUES(aktualisiert_am)", // phpcs:ignore
				$produkt_id,
				$lagerort_id,
				(float) $delta,
				Time::now()
			)
		);
		return self::get_stock_at_location( $produkt_id, $lagerort_id );
	}

	/* --------------------------------------------------------------------
	 * Lagerorte
	 * ------------------------------------------------------------------ */

	public static function create_lagerort( array $data ) {
		global $wpdb;
		$data['erstellt_am'] = Time::now();
		$wpdb->insert( self::table( 'lagerorte' ), $data );
		return (int) $wpdb->insert_id;
	}

	public static function update_lagerort( $id, array $data ) {
		global $wpdb;
		$wpdb->update( self::table( 'lagerorte' ), $data, array( 'id' => (int) $id ) );
	}

	public static function get_lagerort( $id ) {
		global $wpdb;
		$t = self::table( 'lagerorte' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore
	}

	public static function get_lagerorte( $nur_aktiv = true ) {
		global $wpdb;
		$t = self::table( 'lagerorte' );
		if ( $nur_aktiv ) {
			return $wpdb->get_results( "SELECT * FROM {$t} WHERE aktiv = 1 ORDER BY kuerzel ASC" ); // phpcs:ignore
		}
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY kuerzel ASC" ); // phpcs:ignore
	}

	public static function delete_lagerort( $id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'lagerorte' ), array( 'id' => (int) $id ) );
		$wpdb->delete( self::table( 'lagerbestand_ort' ), array( 'lagerort_id' => (int) $id ) );
	}

	/* --------------------------------------------------------------------
	 * Lagerbestand pro Ort
	 * ------------------------------------------------------------------ */

	public static function get_stock_by_location( $produkt_id ) {
		global $wpdb;
		$tb = self::table( 'lagerbestand_ort' );
		$tl = self::table( 'lagerorte' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT bo.*, l.kuerzel, l.name AS lagerort_name FROM {$tb} bo LEFT JOIN {$tl} l ON l.id = bo.lagerort_id WHERE bo.produkt_id = %d ORDER BY l.kuerzel", // phpcs:ignore
			$produkt_id
		) );
	}

	/**
	 * Ortsbestand absolut setzen.
	 *
	 * Zeilen mit Menge 0 (oder negativ) werden bewusst behalten: sie werden
	 * sonst unauffindbar, und die Summe aller Ortsbestände würde vom
	 * Gesamtbestand abweichen, der ebenfalls negativ werden darf.
	 *
	 * @param int   $produkt_id  Produkt-ID.
	 * @param int   $lagerort_id Lagerort-ID.
	 * @param float $menge       Neuer Ortsbestand.
	 */
	public static function set_stock_at_location( $produkt_id, $lagerort_id, $menge ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO " . self::table( 'lagerbestand_ort' ) . " (produkt_id, lagerort_id, menge, aktualisiert_am) VALUES (%d, %d, %f, %s)
				 ON DUPLICATE KEY UPDATE menge = VALUES(menge), aktualisiert_am = VALUES(aktualisiert_am)", // phpcs:ignore
				(int) $produkt_id,
				(int) $lagerort_id,
				(float) $menge,
				Time::now()
			)
		);
	}

	public static function get_stock_at_location( $produkt_id, $lagerort_id ) {
		global $wpdb;
		$t = self::table( 'lagerbestand_ort' );
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT menge FROM {$t} WHERE produkt_id = %d AND lagerort_id = %d", $produkt_id, $lagerort_id ) ); // phpcs:ignore
		return null === $v ? 0.0 : (float) $v;
	}

	/* --------------------------------------------------------------------
	 * Picklisten
	 * ------------------------------------------------------------------ */

	public static function create_pickliste( array $data ) {
		global $wpdb;
		$data['erstellt_am'] = Time::now();
		$wpdb->insert( self::table( 'pickliste' ), $data );
		return (int) $wpdb->insert_id;
	}

	public static function get_pickliste( $id ) {
		global $wpdb;
		$t = self::table( 'pickliste' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore
	}

	public static function get_picklisten( $status = null, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$t = self::table( 'pickliste' );
		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $status, $limit, $offset ) ); // phpcs:ignore
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ) ); // phpcs:ignore
	}

	public static function count_picklisten( $status = null ) {
		global $wpdb;
		$t = self::table( 'pickliste' );
		if ( $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = %s", $status ) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore
	}

	public static function update_pickliste( $id, array $data ) {
		global $wpdb;
		$wpdb->update( self::table( 'pickliste' ), $data, array( 'id' => (int) $id ) );
	}

	public static function add_pickliste_pos( $pickliste_id, array $pos ) {
		global $wpdb;
		$pos['pickliste_id'] = (int) $pickliste_id;
		$wpdb->insert( self::table( 'pickliste_pos' ), $pos );
		return (int) $wpdb->insert_id;
	}

	public static function get_pickliste_positionen( $pickliste_id ) {
		global $wpdb;
		$tp  = self::table( 'pickliste_pos' );
		$tpr = self::table( 'produkte' );
		$tl  = self::table( 'lagerorte' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT pp.*, pr.name AS produkt_name, pr.basis_einheit, l.kuerzel AS lagerort_kuerzel, l.name AS lagerort_name
			 FROM {$tp} pp
			 LEFT JOIN {$tpr} pr ON pr.id = pp.produkt_id
			 LEFT JOIN {$tl} l ON l.id = pp.lagerort_id
			 WHERE pp.pickliste_id = %d
			 ORDER BY l.kuerzel, pr.name", // phpcs:ignore
			$pickliste_id
		) );
	}

	public static function update_pickliste_pos( $id, array $data ) {
		global $wpdb;
		$wpdb->update( self::table( 'pickliste_pos' ), $data, array( 'id' => (int) $id ) );
	}

	public static function get_pickliste_by_order( $order_id ) {
		global $wpdb;
		$t = self::table( 'pickliste' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d ORDER BY id DESC LIMIT 1", $order_id ) ); // phpcs:ignore
	}

	public static function get_unpicked_order_ids() {
		global $wpdb;
		$t = self::table( 'pickliste' );

		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$ot = $wpdb->prefix . 'wc_orders';
			return $wpdb->get_col(
				"SELECT o.id FROM {$ot} o
				 LEFT JOIN {$t} p ON p.order_id = o.id
				 WHERE o.status IN ('wc-processing','wc-on-hold','wc-pending')
				   AND p.id IS NULL
				 ORDER BY o.id DESC"
			); // phpcs:ignore
		}

		return $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$t} pk ON pk.order_id = p.ID
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ('wc-processing','wc-on-hold','wc-pending')
			   AND pk.id IS NULL
			 ORDER BY p.ID DESC"
		); // phpcs:ignore
	}
}
