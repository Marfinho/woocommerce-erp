<?php
/**
 * Montage im Workflow: Zustand der Teile, Bereitschaft und die Einhängepunkte.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Erp\Assembly;

class ErpAssemblyTest extends TestCase {

	protected function read( $rel ) {
		return file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_teil_ist_da_wenn_der_bestand_reicht() {
		$this->assertSame( 'da', Assembly::part_state( 10, 10 ) );
		$this->assertSame( 'da', Assembly::part_state( 10, 12.5 ) );
	}

	public function test_teil_ist_im_druck_wenn_bestand_und_laufende_menge_reichen() {
		$this->assertSame( 'druck', Assembly::part_state( 10, 4, 6 ) );
		$this->assertSame( 'druck', Assembly::part_state( 10, 0, 10 ) );
	}

	public function test_teil_fehlt_wenn_auch_das_laufende_nicht_reicht() {
		$this->assertSame( 'fehlt', Assembly::part_state( 10, 4, 5 ) );
		$this->assertSame( 'fehlt', Assembly::part_state( 3, 0 ) );
	}

	public function test_bereit_nur_wenn_alle_teile_da_sind() {
		$this->assertTrue( Assembly::ready( array( array( 'state' => 'da' ), array( 'state' => 'da' ) ) ) );
		$this->assertFalse( Assembly::ready( array( array( 'state' => 'da' ), array( 'state' => 'druck' ) ) ) );
		$this->assertFalse( Assembly::ready( array( array( 'state' => 'fehlt' ) ) ) );
		$this->assertFalse( Assembly::ready( array() ), 'Ohne Stückliste gibt es nichts zu montieren.' );
	}

	public function test_fehlendes_teil_wird_mit_menge_und_stand_benannt() {
		$text = Assembly::missing_text( array( 'label' => 'HZ-BSM', 'name' => 'Modul BSM', 'need' => 6.0, 'have' => 2.0, 'state' => 'druck' ) );

		$this->assertStringContainsString( '6× HZ-BSM Modul BSM', $text );
		$this->assertStringContainsString( 'im Lager 2', $text );
		$this->assertStringContainsString( 'wird gerade gedruckt', $text );
	}

	public function test_workflow_liefert_die_montage_karten_und_das_brett() {
		$this->assertStringContainsString( "'assembly' => Assembly::cards( \$plan, \$ctx, \$labels )", $this->read( 'includes/Erp/Workflow.php' ) );
		$this->assertStringContainsString( "'assembly' => Assembly::board()", $this->read( 'includes/Erp/RestApi.php' ) );
	}

	public function test_routen_sind_an_die_workflow_rechte_gebunden() {
		$src = $this->read( 'includes/Erp/RestApi.php' );

		$this->assertMatchesRegularExpression( "~'/erp/workflow/montage',\s+array\(\s+'methods'\s+=> 'POST',\s+'callback'\s+=> array\( \\\$this, 'assembly_create' \),\s+'permission_callback' => array\( \\\$this, 'can_plan' \)~", $src );
		$this->assertMatchesRegularExpression( "~'/erp/montage/\(\?P<id>\\\\d\+\)',\s+array\(\s+'methods'\s+=> 'GET',\s+'callback'\s+=> array\( \\\$this, 'assembly_get' \),\s+'permission_callback' => array\( \\\$this, 'can_workflow' \)~", $src );
		$this->assertStringContainsString( "'abbrechen' === \$action && ! Access::can( 'fertigung.workflow.plan' )", $src );
	}

	public function test_oberflaeche_haengt_die_spur_in_den_workflow() {
		$js = $this->read( 'assets/erp/erp.js' );

		$this->assertStringContainsString( 'UI.assemblyLane(asmBox', $js );
		$this->assertStringContainsString( "UI.routes['montage']", $this->read( 'assets/erp/pages/montage.js' ) );
	}

	public function test_dein_naechstes_kennt_die_montage() {
		$this->assertSame( 3, \Lindenweg16\Erp\Tasks::ranks()['montage'] );
		$this->assertStringContainsString( "'href'  => '#/montage/'", $this->read( 'includes/Erp/Tasks.php' ) );
	}
}
