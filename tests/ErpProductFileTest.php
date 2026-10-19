<?php
/**
 * Produktakte: reine Regeln der nativen Reiter.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\ProductFile;
use PHPUnit\Framework\TestCase;

class ErpProductFileTest extends TestCase {

	public function test_pick_nimmt_nur_erlaubte_felder_und_macht_text() {
		$out = ProductFile::pick( array( 'sku' => 'A1', 'price_gross' => 9.9, 'sellable' => false, 'x' => 'evil', 'notes' => null, 'name' => array( 1 ) ), array( 'sku', 'price_gross', 'sellable', 'notes', 'name' ) );
		$this->assertSame( array( 'sku' => 'A1', 'price_gross' => '9.9', 'sellable' => false, 'notes' => '' ), $out );
	}

	public function test_overlay_behaelt_nicht_gesendete_werte() {
		$existing = array( 'sku' => 'A1', 'barcode' => '4012', 'name' => 'Alt', 'notes' => null );
		$out      = ProductFile::overlay( $existing, array( 'name' => 'Neu' ), array( 'sku', 'barcode', 'name', 'notes' ) );
		$this->assertSame( array( 'sku' => 'A1', 'barcode' => '4012', 'name' => 'Neu' ), $out );
	}

	public function test_overlay_leer_gesendet_loescht() {
		$out = ProductFile::overlay( array( 'barcode' => '4012' ), array( 'barcode' => '' ), array( 'barcode' ) );
		$this->assertSame( '', $out['barcode'] );
	}

	public function test_split_child() {
		$this->assertSame( array( 'child_type' => 'artikel', 'child_id' => '7' ), ProductFile::split_child( 'artikel:7' ) );
		$this->assertSame( array(), ProductFile::split_child( '' ) );
	}

	public function test_zu_tiefe_stueckliste_wird_erkannt() {
		// 1 -> 2 -> 3 -> 4; die neue Zeile 4 -> 5 macht fünf Ebenen.
		$bom = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$bom[ $i ][] = array( 'child_type' => 'artikel', 'child_id' => $i + 1, 'qty' => 1 );
		}
		$this->assertFalse( ProductFile::adds_depth_error( 4, 'artikel', 5, $bom, 5 ) );
		$this->assertTrue( ProductFile::adds_depth_error( 4, 'artikel', 5, $bom, 3 ) );
	}

	public function test_komponente_erzeugt_keine_tiefe() {
		$this->assertFalse( ProductFile::adds_depth_error( 1, 'komponente', 9, array(), 5 ) );
	}

	public function test_vorhandene_zu_tiefe_sperrt_nicht_alles() {
		$bom = array(
			1 => array( array( 'child_type' => 'artikel', 'child_id' => 2, 'qty' => 1 ) ),
			2 => array( array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => 1 ) ),
		);
		$this->assertFalse( ProductFile::adds_depth_error( 8, 'artikel', 9, $bom, 1 ) );
	}

	public function test_fill_empty_ueberschreibt_nichts() {
		$values = array( 'print_time_plate_h' => 2.5, 'weight_g' => 10.0, 'parts_per_plate' => 3 );
		$this->assertSame( $values, ProductFile::fill_empty( array( 'print_time_plate_h' => 0, 'weight_g' => 0 ), $values ) );
		$this->assertSame( array(), ProductFile::fill_empty( array( 'print_time_plate_h' => 1.0, 'weight_g' => 5.0 ), $values ) );
		$this->assertSame( array( 'print_time_plate_h' => 2.5 ), ProductFile::fill_empty( array( 'print_time_plate_h' => 0, 'weight_g' => 5.0 ), $values ) );
	}

	public function test_upload_meldung() {
		$this->assertSame( 'Projekt-3MF gespeichert: haken.3mf, 3 Objekte.', ProductFile::upload_message( 'haken.3mf', 'modell', 3 ) );
		$this->assertSame( 'Projekt-3MF gespeichert: haken.3mf, 1 Objekt.', ProductFile::upload_message( 'haken.3mf', 'modell', 1 ) );
		$this->assertStringContainsString( 'keine Projekt-3MF', ProductFile::upload_message( 'a.gcode.3mf', 'druckdatei', 0 ) );
	}

	public function test_upload_fehlertexte() {
		$this->assertSame( '', ProductFile::upload_error( 0, 1000 ) );
		$this->assertStringContainsString( 'zu groß', ProductFile::upload_error( 1, 2097152 ) );
		$this->assertStringContainsString( '2,0 MB', ProductFile::upload_error( 2, 2097152 ) );
		$this->assertStringContainsString( 'keine Datei', ProductFile::upload_error( 4, 1 ) );
		$this->assertNotSame( '', ProductFile::upload_error( 99, 1 ) );
	}

	public function test_zielmarge_wird_begrenzt() {
		$this->assertSame( 45.0, ProductFile::margin_percent( '' ) );
		$this->assertSame( 45.0, ProductFile::margin_percent( 'abc' ) );
		$this->assertSame( 30.5, ProductFile::margin_percent( '30,5' ) );
		$this->assertSame( 95.0, ProductFile::margin_percent( '200' ) );
		$this->assertSame( 0.0, ProductFile::margin_percent( '-5' ) );
	}

	public function test_stichtag() {
		$this->assertSame( '2026-03-01', ProductFile::stichtag( '2026-03-01', '2026-10-19' ) );
		$this->assertSame( '2026-10-19', ProductFile::stichtag( '2026-02-31', '2026-10-19' ) );
		$this->assertSame( '2026-10-19', ProductFile::stichtag( 'morgen', '2026-10-19' ) );
	}

	public function test_uebernehmbarer_preis() {
		$this->assertNull( ProductFile::adoptable_price( null ) );
		$this->assertNull( ProductFile::adoptable_price( 0 ) );
		$this->assertSame( 12.35, ProductFile::adoptable_price( 12.349 ) );
	}

	public function test_download_name_ohne_pfadzeichen() {
		$this->assertSame( 'HH-001-v1.0.3mf', ProductFile::download_name( 'HH-001', 'v1.0' ) );
		$this->assertSame( 'a-b.3mf', ProductFile::download_name( '../a/', 'b' ) );
		$this->assertSame( 'artikel.3mf', ProductFile::download_name( '', '' ) );
	}

	public function test_zugehoerigkeit_zum_artikel() {
		$this->assertTrue( ProductFile::belongs_to( array( 'article_id' => '4' ), 'article_id', 4 ) );
		$this->assertFalse( ProductFile::belongs_to( array( 'article_id' => '5' ), 'article_id', 4 ) );
		$this->assertFalse( ProductFile::belongs_to( null, 'article_id', 4 ) );
	}

	public function test_anteil() {
		$this->assertSame( 25.0, ProductFile::share( 1, 4 ) );
		$this->assertSame( 0.0, ProductFile::share( 1, 0 ) );
	}
}
