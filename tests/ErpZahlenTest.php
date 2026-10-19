<?php
/**
 * Bereich „Zahlen“: reine Regeln und Rechte der Routen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Zahlen;
use PHPUnit\Framework\TestCase;

class ErpZahlenTest extends TestCase {

	public function test_datum_nur_gueltig_oder_vorgabe() {
		$this->assertSame( '2026-02-28', Zahlen::clean_date( '2026-02-28', 'x' ) );
		$this->assertSame( 'x', Zahlen::clean_date( '2026-02-30', 'x' ) );
		$this->assertSame( 'x', Zahlen::clean_date( '28.02.2026', 'x' ) );
		$this->assertSame( 'x', Zahlen::clean_date( null, 'x' ) );
		$this->assertSame( 'x', Zahlen::clean_date( array( '2026-01-01' ), 'x' ) );
	}

	public function test_zeitraum_richtig_herum() {
		$this->assertSame( '', Zahlen::range_error( '2026-01-01', '2026-01-01' ) );
		$this->assertStringContainsString( 'Startdatum', Zahlen::range_error( '2026-02-01', '2026-01-01' ) );
	}

	public function test_jahr_und_auswahl() {
		$this->assertSame( 2025, Zahlen::clean_year( '2025', 2026 ) );
		$this->assertSame( 2026, Zahlen::clean_year( '0', 2026 ) );
		$this->assertSame( 2026, Zahlen::clean_year( '99999', 2026 ) );
		$this->assertSame( array( 2024, 2025, 2026, 2027 ), Zahlen::year_choices( 2026 ) );
	}

	public function test_seiten() {
		$this->assertSame( 1, Zahlen::clean_page( '-4' ) );
		$this->assertSame( 3, Zahlen::clean_page( '3' ) );
		$this->assertSame( 1, Zahlen::page_count( 0 ) );
		$this->assertSame( 2, Zahlen::page_count( 51 ) );
		$this->assertSame( 1, Zahlen::page_count( 50 ) );
	}

	public function test_marge() {
		$this->assertSame( 25.0, Zahlen::margin( 200, 50 ) );
		$this->assertNull( Zahlen::margin( 0, -10 ) );
		$this->assertSame( -10.0, Zahlen::margin( 100, -10 ) );
	}

	public function test_geldgroessen_nur_mit_kostenrecht() {
		$defs = \Lindenweg16\Modules\Betrieb\Kpi::definitions();
		$ohne = Zahlen::visible_kpis( $defs, false );

		$this->assertArrayNotHasKey( 'umsatz', $ohne );
		$this->assertArrayNotHasKey( 'warenkorb', $ohne );
		$this->assertArrayHasKey( 'ausschussquote', $ohne );
		$this->assertSame( $defs, Zahlen::visible_kpis( $defs, true ) );
	}

	public function test_csv_ohne_kostenrecht_ohne_euro_zeilen() {
		$defs = Zahlen::visible_kpis( \Lindenweg16\Modules\Betrieb\Kpi::definitions(), false );
		$rows = Zahlen::kpi_rows( array( 'umsatz' => 100.0, 'ausschussquote' => 5.0 ), $defs );
		$text = Zahlen::csv( $rows );

		$this->assertStringStartsWith( "\xEF\xBB\xBF" . 'Kennzahl;Wert;Einheit;Ziel;Alarm;Status', $text );
		$this->assertStringContainsString( 'Ausschussquote;5;%;6;10;gruen', $text );
		$this->assertStringNotContainsString( 'Umsatz', $text );
	}

	public function test_zieleingabe() {
		$this->assertSame( '30000', Zahlen::goal_input( 30000.0 ) );
		$this->assertSame( '4,5', Zahlen::goal_input( 4.5 ) );
		$this->assertTrue( Zahlen::shows_derived( 10100.0, 30000.0, null ) );
		$this->assertFalse( Zahlen::shows_derived( 6.0, null, 6.0 ) );
		$this->assertFalse( Zahlen::shows_derived( null, 1.0, null ) );
		$this->assertTrue( Zahlen::shows_derived( 5.0, null, null ) );
	}

	public function test_beleg_status_storno_vor_festschreibung() {
		$this->assertSame( 'storniert', Zahlen::beleg_status( true, true ) );
		$this->assertSame( 'festgeschrieben', Zahlen::beleg_status( false, true ) );
		$this->assertSame( 'offen', Zahlen::beleg_status( false, false ) );
		$this->assertSame( 'Storno', Zahlen::source_label( 'storno' ) );
		$this->assertSame( 'manuell', Zahlen::source_label( '' ) );
	}

	public function test_vip_liste() {
		$rows = array(
			array( 'customer_id' => 5, 'email' => 'a@x', 'name' => 'Anna', 'total' => 10 ),
			array( 'customer_id' => 0, 'email' => 'g@x', 'name' => ' ', 'total' => 80 ),
			array( 'customer_id' => 5, 'email' => 'a@x', 'name' => 'Anna', 'total' => 20 ),
			array( 'customer_id' => 0, 'email' => 'h@x', 'name' => 'Hans', 'total' => 1 ),
		);
		$vip  = Zahlen::vip_list( $rows );

		$this->assertSame( 'Gast', $vip[0]['name'] );
		$this->assertSame( 'Anna', $vip[1]['name'] );
		$this->assertSame( 2, $vip[1]['count'] );
		$this->assertEquals( 30, $vip[1]['total'] );
		$this->assertCount( 3, $vip );
	}

	public function test_bestellstatistik() {
		$this->assertSame( array( 'count' => 0, 'umsatz' => 0.0, 'avg' => 0.0 ), Zahlen::order_stats( array() ) );
		$this->assertSame( 20.0, Zahlen::order_stats( array( 10, 30 ) )['avg'] );
	}

	public function test_monatsabschluss_eingaben() {
		$this->assertSame( '', Zahlen::freeze_error( 2026, 12 ) );
		$this->assertNotSame( '', Zahlen::freeze_error( 2026, 13 ) );
		$this->assertNotSame( '', Zahlen::freeze_error( 1999, 1 ) );
	}

	public function test_kategorien_absteigend() {
		$out = Zahlen::sorted_groups( array( 'A' => 5, 'B' => 9 ) );
		$this->assertSame( 'B', $out[0]['name'] );
	}

	public function test_dateinamen() {
		$this->assertSame( 'lw16-analyse-2026-01-02.md', Zahlen::export_filename( 'analyse', '2026-01-02' ) );
		$this->assertSame( 'lw16-kennzahlen-2026-01-02.csv', Zahlen::export_filename( 'kennzahlen', '2026-01-02' ) );
	}

	/**
	 * Die Rechte der Routen stehen im Quelltext; sie müssen zu den alten Seiten passen.
	 */
	public function test_routen_mit_den_rechten_der_alten_seiten() {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Pages/Zahlen.php' );

		$this->assertStringContainsString( "'/erp/zahlen/dashboard', 'dashboard', \$api->needs( array( 'finanzen.dashboard' ) )", $src );
		$this->assertStringContainsString( "'/erp/zahlen/berichte/ziele', 'ziele_speichern', \$api->needs( array( 'finanzen.berichte', 'verwaltung.einstellungen' ) )", $src );
		$this->assertStringContainsString( "'/erp/zahlen/reporting/datenexport', 'datenexport_senden', \$api->needs( array( 'finanzen.reporting', 'verwaltung.support' ) )", $src );
		$this->assertStringContainsString( "\$buch = \$api->needs( array( 'finanzen.buchhaltung' ) )", $src );
		$this->assertStringContainsString( "Access::can( 'finanzen.kosten' )", $src );
	}

	/**
	 * GoBD: Die Buchhaltung ändert nativ keine Bücher.
	 */
	public function test_buchhaltung_aendert_keine_buecher() {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Pages/Zahlen.php' );

		foreach ( array( '::create(', '::update(', '::festschreiben(', '::stornieren(', '::create_storno(', '::create_from', '$wpdb->delete', '$wpdb->update', '$wpdb->insert', 'DELETE FROM' ) as $verboten ) {
			$this->assertStringNotContainsString( $verboten, $src, "Zahlen.php darf $verboten nicht aufrufen (GoBD)" );
		}
	}

	public function test_oberflaeche_ohne_innerhtml_mit_daten() {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/erp/pages/zahlen.js' );

		$this->assertStringNotContainsString( 'innerHTML', $js );
		foreach ( array( 'finanz-dashboard', 'berichte', 'reporting', 'buchhaltung' ) as $id ) {
			$this->assertStringContainsString( "UI.pages['$id']", $js );
		}
	}
}
