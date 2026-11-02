<?php
/**
 * Ehrlicher Stundenlohn: Zeitarten, Verteilung, Ergebnis.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\HourlyRate as H;
use PHPUnit\Framework\TestCase;

class ErpHourlyRateTest extends TestCase {

	public function test_zeitarten() {
		$t = H::time_buckets( array( array( 'category' => 'produktion', 'hours' => 10 ), array( 'category' => 'ruestzeit', 'hours' => 2 ), array( 'category' => 'versand', 'hours' => 3 ), array( 'category' => 'vertrieb', 'hours' => 4 ), array( 'category' => 'verwaltung', 'hours' => 5 ), array( 'category' => 'marketing', 'hours' => 1 ) ) );
		$this->assertSame( array( 'direct' => 12.0, 'versand' => 3.0, 'vertrieb' => 4.0, 'overhead' => 6.0 ), $t );
	}

	public function test_rechnung() {
		$lines = array(
			// Shop-Bestellung: 2 Vasen.
			array( 'order' => 'w1', 'article_id' => 1, 'label' => 'Vase', 'channel' => 'Shop', 'market' => '', 'qty' => 2, 'revenue' => 100, 'material' => 20, 'hand_min' => 30 ),
			// Händler: 10 Haken.
			array( 'order' => 'm1', 'article_id' => 2, 'label' => 'Haken', 'channel' => 'Fachhandel', 'market' => '', 'qty' => 10, 'revenue' => 100, 'material' => 30, 'hand_min' => 6 ),
			// Markt: 1 Vase.
			array( 'order' => 'k1', 'article_id' => 1, 'label' => 'Vase', 'channel' => 'vor Ort', 'market' => 'Herbstmarkt', 'qty' => 1, 'revenue' => 50, 'material' => 10, 'hand_min' => 30 ),
		);
		$orders  = array(
			array( 'key' => 'w1', 'channel' => 'Shop', 'market' => '', 'handling_min' => null ),
			array( 'key' => 'm1', 'channel' => 'Fachhandel', 'market' => '', 'handling_min' => 15 ),
			array( 'key' => 'k1', 'channel' => 'vor Ort', 'market' => 'Herbstmarkt', 'handling_min' => 0 ),
		);
		$markets = array( array( 'name' => 'Herbstmarkt', 'hours' => 6, 'costs' => 40 ) );
		// Geplant: 2×30 + 10×6 + 1×30 = 150 min = 2,5 h; erfasst 5 h → Faktor 2.
		$r = H::compute( $lines, $orders, $markets, array( 'direct' => 5, 'versand' => 0, 'vertrieb' => 1, 'overhead' => 2 ), array( 'default_handling_min' => 6 ) );

		$this->assertSame( 2.0, $r['totals']['scaled'] );
		$p = array_column( $r['products'], null, 'label' );
		// Vase: 3 Stück × 30 min × 2 = 3 h + 6 min Shop-Versand = 3,1 h; DB 150 − 30 = 120.
		$this->assertSame( 120.0, $p['Vase']['db'] );
		$this->assertSame( 3.1, $p['Vase']['hours'] );
		$this->assertSame( 38.71, $p['Vase']['rate'] );
		// Haken: 10×6×2 = 2 h + 15 min Abwicklung + 1 h Vertrieb = 3,25 h; DB 70.
		$this->assertSame( 21.54, $p['Haken']['rate'] );

		$m = $r['markets'][0];
		$this->assertSame( 'Herbstmarkt', $m['label'] );
		$this->assertSame( 0.0, round( $m['db'], 2 ), 'Erlös 50 − Material 10 − Stand 40' );
		$this->assertSame( 7.0, $m['hours'], '1 h Vase + 6 h Stand' );

		$this->assertSame( 150.0, $r['totals']['db'], 'Erlös 250 − Material 60 − Stand 40' );
		$this->assertSame( 2.0, $r['totals']['overhead_hours'] );
		$this->assertSame( round( 150 / ( $r['totals']['direct_hours'] + 2 ), 2 ), $r['totals']['rate_all'] );
		$this->assertNotEmpty( H::insights( $r ) );
	}

	public function test_ohne_zeiterfassung_gilt_die_kalkulation() {
		$r = H::compute( array( array( 'order' => 'm1', 'article_id' => 1, 'label' => 'A', 'channel' => 'Sonstiges', 'market' => '', 'qty' => 1, 'revenue' => 30, 'material' => 0, 'hand_min' => 60 ) ), array( array( 'key' => 'm1', 'channel' => 'Sonstiges', 'market' => '', 'handling_min' => 0 ) ), array(), array( 'direct' => 0, 'versand' => 0, 'vertrieb' => 0, 'overhead' => 0 ) );
		$this->assertSame( 1.0, $r['totals']['scaled'] );
		$this->assertSame( 30.0, $r['products'][0]['rate'] );
	}
}
