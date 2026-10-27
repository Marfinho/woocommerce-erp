<?php
/**
 * Filamentrollen: Verbrauch auf Rollen verteilen, leer melden, wiegen.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\RollAllocator;

/**
 * Die Druckjobs schätzen, die leere Spule weiß es genau.
 */
class BetriebFilamentRollsTest extends TestCase {

	/**
	 * Hilfsmittel: eine Rolle.
	 *
	 * @param int    $id     ID.
	 * @param string $status Status.
	 * @param array  $more   Weitere Felder.
	 * @return array
	 */
	protected function roll( $id, $status = 'neu', array $more = array() ) {
		return array_merge(
			array(
				'id'                  => $id,
				'status'              => $status,
				'start_g'             => 1000,
				'corr_g'              => 0,
				'consumed_g_at_close' => 0,
				'opened_ts'           => 0,
			),
			$more
		);
	}

	/**
	 * Eine Lieferung wird in Rollen geteilt, der Rest ist eine kleinere Rolle.
	 */
	public function test_charge_wird_in_rollen_geteilt() {
		$this->assertSame( array( 1000, 1000, 1000, 1000 ), RollAllocator::split( 4.0, 1000 ) );
		$this->assertSame( array( 1000, 1000, 250 ), RollAllocator::split( 2.25, 1000 ) );
		$this->assertSame( array( 500 ), RollAllocator::split( 0.5, 1000 ), 'Weniger als eine Rolle bleibt eine Rolle.' );
		$this->assertSame( array( 3000 ), RollAllocator::split( 3.0, 0 ), 'Ohne Rollengewicht eine einzige Rolle.' );
		$this->assertSame( array(), RollAllocator::split( 0, 1000 ) );
	}

	/**
	 * Verbrauch füllt zuerst die Rolle im Drucker, dann die neuen.
	 */
	public function test_verbrauch_zuerst_auf_angebrochene_rolle() {
		$rolls = array(
			$this->roll( 1, 'neu' ),
			$this->roll( 2, 'in_benutzung', array( 'opened_ts' => 100 ) ),
			$this->roll( 3, 'neu' ),
		);

		$a = RollAllocator::allocate( $rolls, 1300 );

		$this->assertSame( 1000.0, $a[2]['absorbed_g'], 'Die Rolle im Drucker nimmt zuerst auf.' );
		$this->assertSame( 0.0, $a[2]['remaining_g'] );
		$this->assertSame( 300.0, $a[1]['absorbed_g'] );
		$this->assertSame( 700.0, $a[1]['remaining_g'] );
		$this->assertSame( 1000.0, $a[3]['remaining_g'] );
		$this->assertSame( 1700.0, RollAllocator::remaining_g( $a ) );
	}

	/**
	 * Leer gescannt: Die Rolle fällt aus dem Bestand, der Rest an Rechnung ist Schwund.
	 */
	public function test_leer_gescannt_fehlt_im_bestand() {
		$rolls = array( $this->roll( 1, 'in_benutzung' ), $this->roll( 2, 'neu' ) );

		// Die Druckjobs haben 700 g verbucht.
		$vorher = RollAllocator::allocate( $rolls, 700 );
		$this->assertSame( 300.0, $vorher[1]['remaining_g'] );
		$this->assertSame( 1300.0, RollAllocator::remaining_g( $vorher ) );

		// Die Rolle ist tatsächlich leer: 300 g Schwund, Absorbiertes wird eingefroren.
		$rolls[0]['status']              = 'leer';
		$rolls[0]['consumed_g_at_close'] = $vorher[1]['absorbed_g'];

		$nachher = RollAllocator::allocate( $rolls, 700 );
		$this->assertSame( 0.0, $nachher[1]['remaining_g'] );
		$this->assertSame( 1000.0, $nachher[2]['remaining_g'], 'Die zweite Rolle bleibt unberührt.' );
		$this->assertSame( 1000.0, RollAllocator::remaining_g( $nachher ), 'Der Bestand sank um die 300 g Schwund.' );

		// Weiterer Verbrauch belastet nur noch die zweite Rolle.
		$spaeter = RollAllocator::allocate( $rolls, 900 );
		$this->assertSame( 800.0, $spaeter[2]['remaining_g'] );
	}

	/**
	 * Mehr Verbrauch als Rollen ergibt null, nicht minus.
	 */
	public function test_nie_unter_null() {
		$a = RollAllocator::allocate( array( $this->roll( 1 ) ), 5000 );

		$this->assertSame( 0.0, $a[1]['remaining_g'] );
		$this->assertSame( 0.0, RollAllocator::remaining_g( $a ) );
	}

	/**
	 * Gewogen: Das Restgewicht richtet sich nach der Waage, weiterer Verbrauch zählt davon ab.
	 */
	public function test_wiegen_korrigiert_das_restgewicht() {
		$rolls = array( $this->roll( 1, 'in_benutzung' ) );
		$est   = RollAllocator::allocate( $rolls, 300 )[1]['remaining_g'];
		$this->assertSame( 700.0, $est );

		// Waage: 620 g Filament + 250 g Spule = 870 g.
		$m = RollAllocator::measurement( $est, 870, 250 );
		$this->assertSame( 620.0, $m['measured_g'] );
		$this->assertSame( 80.0, $m['delta_g'] );

		$rolls[0]['corr_g'] = $m['delta_g'];
		$this->assertSame( 620.0, RollAllocator::allocate( $rolls, 300 )[1]['remaining_g'] );
		$this->assertSame( 520.0, RollAllocator::allocate( $rolls, 400 )[1]['remaining_g'], 'Weiterer Verbrauch zählt von der gewogenen Menge ab.' );
	}

	/**
	 * Eine Spule ohne Filament (Waage unter Leergewicht) ergibt null.
	 */
	public function test_wiegen_unter_leergewicht() {
		$this->assertSame( 0.0, RollAllocator::measurement( 500, 200, 250 )['measured_g'] );
	}

	/**
	 * Viel Schwund fällt auf, wenig nicht.
	 */
	public function test_schwund_stufe() {
		$this->assertSame( 'normal', RollAllocator::shrinkage_level( 40, 1000 ) );
		$this->assertSame( 'attention', RollAllocator::shrinkage_level( 150, 1000 ) );
		$this->assertSame( 'normal', RollAllocator::shrinkage_level( 0, 0 ) );
	}
}
