<?php
/**
 * Weihnachts-Leiste: Phasen und Stichtag.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Marketing\Saisonleiste;

/**
 * Reine Regeln der Saisonleiste.
 */
class SaisonleisteTest extends TestCase {

	/**
	 * Vom 1.11. bis Stichtag liefern, danach Gutschein, ab 24.12. nichts.
	 */
	public function test_phasen() {
		$this->assertSame( '', Saisonleiste::phase( 1031, 1214 ) );
		$this->assertSame( 'liefern', Saisonleiste::phase( 1101, 1214 ) );
		$this->assertSame( 'liefern', Saisonleiste::phase( 1214, 1214 ) );
		$this->assertSame( 'gutschein', Saisonleiste::phase( 1215, 1214 ) );
		$this->assertSame( 'gutschein', Saisonleiste::phase( 1223, 1214 ) );
		$this->assertSame( '', Saisonleiste::phase( 1224, 1214 ) );
		$this->assertSame( '', Saisonleiste::phase( 615, 1214 ) );
	}

	/**
	 * Stichtag aus „TT.MM.“, Unsinn fällt auf den 14.12. zurück.
	 */
	public function test_stichtag() {
		$this->assertSame( 1214, Saisonleiste::stichtag_mmtt( '14.12.' ) );
		$this->assertSame( 1217, Saisonleiste::stichtag_mmtt( '17.12' ) );
		$this->assertSame( 1205, Saisonleiste::stichtag_mmtt( ' 5.12. ' ) );
		$this->assertSame( 1214, Saisonleiste::stichtag_mmtt( '31.02.' ) );
		$this->assertSame( 1214, Saisonleiste::stichtag_mmtt( '28.12.' ) );
		$this->assertSame( 1214, Saisonleiste::stichtag_mmtt( 'Weihnachten' ) );
		$this->assertSame( '14.12.', Saisonleiste::datum( 1214 ) );
		$this->assertSame( '5.12.', Saisonleiste::datum( 1205 ) );
	}
}
