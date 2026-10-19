<?php
/**
 * Soll gegen Ist: Daten und Korrektur.
 *
 * Auswertung der erledigten Platten der letzten 90 Tage je Artikel, mit
 * Nachrechnung der Marge, wenn die gemessene statt der geplanten Druckzeit
 * gilt. „Planzeit übernehmen“ schreibt die gemessene Plattenzeit in die
 * aktive Revision des Artikels; die Änderung steht im Protokoll.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\CalculationContext;
use Lindenweg16\Modules\Betrieb\CostCalculator;
use Lindenweg16\Modules\Betrieb\RevisionRepository;

/**
 * Soll-Ist-Auswertung.
 */
class SollIst {

	/**
	 * Auswertungszeitraum in Tagen.
	 */
	const DAYS = 90;

	/**
	 * Erledigte Platten im Zeitraum.
	 *
	 * @return array
	 */
	protected static function done_runs() {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - self::DAYS * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . RunRepository::table() . ' WHERE status = %s AND reported_at >= %s ORDER BY reported_at DESC LIMIT 500', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				RunFlow::ERLEDIGT,
				$since
			),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * Geplante Minuten eines Postens.
	 *
	 * @param array $revision Revision.
	 * @param int   $qty      Stückzahl.
	 * @return float
	 */
	protected static function plan_minutes( array $revision, $qty ) {
		$per = max( 1, (int) $revision['parts_per_plate'] );

		return ( (int) $qty / $per ) * (float) $revision['print_time_plate_h'] * 60;
	}

	/**
	 * Die Auswertung.
	 *
	 * @param bool $with_money Kosten und Marge ausgeben (Recht „Preise, Kosten und Löhne sehen“).
	 * @return array {articles, setup_avg, runs}
	 */
	public static function report( $with_money ) {
		$per     = array();
		$setups  = array();
		$counted = 0;

		foreach ( self::done_runs() as $run ) {
			$minutes = Variance::run_minutes( $run );
			$items   = json_decode( (string) $run['items'], true );

			if ( ! $minutes || ! is_array( $items ) || ! $items ) {
				continue;
			}

			$plan = array();

			foreach ( $items as $item ) {
				$revision = RevisionRepository::active_for_article( (int) $item['article_id'] );

				if ( $revision ) {
					$plan[ (int) $item['article_id'] ] = ( isset( $plan[ (int) $item['article_id'] ] ) ? $plan[ (int) $item['article_id'] ] : 0 ) + self::plan_minutes( $revision, (int) $item['qty'] );
				}
			}

			if ( ! $plan ) {
				continue;
			}

			++$counted;
			$setups[] = $minutes['setup'];

			foreach ( Variance::allocate( $minutes['print'], $plan ) as $id => $actual ) {
				if ( ! isset( $per[ $id ] ) ) {
					$per[ $id ] = array( 'plan' => 0.0, 'actual' => 0.0, 'runs' => 0 );
				}

				$per[ $id ]['plan']   += $plan[ $id ];
				$per[ $id ]['actual'] += $actual;
				$per[ $id ]['runs']   += 1;
			}
		}

		$ctx      = $with_money ? CalculationContext::build() : array();
		$settings = $with_money ? CalculationContext::settings() : array();
		$articles = array();

		foreach ( $per as $id => $sum ) {
			$article  = ArticleRepository::find( $id );
			$revision = RevisionRepository::active_for_article( $id );
			$factor   = Variance::factor( $sum['plan'], $sum['actual'] );

			if ( ! $article || ! $revision ) {
				continue;
			}

			$entry = array(
				'article_id' => $id,
				'sku'        => (string) $article['sku'],
				'name'       => (string) $article['name'],
				'runs'       => $sum['runs'],
				'plan_min'   => round( $sum['plan'] ),
				'actual_min' => round( $sum['actual'] ),
				'factor'     => $factor,
				'adjust'     => Variance::should_adjust( $factor, $sum['runs'] ),
				'plate_h'    => (float) $revision['print_time_plate_h'],
				'new_h'      => null !== $factor ? Variance::adjusted_hours( $revision['print_time_plate_h'], $factor ) : null,
				'margin'     => null,
			);

			if ( $with_money && null !== $factor && ! empty( $ctx['articles'][ $id ] ) ) {
				$entry['margin'] = self::margin( $id, $factor, $ctx, $settings );
			}

			$articles[] = $entry;
		}

		// Die größte Abweichung nach oben zuerst: Dort wird Geld verschenkt.
		usort(
			$articles,
			function ( $a, $b ) {
				return ( null === $b['factor'] ? 0 : $b['factor'] ) <=> ( null === $a['factor'] ? 0 : $a['factor'] );
			}
		);

		return array(
			'articles'  => $articles,
			'setup_avg' => $setups ? round( array_sum( $setups ) / count( $setups ), 1 ) : 0.0,
			'runs'      => $counted,
			'money'     => (bool) $with_money,
			'days'      => self::DAYS,
		);
	}

