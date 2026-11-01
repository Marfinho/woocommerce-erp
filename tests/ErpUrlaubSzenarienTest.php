<?php
/**
 * Szenarien für Urlaub und Abwesenheit, ergänzend zu ErpAbwesenheitTest:
 * Ostern in Sonderjahren, Feiertage weiterer Bundesländer, halbe Tage an
 * Wochenende und Feiertag, Übertrag mit beantragtem Urlaub, Lohntexte.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Tests\Szenarien;

use Lindenweg16\Erp\Pages\Abwesenheit;
use PHPUnit\Framework\TestCase;

/**
 * Abwesenheit.
 */
class ErpUrlaubSzenarienTest extends TestCase {

	/**
	 * Abwesenheit.
	 *
	 * @param string $kind   Art.
	 * @param string $status Status.
	 * @param string $from   Von.
	 * @param string $to     Bis.
	 * @param float  $days   Tage.
	 * @param bool   $half   Halber Tag.
	 * @return array
	 */
	private function a( $kind, $status, $from, $to, $days = 0.0, $half = false ) {
		return array( 'kind' => $kind, 'status' => $status, 'date_from' => $from, 'date_to' => $to, 'days' => $days, 'half_day' => $half ? 1 : 0 );
	}

	/**
	 * Ostersonntage in Sonderjahren (Jahrhundertwechsel, sehr frühes und spätes Ostern).
	 *
	 * @return array
	 */
	public function easterProvider() {
		return array(
			array( 2000, '2000-04-23' ),
			array( 2008, '2008-03-23' ),
			array( 2019, '2019-04-21' ),
			array( 2038, '2038-04-25' ),
		);
	}

	/**
	 * @dataProvider easterProvider
	 *
	 * @param int    $year Jahr.
	 * @param string $date Ostersonntag.
	 */
	public function test_ostersonntag( $year, $date ) {
		$this->assertSame( $date, Abwesenheit::easter( $year ) );
	}

	public function test_feiertage_in_bayern() {
		$h = Abwesenheit::holidays( 2026, 'BY' );

		$this->assertArrayHasKey( '2026-01-06', $h );
		$this->assertSame( 'Fronleichnam', $h['2026-06-04'] );
		$this->assertArrayHasKey( '2026-11-01', $h );
		$this->assertArrayNotHasKey( '2026-10-31', $h );
		$this->assertCount( 12, $h );
	}

	public function test_buss_und_bettag_am_22_und_nur_in_sachsen() {
		$this->assertSame( 'Buß- und Bettag', Abwesenheit::holidays( 2028, 'SN' )['2028-11-22'], 'Fällt der 22. auf einen Mittwoch, ist es der 22.' );
		$this->assertArrayNotHasKey( '2026-11-18', Abwesenheit::holidays( 2026, 'BY' ) );
	}

	public function test_landesfeiertage_berlin_thueringen_saarland() {
		$be = Abwesenheit::holidays( 2026, 'BE' );
		$this->assertArrayHasKey( '2026-03-08', $be );
		$this->assertArrayNotHasKey( '2026-10-31', $be );

		$th = Abwesenheit::holidays( 2026, 'TH' );
		$this->assertArrayHasKey( '2026-09-20', $th );
		$this->assertArrayHasKey( '2026-10-31', $th );

		$this->assertArrayNotHasKey( '2026-06-04', Abwesenheit::holidays( 2026, 'NI' ) );
		$this->assertArrayHasKey( '2026-08-15', Abwesenheit::holidays( 2026, 'SL' ) );
	}

	public function test_jeder_bundeslandschluessel_liefert_mindestens_die_bundesweiten() {
		foreach ( array_keys( Abwesenheit::states() ) as $land ) {
			$this->assertGreaterThanOrEqual( 9, count( Abwesenheit::holidays( 2026, $land ) ), 'Land: ' . $land );
		}
	}

	public function test_jahreswechsel_mit_heilige_drei_koenige() {
		// 24.12.2026 (Do) bis 06.01.2027 (Mi): bundesweit 8, in Bayern 7 Arbeitstage.
		$this->assertSame( 8.0, Abwesenheit::workdays( '2026-12-24', '2027-01-06' ) );
		$this->assertSame( 7.0, Abwesenheit::workdays( '2026-12-24', '2027-01-06', 'BY' ) );
	}

