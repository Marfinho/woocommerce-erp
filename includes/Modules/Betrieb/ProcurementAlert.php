<?php
/**
 * Beschaffungsalarm.
 *
 * Geprüft wird für Zukaufartikel, Kleinteile und Verbrauchsmaterial:
 *
 *   Reichweite in Tagen = Bestand / Tagesverbrauch der letzten vier Wochen
 *   Alarm, wenn Reichweite < Lieferzeit + Sicherheitspuffer
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Ermittelt Positionen, die nachbestellt werden müssen.
 */
class ProcurementAlert {

	/**
	 * Betrachtungszeitraum für den Verbrauch in Tagen.
	 */
	const PERIOD_DAYS = 28;

	/**
	 * Reichweite in Tagen – reine Logik.
	 *
	 * @param float $stock       Bestand.
	 * @param float $consumption Verbrauch im Zeitraum.
	 * @param int   $period_days Länge des Zeitraums.
	 * @return float|null Null, wenn ohne Verbrauch keine Aussage möglich ist.
	 */
	public static function coverage_days( $stock, $consumption, $period_days = self::PERIOD_DAYS ) {
		$consumption = abs( (float) $consumption );

		if ( $consumption <= 0 || $period_days <= 0 ) {
			return null;
		}

		return (float) $stock / ( $consumption / (float) $period_days );
	}

	/**
	 * Löst diese Position Alarm aus? – reine Logik.
	 *
	 * Die Grenze ist einschließlich: Reichweite genau gleich Lieferzeit plus
	 * Puffer heißt bereits Alarm, denn beim Bestellen ist der Bestand dann
	 * exakt bei null, wenn die Lieferung eintrifft.
	 *
	 * @param float|null $coverage_days Reichweite.
	 * @param int        $lead_days     Lieferzeit.
	 * @param int        $safety_days   Sicherheitspuffer.
	 * @return bool
	 */
	public static function is_alarming( $coverage_days, $lead_days, $safety_days ) {
		$threshold = (int) $lead_days + (int) $safety_days;

		// Ohne Verbrauch gibt es keinen Alarm – aber auch keine Entwarnung.
		if ( null === $coverage_days ) {
			return false;
		}

		return $coverage_days <= $threshold;
	}

	/**
	 * Alle alarmierenden Positionen.
	 *
	 * @return array
	 */
	public static function all() {
		return array_merge( self::purchased_articles(), self::components(), self::supplies() );
	}

	/**
	 * Zukaufartikel.
	 *
	 * @return array
	 */
	public static function purchased_articles() {
		$alerts   = array();
		$sourcing = SourcingRepository::all_valid_at();
		// Einmal summieren statt je Artikel erneut: Die Bewegungstabelle ist
		// die größte des Moduls.
		$balances = StockLedger::balances( StockRepository::all() );

		foreach ( ArticleRepository::all() as $article ) {
			// Gedruckte Rohlinge und montierte Halbzeuge werden gefertigt,
			// nicht bestellt – sie gehören nicht in den Beschaffungsalarm.
			if ( in_array( $article['sourcing_type'], array( 'druck', 'montage' ), true ) ) {
				continue;
			}

			$id   = (int) $article['id'];
			$data = isset( $sourcing[ $id ] ) ? $sourcing[ $id ] : null;

			if ( ! $data ) {
				continue;
			}

			$stock = 0.0;
			if ( isset( $balances[ $id ] ) ) {
				foreach ( $balances[ $id ] as $locations ) {
					$stock += isset( $locations['lager'] ) ? (float) $locations['lager'] : 0.0;
				}
			}

			$consumption = StockRepository::consumption( $id, 4 );
			$coverage    = self::coverage_days( $stock, $consumption );
			$lead        = (int) $data['lead_time_days'];
			$safety      = 7;

			if ( ! self::is_alarming( $coverage, $lead, $safety ) ) {
				continue;
			}

			$alerts[] = array(
				'kind'           => 'artikel',
				'kind_label'     => __( 'Zukaufartikel', 'lindenweg16' ),
				'id'             => $id,
				'label'          => $article['sku'] . ' – ' . $article['name'],
				'stock'          => $stock,
				'daily'          => $consumption / self::PERIOD_DAYS,
				'coverage_days'  => $coverage,
				'threshold_days' => $lead + $safety,
				'order_qty'      => max( (float) $data['min_order_qty'], ceil( $consumption ) ),
			);
		}

		return $alerts;
	}

