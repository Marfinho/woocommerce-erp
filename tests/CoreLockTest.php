<?php
/**
 * Prüft die Namensbildung der Sperren.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Core\Lock;
use PHPUnit\Framework\TestCase;

/**
 * Sperren-Tests.
 */
class CoreLockTest extends TestCase {

	/**
	 * Der Name bleibt innerhalb der Grenze von MySQL.
	 *
	 * Ein längerer Name wird von MySQL nicht gekürzt, sondern abgewiesen –
	 * die Sperre käme also gar nicht zustande, und niemand merkte es, weil
	 * der Aufrufer nur „keine Sperre bekommen" sieht.
	 */
	public function test_der_name_bleibt_innerhalb_der_grenze() {
		$this->assertLessThanOrEqual( 64, strlen( Lock::name( 'chain_belege' ) ) );
		$this->assertLessThanOrEqual( 64, strlen( Lock::name( str_repeat( 'x', 500 ) ) ) );
	}

	/**
	 * Verschiedene Sperren bekommen verschiedene Namen – auch lange.
	 *
	 * Hinten abzuschneiden liesse zwei lange Namen zusammenfallen: Zwei
	 * verschiedene Vorgänge blockierten sich dann gegenseitig, und
	 * schlimmer, ein Aufrufer hielte die Sperre eines anderen für seine.
	 */
	public function test_verschiedene_sperren_bekommen_verschiedene_namen() {
		$a = Lock::name( str_repeat( 'a', 200 ) . '_eins' );
		$b = Lock::name( str_repeat( 'a', 200 ) . '_zwei' );

		$this->assertNotSame( $a, $b );
		$this->assertLessThanOrEqual( 64, strlen( $a ) );
		$this->assertLessThanOrEqual( 64, strlen( $b ) );
	}

	/**
	 * Derselbe Schlüssel ergibt denselben Namen.
	 */
	public function test_derselbe_schluessel_ergibt_denselben_namen() {
		$this->assertSame( Lock::name( 'wc_stock_42' ), Lock::name( 'wc_stock_42' ) );
	}

	/**
	 * Zeichen, die MySQL nicht erwartet, kommen nicht durch.
	 */
	public function test_fremde_zeichen_kommen_nicht_durch() {
		$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/i', Lock::name( "wc'stock; DROP" ) );
	}
}
