<?php
/**
 * Härtung der Bambu-Anbindung: Kein Druck fällt nach Lease-Ablauf zurück
 * in die Warteschlange, „unklar“ sperrt den Drucker, Platte-frei-Sperre.
 *
 * Wie BambuTest nur die netzfreie Hälfte; die bedingten UPDATEs selbst
 * brauchen eine echte MySQL-Instanz.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Erp\Pages\BambuDruck;
use Lindenweg16\Erp\RunFlow;
use Lindenweg16\Modules\Betrieb\Bambu\BambuJobRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository;

/**
 * Prüft die Zustandsregeln der gehärteten Anbindung.
 */
class BambuHaertungTest extends TestCase {

	/**
	 * Die Bridge startet jede Datei als „lw16-<Job>-<Name>“ – nur daran
	 * erkennt der Server den Job am Drucker, nicht an einem Präfix-Teil.
	 */
	public function test_datei_gehoert_zum_job_nur_mit_genauer_id() {
		$this->assertTrue( BambuJobRepository::file_belongs_to_job( 'lw16-7-Platte-a.3mf', 7 ) );
		$this->assertTrue( BambuJobRepository::file_belongs_to_job( '/sdcard/LW16-7-platte', 7 ) );
		$this->assertTrue( BambuJobRepository::file_belongs_to_job( 'C:\\x\\lw16-7-a.gcode.3mf', 7 ) );
		$this->assertFalse( BambuJobRepository::file_belongs_to_job( 'lw16-77-Platte-a.3mf', 7 ), 'Job 77 ist nicht Job 7.' );
		$this->assertFalse( BambuJobRepository::file_belongs_to_job( 'Platte-a.3mf', 7 ) );
		$this->assertFalse( BambuJobRepository::file_belongs_to_job( '', 7 ) );
		$this->assertFalse( BambuJobRepository::file_belongs_to_job( 'lw16-0-a.3mf', 0 ) );
	}

	/**
	 * Ein laufender Druck mit abgelaufenem Lease wird nie wieder
	 * ausgeliefert, sondern „unklar“ – sonst druckte er erneut auf die
	 * womöglich schon fertigen Teile.
	 */
	public function test_abgelaufener_druck_wird_unklar_statt_neu_eingereiht() {
		$this->assertSame( 'unklar', BambuJobRepository::stale_target( 'printing', '', 5 ) );
		$this->assertSame( 'unklar', BambuJobRepository::stale_target( 'printing', 'fremd.3mf', 5 ) );
	}

	/**
	 * Was den Drucker sicher nie erreicht hat, darf zurück in die
	 * Warteschlange – außer der Drucker meldet gerade genau diese Datei.
	 */
	public function test_nie_gestarteter_job_geht_zurueck_in_die_warteschlange() {
		$this->assertSame( 'queued', BambuJobRepository::stale_target( 'claimed', '', 5 ) );
		$this->assertSame( 'queued', BambuJobRepository::stale_target( 'uploading', 'lw16-4-alt.3mf', 5 ) );
		$this->assertSame( 'unklar', BambuJobRepository::stale_target( 'uploading', 'lw16-5-neu.3mf', 5 ) );
		$this->assertSame( 'unklar', BambuJobRepository::stale_target( 'claimed', 'lw16-5-neu', 5 ) );
	}

	/**
	 * Andere Status bleiben, wie sie sind.
	 */
	public function test_abgeschlossene_status_bleiben_unberuehrt() {
		foreach ( array( 'queued', 'completed', 'failed', 'cancelled', 'unklar' ) as $status ) {
			$this->assertSame( $status, BambuJobRepository::stale_target( $status, '', 5 ), $status );
		}
	}

	/**
	 * Ein unklarer Job lässt sich noch abschließen – die Bridge meldet das
	 * Ende womöglich erst nach langer Funkstille – und belegt den Drucker.
	 */
	public function test_unklar_laesst_sich_abschliessen_und_belegt_den_drucker() {
		$this->assertContains( 'unklar', BambuJobRepository::STATUSES );
		$this->assertContains( 'unklar', BambuJobRepository::ACTIVE_STATUSES );
		$this->assertTrue( BambuJobRepository::can_complete( 'unklar' ) );
		$this->assertTrue( BambuJobRepository::can_fail( 'unklar' ) );
		$this->assertTrue( BambuJobRepository::can_start_printing( 'unklar' ) );
		$this->assertFalse( BambuJobRepository::can_claim( 'unklar' ) );
	}

	/**
	 * Als unklar markieren geht nur, solange der Job den Drucker belegt.
	 */
	public function test_unklar_nur_aus_aktiven_status() {
		foreach ( array( 'claimed', 'uploading', 'printing', 'unklar' ) as $status ) {
			$this->assertTrue( BambuJobRepository::can_mark_unclear( $status ), $status );
		}
		foreach ( array( 'queued', 'completed', 'failed', 'cancelled' ) as $status ) {
			$this->assertFalse( BambuJobRepository::can_mark_unclear( $status ), $status );
		}
	}

