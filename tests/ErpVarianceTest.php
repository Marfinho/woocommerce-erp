<?php
/**
 * Soll gegen Ist: Rechenregeln.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Variance;
use PHPUnit\Framework\TestCase;

/**
 * Tests für Variance.
 */
class ErpVarianceTest extends TestCase {

	public function test_minuten_aus_zeitstempeln() {
		$m = Variance::run_minutes(
			array(
				'setup_at'     => '2026-10-19 08:00:00',
				'print_at'     => '2026-10-19 08:15:00',
				'print_end_at' => '2026-10-19 11:25:00',
				'reported_at'  => '2026-10-19 12:00:00',
			)
		);

		$this->assertSame( 15.0, $m['setup'] );
		$this->assertSame( 190.0, $m['print'] );
	}

	public function test_ohne_druckende_zaehlt_die_meldung() {
		$m = Variance::run_minutes( array( 'print_at' => '2026-10-19 08:00:00', 'reported_at' => '2026-10-19 09:00:00' ) );

		$this->assertSame( 60.0, $m['print'] );
		$this->assertSame( 0.0, $m['setup'] );
	}

	public function test_ohne_druckstart_keine_messung() {
		$this->assertNull( Variance::run_minutes( array( 'setup_at' => '2026-10-19 08:00:00', 'reported_at' => '2026-10-19 09:00:00' ) ) );
		$this->assertNull( Variance::run_minutes( array( 'print_at' => '2026-10-19 09:00:00', 'reported_at' => '2026-10-19 08:00:00' ) ) );
	}

	public function test_verteilung_nach_geplanter_zeit() {
		$a = Variance::allocate( 120.0, array( 1 => 30.0, 2 => 90.0 ) );

		$this->assertEqualsWithDelta( 30.0, $a[1], 0.001 );
		$this->assertEqualsWithDelta( 90.0, $a[2], 0.001 );
		$this->assertEqualsWithDelta( 120.0, array_sum( $a ), 0.001 );
	}

	public function test_verteilung_ohne_plan_gleichmaessig() {
		$a = Variance::allocate( 100.0, array( 1 => 0.0, 2 => 0.0 ) );

		$this->assertSame( 50.0, $a[1] );
	}

	public function test_faktor_und_korrektur() {
		$this->assertSame( 1.2, Variance::factor( 100.0, 120.0 ) );
		$this->assertNull( Variance::factor( 0.0, 50.0 ) );
		$this->assertSame( 3.6, Variance::adjusted_hours( 3.0, 1.2 ) );
	}

	public function test_korrektur_erst_ab_drei_platten_und_zehn_prozent() {
		$this->assertFalse( Variance::should_adjust( 1.3, 2 ) );
		$this->assertFalse( Variance::should_adjust( 1.05, 10 ) );
		$this->assertTrue( Variance::should_adjust( 1.1, 3 ) );
		$this->assertTrue( Variance::should_adjust( 0.8, 5 ) );
		$this->assertFalse( Variance::should_adjust( null, 5 ) );
	}

	public function test_margenwarnung() {
		$this->assertSame( 'verlust', Variance::margin_state( 0.30, -0.02, -0.4 ) );
		$this->assertSame( 'sinkt', Variance::margin_state( 0.40, 0.34, 1.2 ) );
		$this->assertSame( '', Variance::margin_state( 0.40, 0.38, 1.2 ) );
	}

	public function test_planzeit_nur_mit_recht_stammdaten_aendern() {
		$rest = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/RestApi.php' );

		$this->assertMatchesRegularExpression( "#sollist/\\(\\?P<id>.*?'fertigung\\.stammdaten\\.edit'#s", $rest );
	}
}
