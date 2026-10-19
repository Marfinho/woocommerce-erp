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

	public function test_werkstatt_and_packtisch_get_their_native_pages() {
		$presets = Permissions::presets();

		$this->assertContains( 'lager.scannen', $presets['werkstatt']['keys'] );
		$this->assertContains( 'lager.buchen', $presets['werkstatt']['keys'] );
		$this->assertContains( 'verkauf.picken', $presets['packtisch']['keys'] );
		$this->assertNotContains( 'lager.buchen', $presets['packtisch']['keys'], 'Packtisch bucht keinen Bestand' );
	}

	public function test_every_permission_checked_in_code_exists_in_the_catalog() {
		$root  = dirname( __DIR__ ) . '/includes';
		$found = array();

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			if ( preg_match_all( "/Access::can\(\s*'([\w.]+)'/", file_get_contents( $file->getPathname() ), $m ) ) {
				foreach ( $m[1] as $key ) {
					$found[ $key ] = $file->getFilename();
				}
			}
		}

		$this->assertNotEmpty( $found );
		foreach ( $found as $key => $where ) {
			$this->assertTrue( Permissions::exists( $key ), "$where prüft $key, das es nicht gibt" );
		}
	}

	public function test_rights_marked_as_enforced_in_code_are_really_checked() {
		// Geltungsbereich „cap“ ohne eigene Entsprechung im alten Backend
		// verspricht eine Prüfung im Code. Die Rechte hier sind die, für die
		// das Versprechen über Access::can() eingelöst wird.
		$enforced = array(
			'lager.buchen'            => 'Modules/Betrieb/RestApi.php',
			'verkauf.versand.etikett' => 'Modules/Betrieb/Admin/ShippingPage.php',
		);

		foreach ( $enforced as $key => $file ) {
			$this->assertSame( Permissions::SCOPE_CAP, Permissions::catalog()[ $key ]['scope'], $key );
			$this->assertStringContainsString( "Access::can( '$key' )", file_get_contents( dirname( __DIR__ ) . '/includes/' . $file ), "$key wird in $file nicht geprüft" );
		}

		// Umgekehrt darf kein Recht „Folgt“ tragen, das schon geprüft wird.
		foreach ( Permissions::catalog() as $key => $def ) {
			if ( Permissions::SCOPE_PHASE2 === $def['scope'] ) {
				$this->assertArrayNotHasKey( $key, $enforced, $key );
			}
		}
	}
}
