<?php
/**
 * Prüft das Herunterbrechen der Quartalsziele.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\Kpi;
use Lindenweg16\Modules\Betrieb\KpiGoals;
use Lindenweg16\Modules\Betrieb\Period;
use PHPUnit\Framework\TestCase;

/**
 * Ziel-Tests.
 */
class BetriebKpiGoalsTest extends TestCase {

	/**
	 * Eine Summengröße wird nach Tagen verteilt.
	 *
	 * Das dritte Quartal 2026 hat 92 Tage, der August 31. Aus 30.000 € im
	 * Quartal werden also 30000 × 31/92 – nicht 10.000, was ein Drittel
	 * wäre und dem August drei Tage zu viel gäbe.
	 */
	public function test_summengroesse_wird_nach_tagen_verteilt() {
		$ziele = array( '2026-Q3' => 30000.0 );

		$this->assertEqualsWithDelta(
			30000.0 * 31 / 92,
			KpiGoals::derive( $ziele, '2026-08-01', '2026-08-31', KpiGoals::SUM ),
			0.0001
		);

		// Das ganze Quartal ergibt wieder genau das Quartalsziel.
		$this->assertEqualsWithDelta(
			30000.0,
			KpiGoals::derive( $ziele, '2026-07-01', '2026-09-30', KpiGoals::SUM ),
			0.0001
		);

		// Ein einzelner Tag ist ein Zweiundneunzigstel.
		$this->assertEqualsWithDelta(
			30000.0 / 92,
			KpiGoals::derive( $ziele, '2026-08-15', '2026-08-15', KpiGoals::SUM ),
			0.0001
		);
	}

	/**
	 * Eine Verhältnisgröße bleibt, wie sie ist.
	 *
	 * Sechs Prozent Ausschuss im Quartal sind sechs Prozent im August und
	 * sechs Prozent an einem Dienstag. Wer hier teilt, setzt ein Ziel, das
	 * niemand gesetzt hat.
	 */
	public function test_verhaeltnisgroesse_bleibt_unveraendert() {
		$ziele = array( '2026-Q3' => 6.0 );

		foreach ( array(
			array( '2026-07-01', '2026-09-30' ),
			array( '2026-08-01', '2026-08-31' ),
			array( '2026-08-15', '2026-08-15' ),
		) as $zeitraum ) {
			$this->assertEqualsWithDelta(
				6.0,
				KpiGoals::derive( $ziele, $zeitraum[0], $zeitraum[1], KpiGoals::RATIO ),
				0.0001,
				implode( ' bis ', $zeitraum )
			);
		}
	}

	/**
	 * Eine Woche über der Quartalsgrenze mischt beide Ziele.
	 *
	 * Die Woche vom 28.09. bis 04.10.2026 liegt mit drei Tagen im dritten
	 * und mit vier im vierten Quartal.
	 */
	public function test_woche_ueber_der_quartalsgrenze_mischt() {
		$summen = array( '2026-Q3' => 30000.0, '2026-Q4' => 46000.0 );

		$this->assertEqualsWithDelta(
			30000.0 * 3 / 92 + 46000.0 * 4 / 92,
			KpiGoals::derive( $summen, '2026-09-28', '2026-10-04', KpiGoals::SUM ),
			0.0001
		);

		// Beim Verhältnis wird gewichtet gemittelt, nicht addiert – sonst
		// käme eine Quote von elf Prozent aus zwei Zielen von sechs und fünf.
		$quoten = array( '2026-Q3' => 6.0, '2026-Q4' => 5.0 );

		$this->assertEqualsWithDelta(
			( 6.0 * 3 + 5.0 * 4 ) / 7,
			KpiGoals::derive( $quoten, '2026-09-28', '2026-10-04', KpiGoals::RATIO ),
			0.0001
		);
	}

	/**
	 * Fehlt für ein berührtes Quartal das Ziel, gibt es keines.
	 *
	 * Ein Jahresziel aus dem einen eingetragenen Quartal sähe aus wie eine
	 * gesetzte Zahl und wäre eine erfundene.
	 */
	public function test_ohne_ziel_fuer_jedes_quartal_kein_ziel() {
		$ziele = array( '2026-Q3' => 30000.0 );

		$this->assertNull( KpiGoals::derive( $ziele, '2026-01-01', '2026-12-31', KpiGoals::SUM ) );
		$this->assertNull( KpiGoals::derive( $ziele, '2026-09-28', '2026-10-04', KpiGoals::SUM ) );
		$this->assertNull( KpiGoals::derive( array(), '2026-08-01', '2026-08-31', KpiGoals::SUM ) );
	}

