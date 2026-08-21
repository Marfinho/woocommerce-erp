<?php
/**
 * Der QR-Link von einem Fertigungsauftrag auf das Handy.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\DeviceLabel;
use PHPUnit\Framework\TestCase;

/**
 * Der Link muss die Pickliste mitbringen – sonst landet der Werker auf
 * der Übersicht und sucht sich die richtige Liste selbst.
 */
class BetriebPickLinkTest extends TestCase {

	/**
	 * Sprechende Permalinks aktivieren, sonst greift die Fragezeichenform.
	 */
	protected function setUp(): void {
		$GLOBALS['lw16_test_options']['permalink_structure'] = '/%postname%/';
	}

	/**
	 * Aufräumen, damit andere Tests die Option nicht erben.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['lw16_test_options']['permalink_structure'] );
	}

	/**
	 * Der Code der Pickliste steht als Parameter in der Adresse.
	 */
	public function test_code_steht_in_der_adresse() {
		$url = DeviceLabel::pick_url( 'PL2608-03' );

		$this->assertStringContainsString( 'betrieb-erfassen', $url );
		$this->assertStringContainsString( 'pick=PL2608-03', $url );
	}

	/**
	 * Ohne Code bleibt es die normale Erfassungsadresse – kein leerer
	 * Parameter, der am Handy zu „Liste nicht gefunden" führen würde.
	 */
	public function test_ohne_code_die_normale_adresse() {
		$url = DeviceLabel::pick_url( '' );

		$this->assertStringNotContainsString( 'pick=', $url );
		$this->assertSame( DeviceLabel::url(), $url );
	}

	/**
	 * Der Link ergibt einen scanbaren QR-Code.
	 */
	public function test_link_wird_zum_qr_code() {
		$svg = DeviceLabel::qr( DeviceLabel::pick_url( 'PL2608-03' ), 96, 'M' );

		$this->assertStringStartsWith( '<svg', $svg );
	}
}
