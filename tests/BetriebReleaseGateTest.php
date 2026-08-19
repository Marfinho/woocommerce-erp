<?php
/**
 * Freigabelogik der Artikelrevisionen.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\ReleaseGate;

/**
 * Mindestens fünf Proben und Sicherheitsfaktor 3,0.
 */
class BetriebReleaseGateTest extends TestCase {

	/**
	 * Proben mit gegebenen Bruchlasten bauen.
	 *
	 * @param array $newton Bruchlasten.
	 * @return array
	 */
	protected function tests( array $newton ) {
		$rows = array();
		foreach ( $newton as $i => $n ) {
			$rows[] = array(
				'sample_no'    => $i + 1,
				'break_load_n' => $n,
			);
		}
		return $rows;
	}

	/**
	 * Fünf ausreichend feste Proben geben die Revision frei.
	 */
	public function test_freigabe_bei_fuenf_guten_proben() {
		// Niedrigste 850 N → 86,6 kg gegen 25 kg Traglast → Faktor 3,47.
		$result = ReleaseGate::evaluate( $this->tests( array( 850, 910, 880, 935, 890 ) ), 25 );

		$this->assertTrue( $result['released'] );
		$this->assertSame( 5, $result['sample_count'] );
		$this->assertEqualsWithDelta( 850.0, $result['break_load_min_n'], 0.00001 );
		$this->assertEqualsWithDelta( 86.646, $result['kg_min'], 0.001 );
		$this->assertEqualsWithDelta( 3.466, $result['safety_factor'], 0.001 );
		$this->assertEmpty( $result['reasons'] );
	}

	/**
	 * Vier Proben reichen nicht, egal wie gut sie sind.
	 */
	public function test_vier_proben_reichen_nicht() {
		$result = ReleaseGate::evaluate( $this->tests( array( 1200, 1250, 1300, 1280 ) ), 25 );

		$this->assertFalse( $result['released'] );
		$this->assertCount( 1, $result['reasons'] );
		$this->assertStringContainsString( '4', $result['reasons'][0] );
	}

	/**
	 * Maßgeblich ist die schwächste Probe, nicht der Mittelwert – sonst
	 * gleicht ein Ausreißer nach oben einen Fehlschlag aus.
	 */
	public function test_schwaechste_probe_entscheidet() {
		// Mittelwert wäre ausreichend, die niedrigste Probe nicht.
		$result = ReleaseGate::evaluate( $this->tests( array( 600, 1200, 1150, 1180, 1210 ) ), 25 );

		$this->assertFalse( $result['released'] );
		$this->assertEqualsWithDelta( 600.0, $result['break_load_min_n'], 0.00001 );
		$this->assertGreaterThan( ReleaseGate::MIN_SAFETY_FACTOR, $result['kg_avg'] / 25 );
	}

	/**
	 * Genau auf dem geforderten Faktor wird freigegeben.
	 */
	public function test_faktor_genau_auf_der_schwelle() {
		// 25 kg × 3,0 = 75 kg × 9,81 = 735,75 N.
		$result = ReleaseGate::evaluate( $this->tests( array( 735.75, 800, 810, 820, 830 ) ), 25 );

		$this->assertEqualsWithDelta( 3.0, $result['safety_factor'], 0.00001 );
		$this->assertTrue( $result['released'] );
	}

	/**
	 * Knapp darunter wird verweigert.
	 */
	public function test_faktor_knapp_darunter_verweigert() {
		$result = ReleaseGate::evaluate( $this->tests( array( 735.0, 800, 810, 820, 830 ) ), 25 );

		$this->assertFalse( $result['released'] );
		$this->assertStringContainsString( 'Sicherheitsfaktor', $result['reasons'][0] );
	}

	/**
	 * Ohne angegebene Traglast lässt sich kein Faktor bilden – dann keine
	 * Freigabe, mit klarer Begründung.
	 */
	public function test_ohne_traglast_keine_freigabe() {
		$result = ReleaseGate::evaluate( $this->tests( array( 850, 910, 880, 935, 890 ) ), 0 );

		$this->assertFalse( $result['released'] );
		$this->assertStringContainsString( 'Traglast', $result['reasons'][0] );
	}

	/**
	 * Ohne Proben werden beide Gründe genannt.
	 */
	public function test_ohne_proben_keine_freigabe() {
		$result = ReleaseGate::evaluate( array(), 25 );

		$this->assertFalse( $result['released'] );
		$this->assertSame( 0, $result['sample_count'] );
		$this->assertCount( 2, $result['reasons'] );
	}

	/**
	 * Stornierte Proben zählen nicht mit – sonst ließe sich die Freigabe
	 * durch nachträgliches Stornieren schlechter Werte erschleichen.
	 */
	public function test_stornierte_proben_zaehlen_nicht() {
		$tests                = $this->tests( array( 850, 910, 880, 935, 890 ) );
		$tests[0]['voided_at'] = '2026-10-01 10:00:00';

		$result = ReleaseGate::evaluate( $tests, 25 );

		$this->assertSame( 4, $result['sample_count'] );
		$this->assertFalse( $result['released'] );
	}

	/**
	 * may_activate ist die knappe Form derselben Prüfung.
	 */
	public function test_may_activate() {
		$this->assertTrue( ReleaseGate::may_activate( $this->tests( array( 850, 910, 880, 935, 890 ) ), 25 ) );
		$this->assertFalse( ReleaseGate::may_activate( $this->tests( array( 850, 910, 880 ) ), 25 ) );
	}
}
