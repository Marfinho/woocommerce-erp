<?php
/**
 * Kasse in der ERP-Oberfläche: Recht, Navigation, Seite, Routen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Navigation;
use Lindenweg16\Erp\Permissions;
use Lindenweg16\Modules\POS\POSModule;
use PHPUnit\Framework\TestCase;

/**
 * Prüft, dass Recht, Menüeintrag, Script und Route zusammenpassen.
 */
class ErpKasseTest extends TestCase {

	public function test_recht_ist_im_katalog() {
		$this->assertArrayHasKey( 'verkauf.kasse', Permissions::catalog() );
	}

	public function test_navigation_fuehrt_die_native_seite_ohne_wp_admin_vorgaenger() {
		$items = Navigation::items();

		$this->assertSame( 'native', $items['kasse']['kind'] );
		$this->assertSame( 'verkauf.kasse', $items['kasse']['perm'] );
		$this->assertSame( '', $items['kasse']['alt'], 'Die Kasse hat keine klassische Ansicht mehr.' );
		$this->assertSame( 'verkauf', Navigation::place( 'kasse' )[0] );
	}

	public function test_seite_ist_als_script_registriert() {
		$js = file_get_contents( dirname( __DIR__ ) . '/assets/erp/pages/kasse.js' );

		$this->assertStringContainsString( "UI.pages['kasse']", $js );
		foreach ( array( 'start', 'verkauf', 'storno', 'abschluss' ) as $route ) {
			$this->assertStringContainsString( "'" . $route . "'", $js, $route );
		}
	}

	public function test_routen_und_recht() {
		$php = file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Pages/Kasse.php' );

		$this->assertStringContainsString( "array( 'verkauf.kasse' )", $php );
		foreach ( array( 'start', 'verkauf', 'storno', 'abschluss' ) as $route ) {
			$this->assertTrue( method_exists( \Lindenweg16\Erp\Pages\Kasse::class, $route ), $route );
		}
	}

	public function test_kein_wp_admin_menue_mehr() {
		$menu = file_get_contents( dirname( __DIR__ ) . '/includes/Admin/Menu.php' );

		$this->assertStringNotContainsString( 'lw16-kasse', $menu );
	}

	public function test_summe_ohne_stornierte() {
		$rows = array(
			(object) array( 'storniert' => 0, 'betrag' => '12.50' ),
			(object) array( 'storniert' => 1, 'betrag' => '99.00' ),
			(object) array( 'storniert' => 0, 'betrag' => '7.50' ),
		);

		$this->assertSame( 20.0, POSModule::sum_positions( $rows ) );
	}
}
