<?php
/**
 * Verzögertes Laden der Seitenklassen.
 *
 * Seit 0.76.1 werden die Betrieb-Seitenklassen nur geladen, wenn ihre Seite
 * offen ist oder ein Formular/AJAX-Aufruf kommt. Fehlt eine Seite in der
 * Zuordnung, bliebe sie leer – deshalb wird die Zuordnung hier gegen die
 * Render-Hooks im Quelltext geprüft.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Core\Lazy;
use Lindenweg16\Erp\Shell;
use Lindenweg16\Modules\Betrieb\BetriebModule;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

/**
 * Tests für Core\Lazy.
 */
class CoreLazyTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['pagenow'], $_GET['page'] );
	}

	public function test_seite_wird_nur_auf_ihrer_seite_geladen() {
		$GLOBALS['pagenow'] = 'admin.php';
		$_GET['page']       = 'lw16-betrieb-artikel';
		$this->assertTrue( Lazy::page_needed( array( 'lw16-betrieb-artikel' ) ) );
		$this->assertFalse( Lazy::page_needed( array( 'lw16-betrieb-lager' ) ) );
		$this->assertFalse( Lazy::page_needed( array() ) );
	}

	public function test_auf_dem_dashboard_wird_keine_seite_geladen() {
		$GLOBALS['pagenow'] = 'index.php';
		$this->assertFalse( Lazy::page_needed( array( 'lw16-betrieb-artikel' ) ) );
		$this->assertFalse( Lazy::page_needed( array() ) );
	}

	public function test_formulare_und_ajax_laden_alles() {
		foreach ( array( 'admin-post.php', 'admin-ajax.php' ) as $file ) {
			$GLOBALS['pagenow'] = $file;
			$this->assertTrue( Lazy::page_needed( array( 'lw16-betrieb-artikel' ) ), $file );
			$this->assertTrue( Lazy::page_needed( array() ), $file );
		}
	}

	public function test_method_laedt_erst_beim_aufruf_und_nur_einmal() {
		$cb = Lazy::method( CoreLazyProbe::class, 'hello' );
		$this->assertSame( 0, CoreLazyProbe::$made );
		$this->assertSame( 'hallo welt', $cb( 'welt' ) );
		$cb( 'nochmal' );
		$this->assertSame( 1, CoreLazyProbe::$made );
	}

	/**
	 * Jede Render-Seite einer Betrieb-Seitenklasse steht in page_classes().
	 */
	public function test_zuordnung_passt_zu_den_render_hooks() {
		$root = dirname( __DIR__ ) . '/includes/';
		foreach ( BetriebModule::page_classes() as $class => $slugs ) {
			$file = $root . str_replace( array( 'Lindenweg16\\', '\\' ), array( '', '/' ), $class ) . '.php';
			$this->assertFileExists( $file );
			preg_match_all( "/'lw16_betrieb_render_(lw16_betrieb_[a-z_]+)'/", (string) file_get_contents( $file ), $m );
			$found = array_map(
				static function ( $hook ) {
					return str_replace( '_', '-', $hook );
				},
				$m[1]
			);
			sort( $found );
			sort( $slugs );
			$this->assertSame( $found, $slugs, $class );
		}
	}

	/**
	 * Jede Klasse mit register() im Admin-Ordner ist zugeordnet – keine geht verloren.
	 */
	public function test_keine_seitenklasse_fehlt() {
		$mapped = array_keys( BetriebModule::page_classes() );
		$src    = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/BetriebModule.php' );
		preg_match( '/if \( is_admin\(\) \) \{(.*?)\n\t\t\}/s', $src, $m );
		$this->assertNotEmpty( $m );
		$this->assertStringNotContainsString( 'Page() )->register()', $m[1], 'Seitenklassen gehören in page_classes()' );
		$this->assertCount( 17, $mapped );
	}

	public function test_wpadmin_schalter_wie_die_workflow_einstellung() {
		$GLOBALS['lw16_test_options']['lw16_erp_workflow'] = array( 'hide_wpadmin' => 1, 'lock_wpadmin' => 0 );
		$this->assertTrue( Shell::wpadmin_flag( 'hide_wpadmin' ) );
		$this->assertFalse( Shell::wpadmin_flag( 'lock_wpadmin' ) );
		unset( $GLOBALS['lw16_test_options']['lw16_erp_workflow'] );
		$this->assertFalse( Shell::wpadmin_flag( 'hide_wpadmin' ) );
	}
}

/**
 * Hilfsklasse: zählt, wie oft sie erzeugt wird.
 */
class CoreLazyProbe {
	public static $made = 0;
	public function __construct() {
		++self::$made;
	}
	public function hello( $who ) {
		return 'hallo ' . $who;
	}
}
