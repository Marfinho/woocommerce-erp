<?php
/**
 * Kamera-Standbilder nur, wenn jemand zuschaut.
 *
 * Die Bridge lud früher alle 10 Sekunden ein Bild hoch, rund um die Uhr –
 * jedes davon startet auf dem Webspace WordPress. Seit 0.76.2 vermerkt der
 * Abruf des Bildes, dass jemand zuschaut; die Bridge erfährt das über das
 * Feld „camera“ in der Antwort auf /status und /snapshot.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\Bambu\BambuService;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return isset( $GLOBALS['lw16_test_transients'][ $key ] ) ? $GLOBALS['lw16_test_transients'][ $key ] : false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['lw16_test_transients'][ $key ] = $value;
		$GLOBALS['lw16_test_transient_writes']   = ( isset( $GLOBALS['lw16_test_transient_writes'] ) ? $GLOBALS['lw16_test_transient_writes'] : 0 ) + 1;
		return true;
	}
}

/**
 * Tests für BambuService::mark_camera_watched() / camera_watched().
 */
class BambuKameraZuschauerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['lw16_test_transients']      = array();
		$GLOBALS['lw16_test_transient_writes'] = 0;
	}

	public function test_ohne_zuschauer_keine_bilder() {
		$this->assertFalse( BambuService::camera_watched( 'x1c_1', 1000 ) );
	}

	public function test_nach_dem_abruf_zwei_minuten_lang_bilder() {
		BambuService::mark_camera_watched( 'x1c_1', 1000 );
		$this->assertTrue( BambuService::camera_watched( 'x1c_1', 1000 ) );
		$this->assertTrue( BambuService::camera_watched( 'x1c_1', 1000 + BambuService::CAMERA_WATCH_SECONDS - 1 ) );
		$this->assertFalse( BambuService::camera_watched( 'x1c_1', 1000 + BambuService::CAMERA_WATCH_SECONDS ) );
	}

	public function test_gilt_je_drucker() {
		BambuService::mark_camera_watched( 'x1c_1', 1000 );
		$this->assertFalse( BambuService::camera_watched( 'x1c_2', 1000 ) );
	}

	public function test_haeufige_abrufe_schreiben_selten() {
		for ( $t = 1000; $t < 1060; $t += 3 ) {
			BambuService::mark_camera_watched( 'x1c_1', $t );
		}
		// 60 Sekunden Ansicht, alle 3 Sekunden ein Abruf: höchstens alle 30 Sekunden ein Schreibzugriff.
		$this->assertLessThanOrEqual( 2, $GLOBALS['lw16_test_transient_writes'] );
		// Nach dem letzten Abruf gilt die Ansicht noch mindestens 90 Sekunden (120 minus 30 Schreibabstand).
		$this->assertTrue( BambuService::camera_watched( 'x1c_1', 1059 + 60 ) );
		$this->assertFalse( BambuService::camera_watched( 'x1c_1', 1059 + 130 ) );
	}

	public function test_antworten_der_bridge_enthalten_das_feld() {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Bambu/BambuRestApi.php' );
		$this->assertSame( 2, substr_count( $src, "'camera' => BambuService::camera_watched( \$printer->printer_id )" ) );
		$page = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Admin/BambuPrintersPage.php' );
		$this->assertStringContainsString( 'BambuService::mark_camera_watched( $printer->printer_id )', $page );
	}
}
