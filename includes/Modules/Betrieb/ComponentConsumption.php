<?php
/**
 * Verbrauch von Kleinteilen und Verbrauchsmaterial.
 *
 * Der Verbrauch wird nicht geschätzt, sondern gebucht: Bei jedem Druckjob und
 * bei jeder abgeschlossenen Bestellung wird die Stückliste aufgelöst und der
 * Komponentenbestand entsprechend reduziert. Verkaufst du ein Set mit Stange,
 * gehen automatisch die Dübel, Schrauben und der Bit vom Bestand ab.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Bucht Komponenten- und Verpackungsverbrauch.
 */
class ComponentConsumption {

	/**
	 * Benötigte Komponenten einer Menge eines Artikels – reine Logik.
	 *
	 * @param int   $article_id Artikel.
	 * @param float $qty        Menge.
	 * @param array $ctx        Kontext mit 'bom'.
	 * @return array component_id => Menge.
	 */
	public static function required( $article_id, $qty, array $ctx ) {
		$result = BomResolver::resolve( $article_id, $ctx, (float) $qty );
		$needed = array();

		foreach ( $result['lines'] as $line ) {
			if ( 'komponente' !== $line['child_type'] ) {
				continue;
			}
			$id = (int) $line['child_id'];
			if ( ! isset( $needed[ $id ] ) ) {
				$needed[ $id ] = 0.0;
			}
			$needed[ $id ] += (float) $line['qty'];
		}

		return $needed;
	}

	/**
	 * Komponenten eines Fertigungsvorgangs abbuchen.
	 *
	 * Nur die direkte Stückliste des gefertigten Artikels: Unterbaugruppen
	 * werden als eigene Druckjobs gefertigt und buchen dort ihre eigenen
	 * Kleinteile ab. Sonst würde doppelt verbraucht.
	 *
	 * @param int    $article_id Artikel.
	 * @param float  $qty        Gefertigte Menge.
	 * @param string $ref_type   Herkunft.
	 * @param int    $ref_id     Herkunfts-ID.
	 * @return array Gebuchte Mengen.
	 */
	public static function consume_for_production( $article_id, $qty, $ref_type, $ref_id ) {
		if ( (float) $qty <= 0 ) {
			return array();
		}

		$direct = BomRepository::for_article( $article_id );
		$needed = array();

		foreach ( $direct as $row ) {
			if ( 'komponente' !== $row['child_type'] ) {
				continue;
			}
			$id = (int) $row['child_id'];
			if ( ! isset( $needed[ $id ] ) ) {
				$needed[ $id ] = 0.0;
			}
			$needed[ $id ] += (float) $row['qty'] * (float) $qty;
		}

		return self::book( $needed, $ref_type, $ref_id );
	}

	/**
	 * Komponenten einer verkauften Menge abbuchen – hier vollständig
	 * aufgelöst, weil das Set als Ganzes das Haus verlässt.
	 *
	 * @param int    $article_id Artikel.
	 * @param float  $qty        Menge.
	 * @param string $ref_type   Herkunft.
	 * @param int    $ref_id     Herkunfts-ID.
	 * @return array
	 */
	public static function consume_for_sale( $article_id, $qty, $ref_type, $ref_id ) {
		if ( (float) $qty <= 0 ) {
			return array();
		}

		$ctx    = array( 'bom' => BomRepository::all_by_parent() );
		$needed = self::required( $article_id, $qty, $ctx );

		return self::book( $needed, $ref_type, $ref_id );
	}

	/**
	 * Verpackungsmaterial einer Bestellung pauschal abbuchen.
	 *
	 * @param int $order_op_id Bestellung.
	 * @return int Anzahl gebuchter Positionen.
	 */
	public static function consume_supplies( $order_op_id ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'lw16_supplies';
		$supplies = $wpdb->get_results( "SELECT * FROM {$table} WHERE consumption_per_order > 0", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count    = 0;

		foreach ( (array) $supplies as $supply ) {
			$before = $supply;
			$data   = array(
				'stock_qty'  => (float) $supply['stock_qty'] - (float) $supply['consumption_per_order'],
				'updated_at' => current_time( 'mysql', true ),
			);

			$wpdb->update( $table, $data, array( 'id' => (int) $supply['id'] ) );
			BetriebAudit::record(
				'update',
				'supplies',
				(int) $supply['id'],
				$before,
				$data,
				sprintf(
					/* translators: %d: Bestell-ID. */
					__( 'Verbrauch durch Bestellung %d', 'lindenweg16' ),
					(int) $order_op_id
				)
			);
			$count++;
		}

		return $count;
	}

	/**
	 * Mengen vom Komponentenbestand abziehen und protokollieren.
	 *
	 * @param array  $needed   component_id => Menge.
	 * @param string $ref_type Herkunft.
	 * @param int    $ref_id   Herkunfts-ID.
	 * @return array
	 */
	protected static function book( array $needed, $ref_type, $ref_id ) {
		global $wpdb;

		$table  = ComponentRepository::table();
		$booked = array();

		foreach ( $needed as $component_id => $qty ) {
			$component = ComponentRepository::find( $component_id );
			if ( ! $component ) {
				continue;
			}

			$data = array(
				'stock_qty'  => (float) $component['stock_qty'] - (float) $qty,
				'updated_at' => current_time( 'mysql', true ),
			);

			$wpdb->update( $table, $data, array( 'id' => (int) $component_id ) );
			BetriebAudit::record(
				'update',
				'components',
				(int) $component_id,
				$component,
				$data,
				sprintf(
					/* translators: 1: Menge, 2: Herkunft, 3: ID. */
					__( 'Verbrauch %1$s Stück aus %2$s %3$d', 'lindenweg16' ),
					number_format_i18n( (float) $qty, 2 ),
					$ref_type,
					(int) $ref_id
				)
			);

			$booked[ (int) $component_id ] = (float) $qty;
		}

		return $booked;
	}
}
