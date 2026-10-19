<?php
/**
 * Fehlermeldungen im ERP: Beschreibung statt Nummer.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

class ErpFehlertextTest extends TestCase {

	public function test_oberflaeche_liest_auch_das_feld_error() {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/erp/erp.js' );

		$this->assertStringContainsString( 'json.message || json.error', $js );
		$this->assertStringNotContainsString( "'Fehler ' + res.status", $js );
	}

	public function test_hinweise_nennen_artikelnummer_und_name() {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Nesting/PlateService.php' );

		$this->assertStringContainsString( "\$article['sku'] . ' – ' . \$article['name']", $src );
		$this->assertStringNotContainsString( '– übersprungen', $src );
	}
}
