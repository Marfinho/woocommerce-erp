<?php
/**
 * Farbwahl auf der Nachbestellseite.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\ReorderPage;
use PHPUnit\Framework\TestCase;

/**
 * Ein Formularwert darf nie ungeprüft zur Farbe einer Bestellposition werden.
 */
class ReorderColorTest extends TestCase {

	/**
	 * Bekannte Farben der Testinstallation.
	 *
	 * @return array
	 */
	protected function known() {
		return array(
			'anthrazit' => 'Anthrazit',
			'weiss'     => 'Weiß',
			'holz'      => 'Holzoptik',
		);
	}

	/**
	 * Eine gepflegte Farbe kommt unverändert durch.
	 */
	public function test_bekannte_farbe_bleibt() {
		$this->assertSame( 'weiss', ReorderPage::pick_color( 'weiss', $this->known(), 'anthrazit' ) );
	}

	/**
	 * Umlaute und Großschreibung führen auf denselben Slug.
	 */
	public function test_schreibweise_wird_normalisiert() {
		$this->assertSame( 'weiss', ReorderPage::pick_color( 'Weiß', $this->known(), 'anthrazit' ) );
	}

	/**
	 * Eine unbekannte Farbe fällt auf den Standard zurück, nicht auf sich selbst.
	 */
	public function test_unbekannte_farbe_faellt_zurueck() {
		$this->assertSame( 'anthrazit', ReorderPage::pick_color( 'neongruen', $this->known(), 'anthrazit' ) );
	}

	/**
	 * Ohne Auswahl gilt der Standard.
	 */
	public function test_leerer_wert_faellt_zurueck() {
		$this->assertSame( 'anthrazit', ReorderPage::pick_color( '', $this->known(), 'anthrazit' ) );
	}

	/**
	 * Kein Rohwert überlebt: auch Markup landet nicht in der Position.
	 */
	public function test_markup_landet_nicht_in_der_position() {
		$roh = '<script>alert(1)</script>';
		$this->assertSame( 'anthrazit', ReorderPage::pick_color( $roh, $this->known(), 'anthrazit' ) );
	}
}
