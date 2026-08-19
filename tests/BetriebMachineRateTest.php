<?php
/**
 * Maschinenstundensatz: Berechnung je Gerät und für die Flotte.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\MachineRate;

/**
 * Der Satz ist eine Rechengröße aus Gerätedaten und Betriebsannahmen.
 */
class BetriebMachineRateTest extends TestCase {

	/**
	 * Annahmen wie nach der Erstinstallation.
	 *
	 * @return array
	 */
	protected function settings() {
		return array(
			'printer_lifetime_h'     => 5000.0,
			'spare_parts_per_year'   => 68.0,
			'annual_operating_hours' => 3400.0,
			'printer_power_kw'       => 0.15,
			'power_price_per_kwh'    => 0.35,
			'workspace_cost_per_h'   => 0.0,
		);
	}

	/**
	 * Der Bambu P1S aus der Datenerfassungsmappe.
	 *
	 * @return array
	 */
	protected function p1s() {
		return array(
			'code'             => 'P1',
			'status'           => 'betrieb',
			'acquisition_cost' => 899.0,
		);
	}

	/**
	 * Abschreibung + Verschleiß + Energie, ohne Flächenkosten.
	 */
	public function test_satz_eines_geraets() {
		// 899 / 5000 = 0,1798 + 68 / 3400 = 0,02 + 0,15 * 0,35 = 0,0525 → 0,2523.
		$this->assertEqualsWithDelta(
			0.2523,
			MachineRate::for_printer( $this->p1s(), $this->settings() ),
			0.00001
		);
	}

	/**
	 * Die Aufschlüsselung summiert sich auf den Satz.
	 */
	public function test_aufschluesselung_summiert_sich() {
		$b = MachineRate::breakdown( $this->p1s(), $this->settings() );

		$this->assertEqualsWithDelta( 0.1798, $b['abschreibung'], 0.00001 );
		$this->assertEqualsWithDelta( 0.02, $b['verschleiss'], 0.00001 );
		$this->assertEqualsWithDelta( 0.0525, $b['energie'], 0.00001 );
		$this->assertEqualsWithDelta( 0.0, $b['flaeche'], 0.00001 );
		$this->assertEqualsWithDelta(
			MachineRate::for_printer( $this->p1s(), $this->settings() ),
			$b['summe'],
			0.00001
		);
	}

	/**
	 * Flächenkosten schlagen voll auf den Satz durch.
	 */
	public function test_flaechenkosten_gehen_ein() {
		$settings                         = $this->settings();
		$settings['workspace_cost_per_h'] = 0.05;

		$this->assertEqualsWithDelta(
			0.3023,
			MachineRate::for_printer( $this->p1s(), $settings ),
			0.00001
		);
	}

	/**
	 * Gerätewerte schlagen die globale Annahme – eine größere Maschine
	 * zieht mehr Strom und hält länger.
	 */
	public function test_geraetewert_schlaegt_globale_annahme() {
		$printer               = $this->p1s();
		$printer['lifetime_h'] = 10000.0;
		$printer['power_kw']   = 0.30;

		// 899 / 10000 = 0,0899 + 0,02 + 0,30 * 0,35 = 0,105 → 0,2149.
		$this->assertEqualsWithDelta(
			0.2149,
			MachineRate::for_printer( $printer, $this->settings() ),
			0.00001
		);
	}

	/**
	 * Direkt gemessene Stromkosten schlagen die Rechnung aus Leistung
	 * und Strompreis – das ist die Zahl vom Messgerät.
	 */
	public function test_stromkosten_je_stunde_schlagen_die_rechnung() {
		$printer                     = $this->p1s();
		$printer['power_kw']         = 0.15;
		$printer['power_cost_per_h'] = 0.08;

		$this->assertEqualsWithDelta( 0.08, MachineRate::energy_per_hour( $printer, $this->settings() ), 0.00001 );
		// 0,1798 + 0,02 + 0,08 = 0,2798.
		$this->assertEqualsWithDelta( 0.2798, MachineRate::for_printer( $printer, $this->settings() ), 0.00001 );
	}

