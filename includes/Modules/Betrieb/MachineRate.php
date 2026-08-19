<?php
/**
 * Maschinenstundensatz – berechnet, nicht gepflegt.
 *
 * Der Satz ergibt sich aus den Gerätedaten und den Betriebsannahmen:
 *
 *   Satz je Stunde = Anschaffung / Lebensdauer   (Abschreibung)
 *                  + Ersatzteile je Stunde       (Verschleiß)
 *                  + Leistung kW * Strompreis    (Energie)
 *                  + Flächenkosten je Stunde     (Stellplatz)
 *
 * Die eigentliche Formel liegt bereits in Core\Calc und wird von der
 * Preiskalkulation genutzt; sie wird hier nicht dupliziert, sondern um die
 * Flächenkosten und die Auflösung je Gerät ergänzt.
 *
 * Ein Artikel ist keinem Gerät fest zugeordnet – für die Artikelkalkulation
 * gilt deshalb der Mittelwert der produzierenden Flotte. Wo ein Gerät bekannt
 * ist (Druckjob, Kapazitätsrechnung), wird dessen eigener Satz verwendet.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Calc;

/**
 * Berechnung des Maschinenstundensatzes je Gerät und für die Flotte.
 */
class MachineRate {

	/**
	 * Gerätestatus, die in den Flottenmittelwert eingehen.
	 *
	 * Geräte in Wartung zählen mit – sie sind Kapital und kommen zurück.
	 * Defekte und Reservegeräte produzieren nicht und würden den Satz
	 * verzerren.
	 */
	const PRODUCTIVE_STATUS = array( 'betrieb', 'wartung' );

	/**
	 * Satz eines einzelnen Geräts.
	 *
	 * Gerätefelder (lifetime_h, power_kw, spare_parts_per_h) überschreiben die
	 * globale Annahme, wenn sie gesetzt sind – eine große Maschine hat andere
	 * Werte als ein P1S.
	 *
	 * @param array $printer  Datensatz aus lw16_printers.
	 * @param array $settings Aufgelöste Annahmen zum Stichtag.
	 * @return float Euro je Betriebsstunde.
	 */
	public static function for_printer( array $printer, array $settings ) {
		$lifetime = self::pick( $printer, 'lifetime_h', $settings, 'printer_lifetime_h' );
		$spares   = self::pick( $printer, 'spare_parts_per_h', $settings, 'spare_parts_per_h' );
		$power    = self::pick( $printer, 'power_kw', $settings, 'printer_power_kw' );

		$rate = Calc::drucker_kosten_pro_stunde(
			isset( $printer['acquisition_cost'] ) ? (float) $printer['acquisition_cost'] : 0.0,
			$lifetime,
			$spares,
			$power,
			isset( $settings['power_price_per_kwh'] ) ? (float) $settings['power_price_per_kwh'] : 0.0
		);

		return $rate + ( isset( $settings['workspace_cost_per_h'] ) ? (float) $settings['workspace_cost_per_h'] : 0.0 );
	}

	/**
	 * Aufschlüsselung eines Gerätesatzes für die Anzeige.
	 *
	 * @param array $printer  Gerät.
	 * @param array $settings Annahmen.
	 * @return array Bestandteile und Summe.
	 */
	public static function breakdown( array $printer, array $settings ) {
		$lifetime = self::pick( $printer, 'lifetime_h', $settings, 'printer_lifetime_h' );
		$spares   = self::pick( $printer, 'spare_parts_per_h', $settings, 'spare_parts_per_h' );
		$power    = self::pick( $printer, 'power_kw', $settings, 'printer_power_kw' );
		$strom    = isset( $settings['power_price_per_kwh'] ) ? (float) $settings['power_price_per_kwh'] : 0.0;
		$flaeche  = isset( $settings['workspace_cost_per_h'] ) ? (float) $settings['workspace_cost_per_h'] : 0.0;

		$abschreibung = $lifetime > 0 && isset( $printer['acquisition_cost'] )
			? (float) $printer['acquisition_cost'] / $lifetime
			: 0.0;

		return array(
			'abschreibung' => $abschreibung,
			'verschleiss'  => $spares,
			'energie'      => $power * $strom,
			'flaeche'      => $flaeche,
			'summe'        => $abschreibung + $spares + ( $power * $strom ) + $flaeche,
		);
	}

	/**
	 * Mittelwert über die produzierende Flotte.
	 *
	 * Ohne erfasste Geräte gibt es keine Abschreibung – dann bleiben nur die
	 * laufenden Kosten übrig. Das ist absichtlich sichtbar zu niedrig; die
	 * Oberfläche weist über printer_count darauf hin, statt einen erfundenen
	 * Wert zu zeigen.
	 *
	 * @param array $printers Datensätze aus lw16_printers.
	 * @param array $settings Annahmen.
	 * @return float Euro je Betriebsstunde.
	 */
	public static function fleet_average( array $printers, array $settings ) {
		$result = self::fleet_breakdown( $printers, $settings );
		return $result['rate'];
	}

	/**
	 * Flottensatz inklusive Zählung der berücksichtigten Geräte.
	 *
	 * @param array $printers Geräte.
	 * @param array $settings Annahmen.
	 * @return array {rate, printer_count, per_printer}
	 */
	public static function fleet_breakdown( array $printers, array $settings ) {
		$rates = array();
		foreach ( $printers as $printer ) {
			$printer = (array) $printer;
			$status  = isset( $printer['status'] ) ? (string) $printer['status'] : 'betrieb';
			if ( ! in_array( $status, self::PRODUCTIVE_STATUS, true ) ) {
				continue;
			}
			$code           = isset( $printer['code'] ) ? (string) $printer['code'] : (string) count( $rates );
			$rates[ $code ] = self::for_printer( $printer, $settings );
		}

		if ( ! $rates ) {
			// Keine Geräte: laufende Kosten ohne Abschreibung.
			return array(
				'rate'          => self::for_printer( array( 'acquisition_cost' => 0 ), $settings ),
				'printer_count' => 0,
				'per_printer'   => array(),
			);
		}

		return array(
			'rate'          => array_sum( $rates ) / count( $rates ),
			'printer_count' => count( $rates ),
			'per_printer'   => $rates,
		);
	}

	/**
	 * Aktueller Flottensatz aus der Datenbank.
	 *
	 * @param string $stichtag Datum als Y-m-d.
	 * @return float
	 */
	public static function current( $stichtag = '' ) {
		return self::current_breakdown( $stichtag )['rate'];
	}

	/**
	 * Aktueller Flottensatz mit Aufschlüsselung.
	 *
	 * @param string $stichtag Datum als Y-m-d.
	 * @return array
	 */
	public static function current_breakdown( $stichtag = '' ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'lw16_printers';
		$printers = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::fleet_breakdown( $printers ? $printers : array(), CalcSettings::for_date( $stichtag ) );
	}

	/**
	 * Gerätewert bevorzugen, sonst globale Annahme.
	 *
	 * @param array  $printer      Gerät.
	 * @param string $printer_key  Feld am Gerät.
	 * @param array  $settings     Annahmen.
	 * @param string $setting_key  Schlüssel der Annahme.
	 * @return float
	 */
	protected static function pick( array $printer, $printer_key, array $settings, $setting_key ) {
		if ( isset( $printer[ $printer_key ] ) && '' !== $printer[ $printer_key ] && null !== $printer[ $printer_key ] ) {
			return (float) $printer[ $printer_key ];
		}
		return isset( $settings[ $setting_key ] ) ? (float) $settings[ $setting_key ] : 0.0;
	}
}
