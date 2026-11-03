<?php
/**
 * Soll gegen Ist: Daten und Korrektur.
 *
 * Auswertung der erledigten Platten der letzten 90 Tage: Druckzeit je Artikel
 * (aus reinen Platten, je Teil), Rüstzeit gegen ihr Soll, Wartezeiten und die
 * Datenqualität - mit Nachrechnung der Marge, wenn die gemessene statt der
 * geplanten Druckzeit gilt. „Planzeit übernehmen“ schreibt die gemessene Plattenzeit in die
 * aktive Revision des Artikels; die Änderung steht im Protokoll.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository;
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
	 * Name des Druckers einer Platte.
	 *
	 * @param array $run Platte.
	 * @return string
	 */
	protected static function printer_label( array $run ) {
		if ( ! empty( $run['bambu_printer'] ) ) {
			$bambu = BambuPrinterRepository::find_by_printer_id( $run['bambu_printer'] );

			return $bambu ? (string) $bambu->name : (string) $run['bambu_printer'];
		}

		return '';
	}

	/**
	 * Die Auswertung.
	 *
	 * Getrennt nach Druck (Maschine), Rüsten (Handarbeit) und Warten. Die Druckzeit
	 * je Artikel kommt aus reinen Platten (nur ein Artikel): gemessene Zeit durch
	 * geplante Zeit, als Median. Nur wenn es keine reine Platte gibt, zählt die
	 * anteilig verteilte Zeit gemischter Platten - markiert als geschätzt.
	 * Fehlgeschlagene Drucke und Platten ohne Druckzeit bleiben draußen und
	 * werden in der Datenqualität gezählt.
	 *
	 * @param bool $with_money Kosten und Marge ausgeben (Recht „Preise, Kosten und Löhne sehen“).
	 * @return array {articles, setup, wait, quality, runs, money, days}
	 */
	public static function report( $with_money ) {
		$pure    = array();
		$mixed   = array();
		$setups  = array();
		$printer = array();
		$before  = array();
		$after   = array();
		$counted = 0;
		$quality = array(
			'runs'           => 0,
			'drucker'        => 0,
			'abfrage'        => 0,
			'hand'           => 0,
			'ohne_druckzeit' => 0,
			'fehlgeschlagen' => 0,
			'ruesten_hand'   => 0,
			'ruesten_ohne'   => 0,
		);

		foreach ( self::done_runs() as $run ) {
			++$quality['runs'];

			$m     = Variance::measure( $run );
			$items = json_decode( (string) $run['items'], true );
			$items = is_array( $items ) ? $items : array();

			if ( null !== $m['setup'] ) {
				++$quality['ruesten_hand'];
				$setups[] = $m['setup'];
				$label    = self::printer_label( $run );

				if ( '' !== $label ) {
					$printer[ $label ][] = $m['setup'];
				}
			} else {
				++$quality['ruesten_ohne'];
			}

			if ( null !== $m['wait_before'] ) {
				$before[] = $m['wait_before'];
			}

			if ( null !== $m['wait_after'] ) {
				$after[] = $m['wait_after'];
			}

			if ( null === $m['print'] ) {
				++$quality['ohne_druckzeit'];
				continue;
			}

			++$quality[ $m['print_src'] ];

			if ( $m['failed'] ) {
				++$quality['fehlgeschlagen'];
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
			$only = Variance::pure_article( $items );

			if ( $only && isset( $plan[ $only ] ) ) {
				$pure[ $only ][] = array( 'plan' => $plan[ $only ], 'actual' => $m['print'] );
				continue;
			}

			foreach ( Variance::allocate( $m['print'], $plan ) as $id => $actual ) {
				$mixed[ $id ][] = array( 'plan' => $plan[ $id ], 'actual' => $actual );
			}
		}

		$ctx      = $with_money ? CalculationContext::build() : array();
		$settings = $with_money ? CalculationContext::settings() : array();
		$articles = array();

		foreach ( array_unique( array_merge( array_keys( $pure ), array_keys( $mixed ) ) ) as $id ) {
			$estimated = empty( $pure[ $id ] );
			$samples   = $estimated ? $mixed[ $id ] : $pure[ $id ];
			$plan_sum  = 0.0;
			$real_sum  = 0.0;
			$factors   = array();

			foreach ( $samples as $sample ) {
				$plan_sum += $sample['plan'];
				$real_sum += $sample['actual'];

				if ( $sample['plan'] > 0 ) {
					$factors[] = $sample['actual'] / $sample['plan'];
				}
			}

			$article  = ArticleRepository::find( $id );
			$revision = RevisionRepository::active_for_article( $id );
			$median   = Variance::median( $factors );
			$factor   = null === $median ? null : round( $median, 3 );
			$runs     = count( $samples );

			if ( ! $article || ! $revision ) {
				continue;
			}

			$per_part = (float) $revision['print_time_plate_h'] * 60 / max( 1, (int) $revision['parts_per_plate'] );

			$entry = array(
				'article_id'  => $id,
				'sku'         => (string) $article['sku'],
				'name'        => (string) $article['name'],
				'runs'        => $runs,
				'pure_runs'   => empty( $pure[ $id ] ) ? 0 : count( $pure[ $id ] ),
				'estimated'   => $estimated,
				'plan_min'    => round( $plan_sum ),
				'actual_min'  => round( $real_sum ),
				'part_plan'   => round( $per_part, 2 ),
				'part_actual' => null === $factor ? null : round( $per_part * $factor, 2 ),
				'factor'      => $factor,
				'adjust'      => Variance::should_adjust( $factor, $runs ),
				'plate_h'     => (float) $revision['print_time_plate_h'],
				'new_h'       => null !== $factor ? Variance::adjusted_hours( $revision['print_time_plate_h'], $factor ) : null,
				'margin'      => null,
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

		$by_printer = array();
		foreach ( $printer as $label => $values ) {
			$by_printer[] = array( 'printer' => $label, 'n' => count( $values ), 'median_min' => round( Variance::median( $values ), 1 ) );
		}

		$plan_setup   = (float) Workflow::settings()['setup_plan_min'];
		$actual_setup = Variance::median( $setups );

		return array(
			'articles' => $articles,
			'setup'    => array(
				'plan_min'   => $plan_setup,
				'actual_min' => null === $actual_setup ? null : round( $actual_setup, 1 ),
				'n'          => count( $setups ),
				'factor'     => null !== $actual_setup && $plan_setup > 0 ? round( $actual_setup / $plan_setup, 2 ) : null,
				'by_printer' => $by_printer,
			),
			'wait'     => array(
				'before_min' => $before ? round( Variance::median( $before ), 1 ) : null,
				'after_min'  => $after ? round( Variance::median( $after ), 1 ) : null,
			),
			'quality'  => $quality,
			'runs'     => $counted,
			'money'    => (bool) $with_money,
			'days'     => self::DAYS,
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