	/**
	 * Marge nach Plan und mit gemessener Zeit.
	 *
	 * @param int   $id       Artikel.
	 * @param float $factor   Ist durch Soll.
	 * @param array $ctx      Kalkulationskontext.
	 * @param array $settings Annahmen.
	 * @return array {plan_pct, actual_pct, actual_db2, state}
	 */
	protected static function margin( $id, $factor, array $ctx, array $settings ) {
		$plan = CostCalculator::contribution( $id, $ctx, $settings, 'uvp' );

		if ( isset( $ctx['revisions'][ $id ] ) ) {
			$ctx['revisions'][ $id ]['print_time_plate_h'] = (float) $ctx['revisions'][ $id ]['print_time_plate_h'] * $factor;
		}

		$real = CostCalculator::contribution( $id, $ctx, $settings, 'uvp' );

		return array(
			'plan_pct'   => round( (float) $plan['db2_pct'], 3 ),
			'actual_pct' => round( (float) $real['db2_pct'], 3 ),
			'actual_db2' => round( (float) $real['db2'], 2 ),
			'state'      => Variance::margin_state( (float) $plan['db2_pct'], (float) $real['db2_pct'], (float) $real['db2'] ),
		);
	}

	/**
	 * Gemessene Plattenzeit als Planzeit übernehmen.
	 *
	 * @param int $article_id Artikel.
	 * @return array {ok, message}
	 */
	public static function adopt( $article_id ) {
		$article_id = (int) $article_id;
		$revision   = RevisionRepository::active_for_article( $article_id );

		if ( ! $revision ) {
			return array( 'ok' => false, 'message' => __( 'Der Artikel hat keine aktive Revision.', 'lindenweg16' ) );
		}

		foreach ( self::report( false )['articles'] as $entry ) {
			if ( $entry['article_id'] !== $article_id ) {
				continue;
			}

			if ( ! $entry['adjust'] ) {
				return array( 'ok' => false, 'message' => __( 'Es liegen noch zu wenige oder zu ähnliche Messwerte vor.', 'lindenweg16' ) );
			}

			RevisionRepository::update(
				(int) $revision['id'],
				array( 'print_time_plate_h' => $entry['new_h'] ),
				sprintf( /* translators: 1: alte Zeit, 2: neue Zeit, 3: Platten. */ __( 'Planzeit aus dem Workflow: %1$s h → %2$s h (%3$d Platten gemessen).', 'lindenweg16' ), $entry['plate_h'], $entry['new_h'], $entry['runs'] )
			);

			return array( 'ok' => true, 'message' => sprintf( /* translators: %s: Stunden. */ __( 'Planzeit auf %s h je Platte gesetzt.', 'lindenweg16' ), number_format_i18n( $entry['new_h'], 2 ) ) );
		}

		return array( 'ok' => false, 'message' => __( 'Für diesen Artikel liegen keine Messwerte vor.', 'lindenweg16' ) );
	}
}
