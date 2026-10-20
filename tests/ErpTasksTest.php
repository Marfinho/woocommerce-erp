<?php
/**
 * „Heute“: Reihenfolge und Texte der Aufgaben.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Tasks;
use PHPUnit\Framework\TestCase;

class ErpTasksTest extends TestCase {

	public function test_fertig_gedrucktes_steht_vor_allem_anderen() {
		$sorted = Tasks::sort(
			array(
				array( 'kind' => 'mail', 'title' => 'm' ),
				array( 'kind' => 'nachfassen', 'title' => 'n' ),
				array( 'kind' => 'rechnung', 'title' => 'g' ),
				array( 'kind' => 'lieferung', 'title' => 'l' ),
				array( 'kind' => 'wartung', 'title' => 'w' ),
				array( 'kind' => 'bestellen', 'title' => 'b' ),
				array( 'kind' => 'melden', 'title' => 'x' ),
				array( 'kind' => 'packen', 'title' => 'p' ),
				array( 'kind' => 'ruesten', 'title' => 'r' ),
			)
		);

		$this->assertSame( array( 'melden', 'ruesten', 'packen', 'wartung', 'bestellen', 'lieferung', 'rechnung', 'nachfassen', 'mail' ), array_column( $sorted, 'kind' ) );
	}

	public function test_unbekannte_arten_kommen_zuletzt() {
		$sorted = Tasks::sort( array( array( 'kind' => 'neu' ), array( 'kind' => 'mail' ) ) );

		$this->assertSame( 'mail', $sorted[0]['kind'] );
	}

	public function test_ein_und_mehrzahl() {
		$this->assertSame( '1 Bestellung versenden', Tasks::count_text( 1, '%d Bestellung versenden', '%d Bestellungen versenden' ) );
		$this->assertSame( '3 Bestellungen versenden', Tasks::count_text( 3, '%d Bestellung versenden', '%d Bestellungen versenden' ) );
	}

	public function test_aufgaben_nur_mit_passendem_recht() {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Tasks.php' );

		foreach ( array( 'fertigung.workflow', 'verkauf.versand', 'lager.einkauf', 'verwaltung.mailbox', 'fertigung.drucker', 'verkauf.kontakte' ) as $perm ) {
			$this->assertStringContainsString( "Access::can( '$perm' )", $src );
		}
	}
}