	public function test_halber_tag_an_wochenende_feiertag_und_ueber_mehrere_tage() {
		$this->assertSame( 0.0, Abwesenheit::workdays( '2026-03-07', '2026-03-07', '', true ), 'Halber Samstag ist kein Urlaubstag.' );
		$this->assertSame( 0.0, Abwesenheit::workdays( '2026-05-01', '2026-05-01', '', true ), 'Halber Feiertag ist kein Urlaubstag.' );
		$this->assertSame( 2.0, Abwesenheit::workdays( '2026-03-04', '2026-03-05', '', true ), 'Halber Tag gilt nur für einen einzelnen Tag.' );
	}

	public function test_fehlendes_datum() {
		$this->assertSame( 0.0, Abwesenheit::workdays( '', '2026-03-01' ) );
		$this->assertSame( 0.0, Abwesenheit::workdays( '2026-03-01', '' ) );
	}

	public function test_urlaub_der_heute_beginnt_gilt_als_genommen() {
		$abs = array( $this->a( 'urlaub', 'genehmigt', '2026-03-01', '2026-03-03', 2 ) );
		$this->assertSame( 2.0, Abwesenheit::balance( 24, $abs, 2026, '2026-03-01' )['genommen'] );
	}

	public function test_am_verfallstag_selbst_verfaellt_noch_nichts() {
		$abs = array( $this->a( 'urlaub', 'genehmigt', '2026-02-16', '2026-02-18', 3 ) );
		$this->assertSame( 0.0, Abwesenheit::balance_with_carry( 24, 5, $abs, 2026, '2026-03-31', '03-31' )['verfallen'] );
	}

	public function test_negativer_uebertrag_zaehlt_nicht() {
		$neg = Abwesenheit::balance_with_carry( 24, -3, array(), 2026, '2026-12-31', '03-31' );
		$this->assertSame( 0.0, $neg['uebertrag'] );
		$this->assertSame( 24.0, $neg['rest'] );
	}

	public function test_beantragter_urlaub_vor_dem_verfall_schuetzt_den_uebertrag() {
		$abs = array( $this->a( 'urlaub', 'beantragt', '2026-03-20', '2026-03-27', 6 ) );
		$this->assertSame( 0.0, Abwesenheit::balance_with_carry( 24, 5, $abs, 2026, '2026-04-15', '03-31' )['verfallen'] );
	}

	public function test_lohnvorschlag_mit_feiertag_im_beschnittenen_zeitraum_und_text() {
		$abs   = array( $this->a( 'urlaub', 'genehmigt', '2026-03-30', '2026-04-03' ) );
		$lines = Abwesenheit::pay_lines( $abs, '2026-04-01', '2026-04-30', '', 8, 15.0 );

		// 01.–03.04.: Mi, Do, Karfreitag → 2 Tage.
		$this->assertSame( 2.0, $lines[0]['days'] );
		$this->assertSame( 16.0, $lines[0]['hours'] );
		$this->assertSame( 240.0, $lines[0]['amount'] );
		$this->assertSame( 'Urlaubsentgelt: 2 Tage à 8 h', $lines[0]['label'] );
	}

	public function test_lohnvorschlag_mit_krummen_stunden_und_halbem_tag_im_text() {
		$lines = Abwesenheit::pay_lines(
			array(
				$this->a( 'urlaub', 'genehmigt', '2026-04-06', '2026-04-10' ),
				$this->a( 'krank', 'genehmigt', '2026-04-14', '2026-04-14', 0, true ),
			),
			'2026-04-01',
			'2026-04-30',
			'',
			7.5,
			null
		);

		$this->assertSame( 4.0, $lines[0]['days'], 'Ostermontag frei.' );
		$this->assertSame( 30.0, $lines[0]['hours'] );
		$this->assertStringContainsString( '7,5 h', $lines[0]['label'] );
		$this->assertStringContainsString( '0,5 Tage', $lines[1]['label'] );
	}

	public function test_lohnvorschlag_fasst_gleiche_arten_zusammen() {
		$abs   = array(
			$this->a( 'urlaub', 'genehmigt', '2026-06-01', '2026-06-02' ),
			$this->a( 'urlaub', 'genehmigt', '2026-06-15', '2026-06-15' ),
		);
		$lines = Abwesenheit::pay_lines( $abs, '2026-06-01', '2026-06-30', '', 8, 10 );

		$this->assertCount( 1, $lines );
		$this->assertSame( 3.0, $lines[0]['days'] );
	}
}
