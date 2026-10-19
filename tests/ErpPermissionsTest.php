<?php
/**
 * Rechtekatalog der ERP-Oberfläche.
 *
 * Geprüft wird, was beim Vergeben schiefgehen kann, ohne dass es auffällt:
 * eine Voraussetzung, die nicht existiert, ein Recht, das nach dem Speichern
 * nichts bewirkt, oder eine Vorlage, die mehr freigibt als beschriftet.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * Tests für Permissions.
 */
class ErpPermissionsTest extends TestCase {

	public function test_every_requirement_exists_and_is_grantable() {
		foreach ( Permissions::catalog() as $key => $def ) {
			foreach ( $def['requires'] as $required ) {
				$this->assertTrue( Permissions::grantable( $required ), "$key verlangt $required, das es nicht gibt oder nicht vergebbar ist" );
			}
		}
	}

	public function test_every_group_is_known() {
		foreach ( Permissions::catalog() as $key => $def ) {
			$this->assertArrayHasKey( $def['group'], Permissions::groups(), $key );
		}
	}

	public function test_only_phase2_and_shop_rights_have_no_legacy_cap() {
		// Ein vergebbares Recht ohne altes Recht schaltet im alten Backend
		// nichts frei. Erlaubt nur für den Shop (eigene WordPress-Rolle).
		foreach ( Permissions::catalog() as $key => $def ) {
			if ( Permissions::SCOPE_FULL === $def['scope'] || 0 === strpos( $key, 'verkauf.shop_' ) ) {
				continue;
			}
			$this->assertNotEmpty( $def['caps'], "$key setzt kein altes Recht voraus" );
		}
	}

	public function test_full_only_rights_are_not_grantable() {
		$this->assertFalse( Permissions::grantable( 'finanzen.buchhaltung' ) );
		$this->assertSame( array(), Permissions::normalize( array( 'finanzen.buchhaltung' ) ) );
	}

	public function test_normalize_drops_unknown_and_duplicates_and_keeps_catalog_order() {
		$out = Permissions::normalize( array( 'lager.bedarf', 'gibt.es.nicht', 'verkauf.versand', 'lager.bedarf' ) );

		$this->assertSame( array( 'verkauf.versand', 'lager.bedarf' ), $out );
	}

	public function test_normalize_adds_requirements() {
		$out = Permissions::normalize( array( 'lager.buchen' ) );

		$this->assertContains( 'lager.bestand', $out );
		$this->assertContains( 'lager.buchen', $out );
	}

	public function test_caps_for_follow_the_selection() {
		$this->assertSame( array(), Permissions::caps_for( array() ) );
		$this->assertSame( array( 'lw16_capture' ), Permissions::caps_for( array( 'verkauf.versand' ) ) );
		$this->assertSame(
			array( 'lw16_capture', 'lw16_manage_production', 'lw16_view_costs' ),
			Permissions::caps_for( array( 'finanzen.kosten', 'fertigung.artikel', 'lager.bestand' ) )
		);
	}

	public function test_derive_from_caps_gives_what_the_old_profile_could() {
		$keys = Permissions::derive_from_caps( array( 'lw16_capture' ) );

		$this->assertContains( 'lager.bestand', $keys );
		$this->assertNotContains( 'lager.material', $keys, 'Material braucht Stammdaten-Recht' );
		$this->assertNotContains( 'finanzen.kosten', $keys );
		$this->assertNotContains( 'verkauf.shop_orders', $keys, 'Shop gehört nie zur Ableitung' );
		$this->assertSame( array(), Permissions::derive_from_caps( array() ) );
	}

	public function test_presets_are_valid_and_match_themselves() {
		foreach ( Permissions::presets() as $id => $preset ) {
			foreach ( $preset['keys'] as $key ) {
				$this->assertTrue( Permissions::grantable( $key ), "$id enthält $key" );
			}
			$this->assertSame( $id, Permissions::match_preset( $preset['keys'] ) );
		}
	}

	public function test_werkstatt_sees_no_money_and_no_master_data() {
		$caps = Permissions::caps_for( Permissions::presets()['werkstatt']['keys'] );

		$this->assertSame( array( 'lw16_capture' ), $caps );
	}

	public function test_match_preset_is_empty_when_nothing_fits() {
		$this->assertSame( '', Permissions::match_preset( array( 'verkauf.versand', 'finanzen.kosten' ) ) );
	}
}
