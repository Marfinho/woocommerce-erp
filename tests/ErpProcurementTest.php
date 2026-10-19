<?php
/**
 * Einkaufs-Workflow: Regeln und Verdrahtung.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Procurement;
use Lindenweg16\Modules\Betrieb\Purchase;
use PHPUnit\Framework\TestCase;

/**
 * Tests für Procurement und die erweiterten Bestellarten.
 */
class ErpProcurementTest extends TestCase {

	public function test_alarmarten_werden_bestellarten() {
		$this->assertSame( 'article', Procurement::type_for_kind( 'artikel' ) );
		$this->assertSame( 'component', Procurement::type_for_kind( 'komponente' ) );
		$this->assertSame( 'supply', Procurement::type_for_kind( 'verbrauchsmaterial' ) );
		$this->assertSame( 'filament', Procurement::type_for_kind( 'filament' ) );
		$this->assertSame( '', Procurement::type_for_kind( 'unbekannt' ) );
	}

	public function test_filament_in_kilogramm() {
		$this->assertSame( 'kg', Procurement::unit( 'filament' ) );
		$this->assertSame( 'Stück', Procurement::unit( 'component' ) );
	}

	public function test_verspaetung() {
		$this->assertSame( 0, Procurement::late_days( '', '2026-10-19' ) );
		$this->assertSame( 0, Procurement::late_days( '2026-10-19', '2026-10-19' ) );
		$this->assertSame( 0, Procurement::late_days( '2026-10-25', '2026-10-19' ) );
		$this->assertSame( 4, Procurement::late_days( '2026-10-15', '2026-10-19' ) );
	}

	public function test_filament_bestellung_braucht_farbe_statt_nummer() {
		$ok = Purchase::validate( array( 'item_type' => 'filament', 'item_ref' => 'Weiss', 'qty' => '5' ), '2026-10-19' );
		$this->assertSame( array(), $ok['errors'] );
		$this->assertSame( 0, $ok['data']['item_id'] );
		$this->assertSame( 'weiss', $ok['data']['item_ref'] );

		$bad = Purchase::validate( array( 'item_type' => 'filament', 'qty' => '5' ), '2026-10-19' );
		$this->assertNotEmpty( $bad['errors'] );
	}

	public function test_artikel_bestellung_braucht_nummer() {
		$bad = Purchase::validate( array( 'item_type' => 'article', 'qty' => '5' ), '2026-10-19' );
		$this->assertNotEmpty( $bad['errors'] );

		$ok = Purchase::validate( array( 'item_type' => 'article', 'item_id' => 7, 'qty' => '5' ), '2026-10-19' );
		$this->assertSame( array(), $ok['errors'] );
		$this->assertNull( $ok['data']['item_ref'] );
	}

	public function test_offene_filament_bestellung_wird_je_farbe_gezaehlt() {
		$p = array(
			array( 'item_type' => 'filament', 'item_id' => 0, 'item_ref' => 'weiss', 'status' => 'bestellt', 'qty' => 5 ),
			array( 'item_type' => 'filament', 'item_id' => 0, 'item_ref' => 'schwarz', 'status' => 'bestellt', 'qty' => 3 ),
		);

		$this->assertSame( 5.0, Purchase::incoming( $p, 'filament', 0, 'weiss' ) );
	}

	public function test_eingang_bucht_je_art_in_den_richtigen_bestand() {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/PurchaseRepository.php' );

		$this->assertStringContainsString( 'FilamentRepository::insert', $src );
		$this->assertStringContainsString( "'movement_type' => 'wareneingang'", $src );
		// Charge vor dem Schließen prüfen: sonst wäre die Bestellung zu, die Rolle aber nicht da.
		$this->assertLessThan( strpos( $src, "'status'       => 'geliefert'" ), strpos( $src, 'FilamentRepository::validate' ) );
	}

	public function test_wareneingang_per_scan_nur_fuer_teile_mit_bestellung() {
		$this->assertSame( array(), Procurement::open_for( 'filament', 1 ) );
		$this->assertSame( array(), Procurement::open_for( 'article', 0 ) );
		$this->assertSame( array(), Procurement::open_for( 'unbekannt', 5 ) );

		$rest = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/RestApi.php' );
		$this->assertMatchesRegularExpression( "#'/erp/einkauf/offen'.*?'lager\\.einkauf'#s", $rest );
	}
}
