<?php
/**
 * Die Druckerakte wird mit dem Bambu-Schlüssel geöffnet, nicht mit der Geräte-ID.
 *
 * `Screens::printer_file()` sucht über `printer_id` (z. B. `bambu_p1s_1`). Die
 * Geräteliste unter „3D-Drucker & Wartung“ verlinkte aber mit der Zahl des
 * Geräts – jeder Klick auf „Akte“ endete in „Diesen Drucker gibt es nicht
 * (mehr)“.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

/**
 * Verdrahtung des Akte-Links.
 */
class DruckerAkteLinkTest extends TestCase {

	/**
	 * Die Geräteliste verlinkt die Akte nicht mit der Geräte-ID.
	 */
	public function test_geraeteliste_verlinkt_die_akte_ueber_den_bambu_schluessel() {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/erp/pages/produktion.js' );

		$this->assertStringNotContainsString( "'#/drucker/' + x.id", $js );
		$this->assertStringContainsString( "x.bambu ?", $js, 'Ohne verknüpften Bambu-Drucker darf es keinen Akte-Knopf geben.' );
	}

	/**
	 * Der Server liefert den Schlüssel mit.
	 */
	public function test_geraetezeile_traegt_den_bambu_schluessel() {
		$php = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Pages/Produktion.php' );

		$this->assertStringContainsString( "'bambu'        => \$bambu,", $php );
		$this->assertStringContainsString( 'geraet_id === (int) $printer[\'id\']', $php );
	}
}
