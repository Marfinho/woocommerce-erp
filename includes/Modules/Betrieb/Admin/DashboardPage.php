<?php
/**
 * Dashboard: Kennzahlen, Alarme, Fälligkeiten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\CalcSettings;
use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\DemandPlanner;
use Lindenweg16\Modules\Betrieb\ContactRepository;
use Lindenweg16\Modules\Betrieb\IsoWeek;
use Lindenweg16\Modules\Betrieb\Kpi;
use Lindenweg16\Modules\Betrieb\KpiMeter;
use Lindenweg16\Modules\Betrieb\MaintenanceRepository;
use Lindenweg16\Modules\Betrieb\OrderOpsRepository;
use Lindenweg16\Modules\Betrieb\ProcurementAlert;
use Lindenweg16\Modules\Betrieb\Reporting;
use Lindenweg16\Modules\Betrieb\StockLedger;
use Lindenweg16\Modules\Betrieb\StockRepository;
use Lindenweg16\Modules\Betrieb\TimeRepository;

/**
 * Baut die Dashboard-Ausgabe.
 */
class DashboardPage {

	/**
	 * Dashboard rendern.
	 */
	public static function render() {
		$today = gmdate( 'Y-m-d' );
		$von   = gmdate( 'Y-m-01' );
		$iso   = IsoWeek::from_date( $today );

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Betrieb', 'lindenweg16' ) . '</h1>';

		self::render_kpis( $von, $today );
		self::render_week( $iso );
		self::render_alerts();

		echo '</div>';
	}

