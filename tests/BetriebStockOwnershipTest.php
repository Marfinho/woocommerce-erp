<?php
/**
 * Lagerhoheit: Wer bucht den Abgang?
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\StockOwnership;

/**
 * Genau ein Modul bucht je Produkt – niemals beide, niemals keins.
 */
class BetriebStockOwnershipTest extends TestCase {

	/**
	 * Bei aktiver Übernahme bucht Betrieb die ihm bekannten Artikel.
	 */
	public function test_betrieb_bucht_bekannte_artikel() {
		$this->assertSame( 'betrieb', StockOwnership::decide( 42, true ) );
	}

	/**
	 * Produkte ohne Artikel im Stamm bleiben beim Lager-Modul – auch bei
	 * aktiver Übernahme. Sonst würde niemand abbuchen.
	 */
	public function test_unbekannte_produkte_bleiben_bei_inventory() {
		$this->assertSame( 'inventory', StockOwnership::decide( 0, true ) );
	}

	/**
	 * Ist die Übernahme abgeschaltet, bucht das Lager-Modul alles – auch
	 * bekannte Artikel. Genau eines der beiden Module ist zuständig.
	 */
	public function test_ohne_uebernahme_bucht_inventory_alles() {
		$this->assertSame( 'inventory', StockOwnership::decide( 42, false ) );
		$this->assertSame( 'inventory', StockOwnership::decide( 0, false ) );
	}

	/**
	 * Die Entscheidung ist in jeder Kombination eindeutig: genau ein Modul.
	 */
	public function test_entscheidung_ist_immer_eindeutig() {
		foreach ( array( 0, 1, 42 ) as $article_id ) {
			foreach ( array( true, false ) as $active ) {
				$this->assertContains(
					StockOwnership::decide( $article_id, $active ),
					array( 'betrieb', 'inventory' ),
					'Es muss immer genau ein zuständiges Modul geben.'
				);
			}
		}
	}
}
