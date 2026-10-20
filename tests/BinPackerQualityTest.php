<?php
/**
 * BinPacker: Die Anordnung nutzt das Bett aus und bleibt gültig.
 *
 * Anlass: Zwei Platten (10 und 4 Teile), obwohl alles auf eine passte – der
 * frühere Kandidatensatz kannte Nischen an den Kanten zweier Teile nicht und
 * nahm den ersten freien Treffer statt des besten.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\Nesting\BinPacker;
use Lindenweg16\Modules\Betrieb\Nesting\Polygon;
use Lindenweg16\Modules\Betrieb\Nesting\Sat;

class BinPackerQualityTest extends TestCase {

	protected function rect( $w, $h ) {
		return array( array( 0, 0 ), array( $w, 0 ), array( $w, $h ), array( 0, $h ) );
	}

	protected function ellipse( $a, $b ) {
		$points = array();
		for ( $i = 0; $i < 32; $i++ ) {
			$t        = 2 * M_PI * $i / 32;
			$points[] = array( $a + $a * cos( $t ), $b + $b * sin( $t ) );
		}
		return $points;
	}

	protected function half_disc( $r ) {
		$points = array();
		for ( $i = 0; $i <= 16; $i++ ) {
			$t        = M_PI * $i / 16;
			$points[] = array( $r + $r * cos( $t ), $r * sin( $t ) );
		}
		return $points;
	}

	protected function parts( array $sets ) {
		$parts = array();
		foreach ( $sets as $key => $set ) {
			for ( $i = 0; $i < $set[0]; $i++ ) {
				$parts[] = array( 'key' => $key . $i, 'hull' => $set[1] );
			}
		}
		return $parts;
	}

	/**
	 * Alle Platzierungen liegen im Bett, mit Randabstand, und überlappen einander nicht.
	 */
	protected function assert_valid( array $parts, array $result, $w, $h, $margin ) {
		$hulls = array();
		foreach ( $parts as $part ) {
			$hulls[ $part['key'] ] = $part['hull'];
		}

		$seen = 0;
		foreach ( $result['bins'] as $bin ) {
			$placed = array();

			foreach ( $bin['placements'] as $p ) {
				++$seen;
				$hull = Polygon::place( Polygon::convex_hull( $hulls[ $p['key'] ] ), $p['rotation_deg'], $p['x'], $p['y'] );
				$box  = Polygon::bounding_box( $hull );

				$this->assertGreaterThanOrEqual( $margin - 0.02, $box['minx'] );
				$this->assertGreaterThanOrEqual( $margin - 0.02, $box['miny'] );
				$this->assertLessThanOrEqual( $w - $margin + 0.02, $box['maxx'] );
				$this->assertLessThanOrEqual( $h - $margin + 0.02, $box['maxy'] );

				foreach ( $placed as $other ) {
					$this->assertFalse( Sat::overlap( $hull, Polygon::offset( $other, $margin - 0.05 ) ), 'Teile dürfen sich nicht berühren.' );
				}

				$placed[] = $hull;
			}
		}

		$this->assertSame( count( $parts ), $seen, 'Kein Teil darf verloren gehen.' );
	}

	public function test_zehn_teile_und_vier_ringe_passen_auf_eine_platte() {
		$parts = $this->parts(
			array(
				'tray' => array( 1, $this->rect( 190, 100 ) ),
				'oval' => array( 1, $this->ellipse( 44, 36 ) ),
				'bsm'  => array( 6, $this->rect( 47, 36 ) ),
				'ring' => array( 4, $this->half_disc( 25 ) ),
				'wand' => array( 2, $this->rect( 31, 16 ) ),
			)
		);

		$result = BinPacker::arrange( $parts, 256, 249, 5.0 );

		$this->assertCount( 1, $result['bins'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assert_valid( $parts, $result, 256, 249, 5.0 );
	}

	public function test_anordnung_bleibt_gueltig_bei_gemischten_teilen() {
		$parts = $this->parts(
			array(
				'a' => array( 2, $this->rect( 200, 85 ) ),
				'b' => array( 3, $this->ellipse( 40, 35 ) ),
				'c' => array( 8, $this->rect( 50, 30 ) ),
				'd' => array( 5, $this->half_disc( 22 ) ),
			)
		);

		$result = BinPacker::arrange( $parts, 256, 249, 5.0 );

		$this->assertSame( array(), $result['errors'] );
		$this->assert_valid( $parts, $result, 256, 249, 5.0 );
	}

	public function test_ergebnis_ist_deterministisch() {
		$parts = $this->parts( array( 'a' => array( 7, $this->rect( 60, 35 ) ), 'b' => array( 5, $this->ellipse( 25, 25 ) ) ) );

		$this->assertSame( BinPacker::arrange( $parts, 256, 249, 5.0 ), BinPacker::arrange( $parts, 256, 249, 5.0 ) );
	}
}
