<?php
/**
 * Bambu-Lab-Anbindung: Claim-/Lock-Logik, Long-Poll-Grenzen,
 * Token-Hashing, Ist-Druckzeit-Berechnung.
 *
 * Dasselbe Prinzip wie PrintQueueTest: geprüft wird die netzfreie Hälfte,
 * die eigentliche Claim-Atomarität nur gegen eine echte MySQL-Instanz.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\Bambu\BambuJobRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuService;

/**
 * Prüft die netzfreie Hälfte der Bambu-Lab-Anbindung.
 */
class BambuTest extends TestCase {

	/* ----------------------------------------------------------------
	 * Lock / Lease
	 * -------------------------------------------------------------- */

	/**
	 * Ein Lock ohne Zeitstempel gilt als abgelaufen.
	 */
	public function test_lock_ohne_zeitstempel_ist_abgelaufen() {
		$this->assertTrue( BambuJobRepository::is_lock_expired( null, time() ) );
		$this->assertTrue( BambuJobRepository::is_lock_expired( '', time() ) );
	}

	/**
	 * Innerhalb der Lease-Zeit gilt der Lock als gültig.
	 */
	public function test_lock_innerhalb_der_frist_gilt() {
		$now       = time();
		$locked_at = gmdate( 'Y-m-d H:i:s', $now - 30 * 60 );

		$this->assertFalse( BambuJobRepository::is_lock_expired( $locked_at, $now, 180 ) );
	}

	/**
	 * Nach drei Stunden ohne Aktivität gilt der Job als verwaist – ein
	 * abgestürzter Bridge-Prozess darf einen mehrstündigen Druck nicht
	 * dauerhaft blockieren, aber auch nicht mitten im Druck als verwaist
	 * gelten (die Bridge erneuert das Lease bei jeder Fortschrittsmeldung).
	 */
	public function test_lock_nach_drei_stunden_ist_abgelaufen() {
		$now = time();

		$this->assertTrue( BambuJobRepository::is_lock_expired( gmdate( 'Y-m-d H:i:s', $now - 181 * 60 ), $now, 180 ) );
		$this->assertFalse( BambuJobRepository::is_lock_expired( gmdate( 'Y-m-d H:i:s', $now - 179 * 60 ), $now, 180 ) );
	}

	/* ----------------------------------------------------------------
	 * Long-Poll-Grenzen
	 * -------------------------------------------------------------- */

	/**
	 * Ohne Angabe gilt ein sinnvoller Standardwert.
	 */
	public function test_wait_ohne_angabe_ist_der_standardwert() {
		$this->assertSame( 25, BambuJobRepository::clamp_wait( '', 5, 30, 25 ) );
		$this->assertSame( 25, BambuJobRepository::clamp_wait( null, 5, 30, 25 ) );
		$this->assertSame( 25, BambuJobRepository::clamp_wait( 0, 5, 30, 25 ) );
	}

	/**
	 * Der Client darf keine beliebig lange Verbindung erzwingen.
	 */
	public function test_wait_wird_auf_min_und_max_begrenzt() {
		$this->assertSame( 5, BambuJobRepository::clamp_wait( 1, 5, 30 ) );
		$this->assertSame( 30, BambuJobRepository::clamp_wait( 999, 5, 30 ) );
	}

	/* ----------------------------------------------------------------
	 * Zustandsübergänge
	 * -------------------------------------------------------------- */

	/**
	 * Nur ein wartender Job darf geclaimt werden.
	 */
	public function test_nur_queued_darf_geclaimt_werden() {
		$this->assertTrue( BambuJobRepository::can_claim( 'queued' ) );
		foreach ( array( 'claimed', 'uploading', 'printing', 'completed', 'failed', 'cancelled' ) as $status ) {
			$this->assertFalse( BambuJobRepository::can_claim( $status ), $status );
		}
	}

	/**
	 * Der Upload darf nur nach dem Claim starten – oder wiederholt werden.
	 */
	public function test_upload_nur_nach_claim() {
		$this->assertTrue( BambuJobRepository::can_start_uploading( 'claimed' ) );
		$this->assertTrue( BambuJobRepository::can_start_uploading( 'uploading' ) );
		$this->assertFalse( BambuJobRepository::can_start_uploading( 'queued' ) );
		$this->assertFalse( BambuJobRepository::can_start_uploading( 'printing' ) );
	}

