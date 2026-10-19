<?php
/**
 * Navigation der ERP-Oberfläche muss zu den alten Seiten und Rechten passen.
 *
 * Der leise Fehler: Das Menü zeigt „Material“ mit einem feinen Recht, das
 * nur `lw16_capture` voraussetzt, die Seite verlangt aber
 * `lw16_manage_production`. Nach dem Speichern bekäme die Person den
 * Menüpunkt, im eingebetteten Fenster aber „Keine Berechtigung“.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Navigation;
use Lindenweg16\Erp\Permissions;
use Lindenweg16\Modules\Betrieb\BetriebModule;
use PHPUnit\Framework\TestCase;

/**
 * Tests für Navigation.
 */
class ErpNavigationTest extends TestCase {

	public function test_every_item_points_to_a_known_permission_and_area() {
		foreach ( Navigation::items() as $id => $item ) {
			$this->assertSame( $id, $item['id'] );
			$this->assertTrue( Permissions::exists( $item['perm'] ), "$id: Recht {$item['perm']} fehlt im Katalog" );
			$this->assertArrayHasKey( $item['area'], Navigation::areas(), $id );
			$this->assertSame( Permissions::catalog()[ $item['perm'] ]['group'], $item['area'], "$id liegt in einem anderen Bereich als sein Recht" );
		}
	}

	public function test_each_permission_with_a_page_has_exactly_one_item() {
		$count = array();
		foreach ( Navigation::items() as $item ) {
			$count[ $item['perm'] ] = ( isset( $count[ $item['perm'] ] ) ? $count[ $item['perm'] ] : 0 ) + 1;
		}

		foreach ( $count as $perm => $n ) {
			$this->assertSame( 1, $n, "$perm öffnet mehrere Seiten" );
		}
	}

	public function test_native_items_need_no_target_and_legacy_items_do() {
		foreach ( Navigation::items() as $id => $item ) {
			if ( 'native' === $item['kind'] ) {
				$this->assertSame( '', $item['target'], $id );
			} else {
				$this->assertNotSame( '', $item['target'], $id );
			}
		}
	}

	public function test_permission_covers_the_cap_the_old_page_requires() {
		foreach ( Navigation::items() as $id => $item ) {
			$pages = BetriebModule::pages();

			if ( ! isset( $pages[ $item['target'] ] ) ) {
				continue;
			}

			$required = $pages[ $item['target'] ][1];
			$caps     = Permissions::catalog()[ $item['perm'] ]['caps'];

			$this->assertContains( $required, $caps, "$id: Seite verlangt $required, das Recht {$item['perm']} setzt es nicht voraus" );
		}
	}

	public function test_every_betrieb_page_is_reachable_or_deliberately_left_out() {
		$linked = array();
		foreach ( Navigation::items() as $item ) {
			$linked[] = $item['target'];
		}

		// Das Dashboard ist die Startseite; die Etikett-Jobs ein Reiter.
		$ignored = array( BetriebModule::MENU_SLUG );

		foreach ( array_keys( BetriebModule::pages() ) as $slug ) {
			if ( in_array( $slug, $ignored, true ) ) {
				continue;
			}
			$this->assertContains( $slug, $linked, "$slug fehlt in der ERP-Navigation" );
		}
	}

	public function test_visible_filters_by_permission_and_drops_empty_areas() {
		$tree = Navigation::visible(
			function ( $perm ) {
				return 'verkauf.versand' === $perm;
			}
		);

		$this->assertSame( array( 'verkauf' ), array_keys( $tree ) );
		$this->assertSame( 'versand', $tree['verkauf'][0]['id'] );
	}

	public function test_legacy_path() {
		$this->assertSame( 'admin.php?page=lw16-betrieb-lager', Navigation::legacy_path( Navigation::find( 'lager' ) ) );
		$this->assertSame( 'edit.php?post_type=product', Navigation::legacy_path( Navigation::find( 'shop-produkte' ) ) );
		$this->assertSame( '', Navigation::legacy_path( Navigation::find( 'rechte' ) ) );
	}

	public function test_find_by_page_ignores_shop_pages() {
		$this->assertSame( 'lager', Navigation::find_by_page( 'lw16-betrieb-lager' )['id'] );
		$this->assertNull( Navigation::find_by_page( 'wc-orders' ) );
		$this->assertNull( Navigation::find_by_page( '' ) );
	}
}
