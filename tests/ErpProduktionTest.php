<?php
/**
 * Fertigungsseiten (ERP): reine Regeln und Verdrahtung.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Produktion;
use PHPUnit\Framework\TestCase;

class ErpProduktionTest extends TestCase {

	private function job( $date, $planned, $ok, $voided = false ) {
		return array( 'job_date' => $date, 'parts_planned' => $planned, 'parts_ok' => $ok, 'print_time_h' => 2, 'plate_changes' => 1, 'voided_at' => $voided ? '2026-01-01 00:00:00' : null );
	}

	private function src( $path ) {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $path );
	}

	public function test_datum_wird_echt_geprueft() {
		$this->assertTrue( Produktion::valid_date( '2026-02-28' ) );
		$this->assertFalse( Produktion::valid_date( '2026-02-30' ) );
		$this->assertFalse( Produktion::valid_date( '28.02.2026' ) );
		$this->assertFalse( Produktion::valid_date( null ) );
	}

	public function test_zeitraum_tauscht_vertauschte_grenzen_und_verwirft_unsinn() {
		$this->assertSame( array( '2026-01-01', '2026-02-01' ), Produktion::normalize_range( '2026-02-01', '2026-01-01' ) );
		$this->assertSame( array( '', '2026-02-01' ), Produktion::normalize_range( 'gestern', '2026-02-01' ) );
	}

	public function test_filter_zeitraum_und_ausschuss() {
		$jobs = array( $this->job( '2026-01-05', 10, 10 ), $this->job( '2026-01-10', 10, 8 ), $this->job( '2026-02-01', 10, 5 ), $this->job( '2026-01-11', 10, 1, true ) );

		$range = Produktion::filter_jobs( $jobs, array( 'von' => '2026-01-06', 'bis' => '2026-01-31' ) );
		$this->assertCount( 2, $range );

		$scrap = Produktion::filter_jobs( $jobs, array( 'ausschuss' => true ) );
		$this->assertCount( 2, $scrap, 'Storniert zählt nicht als Ausschuss' );
	}

	public function test_summen_ueberspringen_stornierte() {
		$s = Produktion::summarize( array( $this->job( '2026-01-05', 10, 9 ), $this->job( '2026-01-06', 10, 10 ), $this->job( '2026-01-07', 100, 0, true ) ) );

		$this->assertSame( 2, $s['jobs'] );
		$this->assertEquals( 20, $s['planned'] );
		$this->assertEquals( 1, $s['scrap'] );
		$this->assertEqualsWithDelta( 0.05, $s['scrap_rate'], 0.0001 );

		$leer = Produktion::summarize( array() );
		$this->assertSame( 0.0, $leer['scrap_rate'] );
	}

	public function test_ampel_der_ausschussquote() {
		$this->assertSame( 'gruen', Produktion::scrap_level( 6 ) );
		$this->assertSame( 'gelb', Produktion::scrap_level( 6.1 ) );
		$this->assertSame( 'gelb', Produktion::scrap_level( 10 ) );
		$this->assertSame( 'rot', Produktion::scrap_level( 10.1 ) );
	}

	public function test_doppeltes_absenden_hat_denselben_schluessel() {
		$a = array( 'printer_id' => 1, 'parts_ok' => '5' );
		$b = array( 'parts_ok' => '5', 'printer_id' => 1 );

		$this->assertSame( Produktion::dedupe_key( 3, $a ), Produktion::dedupe_key( 3, $b ) );
		$this->assertNotSame( Produktion::dedupe_key( 3, $a ), Produktion::dedupe_key( 4, $a ) );
	}

	public function test_wartung_lehnt_unbekanntes_intervall_und_datum_ab() {
		$ok = Produktion::maintenance_input( array( 'printer_id' => '4', 'interval_type' => 'monatlich', 'performed_on' => '2026-03-01', 'tasks' => array( 'achsen', 5 ), 'nozzle_changed' => 'true' ), array( 'woechentlich', 'monatlich' ) );
		$this->assertSame( array(), $ok['errors'] );
		$this->assertSame( 4, $ok['input']['printer_id'] );
		$this->assertTrue( $ok['input']['nozzle_changed'] );
		$this->assertSame( array( 'achsen', '5' ), $ok['input']['tasks'] );

		$bad = Produktion::maintenance_input( array( 'interval_type' => 'taeglich', 'performed_on' => '31.12.2026' ), array( 'woechentlich' ) );
		$this->assertCount( 2, $bad['errors'] );
		$this->assertFalse( $bad['input']['nozzle_changed'] );
	}

	public function test_geraet_zusammenfuehren_behaelt_nicht_gesendete_felder() {
		$existing = array( 'code' => 'P1', 'acquisition_cost' => '399.00', 'hours_at_last_nozzle_change' => '120', 'notes' => null );
		$merged   = Produktion::merge_device( $existing, array( 'status' => 'defekt', 'code' => 'P2', 'evil' => 'x', 'model' => array( 'a' ) ) );

		$this->assertSame( 'P2', $merged['code'] );
		$this->assertSame( 'defekt', $merged['status'] );
		$this->assertSame( '399.00', $merged['acquisition_cost'] );
		$this->assertSame( '120', $merged['hours_at_last_nozzle_change'] );
		$this->assertSame( '', $merged['notes'] );
		$this->assertSame( '', $merged['model'] );
		$this->assertArrayNotHasKey( 'evil', $merged );
	}

	public function test_duesenstunden_werden_nicht_negativ() {
		$this->assertSame( 80.0, Produktion::nozzle_hours( 200, 120 ) );
		$this->assertSame( 0.0, Produktion::nozzle_hours( 100, 120 ) );
	}

	public function test_bambu_id_und_verknuepfung() {
		$this->assertSame( 'bambu_p1s-1', Produktion::normalize_bambu_id( '  Bambu_P1S-1 ' ) );
		$this->assertSame( '', Produktion::normalize_bambu_id( 'P1S Werkstatt' ) );
		$this->assertSame( '', Produktion::normalize_bambu_id( '' ) );

		$this->assertSame( 'none', Produktion::link_state( 0, array( 1, 2 ) ) );
		$this->assertSame( 'missing', Produktion::link_state( 9, array( 1, 2 ) ) );
		$this->assertSame( 'ok', Produktion::link_state( 2, array( 1, 2 ) ) );
		$this->assertSame( array( 2 ), Produktion::duplicate_links( array( 2, 0, 2, 0, 3 ) ) );
	}

	public function test_bambu_zustand() {
		$this->assertSame( 'offline', Produktion::bambu_state( false, 'running' ) );
		$this->assertSame( 'unreachable', Produktion::bambu_state( true, 'mqtt_getrennt' ) );
		$this->assertSame( 'busy', Produktion::bambu_state( true, 'RUNNING' ) );
		$this->assertSame( 'error', Produktion::bambu_state( true, 'failed' ) );
		$this->assertSame( 'ready', Produktion::bambu_state( true, 'idle' ) );
		$this->assertSame( 'druckt', Produktion::bambu_status_text( 'running' ) );
		$this->assertSame( 'exotisch', Produktion::bambu_status_text( 'exotisch' ) );
	}

	public function test_ams_faecher_ohne_leere_und_mit_sicherer_farbe() {
		$units = array( array( 'id' => 0, 'trays' => array(
			array( 'id' => 0, 'type' => 'PLA', 'color' => 'FF0000FF', 'remain' => 80 ),
			array( 'id' => 1, 'type' => '' ),
			array( 'id' => 2, 'type' => 'PETG', 'color' => '"><script>', 'remain' => '' ),
		) ) );
		$out = Produktion::ams_trays( $units );

		$this->assertCount( 2, $out );
		$this->assertSame( 'ff0000', $out[0]['color'] );
		$this->assertSame( 1, $out[0]['tray'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{6}$/', $out[1]['color'] );
		$this->assertNull( $out[1]['remain'] );
	}

	public function test_kapazitaet_mengen_und_je_bestellung() {
		$this->assertSame( array( 3 => 5.5, 4 => 100000.0 ), Produktion::clean_quantities( array( '3' => '5,5', '4' => 9999999, '5' => 0, 'x' => 2, '6' => 'abc' ) ) );
		$this->assertSame( array(), Produktion::clean_quantities( 'nope' ) );

		$p = Produktion::per_order( array( 'orders' => 4, 'print_time_h' => 8, 'postprocessing_min' => 40, 'purchased' => array( 7 => array( 'sku' => 'A', 'qty' => 12 ) ) ) );
		$this->assertSame( 2.0, $p['print_time_h'] );
		$this->assertSame( 10.0, $p['postprocessing_min'] );
		$this->assertSame( 3.0, $p['purchased'][7] );

		$null = Produktion::per_order( array( 'orders' => 0, 'print_time_h' => 0, 'postprocessing_min' => 0, 'purchased' => array() ) );
		$this->assertSame( 0.0, $null['print_time_h'] );
	}

	public function test_bestand_nur_am_lagerort_lager() {
		$stock = Produktion::stock_for( array( 7 => array( 'rot' => array( 'lager' => 5, 'werkstatt' => 9 ), 'blau' => array( 'lager' => 2 ) ) ), array( 7, 8 ) );

		$this->assertSame( 7.0, $stock[7] );
		$this->assertSame( 0.0, $stock[8] );
	}

	public function test_auftragsstatus_geht_nur_bis_fertig_ohne_buchung() {
		$this->assertSame( 'in_produktion', Produktion::next_job_status( 'geplant' ) );
		$this->assertSame( 'fertig', Produktion::next_job_status( 'in_produktion' ) );
		$this->assertSame( '', Produktion::next_job_status( 'fertig' ), 'verpackt bucht Bestand und geht nur über den Abschluss' );
		$this->assertSame( '', Produktion::next_job_status( 'verpackt' ) );
	}

	public function test_ausgeschlossene_wochen() {
		$r = Produktion::parse_weeks( '2026-51, 2026-5;2026-52 2026-51' );
		$this->assertSame( array( '2026-51', '2026-05', '2026-52' ), $r['weeks'] );
		$this->assertSame( array(), $r['invalid'] );

		$bad = Produktion::parse_weeks( '2026-54, Weihnachten, 2026-01' );
		$this->assertSame( array( '2026-54', 'Weihnachten' ), $bad['invalid'] );

		$leer = Produktion::parse_weeks( '  ' );
		$this->assertSame( array(), $leer['weeks'] );
	}

	public function test_abweichung_in_prozent() {
		$this->assertSame( 25, Produktion::deviation_percent( 20, 25 ) );
		$this->assertSame( -50, Produktion::deviation_percent( 10, 5 ) );
		$this->assertNull( Produktion::deviation_percent( 0, 5 ) );
	}

	public function test_herkunft_im_chargenbuch_hat_klartext() {
		$this->assertSame( 'Shop-Bestellung', Produktion::ref_label( 'wc_order' ) );
		$this->assertSame( 'unbekannt', Produktion::ref_label( 'unbekannt' ) );
	}

	public function test_routen_haben_feine_rechte_und_alte_rechte() {
		$src = $this->src( 'includes/Erp/Pages/Produktion.php' );

		$this->assertStringContainsString( "array( 'fertigung.drucker', 'fertigung.stammdaten.edit' ), 'lw16_manage_production'", $src );
		$this->assertStringContainsString( "array( 'fertigung.druckjournal' ), 'lw16_manage_production'", $src );
		$this->assertStringContainsString( "array( 'fertigung.bambu', 'fertigung.stammdaten.edit' )", $src );
		$this->assertStringContainsString( "'lw16_mod_produktion'", $src );
	}

	public function test_zugangscode_wird_weder_angenommen_noch_ausgegeben() {
		$src = $this->src( 'includes/Erp/Pages/Produktion.php' );
		$js  = $this->src( 'assets/erp/pages/produktion.js' );

		// WordPress speichert den LAN-Zugangscode nicht mehr – er steht nur in
		// der .env.bambu der Bridge.
		$this->assertStringNotContainsString( 'access_code', $src );
		$this->assertStringNotContainsString( 'access_code', $js );
	}

	public function test_jede_seite_ist_in_der_oberflaeche_angemeldet() {
		$js = $this->src( 'assets/erp/pages/produktion.js' );

		foreach ( array( 'druckjournal', 'drucker', 'bambu', 'qualitaet', 'kapazitaet', 'produktion' ) as $id ) {
			$this->assertStringContainsString( "UI.pages['$id']", $js );
		}
		$this->assertStringNotContainsString( 'innerHTML', $js );
	}
}