	/**
	 * Vier eingetragene Quartale ergeben ein Jahresziel.
	 */
	public function test_vier_quartale_ergeben_ein_jahr() {
		$ziele = array(
			'2026-Q1' => 20000.0,
			'2026-Q2' => 25000.0,
			'2026-Q3' => 30000.0,
			'2026-Q4' => 46000.0,
		);

		$this->assertEqualsWithDelta(
			121000.0,
			KpiGoals::derive( $ziele, '2026-01-01', '2026-12-31', KpiGoals::SUM ),
			0.01
		);
	}

	/**
	 * Ziel und Alarm werden getrennt abgeleitet.
	 */
	public function test_ziel_und_alarm_werden_getrennt_abgeleitet() {
		$goals = array(
			'2026-Q3' => array( 'target' => 30000.0, 'alarm' => 18000.0 ),
		);

		$august = KpiGoals::for_bucket( $goals, Period::bucket( 'monat', '2026-08-15' ), KpiGoals::SUM );

		$this->assertEqualsWithDelta( 30000.0 * 31 / 92, $august['target'], 0.0001 );
		$this->assertEqualsWithDelta( 18000.0 * 31 / 92, $august['alarm'], 0.0001 );
	}

	/**
	 * Ein Ziel ohne Alarm bleibt ein Ziel ohne Alarm.
	 */
	public function test_fehlender_alarm_erfindet_keinen() {
		$goals = array( '2026-Q3' => array( 'target' => 30000.0, 'alarm' => null ) );

		$august = KpiGoals::for_bucket( $goals, Period::bucket( 'monat', '2026-08-15' ), KpiGoals::SUM );

		$this->assertNotNull( $august['target'] );
		$this->assertNull( $august['alarm'] );
	}

	/**
	 * Jede Kennzahl weiss, ob sie sich ansammelt.
	 *
	 * Der Fall, der leicht falsch wird: „Arbeitsstunden je Woche" ist trotz
	 * der Stunden keine Summe – die Kennzahl ist schon je Woche normiert.
	 */
	public function test_jede_kennzahl_kennt_ihre_art() {
		$this->assertSame( KpiGoals::SUM, KpiGoals::kind( 'umsatz' ) );
		$this->assertSame( KpiGoals::SUM, KpiGoals::kind( 'bestellungen' ) );

		foreach ( array( 'ausschussquote', 'warenkorb', 'auslastung', 'wochenstunden', 'handlingzeit' ) as $key ) {
			$this->assertSame( KpiGoals::RATIO, KpiGoals::kind( $key ), $key );
		}

		// Eine unbekannte Kennzahl gilt als Verhältnis: Eine unverändert
		// übernommene Grenze ist sichtbar falsch, eine geteilte unsichtbar.
		$this->assertSame( KpiGoals::RATIO, KpiGoals::kind( 'gibtsnicht' ) );
	}

	/**
	 * Alle Kennzahlen tragen eine Art – sonst rät KpiGoals.
	 */
	public function test_alle_kennzahlen_tragen_eine_art() {
		foreach ( Kpi::definitions() as $key => $definition ) {
			$this->assertArrayHasKey( 'kind', $definition, $key );
			$this->assertContains( $definition['kind'], array( Kpi::SUM, Kpi::RATIO ), $key );
		}
	}

	/**
	 * Ein leeres Feld heisst „kein Ziel", nicht „Ziel null".
	 */
	public function test_leeres_feld_ist_kein_ziel() {
		$result = KpiGoals::sanitize(
			array(
				'umsatz'         => array( 'target' => '', 'alarm' => '' ),
				'ausschussquote' => array( 'target' => '6', 'alarm' => '' ),
			)
		);

		$this->assertArrayNotHasKey( 'umsatz', $result );
		$this->assertSame( 6.0, $result['ausschussquote']['target'] );
		$this->assertNull( $result['ausschussquote']['alarm'] );
	}

	/**
	 * Komma als Dezimaltrennzeichen ist das, was jemand tippt.
	 */
	public function test_komma_wird_gelesen() {
		$result = KpiGoals::sanitize( array( 'warenkorb' => array( 'target' => '57,50' ) ) );

		$this->assertSame( 57.5, $result['warenkorb']['target'] );
	}

	/**
	 * Unbekannte Kennzahlen kommen nicht durch.
	 */
	public function test_fremde_kennzahlen_fallen_heraus() {
		$result = KpiGoals::sanitize( array( 'boeses_feld' => array( 'target' => '1' ) ) );

		$this->assertSame( array(), $result );
	}
}
