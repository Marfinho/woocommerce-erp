<?php
/**
 * Die Bildschirmfarben der Vorschau.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Configurator\ColorHex;

/**
 * Prüft Zuordnung und Rückfallwert.
 */
class KonfiguratorColorHexTest extends TestCase {

	/**
	 * Bekannte Farben kommen aus der Zuordnung.
	 */
	public function test_bekannte_farbe() {
		$this->assertSame( '#1c1c1c', ColorHex::for_slug( 'schwarz' ) );
		$this->assertSame( '#f2f2ef', ColorHex::for_slug( 'WEISS' ) );
	}

	/**
	 * Eine unbekannte Farbe wird neutral dargestellt, nicht geraten.
	 */
	public function test_unbekannte_farbe() {
		$this->assertSame( ColorHex::FALLBACK, ColorHex::for_slug( 'seegruen-metallic' ) );
	}

	/**
	 * Kurzschreibweisen werden ausgeschrieben.
	 */
	public function test_kurze_schreibweise() {
		$this->assertSame( '#aabbcc', ColorHex::normalize( 'abc' ) );
		$this->assertSame( '#aabbcc', ColorHex::normalize( '#AABBCC' ) );
	}

	/**
	 * Was kein Farbwert ist, wird nicht zu einem.
	 */
	public function test_unfug_wird_neutral() {
		$this->assertSame( ColorHex::FALLBACK, ColorHex::normalize( 'rot wie eine Tomate' ) );
	}
}
