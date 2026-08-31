<?php
/**
 * Quartalsziele lesen und schreiben.
 *
 * Ein Ziel gehört zu genau einem Quartal. Wer im vierten Quartal die Latte
 * höher legt, verändert damit nicht die Bewertung des dritten – deshalb je
 * Quartal eine eigene Zeile und kein Feld, das überschrieben wird.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Datenzugriff für die Ziele.
 */
class KpiGoalRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_kpi_goals';
	}

	/**
	 * Alle Ziele einer Kennzahl, nach Quartal.
	 *
	 * @param string $metric_key Kennzahl.
	 * @return array Quartalsschlüssel => {target, alarm}.
	 */
	public static function for_metric( $metric_key ) {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT quarter_key, target_value, alarm_value FROM {$table} WHERE metric_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $metric_key
			),
			ARRAY_A
		);

		return self::index( (array) $rows );
	}

	/**
	 * Alle Ziele eines Quartals, nach Kennzahl.
	 *
	 * @param string $quarter_key Quartal.
	 * @return array Kennzahl => {target, alarm}.
	 */
	public static function for_quarter( $quarter_key ) {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT metric_key, target_value, alarm_value FROM {$table} WHERE quarter_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $quarter_key
			),
			ARRAY_A
		);

		$out = array();

		foreach ( $rows as $row ) {
			$out[ (string) $row['metric_key'] ] = array(
				'target' => null === $row['target_value'] ? null : (float) $row['target_value'],
				'alarm'  => null === $row['alarm_value'] ? null : (float) $row['alarm_value'],
			);
		}

		return $out;
	}

	/**
	 * Ziele mehrerer Kennzahlen auf einmal.
	 *
	 * Der Verlaufsgraph braucht die Ziele aller sichtbaren Kennzahlen über
	 * alle gezeigten Quartale. Einzeln abgefragt wären das zwölf Abfragen je
	 * Seitenaufruf.
	 *
	 * @return array Kennzahl => Quartalsschlüssel => {target, alarm}.
	 */
	public static function all() {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results( "SELECT metric_key, quarter_key, target_value, alarm_value FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['metric_key'] ][ (string) $row['quarter_key'] ] = array(
				'target' => null === $row['target_value'] ? null : (float) $row['target_value'],
				'alarm'  => null === $row['alarm_value'] ? null : (float) $row['alarm_value'],
			);
		}

		return $out;
	}

	/**
	 * Ziele eines Quartals speichern.
	 *
	 * Eine Kennzahl ohne Ziel und ohne Alarm hat keine Zeile – das ist der
	 * Unterschied zwischen „kein Ziel gesetzt“ und „Ziel null“.
	 *
	 * Bearbeitet werden nur die übergebenen Kennzahlen. Das ist wichtig,
	 * sobald jemand das Formular ohne das Kostenrecht sieht: Die Zeilen in
	 * Euro fehlen dann im Absenden, und ohne diese Begrenzung würde ihr
	 * Fehlen als „Ziel gelöscht" gelesen.
	 *
	 * @param string $quarter_key Quartal.
	 * @param array  $goals       Kennzahl => {target, alarm}.
	 * @param array  $metrics     Kennzahlen, die bearbeitet werden dürfen.
	 * @return array {success, message}
	 */
	public static function save( $quarter_key, array $goals, array $metrics = array() ) {
		global $wpdb;

		$quarter = Period::quarter_bounds( $quarter_key );

		if ( ! $quarter ) {
			return array( 'success' => false, 'message' => __( 'Kein gültiges Quartal.', 'lindenweg16' ) );
		}

		$vorher = self::for_quarter( $quarter['key'] );
		$sauber = KpiGoals::sanitize( $goals );
		$jetzt  = current_time( 'mysql', true );
		$table  = self::table();

		$bearbeitbar = $metrics ? array_intersect( $metrics, array_keys( Kpi::definitions() ) ) : array_keys( Kpi::definitions() );

		foreach ( $bearbeitbar as $metric ) {
			$neu = isset( $sauber[ $metric ] ) ? $sauber[ $metric ] : null;

			if ( null === $neu ) {
				if ( isset( $vorher[ $metric ] ) ) {
					$wpdb->delete( $table, array( 'quarter_key' => $quarter['key'], 'metric_key' => $metric ) );
				}
				continue;
			}

			$daten = array(
				'quarter_key'  => $quarter['key'],
				'metric_key'   => $metric,
				'target_value' => $neu['target'],
				'alarm_value'  => $neu['alarm'],
				'updated_at'   => $jetzt,
			);

			if ( isset( $vorher[ $metric ] ) ) {
				$wpdb->update( $table, $daten, array( 'quarter_key' => $quarter['key'], 'metric_key' => $metric ) );
			} else {
				$daten['created_at'] = $jetzt;
				$daten['created_by'] = get_current_user_id();
				$wpdb->insert( $table, $daten );
			}
		}

		$nachher = self::for_quarter( $quarter['key'] );

		BetriebAudit::record(
			'update',
			'kpi_goals',
			0,
			$vorher,
			$nachher,
			sprintf(
				/* translators: %s: Quartal. */
				__( 'Ziele für %s gespeichert.', 'lindenweg16' ),
				$quarter['label']
			)
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: Quartal. */
				__( 'Ziele für %s gespeichert.', 'lindenweg16' ),
				$quarter['label']
			),
		);
	}

	/**
	 * Zeilen nach Quartal ordnen.
	 *
	 * @param array $rows Zeilen.
	 * @return array
	 */
	protected static function index( array $rows ) {
		$out = array();

		foreach ( $rows as $row ) {
			$out[ (string) $row['quarter_key'] ] = array(
				'target' => null === $row['target_value'] ? null : (float) $row['target_value'],
				'alarm'  => null === $row['alarm_value'] ? null : (float) $row['alarm_value'],
			);
		}

		return $out;
	}
}
