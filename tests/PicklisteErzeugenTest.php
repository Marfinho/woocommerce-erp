<?php
/**
 * Das ERP erzeugt Picklisten aus offenen Bestellungen selbst.
 *
 * Vorher ging das nur über den Knopf der Klassischen Ansicht; die native Seite
 * „Picklisten“ konnte Listen nur abarbeiten.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

/**
 * Verdrahtung von Route, Recht und Oberfläche.
 */
class PicklisteErzeugenTest extends TestCase {

	/**
	 * Beide Routen hängen am Recht der klassischen Aktion.
	 */
	public function test_routen_nutzen_das_recht_der_klassischen_aktion() {
		$php = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Pages/Lager.php' );

		$this->assertStringContainsString( "'/erp/picklisten/erzeugen', 'POST', 'pickliste_erzeugen', \$api->needs( \$rules['lw16_betrieb_make_picklist'] )", $php );
		$this->assertStringContainsString( "'/erp/picklisten/bestellungen', 'GET', 'pickliste_vorschau', \$api->needs( \$rules['lw16_betrieb_make_picklist'] )", $php );
		$this->assertTrue( method_exists( \Lindenweg16\Erp\Pages\Lager::class, 'pickliste_erzeugen' ) );
		$this->assertTrue( method_exists( \Lindenweg16\Erp\Pages\Lager::class, 'pickliste_vorschau' ) );
	}

	/**
	 * Die Seite bietet den Knopf an und fragt bei einer offenen Liste nach.
	 */
	public function test_seite_bietet_knopf_und_rueckfrage() {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/erp/erp.js' );

		$this->assertStringContainsString( "api('picklisten/erzeugen'", $js );
		$this->assertStringContainsString( 'open_lists', $js );
		$this->assertStringContainsString( 'window.confirm', $js );
	}
}
