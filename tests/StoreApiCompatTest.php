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
	 * Für den Kassenstand fällt nur die Neuberechnung weg, alles andere bleibt.
	 */
	public function test_query_for_state_drops_calc_totals_only() {
		$this->assertSame(
			array( '_locale' => 'site' ),
			StoreApiCompat::query_for_state( array( '__experimental_calc_totals' => 'true', '_locale' => 'site', '_method' => 'PUT' ) )
		);
	}

	/**
	 * Keine Adresse ergänzen und nie in den Bestellpfad: Die Antwort kommt von GET /checkout.
	 */
	public function test_answers_with_checkout_state_instead_of_injecting() {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/Core/StoreApiCompat.php' );
		$this->assertStringContainsString( "new \\WP_REST_Request( 'GET', self::ROUTE )", $src );
		$this->assertStringContainsString( 'return rest_do_request( $get );', $src );
		$this->assertStringNotContainsString( "set_param( 'billing_address'", $src, 'Eine ergänzte Adresse schob die Neuberechnung in die Bestellprüfung.' );
		$this->assertStringNotContainsString( "'POST' !== \$request->get_method()", $src, 'Nur POST zu prüfen übersieht die PUT-Neuberechnung der Kasse.' );
	}
}
