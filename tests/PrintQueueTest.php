<?php
/**
 * Druck-Warteschlange: Claim-/Lock-Logik, Long-Poll-Grenzen,
 * Token-Hashing, Testlabel-PDF.
 *
 * Alles hier ist reine Logik ohne Datenbank – die eigentliche
 * Claim-Atomarität (das bedingte UPDATE) lässt sich nur gegen eine echte
 * MySQL-Instanz sinnvoll prüfen (siehe manueller Test in der
 * Raspberry-Pi-README). Was ohne Netz und ohne Datenbank geprüft werden
 * kann, ist hier geprüft – ein Fehler in der Zustandslogik soll aber
 * nicht erst bei zwei parallelen Raspberry Pis aliffallen.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\PrintQueue\PrintJobRepository;
use Lindenweg16\Modules\Betrieb\PrintQueue\PrinterRepository;
use Lindenweg16\Modules\Betrieb\PrintQueue\TestLabelPdf;

/**
 * Prüft die netzfreie Hälfte der Druck-Warteschlange.
 */
class PrintQueueTest extends TestCase {

	/* ----------------------------------------------------------------
	 * Lock / Lease
	 * -------------------------------------------------------------- */

	/**
	 * Ein Lock ohne Zeitstempel gilt als abgelaufen – so verhindert ein
	 * fehlerhafter Datensatz nicht dauerhaft die Freigabe.
	 */
	public function test_lock_ohne_zeitstempel_ist_abgelaufen() {
		$this->assertTrue( PrintJobRepository::is_lock_expired( null, time() ) );
		$this->assertTrue( PrintJobRepository::is_lock_expired( '', time() ) );
	}

	/**
	 * Innerhalb der Lease-Zeit gilt der Lock als gültig.
	 */
	public function test_lock_innerhalb_der_frist_gilt() {
		$now       = time();
		$locked_at = gmdate( 'Y-m-d H:i:s', $now - 5 * 60 );

		$this->assertFalse( PrintJobRepository::is_lock_expired( $locked_at, $now, 10 ) );
	}

	/**
	 * Nach zehn Minuten ohne Aktivität gilt der Job als verwaist – ein
	 * abgestürzter Raspberry Pi darf den Job nicht dauerhaft blockieren.
	 */
	public function test_lock_nach_zehn_minuten_ist_abgelaufen() {
		$now       = time();
		$locked_at = gmdate( 'Y-m-d H:i:s', $now - 11 * 60 );

		$this->assertTrue( PrintJobRepository::is_lock_expired( $locked_at, $now, 10 ) );

		// Exakt an der Grenze (9:59) noch gültig, an der Grenze +1s abgelaufen.
		$this->assertFalse( PrintJobRepository::is_lock_expired( gmdate( 'Y-m-d H:i:s', $now - 599 ), $now, 10 ) );
		$this->assertTrue( PrintJobRepository::is_lock_expired( gmdate( 'Y-m-d H:i:s', $now - 601 ), $now, 10 ) );
	}

	/* ----------------------------------------------------------------
	 * Long-Poll-Grenzen
	 * -------------------------------------------------------------- */

	/**
	 * Ohne Angabe gilt ein sinnvoller Standardwert.
	 */
	public function test_wait_ohne_angabe_ist_der_standardwert() {
		$this->assertSame( 25, PrintJobRepository::clamp_wait( '', 5, 30, 25 ) );
		$this->assertSame( 25, PrintJobRepository::clamp_wait( null, 5, 30, 25 ) );
		$this->assertSame( 25, PrintJobRepository::clamp_wait( 0, 5, 30, 25 ) );
		$this->assertSame( 25, PrintJobRepository::clamp_wait( -5, 5, 30, 25 ) );
	}

	/**
	 * Der Client darf keine beliebig lange Verbindung erzwingen.
	 */
	public function test_wait_wird_auf_min_und_max_begrenzt() {
		$this->assertSame( 5, PrintJobRepository::clamp_wait( 1, 5, 30 ) );
		$this->assertSame( 30, PrintJobRepository::clamp_wait( 999, 5, 30 ) );
		$this->assertSame( 20, PrintJobRepository::clamp_wait( 20, 5, 30 ) );
	}

