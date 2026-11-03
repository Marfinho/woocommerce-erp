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

	public function test_messung_trennt_ruesten_druck_und_warten() {
		$m = Variance::measure(
			array(
				'released_at'    => '2026-10-19 07:00:00',
				'setup_at'       => '2026-10-19 08:00:00',
				'setup_src'      => 'hand',
				'setup_paused_s' => 600,
				'print_at'       => '2026-10-19 08:30:00',
				'print_src'      => 'drucker',
				'print_end_at'   => '2026-10-19 11:30:00',
				'reported_at'    => '2026-10-19 12:00:00',
			)
		);

		$this->assertSame( 20.0, $m['setup'], '30 Minuten abzüglich 10 Minuten Pause.' );
		$this->assertSame( 'hand', $m['setup_src'] );
		$this->assertSame( 180.0, $m['print'] );
		$this->assertSame( 'drucker', $m['print_src'] );
		$this->assertSame( 60.0, $m['wait_before'] );
		$this->assertSame( 30.0, $m['wait_after'] );
		$this->assertFalse( $m['failed'] );
	}

	public function test_automatisches_ruesten_ist_keine_arbeitszeit() {
		$run = array(
			'setup_at'     => '2026-10-19 08:00:00',
			'setup_src'    => 'auto',
			'print_at'     => '2026-10-19 08:20:00',
			'print_end_at' => '2026-10-19 09:20:00',
		);

		$this->assertNull( Variance::measure( $run )['setup'] );
		$this->assertNull( Variance::measure( array_merge( $run, array( 'setup_src' => null ) ) )['setup'], 'Alte Platten ohne Herkunft zählen nicht.' );
		$this->assertSame( 12.5, Variance::measure( array_merge( $run, array( 'setup_min' => '12.5' ) ) )['setup'], 'Von Hand gesetzt zählt immer.' );
	}

	public function test_druckzeit_ohne_dauer_ist_nicht_messbar() {
		$m = Variance::measure( array( 'print_at' => '2026-10-19 08:00:00', 'print_end_at' => '2026-10-19 08:00:00' ) );

		$this->assertNull( $m['print'] );
		$this->assertNull( $m['print_src'] );
	}

	public function test_fehlgeschlagener_druck_ist_markiert() {
		$m = Variance::measure( array( 'print_at' => '2026-10-19 08:00:00', 'print_end_at' => '2026-10-19 08:10:00', 'print_failed' => 1 ) );

		$this->assertTrue( $m['failed'] );
		$this->assertSame( 10.0, $m['print'] );
	}

	public function test_median_ist_robust_gegen_ausreisser() {
		$this->assertSame( 12.0, Variance::median( array( 10, 12, 600 ) ) );
		$this->assertSame( 11.0, Variance::median( array( 10, 12 ) ) );
		$this->assertNull( Variance::median( array() ) );
	}

	public function test_reine_platte_erkennt_genau_einen_artikel() {
		$this->assertSame( 7, Variance::pure_article( array( array( 'article_id' => 7, 'qty' => 4, 'color' => 'rot' ), array( 'article_id' => 7, 'qty' => 2, 'color' => 'blau' ) ) ) );
		$this->assertSame( 0, Variance::pure_article( array( array( 'article_id' => 7, 'qty' => 4 ), array( 'article_id' => 8, 'qty' => 2 ) ) ) );
		$this->assertSame( 0, Variance::pure_article( array() ) );
	}

	public function test_planzeit_nur_mit_recht_stammdaten_aendern() {
		$rest = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/RestApi.php' );

		$this->assertMatchesRegularExpression( "#sollist/\\(\\?P<id>.*?'fertigung\\.stammdaten\\.edit'#s", $rest );
	}
}
