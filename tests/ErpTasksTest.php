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
				array( 'kind' => 'dauerbeleg', 'title' => 'd' ),
				array( 'kind' => 'rechnung', 'title' => 'g' ),
				array( 'kind' => 'lieferung', 'title' => 'l' ),
				array( 'kind' => 'wartung', 'title' => 'w' ),
				array( 'kind' => 'bestellen', 'title' => 'b' ),
				array( 'kind' => 'melden', 'title' => 'x' ),
				array( 'kind' => 'packen', 'title' => 'p' ),
				array( 'kind' => 'ruesten', 'title' => 'r' ),
				array( 'kind' => 'montage', 'title' => 'mo' ),
			)
		);

		$this->assertSame( array( 'melden', 'ruesten', 'montage', 'packen', 'wartung', 'bestellen', 'lieferung', 'rechnung', 'dauerbeleg', 'nachfassen', 'mail' ), array_column( $sorted, 'kind' ) );
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

		foreach ( array( 'fertigung.workflow', 'verkauf.versand', 'lager.einkauf', 'verwaltung.mailbox', 'fertigung.drucker', 'verkauf.kontakte', 'finanzen.buchhaltung' ) as $perm ) {
			$this->assertStringContainsString( "Access::can( '$perm' )", $src );
		}
	}

	public function test_rechnung_zur_dauerbuchung_hochladen() {
		$this->assertNull( Tasks::dauerbeleg_task( array() ) );

		$one = Tasks::dauerbeleg_task( array( array( 'bezeichnung' => 'Hosting', 'faellig_am' => '2026-11-01' ) ) );
		$this->assertSame( 'dauerbeleg', $one['kind'] );
		$this->assertSame( '1 Rechnung zur Dauerbuchung hochladen', $one['title'] );
		$this->assertSame( 'Hosting · fällig seit 01.11.2026', $one['sub'] );
		$this->assertSame( '#/seite/buchhaltung/dauer', $one['href'] );

		$many = Tasks::dauerbeleg_task(
			array(
				(object) array( 'bezeichnung' => 'Telefon', 'faellig_am' => '2026-10-01' ),
				(object) array( 'bezeichnung' => 'Telefon', 'faellig_am' => '2026-11-01' ),
				(object) array( 'bezeichnung' => 'Software', 'faellig_am' => '2026-11-01' ),
				(object) array( 'bezeichnung' => 'Strom', 'faellig_am' => '2026-11-02' ),
			)
		);
		$this->assertSame( '4 Rechnungen zu Dauerbuchungen hochladen', $many['title'] );
		$this->assertSame( 'Telefon, Software … · fällig seit 01.10.2026', $many['sub'] );
	}

	public function test_buchhaltung_nicht_an_der_wand() {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Tasks.php' );

		$this->assertStringContainsString( "if ( ! \$everything && Access::can( 'finanzen.buchhaltung' ) )", $src );
	}
}