	/**
	 * complete() ist idempotent gedacht: completed ist als Ausgangsstatus
	 * ausdrücklich erlaubt.
	 */
	public function test_complete_ist_idempotent_erlaubt() {
		$this->assertTrue( BambuJobRepository::can_complete( 'claimed' ) );
		$this->assertTrue( BambuJobRepository::can_complete( 'uploading' ) );
		$this->assertTrue( BambuJobRepository::can_complete( 'printing' ) );
		$this->assertTrue( BambuJobRepository::can_complete( 'completed' ) );
		$this->assertFalse( BambuJobRepository::can_complete( 'queued' ) );
		$this->assertFalse( BambuJobRepository::can_complete( 'failed' ) );
	}

	/**
	 * Ein bereits fertig gedruckter Job darf nicht nachträglich als
	 * fehlgeschlagen markiert werden.
	 */
	public function test_ein_fertiger_druck_kann_nicht_mehr_fehlschlagen() {
		$this->assertTrue( BambuJobRepository::can_fail( 'claimed' ) );
		$this->assertTrue( BambuJobRepository::can_fail( 'uploading' ) );
		$this->assertTrue( BambuJobRepository::can_fail( 'printing' ) );
		$this->assertTrue( BambuJobRepository::can_fail( 'failed' ), 'failed->failed ist idempotent erlaubt.' );
		$this->assertFalse( BambuJobRepository::can_fail( 'completed' ) );
		$this->assertFalse( BambuJobRepository::can_fail( 'queued' ) );
	}

	/* ----------------------------------------------------------------
	 * Ist-Druckzeit
	 * -------------------------------------------------------------- */

	/**
	 * Ohne Start- oder Endzeitpunkt lässt sich keine Ist-Druckzeit bilden.
	 */
	public function test_ist_druckzeit_ohne_zeitstempel_ist_unbekannt() {
		$this->assertNull( BambuJobRepository::actual_minutes( null, gmdate( 'Y-m-d H:i:s' ) ) );
		$this->assertNull( BambuJobRepository::actual_minutes( gmdate( 'Y-m-d H:i:s' ), '' ) );
	}

	/**
	 * Ein Ende vor dem Start ist unplausibel und wird nicht als negative
	 * Zeit übernommen.
	 */
	public function test_ist_druckzeit_ende_vor_start_ist_unbekannt() {
		$now   = time();
		$start = gmdate( 'Y-m-d H:i:s', $now );
		$ende  = gmdate( 'Y-m-d H:i:s', $now - 60 );

		$this->assertNull( BambuJobRepository::actual_minutes( $start, $ende ) );
	}

	/**
	 * Normalfall: Differenz in Minuten, gerundet auf zwei Nachkommastellen.
	 */
	public function test_ist_druckzeit_wird_in_minuten_berechnet() {
		$now   = time();
		$start = gmdate( 'Y-m-d H:i:s', $now );
		$ende  = gmdate( 'Y-m-d H:i:s', $now + 2 * 3600 + 30 * 60 ); // 2:30 h später.

		$this->assertEqualsWithDelta( 150.0, BambuJobRepository::actual_minutes( $start, $ende ), 0.01 );
	}

	/* ----------------------------------------------------------------
	 * Token
	 * -------------------------------------------------------------- */

	/**
	 * Ein Token ist lang genug, um praktisch nicht erratbar zu sein.
	 */
	public function test_token_hat_ausreichend_entropie() {
		$token = BambuPrinterRepository::generate_token();

		$this->assertSame( 64, strlen( $token ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $token );
		$this->assertNotSame( $token, BambuPrinterRepository::generate_token() );
	}

	/**
	 * Nur das richtige Token passt zu seinem Hash.
	 */
	public function test_token_vergleich() {
		$token = BambuPrinterRepository::generate_token();
		$hash  = BambuPrinterRepository::hash_token( $token );

		$this->assertTrue( BambuPrinterRepository::token_matches( $token, $hash ) );
		$this->assertFalse( BambuPrinterRepository::token_matches( 'falsches-token', $hash ) );
		$this->assertFalse( BambuPrinterRepository::token_matches( '', $hash ) );
		$this->assertFalse( BambuPrinterRepository::token_matches( $token, '' ) );
	}

	/**
	 * Ein Token wird nie im Klartext gespeichert – der Hash unterscheidet
	 * sich vom Token selbst.
	 */
	public function test_gespeichert_wird_nur_der_hash() {
		$token = BambuPrinterRepository::generate_token();

		$this->assertNotSame( $token, BambuPrinterRepository::hash_token( $token ) );
		$this->assertSame( 64, strlen( BambuPrinterRepository::hash_token( $token ) ) );
	}

	/* ----------------------------------------------------------------
	 * Online-Status
	 * -------------------------------------------------------------- */

	/**
	 * Ohne jemals gesehenen Heartbeat gilt ein Drucker als offline.
	 */
	public function test_ohne_heartbeat_offline() {
		$this->assertFalse( BambuPrinterRepository::is_online( null ) );
		$this->assertFalse( BambuPrinterRepository::is_online( (object) array( 'last_seen_at' => null ) ) );
	}

	/**
	 * Ein frischer Heartbeat gilt als online, ein zu alter nicht mehr.
	 */
	public function test_heartbeat_schwelle() {
		$frisch = (object) array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) );
		$alt    = (object) array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s', time() - 300 ) );

