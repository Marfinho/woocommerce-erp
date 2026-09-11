<?php
/**
 * Slicing-Warteschlange: Claim-/Lock-Logik, Long-Poll-Grenzen,
 * Zustandsübergänge und das Worker-Token.
 *
 * Dasselbe Prinzip wie PrintQueueTest/BambuTest: geprüft wird die
 * netzfreie Hälfte.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\Nesting\PlateJobRepository;
use Lindenweg16\Modules\Betrieb\Nesting\SlicingJobRepository;
use Lindenweg16\Modules\Betrieb\Nesting\SlicingWorkerAuth;

/**
 * Prüft die netzfreie Hälfte der Slicing-Warteschlange.
 */
class SlicingQueueTest extends TestCase {

	/**
	 * Testoptionen zurücksetzen, damit ein Test den nächsten nicht
	 * beeinflusst (get_option()/update_option() lesen in diesem
	 * Test-Stub aus $GLOBALS['lw16_test_options'], siehe bootstrap.php).
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['lw16_test_options'] = array();
	}

	/* ----------------------------------------------------------------
	 * Lock / Lease
	 * -------------------------------------------------------------- */

	/**
	 * Ein Lock ohne Zeitstempel gilt als abgelaufen.
	 */
	public function test_lock_ohne_zeitstempel_ist_abgelaufen() {
		$this->assertTrue( SlicingJobRepository::is_lock_expired( null, time() ) );
		$this->assertTrue( SlicingJobRepository::is_lock_expired( '', time() ) );
	}

	/**
	 * Innerhalb der Lease-Zeit gilt der Lock als gültig, danach als
	 * abgelaufen.
	 */
	public function test_lock_schwelle() {
		$now = time();
		$this->assertFalse( SlicingJobRepository::is_lock_expired( gmdate( 'Y-m-d H:i:s', $now - 10 * 60 ), $now, 30 ) );
		$this->assertTrue( SlicingJobRepository::is_lock_expired( gmdate( 'Y-m-d H:i:s', $now - 31 * 60 ), $now, 30 ) );
	}

	/* ----------------------------------------------------------------
	 * Long-Poll-Grenzen
	 * -------------------------------------------------------------- */

	/**
	 * Ohne Angabe gilt ein sinnvoller Standardwert, und die Grenzen
	 * werden durchgesetzt.
	 */
	public function test_wait_grenzen() {
		$this->assertSame( 25, SlicingJobRepository::clamp_wait( '', 5, 30, 25 ) );
		$this->assertSame( 5, SlicingJobRepository::clamp_wait( 1, 5, 30 ) );
		$this->assertSame( 30, SlicingJobRepository::clamp_wait( 999, 5, 30 ) );
	}

	/**
	 * wait=0 ist explizit "sofort antworten" fürs klassische Intervall-
	 * Polling - anders als "kein wait angegeben" oben, das den Long-Poll-
	 * Default ergibt. Siehe BambuTest für die ausführliche Begründung
	 * (ohne diese Unterscheidung blockiert jede Anfrage einen PHP-Worker
	 * mindestens $min Sekunden lang, auch im klassischen Poll-Modus).
	 */
	public function test_wait_null_bedeutet_sofort_antworten() {
		$this->assertSame( 0, SlicingJobRepository::clamp_wait( 0, 5, 30, 25 ) );
		$this->assertSame( 0, SlicingJobRepository::clamp_wait( '0', 5, 30, 25 ) );
		$this->assertSame( 25, SlicingJobRepository::clamp_wait( -5, 5, 30, 25 ) );
	}

	/* ----------------------------------------------------------------
	 * Zustandsübergänge
	 * -------------------------------------------------------------- */

	/**
	 * Nur ein wartender Auftrag darf geclaimt werden.
	 */
	public function test_nur_queued_darf_geclaimt_werden() {
		$this->assertTrue( SlicingJobRepository::can_claim( 'queued' ) );
		foreach ( array( 'claimed', 'slicing', 'completed', 'failed', 'cancelled' ) as $status ) {
			$this->assertFalse( SlicingJobRepository::can_claim( $status ), $status );
		}
	}

	/**
	 * complete() ist idempotent gedacht: completed ist als Ausgangsstatus
	 * ausdrücklich erlaubt.
	 */
	public function test_complete_ist_idempotent_erlaubt() {
		$this->assertTrue( SlicingJobRepository::can_complete( 'claimed' ) );
		$this->assertTrue( SlicingJobRepository::can_complete( 'slicing' ) );
		$this->assertTrue( SlicingJobRepository::can_complete( 'completed' ) );
		$this->assertFalse( SlicingJobRepository::can_complete( 'queued' ) );
		$this->assertFalse( SlicingJobRepository::can_complete( 'failed' ) );
	}