	/* ----------------------------------------------------------------
	 * Zustandsübergänge
	 * -------------------------------------------------------------- */

	/**
	 * Nur ein wartender Job darf geclaimt werden.
	 */
	public function test_nur_queued_darf_geclaimt_werden() {
		$this->assertTrue( PrintJobRepository::can_claim( 'queued' ) );
		foreach ( array( 'claimed', 'printing', 'printed', 'failed', 'cancelled' ) as $status ) {
			$this->assertFalse( PrintJobRepository::can_claim( $status ), $status );
		}
	}

	/**
	 * complete() ist idempotent gedacht: printed ist als Ausgangsstatus
	 * ausdrücklich erlaubt (ein wiederholter Aufruf darf nicht scheitern).
	 */
	public function test_complete_ist_idempotent_erlaubt() {
		$this->assertTrue( PrintJobRepository::can_complete( 'claimed' ) );
		$this->assertTrue( PrintJobRepository::can_complete( 'printing' ) );
		$this->assertTrue( PrintJobRepository::can_complete( 'printed' ) );
		$this->assertFalse( PrintJobRepository::can_complete( 'queued' ) );
		$this->assertFalse( PrintJobRepository::can_complete( 'failed' ) );
	}

	/**
	 * Ein bereits gedruckter Job darf nicht nachträglich als
	 * fehlgeschlagen markiert werden – das Label hat den Drucker schon
	 * verlassen.
	 */
	public function test_ein_gedruckter_job_kann_nicht_mehr_fehlschlagen() {
		$this->assertTrue( PrintJobRepository::can_fail( 'claimed' ) );
		$this->assertTrue( PrintJobRepository::can_fail( 'printing' ) );
		$this->assertTrue( PrintJobRepository::can_fail( 'failed' ), 'failed->failed ist idempotent erlaubt.' );
		$this->assertFalse( PrintJobRepository::can_fail( 'printed' ) );
		$this->assertFalse( PrintJobRepository::can_fail( 'queued' ) );
	}

	/* ----------------------------------------------------------------
	 * Token
	 * -------------------------------------------------------------- */

	/**
	 * Ein Token ist lang genug, um praktisch nicht erratbar zu sein.
	 */
	public function test_token_hat_ausreichend_entropie() {
		$token = PrinterRepository::generate_token();

		$this->assertSame( 64, strlen( $token ), '256 Bit als Hex sind 64 Zeichen.' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $token );

		$this->assertNotSame( $token, PrinterRepository::generate_token(), 'Zwei Token dürfen nicht gleich sein.' );
	}

	/**
	 * Nur das richtige Token passt zu seinem Hash.
	 */
	public function test_token_vergleich() {
		$token = PrinterRepository::generate_token();
		$hash  = PrinterRepository::hash_token( $token );

		$this->assertTrue( PrinterRepository::token_matches( $token, $hash ) );
		$this->assertFalse( PrinterRepository::token_matches( 'falsches-token', $hash ) );
		$this->assertFalse( PrinterRepository::token_matches( '', $hash ) );
		$this->assertFalse( PrinterRepository::token_matches( $token, '' ) );
	}

	/**
	 * Ein Token wird nie im Klartext gespeichert – der Hash unterscheidet
	 * sich vom Token selbst.
	 */
	public function test_gespeichert_wird_nur_der_hash() {
		$token = PrinterRepository::generate_token();

		$this->assertNotSame( $token, PrinterRepository::hash_token( $token ) );
		$this->assertSame( 64, strlen( PrinterRepository::hash_token( $token ) ), 'sha256 als Hex.' );
	}

	/* ----------------------------------------------------------------
	 * Online-Status
	 * -------------------------------------------------------------- */

