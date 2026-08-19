<?php
/**
 * Lädt die Daten für die Kalkulation einmal aus der Datenbank.
 *
 * Die Rechenklassen sind bewusst datenbankfrei. Diese Klasse ist die Brücke:
 * Sie lädt Artikel, Revisionen, Komponenten, Stückliste und Beschaffungsdaten
 * zum Stichtag in einen Kontext – eine Abfrage je Tabelle statt einer je
 * Stücklistenzeile.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Erzeugt Kontext und Annahmen für die Kalkulation.
 */
class CalculationContext {

	/**
	 * Kontext zum Stichtag bauen.
	 *
	 * @param string $stichtag Datum als Y-m-d. Leer = heute.
	 * @return array
	 */
	public static function build( $stichtag = '' ) {
		if ( '' === $stichtag ) {
			$stichtag = gmdate( 'Y-m-d' );
		}

		return array(
			'stichtag'   => $stichtag,
			'articles'   => ArticleRepository::all_by_id(),
			'revisions'  => RevisionRepository::active_by_article(),
			'components' => ComponentRepository::all_by_id(),
			'bom'        => BomRepository::all_by_parent(),
			'sourcing'   => SourcingRepository::all_valid_at( $stichtag ),
		);
	}

	/**
	 * Annahmen zum Stichtag inklusive des berechneten Maschinenstundensatzes.
	 *
	 * Der Satz ist keine gepflegte Annahme, sondern kommt aus den Gerätedaten.
	 * Er wird hier ergänzt, damit die Rechenklassen ihn wie jede andere
	 * Annahme lesen können.
	 *
	 * @param string $stichtag Datum als Y-m-d.
	 * @return array
	 */
	public static function settings( $stichtag = '' ) {
		$settings                       = CalcSettings::for_date( $stichtag );
		$settings['machine_rate_per_h'] = MachineRate::current( $stichtag );

		return $settings;
	}

	/**
	 * Kalkulation eines Artikels über alle Preisebenen.
	 *
	 * @param int    $article_id Artikel.
	 * @param string $stichtag   Stichtag.
	 * @return array
	 */
	public static function for_article( $article_id, $stichtag = '' ) {
		$ctx      = self::build( $stichtag );
		$settings = self::settings( $stichtag );

		$levels = array(
			'uvp'      => CostCalculator::contribution( $article_id, $ctx, $settings, 'uvp' ),
			'haendler' => CostCalculator::contribution( $article_id, $ctx, $settings, 'haendler' ),
		);

		// Zusätzlich jede Stufe der Standard-Rabattstaffel gegen den UVP.
		foreach ( self::price_tiers( $stichtag ) as $tier ) {
			$brutto = (float) $levels['uvp']['price_gross'] * ( 1 - (float) $tier['discount_pct'] / 100 );

			$levels[ 'tier_' . (int) $tier['id'] ] = CostCalculator::contribution(
				$article_id,
				$ctx,
				$settings,
				$tier['tier_name'],
				$brutto
			);
		}

		return array(
			'stichtag' => $ctx['stichtag'],
			'settings' => $settings,
			'levels'   => $levels,
		);
	}

	/**
	 * Zum Stichtag gültige Stufen der Standardstaffel.
	 *
	 * @param string $stichtag Stichtag.
	 * @return array
	 */
	public static function price_tiers( $stichtag = '' ) {
		global $wpdb;

		if ( '' === $stichtag ) {
			$stichtag = gmdate( 'Y-m-d' );
		}

		$table = $wpdb->prefix . 'lw16_price_tiers';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE valid_from <= %s AND ( valid_until IS NULL OR valid_until >= %s ) ORDER BY min_order_value ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$stichtag,
				$stichtag
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}
}