	/**
	 * Kleinteile.
	 *
	 * @return array
	 */
	public static function components() {
		$alerts = array();

		foreach ( ComponentRepository::all() as $component ) {
			$stock       = (float) $component['stock_qty'];
			$consumption = self::component_consumption( (int) $component['id'] );
			$coverage    = self::coverage_days( $stock, $consumption );
			$lead        = (int) $component['lead_time_days'];
			$safety      = (int) $component['safety_days'];

			if ( ! self::is_alarming( $coverage, $lead, $safety ) ) {
				continue;
			}

			$alerts[] = array(
				'kind'           => 'komponente',
				'kind_label'     => __( 'Kleinteil', 'lindenweg16' ),
				'id'             => (int) $component['id'],
				'label'          => $component['code'] . ' – ' . $component['name'],
				'stock'          => $stock,
				'daily'          => $consumption / self::PERIOD_DAYS,
				'coverage_days'  => $coverage,
				'threshold_days' => $lead + $safety,
				'order_qty'      => max( (float) $component['min_order_qty'], (float) $component['pack_qty'] ),
			);
		}

		return $alerts;
	}

	/**
	 * Verbrauchsmaterial.
	 *
	 * @return array
	 */
	public static function supplies() {
		$alerts = array();
		$orders = self::orders_per_day();

		foreach ( SupplyRepository::all() as $supply ) {
			$daily    = $orders * (float) $supply['consumption_per_order'];
			$coverage = $daily > 0 ? (float) $supply['stock_qty'] / $daily : null;
			$lead     = (int) $supply['lead_time_days'];
			$safety   = (int) $supply['safety_days'];

			if ( ! self::is_alarming( $coverage, $lead, $safety ) ) {
				continue;
			}

			$alerts[] = array(
				'kind'           => 'verbrauchsmaterial',
				'kind_label'     => __( 'Verbrauchsmaterial', 'lindenweg16' ),
				'id'             => (int) $supply['id'],
				'label'          => $supply['name'],
				'stock'          => (float) $supply['stock_qty'],
				'daily'          => $daily,
				'coverage_days'  => $coverage,
				'threshold_days' => $lead + $safety,
				'order_qty'      => (float) $supply['min_order_qty'],
			);
		}

		return $alerts;
	}

	/**
	 * Verbrauch eines Kleinteils aus dem Protokoll der letzten vier Wochen.
	 *
	 * Der Bestand wird beim Buchen fortgeschrieben; die verbrauchte Menge
	 * steht im Audit-Log. Das ist die einzige Stelle, an der der Verlauf
	 * eines Kleinteils vollständig vorliegt.
	 *
	 * @param int $component_id Komponente.
	 * @return float
	 */
	protected static function component_consumption( $component_id ) {
		global $wpdb;

		$table = BetriebAudit::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - self::PERIOD_DAYS * DAY_IN_SECONDS );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT payload_before_json, payload_after_json FROM {$table}
				 WHERE objekt_typ = 'components' AND objekt_id = %d
				 AND aktion = 'betrieb_update' AND erstellt_am >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $component_id,
				$since
			),
			ARRAY_A
		);

		$sum = 0.0;

		foreach ( (array) $rows as $row ) {
			$before = json_decode( (string) $row['payload_before_json'], true );
			$after  = json_decode( (string) $row['payload_after_json'], true );

			if ( ! isset( $before['stock_qty'], $after['stock_qty'] ) ) {
				continue;
			}

			$delta = (float) $before['stock_qty'] - (float) $after['stock_qty'];
			if ( $delta > 0 ) {
				$sum += $delta;
			}
		}

		return $sum;
	}

	/**
	 * Durchschnittliche Bestellungen je Tag der letzten vier Wochen.
	 *
	 * @return float
	 */
	protected static function orders_per_day() {
		global $wpdb;

		$table = OrderOpsRepository::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - self::PERIOD_DAYS * DAY_IN_SECONDS );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND order_status = 'bestaetigt'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			)
		);

		return $count / self::PERIOD_DAYS;
	}
}
