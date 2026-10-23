<?php
/**
 * Kurzlinks in der ERP-Oberfläche: Recht, Navigation, Seite und reine Regeln.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Navigation;
use Lindenweg16\Erp\Pages\Kurzlinks;
use Lindenweg16\Erp\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * Prüft, dass Recht, Menüeintrag, Script und Route zusammenpassen.
 */
class ErpKurzlinksTest extends TestCase {

	public function test_recht_ist_im_katalog_und_nutzt_manage_woocommerce() {
		$catalog = Permissions::catalog();

		$this->assertArrayHasKey( 'verkauf.kurzlinks', $catalog );
		$this->assertSame( array( 'manage_woocommerce' ), $catalog['verkauf.kurzlinks']['caps'] );
	}

	public function test_navigation_fuehrt_die_native_seite() {
		$items = Navigation::items();

		$this->assertSame( 'native', $items['kurzlinks']['kind'] );
		$this->assertSame( 'verkauf.kurzlinks', $items['kurzlinks']['perm'] );
		$this->assertSame( 'lw16-kurzlinks', $items['kurzlinks']['alt'] );
		$this->assertSame( 'verkauf', Navigation::place( 'kurzlinks' )[0] );
	}

	public function test_seite_ist_als_script_registriert() {
		$js = file_get_contents( dirname( __DIR__ ) . '/assets/erp/pages/kurzlinks.js' );

		$this->assertStringContainsString( "UI.pages['kurzlinks']", $js );
		$this->assertStringContainsString( "ui.api('kurzlinks", $js );
	}

	public function test_zeitraum_und_fehlertext() {
		$this->assertSame( 90, Kurzlinks::clean_days( null ) );
		$this->assertSame( 90, Kurzlinks::clean_days( '7' ) );
		$this->assertSame( 30, Kurzlinks::clean_days( '30' ) );
		$this->assertSame( 0, Kurzlinks::clean_days( '0' ) );

		$msg = Kurzlinks::error_message( array( 1 => array( 'A.', 'B.' ), 3 => array( 'C.' ) ) );
		$this->assertStringContainsString( 'Nichts gespeichert.', $msg );
		$this->assertStringContainsString( 'A. B. C.', $msg );
	}
}
