<?php
/**
 * Türklingel zu den Raspberry-Pi-Programmen über Home Assistant.
 *
 * Statt dass die Pi-Programme alle paar Sekunden nachfragen, klingelt
 * WordPress bei einem neuen Auftrag. Hier steht fest: ohne Adresse passiert
 * nichts, nur bekannte Kanäle, mehrfaches Klingeln zählt einmal, nur HTTPS,
 * und an jeder Stelle, an der ein Auftrag in die Warteschlange kommt, wird
 * geklingelt.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\Doorbell;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['lw16_test_remote_posts'][] = array( $url, $args );
		return array( 'response' => array( 'code' => 200 ) );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
	}
}

/**
 * Tests für Betrieb\Doorbell.
 */
class BetriebDoorbellTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['lw16_test_remote_posts'] = array();
		unset( $GLOBALS['lw16_test_options'][ Doorbell::OPTION ] );
		Doorbell::reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lw16_test_options'][ Doorbell::OPTION ] );
		Doorbell::reset();
	}

	private function an() {
		$GLOBALS['lw16_test_options'][ Doorbell::OPTION ] = 'https://hooks.nabu.casa/abc123';
	}

	public function test_ohne_adresse_wird_nicht_geklingelt() {
		Doorbell::ring( 'bambu', 'x1c_1' );
		$this->assertSame( array(), Doorbell::pending() );
		$this->assertSame( array(), Doorbell::flush() );
		$this->assertSame( array(), $GLOBALS['lw16_test_remote_posts'] );
	}

	public function test_mehrfaches_klingeln_zaehlt_einmal() {
		$this->an();
		Doorbell::ring( 'bambu', 'x1c_1' );
		Doorbell::ring( 'bambu', 'x1c_1' );
		Doorbell::ring( 'bambu', 'x1c_2' );
		Doorbell::ring( 'slicing' );
		$this->assertCount( 3, Doorbell::pending() );
	}

	public function test_unbekannte_kanaele_und_drucker_ids_werden_bereinigt() {
		$this->an();
		Doorbell::ring( 'irgendwas' );
		Doorbell::ring( 'etikett', 'Drucker 1/../#' );
		$this->assertSame( array( array( 'channel' => 'etikett', 'printer' => 'drucker1' ) ), Doorbell::pending() );
	}

	public function test_abschicken_ohne_auf_die_antwort_zu_warten() {
		$this->an();
		Doorbell::ring( 'bambu', 'x1c_1' );
		Doorbell::flush();

		$this->assertCount( 1, $GLOBALS['lw16_test_remote_posts'] );
		list( $url, $args ) = $GLOBALS['lw16_test_remote_posts'][0];
		$this->assertSame( 'https://hooks.nabu.casa/abc123', $url );
		$this->assertFalse( $args['blocking'] );
		$this->assertLessThanOrEqual( 2, $args['timeout'] );
		$this->assertSame( array( 'channel' => 'bambu', 'printer' => 'x1c_1' ), json_decode( $args['body'], true ) );
		$this->assertSame( array(), Doorbell::pending() );
	}

	public function test_nur_https() {
		$this->assertTrue( Doorbell::valid_url( '' ) );
		$this->assertTrue( Doorbell::valid_url( 'https://hooks.nabu.casa/abc' ) );
		$this->assertTrue( Doorbell::valid_url( 'https://xyz.ui.nabu.casa/api/webhook/lw16-geheim' ) );
		$this->assertFalse( Doorbell::valid_url( 'http://homeassistant.local:8123/api/webhook/x' ) );
		$this->assertFalse( Doorbell::valid_url( 'javascript:alert(1)' ) );
	}

	/**
	 * Jede Stelle, an der ein Auftrag (wieder) in die Warteschlange kommt, klingelt.
	 */
	public function test_ueberall_wo_auftraege_eingereiht_werden_wird_geklingelt() {
		$root  = dirname( __DIR__ ) . '/includes/';
		$cases = array(
			'Modules/Betrieb/Bambu/BambuJobRepository.php'    => array( "ring( 'bambu', (string) \$data['printer_id'] )", "ring( 'bambu', (string) \$job->printer_id )" ),
			'Modules/Betrieb/Nesting/SlicingJobRepository.php' => array( "ring( 'slicing' )" ),
			'Modules/Betrieb/PrintQueue/PrintJobRepository.php' => array( "ring( 'etikett', (string) \$data['printer_id'] )", "ring( 'etikett' )" ),
			'Erp/Pages/Verwaltung.php'                         => array( "ring( 'etikett', (string) \$job->printer_id )" ),
			'Modules/Betrieb/Bambu/BambuService.php'           => array( "ring( 'kamera', \$printer_id )" ),
		);
		foreach ( $cases as $file => $needles ) {
			$src = (string) file_get_contents( $root . $file );
			foreach ( $needles as $needle ) {
				$this->assertStringContainsString( 'Doorbell::' . $needle, $src, $file );
			}
		}
	}
}