		$this->assertTrue( BambuPrinterRepository::is_online( $frisch, 120 ) );
		$this->assertFalse( BambuPrinterRepository::is_online( $alt, 120 ) );
	}

	/* ----------------------------------------------------------------
	 * Welche Datei darf mit einem Job vergehen?
	 *
	 * Die Frage entscheidet über einen Löschvorgang: Ein Job zeigt
	 * entweder auf eine eigens hochgeladene Kopie (vergeht mit dem Job)
	 * oder auf eine Datei der Modellablage, die einer Artikelrevision
	 * gehört und bleiben muss.
	 * -------------------------------------------------------------- */

	/**
	 * Verzeichnisse und Dateien für die Löschprüfung anlegen.
	 *
	 * @return array {docs, modelle}
	 */
	private function dateien_anlegen() {
		$basis   = sys_get_temp_dir() . '/lw16-bambu-' . uniqid();
		$docs    = $basis . '/dokumente';
		$modelle = $basis . '/modelle';

		mkdir( $docs, 0777, true );
		mkdir( $modelle, 0777, true );

		file_put_contents( $docs . '/bambu-upload-1700000000-abcdef.3mf', 'x' );
		file_put_contents( $docs . '/rechnung-2024-001.pdf', 'x' );
		file_put_contents( $modelle . '/bambu-upload-tarnung.3mf', 'x' );
		file_put_contents( $modelle . '/haken-v3.gcode.3mf', 'x' );

		return array( 'basis' => $basis, 'docs' => $docs, 'modelle' => $modelle );
	}

	/**
	 * Eine hochgeladene Druckdatei im Dokumentenordner gehört der
	 * Warteschlange.
	 */
	public function test_eigener_upload_darf_geloescht_werden() {
		$d = $this->dateien_anlegen();

		$this->assertTrue(
			BambuService::is_queue_upload( $d['docs'] . '/bambu-upload-1700000000-abcdef.3mf', $d['docs'] )
		);
	}

	/**
	 * Eine Datei der Modellablage gehört der Artikelrevision – sie darf
	 * nicht mitgelöscht werden, auch wenn ihr Name den Upload-Präfix
	 * trägt.
	 */
	public function test_modellablage_bleibt_unangetastet() {
		$d = $this->dateien_anlegen();

		$this->assertFalse(
			BambuService::is_queue_upload( $d['modelle'] . '/haken-v3.gcode.3mf', $d['docs'] )
		);
		$this->assertFalse(
			BambuService::is_queue_upload( $d['modelle'] . '/bambu-upload-tarnung.3mf', $d['docs'] )
		);
	}

	/**
	 * Fremde Dateien im Dokumentenordner (Belege!) gehören nicht der
	 * Warteschlange.
	 */
	public function test_fremde_datei_im_dokumentenordner_bleibt() {
		$d = $this->dateien_anlegen();

		$this->assertFalse(
			BambuService::is_queue_upload( $d['docs'] . '/rechnung-2024-001.pdf', $d['docs'] )
		);
	}

	/**
	 * Ein Pfad, der über .. aus dem Dokumentenordner herausführt, zählt
	 * nicht als eigener Upload – realpath löst ihn vorher auf.
	 */
	public function test_pfad_ausbruch_wird_erkannt() {
		$d = $this->dateien_anlegen();

		$this->assertFalse(
			BambuService::is_queue_upload( $d['docs'] . '/../modelle/bambu-upload-tarnung.3mf', $d['docs'] )
		);
	}

	/**
	 * Leere Pfade und nicht vorhandene Dateien führen zu keinem
	 * Löschversuch.
	 */
	public function test_leerer_oder_unbekannter_pfad() {
		$d = $this->dateien_anlegen();

		$this->assertFalse( BambuService::is_queue_upload( '', $d['docs'] ) );
		$this->assertFalse( BambuService::is_queue_upload( $d['docs'] . '/gibt-es-nicht.3mf', $d['docs'] ) );
		$this->assertFalse( BambuService::is_queue_upload( $d['docs'], $d['docs'] ) );
	}
}
