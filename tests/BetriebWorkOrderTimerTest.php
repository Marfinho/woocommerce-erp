<?php
/**
 * Zeitmessung an Fertigungsaufträgen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\WorkOrderRepository;
use PHPUnit\Framework\TestCase;

/**
 * Der Timer misst Arbeitszeit. Er darf nichts buchen und nichts erfinden.
 */
class BetriebWorkOrderTimerTest extends TestCase {

	/**
	 * Ein Segment wird auf die bisherige Dauer addiert.
	 */
	public function test_segment_wird_addiert() {
		$this->assertSame(
			1800,
			WorkOrderRepository::accumulate( 0, '2026-08-21 10:00:00', '2026-08-21 10:30:00' )
		);
	}

	/**
	 * Mehrere Segmente summieren sich.
	 */
	public function test_zweites_segment_kommt_dazu() {
		$this->assertSame(
			2700,
			WorkOrderRepository::accumulate( 1800, '2026-08-21 11:00:00', '2026-08-21 11:15:00' )
		);
	}

	/**
	 * Ohne Startzeitpunkt ändert sich nichts.
	 */
	public function test_ohne_start_bleibt_die_summe() {
		$this->assertSame( 900, WorkOrderRepository::accumulate( 900, '', '2026-08-21 11:15:00' ) );
	}

	/**
	 * Eine rückwärts laufende Uhr zählt nicht – sonst entstünde eine
	 * negative Arbeitszeit.
	 */
	public function test_rueckwaerts_zaehlt_nicht() {
		$this->assertSame(
			600,
			WorkOrderRepository::accumulate( 600, '2026-08-21 12:00:00', '2026-08-21 11:00:00' )
		);
	}

	/**
	 * Ein vergessener Timer wird gekappt, nicht übernommen.
	 */
	public function test_vergessener_timer_wird_gekappt() {
		$dreiTage = WorkOrderRepository::accumulate( 0, '2026-08-21 08:00:00', '2026-08-24 08:00:00' );

		$this->assertSame( WorkOrderRepository::MAX_SEGMENT, $dreiTage );
		$this->assertLessThan( 3 * 24 * 3600, $dreiTage );
	}

	/**
	 * Genau an der Grenze wird noch voll gezählt.
	 */
	public function test_grenze_wird_noch_voll_gezaehlt() {
		$this->assertSame(
			WorkOrderRepository::MAX_SEGMENT,
			WorkOrderRepository::accumulate( 0, '2026-08-21 00:00:00', '2026-08-21 12:00:00' )
		);
	}

	/**
	 * Dauern werden lesbar ausgegeben.
	 */
	public function test_dauer_wird_lesbar() {
		$this->assertSame( '2 h 05 min', WorkOrderRepository::format_duration( 7500 ) );
		$this->assertSame( '25 min', WorkOrderRepository::format_duration( 1500 ) );
		$this->assertSame( '42 s', WorkOrderRepository::format_duration( 42 ) );
	}

	/**
	 * Die Kennzahl, für die der Timer da ist.
	 */
	public function test_minuten_je_stueck() {
		$this->assertSame( 6.0, WorkOrderRepository::minutes_per_piece( 3600, 10 ) );
	}

	/**
	 * Ohne Messung oder ohne Menge gibt es keine Kennzahl – und keine Null.
	 */
	public function test_ohne_messung_keine_kennzahl() {
		$this->assertNull( WorkOrderRepository::minutes_per_piece( 0, 10 ) );
		$this->assertNull( WorkOrderRepository::minutes_per_piece( 3600, 0 ) );
	}

	/**
	 * Laufend ist nur, was den Zustand und einen Startzeitpunkt hat.
	 */
	public function test_laufend_braucht_beides() {
		$this->assertTrue(
			WorkOrderRepository::is_running( array( 'status' => 'laufend', 'started_at' => '2026-08-21 10:00:00' ) )
		);
		$this->assertFalse(
			WorkOrderRepository::is_running( array( 'status' => 'laufend', 'started_at' => '' ) )
		);
		$this->assertFalse(
			WorkOrderRepository::is_running( array( 'status' => 'offen', 'started_at' => '2026-08-21 10:00:00' ) )
		);
	}

	/**
	 * Bei laufender Uhr zählt das angebrochene Segment mit.
	 */
	public function test_laufendes_segment_zaehlt_mit() {
		$order = array(
			'status'         => 'laufend',
			'started_at'     => '2026-08-21 10:00:00',
			'seconds_worked' => 600,
		);

		$this->assertSame( 1500, WorkOrderRepository::elapsed( $order, '2026-08-21 10:15:00' ) );
	}

	/**
	 * Bei angehaltener Uhr bleibt es bei der Summe.
	 */
	public function test_pausierter_auftrag_zaehlt_nicht_weiter() {
		$order = array(
			'status'         => 'offen',
			'started_at'     => null,
			'seconds_worked' => 600,
		);

		$this->assertSame( 600, WorkOrderRepository::elapsed( $order, '2026-08-21 23:00:00' ) );
	}

	/**
	 * Der neue Zustand gehört in die Liste der erlaubten.
	 */
	public function test_laufend_ist_ein_gueltiger_zustand() {
		$this->assertContains( 'laufend', WorkOrderRepository::STATES );
	}

	/**
	 * Ein gestarteter Auftrag zählt weiter als offene Arbeit.
	 *
	 * Sonst fiele er aus der Bedarfsplanung und dieselbe Menge würde ein
	 * zweites Mal vorgeschlagen, sobald jemand die Uhr drückt.
	 */
	public function test_laufend_gilt_als_offene_arbeit() {
		$this->assertContains( 'offen', WorkOrderRepository::OPEN_STATES );
		$this->assertContains( 'laufend', WorkOrderRepository::OPEN_STATES );
		$this->assertNotContains( 'erledigt', WorkOrderRepository::OPEN_STATES );
		$this->assertNotContains( 'storniert', WorkOrderRepository::OPEN_STATES );
	}
}
