<?php
/**
 * Szenarien für die Grundbausteine der ERP-Seiten (Kit), ergänzend zu
 * ErpBankTest::test_kit_betraege_und_daten: weitere Schreibweisen von Beträgen
 * und Daten, Zeitumstellung, Nummernkreise, Einstellungen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Tests\Szenarien;

use Lindenweg16\Erp\Kit;
use PHPUnit\Framework\TestCase;

/**
 * Kit: Beträge, Daten, Nummernkreise, Einstellungen.
 */
class ErpKitSzenarienTest extends TestCase {

	/**
	 * Beträge, wie Menschen und Banken sie schreiben.
	 *
	 * @return array
	 */
	public function amountProvider() {
		return array(
			'englisch'                     => array( '1234.50', 1234.5 ),
			'nur Tausenderpunkt'           => array( '1.000', 1000.0 ),
			'Millionen mit Punkten'        => array( '1.234.567', 1234567.0 ),
			'Dezimalpunkt kurz'            => array( '1.5', 1.5 ),
			'Euro-Zeichen und Leerzeichen' => array( '  12,00 € ', 12.0 ),
			'EUR-Kürzel'                   => array( '99,90EUR', 99.9 ),
			'geschütztes Leerzeichen'      => array( "1\xc2\xa0234,00", 1234.0 ),
			'Pluszeichen'                  => array( '+5', 5.0 ),
			'nur Nachkommastellen'         => array( ',5', 0.5 ),
			'Ganzzahl'                     => array( 42, 42.0 ),
			'Float'                        => array( 3.25, 3.25 ),
			'Null als Text'                => array( '0', 0.0 ),
		);
	}

	/**
	 * @dataProvider amountProvider
	 *
	 * @param mixed $raw      Eingabe.
	 * @param float $expected Erwartet.
	 */
	public function test_betraege_werden_wie_geschrieben_gelesen( $raw, $expected ) {
		$this->assertEqualsWithDelta( $expected, Kit::amount( $raw ), 0.000001 );
	}

	/**
	 * Was keine Zahl ist, wird null – nie stillschweigend 0.
	 */
	public function test_unsinn_ergibt_null_statt_null_euro() {
		foreach ( array( '', '   ', '12a', '1e5', '-', '€', '1,2,3,4x', '--5' ) as $raw ) {
			$this->assertNull( Kit::amount( $raw ), 'Eingabe: ' . var_export( $raw, true ) );
		}
		$this->assertNull( Kit::amount( INF ) );
		$this->assertNull( Kit::amount( NAN ) );
	}

	public function test_daten_mit_uhrzeit_und_schalttag() {
		$this->assertSame( '2026-03-05', Kit::date( '2026-03-05 14:22:00' ) );
		$this->assertSame( '2028-02-29', Kit::date( '29.02.2028' ) );
	}

	public function test_unmoegliche_daten_werden_abgelehnt() {
		foreach ( array( '2026-02-30', '31.04.2026', '29.02.2027', '2026-13-01', '', 'gestern', '05/03/2026', '5.3.' ) as $raw ) {
			$this->assertSame( '', Kit::date( $raw ), 'Eingabe: ' . $raw );
		}
	}

	public function test_deutsche_darstellung_von_datum_und_betrag() {
		$this->assertSame( '05.03.2026', Kit::dmy( '2026-03-05' ) );
		$this->assertSame( '05.03.2026', Kit::dmy( '2026-03-05 10:00:00' ) );
		$this->assertSame( '', Kit::dmy( '' ) );
		$this->assertSame( '', Kit::dmy( '05.03.2026' ) );
		$this->assertSame( '1.234,50 €', Kit::eur( 1234.5 ) );
		$this->assertSame( '-0,99 €', Kit::eur( -0.99 ) );
		$this->assertSame( '0,00 €', Kit::eur( null ) );
	}

	public function test_tage_ueber_zeitumstellung_und_schaltjahr() {
		$this->assertSame( 1, Kit::days_between( '2026-03-28', '2026-03-29' ) );
		$this->assertSame( 1, Kit::days_between( '2026-10-24', '2026-10-25' ) );
		$this->assertSame( 2, Kit::days_between( '2028-02-28', '2028-03-01' ) );
		$this->assertSame( -10, Kit::days_between( '2026-01-11', '2026-01-01' ) );
		$this->assertSame( 365, Kit::days_between( '2026-01-01', '2027-01-01' ) );
	}

	public function test_fehlendes_datum_ergibt_null_tage_nicht_den_abstand_zu_heute() {
		$this->assertSame( 0, Kit::days_between( '', '2026-01-01' ) );
		$this->assertSame( 0, Kit::days_between( '2026-01-01', '' ) );
	}

	public function test_datum_plus_tage() {
		$this->assertSame( '2026-03-01', Kit::add_days( '2026-02-28', 1 ) );
		$this->assertSame( '2028-02-29', Kit::add_days( '2028-02-28', 1 ) );
		$this->assertSame( '2027-01-09', Kit::add_days( '2026-12-30', 10 ) );
		$this->assertSame( '2026-03-29', Kit::add_days( '2026-03-30 23:59:59', -1 ) );
	}

	public function test_nummernkreis_fremde_kuerzel_neues_jahr_und_ueberlauf() {
		$this->assertSame( 'AN-2027-0001', Kit::next_number( 'AN', 2027, array( 'AN-2026-0009' ) ) );
		$this->assertSame( 'BE-2026-0001', Kit::next_number( 'BE', 2026, array( 'AN-2026-0042', 'RK-2026-0007' ) ) );
		$this->assertSame( 'AN-2026-0004', Kit::next_number( 'AN', 2026, array( 'AN-2026-0003', 'AN-2026-0001' ) ), 'Unsortiert.' );
		$this->assertSame( 'AN-2026-10000', Kit::next_number( 'AN', 2026, array( 'AN-2026-9999' ) ) );
	}

	public function test_text_kuerzen() {
		$this->assertSame( 'Hallo Welt', Kit::cut( "  Hallo \n\t Welt ", 20 ) );
		$this->assertSame( 'Größe…', Kit::cut( 'Größenangabe', 6 ) );
		$this->assertSame( 6, mb_strlen( Kit::cut( 'Größenangabe', 6 ) ) );
		$this->assertSame( 'Äpfel', Kit::cut( 'Äpfel', 5 ) );
	}

	public function test_einstellungen_werden_typisiert_und_bereinigt() {
		$out = Kit::clean_settings(
			'mahn',
			array(
				'tage_stufe2'    => '21',
				'gebuehr_stufe2' => '7,50',
				'gebuehr_stufe3' => 'viel',
				'konto'          => 'DE12 <script>',
				'boese'          => 'x',
			),
			array( 'frist_tage' => 5 )
		);

		$this->assertSame( 21, $out['tage_stufe2'] );
		$this->assertSame( 7.5, $out['gebuehr_stufe2'] );
		$this->assertSame( 10.0, $out['gebuehr_stufe3'], 'Unlesbarer Betrag fällt auf die Vorgabe zurück.' );
		$this->assertSame( 5, $out['frist_tage'], 'Bisheriger Wert bleibt, wenn nichts Neues kommt.' );
		$this->assertSame( 14, $out['tage_stufe3'], 'Vorgabe, wenn weder neu noch bisher.' );
		$this->assertSame( 'DE12 script', $out['konto'] );
		$this->assertArrayNotHasKey( 'boese', $out );
		$this->assertSame( array(), Kit::clean_settings( 'gibtsnicht', array( 'a' => 1 ) ) );
	}
}
