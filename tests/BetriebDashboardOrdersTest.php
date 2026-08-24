<?php
/**
 * Offene Aufträge auf dem Dashboard – Rücksprung und Nonce.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\BetriebModule;
use PHPUnit\Framework\TestCase;

/**
 * Der Startknopf sitzt auf dem Dashboard, der Handler auf der
 * Lagerplatzseite. Beide müssen zusammenpassen – und der Rücksprung
 * darf kein beliebiges Ziel annehmen.
 */
class BetriebDashboardOrdersTest extends TestCase {

	/**
	 * Bekannte Seiten werden als Rücksprungziel akzeptiert.
	 */
	public function test_bekannte_seite_ist_erlaubt() {
		$this->assertTrue( BetriebModule::is_page( BetriebModule::MENU_SLUG ) );
		$this->assertTrue( BetriebModule::is_page( 'lw16-betrieb-lagerplaetze' ) );
	}

	/**
	 * Ein fremdes Ziel wird abgewiesen – sonst wäre das eine offene
	 * Weiterleitung.
	 */
	public function test_fremdes_ziel_wird_abgewiesen() {
		foreach ( array( '', 'https://example.com', 'index', 'lw16-betrieb-gibtesnicht' ) as $ziel ) {
			$this->assertFalse( BetriebModule::is_page( $ziel ), 'Angenommen: ' . $ziel );
		}
	}

	/**
	 * Das Dashboard sendet mit demselben Nonce, den der Handler prüft.
	 *
	 * Beide beziehen sich auf dieselbe Konstante. Ein abgetippter Name
	 * bräche stillschweigend, und ein falscher Nonce sieht für den
	 * Benutzer aus wie ein Rechtefehler.
	 */
	public function test_dashboard_nutzt_die_gemeinsame_konstante() {
		$dashboard = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Admin/DashboardPage.php' );
		$bins      = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Admin/BinsPage.php' );

		$this->assertStringContainsString( 'BinsPage::NONCE_WORK', $dashboard );
		$this->assertStringNotContainsString(
			"'lw16_betrieb_bins_work'",
			$dashboard,
			'Der Nonce ist abgetippt statt referenziert.'
		);
		$this->assertStringContainsString( 'const NONCE_WORK', $bins );
	}

	/**
	 * Das Dashboard schickt den Klick an den vorhandenen Handler.
	 */
	public function test_dashboard_trifft_den_handler() {
		$dashboard = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Admin/DashboardPage.php' );
		$bins      = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Admin/BinsPage.php' );

		$this->assertStringContainsString( 'value="lw16_betrieb_work_timer"', $dashboard );
		$this->assertStringContainsString( "admin_post_lw16_betrieb_work_timer", $bins );
	}
}
