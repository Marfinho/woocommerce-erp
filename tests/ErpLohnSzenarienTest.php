<?php
/**
 * Szenarien für die Lohnabrechnung, ergänzend zu ErpPayrollTest: leerer
 * Monat, Tausenderpunkt, Zeilenhöchstzahl, Rundungstoleranzen bei Grenzen
 * und Prüfsumme, weitere Formel-Zeichen im CSV.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Tests\Szenarien;

use Lindenweg16\Erp\Payroll;
use PHPUnit\Framework\TestCase;

/**
 * Payroll.
 */
class ErpLohnSzenarienTest extends TestCase {

	public function test_monatsangabe_ohne_fuehrende_null_oder_leer() {
		$this->assertNull( Payroll::month_bounds( '2026-1' ) );
		$this->assertNull( Payroll::month_bounds( '' ) );
		$this->assertSame( '2026-12-31', Payroll::month_bounds( '2026-12' )['bis'] );
	}

	public function test_leerer_monat() {
		$slip = Payroll::build( array(), array(), 7, '2026-03-01', '2026-03-31' );
		$this->assertSame( 0.0, $slip['gross'] );
		$this->assertSame( 0.0, $slip['hours'] );
		$this->assertSame( array(), $slip['lines'] );
	}

	public function test_bezahlte_und_unbezahlte_stunden_getrennt() {
		$wages = array( array( 'user_id' => 7, 'rate_per_h' => 12.0, 'valid_from' => '2026-03-10' ) );
		$e     = array(
			array( 'entry_date' => '2026-03-05', 'hours' => 3, 'created_by' => 7 ),
			array( 'entry_date' => '2026-03-12', 'hours' => 2, 'created_by' => 7 ),
		);
		$slip  = Payroll::build( $e, $wages, 7, '2026-03-01', '2026-03-31' );

		$this->assertSame( 2.0, $slip['priced_hours'] );
		$this->assertNull( $slip['days'][0]['rate'] );
		$this->assertSame( '', $slip['days'][0]['category'], 'Fehlende Felder werden leer.' );
	}

	public function test_bezeichnung_wird_getrimmt_und_null_betrag_ohne_text_faellt_weg() {
		$res = Payroll::adjustments(
			array(
				array( 'label' => '  Vorschuss  ', 'amount' => '-100' ),
				array( 'label' => '', 'amount' => '0' ),
			)
		);

		$this->assertSame( array( array( 'label' => 'Vorschuss', 'amount' => -100.0 ) ), $res['rows'] );
	}

	public function test_zuschlag_mit_tausenderpunkt() {
		// Wie überall im ERP (Kit::amount): „1.000“ sind tausend Euro, nicht ein Euro.
		$res = Payroll::adjustments( array( array( 'label' => 'Prämie', 'amount' => '1.000' ) ) );
		$this->assertSame( 1000.0, $res['total'] );
	}

	public function test_hoechstens_zwanzig_zeilen_und_kaputte_eingabe() {
		$this->assertNotSame( '', Payroll::adjustments( array_fill( 0, 21, array( 'label' => 'x', 'amount' => 1 ) ) )['error'] );
		$this->assertSame( '', Payroll::adjustments( array_fill( 0, 20, array( 'label' => 'x', 'amount' => 1 ) ) )['error'] );
		$this->assertSame( array( 'rows' => array(), 'total' => 0.0, 'error' => '' ), Payroll::adjustments( 'kaputt' ) );
	}

	public function test_bezeichnung_ohne_html_und_gekuerzt() {
		$res = Payroll::adjustments( array( array( 'label' => '<b>Bonus</b> ' . str_repeat( 'x', 100 ), 'amount' => 1 ) ) );
		$this->assertStringStartsWith( 'Bonus x', $res['rows'][0]['label'] );
		$this->assertSame( 80, mb_strlen( $res['rows'][0]['label'] ) );
	}

	public function test_grenze_null_ist_keine_grenze() {
		$this->assertNotSame( '', Payroll::limits_clean( array( 'gross_month' => '0' ) )['error'] );
		$this->assertSame( 'keine', Payroll::limit_state( 100, 0 ) );
	}

	public function test_grenze_mit_rundungstoleranz() {
		$this->assertSame( 'nah', Payroll::limit_state( 603.004, 603 ), 'Unter einem halben Cent drüber zählt nicht.' );
	}

	public function test_stundengrenze_als_text() {
		$hours = Payroll::checks( array( 'hours_month' => 40.0 ), 0, 41.5, 0 );
		$this->assertSame( 'Stunden im Monat: 41,50 h, erlaubt sind 40,00 h', Payroll::over_text( $hours[0] ) );
	}

	public function test_pruefsumme_stabil_und_auf_den_cent() {
		$slip = array(
			'user_id'     => 7,
			'von'         => '2026-03-01',
			'bis'         => '2026-03-31',
			'lines'       => array( array( 'rate' => 13.0, 'hours' => 10.0, 'amount' => 130.0 ) ),
			'days'        => array(),
			'adjustments' => array(),
			'gross'       => 130.0,
			'total'       => 130.0,
		);

		$sum = Payroll::checksum( $slip );
		$this->assertSame( $sum, Payroll::checksum( $slip ) );
		$this->assertSame( $sum, Payroll::checksum( array_merge( $slip, array( 'gross' => 130.001 ) ) ), 'Unter einem Cent zählt nicht.' );

		foreach ( array( 'user_id' => 8, 'bis' => '2026-03-30', 'adjustments' => array( array( 'label' => 'x', 'amount' => 1 ) ) ) as $key => $value ) {
			$this->assertNotSame( $sum, Payroll::checksum( array_merge( $slip, array( $key => $value ) ) ), 'Geändert: ' . $key );
		}
	}

	public function test_csv_sichert_auch_plus_at_und_minus_text_ab() {
		$line = Payroll::csv_line( array( '+1', '@SUM(A1)', '-5', '-cmd', '"Zitat"' ) );
		$this->assertSame( '"\'+1";"\'@SUM(A1)";"-5";"\'-cmd";"""Zitat"""' . "\r\n", $line );
	}

	public function test_deutsche_formate() {
		$this->assertSame( '1.234,50 €', Payroll::eur( 1234.5 ) );
		$this->assertSame( '7,25', Payroll::hours_text( 7.25 ) );
		$this->assertSame( '21.10.2026', Payroll::dmy( '2026-10-21 08:00:00' ) );
		$this->assertSame( 'kein Datum', Payroll::dmy( 'kein Datum' ) );
	}
}
