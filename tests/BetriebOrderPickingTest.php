<?php
/**
 * Prüft die Regeln des Kommissionierens: Scan, Zählstand, „ist alles da?“.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\OrderPicking;
use PHPUnit\Framework\TestCase;

/**
 * Kommissionier-Tests.
 */
class BetriebOrderPickingTest extends TestCase {

	/**
	 * Zwei Positionen mit Code, eine ohne.
	 *
	 * @return array
	 */
	protected function lines() {
		return array(
			array( 'id' => 11, 'qty' => 2, 'codes' => array( 'LW-100', '4012345678901' ) ),
			array( 'id' => 12, 'qty' => 1, 'codes' => array( 'LW-200' ) ),
			array( 'id' => 13, 'qty' => 3, 'codes' => array() ),
		);
	}

	public function test_codes_werden_bereinigt() {
		$this->assertSame( array( 'LW-100', '4012' ), OrderPicking::normalize_codes( array( ' lw-100 ', '', '4012', 'LW-100' ) ) );
	}

	public function test_scan_zaehlt_die_position_hoch() {
		$r = OrderPicking::apply_scan( $this->lines(), array(), 'lw-100' );

		$this->assertSame( 'ok', $r['result'] );
		$this->assertSame( 11, $r['id'] );
		$this->assertSame( array( 11 => 1 ), $r['scans'] );
	}

	public function test_ean_passt_wie_die_artikelnummer() {
		$r = OrderPicking::apply_scan( $this->lines(), array(), '4012345678901' );

		$this->assertSame( 'ok', $r['result'] );
		$this->assertSame( 11, $r['id'] );
	}

	public function test_fremder_code_wird_abgelehnt_und_zaehlt_nicht() {
		$r = OrderPicking::apply_scan( $this->lines(), array( 11 => 1 ), 'LW-999' );

		$this->assertSame( 'unknown', $r['result'] );
		$this->assertSame( array( 11 => 1 ), $r['scans'] );
		$this->assertSame( 'unknown', OrderPicking::apply_scan( $this->lines(), array(), '  ' )['result'] );
	}

	public function test_zu_viel_wird_gemeldet_und_nicht_gezaehlt() {
		$r = OrderPicking::apply_scan( $this->lines(), array( 12 => 1 ), 'LW-200' );

		$this->assertSame( 'too_many', $r['result'] );
		$this->assertSame( array( 12 => 1 ), $r['scans'] );
	}

	public function test_gleicher_code_in_zwei_positionen_fuellt_nacheinander() {
		$lines = array(
			array( 'id' => 1, 'qty' => 1, 'codes' => array( 'X' ) ),
			array( 'id' => 2, 'qty' => 1, 'codes' => array( 'X' ) ),
		);

		$r = OrderPicking::apply_scan( $lines, array( 1 => 1 ), 'X' );

		$this->assertSame( 'ok', $r['result'] );
		$this->assertSame( 2, $r['id'] );
	}

	public function test_ohne_code_nur_von_hand_abhaken() {
		$ok = OrderPicking::confirm_manual( $this->lines(), array(), 13 );
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( array( 13 => 3 ), $ok['scans'] );

		$this->assertFalse( OrderPicking::confirm_manual( $this->lines(), array(), 11 )['ok'], 'Eine Position mit Code muss gescannt werden.' );
		$this->assertFalse( OrderPicking::confirm_manual( $this->lines(), array(), 99 )['ok'] );
	}

	public function test_vollstaendig_erst_wenn_jede_menge_erreicht_ist() {
		$this->assertFalse( OrderPicking::is_complete( $this->lines(), array() ) );
		$this->assertFalse( OrderPicking::is_complete( $this->lines(), array( 11 => 2, 12 => 1, 13 => 2 ) ) );
		$this->assertTrue( OrderPicking::is_complete( $this->lines(), array( 11 => 2, 12 => 1, 13 => 3 ) ) );
		$this->assertFalse( OrderPicking::is_complete( array(), array() ), 'Ohne Positionen gibt es nichts zu melden.' );
	}

	public function test_status_steht_hinter_in_bearbeitung_und_zaehlt_als_bezahlt() {
		$list = OrderPicking::add_status( array( 'wc-pending' => 'A', 'wc-processing' => 'B', 'wc-completed' => 'C' ) );

		$this->assertSame( array( 'wc-pending', 'wc-processing', 'wc-kommissioniert', 'wc-completed' ), array_keys( $list ) );
		$this->assertContains( 'kommissioniert', OrderPicking::add_paid( array( 'processing', 'completed' ) ) );
		$this->assertCount( 3, OrderPicking::add_paid( OrderPicking::add_paid( array( 'processing', 'completed' ) ) ) );
	}
}
