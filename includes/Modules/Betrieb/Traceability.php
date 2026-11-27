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

		return array(
			'batch'    => $batch,
			'article'  => $article,
			'revision' => $revision,
			'lot'      => $lot,
			'printer'  => $printer,
			'orders'   => self::orders_for_batch( $batch ),
		);
	}
}
