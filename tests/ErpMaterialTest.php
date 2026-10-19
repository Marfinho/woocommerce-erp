<?php
/**
 * Filament im Workflow: Rollenwahl, Bedarf gegen Bestand, Nachbestellmenge.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Material;
use PHPUnit\Framework\TestCase;

/**
 * Tests für Material.
 */
class ErpMaterialTest extends TestCase {

	protected function lots() {
		return array(
			array( 'id' => 3, 'code' => 'R-03', 'remaining_kg' => 0.2, 'received_on' => '2026-08-01' ),
			array( 'id' => 5, 'code' => 'R-05', 'remaining_kg' => 0.9, 'received_on' => '2026-09-01' ),
			array( 'id' => 7, 'code' => 'R-07', 'remaining_kg' => 1.0, 'received_on' => '2026-10-01' ),
		);
	}

	public function test_oldest_lot_with_enough_left_is_chosen() {
		$this->assertSame( 5, Material::pick_lot( $this->lots(), 0.5 )['id'], 'R-03 ist älter, hat aber zu wenig' );
		$this->assertSame( 3, Material::pick_lot( $this->lots(), 0.1 )['id'] );
	}

	public function test_when_no_lot_has_enough_the_fullest_is_chosen() {
		$this->assertSame( 7, Material::pick_lot( $this->lots(), 3.0 )['id'] );
	}

	public function test_empty_and_missing_lots_give_nothing() {
		$this->assertNull( Material::pick_lot( array(), 1.0 ) );
		$this->assertNull( Material::pick_lot( array( array( 'id' => 1, 'code' => 'x', 'remaining_kg' => 0.0, 'received_on' => '2026-01-01' ) ), 0.1 ) );
		$this->assertNull( Material::pick_lot( array( array( 'id' => 1, 'code' => 'x', 'remaining_kg' => -0.4, 'received_on' => '2026-01-01' ) ), 0.1 ), 'überzogene Rolle zählt nicht' );
	}

	public function test_same_date_falls_back_to_the_lower_id() {
		$lots = array(
			array( 'id' => 9, 'code' => 'B', 'remaining_kg' => 1.0, 'received_on' => '2026-09-01' ),
			array( 'id' => 4, 'code' => 'A', 'remaining_kg' => 1.0, 'received_on' => '2026-09-01' ),
		);

		$this->assertSame( 4, Material::pick_lot( $lots, 0.5 )['id'] );
	}

	public function test_compare_reports_shortage() {
		$rows = Material::compare( array( 'schwarz' => 0.41, 'weiss' => 0.16 ), array( 'schwarz' => 1.2, 'weiss' => 0.09 ) );

		$this->assertTrue( $rows[0]['ok'] );
		$this->assertFalse( $rows[1]['ok'] );
		$this->assertSame( 0.07, $rows[1]['missing_kg'] );
	}

	public function test_compare_treats_unknown_color_as_empty_stock() {
		$rows = Material::compare( array( 'rot' => 0.3 ), array() );

		$this->assertFalse( $rows[0]['ok'] );
		$this->assertSame( 0.0, $rows[0]['available_kg'] );
		$this->assertSame( 0.3, $rows[0]['missing_kg'] );
	}

	public function test_compare_ignores_negative_stock() {
		$rows = Material::compare( array( 'rot' => 0.3 ), array( 'rot' => -2.0 ) );

		$this->assertSame( 0.0, $rows[0]['available_kg'] );
	}

	public function test_order_quantity_covers_lead_time_plus_two_weeks_in_whole_kg() {
		// 0,1 kg/Tag, Schwelle 15 Tage: (15 + 14) × 0,1 = 2,9 → 3 kg.
		$this->assertSame( 3, Material::order_kg( 0.1, 15 ) );
		$this->assertSame( 1, Material::order_kg( 0.0, 15 ), 'mindestens ein Kilogramm' );
	}

	public function test_the_report_assigns_a_lot_so_consumption_is_booked() {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Workflow.php' );

		$this->assertStringContainsString( "'filament_lot_id' => \$lot_of[ \$index ]", $source, 'ohne Rolle verschwindet das Filament aus der Rechnung' );
	}

	public function test_procurement_alert_includes_filament() {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/ProcurementAlert.php' );

		$this->assertStringContainsString( 'Material::alerts()', $source, 'Dashboard, Materialseite und Wochenmail sollen Filament mitmelden' );
	}

	public function test_ampel_je_farbe() {
		$this->assertSame( 'leer', Material::level( 3.0, 15, 0.0 ) );
		$this->assertSame( 'ok', Material::level( null, 15, 2.0 ) );
		$this->assertSame( 'knapp', Material::level( 15.0, 15, 1.0 ) );
		$this->assertSame( 'bald', Material::level( 25.0, 15, 1.0 ) );
		$this->assertSame( 'ok', Material::level( 31.0, 15, 4.0 ) );
	}
}
