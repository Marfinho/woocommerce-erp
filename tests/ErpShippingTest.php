<?php
/**
 * Versand-Workflow: Lagerabgang beim Versand.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

class ErpShippingTest extends TestCase {

	/**
	 * @return string
	 */
	private function source() {
		return (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Screens.php' );
	}

	public function test_lager_wird_vor_dem_abschluss_gebucht() {
		$src   = $this->source();
		$start = strpos( $src, 'function mark_shipped' );

		$this->assertNotFalse( $start );

		$body = substr( $src, $start );

		$book   = strpos( $body, 'book_stock(' );
		$status = strpos( $body, "update_status( 'completed'" );

		$this->assertNotFalse( $book, 'Der Lagerabgang muss beim Versand gebucht werden.' );
		$this->assertNotFalse( $status );
		$this->assertLessThan( $status, $book, 'Erst Lager buchen, dann abschließen.' );
	}

	public function test_nur_versandbereite_bestellungen_werden_gemeldet() {
		$src = $this->source();

		$this->assertStringContainsString( "array( 'processing', 'on-hold' )", $src );
	}

	public function test_rest_routen_fuer_packseite_und_versendet() {
		$rest = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/RestApi.php' );

		$this->assertStringContainsString( "'/erp/versand/(?P<id>\\d+)/versendet'", $rest );
		$this->assertStringContainsString( 'Screens::mark_shipped', $rest );
		$this->assertStringContainsString( 'Screens::ship_order', $rest );
	}
}
