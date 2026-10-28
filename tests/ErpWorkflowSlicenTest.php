<?php
/**
 * Workflow: automatisch slicen aus der Plattenseite, Stand der
 * Warteschlange, Rolle und Lagerplatz beim Melden – und dass die alten,
 * nicht buchenden Druckwege weg sind.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Erp\RunFlow;

/**
 * Prüft RunFlow::queue_state() und die Verdrahtung im Quelltext.
 */
class ErpWorkflowSlicenTest extends TestCase {

	/**
	 * Datei lesen.
	 *
	 * @param string $path Pfad relativ zum Plugin.
	 * @return string
	 */
	protected function src( $path ) {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $path );
	}

	public function test_ungeslicte_platte_bietet_automatisches_slicen() {
		$q = RunFlow::queue_state( RunFlow::FREIGEGEBEN, 'entworfen', null, null, true );

		$this->assertSame( 'none', $q['state'] );
		$this->assertTrue( $q['can_slice'] );
		$this->assertFalse( RunFlow::queue_state( RunFlow::DRUCKT, 'entworfen', null, null, true )['can_slice'], 'Im Druck wird nicht mehr geslict.' );
	}

	public function test_slicen_laeuft() {
		$q = RunFlow::queue_state( RunFlow::RUESTEN, 'wird_geslict', array( 'kind' => 'work', 'text' => 'Der Worker slict gerade.' ), null, true );

		$this->assertSame( 'slicing', $q['state'] );
		$this->assertSame( 'Der Worker slict gerade.', $q['text'] );
		$this->assertFalse( $q['can_slice'], 'Kein zweiter Slicing-Auftrag, solange einer läuft.' );
	}

	public function test_fehlgeschlagenes_slicen_nennt_den_grund_und_laesst_wiederholen() {
		$q = RunFlow::queue_state( RunFlow::FREIGEGEBEN, 'fehlgeschlagen', array( 'kind' => 'error', 'text' => 'Kein Profil für PETG' ), null, true );

		$this->assertSame( 'slice_failed', $q['state'] );
		$this->assertSame( 'Kein Profil für PETG', $q['error'] );
		$this->assertTrue( $q['can_slice'] );
	}

	public function test_warteschlange_nennt_die_freigabe_der_platte() {
		$wartet = RunFlow::queue_state( RunFlow::FREIGEGEBEN, 'geslict', null, array( 'status' => 'queued' ), false );
		$frei   = RunFlow::queue_state( RunFlow::FREIGEGEBEN, 'geslict', null, array( 'status' => 'queued' ), true );

		$this->assertSame( 'queued', $wartet['state'] );
		$this->assertStringContainsString( 'als frei bestätigt', $wartet['text'] );
		$this->assertStringNotContainsString( 'als frei bestätigt', $frei['text'] );
	}

	public function test_druckauftrag_zustaende() {
		$this->assertSame( 'sending', RunFlow::queue_state( RunFlow::RUESTEN, 'geslict', null, array( 'status' => 'uploading' ), true )['state'] );
		$this->assertSame( 'printing', RunFlow::queue_state( RunFlow::DRUCKT, 'geslict', null, array( 'status' => 'printing' ), true )['state'] );
		$this->assertSame( 'done', RunFlow::queue_state( RunFlow::ZU_MELDEN, 'geslict', null, array( 'status' => 'completed' ), true )['state'] );

		$unklar = RunFlow::queue_state( RunFlow::DRUCKT, 'geslict', null, array( 'status' => 'unklar', 'error_message' => 'Bridge still' ), true );
		$this->assertSame( 'unclear', $unklar['state'] );
		$this->assertSame( 'Bridge still', $unklar['error'] );
	}

	public function test_gescheiterter_auftrag_laesst_sich_nur_vor_dem_druck_neu_einreihen() {
		$vorher = RunFlow::queue_state( RunFlow::RUESTEN, 'geslict', null, array( 'status' => 'failed', 'error_message' => 'Filament nicht im AMS' ), true );

		$this->assertSame( 'print_failed', $vorher['state'] );
		$this->assertSame( 'Filament nicht im AMS', $vorher['error'] );
		$this->assertTrue( $vorher['can_requeue'] );

		// Scheiterte ein laufender Druck, steht die Platte bei „Ist melden“:
		// Ausschuss melden, der Rest kommt als Bedarf zurück.
		$this->assertFalse( RunFlow::queue_state( RunFlow::ZU_MELDEN, 'geslict', null, array( 'status' => 'failed' ), true )['can_requeue'] );
		$this->assertTrue( RunFlow::queue_state( RunFlow::FREIGEGEBEN, 'geslict', null, array( 'status' => 'cancelled' ), true )['can_requeue'] );
	}

	public function test_plattenseite_kann_slicen_und_neu_einreihen() {
		$rest = $this->src( 'includes/Erp/RestApi.php' );
		$wf   = $this->src( 'includes/Erp/Workflow.php' );
		$js   = $this->src( 'assets/erp/erp.js' );

		$this->assertStringContainsString( '|platte-frei|slicen|erneut)', $rest );
		$this->assertStringContainsString( 'public static function slice( $id )', $wf );
		$this->assertStringContainsString( 'public static function requeue( $id )', $wf );
		$this->assertStringContainsString( "(bool) \$request->get_param( 'slice' )", $rest, 'Freigeben kann gleich slicen.' );
		$this->assertStringContainsString( "'queue'       => \$queue", $wf );
		$this->assertStringContainsString( 'function queueCard(q)', $js );
		$this->assertStringContainsString( 'slice: slc.checked', $js );
	}

	public function test_melden_mit_rolle_lagerplatz_und_notiz() {
		$wf = $this->src( 'includes/Erp/Workflow.php' );
		$js = $this->src( 'assets/erp/erp.js' );
		$pj = $this->src( 'includes/Modules/Betrieb/PrintJobRepository.php' );

		$this->assertStringContainsString( "\$input['rolls']", $wf );
		$this->assertStringContainsString( "\$input['bins']", $wf );
		$this->assertStringContainsString( 'PrintJobRepository::insert( $job, $bin_of[ $index ] )', $wf );
		$this->assertStringContainsString( 'FilamentRolls::start_use(', $wf, 'Gescannte Spule ist danach in Benutzung.' );
		$this->assertStringContainsString( 'public static function insert( array $data, $bin_id = 0 )', $pj );
		$this->assertStringContainsString( "'bin_id'        => (int) \$bin_id", $pj );
		$this->assertStringContainsString( 'rolls: rollPick, bins: binPick, note: noteField.value', $js );
		$this->assertStringContainsString( 'Rollen-Etikett scannen', $js );
	}

	public function test_alte_nicht_buchende_druckwege_sind_entfernt() {
		$this->assertFileDoesNotExist( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Admin/BambuPlatesPage.php' );
		$this->assertStringNotContainsString( 'dispatch_from_demand', $this->src( 'includes/Modules/Betrieb/Bambu/BambuService.php' ) );
		$this->assertStringNotContainsString( 'demand_bambu_print', $this->src( 'includes/Modules/Betrieb/Admin/DemandPage.php' ) );
		$this->assertStringNotContainsString( 'book_work_order_completion', $this->src( 'includes/Modules/Betrieb/Bambu/BambuJobRepository.php' ) );
		$this->assertStringNotContainsString( 'ProductionModule::complete_job', $this->src( 'includes/Modules/Betrieb/Bambu/BambuJobRepository.php' ) );
		$this->assertStringNotContainsString( "'platte'    =>", $this->src( 'includes/Modules/Betrieb/Admin/BambuPrintersPage.php' ) );
	}
}
