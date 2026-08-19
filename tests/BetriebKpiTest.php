<?php
/**
 * Bewertung der Kennzahlen.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\Kpi;

/**
 * Ampelstufen je Kennzahl und Richtung.
 */
class BetriebKpiTest extends TestCase {

	/**
	 * Beim Warenkorb ist mehr besser.
	 */
	public function test_warenkorb_hoeher_ist_besser() {
		$this->assertSame( 'gruen', Kpi::evaluate( 'warenkorb', 60.0 )['level'] );
		$this->assertSame( 'gelb', Kpi::evaluate( 'warenkorb', 50.0 )['level'] );
		$this->assertSame( 'rot', Kpi::evaluate( 'warenkorb', 44.0 )['level'] );
	}

	/**
	 * Genau auf dem Zielwert ist das Ziel erreicht.
	 */
	public function test_zielwert_ist_einschliesslich() {
		$result = Kpi::evaluate( 'warenkorb', 55.0 );

		$this->assertSame( 'gruen', $result['level'] );
		$this->assertTrue( $result['reached'] );
	}

	/**
	 * Genau auf der Alarmschwelle ist noch kein Alarm.
	 */
	public function test_alarmschwelle_ist_ausschliesslich() {
		$this->assertSame( 'gelb', Kpi::evaluate( 'warenkorb', 45.0 )['level'] );
	}

	/**
	 * Bei der Handlingzeit ist weniger besser.
	 */
	public function test_handlingzeit_niedriger_ist_besser() {
		$this->assertSame( 'gruen', Kpi::evaluate( 'handlingzeit', 8.0 )['level'] );
		$this->assertSame( 'gelb', Kpi::evaluate( 'handlingzeit', 10.0 )['level'] );
		$this->assertSame( 'rot', Kpi::evaluate( 'handlingzeit', 13.0 )['level'] );
	}

	/**
	 * Ausschussquote: bis 6 Prozent gut, über 10 Alarm.
	 */
	public function test_ausschussquote() {
		$this->assertSame( 'gruen', Kpi::evaluate( 'ausschussquote', 5.0 )['level'] );
		$this->assertSame( 'gelb', Kpi::evaluate( 'ausschussquote', 8.0 )['level'] );
		$this->assertSame( 'rot', Kpi::evaluate( 'ausschussquote', 11.0 )['level'] );
	}

	/**
	 * Die Auslastung ist der Sonderfall: Sie soll hoch sein, aber über
	 * 90 Prozent ist sie ein Alarm und keine Auszeichnung.
	 */
	public function test_auslastung_ist_der_sonderfall() {
		$this->assertSame( 'gelb', Kpi::evaluate( 'auslastung', 60.0 )['level'] );
		$this->assertSame( 'gruen', Kpi::evaluate( 'auslastung', 75.0 )['level'] );
		$this->assertSame( 'rot', Kpi::evaluate( 'auslastung', 95.0 )['level'], 'Zu hohe Auslastung ist ein Alarm.' );
	}

	/**
	 * Kennzahlen ohne Zielwert bleiben neutral.
	 */
	public function test_ohne_zielwert_neutral() {
		$this->assertSame( 'neutral', Kpi::evaluate( 'umsatz', 4000.0 )['level'] );
	}

	/**
	 * Fehlende Werte werden nicht bewertet.
	 */
	public function test_fehlender_wert() {
		$result = Kpi::evaluate( 'warenkorb', null );

		$this->assertSame( 'neutral', $result['level'] );
		$this->assertNull( $result['reached'] );
	}

	/**
	 * Unbekannte Kennzahlen bleiben neutral statt zu scheitern.
	 */
	public function test_unbekannte_kennzahl() {
		$this->assertSame( 'neutral', Kpi::evaluate( 'gibtsnicht', 5.0 )['level'] );
	}

	/**
	 * Teile je Plattenwechsel: ab 4 gut, unter 3 Alarm.
	 */
	public function test_teile_je_plattenwechsel() {
		$this->assertSame( 'gruen', Kpi::evaluate( 'teile_je_plattenwechsel', 4.0 )['level'] );
		$this->assertSame( 'gelb', Kpi::evaluate( 'teile_je_plattenwechsel', 3.5 )['level'] );
		$this->assertSame( 'rot', Kpi::evaluate( 'teile_je_plattenwechsel', 2.9 )['level'] );
	}

	/**
	 * Jede Kennzahl hat eine Bezeichnung und eine Richtung.
	 */
	public function test_definitionen_sind_vollstaendig() {
		foreach ( Kpi::definitions() as $key => $definition ) {
			$this->assertNotEmpty( $definition['label'], $key );
			$this->assertContains( $definition['direction'], array( Kpi::HIGHER_IS_BETTER, Kpi::LOWER_IS_BETTER ), $key );
		}
	}
}
