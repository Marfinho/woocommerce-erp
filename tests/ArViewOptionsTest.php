<?php
/**
 * AR-Ansicht: Einstellungen werden bereinigt und begrenzt.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\ArView\Options;
use PHPUnit\Framework\TestCase;

/**
 * Vorgaben, Grenzen und Textbereinigung.
 */
class ArViewOptionsTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['lw16_test_options'] );
	}

	/**
	 * Die Vorgaben aus der Aufgabenstellung.
	 */
	public function test_vorgaben() {
		$d = Options::defaults();

		$this->assertSame( 7, $d['lock_days'] );
		$this->assertSame( 3, $d['delay'] );
		$this->assertSame( 'Passt der Haster Haken an deine Wand?', $d['title'] );
		$this->assertSame( 'Sieh ihn dir in Originalgröße mit deiner Handykamera an – ohne App.', $d['text'] );
		$this->assertSame( 'Ja, zeig mal', $d['btn_yes'] );
		$this->assertSame( 'Nein, danke', $d['btn_no'] );
	}

	/**
	 * Nie gespeichert: alles aus den Vorgaben, Funktion an.
	 */
	public function test_ohne_gespeicherte_werte_gelten_die_vorgaben() {
		$o = Options::get();

		$this->assertSame( 1, $o['enabled'] );
		$this->assertSame( 0, $o['page_id'] );
		$this->assertSame( 7, $o['lock_days'] );
	}

	/**
	 * Ein abgewähltes Kästchen schaltet aus; fehlender Schlüssel nicht.
	 */
	public function test_schalter() {
		$this->assertSame( 0, Options::sanitize( array( 'enabled' => 0 ) )['enabled'] );
		$this->assertSame( 0, Options::sanitize( array( 'enabled' => '' ) )['enabled'] );
		$this->assertSame( 1, Options::sanitize( array( 'enabled' => '1' ) )['enabled'] );
		$this->assertSame( 1, Options::sanitize( array() )['enabled'] );
	}

	/**
	 * Zahlenfelder bleiben in ihren Grenzen; Unsinn ergibt die Vorgabe.
	 */
	public function test_grenzen() {
		$o = Options::sanitize( array( 'lock_days' => '0', 'delay' => '-5' ) );
		$this->assertSame( 1, $o['lock_days'] );
		$this->assertSame( 0, $o['delay'] );

		$o = Options::sanitize( array( 'lock_days' => '9999', 'delay' => '9999' ) );
		$this->assertSame( 365, $o['lock_days'] );
		$this->assertSame( 60, $o['delay'] );

		$o = Options::sanitize( array( 'lock_days' => 'abc', 'delay' => '' ) );
		$this->assertSame( 7, $o['lock_days'] );
		$this->assertSame( 3, $o['delay'] );

		$this->assertSame( 14, Options::sanitize( array( 'lock_days' => '14' ) )['lock_days'] );
	}

	/**
	 * Texte: Tags fliegen raus, leere Texte fallen auf die Vorgabe zurück.
	 */
	public function test_texte() {
		$o = Options::sanitize(
			array(
				'title'   => '<script>alert(1)</script>Hallo  Wand',
				'text'    => '   ',
				'btn_yes' => 'Los',
			)
		);

		$this->assertStringNotContainsString( '<', $o['title'] );
		$this->assertStringContainsString( 'Hallo Wand', $o['title'] );
		$this->assertSame( Options::defaults()['text'], $o['text'] );
		$this->assertSame( 'Los', $o['btn_yes'] );
		$this->assertSame( 'Nein, danke', $o['btn_no'] );
	}

	/**
	 * Gespeicherte Werte werden beim Lesen erneut bereinigt.
	 */
	public function test_get_liest_gespeicherte_werte() {
		$GLOBALS['lw16_test_options'][ Options::OPTION ] = array( 'lock_days' => 30, 'page_id' => '12', 'enabled' => 0 );

		$o = Options::get();

		$this->assertSame( 30, $o['lock_days'] );
		$this->assertSame( 12, $o['page_id'] );
		$this->assertFalse( Options::enabled() );
	}

	/**
	 * Die Seite-ID kann nie negativ werden.
	 */
	public function test_page_id_nicht_negativ() {
		$this->assertSame( 0, Options::sanitize( array( 'page_id' => '-4' ) )['page_id'] );
	}
}
