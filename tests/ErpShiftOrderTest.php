<?php
/**
 * Reihenfolge der Platten nach Tageszeit: lange über Nacht, kurze am Tag.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Erp\ShiftOrder;

/**
 * Wer nachts nicht da ist, startet die lange Platte vor Feierabend.
 */
class ErpShiftOrderTest extends TestCase {

	/**
	 * Die Platten aus dem Alltag: eine mit 7 Stunden, vier mit einer.
	 *
	 * @return array
	 */
	protected function runs() {
		return array(
			array( 'id' => 4, 'est_minutes' => 420 ),
			array( 'id' => 5, 'est_minutes' => 60 ),
			array( 'id' => 6, 'est_minutes' => 60 ),
			array( 'id' => 7, 'est_minutes' => 60 ),
			array( 'id' => 8, 'est_minutes' => 60 ),
		);
	}

	/**
	 * Vormittags: die kurzen zuerst, die lange zuletzt.
	 */
	public function test_vormittags_zuerst_die_kurzen() {
		$ids = array_column( ShiftOrder::order( $this->runs(), 9 * 60, 17 * 60 ), 'id' );

		$this->assertSame( array( 5, 6, 7, 8, 4 ), $ids );
	}

	/**
	 * Kurz vor Feierabend: die lange zuerst, sie läuft über Nacht.
	 */
	public function test_nachmittags_zuerst_die_lange() {
		$ordered = ShiftOrder::order( $this->runs(), 15 * 60 + 30, 17 * 60 );

		$this->assertSame( 4, $ordered[0]['id'] );
		$this->assertSame( 'night_now', $ordered[0]['hint'] );
	}

	/**
	 * Die lange Platte trägt tagsüber den Hinweis, wann sich der Start lohnt.
	 */
	public function test_hinweis_tagsueber() {
		$ordered = ShiftOrder::order( $this->runs(), 9 * 60, 17 * 60 );
		$lang    = array_values( array_filter( $ordered, static function ( $r ) { return 4 === $r['id']; } ) )[0];

		$this->assertTrue( $lang['long'] );
		$this->assertSame( 'night_later', $lang['hint'] );
		$this->assertSame( '', $ordered[0]['hint'] );
	}

	/**
	 * Tagsüber kommt zuerst, was vor Feierabend noch fertig wird.
	 */
	public function test_was_vor_feierabend_fertig_wird_kommt_vor() {
		$runs = array(
			array( 'id' => 1, 'est_minutes' => 180 ),
			array( 'id' => 2, 'est_minutes' => 60 ),
		);

		// Es ist 14:30, Feierabend 17:00 (2,5 h): 180 min passt nicht mehr, 60 min schon.
		$ids = array_column( ShiftOrder::order( $runs, 14 * 60 + 30, 17 * 60, 1.0 ), 'id' );

		$this->assertSame( array( 2, 1 ), $ids );
	}

	/**
	 * Nachts (vor sechs) gilt Abend: lange zuerst.
	 */
	public function test_nachts_gilt_abend() {
		$this->assertSame( ShiftOrder::EVENING, ShiftOrder::phase( 3 * 60, 17 * 60, 2.0 ) );
		$this->assertSame( ShiftOrder::DAY, ShiftOrder::phase( 8 * 60, 17 * 60, 2.0 ) );
		$this->assertSame( ShiftOrder::EVENING, ShiftOrder::phase( 15 * 60, 17 * 60, 2.0 ) );
	}

	/**
	 * Unbekannte Dauer gilt als kurz, nichts bricht.
	 */
	public function test_unbekannte_dauer() {
		$ordered = ShiftOrder::order( array( array( 'id' => 1, 'est_minutes' => 0 ), array( 'id' => 2, 'est_minutes' => 300 ) ), 9 * 60, 17 * 60 );

		$this->assertSame( 1, $ordered[0]['id'] );
		$this->assertFalse( $ordered[0]['long'] );
		$this->assertSame( array(), ShiftOrder::order( array(), 9 * 60, 17 * 60 ) );
	}

	/**
	 * Uhrzeit lesen, Dauer und Hinweis als Text.
	 */
	public function test_zeiten_und_texte() {
		$this->assertSame( 17 * 60, ShiftOrder::minutes_of( '17:00' ) );
		$this->assertSame( 16 * 60 + 30, ShiftOrder::minutes_of( '16:30' ) );
		$this->assertSame( 17 * 60, ShiftOrder::minutes_of( 'quatsch' ) );
		$this->assertSame( '7 h', ShiftOrder::duration_text( 420 ) );
		$this->assertSame( '1 h 30 min', ShiftOrder::duration_text( 90 ) );
		$this->assertSame( '45 min', ShiftOrder::duration_text( 45 ) );
		$this->assertStringContainsString( 'jetzt starten', ShiftOrder::hint_text( 'night_now', 420, 900 ) );
		$this->assertStringContainsString( 'ab 15:00', ShiftOrder::hint_text( 'night_later', 420, 900 ) );
	}
}
