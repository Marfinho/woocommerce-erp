<?php
/**
 * Prüft, dass Passwörter beim Speichern nicht verändert werden.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Core\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Passwort-Tests.
 */
class SettingsSecretTest extends TestCase {

	public function test_sonderzeichen_bleiben_erhalten() {
		foreach ( array( 'Pa<ss>wort1!', 'abc%ab12', '  mit Leerzeichen  ', 'Ä&ü#$"\'', 'a<b' ) as $pw ) {
			$this->assertSame( $pw, Settings::clean_secret( $pw ), $pw );
		}
	}

	public function test_steuerzeichen_aus_der_zwischenablage_fallen_weg() {
		$this->assertSame( 'geheim', Settings::clean_secret( "geheim\r\n" ) );
		$this->assertSame( 'geheim', Settings::clean_secret( "ge\theim\0" ) );
	}
}
