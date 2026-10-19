<?php
/**
 * Filament im Workflow: Bedarf prüfen, Rolle zuordnen, Nachbestellung melden.
 *
 * Der Bestand an Filament steht in den Chargen (Rollen); sein Verbrauch wird
 * nicht gebucht, sondern aus den Druckjobs gerechnet, die einer Charge
 * zugeordnet sind (Teile mal Bauteilgewicht). Wird beim Melden keine Charge
 * gesetzt, verschwindet das Material aus der Rechnung – der Restbestand
 * stimmt nicht mehr und der Nachbestell-Alarm kommt zu spät. Deshalb ordnet
 * der Workflow jeder gedruckten Position eine Rolle zu: die älteste mit
 * genug Rest (FIFO), oder die, die der Mitarbeiter gewählt hat.
 *
 * Reine Rechenregeln stehen oben, Abfragen unten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\FilamentRepository;
use Lindenweg16\Modules\Betrieb\PrintJobRepository;
use Lindenweg16\Modules\Betrieb\ProcurementAlert;
use Lindenweg16\Modules\Betrieb\RevisionRepository;

/**
 * Materialrechnung des Workflows.
 */
class Material {

	/**
	 * Zwischenspeicher der Chargen innerhalb einer Anfrage.
	 *
	 * @var array|null
	 */
	protected static $lots_cache = null;

	/* ====================================================================
	 * Reine Regeln
	 * ================================================================== */

	/**
	 * Welche Rolle für diese Menge?
	 *
	 * Die älteste Rolle, auf der noch genug liegt. Reicht keine allein,
	 * die mit dem meisten Rest – dann ist der Verbrauch zumindest an der
	 * Rolle verbucht, die zuerst leer wird. Leere Rollen zählen nicht.
	 *
	 * @param array $lots Rollen: id, code, remaining_kg, received_on.
	 * @param float $kg   Benötigte Menge.
	 * @return array|null Die Rolle oder null.
	 */
	public static function pick_lot( array $lots, $kg ) {
		$usable = array_values(
			array_filter(
				$lots,
				static function ( $lot ) {
					return (float) $lot['remaining_kg'] > 0.0005;
				}
			)
		);

		if ( ! $usable ) {
			return null;
		}

		usort(
			$usable,
			static function ( $a, $b ) {
				$by_date = strcmp( (string) $a['received_on'], (string) $b['received_on'] );

				return 0 !== $by_date ? $by_date : (int) $a['id'] <=> (int) $b['id'];
			}
		);

		foreach ( $usable as $lot ) {
			if ( (float) $lot['remaining_kg'] + 0.0005 >= (float) $kg ) {
				return $lot;
			}
		}

		usort(
			$usable,
			static function ( $a, $b ) {
				return (float) $b['remaining_kg'] <=> (float) $a['remaining_kg'];
			}
		);

		return $usable[0];
	}

	/**
	 * Bedarf gegen Bestand je Farbe.
	 *
	 * @param array $needed    color => kg.
	 * @param array $available color => kg.
	 * @return array Liste von {color, needed_kg, available_kg, ok, missing_kg}.
	 */
	public static function compare( array $needed, array $available ) {
		$rows = array();

		foreach ( $needed as $color => $kg ) {
			$have      = isset( $available[ $color ] ) ? max( 0.0, (float) $available[ $color ] ) : 0.0;
			$missing   = max( 0.0, round( (float) $kg - $have, 3 ) );
			$rows[]    = array(
				'color'        => (string) $color,
				'needed_kg'    => round( (float) $kg, 3 ),
				'available_kg' => round( $have, 3 ),
				'ok'           => $missing <= 0.0005,
				'missing_kg'   => $missing,
			);
		}

		return $rows;
	}

	/**
	 * Zu bestellende Menge in ganzen Kilogramm.
	 *
	 * Bis über Lieferzeit und Puffer hinaus plus zwei Wochen Vorrat – eine
	 * Bestellung, die beim Eintreffen schon wieder knapp wäre, hilft nicht.
	 *
	 * @param float $daily_kg  Tagesverbrauch.
	 * @param int   $threshold Lieferzeit plus Puffer in Tagen.
	 * @return int
	 */
	public static function order_kg( $daily_kg, $threshold ) {
		return max( 1, (int) ceil( (float) $daily_kg * ( (int) $threshold + 14 ) ) );
	}

	/* ====================================================================
	 * Abfragen
	 * ================================================================== */

	/**
	 * Farbe einer Position – leer heißt Standardfarbe, wie im Druckjournal.
	 *
	 * @param string $color Farbe der Revision oder Position.
	 * @return string
	 */
	public static function color_of( $color ) {
		$slug = ColorRegistry::normalize( (string) $color );

		return '' !== $slug ? $slug : ColorRegistry::default_color();
	}

	/**
	 * Alle Rollen mit Restbestand.
	 *
	 * @return array Liste von {id, code, color, material, remaining_kg, received_on}.
	 */
	public static function lots() {
		if ( null !== self::$lots_cache ) {
			return self::$lots_cache;
		}

		$out = array();

		foreach ( FilamentRepository::all() as $lot ) {
			$out[] = array(
				'id'           => (int) $lot['id'],
				'code'         => (string) $lot['lot_code'],
				'color'        => self::color_of( $lot['color'] ),
				'material'     => (string) $lot['material'],
				'remaining_kg' => round( FilamentRepository::remaining_kg( $lot ), 3 ),
				'received_on'  => (string) $lot['received_on'],
			);
		}

		self::$lots_cache = $out;

		return $out;
	}

	/**
	 * Zwischenspeicher leeren – nach dem Buchen.
	 */
	public static function flush() {
		self::$lots_cache = null;
	}

