<?php
/**
 * Prüft die Wiederkaufsrate.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\RepeatRate;
use PHPUnit\Framework\TestCase;

/**
 * Wiederkaufs-Tests.
 */
class BetriebRepeatRateTest extends TestCase {

	/**
	 * Bestellungen bauen.
	 *
	 * @param array $paare Kunde => Liste von Daten.
	 * @return array
	 */
	protected function bestellungen( array $paare ) {
		$out = array();

		foreach ( $paare as $kunde => $daten ) {
			foreach ( (array) $daten as $datum ) {
				$out[] = array( 'customer' => $kunde, 'date' => $datum );
			}
		}

		return $out;
	}

	/**
	 * Das Fenster reicht ein Jahr zurück.
	 */
	public function test_das_fenster_reicht_ein_jahr_zurueck() {
		$this->assertSame( '2025-08-15', RepeatRate::window_start( '2026-08-15' ) );
		$this->assertSame( '2026-08-08', RepeatRate::window_start( '2026-08-15', 7 ) );
		$this->assertSame( '', RepeatRate::window_start( 'irgendwann' ) );
	}

	/**
	 * Der Anteil zählt Kunden, nicht Bestellungen.
	 */
	public function test_der_anteil_zaehlt_kunden() {
		$orders = $this->bestellungen(
			array(
				'u1' => array( '2026-01-10', '2026-03-04', '2026-05-20' ),
				'u2' => array( '2026-02-01' ),
				'u3' => array( '2026-02-02' ),
				'u4' => array( '2026-02-03', '2026-06-06' ),
			)
		);

		// Zwei von vier Kunden sind wiedergekommen, obwohl sie sechs von
		// sieben Bestellungen ausmachen.
		$this->assertEqualsWithDelta( 50.0, RepeatRate::share( $orders, '2026-01-01', '2026-12-31' ), 0.0001 );
	}

	/**
	 * Die Rate hängt am Stichtag, nicht an heute.
	 *
	 * Das war der Fehler im Verlaufsgraphen: Die Rate wurde immer über die
	 * letzten zwölf Monate ab heute gerechnet und ergab für jeden Eimer
	 * denselben Wert – eine waagerechte Linie, die wie Geschichte aussah.
	 */
	public function test_die_rate_haengt_am_stichtag() {
		$orders = $this->bestellungen(
			array(
				'u1' => array( '2026-01-10', '2026-07-04' ),
				'u2' => array( '2026-01-11' ),
			)
		);

		// Im März hatte noch niemand ein zweites Mal gekauft.
		$this->assertEqualsWithDelta( 0.0, RepeatRate::at( $orders, '2026-03-31' ), 0.0001 );

		// Im August schon – einer von zwei Kunden.
		$this->assertEqualsWithDelta( 50.0, RepeatRate::at( $orders, '2026-08-31' ), 0.0001 );
	}

	/**
	 * Was älter als das Fenster ist, zählt nicht mehr mit.
	 */
	public function test_alte_bestellungen_fallen_aus_dem_fenster() {
		$orders = $this->bestellungen( array( 'u1' => array( '2024-05-01', '2026-07-01' ) ) );

		// Beide Käufe im Fenster: ein Wiederkäufer.
		$this->assertEqualsWithDelta( 100.0, RepeatRate::share( $orders, '2024-01-01', '2026-12-31' ), 0.0001 );

		// Nur der zweite im Fenster: ein Einmalkäufer.
		$this->assertEqualsWithDelta( 0.0, RepeatRate::at( $orders, '2026-08-15' ), 0.0001 );
	}

	/**
	 * Ohne Kunden im Fenster gibt es keine Rate – nicht null Prozent.
	 *
	 * Null Prozent hiesse „niemand kam wieder"; richtig ist „niemand hat
	 * gekauft". Der Unterschied entscheidet, ob die Ampel Alarm schlägt.
	 */
	public function test_ohne_kunden_gibt_es_keine_rate() {
		$orders = $this->bestellungen( array( 'u1' => array( '2026-07-01' ) ) );

		$this->assertNull( RepeatRate::share( $orders, '2026-01-01', '2026-03-31' ) );
		$this->assertNull( RepeatRate::share( array(), '2026-01-01', '2026-12-31' ) );
	}

	/**
	 * Nicht wiedererkennbare Käufer zählen gar nicht mit.
	 *
	 * Jede anonyme Bestellung als eigenen Neukunden zu zählen drückte die
	 * Rate, ohne dass jemand etwas anders gemacht hätte.
	 */
	public function test_anonyme_bestellungen_zaehlen_nicht() {
		$orders = array(
			array( 'customer' => 'u1', 'date' => '2026-02-01' ),
			array( 'customer' => 'u1', 'date' => '2026-03-01' ),
			array( 'customer' => '', 'date' => '2026-02-02' ),
			array( 'customer' => '  ', 'date' => '2026-02-03' ),
			array( 'customer' => 'u2', 'date' => '' ),
		);

		$this->assertEqualsWithDelta( 100.0, RepeatRate::share( $orders, '2026-01-01', '2026-12-31' ), 0.0001 );
	}
}
