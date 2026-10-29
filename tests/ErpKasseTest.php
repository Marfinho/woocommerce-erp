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
		foreach ( array( 'start', 'bon', 'storno', 'abschluss' ) as $route ) {
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

	public function test_kassenprodukt_hat_felder_im_schema_und_filter_in_den_shop_listen() {
		$root = dirname( __DIR__ );

		$schema = file_get_contents( $root . '/includes/Modules/Betrieb/Schema.php' );
		$this->assertStringContainsString( 'pos_only TINYINT(1) NOT NULL DEFAULT 0', $schema );
		$this->assertStringContainsString( 'image_id BIGINT UNSIGNED NULL', $schema );

		$this->assertSame( 1, preg_match( "/const DB_VERSION = '(\\d+)'/", file_get_contents( $root . '/includes/Core/Installer.php' ), $m ) );
		$this->assertGreaterThanOrEqual( 48, (int) $m[1], 'Neue Spalten brauchen eine höhere DB-Version, sonst läuft das Upgrade nicht.' );

		$this->assertStringContainsString( 'selectable( true, true )', file_get_contents( $root . '/includes/Modules/Betrieb/Admin/OrdersPage.php' ) );
		$this->assertStringContainsString( 'selectable( true, true )', file_get_contents( $root . '/includes/Modules/Configurator/Admin/ConfiguratorPage.php' ) );
		$this->assertStringContainsString( 'selectable( true )', file_get_contents( $root . '/includes/Modules/POS/POSModule.php' ), 'Die Kasse selbst zeigt Kassenprodukte.' );
	}

	public function test_schnellanlage_nimmt_kamera_und_verkleinert() {
		$js = file_get_contents( dirname( __DIR__ ) . '/assets/erp/pages/kasse.js' );

		$this->assertStringContainsString( "capture: 'environment'", $js );
		$this->assertStringContainsString( 'toDataURL', $js );
		$this->assertStringContainsString( "'produkt-aendern' : 'produkt'", $js );
	}

	public function test_foto_nur_echte_bilder() {
		$this->assertIsString( POSModule::save_photo( 'data:text/html;base64,PGI+', 'x' ) );
		$this->assertIsString( POSModule::save_photo( 'data:image/png;base64,' . base64_encode( 'kein bild' ), 'x' ), 'Inhalt wird geprüft, nicht der Dateiname.' );
		$this->assertIsString( POSModule::save_photo( 'data:image/jpeg;base64,' . base64_encode( str_repeat( 'a', 3 * 1024 * 1024 + 1 ) ), 'x' ) );
	}

	public function test_kassenprodukt_aendern_nur_fuer_pos_artikel() {
		$php = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/POS/POSModule.php' );

		$this->assertStringContainsString( "empty( \$article['pos_only'] )", $php, 'Normale Artikel dürfen nicht über die Kasse geändert werden.' );
		$this->assertTrue( method_exists( \Lindenweg16\Erp\Pages\Kasse::class, 'produkt_aendern' ) );
		$this->assertStringContainsString( "post(path, body", file_get_contents( dirname( __DIR__ ) . '/assets/erp/pages/kasse.js' ) );
	}

	public function test_produkte_auch_ohne_offenen_tag() {
		$php = file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Pages/Kasse.php' );
		$js  = file_get_contents( dirname( __DIR__ ) . '/assets/erp/pages/kasse.js' );

		$this->assertStringContainsString( 'Auch ohne offenen Tag', $php );
		$this->assertDoesNotMatchRegularExpression( '/if \( \$offen \) \{\s*foreach \( POSModule::sale_options/', $php );
		$this->assertStringContainsString( 'paintStart(d); paintProdukte(d);', $js );
	}
}