	/**
	 * Stromkosten von 0 sind eine Aussage, kein fehlender Wert – sie dürfen
	 * nicht auf die Leistungsrechnung zurückfallen.
	 */
	public function test_stromkosten_null_faellt_nicht_zurueck() {
		$printer                     = $this->p1s();
		$printer['power_cost_per_h'] = 0.0;

		$this->assertEqualsWithDelta( 0.0, MachineRate::energy_per_hour( $printer, $this->settings() ), 0.00001 );
	}

	/**
	 * Ersatzteile werden als Jahresbetrag gepflegt und über die
	 * Jahresbetriebsstunden umgelegt.
	 */
	public function test_ersatzteile_werden_aus_dem_jahresbetrag_umgelegt() {
		$printer                         = $this->p1s();
		$printer['spare_parts_per_year'] = 340.0;
		$printer['annual_operating_hours'] = 1700.0;

		// 340 / 1700 = 0,20 je Stunde.
		$this->assertEqualsWithDelta( 0.20, MachineRate::wear_per_hour( $printer, $this->settings() ), 0.00001 );
	}

	/**
	 * Weniger Betriebsstunden bei gleichem Jahresbetrag heben den
	 * Stundenanteil – genau das soll die Umlage sichtbar machen.
	 */
	public function test_geringere_auslastung_erhoeht_den_verschleissanteil() {
		$viel  = $this->p1s();
		$wenig = $this->p1s();

		$viel['annual_operating_hours']  = 3400.0;
		$wenig['annual_operating_hours'] = 850.0;

		$this->assertGreaterThan(
			MachineRate::wear_per_hour( $viel, $this->settings() ),
			MachineRate::wear_per_hour( $wenig, $this->settings() )
		);
	}

	/**
	 * Ohne Jahresstunden keine Division durch null.
	 */
	public function test_jahresstunden_null_ergibt_keinen_verschleiss() {
		$settings                           = $this->settings();
		$settings['annual_operating_hours'] = 0.0;

		$this->assertEqualsWithDelta( 0.0, MachineRate::wear_per_hour( $this->p1s(), $settings ), 0.00001 );
	}

	/**
	 * Ohne Lebensdauer keine Division durch null.
	 */
	public function test_lebensdauer_null_ergibt_keine_abschreibung() {
		$settings                       = $this->settings();
		$settings['printer_lifetime_h'] = 0.0;

		$this->assertEqualsWithDelta(
			0.0725,
			MachineRate::for_printer( $this->p1s(), $settings ),
			0.00001
		);
	}

	/**
	 * Der Flottensatz ist der Mittelwert der produzierenden Geräte.
	 */
	public function test_flottenmittel() {
		$printers = array(
			$this->p1s(),
			array( 'code' => 'P2', 'status' => 'betrieb', 'acquisition_cost' => 1899.0 ),
		);

		// (0,2523 + 0,4523) / 2 = 0,3523.
		$result = MachineRate::fleet_breakdown( $printers, $this->settings() );
		$this->assertSame( 2, $result['printer_count'] );
		$this->assertEqualsWithDelta( 0.3523, $result['rate'], 0.00001 );
	}

	/**
	 * Geräte in Wartung zählen mit, defekte und Reserve nicht.
	 */
	public function test_nur_produzierende_geraete_zaehlen() {
		$printers = array(
			$this->p1s(),
			array( 'code' => 'P2', 'status' => 'wartung', 'acquisition_cost' => 899.0 ),
			array( 'code' => 'P3', 'status' => 'defekt', 'acquisition_cost' => 5000.0 ),
			array( 'code' => 'P4', 'status' => 'reserve', 'acquisition_cost' => 5000.0 ),
		);

		$result = MachineRate::fleet_breakdown( $printers, $this->settings() );
		$this->assertSame( 2, $result['printer_count'] );
		$this->assertEqualsWithDelta( 0.2523, $result['rate'], 0.00001 );
	}

	/**
	 * Ohne Geräte fehlt die Abschreibung. Der Satz ist dann sichtbar zu
	 * niedrig und printer_count meldet die Ursache.
	 */
	public function test_ohne_geraete_bleiben_nur_laufende_kosten() {
		$result = MachineRate::fleet_breakdown( array(), $this->settings() );

		$this->assertSame( 0, $result['printer_count'] );
		$this->assertEqualsWithDelta( 0.0725, $result['rate'], 0.00001 );
	}
}