	/**
	 * Kennzahlenkacheln des laufenden Monats.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 */
	protected static function render_kpis( $von, $bis ) {
		$metrics     = Reporting::metrics( $von, $bis );
		$definitions = Kpi::definitions();

		echo '<h2>' . esc_html__( 'Laufender Monat', 'lindenweg16' ) . '</h2>';
		echo '<div class="lw16-tiles">';

		foreach ( $definitions as $key => $definition ) {
			$value      = isset( $metrics[ $key ] ) ? $metrics[ $key ] : null;
			$evaluation = Kpi::evaluate( $key, $value );

			// Marken im Klartext unter dem Balken: Die Linie sagt wo, die
			// Zahl sagt wie viel. Beides zusammen, sonst muss man raten.
			$marken = array();

			if ( null !== $definition['target'] ) {
				/* translators: %s: Zielwert. */
				$marken[] = sprintf( __( 'Ziel %s', 'lindenweg16' ), Kpi::format( $key, $definition['target'] ) );
			}

			if ( null !== $definition['alarm'] ) {
				/* translators: %s: Alarmgrenze. */
				$marken[] = sprintf( __( 'Alarm %s', 'lindenweg16' ), Kpi::format( $key, $definition['alarm'] ) );
			}

			printf(
				'<div class="lw16-tile lw16-tile--%s"><span class="lw16-tile__label">%s</span><span class="lw16-tile__value">%s</span>%s%s</div>',
				esc_attr( $evaluation['level'] ),
				esc_html( $definition['label'] ),
				esc_html( Kpi::format( $key, $value ) ),
				KpiMeter::bar( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- erzeugte Auszeichnung.
					array(
						'level'  => $evaluation['level'],
						'value'  => $value,
						'target' => $definition['target'],
						'alarm'  => $definition['alarm'],
						'aria'   => $definition['label'] . ': ' . Kpi::format( $key, $value ) . '. ' . implode( ', ', $marken ),
					)
				),
				$marken ? '<span class="lw16-tile__target">' . esc_html( implode( ' · ', $marken ) ) . '</span>' : ''
			);
		}

		echo '</div>';

		echo KpiMeter::legend(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- feste Auszeichnung.
	}

	/**
	 * Laufende Woche: Auslastung und Stundenbudget.
	 *
	 * @param array $iso ISO-Woche.
	 */
	protected static function render_week( array $iso ) {
		$bounds   = IsoWeek::bounds( $iso['iso_year'], $iso['iso_week'] );
		$hours    = TimeRepository::hours_in_week( $iso['iso_year'], $iso['iso_week'] );
		$settings = CalcSettings::for_date();

		printf(
			'<h2>%s</h2>',
			esc_html( sprintf( /* translators: %s: Kalenderwoche. */ __( 'Diese Woche (%s)', 'lindenweg16' ), IsoWeek::label( $iso['iso_year'], $iso['iso_week'] ) ) )
		);

		$budget = 14.0;
		printf(
			'<p>%s <strong>%s h</strong> %s <strong>%s h</strong> %s</p>',
			esc_html__( 'Erfasste Arbeitszeit:', 'lindenweg16' ),
			esc_html( number_format_i18n( $hours, 1 ) ),
			esc_html__( 'von', 'lindenweg16' ),
			esc_html( number_format_i18n( $budget, 0 ) ),
			esc_html__( 'Wochenbudget', 'lindenweg16' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html( sprintf( /* translators: 1: von, 2: bis. */ __( 'Zeitraum %1$s bis %2$s', 'lindenweg16' ), $bounds['von'], $bounds['bis'] ) )
		);
	}

	/**
	 * Was aus dem Bedarf heraus zu fertigen oder zu bestellen ist.
	 *
	 * Nur die feste Seite – Bestellungen und Sollbestand. Wunschlisten
	 * gehören nicht in eine Alarmliste; sie sind ein Hinweis, keine
	 * Verpflichtung, und stehen deshalb nur auf der Bedarfsseite.
	 *
	 * @return array
	 */
	protected static function to_produce() {
		$plan    = DemandPlanner::plan( DemandPlanner::collect( false ), DemandPlanner::context() );
		$actions = DemandPlanner::action_labels();
		$out     = array();

		foreach ( DemandPlanner::actionable( $plan['articles'] ) as $row ) {
			$out[] = sprintf(
				/* translators: 1: Menge, 2: Artikelnummer, 3: Handlung. */
				__( '%1$s× %2$s – %3$s', 'lindenweg16' ),
				number_format_i18n( $row['net'], 0 ),
				$row['sku'],
				isset( $actions[ $row['action'] ] ) ? $actions[ $row['action'] ] : $row['action']
			);
		}

		return $out;
	}

	/**
	 * Alarme: Wartung, Bestände, Beschaffung, Wiedervorlagen, offene Posten.
	 */
	protected static function render_alerts() {
		echo '<h2>' . esc_html__( 'Was Aufmerksamkeit braucht', 'lindenweg16' ) . '</h2>';
		echo '<div class="lw16-alerts">';

		self::alert_block(
			__( 'Überfällige Wartungen', 'lindenweg16' ),
			array_map(
				static function ( $row ) {
					return sprintf(
						/* translators: 1: Gerät, 2: Grund. */
						__( '%1$s – %2$s', 'lindenweg16' ),
						$row['printer']['code'],
						implode( ', ', $row['reasons'] )
					);
				},
				array_filter(
					MaintenanceRepository::due(),
					static function ( $row ) {
						return ! empty( $row['reasons'] );
					}
				)
			),
			'lw16-betrieb-drucker'
		);

		self::alert_block(
			__( 'Beschaffungsalarm', 'lindenweg16' ),
			array_map(
				static function ( $alert ) {
					return sprintf(
						/* translators: 1: Position, 2: Reichweite. */
						__( '%1$s – Reichweite %2$s Tage', 'lindenweg16' ),
						$alert['label'],
						null === $alert['coverage_days'] ? '?' : number_format_i18n( $alert['coverage_days'], 0 )
					);
				},
				ProcurementAlert::all()
			),
			'lw16-betrieb-material'
		);

		self::alert_block( __( 'Artikel unter Sollbestand', 'lindenweg16' ), self::below_target(), 'lw16-betrieb-lager' );

		self::alert_block( __( 'Zu fertigen', 'lindenweg16' ), self::to_produce(), 'lw16-betrieb-bedarf' );

		self::alert_block(
			__( 'Fällige Wiedervorlagen', 'lindenweg16' ),
			array_map(
				static function ( $contact ) {
					return sprintf(
						/* translators: 1: Firma, 2: Datum. */
						__( '%1$s – seit %2$s', 'lindenweg16' ),
						$contact['company'],
						mysql2date( 'd.m.Y', $contact['follow_up_on'] )
					);
				},
				ContactRepository::due_follow_ups()
			),
			'lw16-betrieb-kontakte'
		);

		$overdue = array_filter(
			OrderOpsRepository::open_invoices(),
			array( OrderOpsRepository::class, 'is_overdue' )
		);

		self::alert_block(
			__( 'Überfällige Posten', 'lindenweg16' ),
			array_map(
				static function ( $order ) {
					return sprintf(
						/* translators: 1: Bestellung, 2: Betrag. */
						__( 'Bestellung %1$s – %2$s €', 'lindenweg16' ),
						$order['wc_order_id'] ? '#' . $order['wc_order_id'] : '#' . $order['id'],
						number_format_i18n( OrderOpsRepository::order_value( $order ), 2 )
					);
				},
				$overdue
			),
			'lw16-betrieb-bestellungen'
		);

		$unresolved = StockRepository::unresolved();
		self::alert_block(
			__( 'Bewegungen ohne Farbzuordnung', 'lindenweg16' ),
			$unresolved ? array(
				sprintf(
					/* translators: %d: Anzahl. */
					__( '%d Bewegung(en) als ungeklärt gebucht', 'lindenweg16' ),
					count( $unresolved )
				),
			) : array(),
			'lw16-betrieb-lager'
		);

		echo '</div>';
	}

	/**
	 * Artikel unter Sollbestand.
	 *
	 * @return array
	 */
	protected static function below_target() {
		$balances = StockLedger::balances( StockRepository::all() );
		$articles = ArticleRepository::all_by_id();
		$rows     = array();

		foreach ( StockRepository::targets() as $target ) {
			$balance = StockLedger::pick( $balances, (int) $target['article_id'], (string) $target['color'] );

			if ( $balance['gesamt'] >= (float) $target['target_qty'] ) {
				continue;
			}

			$article = isset( $articles[ (int) $target['article_id'] ] ) ? $articles[ (int) $target['article_id'] ] : null;

			$rows[] = sprintf(
				/* translators: 1: Artikel, 2: Farbe, 3: Ist, 4: Soll. */
				__( '%1$s %2$s: %3$s von %4$s', 'lindenweg16' ),
				$article ? $article['sku'] : '#' . $target['article_id'],
				ColorRegistry::label( $target['color'] ),
				number_format_i18n( $balance['gesamt'], 0 ),
				number_format_i18n( (float) $target['target_qty'], 0 )
			);
		}

		return $rows;
	}

	/**
	 * Einen Alarmblock ausgeben.
	 *
	 * @param string $title Titel.
	 * @param array  $items Einträge.
	 * @param string $page  Zielseite.
	 */
	protected static function alert_block( $title, array $items, $page ) {
		$items = array_values( array_filter( $items ) );

		printf(
			'<div class="lw16-alert lw16-alert--%s"><h3>%s <span class="lw16-ampel lw16-ampel--%s">%d</span></h3>',
			esc_attr( $items ? 'aktiv' : 'leer' ),
			esc_html( $title ),
			esc_attr( $items ? 'rot' : 'gruen' ),
			count( $items )
		);

		if ( $items ) {
			echo '<ul>';
			foreach ( array_slice( $items, 0, 6 ) as $item ) {
				printf( '<li>%s</li>', esc_html( $item ) );
			}
			echo '</ul>';
			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=' . $page ) ),
				esc_html__( 'Ansehen', 'lindenweg16' )
			);
		} else {
			printf( '<p>%s</p>', esc_html__( 'nichts offen', 'lindenweg16' ) );
		}

		echo '</div>';
	}
}
