<?php
/**
 * Urlaub und Abwesenheit: Ostern, Feiertage, Arbeitstage, Konto.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Abwesenheit as A;
use PHPUnit\Framework\TestCase;

class ErpAbwesenheitTest extends TestCase {

	public function test_ostern() {
		$this->assertSame( '2024-03-31', A::easter( 2024 ) );
		$this->assertSame( '2025-04-20', A::easter( 2025 ) );
		$this->assertSame( '2026-04-05', A::easter( 2026 ) );
		$this->assertSame( '2027-03-28', A::easter( 2027 ) );
	}

	public function test_feiertage_bundesweit_und_land() {
		$all = A::holidays( 2026 );
		$this->assertArrayHasKey( '2026-04-03', $all, 'Karfreitag' );
		$this->assertArrayHasKey( '2026-05-14', $all, 'Himmelfahrt' );
		$this->assertArrayHasKey( '2026-05-25', $all, 'Pfingstmontag' );
		$this->assertArrayNotHasKey( '2026-06-04', $all, 'Fronleichnam nicht bundesweit' );
		$this->assertCount( 9, $all );

		$this->assertArrayHasKey( '2026-06-04', A::holidays( 2026, 'NW' ) );
		$this->assertArrayHasKey( '2026-11-01', A::holidays( 2026, 'NW' ) );
		$this->assertArrayHasKey( '2026-10-31', A::holidays( 2026, 'NI' ) );
		$this->assertArrayHasKey( '2026-11-18', A::holidays( 2026, 'SN' ), 'Buß- und Bettag 2026' );
		$this->assertArrayHasKey( '2025-11-19', A::holidays( 2025, 'SN' ), 'Buß- und Bettag 2025' );
	}

	public function test_arbeitstage() {
		// Mo 30.03. bis Mo 06.04.2026: Karfreitag und Ostermontag frei.
		$this->assertSame( 4.0, A::workdays( '2026-03-30', '2026-04-06' ) );
		$this->assertSame( 0.0, A::workdays( '2026-10-31', '2026-11-01' ), 'Wochenende' );
		$this->assertSame( 0.5, A::workdays( '2026-11-02', '2026-11-02', '', true ) );
		$this->assertSame( 0.0, A::workdays( '2026-11-05', '2026-11-02' ) );
		$this->assertSame( 5.0, A::workdays( '2026-10-26', '2026-10-30', 'NI' ), 'Reformationstag fällt 2026 auf Samstag' );
		$this->assertSame( 4.0, A::workdays( '2025-10-27', '2025-10-31', 'NI' ), 'Reformationstag 2025 ist ein Freitag' );
		$this->assertSame( 23.0, A::workdays( '2026-12-21', '2027-01-22' ), 'Über den Jahreswechsel' );
	}

	public function test_ueberschneidung() {
		$this->assertTrue( A::overlaps( '2026-11-02', '2026-11-06', '2026-11-06', '2026-11-10' ) );
		$this->assertFalse( A::overlaps( '2026-11-02', '2026-11-05', '2026-11-06', '2026-11-10' ) );
	}

	public function test_urlaubskonto() {
		$b = A::balance(
			24,
			array(
				array( 'kind' => 'urlaub', 'status' => 'genehmigt', 'days' => 5, 'date_from' => '2026-03-02' ),
				array( 'kind' => 'urlaub', 'status' => 'genehmigt', 'days' => 3, 'date_from' => '2026-12-21' ),
				array( 'kind' => 'urlaub', 'status' => 'beantragt', 'days' => 2, 'date_from' => '2026-11-20' ),
				array( 'kind' => 'urlaub', 'status' => 'abgelehnt', 'days' => 9, 'date_from' => '2026-08-01' ),
				array( 'kind' => 'krank', 'status' => 'genehmigt', 'days' => 4, 'date_from' => '2026-02-01' ),
				array( 'kind' => 'urlaub', 'status' => 'genehmigt', 'days' => 10, 'date_from' => '2025-08-01' ),
			),
			2026,
			'2026-11-01'
		);
		$this->assertSame( array( 'anspruch' => 24.0, 'genommen' => 5.0, 'geplant' => 3.0, 'beantragt' => 2.0, 'rest' => 14.0 ), $b );
	}
}