	/**
	 * Ohne jemals gesehenen Heartbeat gilt ein Drucker als offline.
	 */
	public function test_ohne_heartbeat_offline() {
		$this->assertFalse( PrinterRepository::is_online( null ) );
		$this->assertFalse( PrinterRepository::is_online( (object) array( 'last_seen_at' => null ) ) );
	}

	/**
	 * Ein frischer Heartbeat gilt als online, ein zu alter nicht mehr.
	 */
	public function test_heartbeat_schwelle() {
		$frisch = (object) array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) );
		$alt    = (object) array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s', time() - 200 ) );

		$this->assertTrue( PrinterRepository::is_online( $frisch, 90 ) );
		$this->assertFalse( PrinterRepository::is_online( $alt, 90 ) );
	}

	/* ----------------------------------------------------------------
	 * Testlabel-PDF
	 * -------------------------------------------------------------- */

	/**
	 * Die Seitengröße entspricht dem Thermolabel – keine A4-Anpassung.
	 */
	public function test_testlabel_hat_die_labelgroesse() {
		list( $w, $h ) = TestLabelPdf::page_points( 103, 199 );

		// 103 mm bzw. 199 mm in PDF-Punkten (1 mm = 72/25.4 pt).
		$this->assertEqualsWithDelta( 291.97, $w, 0.5 );
		$this->assertEqualsWithDelta( 564.09, $h, 0.5 );
	}

	/**
	 * Das erzeugte Dokument ist ein gültig eingeleitetes PDF und enthält
	 * die geforderten Hinweistexte – lesbar im Rohtext, ohne dass eine
	 * echte Sendung suggeriert wird.
	 */
	public function test_testlabel_pdf_enthaelt_die_hinweistexte() {
		$pdf = TestLabelPdf::build( array( 'HASTER HAKEN', 'TESTLABEL', 'KEINE VERSENDUNG' ) );

		$this->assertStringStartsWith( '%PDF-1.4', $pdf );
		$this->assertStringContainsString( '(HASTER HAKEN)', $pdf );
		$this->assertStringContainsString( '(TESTLABEL)', $pdf );
		$this->assertStringContainsString( '(KEINE VERSENDUNG)', $pdf );
		$this->assertStringContainsString( '%%EOF', $pdf );
	}

	/**
	 * Klammern im Text würden die PDF-Syntax brechen und müssen maskiert
	 * werden.
	 */
	public function test_testlabel_pdf_maskiert_sonderzeichen() {
		$pdf = TestLabelPdf::build( array( 'Test (mit) Klammern' ) );

		$this->assertStringContainsString( '\\(mit\\)', $pdf );
	}

	/**
	 * Bei einem quer beschrifteten Testlabel (z. B. DK-11201) wird der
	 * Inhalt gedreht (Rotationsmatrix in der PDF-Syntax), nicht die
	 * Seite - sonst kommt der Text um 90 Grad verdreht aus dem Drucker.
	 */
	public function test_testlabel_quer_dreht_den_inhalt_nicht_die_seite() {
		$hochkant = TestLabelPdf::build( array( 'X' ), 29, 90, false );
		$quer     = TestLabelPdf::build( array( 'X' ), 29, 90, true );

		$this->assertStringNotContainsString( ' cm', $hochkant );
		$this->assertStringContainsString( ' cm', $quer, 'Die Rotationsmatrix (cm-Operator) muss vorhanden sein.' );

		// Beide MediaBoxen bleiben exakt gleich gross - nur der Inhalt
		// dreht sich, nicht das physische Etikettenmass.
		list( $w, $h ) = TestLabelPdf::page_points( 29, 90 );
		$mediabox = sprintf( '/MediaBox [0 0 %s %s]', rtrim( rtrim( number_format( $w, 2, '.', '' ), '0' ), '.' ), rtrim( rtrim( number_format( $h, 2, '.', '' ), '0' ), '.' ) );
		$this->assertStringContainsString( $mediabox, $hochkant );
		$this->assertStringContainsString( $mediabox, $quer );
	}
}
