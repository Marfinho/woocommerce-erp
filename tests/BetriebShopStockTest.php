<?php
/**
 * Bestand für den Shop: Lager minus Reservierung.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\ShopStock;

/**
 * Der Shop bietet nie mehr an, als frei im Lager liegt.
 */
class BetriebShopStockTest extends TestCase {

	/**
	 * Nur der Lagerort „lager" ist verkäuflich.
	 */
	public function test_nur_lager_zaehlt() {
		$stock = ShopStock::stock_by_color(
			array(
				'anthrazit' => array( 'lager' => 10.0, 'kommission' => 4.0, 'unterwegs' => 2.0 ),
				'kupfer'    => array( 'kommission' => 5.0 ),
			)
		);

		$this->assertSame( 10.0, $stock['anthrazit'] );
		$this->assertSame( 0.0, $stock['kupfer'] );
	}

	/**
	 * Reservierungen gelten je Artikel und Farbe.
	 */
	public function test_reservierung_je_artikel_und_farbe() {
		$lines = array(
			array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 2 ),
			array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 1 ),
			array( 'article_id' => 1, 'color' => 'kupfer', 'qty' => 4 ),
			array( 'article_id' => 2, 'color' => 'anthrazit', 'qty' => 9 ),
		);

		$this->assertSame( array( 'anthrazit' => 3.0, 'kupfer' => 4.0 ), ShopStock::reserved_by_color( $lines, 1 ) );
		$this->assertSame( array(), ShopStock::reserved_by_color( $lines, 3 ) );
	}

	/**
	 * Verfügbar ist Lager minus Reservierung, nie negativ, nur ganze Stück.
	 */
	public function test_verfuegbar_nie_negativ_und_ganzzahlig() {
		$available = ShopStock::available(
			array( 'anthrazit' => 10.0, 'kupfer' => 2.0, 'weiss' => 5.5 ),
			array( 'anthrazit' => 3.0, 'kupfer' => 4.0 )
		);

		$this->assertSame( 7, $available['anthrazit'] );
		$this->assertSame( 0, $available['kupfer'], 'Überreservierung ergibt null, nicht minus zwei.' );
		$this->assertSame( 5, $available['weiss'] );
	}

	/**
	 * Varianten bekommen die Menge ihrer Farbe; ohne Farbe gilt die Standardfarbe.
	 */
	public function test_mengen_je_variante() {
		$qty = ShopStock::per_variation(
			array( 101 => 'anthrazit', 102 => 'kupfer', 103 => '', 104 => 'unbekannt' ),
			array( 'anthrazit' => 7, 'kupfer' => 0 ),
			'anthrazit'
		);

		$this->assertSame( array( 101 => 7, 102 => 0, 103 => 7, 104 => 0 ), $qty );
	}

	/**
	 * Ein einfaches Produkt zählt alle Farben zusammen.
	 */
	public function test_einfaches_produkt_summiert_farben() {
		$this->assertSame(
			6,
			ShopStock::simple_quantity( array( 'anthrazit' => 5.0, 'kupfer' => 3.0, 'ungeklaert' => -1.0 ), array( 'anthrazit' => 1.0 ) )
		);
		$this->assertSame( 0, ShopStock::simple_quantity( array( 'anthrazit' => 1.0 ), array( 'anthrazit' => 5.0 ) ) );
	}
}
