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

	public function test_every_module_right_is_grantable_and_has_its_own_cap() {
		foreach ( array( 'finanzen.buchhaltung', 'finanzen.reporting', 'verkauf.kasse', 'verkauf.loyalty', 'verkauf.marketing', 'fertigung.produktion', 'verwaltung.support' ) as $key ) {
			$this->assertTrue( Permissions::grantable( $key ), $key );
			$this->assertNotEmpty( Permissions::catalog()[ $key ]['caps'], $key );
			$this->assertStringStartsWith( 'lw16_mod_', Permissions::catalog()[ $key ]['caps'][0], $key );
		}
	}

	public function test_module_caps_are_known_to_roles() {
		$known = array_merge( \Lindenweg16\Core\Roles::MODULE_CAPS, array( 'lw16_mail_use', 'lw16_mail_manage' ) );

		foreach ( Permissions::all_caps() as $cap ) {
			if ( 0 === strpos( $cap, 'lw16_mod_' ) ) {
				$this->assertContains( $cap, $known, "$cap fehlt in Roles::MODULE_CAPS" );
			}
		}
		foreach ( \Lindenweg16\Core\Roles::MODULE_CAPS as $cap ) {
			$this->assertContains( $cap, Permissions::all_caps(), "$cap wird von keinem Recht gebraucht" );
		}
	}

	public function test_full_access_grants_all_module_caps() {
		$caps = \Lindenweg16\Core\Roles::grant_module_caps( array( LW16_CAPABILITY => true ) );
		foreach ( \Lindenweg16\Core\Roles::MODULE_CAPS as $cap ) {
			$this->assertTrue( $caps[ $cap ], $cap );
		}

		$none = \Lindenweg16\Core\Roles::grant_module_caps( array( 'read' => true ) );
		$this->assertArrayNotHasKey( 'lw16_mod_finanzen', $none, 'ohne Vollzugriff gibt es kein Modulrecht dazu' );
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

	public function test_every_cap_scoped_right_is_really_enforced() {
		// Ein Recht ohne eigene Entsprechung im alten Backend, das „wirkt in
		// den Seiten“ verspricht, muss in der Vorgangstabelle stehen oder im
		// Code per Access::can() geprüft werden.
		$enforced = array();
		foreach ( \Lindenweg16\Erp\ActionMap::rules() as $perms ) {
			foreach ( $perms as $perm ) {
				$enforced[ $perm ] = true;
			}
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/includes', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() && preg_match_all( "/Access::can\\(\\s*'([\\w.]+)'/", file_get_contents( $file->getPathname() ), $m ) ) {
				foreach ( $m[1] as $key ) {
					$enforced[ $key ] = true;
				}
			}
		}

		foreach ( Permissions::catalog() as $key => $def ) {
			if ( Permissions::SCOPE_CAP === $def['scope'] && 'finanzen.kosten' !== $key ) {
				$this->assertArrayHasKey( $key, $enforced, "$key verspricht Wirkung, wird aber nirgends geprüft" );
			}
			$this->assertNotSame( Permissions::SCOPE_PHASE2, $def['scope'], "$key steht noch auf 'folgt'" );
		}
	}
}
