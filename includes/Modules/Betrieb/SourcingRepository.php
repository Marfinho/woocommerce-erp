<?php
/**
 * Beschaffungsdaten für Zukauf- und Hybridartikel.
 *
 * Historisiert wie die Kalkulationsannahmen: Ein Preiswechsel des Lieferanten
 * erzeugt eine neue Zeile, keine Überschreibung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Beschaffungsdaten.
 */
class SourcingRepository {

	use SanitizesInput;

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_article_sourcing';
	}

	/**
	 * Zum Stichtag gültige Zeile je Artikel auswählen – reine Logik.
	 *
	 * @param array  $rows     Zeilen mit article_id und valid_from.
	 * @param string $stichtag Datum als Y-m-d.
	 * @return array article_id => Zeile.
	 */
	public static function resolve( array $rows, $stichtag ) {
		$best   = array();
		$result = array();

		foreach ( $rows as $row ) {
			$row  = (array) $row;
			$id   = isset( $row['article_id'] ) ? (int) $row['article_id'] : 0;
			$from = isset( $row['valid_from'] ) ? substr( (string) $row['valid_from'], 0, 10 ) : '';

			if ( ! $id || '' === $from || $from > $stichtag ) {
				continue;
			}
			if ( isset( $best[ $id ] ) && $best[ $id ] > $from ) {
				continue;
			}
			$best[ $id ]   = $from;
			$result[ $id ] = $row;
		}

		return $result;
	}

	/**
	 * Zum Stichtag gültige Beschaffungsdaten aller Artikel.
	 *
	 * @param string $stichtag Datum als Y-m-d.
	 * @return array
	 */
	public static function all_valid_at( $stichtag = '' ) {
		global $wpdb;

		if ( '' === $stichtag ) {
			$stichtag = gmdate( 'Y-m-d' );
		}
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE valid_from <= %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$stichtag
			),
			ARRAY_A
		);

		return self::resolve( $rows ? $rows : array(), $stichtag );
	}

	/**
	 * Verlauf eines Artikels, neueste zuerst.
	 *
	 * @param int $article_id Artikel.
	 * @return array
	 */
	public static function history( $article_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE article_id = %d ORDER BY valid_from DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $article_id
			),
			ARRAY_A
		);
	}

	/**
	 * Eingaben prüfen.
	 *
	 * @param array $input Eingaben.
	 * @return array {data, errors}
	 */
	public static function validate( array $input ) {
		$errors     = array();
		$article_id = self::int_or( $input, 'article_id', 0 );

		$article = $article_id ? ArticleRepository::find( $article_id ) : null;
		if ( ! $article ) {
			$errors[] = __( 'Der Artikel existiert nicht.', 'lindenweg16' );
		} elseif ( in_array( $article['sourcing_type'], array( 'druck', 'montage' ), true ) ) {
			$errors[] = __( 'Selbst gefertigte Artikel werden nicht eingekauft. Beschaffungsweg zuerst auf Zukauf oder Hybrid ändern.', 'lindenweg16' );
		}

		$price = self::decimal_or( $input, 'purchase_price_net', 0 );
		if ( $price <= 0 ) {
			$errors[] = __( 'Ein Einkaufspreis größer als 0 ist Pflicht – sonst rechnet die Kalkulation den Artikel kostenlos.', 'lindenweg16' );
		}

		return array(
			'data'   => array(
				'article_id'         => $article_id,
				'supplier_name'      => self::text( $input, 'supplier_name' ),
				'supplier_contact'   => self::text( $input, 'supplier_contact' ),
				'purchase_price_net' => $price,
				'min_order_qty'      => self::decimal_or( $input, 'min_order_qty', 1 ),
				'lead_time_days'     => self::int_or( $input, 'lead_time_days', 0 ),
				'own_processing_min' => self::int_or( $input, 'own_processing_min', 0 ),
				'unit_note'          => isset( $input['unit_note'] ) ? sanitize_textarea_field( $input['unit_note'] ) : null,
				'valid_from'         => self::date_or_today( $input, 'valid_from' ),
			),
			'errors' => $errors,
		);
	}

	/**
	 * Neue Gültigkeit festschreiben.
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
		BetriebAudit::record( 'create', 'article_sourcing', $id, null, $data );
		return $id;
	}
}
