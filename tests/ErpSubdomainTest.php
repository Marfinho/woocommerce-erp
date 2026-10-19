<?php
/**
 * Subdomain-Betrieb: Adressvergleich und Austausch.
 *
 * Ein Fehler hier wäre leise und gefährlich: ein zu großzügiger Vergleich
 * ließe fremde Adressen die Website-Adresse umbiegen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Subdomain;
use PHPUnit\Framework\TestCase;

/**
 * Tests für Subdomain.
 */
class ErpSubdomainTest extends TestCase {

	public function test_normalize_strips_scheme_port_path_and_case() {
		$this->assertSame( 'erp.lindenweg16.de', Subdomain::normalize( 'HTTPS://ERP.Lindenweg16.de:8443/erp/?x=1' ) );
		$this->assertSame( 'erp.lindenweg16.de', Subdomain::normalize( ' erp.lindenweg16.de ' ) );
		$this->assertSame( '', Subdomain::normalize( '' ) );
	}

	public function test_swap_host_keeps_scheme_and_path() {
		$this->assertSame( 'https://erp.x.de/wp-admin/admin.php?page=a', Subdomain::swap_host( 'https://x.de/wp-admin/admin.php?page=a', 'erp.x.de' ) );
		$this->assertSame( 'http://erp.x.de', Subdomain::swap_host( 'http://x.de', 'erp.x.de' ) );
		$this->assertSame( '//erp.x.de/a', Subdomain::swap_host( '//x.de/a', 'erp.x.de' ) );
	}

	public function test_swap_host_changes_only_the_first_host() {
		$this->assertSame( 'https://erp.x.de/?u=https://x.de/', Subdomain::swap_host( 'https://x.de/?u=https://x.de/', 'erp.x.de' ) );
	}

	public function test_swap_host_without_target_changes_nothing() {
		$this->assertSame( 'https://x.de/a', Subdomain::swap_host( 'https://x.de/a', '' ) );
	}

	public function test_nothing_is_a_subdomain_request_without_the_setting() {
		if ( defined( 'LW16_ERP_HOST' ) ) {
			$this->markTestSkipped( 'Konstante ist gesetzt.' );
		}

		$this->assertSame( '', Subdomain::host() );
		$this->assertFalse( Subdomain::is_request( 'erp.lindenweg16.de' ) );
		$this->assertFalse( Subdomain::is_request( '' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_request_host_must_match_exactly() {
		define( 'LW16_ERP_HOST', 'ERP.lindenweg16.de' );

		$this->assertSame( 'erp.lindenweg16.de', Subdomain::host() );
		$this->assertTrue( Subdomain::is_request( 'erp.lindenweg16.de' ) );
		$this->assertTrue( Subdomain::is_request( 'ERP.lindenweg16.de:443' ) );
		$this->assertFalse( Subdomain::is_request( 'lindenweg16.de' ) );
		$this->assertFalse( Subdomain::is_request( 'erp.lindenweg16.de.evil.example' ) );
		$this->assertFalse( Subdomain::is_request( 'evil-erp.lindenweg16.de' ) );
		$this->assertFalse( Subdomain::is_request( 'x.erp.lindenweg16.de' ) );
	}
}