	/**
	 * Von Hand klären lassen sich genau die Jobs, die den Drucker belegen.
	 */
	public function test_klaeren_nur_fuer_aktive_jobs() {
		$this->assertTrue( BambuDruck::can_resolve( 'unklar' ) );
		$this->assertTrue( BambuDruck::can_resolve( 'printing' ) );
		$this->assertFalse( BambuDruck::can_resolve( 'queued' ) );
		$this->assertFalse( BambuDruck::can_resolve( 'completed' ) );
		$this->assertSame( 'laeuft', BambuDruck::job_group( 'unklar' ) );
	}

	/**
	 * Jeder Druckzustand belegt die Platte, FINISH/IDLE nicht (sonst setzte
	 * jeder Heartbeat nach dem Druck die Freigabe sofort zurück).
	 */
	public function test_druckzustaende_belegen_die_platte() {
		foreach ( array( 'prepare', 'running', 'pause', 'slicing', 'RUNNING' ) as $status ) {
			$this->assertTrue( BambuPrinterRepository::is_printing_status( $status ), $status );
		}
		foreach ( array( 'finish', 'failed', 'idle', 'mqtt_getrennt', '' ) as $status ) {
			$this->assertFalse( BambuPrinterRepository::is_printing_status( $status ), $status );
		}
	}

	/**
	 * Ohne ausdrückliche Freigabe gilt die Platte als belegt.
	 */
	public function test_platte_gilt_ohne_freigabe_als_belegt() {
		$this->assertFalse( BambuPrinterRepository::bed_is_clear( null ) );
		$this->assertFalse( BambuPrinterRepository::bed_is_clear( (object) array( 'bed_clear' => 0 ) ) );
		$this->assertFalse( BambuPrinterRepository::bed_is_clear( (object) array() ) );
		$this->assertTrue( BambuPrinterRepository::bed_is_clear( (object) array( 'bed_clear' => '1' ) ) );
	}

	/**
	 * Werkstatt-Platten folgen ihrem Bambu-Auftrag (automatisch geslict),
	 * nicht dem Dateinamen – der heißt dann „lw16-<Job>-sliced-…“.
	 */
	public function test_platte_folgt_dem_status_ihres_auftrags() {
		$this->assertSame( array( 'druckstart' ), RunFlow::step_for_job( RunFlow::FREIGEGEBEN, 'printing' ) );
		$this->assertSame( array( 'druckstart' ), RunFlow::step_for_job( RunFlow::RUESTEN, 'printing' ) );
		$this->assertSame( array( 'druckende' ), RunFlow::step_for_job( RunFlow::DRUCKT, 'completed' ) );
		$this->assertSame( array( 'druckende' ), RunFlow::step_for_job( RunFlow::DRUCKT, 'failed' ) );
		$this->assertSame( array( 'druckstart', 'druckende' ), RunFlow::step_for_job( RunFlow::RUESTEN, 'completed' ) );
	}

	/**
	 * Was nichts über den Druck aussagt, bewegt die Platte nicht.
	 */
	public function test_platte_bleibt_ohne_aussagekraeftigen_auftragsstatus() {
		foreach ( array( 'queued', 'claimed', 'uploading', 'unklar', 'cancelled' ) as $status ) {
			$this->assertSame( array(), RunFlow::step_for_job( RunFlow::RUESTEN, $status ), $status );
			$this->assertSame( array(), RunFlow::step_for_job( RunFlow::DRUCKT, $status ), $status );
		}
		$this->assertSame( array(), RunFlow::step_for_job( RunFlow::RUESTEN, 'failed' ), 'Vor dem Druck gescheitert: Platte steht noch beim Rüsten.' );
		$this->assertSame( array(), RunFlow::step_for_job( RunFlow::ZU_MELDEN, 'printing' ) );
		$this->assertSame( array(), RunFlow::step_for_job( RunFlow::ERLEDIGT, 'completed' ) );
	}

	/**
	 * Erneut einreihen nur nach Fehlschlag oder Storno – ein fertiger Job
	 * würde doppelt drucken.
	 */
	public function test_erneut_einreihen_nur_nach_fehlschlag_oder_storno() {
		$this->assertTrue( BambuJobRepository::can_requeue( 'failed' ) );
		$this->assertTrue( BambuJobRepository::can_requeue( 'cancelled' ) );
		foreach ( array( 'queued', 'claimed', 'uploading', 'printing', 'unklar', 'completed' ) as $status ) {
			$this->assertFalse( BambuJobRepository::can_requeue( $status ), $status );
		}
	}
}
