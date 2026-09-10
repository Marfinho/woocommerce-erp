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
use Lindenweg16\Modules\Betrieb\Bambu\BedSizeLookup;

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
	}

	/**
	 * Der Client darf keine beliebig lange Verbindung erzwingen.
	 */
	public function test_wait_wird_auf_min_und_max_begrenzt() {
		$this->assertSame( 5, BambuJobRepository::clamp_wait( 1, 5, 30 ) );
		$this->assertSame( 30, BambuJobRepository::clamp_wait( 999, 5, 30 ) );
	}

	/**
	 * wait=0 ist explizit "sofort antworten" fürs klassische
	 * Intervall-Polling (siehe BambuRestApi::get_jobs()) - anders als
	 * "kein wait angegeben" oben, das den Long-Poll-Default ergibt. Ohne
	 * diese Unterscheidung würde jede Anfrage einen PHP-Worker samt
	 * Datenbankverbindung mindestens `$min` Sekunden lang blockieren, auch
	 * im klassischen Poll-Modus - das kann auf Shared-Hosting mit wenigen
	 * PHP-FPM-Workern schon durch einen einzelnen Drucker die ganze
	 * Website spürbar verlangsamen.
	 */
	public function test_wait_null_bedeutet_sofort_antworten() {
		$this->assertSame( 0, BambuJobRepository::clamp_wait( 0, 5, 30, 25 ) );
		$this->assertSame( 0, BambuJobRepository::clamp_wait( '0', 5, 30, 25 ) );
		$this->assertSame( 25, BambuJobRepository::clamp_wait( -5, 5, 30, 25 ) );
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

	/* ----------------------------------------------------------------
	 * Druckbett-Nachschlagetabelle
	 * -------------------------------------------------------------- */

	/**
	 * Ein bekanntes Modell wird per Teilstring gefunden, unabhängig davon,
	 * ob "Bambu Lab" davorsteht oder nicht.
	 */
	public function test_bed_size_lookup_findet_bekanntes_modell() {
		$this->assertSame( array( 'width_mm' => 256, 'height_mm' => 256 ), BedSizeLookup::match( 'P1S' ) );
		$this->assertSame( array( 'width_mm' => 256, 'height_mm' => 256 ), BedSizeLookup::match( 'Bambu Lab P1S' ) );
	}

	/**
	 * "A1 mini" darf nicht fälschlich schon auf den kürzeren Schlüssel
	 * "A1" treffen – beide Drucker haben unterschiedliche Bettmasse.
	 */
	public function test_bed_size_lookup_unterscheidet_a1_und_a1_mini() {
		$this->assertSame( array( 'width_mm' => 180, 'height_mm' => 180 ), BedSizeLookup::match( 'Bambu Lab A1 mini' ) );
		$this->assertSame( array( 'width_mm' => 256, 'height_mm' => 256 ), BedSizeLookup::match( 'Bambu Lab A1' ) );
	}

	/**
	 * Ein unbekanntes oder leeres Modell liefert null statt eines Ratewerts.
	 */
	public function test_bed_size_lookup_unbekanntes_modell_ist_null() {
		$this->assertNull( BedSizeLookup::match( 'Prusa MK4' ) );
		$this->assertNull( BedSizeLookup::match( '' ) );
	}

	/* ----------------------------------------------------------------
	 * Wirksames Druckbettmass
	 * -------------------------------------------------------------- */

	/**
	 * Ein eigens hinterlegtes Mass hat Vorrang vor der Schätzung nach
	 * Druckermodell.
	 */
	public function test_effective_bed_size_bevorzugt_eigenes_mass() {
		$printer = (object) array( 'bed_width_mm' => 300, 'bed_height_mm' => 300, 'printer_model' => 'P1S' );
		$this->assertSame( array( 'width_mm' => 300, 'height_mm' => 300 ), BambuPrinterRepository::effective_bed_size( $printer ) );
	}

	/**
	 * Ohne eigenes Mass greift die Schätzung nach dem gemeldeten Modell.
	 */
	public function test_effective_bed_size_faellt_auf_modell_zurueck() {
		$printer = (object) array( 'bed_width_mm' => null, 'bed_height_mm' => null, 'printer_model' => 'Bambu Lab X1C' );
		$this->assertSame( array( 'width_mm' => 256, 'height_mm' => 256 ), BambuPrinterRepository::effective_bed_size( $printer ) );
	}

	/**
	 * Ohne Drucker, ohne Mass und ohne erkennbares Modell gibt es kein
	 * Ergebnis – kein geratenes Mass, das zu Fehlplatzierungen führen könnte.
	 */
	public function test_effective_bed_size_ohne_angaben_ist_null() {
		$this->assertNull( BambuPrinterRepository::effective_bed_size( null ) );
		$this->assertNull( BambuPrinterRepository::effective_bed_size( (object) array( 'bed_width_mm' => null, 'bed_height_mm' => null, 'printer_model' => '' ) ) );
	}

	/* ----------------------------------------------------------------
	 * AMS-Belegung
	 * -------------------------------------------------------------- */

	/**
	 * Gültiges JSON wird unverändert (bis zur Längenbegrenzung) übernommen.
	 */
	public function test_ams_json_gueltig_wird_uebernommen() {
		$json = '[{"id":0,"humidity":51,"temp":25.6,"trays":[{"id":0,"type":"PLA","color":"FFFFFFFF","remain":92}]}]';
		$this->assertSame( $json, BambuPrinterRepository::normalize_ams_json( $json ) );
	}

	/**
	 * Leerer Wert und kaputtes JSON dürfen nicht in die Datenbank
	 * durchrutschen - ein Fehler hier soll beim Anzeigen nicht erneut
	 * geparst und dabei unbemerkt verschluckt werden.
	 */
	public function test_ams_json_leer_oder_kaputt_wird_null() {
		$this->assertNull( BambuPrinterRepository::normalize_ams_json( '' ) );
		$this->assertNull( BambuPrinterRepository::normalize_ams_json( '{kaputt' ) );
		$this->assertNull( BambuPrinterRepository::normalize_ams_json( 'nicht mal json' ) );
	}

	/**
	 * Eine unerwartet riesige Nutzlast wird gekappt, statt unbegrenzt in
	 * der Datenbank zu landen.
	 */
	public function test_ams_json_wird_der_laenge_nach_begrenzt() {
		$riesig = '[' . str_repeat( '1', 25000 ) . ']';
		$ergebnis = BambuPrinterRepository::normalize_ams_json( $riesig );
		$this->assertSame( 20000, strlen( $ergebnis ) );
	}

	/**
	 * ams_units() entpackt das gespeicherte JSON wieder zur Anzeige -
	 * kaputtes/fehlendes JSON liefert eine leere Liste statt eines Fehlers.
	 */
	public function test_ams_units_entpackt_gespeichertes_json() {
		$printer = (object) array( 'ams_json' => '[{"id":0,"trays":[{"type":"PLA"}]}]' );
		$einheiten = BambuPrinterRepository::ams_units( $printer );

		$this->assertCount( 1, $einheiten );
		$this->assertSame( 'PLA', $einheiten[0]['trays'][0]['type'] );

		$this->assertSame( array(), BambuPrinterRepository::ams_units( (object) array( 'ams_json' => null ) ) );
		$this->assertSame( array(), BambuPrinterRepository::ams_units( (object) array( 'ams_json' => 'kaputt' ) ) );
	}

	/**
	 * ams_tray_options() bildet die "globale" Fach-Nummer (AMS-Einheit * 4 +
	 * Fach-Index) - dieselbe Zählweise, die die Firmware in ams_mapping
	 * erwartet - und lässt leere Fächer aus der Auswahl weg.
	 */
	public function test_ams_tray_options_globale_nummerierung() {
		$printer = (object) array(
			'ams_json' => json_encode(
				array(
					array(
						'id'    => 0,
						'trays' => array(
							array( 'id' => 0, 'type' => 'PLA', 'color' => 'FF0000FF', 'remain' => 80 ),
							array( 'id' => 1, 'type' => '', 'color' => '', 'remain' => null ), // leeres Fach
						),
					),
					array(
						'id'    => 1,
						'trays' => array(
							array( 'id' => 0, 'type' => 'PETG', 'color' => '00FF00FF', 'remain' => 45 ),
						),
					),
				)
			),
		);

		$optionen = BambuPrinterRepository::ams_tray_options( $printer );

		$this->assertCount( 2, $optionen );
		$this->assertSame( 0, $optionen[0]['value'] ); // Einheit 0, Fach 0 -> 0*4+0
		$this->assertSame( 4, $optionen[1]['value'] ); // Einheit 1, Fach 0 -> 1*4+0
		$this->assertStringContainsString( 'PLA', $optionen[0]['label'] );
		$this->assertStringContainsString( 'PETG', $optionen[1]['label'] );
	}

	/**
	 * Ohne AMS-Daten (oder nur leere Fächer) gibt es nichts zur Auswahl.
	 */
	public function test_ams_tray_options_ohne_daten_ist_leer() {
		$this->assertSame( array(), BambuPrinterRepository::ams_tray_options( (object) array( 'ams_json' => null ) ) );
	}
}
