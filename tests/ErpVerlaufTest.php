<?php
/**
 * Verlauf: Aufrufe nach Tag, Wochentag und Tageszeit in der Zeit der Website.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\History;
use Lindenweg16\Erp\Navigation;
use PHPUnit\Framework\TestCase;

/**
 * Tests des Verlaufs.
 */
class ErpVerlaufTest extends TestCase {

	/**
	 * Mittwoch, 11.11.2026, 12:00 Uhr in Berlin (11:00 UTC).
	 */
	private function now() {
		return strtotime( '2026-11-11 11:00:00 UTC' );
	}

	private function berlin() {
		return new DateTimeZone( 'Europe/Berlin' );
	}

	public function test_zeitraum() {
		$this->assertSame( 30, History::clean_days( null ) );
		$this->assertSame( 365, History::clean_days( '365' ) );
		$this->assertSame( 7, History::clean_days( 7 ) );
		$this->assertSame( 30, History::clean_days( 5 ) );
	}

	public function test_umrechnung_auf_ortszeit_und_wochentag() {
		$rows = array(
			// Montag 09.11., 07:00 UTC = 08:00 Berlin.
			array( 'day' => '2026-11-09', 'hour' => 7, 'views' => 10 ),
			// Montag 09.11., 23:00 UTC = Dienstag 10.11., 00:00 Berlin.
			array( 'day' => '2026-11-09', 'hour' => 23, 'views' => 4 ),
			// Dienstag 10.11., 07:00 UTC = 08:00 Berlin.
			array( 'day' => '2026-11-10', 'hour' => 7, 'views' => 6 ),
		);

		$s = History::summarize( $rows, $this->now(), 7, $this->berlin() );

		$this->assertSame( 20, $s['total'] );
		$this->assertSame( '2026-11-09', $s['first'] );

		$by = array();
		foreach ( $s['daily'] as $d ) {
			$by[ $d['date'] ] = $d['views'];
		}
		$this->assertSame( 10, $by['2026-11-09'] );
		$this->assertSame( 10, $by['2026-11-10'], 'Die Stunde 23 Uhr UTC gehört in Berlin schon zum nächsten Tag.' );
		$this->assertSame( 10.0, $s['heat'][0][8], 'Montag 8 Uhr.' );
		$this->assertSame( 4.0, $s['heat'][1][0], 'Dienstag 0 Uhr.' );
		$this->assertSame( 6.0, $s['heat'][1][8], 'Dienstag 8 Uhr.' );
	}

	public function test_sommerzeit() {
		// 15.07.2026, 07:00 UTC = 09:00 Berlin (Sommerzeit).
		$now  = strtotime( '2026-07-16 10:00:00 UTC' );
		$rows = array( array( 'day' => '2026-07-15', 'hour' => 7, 'views' => 8 ) );

		$s = History::summarize( $rows, $now, 7, $this->berlin() );

		$this->assertSame( 8.0, $s['heat'][2][9], 'Mittwoch 9 Uhr Ortszeit.' );
		$this->assertSame( 9, $s['best_hour']['h'] );
	}

	public function test_durchschnitte_nur_abgeschlossene_tage() {
		$rows = array(
			// Zwei Montage mit 10 und 20 Aufrufen um 8 Uhr; heute (Mittwoch) 100 Aufrufe.
			array( 'day' => '2026-11-02', 'hour' => 7, 'views' => 10 ),
			array( 'day' => '2026-11-09', 'hour' => 7, 'views' => 20 ),
			array( 'day' => '2026-11-11', 'hour' => 7, 'views' => 100 ),
		);

		$s = History::summarize( $rows, $this->now(), 14, $this->berlin() );

		$this->assertSame( 130, $s['total'] );
		$this->assertSame( 100, $s['today'] );
		$this->assertSame( 9, $s['complete_days'], 'Vom 02.11. bis gestern (10.11.), heute nicht.' );
		$this->assertSame( 15.0, $s['weekdays'][0]['avg'], 'Montage: (10 + 20) / 2.' );
		$this->assertSame( 2, $s['weekdays'][0]['days'] );
		$this->assertSame( 0.0, $s['weekdays'][2]['avg'], 'Der heutige Mittwoch zählt nicht in den Durchschnitt.' );
		$this->assertSame( 3.3, $s['avg_day'], '30 Aufrufe an 9 Tagen.' );
		$this->assertSame( 1, $s['best_weekday']['w'] );
		$this->assertSame( 8, $s['best_hour']['h'] );
		$this->assertSame( array( 'date' => '2026-11-11', 'views' => 100 ), $s['best_day'] );
	}

	public function test_ohne_daten() {
		$s = History::summarize( array(), $this->now(), 30, $this->berlin() );

		$this->assertSame( 0, $s['total'] );
		$this->assertCount( 30, $s['daily'] );
		$this->assertSame( 0.0, $s['avg_day'] );
		$this->assertNull( $s['best_day'] );
		$this->assertNull( $s['best_weekday'] );
		$this->assertNull( $s['best_hour'] );
		$this->assertCount( 7, $s['heat'] );
		$this->assertCount( 24, $s['heat'][0] );
	}

	public function test_ausserhalb_des_zeitraums_zaehlt_nicht() {
		$rows = array( array( 'day' => '2026-09-01', 'hour' => 10, 'views' => 50 ) );

		$s = History::summarize( $rows, $this->now(), 7, $this->berlin() );

		$this->assertSame( 0, $s['total'] );
	}

	public function test_seite_und_name() {
		$items = Navigation::items();
		$this->assertSame( 'Besucher', $items['besucher']['title'] );

		$js = file_get_contents( dirname( __DIR__ ) . '/assets/erp/pages/besucher.js' );
		$this->assertStringContainsString( "ui.api('besucher/verlauf", $js );
		$this->assertStringContainsString( "ui.pageHead('Besucher'", $js );
		$this->assertStringNotContainsString( 'innerHTML', $js );

		$src = file_get_contents( dirname( __DIR__ ) . '/includes/Erp/History.php' );
		foreach ( array( 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'setcookie' ) as $word ) {
			$this->assertStringNotContainsString( $word, $src );
		}
	}
}
