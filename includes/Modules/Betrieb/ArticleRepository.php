<?php
/**
 * Artikelstamm.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Artikel samt Protokollierung.
 */
class ArticleRepository {

	use SanitizesInput;

	/**
	 * Erlaubte Status.
	 */
	const STATUS = array( 'aktiv', 'entwicklung', 'gesperrt', 'ausgelaufen' );

	/**
	 * Erlaubte Beschaffungswege.
	 */
	const SOURCING = array( 'druck', 'montage', 'zukauf', 'hybrid' );

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_articles';
	}

	/**
	 * Bezeichnungen der Beschaffungswege.
	 *
	 * @return array
	 */
	public static function sourcing_labels() {
		return array(
			'druck'   => __( 'Rohling aus dem Drucker', 'lindenweg16' ),
			'montage' => __( 'Halbzeug oder Baugruppe (Montage)', 'lindenweg16' ),
			'zukauf'  => __( 'Zukauf', 'lindenweg16' ),
			'hybrid'  => __( 'Hybrid (Zukauf mit gedruckten Anbauteilen)', 'lindenweg16' ),
		);
	}

	/**
	 * Bezeichnungen der Status.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'aktiv'       => __( 'aktiv', 'lindenweg16' ),
			'entwicklung' => __( 'in Entwicklung', 'lindenweg16' ),
			'gesperrt'    => __( 'gesperrt', 'lindenweg16' ),
			'ausgelaufen' => __( 'ausgelaufen', 'lindenweg16' ),
		);
	}

	/**
	 * Alle Artikel.
	 *
	 * @param bool $only_sellable Nur verkäufliche Artikel.
	 * @return array
	 */
	public static function all( $only_sellable = false ) {
		global $wpdb;
		$table = self::table();
		$where = $only_sellable ? 'WHERE sellable = 1' : '';
		return $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY sku ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Artikel nach ID indiziert – Kontext für die Kalkulation.
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
	 * Einen Artikel holen.
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
	 * Artikel anhand eines fremden Barcodes.
	 *
	 * Zugekaufte Ware traegt den Barcode des Lieferanten. Ihn zu erfassen ist
	 * billiger, als jeden Beutel neu zu bekleben.
	 *
	 * @param string $barcode Barcode.
	 * @return array|null
	 */
	public static function find_by_barcode( $barcode ) {
		global $wpdb;

		$barcode = trim( (string) $barcode );
		if ( '' === $barcode ) {
			return null;
		}

		$table = self::table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE barcode = %s", $barcode ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Artikel über die Artikelnummer holen.
	 *
	 * @param string $sku Artikelnummer.
	 * @return array|null
	 */
	public static function find_by_sku( $sku ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE sku = %s", (string) $sku ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Eingaben prüfen.
	 *
	 * @param array    $input Rohe Eingaben.
	 * @param int|null $id    Bei Bearbeitung die eigene ID.
	 * @return array {data, errors}
	 */
	public static function validate( array $input, $id = null ) {
		$errors = array();

		$sku = isset( $input['sku'] ) ? strtoupper( sanitize_text_field( $input['sku'] ) ) : '';
		if ( '' === $sku ) {
			$errors[] = __( 'Eine Artikelnummer ist Pflicht.', 'lindenweg16' );
		} else {
			$existing = self::find_by_sku( $sku );
			if ( $existing && (int) $existing['id'] !== (int) $id ) {
				$errors[] = sprintf(
					/* translators: %s: Artikelnummer. */
					__( 'Die Artikelnummer %s ist bereits vergeben.', 'lindenweg16' ),
					$sku
				);
			}
		}

		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		if ( '' === $name ) {
			$errors[] = __( 'Eine Bezeichnung ist Pflicht.', 'lindenweg16' );
		}

		$sourcing = self::enum( $input, 'sourcing_type', self::SOURCING, 'druck' );

		$data = array(
			'sku'                => $sku,
			'name'               => $name,
			'status'             => self::enum( $input, 'status', self::STATUS, 'entwicklung' ),
			'sourcing_type'      => $sourcing,
			'sellable'           => self::flag( $input, 'sellable' ),
			'discountable'       => self::flag( $input, 'discountable' ),
			'default_bin_id'     => self::bin_or_null( $input ),
			'wc_product_id'      => self::decimal( $input, 'wc_product_id' ) ? (int) self::decimal( $input, 'wc_product_id' ) : null,
			'legacy_produkt_id'  => self::decimal( $input, 'legacy_produkt_id' ) ? (int) self::decimal( $input, 'legacy_produkt_id' ) : null,
			'price_gross'        => self::decimal( $input, 'price_gross' ),
			'dealer_price'       => self::decimal( $input, 'dealer_price' ),
			'weight_g'           => self::decimal( $input, 'weight_g' ),
			'postprocessing_min' => self::int_or( $input, 'postprocessing_min', 0 ),
			'load_rating_kg'     => self::decimal( $input, 'load_rating_kg' ),
			'barcode'            => self::text( $input, 'barcode' ) ? self::text( $input, 'barcode' ) : null,
			'notes'              => isset( $input['notes'] ) ? sanitize_textarea_field( $input['notes'] ) : null,
		);

		// Ein verkäuflicher Artikel braucht einen Preis, sonst rechnet die
		// Kalkulation gegen null und meldet einen Deckungsbeitrag von minus
		// den Fixkosten.
		if ( $data['sellable'] && ( null === $data['price_gross'] || $data['price_gross'] <= 0 ) ) {
			$errors[] = __( 'Ein verkäuflicher Artikel braucht einen Bruttopreis.', 'lindenweg16' );
		}

		if ( 'zukauf' === $sourcing && null !== $data['weight_g'] && $data['weight_g'] > 0 ) {
			$errors[] = __( 'Ein Zukaufartikel hat kein Filamentgewicht – die Kosten kommen aus der Beschaffung.', 'lindenweg16' );
		}

		return array(
			'data'   => $data,
			'errors' => $errors,
		);
	}

	/**
	 * Artikel anlegen.
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
		BetriebAudit::record( 'create', 'articles', $id, null, $data );
		return $id;
	}

	/**
	 * Artikel ändern.
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
		BetriebAudit::record( 'update', 'articles', (int) $id, $before, $data, $reason );
		return true;
	}
}
