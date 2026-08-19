<?php
/**
 * Beschaffungsalarm: Reichweite und Meldegrenze.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\ProcurementAlert;

/**
 * Der Engpass ist nicht der Drucker, sondern alles mit Lieferzeit.
 */
class BetriebProcurementTest extends TestCase {

	/**
	 * Reichweite aus Bestand und Verbrauch der letzten vier Wochen.
	 */
	public function test_reichweite_in_tagen() {
		// 56 Stück Bestand, 28 Stück in 28 Tagen verbraucht → 1 je Tag → 56 Tage.
		$this->assertEqualsWithDelta( 56.0, ProcurementAlert::coverage_days( 56, 28, 28 ), 0.00001 );
	}

	/**
	 * Ohne Verbrauch keine Reichweite – und damit auch kein Alarm.
	 */
	public function test_ohne_verbrauch_keine_aussage() {
		$this->assertNull( ProcurementAlert::coverage_days( 40, 0, 28 ) );
		$this->assertFalse( ProcurementAlert::is_alarming( null, 10, 7 ) );
	}

	/**
	 * Reichweite über der Schwelle löst keinen Alarm aus.
	 */
	public function test_kein_alarm_ueber_der_schwelle() {
		$this->assertFalse( ProcurementAlert::is_alarming( 20.0, 10, 7 ) );
	}

	/**
	 * Genau auf der Grenze wird bereits gemeldet: Bestellt man erst danach,
	 * ist der Bestand bei Eintreffen der Lieferung exakt null.
	 */
	public function test_alarm_genau_auf_der_grenze() {
		$this->assertTrue( ProcurementAlert::is_alarming( 17.0, 10, 7 ) );
	}

	/**
	 * Knapp darüber noch kein Alarm.
	 */
	public function test_knapp_ueber_der_grenze() {
		$this->assertFalse( ProcurementAlert::is_alarming( 17.01, 10, 7 ) );
	}

	/**
	 * Unter der Grenze erst recht.
	 */
	public function test_alarm_unter_der_grenze() {
		$this->assertTrue( ProcurementAlert::is_alarming( 5.0, 10, 7 ) );
	}

	/**
	 * Ohne Sicherheitspuffer zählt allein die Lieferzeit.
	 */
	public function test_ohne_puffer() {
		$this->assertTrue( ProcurementAlert::is_alarming( 10.0, 10, 0 ) );
		$this->assertFalse( ProcurementAlert::is_alarming( 11.0, 10, 0 ) );
	}

	/**
	 * Ein Artikel ohne Lieferzeit meldet nur bei leerem Bestand.
	 */
	public function test_ohne_lieferzeit() {
		$this->assertFalse( ProcurementAlert::is_alarming( 1.0, 0, 0 ) );
		$this->assertTrue( ProcurementAlert::is_alarming( 0.0, 0, 0 ) );
	}
}
