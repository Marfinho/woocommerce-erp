<?php
/**
 * Neuberechnung der Block-Kasse ohne Rechnungsadresse.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Core\StoreApiCompat;

/**
 * Tests der reinen Regeln von StoreApiCompat.
 */
class StoreApiCompatTest extends TestCase {

	/**
	 * Nur die Neuberechnung ohne Adresse wird ergänzt.
	 */
	public function test_needs_billing_only_for_calc_totals_without_address() {
		$this->assertTrue( StoreApiCompat::needs_billing( array( '__experimental_calc_totals' => 'true' ) ) );
		$this->assertTrue( StoreApiCompat::needs_billing( array( '__experimental_calc_totals' => true, 'payment_method' => 'ppcp-gateway' ) ) );
		$this->assertFalse( StoreApiCompat::needs_billing( array( '__experimental_calc_totals' => 'true', 'billing_address' => array( 'city' => 'Berlin' ) ) ) );
		// Die echte Bestellung ist nie betroffen.
		$this->assertFalse( StoreApiCompat::needs_billing( array( 'payment_method' => 'ppcp-gateway' ) ) );
		$this->assertFalse( StoreApiCompat::needs_billing( array( '__experimental_calc_totals' => 'false' ) ) );
	}

	/**
	 * Leere Rechnungsfelder kommen aus der Lieferadresse, die E-Mail nie.
	 */
	public function test_billing_from_falls_back_to_shipping() {
		$out = StoreApiCompat::billing_from(
			array( 'city' => 'Hamburg', 'country' => 'DE', 'email' => '' ),
			array( 'city' => 'Berlin', 'postcode' => '10115', 'address_1' => 'Musterstr. 1', 'email' => 'x@example.com' )
		);
		$this->assertSame( 'Hamburg', $out['city'] );
		$this->assertSame( '10115', $out['postcode'] );
		$this->assertSame( 'Musterstr. 1', $out['address_1'] );
		$this->assertSame( '', $out['email'] );
		$this->assertCount( 11, $out );
	}

	/**
	 * Die Kasse schickt die Neuberechnung als PUT (Method-Override); der Fix muss sie sehen.
	 */
	public function test_handles_put_from_method_override() {
		$this->assertTrue( StoreApiCompat::handles_method( 'PUT' ) );
		$this->assertTrue( StoreApiCompat::handles_method( 'post' ) );
		$this->assertTrue( StoreApiCompat::handles_method( 'PATCH' ) );
		$this->assertFalse( StoreApiCompat::handles_method( 'GET' ) );
		$this->assertFalse( StoreApiCompat::handles_method( 'DELETE' ) );
	}

	/**
	 * Ohne geladene Kundendaten wird der Warenkorb geladen statt aufzugeben.
	 */
	public function test_loads_cart_before_giving_up() {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/Core/StoreApiCompat.php' );
		$this->assertStringContainsString( 'wc_load_cart()', $src );
		$this->assertStringNotContainsString( "'POST' !== \$request->get_method()", $src, 'Nur POST zu prüfen übersieht die PUT-Neuberechnung der Kasse.' );
	}
}
