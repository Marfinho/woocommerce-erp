<?php
/**
 * Kalkulationsannahmen mit Gültigkeitsdatum.
 *
 * Jede Änderung erzeugt eine neue Zeile mit eigenem valid_from – Werte werden
 * nie überschrieben. Eine Kalkulation vom September 2026 lässt sich damit auch
 * 2028 noch mit den damaligen Annahmen nachrechnen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Db;

/**
 * Lesen, Schreiben und Auflösen der historisierten Kalkulationsannahmen.
 */
class CalcSettings {

	/**
	 * Startwerte. Gelten, solange für einen Schlüssel keine Zeile existiert.
	 */
	const DEFAULTS = array(
		'filament_price_per_kg'  => 22.0,
		'labor_rate_per_h'       => 25.0,
		// Eingangsgrößen des Maschinenstundensatzes. Der Satz selbst wird
		// aus den Gerätedaten berechnet (siehe MachineRate) und ist deshalb
		// bewusst keine eigene Annahme.
		'printer_lifetime_h'     => 5000.0,
		'spare_parts_per_year'   => 68.0,
		'annual_operating_hours' => 3380.0,
		'printer_power_kw'       => 0.15,
		'power_price_per_kwh'    => 0.35,
		'workspace_cost_per_h'   => 0.0,
		'payment_fee_pct'        => 0.0249,
		'payment_fee_fixed'      => 0.35,
		'packaging_cost'         => 0.75,
		'scrap_surcharge_pct'    => 0.05,
		'vat_rate'               => 0.0,
		'net_hours_per_printer'  => 65.0,
		'plate_change_minutes'   => 12.0,
		'operator_hours_per_week' => 20.0,
		'customer_acquisition_cost' => 12.0,
		'min_db2_warning'        => 4.0,
	);

	/**
	 * Beschreibung je Schlüssel für die Oberfläche.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'filament_price_per_kg'     => __( 'Filamentpreis (€/kg)', 'lindenweg16' ),
			'labor_rate_per_h'          => __( 'Kalkulatorischer Stundensatz (€/h)', 'lindenweg16' ),
			'printer_lifetime_h'        => __( 'Lebensdauer je Gerät (Betriebsstunden)', 'lindenweg16' ),
			'spare_parts_per_year'      => __( 'Ersatzteile und Verschleiß (€ je Gerät und Jahr)', 'lindenweg16' ),
			'annual_operating_hours'    => __( 'Geplante Betriebsstunden je Gerät und Jahr', 'lindenweg16' ),
			'printer_power_kw'          => __( 'Leistungsaufnahme je Gerät (kW)', 'lindenweg16' ),
			'power_price_per_kwh'       => __( 'Strompreis (€/kWh)', 'lindenweg16' ),
			'workspace_cost_per_h'      => __( 'Flächenkosten je Betriebsstunde (€/h)', 'lindenweg16' ),
			'payment_fee_pct'           => __( 'Zahlungsgebühr variabel (Anteil)', 'lindenweg16' ),
			'payment_fee_fixed'         => __( 'Zahlungsgebühr fix (€ je Transaktion)', 'lindenweg16' ),
			'packaging_cost'            => __( 'Verpackung + Beileger (€ je Bestellung)', 'lindenweg16' ),
			'scrap_surcharge_pct'       => __( 'Ausschusszuschlag (Anteil)', 'lindenweg16' ),
			'vat_rate'                  => __( 'Umsatzsteuersatz (Anteil)', 'lindenweg16' ),
			'net_hours_per_printer'     => __( 'Nettodruckstunden je Gerät und Woche', 'lindenweg16' ),
			'plate_change_minutes'      => __( 'Minuten je Plattenwechsel', 'lindenweg16' ),
			'operator_hours_per_week'   => __( 'Eigene Arbeitsstunden je Woche (Planung)', 'lindenweg16' ),
			'customer_acquisition_cost' => __( 'Kundengewinnungskosten Direktverkauf (€)', 'lindenweg16' ),
			'min_db2_warning'           => __( 'Warnschwelle DB II je Position (€)', 'lindenweg16' ),
		);
	}

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_calc_settings';
	}

	/**
	 * Werte zum Stichtag auflösen – reine Logik, ohne Datenbank.
	 *
	 * Je Schlüssel gewinnt die Zeile mit dem größten valid_from, das den
	 * Stichtag nicht überschreitet. Liegt für einen Schlüssel keine gültige
	 * Zeile vor, greift der Startwert.
	 *
	 * @param array  $rows     Zeilen mit setting_key, value_decimal, valid_from.
	 * @param string $stichtag Datum als Y-m-d.
	 * @return array Schlüssel => float.
	 */
	public static function resolve( array $rows, $stichtag ) {
		$best   = array();
		$values = self::DEFAULTS;

		foreach ( $rows as $row ) {
			$row  = (array) $row;
			$key  = isset( $row['setting_key'] ) ? (string) $row['setting_key'] : '';
			$from = isset( $row['valid_from'] ) ? substr( (string) $row['valid_from'], 0, 10 ) : '';

			if ( '' === $key || '' === $from || $from > $stichtag ) {
				continue;
			}
			// Bei gleichem valid_from gewinnt die zuletzt gelesene Zeile
			// (die Tabelle liefert nach id aufsteigend).
			if ( isset( $best[ $key ] ) && $best[ $key ] > $from ) {
				continue;
			}
			$best[ $key ]   = $from;
			$values[ $key ] = (float) $row['value_decimal'];
		}

		return $values;
	}

