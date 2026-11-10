<?php
/**
 * Wunschliste: Merkliste je Anfrage einmal laden statt je Produkt fragen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Retention\Wishlist;
use PHPUnit\Framework\TestCase;

/**
 * Tests der Wunschliste.
 */
class WishlistTest extends TestCase {

	public function test_produktnummern_zum_nachschlagen() {
		$this->assertSame( array( 12 => true, 7 => true ), Wishlist::index_ids( array( '12', 7, '12' ) ) );
		$this->assertSame( array(), Wishlist::index_ids( array( 0, '', 'x', -3 ) ) );
		$this->assertSame( array(), Wishlist::index_ids( null ) );
	}

	public function test_eine_abfrage_je_anfrage_und_merker_wird_verworfen() {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Retention/Wishlist.php' );

		preg_match( '/public function has\(.*?\n\t}/s', $src, $m );
		$this->assertNotEmpty( $m );
		$this->assertStringContainsString( 'self::$merkliste', $m[0] );
		$this->assertStringNotContainsString( 'woo_product_id = %d', $m[0], 'Keine Abfrage je Produkt.' );

		// Nach dem Umschalten gilt der alte Stand nicht mehr.
		$this->assertSame( 2, substr_count( $src, 'unset( self::$merkliste[ $user_id ] );' ) );
	}
}
