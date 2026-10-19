<?php
/**
 * Download-Links im ERP: kein HTML in der Adresse.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

class ErpDownloadLinkTest extends TestCase {

	public function test_erp_nutzt_kein_wp_nonce_url() {
		// wp_nonce_url() liefert „&#038;“; im href zerlegt das die Adresse (Lieferschein, Label, Live-Bild, Datei).
		foreach ( (array) glob( dirname( __DIR__ ) . '/includes/Erp/*.php' ) as $file ) {
			$code = (string) file_get_contents( $file );
			$code = preg_replace( '#/\*.*?\*/#s', '', $code );
			$this->assertStringNotContainsString( 'wp_nonce_url(', $code, basename( $file ) );
		}
	}

	public function test_lieferschein_und_live_bild_haben_keine_feinrechte_sperre_die_den_packtisch_aussperrt() {
		$rules = \Lindenweg16\Erp\ActionMap::rules();

		$this->assertArrayNotHasKey( 'lw16_packliste', $rules );
		$this->assertArrayNotHasKey( 'lw16_bambu_snapshot', $rules );
		$this->assertArrayHasKey( 'lw16_packliste', \Lindenweg16\Erp\ActionMap::exempt() );
	}
}