	/**
	 * Alle zum Stichtag gültigen Annahmen.
	 *
	 * @param string $stichtag Datum als Y-m-d. Leer = heute.
	 * @return array Schlüssel => float.
	 */
	public static function for_date( $stichtag = '' ) {
		global $wpdb;

		if ( '' === $stichtag ) {
			$stichtag = gmdate( 'Y-m-d' );
		}
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT setting_key, value_decimal, valid_from FROM {$table} WHERE valid_from <= %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$stichtag
			),
			ARRAY_A
		);

		return self::resolve( $rows ? $rows : array(), $stichtag );
	}

	/**
	 * Einzelnen Wert zum Stichtag holen.
	 *
	 * @param string $key      Schlüssel.
	 * @param string $stichtag Datum als Y-m-d.
	 * @return float
	 */
	public static function get( $key, $stichtag = '' ) {
		$all = self::for_date( $stichtag );
		return isset( $all[ $key ] ) ? (float) $all[ $key ] : 0.0;
	}

	/**
	 * Neuen Wert ab einem Datum festschreiben.
	 *
	 * Existiert für Schlüssel und Datum bereits eine Zeile, wird sie ersetzt
	 * (Tippfehler am selben Tag soll keine Karteileiche erzeugen); jede
	 * Änderung landet im Audit-Log.
	 *
	 * @param string $key        Schlüssel.
	 * @param float  $value      Wert.
	 * @param string $valid_from Gültig ab (Y-m-d).
	 * @param string $note       Begründung.
	 * @return bool
	 */
	public static function set( $key, $value, $valid_from, $note = '' ) {
		global $wpdb;

		$key = sanitize_key( $key );
		if ( '' === $key ) {
			return false;
		}
		$valid_from = self::sanitize_date( $valid_from );
		$table      = self::table();

		$before = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE setting_key = %s AND valid_from = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key,
				$valid_from
			),
			ARRAY_A
		);

		$data = array(
			'setting_key'   => $key,
			'value_decimal' => (float) $value,
			'valid_from'    => $valid_from,
			'note'          => $note ? sanitize_text_field( $note ) : null,
			'created_at'    => current_time( 'mysql', true ),
			'created_by'    => get_current_user_id(),
		);

		if ( $before ) {
			$ok = false !== $wpdb->update( $table, $data, array( 'id' => (int) $before['id'] ) );
			BetriebAudit::record( 'update', 'calc_settings', (int) $before['id'], $before, $data, $note );
		} else {
			$ok = false !== $wpdb->insert( $table, $data );
			BetriebAudit::record( 'create', 'calc_settings', (int) $wpdb->insert_id, null, $data, $note );
		}

		return $ok;
	}

	/**
	 * Verlauf eines Schlüssels, neueste zuerst.
	 *
	 * @param string $key Schlüssel.
	 * @return array
	 */
	public static function history( $key ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE setting_key = %s ORDER BY valid_from DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				sanitize_key( $key )
			),
			ARRAY_A
		);
	}

	/**
	 * Startwerte einmalig anlegen (beim Aktivieren/Upgrade).
	 */
	public static function seed_defaults() {
		global $wpdb;

		$table = self::table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count > 0 ) {
			return;
		}

		$now   = current_time( 'mysql', true );
		$start = gmdate( 'Y-m-d' );
		$nutzer = get_current_user_id();
		$zeilen = array();

		foreach ( self::DEFAULTS as $key => $value ) {
			$zeilen[] = array(
				'setting_key'   => $key,
				'value_decimal' => (float) $value,
				'valid_from'    => $start,
				'note'          => 'Startwert',
				'created_at'    => $now,
				'created_by'    => $nutzer,
			);
		}

		Db::insert_many( $table, $zeilen );
	}

	/**
	 * Standard-Rabattstaffel einmalig anlegen.
	 *
	 * Die Staffel gilt für alle Händler ohne Sondervereinbarung. Sie steht
	 * hier, weil die Kalkulationsansicht sie ab Meilenstein 2 braucht, um die
	 * Preisebenen nebeneinanderzustellen; gepflegt wird sie in Meilenstein 8.
	 */
	public static function seed_price_tiers() {
		global $wpdb;

		$table = $wpdb->prefix . 'lw16_price_tiers';
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count > 0 ) {
			return;
		}

		$now   = current_time( 'mysql', true );
		$start = gmdate( 'Y-m-d' );

		foreach ( array(
			array( 'Stufe 1', 150.0, 40.0 ),
			array( 'Stufe 2', 400.0, 45.0 ),
			array( 'Stufe 3', 800.0, 50.0 ),
		) as $tier ) {
			$wpdb->insert(
				$table,
				array(
					'tier_name'       => $tier[0],
					'min_order_value' => $tier[1],
					'discount_pct'    => $tier[2],
					'valid_from'      => $start,
					'note'            => 'Startwert',
					'created_at'      => $now,
					'created_by'      => get_current_user_id(),
				)
			);
		}
	}

	/**
	 * Datum auf Y-m-d normalisieren, Fallback heute.
	 *
	 * @param string $date Eingabe.
	 * @return string
	 */
	public static function sanitize_date( $date ) {
		$date = trim( (string) $date );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}
		return gmdate( 'Y-m-d' );
	}
}
