<?php
/**
 * Bambu-Druckaufträge, Platten und Slicing-Profile (ERP): reine Regeln.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\BambuDruck;
use Lindenweg16\Modules\Betrieb\Bambu\BambuJobRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuService;
use PHPUnit\Framework\TestCase;

class ErpBambuDruckTest extends TestCase {

	private function src( $path ) {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $path );
	}

	private function bbox( array $points ) {
		$xs = array_column( $points, 0 );
		$ys = array_column( $points, 1 );

		return array( min( $xs ), min( $ys ), max( $xs ), max( $ys ) );
	}

	public function test_status_gruppen_fuer_die_liste() {
		$this->assertSame( 'wartend', BambuDruck::job_group( 'queued' ) );
		foreach ( array( 'claimed', 'uploading', 'printing' ) as $status ) {
			$this->assertSame( 'laeuft', BambuDruck::job_group( $status ) );
		}
		$this->assertSame( 'fertig', BambuDruck::job_group( 'completed' ) );
		$this->assertSame( 'fehler', BambuDruck::job_group( 'failed' ) );
		$this->assertSame( 'storniert', BambuDruck::job_group( 'cancelled' ) );
		$this->assertSame( 'fehler', BambuDruck::job_group( 'unbekannt' ), 'Unbekanntes darf nicht unsichtbar werden' );
	}

	public function test_jeder_status_des_repositorys_hat_eine_gruppe_und_einen_text() {
		foreach ( BambuJobRepository::STATUSES as $status ) {
			$this->assertArrayHasKey( $status, BambuDruck::GROUPS );
			$this->assertNotSame( $status, BambuDruck::job_status_text( $status ) );
		}
	}

	public function test_abbrechen_nur_solange_die_bridge_den_auftrag_nicht_hat() {
		$this->assertTrue( BambuDruck::can_cancel( 'queued' ) );
		foreach ( array( 'claimed', 'uploading', 'printing', 'completed', 'failed', 'cancelled' ) as $status ) {
			$this->assertFalse( BambuDruck::can_cancel( $status ), $status );
		}
	}

	public function test_filter_und_zaehlung_nach_gruppe() {
		$jobs = array(
			array( 'id' => 1, 'group' => 'wartend' ),
			array( 'id' => 2, 'group' => 'laeuft' ),
			array( 'id' => 3, 'group' => 'fertig' ),
			array( 'id' => 4, 'group' => 'fertig' ),
			array( 'id' => 5, 'group' => 'fehler' ),
		);

		$this->assertCount( 5, BambuDruck::filter_by_group( $jobs, '' ) );
		$this->assertSame( array( 3, 4 ), array_column( BambuDruck::filter_by_group( $jobs, 'fertig' ), 'id' ) );
		$this->assertSame( array(), BambuDruck::filter_by_group( $jobs, 'storniert' ) );
		$this->assertSame( array( 'wartend' => 1, 'laeuft' => 1, 'fertig' => 2, 'fehler' => 1, 'storniert' => 0 ), BambuDruck::group_counts( $jobs ) );
	}

	public function test_ams_fach_leer_heisst_kein_ams_und_nicht_fach_null() {
		$this->assertNull( BambuDruck::clean_tray( '' ) );
		$this->assertNull( BambuDruck::clean_tray( null ) );
		$this->assertNull( BambuDruck::clean_tray( 'abc' ) );
		$this->assertNull( BambuDruck::clean_tray( '-3' ) );
		$this->assertSame( 0, BambuDruck::clean_tray( '0' ) );
		$this->assertSame( 0, BambuDruck::clean_tray( 0 ) );
		$this->assertSame( 5, BambuDruck::clean_tray( '5' ) );
		$this->assertSame( 255, BambuDruck::clean_tray( '9999' ) );
	}

	public function test_dateiendung_3mf_und_uploadgrenze() {
		$this->assertTrue( BambuDruck::is_3mf_name( 'Teil.gcode.3mf' ) );
		$this->assertTrue( BambuDruck::is_3mf_name( 'TEIL.3MF' ) );
		$this->assertFalse( BambuDruck::is_3mf_name( 'teil.stl' ) );
		$this->assertFalse( BambuDruck::is_3mf_name( '3mf' ) );

		$max = BambuService::MAX_FILE_BYTES;
		$this->assertSame( $max, BambuDruck::max_upload( 0 ) );
		$this->assertSame( 8388608, BambuDruck::max_upload( 8388608 ), 'Das Serverlimit gilt, wenn es kleiner ist' );
		$this->assertSame( $max, BambuDruck::max_upload( $max * 4 ) );
	}

	public function test_plattenmengen_werden_bereinigt_und_zusammengezaehlt() {
		$items = BambuDruck::plate_items(
			array(
				array( 'article_id' => '7', 'qty' => '3' ),
				array( 'article_id' => 7, 'qty' => 2 ),
				array( 'article_id' => 9, 'qty' => 0 ),
				array( 'article_id' => 0, 'qty' => 4 ),
				array( 'article_id' => 11, 'qty' => -1 ),
				array( 'article_id' => 12 ),
				'kaputt',
				array( 'article_id' => array( 1 ), 'qty' => 5 ),
			)
		);

		$this->assertSame( array( array( 'article_id' => 7, 'qty' => 5 ) ), $items );
		$this->assertSame( 5, BambuDruck::parts_total( $items ) );
		$this->assertSame( array(), BambuDruck::plate_items( 'x' ) );
		$this->assertSame( 0, BambuDruck::parts_total( array() ) );
	}

	public function test_sicherheitsabstand_mit_komma_vorgabe_und_obergrenze() {
		$this->assertSame( 5.0, BambuDruck::clean_margin( null ) );
		$this->assertSame( 5.0, BambuDruck::clean_margin( '' ) );
		$this->assertSame( 5.0, BambuDruck::clean_margin( 'viel' ) );
		$this->assertSame( 5.0, BambuDruck::clean_margin( '0' ) );
		$this->assertSame( 5.0, BambuDruck::clean_margin( '-2' ) );
		$this->assertSame( 3.5, BambuDruck::clean_margin( '3,5' ) );
		$this->assertSame( 8.0, BambuDruck::clean_margin( 8 ) );
		$this->assertSame( 50.0, BambuDruck::clean_margin( '500' ) );
	}

	public function test_nur_gedruckte_artikel_mit_ungeslicter_projektdatei_lassen_sich_anordnen() {
		$druck = array( 'sourcing_type' => 'druck' );

		$this->assertTrue( BambuDruck::is_nestable( $druck, array( 'model_file' => 'a.3mf', 'model_kind' => 'modell' ) ) );
		$this->assertFalse( BambuDruck::is_nestable( $druck, array( 'model_file' => 'a.3mf', 'model_kind' => 'druckdatei' ) ), 'Geslicte Datei hat keine Geometrie mehr' );
		$this->assertFalse( BambuDruck::is_nestable( $druck, array( 'model_file' => '', 'model_kind' => 'modell' ) ) );
		$this->assertFalse( BambuDruck::is_nestable( $druck, null ) );
		$this->assertFalse( BambuDruck::is_nestable( array( 'sourcing_type' => 'zukauf' ), array( 'model_file' => 'a.3mf', 'model_kind' => 'modell' ) ) );
	}

	public function test_hinweis_zum_letzten_slicing_lauf() {
		$this->assertNull( BambuDruck::slicing_note( 'fehlgeschlagen', null ) );
		$this->assertNull( BambuDruck::slicing_note( 'entworfen', array( 'status' => 'queued' ) ) );
		$this->assertNull( BambuDruck::slicing_note( 'geslict', array( 'status' => 'done' ) ) );

		$error = BambuDruck::slicing_note( 'fehlgeschlagen', array( 'error_message' => ' Kollision mit Sperrfläche ' ) );
		$this->assertSame( 'error', $error['kind'] );
		$this->assertSame( 'Kollision mit Sperrfläche', $error['text'] );

		$empty = BambuDruck::slicing_note( 'fehlgeschlagen', array( 'error_message' => '' ) );
		$this->assertSame( 'Kein Fehlergrund übermittelt.', $empty['text'] );

		$wait = BambuDruck::slicing_note( 'wird_geslict', array( 'status' => 'queued', 'created_ts' => 1000 ) );
		$this->assertSame( 'wait', $wait['kind'] );
		$this->assertSame( 1000, $wait['since'] );

		$work = BambuDruck::slicing_note( 'wird_geslict', array( 'status' => 'slicing', 'created_ts' => 1000, 'started_ts' => 1500 ) );
		$this->assertSame( 'work', $work['kind'] );
		$this->assertSame( 1500, $work['since'] );

		$fallback = BambuDruck::slicing_note( 'wird_geslict', array( 'status' => 'claimed', 'created_ts' => 1000, 'started_ts' => 0 ) );
		$this->assertSame( 1000, $fallback['since'] );
	}

	public function test_umriss_folgt_der_platzierung_des_packers() {
		// 10 x 20 mm Rechteck, beliebig im Raum liegend.
		$hull = array( array( 100, 100 ), array( 110, 100 ), array( 110, 120 ), array( 100, 120 ) );

		$box = $this->bbox( BambuDruck::outline( $hull, 5, 7, 0 ) );
		$this->assertEqualsWithDelta( 5, $box[0], 0.11 );
		$this->assertEqualsWithDelta( 7, $box[1], 0.11 );
		$this->assertEqualsWithDelta( 15, $box[2], 0.11 );
		$this->assertEqualsWithDelta( 27, $box[3], 0.11 );

		// Um 90 Grad gedreht: 20 breit, 10 hoch, Ecke bleibt bei (5, 7).
		$box = $this->bbox( BambuDruck::outline( $hull, 5, 7, 90 ) );
		$this->assertEqualsWithDelta( 5, $box[0], 0.11 );
		$this->assertEqualsWithDelta( 7, $box[1], 0.11 );
		$this->assertEqualsWithDelta( 25, $box[2], 0.11 );
		$this->assertEqualsWithDelta( 17, $box[3], 0.11 );
	}

	public function test_umriss_ohne_flaeche_ist_leer() {
		$this->assertSame( array(), BambuDruck::outline( array(), 0, 0, 0 ) );
		$this->assertSame( array(), BambuDruck::outline( array( array( 0, 0 ), array( 5, 5 ) ), 0, 0, 0 ) );
	}

	public function test_profilziel_wird_geprueft() {
		$materials = array( 'PETG', 'ASA', 'PLA', 'PETG-CF' );

		$ok = BambuDruck::profile_target( ' petg-cf ', '  Bambu   Lab P1S ', $materials );
		$this->assertSame( '', $ok['error'] );
		$this->assertSame( 'PETG-CF', $ok['material'] );
		$this->assertSame( 'Bambu Lab P1S', $ok['printer_model'] );

		$this->assertNotSame( '', BambuDruck::profile_target( '', 'P1S', $materials )['error'] );
		$this->assertNotSame( '', BambuDruck::profile_target( 'PLA', '  ', $materials )['error'] );
		$this->assertStringContainsString( 'PETG-CF', BambuDruck::profile_target( 'TPU', 'P1S', $materials )['error'] );
		$this->assertNotSame( '', BambuDruck::profile_target( 'PLA', str_repeat( 'x', 61 ), $materials )['error'] );
		$this->assertNotSame( '', BambuDruck::profile_target( array( 'PLA' ), 'P1S', $materials )['error'] );
	}

	public function test_profildatei_wird_vorab_geprueft() {
		$file = array( 'name' => 'p.json', 'error' => 0, 'size' => 1200 );

		$this->assertSame( '', BambuDruck::profile_file_error( 'Das Druckerprofil', $file, '{"a":1}' ) );
		$this->assertStringContainsString( 'fehlt', BambuDruck::profile_file_error( 'Das Druckerprofil', null, '' ) );
		$this->assertStringContainsString( 'fehlt', BambuDruck::profile_file_error( 'Das Druckerprofil', array( 'name' => '', 'error' => 0 ), '' ) );
		$this->assertStringContainsString( '.json', BambuDruck::profile_file_error( 'Das Druckerprofil', array( 'name' => 'p.txt', 'error' => 0, 'size' => 5 ), '{}' ) );
		$this->assertStringContainsString( 'gültige JSON', BambuDruck::profile_file_error( 'Das Druckerprofil', $file, '{kaputt' ) );
		$this->assertStringContainsString( 'gültige JSON', BambuDruck::profile_file_error( 'Das Druckerprofil', $file, '' ) );
		$this->assertStringContainsString( 'gültige JSON', BambuDruck::profile_file_error( 'Das Druckerprofil', $file, '"text"' ) );
		$this->assertStringContainsString( 'groß', BambuDruck::profile_file_error( 'Das Druckerprofil', array( 'name' => 'p.json', 'error' => 0, 'size' => BambuDruck::MAX_PROFILE_BYTES + 1 ), '{}' ) );
		$this->assertStringContainsString( 'Das Druckerprofil', BambuDruck::profile_file_error( 'Das Druckerprofil', array( 'name' => 'p.json', 'error' => 1, 'size' => 0 ), '' ) );
	}

	public function test_filament_id_aus_dem_profil() {
		$this->assertSame( 'GFA01', BambuDruck::filament_id_from_json( '{"filament_id":" GFA01 ","name":"x"}' ) );
		$this->assertSame( '', BambuDruck::filament_id_from_json( '{"name":"x"}' ) );
		$this->assertSame( '', BambuDruck::filament_id_from_json( '{"filament_id":["a"]}' ) );
		$this->assertSame( '', BambuDruck::filament_id_from_json( 'kein json' ) );
	}

	public function test_routen_haben_die_rechte_der_alten_seiten() {
		$php = $this->src( 'includes/Erp/Pages/BambuDruck.php' );

		$this->assertStringContainsString( "gate( array( 'fertigung.bambu' ), 'lw16_capture' )", $php, 'Aufträge: Erfassungsrecht wie BambuJobsPage' );
		$this->assertStringContainsString( "gate( array( 'fertigung.bambu' ), 'lw16_manage_production' )", $php, 'Platten: Produktionsrecht wie BambuPlatesPage' );
		$this->assertStringContainsString( "gate( array( 'fertigung.bambu', 'fertigung.stammdaten.edit' ), 'lw16_manage_production' )", $php, 'Profile ändern: wie ActionMap' );
		$this->assertStringContainsString( "'bambu/slicing/profil', 'POST', 'profile_save', \$edit", $php );
		$this->assertStringContainsString( "'bambu/slicing/profil/(?P<id>\\d+)/loeschen', 'POST', 'profile_delete', \$edit", $php );
		$this->assertStringContainsString( "'bambu/platten', 'POST', 'plate_build', \$plates", $php );
	}

	public function test_zugangsdaten_stehen_nirgends_in_den_antworten() {
		$this->assertStringNotContainsString( 'access_code', $this->src( 'includes/Erp/Pages/BambuDruck.php' ) );
		$this->assertStringNotContainsString( 'access_code', $this->src( 'assets/erp/pages/bambu_druck.js' ) );
	}

	public function test_oberflaeche_baut_keine_daten_per_innerhtml() {
		$js = $this->src( 'assets/erp/pages/bambu_druck.js' );

		$this->assertStringNotContainsString( 'innerHTML', $js );
		$this->assertStringNotContainsString( 'insertAdjacentHTML', $js );
		$this->assertStringContainsString( "UI.bambuTabs['auftraege']", $js );
		$this->assertStringContainsString( "UI.bambuTabs['platten']", $js );
		$this->assertStringContainsString( "UI.bambuTabs['profile']", $js );
	}

	public function test_bambu_seite_haengt_die_reiter_ein() {
		$js = $this->src( 'assets/erp/pages/produktion.js' );

		$this->assertStringContainsString( 'UI.bambuTabs', $js );
		foreach ( array( 'auftraege', 'platten', 'profile' ) as $tab ) {
			$this->assertStringContainsString( "'" . $tab . "'", $js );
		}
	}
}
