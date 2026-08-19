<?php
/**
 * Historisierung der Beschaffungsdaten.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\SourcingRepository;

/**
 * Ein Preiswechsel des Lieferanten erzeugt eine neue Zeile, keine
 * Überschreibung – alte Kalkulationen bleiben nachrechenbar.
 */
class BetriebSourcingTest extends TestCase {

	/**
	 * Zwei Artikel, für die Stange zwei Preisstände.
	 *
	 * @return array
	 */
	protected function rows() {
		return array(
			array( 'article_id' => 100, 'purchase_price_net' => 6.40, 'valid_from' => '2026-09-01', 'supplier_name' => 'Holz Meyer' ),
			array( 'article_id' => 100, 'purchase_price_net' => 7.10, 'valid_from' => '2027-02-01', 'supplier_name' => 'Holz Meyer' ),
			array( 'article_id' => 200, 'purchase_price_net' => 1.20, 'valid_from' => '2026-11-15', 'supplier_name' => 'Beschlag Nord' ),
		);
	}

	/**
	 * Zum ersten Stichtag gilt der erste Preis.
	 */
	public function test_erster_preisstand() {
		$result = SourcingRepository::resolve( $this->rows(), '2026-10-01' );

		$this->assertEqualsWithDelta( 6.40, $result[100]['purchase_price_net'], 0.00001 );
		$this->assertArrayNotHasKey( 200, $result, 'Der zweite Artikel gilt erst ab November.' );
	}

	/**
	 * Am Tag des Preiswechsels gilt der neue Preis.
	 */
	public function test_preiswechsel_am_stichtag() {
		$result = SourcingRepository::resolve( $this->rows(), '2027-02-01' );

		$this->assertEqualsWithDelta( 7.10, $result[100]['purchase_price_net'], 0.00001 );
	}

	/**
	 * Einen Tag davor noch der alte.
	 */
	public function test_tag_vor_preiswechsel() {
		$result = SourcingRepository::resolve( $this->rows(), '2027-01-31' );

		$this->assertEqualsWithDelta( 6.40, $result[100]['purchase_price_net'], 0.00001 );
	}

	/**
	 * Eine Kalkulation aus 2026 rechnet auch 2028 noch mit dem damaligen
	 * Einkaufspreis.
	 */
	public function test_alte_kalkulation_bleibt_nachrechenbar() {
		$damals = SourcingRepository::resolve( $this->rows(), '2026-12-01' );
		$heute  = SourcingRepository::resolve( $this->rows(), '2028-06-01' );

		$this->assertEqualsWithDelta( 6.40, $damals[100]['purchase_price_net'], 0.00001 );
		$this->assertEqualsWithDelta( 7.10, $heute[100]['purchase_price_net'], 0.00001 );
	}

	/**
	 * Zukünftige Zeilen werden ignoriert – ein bereits eingetragener
	 * Preiswechsel darf nicht vorzeitig greifen.
	 */
	public function test_zukuenftige_zeilen_greifen_nicht() {
		$result = SourcingRepository::resolve( $this->rows(), '2026-09-15' );

		$this->assertCount( 1, $result );
		$this->assertEqualsWithDelta( 6.40, $result[100]['purchase_price_net'], 0.00001 );
	}

	/**
	 * DATETIME-Spalten aus MySQL werden akzeptiert.
	 */
	public function test_datetime_spalte() {
		$rows = array(
			array( 'article_id' => 100, 'purchase_price_net' => 5.00, 'valid_from' => '2026-09-01 00:00:00' ),
		);

		$result = SourcingRepository::resolve( $rows, '2026-09-01' );

		$this->assertEqualsWithDelta( 5.00, $result[100]['purchase_price_net'], 0.00001 );
	}
}