	/**
	 * Ein bereits abgeschlossener Slice-Lauf darf nicht nachträglich als
	 * fehlgeschlagen gelten.
	 */
	public function test_ein_abgeschlossener_lauf_kann_nicht_mehr_fehlschlagen() {
		$this->assertTrue( SlicingJobRepository::can_fail( 'claimed' ) );
		$this->assertTrue( SlicingJobRepository::can_fail( 'slicing' ) );
		$this->assertTrue( SlicingJobRepository::can_fail( 'failed' ), 'failed->failed ist idempotent erlaubt.' );
		$this->assertFalse( SlicingJobRepository::can_fail( 'completed' ) );
		$this->assertFalse( SlicingJobRepository::can_fail( 'queued' ) );
	}

	/**
	 * Eine Platte darf aus "entworfen" UND aus "fehlgeschlagen" (erneut)
	 * zum Slicen eingereiht werden - ein gescheiterter Lauf soll sich
	 * nach Behebung der Ursache per Klick wiederholen lassen. Aus "wird
	 * geslict" oder "geslict" dagegen nie (doppelter Auftrag bzw. schon
	 * in der Bambu-Warteschlange).
	 */
	public function test_platte_darf_aus_entworfen_und_fehlgeschlagen_eingereiht_werden() {
		$this->assertTrue( PlateJobRepository::can_enqueue_for_slicing( 'entworfen' ) );
		$this->assertTrue( PlateJobRepository::can_enqueue_for_slicing( 'fehlgeschlagen' ) );
		$this->assertFalse( PlateJobRepository::can_enqueue_for_slicing( 'wird_geslict' ) );
		$this->assertFalse( PlateJobRepository::can_enqueue_for_slicing( 'geslict' ) );
		$this->assertFalse( PlateJobRepository::can_enqueue_for_slicing( '' ) );
	}

	/**
	 * Offen ist ein Slicing-Auftrag, solange er wartet oder bearbeitet
	 * wird - nur dann blockiert er ein erneutes Einreihen derselben Platte.
	 */
	public function test_offene_auftraege() {
		foreach ( array( 'queued', 'claimed', 'slicing' ) as $status ) {
			$this->assertTrue( SlicingJobRepository::is_open( $status ), $status );
		}
		foreach ( array( 'completed', 'failed', 'cancelled', '' ) as $status ) {
			$this->assertFalse( SlicingJobRepository::is_open( $status ), $status );
		}
	}

	/* ----------------------------------------------------------------
	 * Worker-Token
	 * -------------------------------------------------------------- */

	/**
	 * Ein Token ist lang genug, um praktisch nicht erratbar zu sein.
	 */
	public function test_token_hat_ausreichend_entropie() {
		$token = SlicingWorkerAuth::generate_token();

		$this->assertSame( 64, strlen( $token ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $token );
		$this->assertNotSame( $token, SlicingWorkerAuth::generate_token() );
	}

	/**
	 * Nur das richtige Token passt zu seinem Hash.
	 */
	public function test_token_vergleich() {
		$token = SlicingWorkerAuth::generate_token();
		$hash  = SlicingWorkerAuth::hash_token( $token );

		$this->assertTrue( SlicingWorkerAuth::token_matches( $token, $hash ) );
		$this->assertFalse( SlicingWorkerAuth::token_matches( 'falsches-token', $hash ) );
		$this->assertFalse( SlicingWorkerAuth::token_matches( '', $hash ) );
		$this->assertFalse( SlicingWorkerAuth::token_matches( $token, '' ) );
	}

	/**
	 * Ohne gespeicherten Hash gilt kein Token als vergeben, und jede
	 * Prüfung schlägt fehl.
	 */
	public function test_ohne_gespeicherten_hash_gibt_es_kein_gueltiges_token() {
		$this->assertFalse( SlicingWorkerAuth::has_token() );
		$this->assertFalse( SlicingWorkerAuth::verify( 'irgendein-token' ) );
	}

	/**
	 * Mit einem (im Test-Stub direkt gesetzten) gespeicherten Hash prüft
	 * verify() korrekt gegen das passende bzw. ein falsches Token.
	 */
	public function test_verify_gegen_gespeicherten_hash() {
		$token = SlicingWorkerAuth::generate_token();
		$GLOBALS['lw16_test_options'][ \Lindenweg16\Core\Settings::OPTION ] = array(
			SlicingWorkerAuth::SETTING_HASH => SlicingWorkerAuth::hash_token( $token ),
		);

		$this->assertTrue( SlicingWorkerAuth::has_token() );
		$this->assertTrue( SlicingWorkerAuth::verify( $token ) );
		$this->assertFalse( SlicingWorkerAuth::verify( 'falsches-token' ) );
	}
}
