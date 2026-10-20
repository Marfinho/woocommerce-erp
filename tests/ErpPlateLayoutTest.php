<?php
/**
 * Die Anordnung der Teile auf dem Bett steht in Plan-Vorschau und Plattenseite.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

class ErpPlateLayoutTest extends TestCase {

	protected function read( $rel ) {
		return file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_workflow_liefert_die_anordnung_mit() {
		$src = $this->read( 'includes/Erp/Workflow.php' );

		$this->assertStringContainsString( "'layout'      => BambuDruck::layout( \$plate )", $src );
		$this->assertStringContainsString( "'layout'      => \$layout", $src );
	}

	public function test_bambu_seite_und_workflow_teilen_die_berechnung() {
		$src = $this->read( 'includes/Erp/Pages/BambuDruck.php' );

		$this->assertStringContainsString( 'public static function layout( $plate )', $src );
		$this->assertStringContainsString( 'new WP_REST_Response( self::layout( $plate ) )', $src );
	}

	public function test_oberflaeche_zeigt_die_anordnung() {
		$js = $this->read( 'assets/erp/erp.js' );

		$this->assertStringContainsString( 'UI.plateView(layout)', $js );
		$this->assertStringContainsString( 'layoutBlock(run.layout', $js );
		$this->assertStringContainsString( 'layoutBlock(p.layout', $js );
		$this->assertStringContainsString( 'UI.plateView = function', $this->read( 'assets/erp/pages/bambu_druck.js' ) );
	}
}
