<?php
/**
 * AR-Ansicht: Zähler – Ereignisse, Auswertung und Ja-Quote.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\ArView\Stats;
use PHPUnit\Framework\TestCase;

/**
 * Reine Logik der Zähler.
 */
class ArViewStatsTest extends TestCase {

	/**
	 * Genau die vier Ereignisse sind zulässig.
	 */
	public function test_ereignisse() {
		$this->assertSame( array( 'shown', 'yes', 'no', 'started' ), array_keys( Stats::events() ) );

		foreach ( array( 'shown', 'yes', 'no', 'started' ) as $e ) {
			$this->assertTrue( Stats::is_event( $e ) );
		}

		foreach ( array( '', 'SHOWN', 'drop', "yes'; --", null, 5, array( 'yes' ) ) as $e ) {
			$this->assertFalse( Stats::is_event( $e ), 'Darf kein Ereignis sein: ' . wp_json_encode_safe( $e ) );
		}
	}

	/**
	 * Zeilen werden je Produkt zusammengefaltet, Fehlendes ist 0.
	 */
	public function test_pivot() {
		$out = Stats::pivot(
			array(
				array( 'product_id' => '5', 'event' => 'shown', 'cnt' => '10' ),
				array( 'product_id' => '5', 'event' => 'yes', 'cnt' => '3' ),
				array( 'product_id' => '5', 'event' => 'shown', 'cnt' => '2' ),
				array( 'product_id' => '9', 'event' => 'started', 'cnt' => '4' ),
				array( 'product_id' => '9', 'event' => 'bogus', 'cnt' => '99' ),
			)
		);

		$this->assertSame( array( 'shown' => 12, 'yes' => 3, 'no' => 0, 'started' => 0 ), $out[5] );
		$this->assertSame( array( 'shown' => 0, 'yes' => 0, 'no' => 0, 'started' => 4 ), $out[9] );
	}

	/**
	 * Ja-Quote: Ja geteilt durch gezeigt, ohne Division durch null.
	 */
	public function test_quote() {
		$this->assertSame( 25.0, Stats::rate( 1, 4 ) );
		$this->assertSame( 33.3, Stats::rate( 1, 3 ) );
		$this->assertSame( 0.0, Stats::rate( 0, 10 ) );
		$this->assertNull( Stats::rate( 5, 0 ) );
		$this->assertNull( Stats::rate( 0, 0 ) );
	}

	/**
	 * Zählen ohne gültige Eingabe tut nichts (und braucht keine Datenbank).
	 */
	public function test_ungueltige_eingaben_werden_nicht_gezaehlt() {
		$this->assertFalse( Stats::record( 0, 'shown' ) );
		$this->assertFalse( Stats::record( -3, 'shown' ) );
		$this->assertFalse( Stats::record( 12, 'unbekannt' ) );
	}
}

/**
 * Hilfsfunktion für die Fehlermeldung oben.
 *
 * @param mixed $v Wert.
 * @return string
 */
function wp_json_encode_safe( $v ) {
	return json_encode( $v );
}
