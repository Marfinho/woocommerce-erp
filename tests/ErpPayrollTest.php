<?php
/**
 * Lohnabrechnung aus Zeiten: Rechnung, Zuschläge, Nummern, PDF, CSV.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Erp\Payroll;
use Lindenweg16\Erp\PayslipPdf;
use Lindenweg16\Erp\Payslips;

class ErpPayrollTest extends TestCase {

	protected function entries() {
		return array(
			array( 'created_by' => 5, 'entry_date' => '2026-10-02', 'hours' => 4, 'category' => 'produktion', 'description' => 'Platten' ),
			array( 'created_by' => 5, 'entry_date' => '2026-10-20', 'hours' => 6.5, 'category' => 'versand', 'description' => '' ),
			array( 'created_by' => 5, 'entry_date' => '2026-10-31', 'hours' => 2, 'category' => 'wartung', 'description' => '' ),
			array( 'created_by' => 5, 'entry_date' => '2026-09-30', 'hours' => 9, 'category' => 'produktion', 'description' => 'Vormonat' ),
			array( 'created_by' => 6, 'entry_date' => '2026-10-05', 'hours' => 8, 'category' => 'produktion', 'description' => 'Andere Person' ),
		);
	}

	protected function wages() {
		return array(
			array( 'user_id' => 5, 'rate_per_h' => '12.00', 'valid_from' => '2026-01-01' ),
			array( 'user_id' => 5, 'rate_per_h' => '13.50', 'valid_from' => '2026-10-15' ),
		);
	}

	public function test_monatsgrenzen() {
		$b = Payroll::month_bounds( '2026-02' );
		$this->assertSame( '2026-02-01', $b['von'] );
		$this->assertSame( '2026-02-28', $b['bis'] );
		$this->assertSame( 'Februar 2026', $b['label'] );
		$this->assertSame( '2028-02-29', Payroll::month_bounds( '2028-02' )['bis'] );
		$this->assertNull( Payroll::month_bounds( '2026-13' ) );
		$this->assertNull( Payroll::month_bounds( 'Oktober' ) );
	}

	public function test_nur_die_stunden_der_person_im_zeitraum_zaehlen() {
		$calc = Payroll::build( $this->entries(), $this->wages(), 5, '2026-10-01', '2026-10-31' );

		$this->assertEqualsWithDelta( 12.5, $calc['hours'], 0.001, 'Vormonat und andere Person zählen nicht.' );
		$this->assertCount( 3, $calc['days'] );
	}

	public function test_lohnaenderung_im_monat_gibt_zwei_zeilen_mit_je_eigenem_satz() {
		$calc = Payroll::build( $this->entries(), $this->wages(), 5, '2026-10-01', '2026-10-31' );

		$this->assertCount( 2, $calc['lines'] );
		$this->assertEqualsWithDelta( 12.0, $calc['lines'][0]['rate'], 0.001 );
		$this->assertEqualsWithDelta( 4.0, $calc['lines'][0]['hours'], 0.001 );
		$this->assertEqualsWithDelta( 48.0, $calc['lines'][0]['amount'], 0.001 );
		$this->assertEqualsWithDelta( 13.5, $calc['lines'][1]['rate'], 0.001 );
		$this->assertEqualsWithDelta( 8.5, $calc['lines'][1]['hours'], 0.001 );
		$this->assertEqualsWithDelta( 114.75, $calc['lines'][1]['amount'], 0.001 );
		$this->assertEqualsWithDelta( 162.75, $calc['gross'], 0.001 );
	}

	public function test_stunden_ohne_lohn_werden_gemeldet_nicht_mit_null_bewertet() {
		$calc = Payroll::build( $this->entries(), array( array( 'user_id' => 5, 'rate_per_h' => '12', 'valid_from' => '2026-10-10' ) ), 5, '2026-10-01', '2026-10-31' );

		$this->assertEqualsWithDelta( 4.0, $calc['unpriced_hours'], 0.001, 'Der 2.10. liegt vor dem ersten Stichtag.' );
		$this->assertEqualsWithDelta( 8.5 * 12, $calc['gross'], 0.001 );
		$this->assertEqualsWithDelta( 12.5, $calc['hours'], 0.001 );
	}

	public function test_zuschlaege_und_abzuege() {
		$adj = Payroll::adjustments( array( array( 'label' => 'Prämie', 'amount' => '50' ), array( 'label' => 'Vorschuss', 'amount' => '-25,50' ), array( 'label' => '', 'amount' => '' ) ) );

		$this->assertSame( '', $adj['error'] );
		$this->assertCount( 2, $adj['rows'], 'Leere Zeilen fallen weg.' );
		$this->assertEqualsWithDelta( 24.5, $adj['total'], 0.001 );
	}

	public function test_zuschlag_ohne_bezeichnung_oder_zahl_wird_benannt() {
		$this->assertStringContainsString( 'Bezeichnung', Payroll::adjustments( array( array( 'label' => '', 'amount' => '10' ) ) )['error'] );
		$this->assertStringContainsString( '„Prämie“', Payroll::adjustments( array( array( 'label' => 'Prämie', 'amount' => 'viel' ) ) )['error'] );
		$this->assertStringContainsString( '100.000', Payroll::adjustments( array( array( 'label' => 'X', 'amount' => '200000' ) ) )['error'] );
	}

	public function test_zahlen_mit_deutschem_komma() {
		$this->assertEqualsWithDelta( 1234.56, Payroll::number( '1.234,56' ), 0.0001 );
		$this->assertEqualsWithDelta( 12.5, Payroll::number( '12,5' ), 0.0001 );
		$this->assertEqualsWithDelta( 12.5, Payroll::number( '12.5' ), 0.0001 );
		$this->assertEqualsWithDelta( -3.0, Payroll::number( '-3' ), 0.0001 );
		$this->assertNull( Payroll::number( 'abc' ) );
		$this->assertNull( Payroll::number( '' ) );
	}

	public function test_zeitraeume_ueberschneiden_sich() {
		$this->assertTrue( Payroll::overlaps( '2026-10-01', '2026-10-31', '2026-10-31', '2026-11-30' ) );
		$this->assertFalse( Payroll::overlaps( '2026-10-01', '2026-10-31', '2026-11-01', '2026-11-30' ) );
	}

	public function test_nummer_und_pruefsumme() {
		$this->assertSame( 'LA-2026-0007', Payroll::number_text( 2026, 7 ) );

		$slip = array( 'user_id' => 5, 'von' => '2026-10-01', 'bis' => '2026-10-31', 'lines' => array(), 'days' => array(), 'adjustments' => array(), 'gross' => 100.0, 'total' => 100.0 );
		$a    = Payroll::checksum( $slip );
		$slip['total'] = 100.01;

		$this->assertSame( 64, strlen( $a ) );
		$this->assertNotSame( $a, Payroll::checksum( $slip ), 'Jede Änderung ändert die Prüfsumme.' );
	}

	public function test_csv_ist_deutsch_und_gegen_formeln_gesichert() {
		$line = Payroll::csv_line( array( 'LA-1', '=HYPERLINK("x")', 'Müller; Anna', '12,50', '-25,50' ) );

		$this->assertStringContainsString( '"\'=HYPERLINK(""x"")"', $line );
		$this->assertStringContainsString( '"Müller; Anna"', $line );
		$this->assertStringContainsString( '"-25,50"', $line, 'Minusbeträge bleiben Zahlen.' );
		$this->assertStringEndsWith( "\r\n", $line );
	}

	public function test_pdf_enthaelt_name_zeitraum_und_betrag() {
		$calc = Payroll::build( $this->entries(), $this->wages(), 5, '2026-10-01', '2026-10-31' );
		$slip = array(
			'status' => Payslips::FINAL, 'number' => 'LA-2026-0001', 'employee_name' => 'Anna Müller', 'period_von' => '2026-10-01', 'period_bis' => '2026-10-31',
			'paid_on' => null, 'days' => $calc['days'], 'lines' => $calc['lines'], 'hours' => $calc['hours'], 'gross' => $calc['gross'],
			'adjustments' => array( array( 'label' => 'Prämie', 'amount' => 50.0 ) ), 'total' => $calc['gross'] + 50, 'note' => '', 'checksum' => str_repeat( 'a', 64 ),
		);

		$pdf = PayslipPdf::build( $slip, 'Lindenweg16' );

		$this->assertStringStartsWith( '%PDF', $pdf );
		$this->assertStringContainsString( 'Lohnabrechnung', $pdf );
		$this->assertStringContainsString( 'LA-2026-0001', $pdf );
		$this->assertStringContainsString( '01.10.2026', $pdf );
		$this->assertStringContainsString( '212,75', $pdf, 'Gesamtbetrag 162,75 + 50.' );
		$this->assertStringContainsString( 'Lohnsteuer und Sozialversicherungsbeitr', $pdf, 'Der Hinweis steht auf jedem Beleg.' );
	}

	public function test_entwurf_und_storno_sind_im_pdf_kenntlich() {
		$base = array( 'number' => '', 'employee_name' => 'A', 'period_von' => '2026-10-01', 'period_bis' => '2026-10-31', 'paid_on' => null, 'days' => array(), 'lines' => array(), 'hours' => 0, 'gross' => 0, 'adjustments' => array(), 'total' => 0, 'note' => '', 'checksum' => '' );

		$this->assertStringContainsString( 'ENTWURF', PayslipPdf::build( $base + array( 'status' => Payslips::DRAFT ), 'X' ) );
		$this->assertStringContainsString( 'STORNIERT', PayslipPdf::build( array( 'number' => 'LA-1' ) + $base + array( 'status' => Payslips::VOID ), 'X' ) );
	}

	public function test_csv_listet_keine_stornierten_abrechnungen() {
		$row = array( 'number' => 'LA-1', 'status' => Payslips::FINAL, 'employee_name' => 'A', 'period_von' => '2026-10-01', 'period_bis' => '2026-10-31', 'hours' => 10.0, 'gross' => 120.0, 'adjustments_total' => 0.0, 'total' => 120.0, 'paid_on' => null, 'note' => '' );
		$csv = PayslipPdf::csv( array( $row, array( 'number' => 'LA-2', 'status' => Payslips::VOID ) + $row ) );

		$this->assertStringContainsString( 'LA-1', $csv );
		$this->assertStringNotContainsString( 'LA-2', $csv );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv, 'Excel braucht die UTF-8-Marke für Umlaute.' );
	}

	protected function read( $rel ) {
		return file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_routen_und_downloads_hängen_am_recht_loehne() {
		$lohn = $this->read( 'includes/Erp/Pages/Lohn.php' );
		$map  = $this->read( 'includes/Erp/ActionMap.php' );

		$this->assertStringContainsString( "\$api->needs( array( 'verwaltung.loehne' ) )", $lohn );
		$this->assertStringContainsString( "'lw16_erp_payslip_pdf'        => array( 'verwaltung.loehne' )", $map );
		$this->assertStringContainsString( "'lw16_erp_payslip_csv'        => array( 'verwaltung.loehne' )", $map );
		$this->assertStringContainsString( "check_admin_referer( 'lw16_erp_payslip_pdf_' . \$id )", $this->read( 'includes/Erp/PayslipPdf.php' ) );
	}

	public function test_festschreiben_verlangt_vollstaendige_loehne_und_vergibt_nummer_und_pruefsumme() {
		$src = $this->read( 'includes/Erp/Payslips.php' );

		$this->assertStringContainsString( "\$calc['unpriced_hours'] > 0", $src );
		$this->assertStringContainsString( 'Payroll::checksum( $full )', $src );
		$this->assertStringContainsString( "Nur ein Entwurf lässt sich ändern", $src );
		$this->assertStringContainsString( "Bitte einen Grund für die Stornierung", $src );
	}

	public function test_tabelle_und_version() {
		$this->assertStringContainsString( '{$p}payslips', $this->read( 'includes/Modules/Betrieb/Schema.php' ) );
		$this->assertStringContainsString( "const DB_VERSION = '38'", $this->read( 'includes/Core/Installer.php' ) );
	}

	public function test_oberflaeche_ist_von_loehne_aus_erreichbar() {
		$this->assertStringContainsString( "href: '#/lohn'", $this->read( 'assets/erp/pages/verwaltung.js' ) );
		$this->assertStringContainsString( "UI.routes['lohn']", $this->read( 'assets/erp/pages/lohn.js' ) );
	}

	public function test_grenze_ok_nah_ueber_und_keine() {
		$this->assertSame( 'keine', Payroll::limit_state( 500, null ) );
		$this->assertSame( 'ok', Payroll::limit_state( 400, 603 ) );
		$this->assertSame( 'nah', Payroll::limit_state( 543, 603 ), '90 % von 603 sind 542,70.' );
		$this->assertSame( 'nah', Payroll::limit_state( 603, 603 ), 'Genau auf der Grenze ist erlaubt.' );
		$this->assertSame( 'ueber', Payroll::limit_state( 603.01, 603 ) );
	}

	public function test_grenzen_aus_der_eingabe() {
		$ok = Payroll::limits_clean( array( 'gross_month' => '603', 'hours_month' => '43,5', 'gross_year' => '' ) );

		$this->assertSame( '', $ok['error'] );
		$this->assertEqualsWithDelta( 603.0, $ok['limits']['gross_month'], 0.001 );
		$this->assertEqualsWithDelta( 43.5, $ok['limits']['hours_month'], 0.001 );
		$this->assertNull( $ok['limits']['gross_year'], 'Leer heißt: keine Grenze.' );

		$this->assertStringContainsString( 'Verdienstgrenze je Monat', Payroll::limits_clean( array( 'gross_month' => 'viel' ) )['error'] );
		$this->assertStringContainsString( 'größer als null', Payroll::limits_clean( array( 'hours_month' => '-5' ) )['error'] );
		$this->assertSame( array( 'gross_month' => null, 'hours_month' => null, 'gross_year' => null ), Payroll::limits_clean( 'x' )['limits'] );
	}

	public function test_pruefungen_nennen_nur_gesetzte_grenzen_und_das_jahr() {
		$limits = array( 'gross_month' => 603.0, 'hours_month' => null, 'gross_year' => 7236.0 );
		$rows   = Payroll::checks( $limits, 650.0, 40.0, 7000.0 );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'ueber', $rows[0]['state'] );
		$this->assertSame( 'nah', $rows[1]['state'] );
		$this->assertStringContainsString( 'erlaubt sind 603,00 €', Payroll::over_text( $rows[0] ) );
	}

	public function test_festschreiben_fragt_bei_ueberschrittener_grenze_nach() {
		$src = $this->read( 'includes/Erp/Payslips.php' );

		$this->assertStringContainsString( "if ( \$over && ! \$force )", $src );
		$this->assertStringContainsString( "'needs_confirm'", $src );
		$this->assertStringContainsString( "(bool) \$request->get_param( 'force' )", $this->read( 'includes/Erp/Pages/Lohn.php' ) );
		$this->assertStringContainsString( '409', $this->read( 'includes/Erp/Pages/Lohn.php' ) );
	}
}
