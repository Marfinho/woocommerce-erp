<?php
/**
 * Übernahme aus der alten Lagerverwaltung.
 *
 * Mit dem Zusammenlegen auf eine Lagerverwaltung verschwindet das alte Menü.
 * Was dort an Produkten und Beständen steht, darf dabei nicht verwaisen –
 * deshalb dieser einmalige Übergang.
 *
 * Grundsätze:
 * - Es wird nichts gelöscht und nichts überschrieben. Die alten Tabellen
 *   bleiben, wie sie sind; sie sind der Beleg dafür, wo der Anfangsbestand
 *   hergekommen ist.
 * - Der Bestand kommt als eine einzige Anfangsbuchung je Artikel und Farbe
 *   ins Bewegungskonto, mit Verweis auf das alte Produkt. Kein stiller
 *   Übertrag: Man kann später nachlesen, welche Zahl übernommen wurde.
 * - Zweimal ausgeführt entsteht kein doppelter Bestand, weil Artikel über
 *   legacy_produkt_id wiedererkannt werden.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Inventory\Repository;

/**
 * Überträgt Produkte und Bestände in das Modul Betrieb.
 */
class LegacyMigration {

	/**
	 * Was würde übernommen? Reine Vorschau, ohne zu schreiben.
	 *
	 * @return array Zeilen mit Produkt, Zielart und Zustand.
	 */
	public static function preview() {
		$rows = array();

		foreach ( self::legacy_products() as $product ) {
			$existing = self::find_migrated( (int) $product->id );

			$rows[] = array(
				'legacy_id' => (int) $product->id,
				'name'      => (string) $product->name,
				'typ'       => (string) $product->typ,
				'stock'     => (float) Repository::get_stock( (int) $product->id ),
				'target'    => self::target_kind( $product ),
				'status'    => $existing ? 'vorhanden' : 'neu',
				'sku'       => $existing ? $existing['sku'] : self::make_sku( $product ),
			);
		}

		return $rows;
	}

	/**
	 * Übernahme ausführen.
	 *
	 * @return array {angelegt, uebersprungen, gebucht}
	 */
	public static function run() {
		$created = 0;
		$skipped = 0;
		$booked  = 0;

		foreach ( self::legacy_products() as $product ) {
			$legacy_id = (int) $product->id;
			$existing  = self::find_migrated( $legacy_id );

			if ( $existing ) {
				$skipped++;
				continue;
			}

			$id = ArticleRepository::insert(
				array(
					'sku'                => self::make_sku( $product ),
					'name'               => (string) $product->name,
					'status'             => 'aktiv',
					'wc_product_id'      => ! empty( $product->woo_product_id ) ? (int) $product->woo_product_id : null,
					'legacy_produkt_id'  => $legacy_id,
					'sourcing_type'      => self::target_kind( $product ),
					'sellable'           => 'rohmaterial' === $product->typ ? 0 : 1,
					'price_gross'        => null,
					'dealer_price'       => null,
					'weight_g'           => ! empty( $product->filament_gramm ) ? (float) $product->filament_gramm : null,
					'postprocessing_min' => ! empty( $product->arbeitszeit_minuten ) ? (int) $product->arbeitszeit_minuten : 0,
					'load_rating_kg'     => null,
					'discountable'       => 1,
					'barcode'            => null,
					'notes'              => sprintf(
						/* translators: %d: alte Produkt-ID. */
						__( 'Übernommen aus der alten Lagerverwaltung, Produkt %d.', 'lindenweg16' ),
						$legacy_id
					),
				)
			);

			if ( ! $id ) {
				continue;
			}

			$created++;

			$stock = (float) Repository::get_stock( $legacy_id );
			if ( abs( $stock ) > 0.0005 ) {
				StockRepository::book(
					array(
						'article_id'    => $id,
						'color'         => ColorRegistry::default_color(),
						'qty'           => $stock,
						'movement_type' => 'korrektur',
						'location'      => 'lager',
						'ref_type'      => 'altbestand',
						'ref_id'        => $legacy_id,
						'note'          => sprintf(
							/* translators: 1: Menge, 2: alte Produkt-ID. */
							__( 'Anfangsbestand %1$s aus der alten Lagerverwaltung, Produkt %2$d.', 'lindenweg16' ),
							$stock,
							$legacy_id
						),
					)
				);
				$booked++;
			}
		}

		return array(
			'angelegt'      => $created,
			'uebersprungen' => $skipped,
			'gebucht'       => $booked,
		);
	}

	/**
	 * Produkte der alten Verwaltung.
	 *
	 * @return array
	 */
	protected static function legacy_products() {
		if ( ! class_exists( Repository::class ) ) {
			return array();
		}

		$products = Repository::get_products();

		return is_array( $products ) ? $products : array();
	}

	/**
	 * Schon übernommener Artikel zu einem alten Produkt.
	 *
	 * @param int $legacy_id Alte Produkt-ID.
	 * @return array|null
	 */
	protected static function find_migrated( $legacy_id ) {
		global $wpdb;

		$table = ArticleRepository::table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE legacy_produkt_id = %d", (int) $legacy_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Beschaffungsweg aus dem alten Typ ableiten.
	 *
	 * Module wurden gedruckt, alles andere wurde eingekauft. Wer es anders
	 * braucht, ändert es danach am Artikel – raten ist hier besser als eine
	 * Rückfrage je Zeile.
	 *
	 * @param object $product Altes Produkt.
	 * @return string
	 */
	protected static function target_kind( $product ) {
		return 'modul' === $product->typ ? 'druck' : 'zukauf';
	}

	/**
	 * Artikelnummer bilden, die garantiert frei ist.
	 *
	 * @param object $product Altes Produkt.
	 * @return string
	 */
	protected static function make_sku( $product ) {
		$base = strtoupper( sanitize_title( (string) $product->name ) );
		$base = preg_replace( '/[^A-Z0-9]+/', '-', $base );
		$base = trim( (string) $base, '-' );
		$base = $base ? substr( $base, 0, 40 ) : 'ALT';

		$sku   = $base;
		$index = 1;

		while ( ArticleRepository::find_by_sku( $sku ) ) {
			$index++;
			$sku = $base . '-' . $index;
		}

		return $sku;
	}
}
