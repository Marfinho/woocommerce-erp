<?php
/**
 * Messbalken einer Kennzahl: Lage von Wert, Ziel und Alarm.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\KpiMeter;
use PHPUnit\Framework\TestCase;

/**
 * Die Skala muss alle drei Größen zeigen – auch wenn der Wert das Ziel
 * weit überschreitet.
 */
class BetriebKpiMeterTest extends TestCase {

	/**
	 * Der größte Wert bestimmt die Skala, mit etwas Luft nach rechts.
	 */
	public function test_groesster_wert_bestimmt_die_skala() {
		$lage = KpiMeter::scale( 20, 55, 45 );

		$this->assertSame( 55 * KpiMeter::HEADROOM, $lage['max'] );
		$this->assertLessThan( 1.0, $lage['target'] );
	}

	/**
	 * Ein Wert über dem Ziel sprengt die Skala nicht – sie wächst mit.
	 */
	public function test_wert_ueber_ziel_bleibt_im_bild() {
		$lage = KpiMeter::scale( 200, 55, 45 );

		$this->assertSame( 200 * KpiMeter::HEADROOM, $lage['max'] );
		$this->assertLessThan( 1.0, $lage['value'] );
		$this->assertGreaterThan( 0.8, $lage['value'] );
	}

	/**
	 * Ziel und Alarm behalten ihr Verhältnis zueinander.
	 */
	public function test_verhaeltnis_bleibt_erhalten() {
		$lage = KpiMeter::scale( 10, 100, 50 );

		$this->assertEqualsWithDelta( $lage['target'] / 2, $lage['alarm'], 0.0001 );
	}

	/**
	 * Ohne Ziel und Alarm bleibt nur der Wert.
	 */
	public function test_ohne_marken_nur_der_wert() {
		$lage = KpiMeter::scale( 21.78, null, null );

		$this->assertNull( $lage['target'] );
		$this->assertNull( $lage['alarm'] );
		$this->assertNotNull( $lage['value'] );
	}

	/**
	 * Ohne jede Zahl gibt es nichts zu zeichnen – und keine Division
	 * durch null.
	 */
	public function test_ohne_zahlen_keine_skala() {
		$lage = KpiMeter::scale( null, null, null );

		$this->assertSame( 0.0, $lage['max'] );
		$this->assertNull( $lage['value'] );
	}

	/**
	 * Ein Wert von 0 bei gesetztem Ziel ist etwas anderes als kein Wert:
	 * Der Balken ist leer, die Marken stehen trotzdem.
	 */
	public function test_null_ist_nicht_nichts() {
		$lage = KpiMeter::scale( 0, 55, 45 );

		$this->assertSame( 0.0, $lage['value'] );
		$this->assertNotNull( $lage['target'] );
	}

	/**
	 * Negative Werte gibt es auf dieser Skala nicht – sie beginnt bei 0.
	 */
	public function test_negativer_wert_wird_zu_null() {
		$lage = KpiMeter::scale( -5, 10, null );

		$this->assertSame( 0.0, $lage['value'] );
	}

	/**
	 * Jede Bewertungsstufe hat eine eigene Farbe.
	 */
	public function test_jede_stufe_hat_eine_farbe() {
		$farben = KpiMeter::colors();

		foreach ( array( 'gruen', 'gelb', 'rot', 'neutral' ) as $stufe ) {
			$this->assertArrayHasKey( $stufe, $farben );
			$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/i', $farben[ $stufe ] );
		}
	}

	/**
	 * Ohne Ziel und Alarm gibt es keinen Balken.
	 *
	 * Ein Balken braucht eine Skala. Bei „Umsatz" ohne Ziel wäre jede
	 * Länge willkürlich und täuschte einen Maßstab vor.
	 */
	public function test_ohne_marken_kein_balken() {
		$this->assertSame( '', KpiMeter::bar( array( 'value' => 21.78, 'target' => null, 'alarm' => null ) ) );
	}

	/**
	 * Mit Marken entstehen Spur, Wert und beide Markierungen.
	 */
	public function test_balken_zeigt_wert_und_marken() {
		$html = KpiMeter::bar(
			array( 'level' => 'rot', 'value' => 21.78, 'target' => 55.0, 'alarm' => 45.0 )
		);

		$this->assertStringContainsString( 'lw16-meter__track', $html );
		$this->assertStringContainsString( 'lw16-meter__value--rot', $html );
		$this->assertStringContainsString( 'lw16-meter__mark--ziel', $html );
		$this->assertStringContainsString( 'lw16-meter__mark--alarm', $html );
	}

	/**
	 * Ohne Wert, aber mit Marken: Spur und Marken, kein Balken.
	 */
	public function test_ohne_wert_nur_die_marken() {
		$html = KpiMeter::bar( array( 'value' => null, 'target' => 9.0, 'alarm' => 12.0 ) );

		$this->assertStringContainsString( 'lw16-meter__mark--ziel', $html );
		$this->assertStringNotContainsString( 'lw16-meter__value', $html );
	}

	/**
	 * Eine unbekannte Stufe färbt nicht irgendwie, sondern neutral.
	 */
	public function test_unbekannte_stufe_wird_neutral() {
		$html = KpiMeter::bar( array( 'level' => 'lila', 'value' => 5, 'target' => 10, 'alarm' => null ) );

		$this->assertStringContainsString( 'lw16-meter__value--neutral', $html );
		$this->assertStringNotContainsString( 'lila', $html );
	}

	/**
	 * Die Breitenangabe ist CSS und trägt deshalb immer einen Punkt –
	 * ein Komma ergäbe je nach Spracheinstellung eine kaputte Breite.
	 */
	public function test_breite_immer_mit_punkt() {
		$this->assertSame( '39.60', KpiMeter::pct( 0.396 ) );
		$this->assertSame( '100.00', KpiMeter::pct( 5 ) );
		$this->assertSame( '0.00', KpiMeter::pct( -1 ) );
	}

	/**
	 * Die Beschriftung landet im aria-label, damit die Farbe nicht die
	 * einzige Aussage ist.
	 */
	public function test_beschriftung_steht_im_label() {
		$html = KpiMeter::bar(
			array( 'value' => 21.78, 'target' => 55.0, 'alarm' => 45.0, 'aria' => 'Ø-Warenkorb: 21,78 €. Ziel 55,00 €' )
		);

		$this->assertStringContainsString( 'aria-label="Ø-Warenkorb: 21,78 €. Ziel 55,00 €"', $html );
	}
}
