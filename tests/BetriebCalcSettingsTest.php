<?php
/**
 * Auflösung der historisierten Kalkulationsannahmen zum Stichtag.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\CalcSettings;

/**
 * Prüft, dass eine Kalkulation von gestern morgen noch dieselben Zahlen liefert.
 */
class BetriebCalcSettingsTest extends TestCase {

	/**
	 * Zeilen für die Tests.
	 *
	 * @return array
	 */
	protected function rows() {
		return array(
			array(
				'setting_key'   => 'filament_price_per_kg',
				'value_decimal' => 22.0,
				'valid_from'    => '2026-09-01',
			),
			array(
				'setting_key'   => 'filament_price_per_kg',
				'value_decimal' => 24.5,
				'valid_from'    => '2027-01-15',
			),
			array(
				'setting_key'   => 'labor_rate_per_h',
				'value_decimal' => 28.0,
				'valid_from'    => '2027-03-01',
			),
		);
	}

	/**
	 * Ohne Zeilen greifen die Startwerte.
	 */
	public function test_defaults_gelten_ohne_zeilen() {
		$values = CalcSettings::resolve( array(), '2026-09-01' );
		$this->assertSame( 22.0, $values['filament_price_per_kg'] );
		$this->assertSame( 0.05, $values['scrap_surcharge_pct'] );
		$this->assertSame( 0.0, $values['vat_rate'] );
	}

	/**
	 * Der Maschinenstundensatz ist keine Annahme, sondern eine Rechengröße
	 * aus den Gerätedaten. Nur seine Eingangsgrößen werden gepflegt.
	 */
	public function test_maschinensatz_ist_keine_annahme() {
		$values = CalcSettings::resolve( array(), '2026-09-01' );

		$this->assertArrayNotHasKey( 'machine_rate_per_h', $values );
		$this->assertSame( 5000.0, $values['printer_lifetime_h'] );
		$this->assertSame( 68.0, $values['spare_parts_per_year'] );
		$this->assertSame( 3380.0, $values['annual_operating_hours'] );
		$this->assertSame( 0.15, $values['printer_power_kw'] );
		$this->assertSame( 0.35, $values['power_price_per_kwh'] );
		$this->assertSame( 0.0, $values['workspace_cost_per_h'] );
	}

	/**
	 * Vor dem ersten Gültigkeitsdatum bleibt der Startwert stehen.
	 */
	public function test_wert_vor_gueltigkeit_wird_ignoriert() {
		$values = CalcSettings::resolve( $this->rows(), '2026-08-31' );
		$this->assertSame( 22.0, $values['filament_price_per_kg'] );
		$this->assertSame( 25.0, $values['labor_rate_per_h'], 'Startwert, weil die Zeile erst 2027 gilt.' );
	}

	/**
	 * Am Stichtag selbst gilt der neue Wert bereits.
	 */
	public function test_stichtag_ist_einschliesslich() {
		$values = CalcSettings::resolve( $this->rows(), '2027-01-15' );
		$this->assertSame( 24.5, $values['filament_price_per_kg'] );
	}

	/**
	 * Einen Tag davor gilt noch der alte Wert.
	 */
	public function test_tag_vor_stichtag_liefert_alten_wert() {
		$values = CalcSettings::resolve( $this->rows(), '2027-01-14' );
		$this->assertSame( 22.0, $values['filament_price_per_kg'] );
	}

	/**
	 * Der Kern der Historisierung: eine Kalkulation aus 2026 rechnet auch
	 * 2028 noch mit den Annahmen von 2026.
	 */
	public function test_rueckwirkende_kalkulation_bleibt_stabil() {
		$damals = CalcSettings::resolve( $this->rows(), '2026-10-01' );
		$heute  = CalcSettings::resolve( $this->rows(), '2028-06-01' );

		$this->assertSame( 22.0, $damals['filament_price_per_kg'] );
		$this->assertSame( 24.5, $heute['filament_price_per_kg'] );
		$this->assertSame( 25.0, $damals['labor_rate_per_h'] );
		$this->assertSame( 28.0, $heute['labor_rate_per_h'] );
	}

	/**
	 * Datumsspalten kommen aus MySQL teils als DATETIME zurück.
	 */
	public function test_datetime_spalte_wird_akzeptiert() {
		$rows   = array(
			array(
				'setting_key'   => 'packaging_cost',
				'value_decimal' => 0.95,
				'valid_from'    => '2026-09-01 00:00:00',
			),
		);
		$values = CalcSettings::resolve( $rows, '2026-09-01' );
		$this->assertSame( 0.95, $values['packaging_cost'] );
	}

	/**
	 * Mehrere Zeilen am selben Tag: die zuletzt gelesene gewinnt
	 * (die Abfrage sortiert nach id aufsteigend, also die neueste Korrektur).
	 */
	public function test_gleiches_datum_letzte_zeile_gewinnt() {
		$rows   = array(
			array( 'setting_key' => 'packaging_cost', 'value_decimal' => 0.75, 'valid_from' => '2026-09-01' ),
			array( 'setting_key' => 'packaging_cost', 'value_decimal' => 0.85, 'valid_from' => '2026-09-01' ),
		);
		$values = CalcSettings::resolve( $rows, '2026-09-05' );
		$this->assertSame( 0.85, $values['packaging_cost'] );
	}
}
