<?php
/**
 * Prüft die Rechnung der Stoppuhr.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\Stopwatch;
use PHPUnit\Framework\TestCase;

/**
 * Stoppuhr-Tests.
 */
class BetriebStopwatchTest extends TestCase {

	/**
	 * Die Laufzeit ist die Differenz.
	 */
	public function test_laufzeit_ist_die_differenz() {
		$this->assertSame( 3600, Stopwatch::elapsed( '2026-08-15 09:00:00', '2026-08-15 10:00:00' ) );
		$this->assertSame( 90, Stopwatch::elapsed( '2026-08-15 09:00:00', '2026-08-15 09:01:30' ) );
		$this->assertSame( 0, Stopwatch::elapsed( '2026-08-15 09:00:00', '2026-08-15 09:00:00' ) );
	}

	/**
	 * Eine rückwärts laufende Uhr ist kein Zeitguthaben.
	 *
	 * Kommt vor, wenn die Serverzeit korrigiert wird. Eine negative Zahl
	 * würde als Stunden gebucht und die Wochensumme verkleinern.
	 */
	public function test_rueckwaerts_ist_null() {
		$this->assertSame( 0, Stopwatch::elapsed( '2026-08-15 10:00:00', '2026-08-15 09:00:00' ) );
	}

	/**
	 * Unsinnige Zeitangaben ergeben keine Laufzeit.
	 */
	public function test_unsinnige_angaben_ergeben_keine_laufzeit() {
		$this->assertSame( 0, Stopwatch::elapsed( '', '2026-08-15 09:00:00' ) );
		$this->assertSame( 0, Stopwatch::elapsed( 'gestern', '2026-08-15 09:00:00' ) );
		$this->assertSame( 0, Stopwatch::elapsed( '2026-08-15', '2026-08-15 09:00:00' ) );
	}

	/**
	 * Stunden auf zwei Nachkommastellen – feiner täuschte Genauigkeit vor.
	 */
	public function test_stunden_werden_auf_hundertstel_gerundet() {
		$this->assertSame( 1.0, Stopwatch::hours( 3600 ) );
		$this->assertSame( 1.5, Stopwatch::hours( 5400 ) );
		$this->assertSame( 0.25, Stopwatch::hours( 900 ) );
		$this->assertSame( 0.02, Stopwatch::hours( 60 ) );
		$this->assertSame( 0.0, Stopwatch::hours( -100 ) );
	}

	/**
	 * Die Anzeige zählt Stunden, Minuten, Sekunden.
	 */
	public function test_anzeige() {
		$this->assertSame( '0:00:00', Stopwatch::format( 0 ) );
		$this->assertSame( '0:01:30', Stopwatch::format( 90 ) );
		$this->assertSame( '1:23:45', Stopwatch::format( 5025 ) );
		$this->assertSame( '13:00:00', Stopwatch::format( 46800 ) );

		$this->assertSame( '0:01', Stopwatch::short( 90 ) );
		$this->assertSame( '1:23', Stopwatch::short( 5025 ) );
	}

	/**
	 * Ein Fehlklick ist keine Buchung.
	 */
	public function test_fehlklick_ist_nicht_buchbar() {
		$this->assertFalse( Stopwatch::is_bookable( 5 ) );
		$this->assertFalse( Stopwatch::is_bookable( 59 ) );
		$this->assertTrue( Stopwatch::is_bookable( 60 ) );
	}

	/**
	 * Eine vergessene Uhr wird erkannt, aber nicht heimlich gekappt.
	 *
	 * Der Wert bleibt, was er war – nur das Formular fragt nach. Wer hier
	 * kappte, buchte acht Stunden, die niemand geprüft hat.
	 */
	public function test_vergessene_uhr_wird_erkannt() {
		$this->assertFalse( Stopwatch::is_implausible( 8 * 3600 ) );
		$this->assertTrue( Stopwatch::is_implausible( 10 * 3600 ) );
		$this->assertTrue( Stopwatch::is_implausible( 26 * 3600 ) );

		// Gekappt wird nichts: 26 Stunden bleiben 26 Stunden.
		$this->assertSame( 26.0, Stopwatch::hours( 26 * 3600 ) );
	}

	/**
	 * Gebucht wird auf den Starttag, nicht auf den Stopptag.
	 *
	 * Wer um 23:40 anfängt und um 0:20 aufhört, hat am Vortag gearbeitet.
	 */
	public function test_gebucht_wird_auf_den_starttag() {
		// 21:40 UTC ist in Berlin (Sommerzeit, UTC+2) der 15. um 23:40.
		$this->assertSame( '2026-08-15', Stopwatch::entry_date( '2026-08-15 21:40:00', 'Europe/Berlin' ) );

		// Dieselbe Sekunde in UTC gelesen wäre noch der 15. – der Unterschied
		// zeigt sich an der Tagesgrenze.
		$this->assertSame( '2026-08-16', Stopwatch::entry_date( '2026-08-15 22:30:00', 'Europe/Berlin' ) );
		$this->assertSame( '2026-08-15', Stopwatch::entry_date( '2026-08-15 22:30:00', 'UTC' ) );
	}

	/**
	 * Eine unbekannte Zeitzone lässt die Uhr nicht scheitern.
	 */
	public function test_unbekannte_zeitzone_faellt_auf_utc_zurueck() {
		$this->assertSame( '2026-08-15', Stopwatch::entry_date( '2026-08-15 12:00:00', 'Mars/Olympus' ) );
	}
}
