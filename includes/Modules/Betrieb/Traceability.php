<?php
/**
 * Rückverfolgung in beide Richtungen.
 *
 * Von einer Filamentcharge zu allen betroffenen Chargen und Bestellungen –
 * und von einer Bestellung zurück zur Filamentcharge. Das ist die Auskunft,
 * die im Ernstfall innerhalb von Minuten vorliegen muss.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Verfolgt Chargen vorwärts und rückwärts.
 */
class Traceability {

	/**
	 * Vorwärts: Was ist aus einer Filamentcharge geworden?
	 *
	 * @param int $lot_id Filamentcharge.
	 * @return array {lot, jobs, batches, orders}
	 */
	public static function forward_from_lot( $lot_id ) {
		global $wpdb;

		$lot_id = (int) $lot_id;
		$lot    = FilamentRepository::find( $lot_id );

		if ( ! $lot ) {
			return array(
				'lot'     => null,
				'jobs'    => array(),
				'batches' => array(),
				'orders'  => array(),
			);
		}

		$jobs_table = PrintJobRepository::table();
		$jobs       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$jobs_table} WHERE filament_lot_id = %d ORDER BY job_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lot_id
			),
			ARRAY_A
		);

		$batches = BatchRepository::all( array( 'filament_lot_id' => $lot_id, 'limit' => 500 ) );

		// Betroffene Bestellungen: Alle Verkäufe der Artikel dieser Chargen
		// ab dem jeweiligen Produktionsdatum.
		$orders = array();
		foreach ( $batches as $batch ) {
			foreach ( self::orders_for_batch( $batch ) as $order ) {
				$orders[ $order['ref_id'] . '-' . $order['ref_type'] ] = $order;
			}
		}

		return array(
			'lot'     => $lot,
			'jobs'    => $jobs,
			'batches' => $batches,
			'orders'  => array_values( $orders ),
		);
	}

	/**
	 * Rückwärts: Aus welchem Material stammt eine Bestellung?
	 *
	 * @param string $ref_type wc_order oder order_ops.
	 * @param int    $ref_id   Bestellung.
	 * @return array {movements, batches, lots}
	 */
	public static function backward_from_order( $ref_type, $ref_id ) {
		global $wpdb;

		$stock     = StockRepository::table();
		$movements = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$stock} WHERE ref_type = %s AND ref_id = %d AND qty < 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				sanitize_key( $ref_type ),
				(int) $ref_id
			),
			ARRAY_A
		);

		$batches = array();
		$lots    = array();

		foreach ( $movements as $movement ) {
			// Chargen desselben Artikels, die vor dem Verkauf produziert
			// wurden – nach dem Verbrauchsprinzip zuerst die ältesten.
			$candidates = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM " . BatchRepository::table() . "
					 WHERE article_id = %d AND produced_on <= DATE( %s )
					 ORDER BY produced_on DESC LIMIT 5", // phpcs:ignore WordPress.DB.PreparedSQL
					(int) $movement['article_id'],
					$movement['moved_at']
				),
				ARRAY_A
			);

			foreach ( $candidates as $batch ) {
				$batches[ (int) $batch['id'] ] = $batch;

				if ( $batch['filament_lot_id'] ) {
					$lot = FilamentRepository::find( (int) $batch['filament_lot_id'] );
					if ( $lot ) {
						$lots[ (int) $lot['id'] ] = $lot;
					}
				}
			}
		}

		return array(
			'movements' => $movements,
			'batches'   => array_values( $batches ),
			'lots'      => array_values( $lots ),
		);
	}

	/**
	 * Bestellungen, in denen Teile einer Charge stecken können.
	 *
	 * @param array $batch Charge.
	 * @return array
	 */
	public static function orders_for_batch( array $batch ) {
		global $wpdb;

		$stock = StockRepository::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ref_type, ref_id, moved_at, qty, color FROM {$stock}
				 WHERE article_id = %d AND qty < 0
				 AND ref_type IN ( 'wc_order', 'order_ops' )
				 AND DATE( moved_at ) >= %s
				 ORDER BY moved_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $batch['article_id'],
				$batch['produced_on']
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Vollständige Chargenakte für den GPSR-Export.
	 *
	 * @param int $batch_id Charge.
	 * @return array
	 */
	public static function dossier( $batch_id ) {
		$batch = BatchRepository::find( $batch_id );

		if ( ! $batch ) {
			return array();
		}

		$article  = ArticleRepository::find( (int) $batch['article_id'] );
		$revision = $batch['article_revision_id'] ? RevisionRepository::find( (int) $batch['article_revision_id'] ) : null;
		$lot      = $batch['filament_lot_id'] ? FilamentRepository::find( (int) $batch['filament_lot_id'] ) : null;
		$printer  = $batch['printer_id'] ? PrinterRepository::find( (int) $batch['printer_id'] ) : null;
		$tests    = $revision ? TensileRepository::for_revision( (int) $revision['id'] ) : array();

		return array(
			'batch'    => $batch,
			'article'  => $article,
			'revision' => $revision,
			'lot'      => $lot,
			'printer'  => $printer,
			'tests'    => $tests,
			'release'  => ReleaseGate::evaluate(
				$tests,
				$article && null !== $article['load_rating_kg'] ? (float) $article['load_rating_kg'] : 0.0
			),
			'orders'   => self::orders_for_batch( $batch ),
		);
	}
}