	/**
	 * Rollen einer Farbe.
	 *
	 * @param string $color Farbe (Slug).
	 * @return array
	 */
	public static function lots_for( $color ) {
		return array_values(
			array_filter(
				self::lots(),
				static function ( $lot ) use ( $color ) {
					return $lot['color'] === $color;
				}
			)
		);
	}

	/**
	 * Bestand einer Farbe in Kilogramm.
	 *
	 * @param string $color Farbe (Slug).
	 * @return float
	 */
	public static function available_kg( $color ) {
		$sum = 0.0;

		foreach ( self::lots_for( $color ) as $lot ) {
			$sum += max( 0.0, $lot['remaining_kg'] );
		}

		return round( $sum, 3 );
	}

	/**
	 * Gewicht je Teil in Kilogramm.
	 *
	 * Dieselbe Revision wie im Druckjournal – nur so stimmen Planung und
	 * später gebuchter Verbrauch überein.
	 *
	 * @param int $article_id Artikel.
	 * @return float
	 */
	public static function weight_kg( $article_id ) {
		$revision = RevisionRepository::current_for_article( (int) $article_id );

		return $revision ? (float) $revision['weight_g'] / 1000 : 0.0;
	}

	/**
	 * Bedarf einer Platte oder Planung je Farbe.
	 *
	 * @param array $items Positionen: article_id, color, qty.
	 * @return array color => kg.
	 */
	public static function needs( array $items ) {
		$need = array();

		foreach ( $items as $item ) {
			$kg = self::weight_kg( $item['article_id'] ) * (float) $item['qty'];

			if ( $kg <= 0 ) {
				continue;
			}

			$color          = self::color_of( isset( $item['color'] ) ? $item['color'] : '' );
			$need[ $color ] = ( isset( $need[ $color ] ) ? $need[ $color ] : 0.0 ) + $kg;
		}

		return $need;
	}

	/**
	 * Reicht das Material für diese Positionen?
	 *
	 * @param array $items Positionen.
	 * @return array Zeilen aus compare(), mit label und Rollen.
	 */
	public static function check( array $items ) {
		$needed    = self::needs( $items );
		$available = array();

		foreach ( array_keys( $needed ) as $color ) {
			$available[ $color ] = self::available_kg( $color );
		}

		$rows = self::compare( $needed, $available );

		foreach ( $rows as $index => $row ) {
			$lots                  = self::lots_for( $row['color'] );
			$pick                  = self::pick_lot( $lots, $row['needed_kg'] );
			$rows[ $index ]['label']     = ColorRegistry::label( $row['color'] );
			$rows[ $index ]['lots']      = $lots;
			$rows[ $index ]['suggested'] = $pick ? $pick['id'] : 0;
		}

		return $rows;
	}

	/**
	 * Verbrauch der letzten Tage je Farbe in Kilogramm.
	 *
	 * @param int $days Zeitraum.
	 * @return array color => kg.
	 */
	public static function consumption_by_color( $days = ProcurementAlert::PERIOD_DAYS ) {
		global $wpdb;

		$jobs      = PrintJobRepository::table();
		$revisions = RevisionRepository::table();
		$since     = gmdate( 'Y-m-d', time() - (int) $days * DAY_IN_SECONDS );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT j.color AS color, COALESCE( SUM( j.parts_planned * r.weight_g ), 0 ) AS grams
				 FROM {$jobs} j
				 INNER JOIN {$revisions} r ON r.id = j.article_revision_id
				 WHERE j.job_date >= %s AND j.voided_at IS NULL
				 GROUP BY j.color", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$color         = self::color_of( $row['color'] );
			$out[ $color ] = ( isset( $out[ $color ] ) ? $out[ $color ] : 0.0 ) + (float) $row['grams'] / 1000;
		}

		return $out;
	}

	/**
	 * Filament, das knapp wird: Reichweite gegen Lieferzeit plus Puffer.
	 *
	 * Gleiche Rechnung und gleiche Form wie der Beschaffungsalarm für
	 * Kleinteile (ProcurementAlert), damit Dashboard, Materialseite und
	 * Wochenmail das Filament ohne Umbau mitzeigen.
	 *
	 * @return array
	 */
	public static function alerts() {
		$settings  = Workflow::settings();
		$lead      = (int) $settings['filament_lead_days'];
		$safety    = (int) $settings['filament_safety_days'];
		$used      = self::consumption_by_color();
		$alerts    = array();
		$colors    = array_unique( array_merge( array_keys( $used ), array_column( self::lots(), 'color' ) ) );

		foreach ( $colors as $color ) {
			$stock    = self::available_kg( $color );
			$daily    = isset( $used[ $color ] ) ? $used[ $color ] / ProcurementAlert::PERIOD_DAYS : 0.0;
			$coverage = ProcurementAlert::coverage_days( $stock, isset( $used[ $color ] ) ? $used[ $color ] : 0.0 );

			if ( ! ProcurementAlert::is_alarming( $coverage, $lead, $safety ) ) {
				continue;
			}

			$alerts[] = array(
				'kind'           => 'filament',
				'kind_label'     => __( 'Filament (kg)', 'lindenweg16' ),
				'id'             => 0,
				'color'          => $color,
				'label'          => sprintf( /* translators: %s: Farbe. */ __( 'Filament %s', 'lindenweg16' ), ColorRegistry::label( $color ) ),
				'stock'          => $stock,
				'daily'          => $daily,
				'coverage_days'  => $coverage,
				'threshold_days' => $lead + $safety,
				'order_qty'      => (float) self::order_kg( $daily, $lead + $safety ),
			);
		}

		return $alerts;
	}
}
