<?php
/**
 * Hochrechnung aus dem Verkaufsverlauf.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Forecast;
use PHPUnit\Framework\TestCase;

/**
 * Tests für Forecast.
 */
class ErpForecastTest extends TestCase {

	public function test_weekly_average_times_cover_rounded_up() {
		// 40 Stück in 8 Wochen = 5 je Woche, Reichweite 2 Wochen = 10.
		$d = Forecast::demands( array( 5 => 40 ), array(), 8, 2 );

		$this->assertCount( 1, $d );
		$this->assertSame( 5, $d[0]['article_id'] );
		$this->assertSame( 10.0, $d[0]['qty'] );
		$this->assertSame( 'hochrechnung', $d[0]['source'] );
		$this->assertSame( 5.0, $d[0]['per_week'] );
	}

	public function test_open_orders_are_not_counted_twice() {
		$d = Forecast::demands( array( 5 => 40 ), array( 5 => 4 ), 8, 2 );
		$this->assertSame( 6.0, $d[0]['qty'] );

		$this->assertSame( array(), Forecast::demands( array( 5 => 40 ), array( 5 => 10 ), 8, 2 ), 'Bestellungen decken die Reichweite schon' );
	}

	public function test_fraction_rounds_up_but_noise_does_not_create_demand() {
		$this->assertSame( 1.0, Forecast::demands( array( 5 => 3 ), array(), 8, 2 )[0]['qty'], '0,75 je Woche × 2 = 1,5 → 2? nein: 0,375×2 = 0,75 → 1' );
		$this->assertSame( array(), Forecast::demands( array( 5 => 0 ), array() ) );
		$this->assertSame( array(), Forecast::demands( array(), array() ) );
	}

	public function test_zero_cover_gives_no_demand() {
		$this->assertSame( array(), Forecast::demands( array( 5 => 40 ), array(), 8, 0 ) );
	}

	public function test_window_never_divides_by_zero() {
		$d = Forecast::demands( array( 5 => 10 ), array(), 0, 1 );
		$this->assertSame( 10.0, $d[0]['qty'] );
	}

	public function test_label_names_the_weekly_average() {
		$this->assertStringContainsString( '5', Forecast::demands( array( 5 => 40 ), array() )[0]['label'] );
	}
}
