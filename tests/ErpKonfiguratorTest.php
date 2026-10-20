<?php
/**
 * Konfigurator im ERP: reine Regeln (Modellwahl, Filter, Sperrbereich-Voraussetzung).
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Konfigurator;
use PHPUnit\Framework\TestCase;

class ErpKonfiguratorTest extends TestCase {

	private function rows() {
		return array(
			array( 'name' => 'Schiene 600', 'sku' => 'HK-S600', 'teaser' => 'Die Wandschiene', 'role' => 'basis', 'is_active' => 1, 'article_active' => true, 'model_kind' => 'demo' ),
			array( 'name' => 'Haken groß', 'sku' => 'HK-H80', 'teaser' => '', 'role' => 'modul', 'is_active' => 1, 'article_active' => true, 'model_kind' => 'none' ),
			array( 'name' => 'Ablage Äußerst', 'sku' => 'HK-A150', 'teaser' => 'Für Schlüssel', 'role' => 'modul', 'is_active' => 0, 'article_active' => true, 'model_kind' => 'upload' ),
			array( 'name' => 'Alter Haken', 'sku' => 'HK-OLD', 'teaser' => '', 'role' => 'modul', 'is_active' => 1, 'article_active' => false, 'model_kind' => 'none' ),
		);
	}

	private function skus( array $rows ) {
		return array_map(
			static function ( $r ) {
				return $r['sku'];
			},
			$rows
		);
	}

	public function test_model_plan_upload_sticht_auswahl() {
		$a = Konfigurator::model_plan( 'keines', true );
		$b = Konfigurator::model_plan( '', true );
		$this->assertSame( 'upload', $a['action'] );
		$this->assertSame( 'upload', $b['action'] );
	}

	public function test_model_plan_ohne_datei() {
		$none    = Konfigurator::model_plan( 'keines', false );
		$keep    = Konfigurator::model_plan( '', false );
		$unknown = Konfigurator::model_plan( 'demo:gibtesnicht', false );
		$demo    = Konfigurator::model_plan( 'demo:haken', false );

		$this->assertSame( 'none', $none['action'] );
		$this->assertSame( 'keep', $keep['action'] );
		$this->assertSame( 'keep', $unknown['action'] );
		$this->assertSame( 'demo', $demo['action'] );
		$this->assertSame( 'demo:haken', $demo['value'] );
	}

	public function test_model_kind() {
		$this->assertSame( 'none', Konfigurator::model_kind( '' ) );
		$this->assertSame( 'demo', Konfigurator::model_kind( 'demo:ablage' ) );
		$this->assertSame( 'upload', Konfigurator::model_kind( 'schiene-ab12cd.glb' ) );
		$this->assertSame( 'upload', Konfigurator::model_kind( 'demo:unbekannt' ) );
	}

	public function test_flag() {
		$this->assertSame( 1, Konfigurator::flag( '1' ) );
		$this->assertSame( 1, Konfigurator::flag( 'on' ) );
		$this->assertSame( 1, Konfigurator::flag( true ) );
		$this->assertSame( 1, Konfigurator::flag( 1 ) );
		$this->assertSame( 0, Konfigurator::flag( '0' ) );
		$this->assertSame( 0, Konfigurator::flag( '' ) );
		$this->assertSame( 0, Konfigurator::flag( null ) );
		$this->assertSame( 0, Konfigurator::flag( 'false' ) );
	}

	public function test_filter_ohne_kriterien_liefert_alles() {
		$this->assertCount( 4, Konfigurator::filter_parts( $this->rows(), '', '', '' ) );
	}

	public function test_filter_suche_name_sku_teaser_ohne_gross_klein() {
		$this->assertSame( array( 'HK-S600' ), $this->skus( Konfigurator::filter_parts( $this->rows(), 'schiene', '', '' ) ) );
		$this->assertSame( array( 'HK-H80' ), $this->skus( Konfigurator::filter_parts( $this->rows(), 'hk-h80', '', '' ) ) );
		$this->assertSame( array( 'HK-A150' ), $this->skus( Konfigurator::filter_parts( $this->rows(), 'schlüssel', '', '' ) ) );
		$this->assertSame( array( 'HK-A150' ), $this->skus( Konfigurator::filter_parts( $this->rows(), 'äußerst', '', '' ) ) );
		$this->assertSame( array(), Konfigurator::filter_parts( $this->rows(), 'gibtesnicht', '', '' ) );
	}

	public function test_filter_rolle() {
		$this->assertSame( array( 'HK-S600' ), $this->skus( Konfigurator::filter_parts( $this->rows(), '', 'basis', '' ) ) );
		$this->assertCount( 3, Konfigurator::filter_parts( $this->rows(), '', 'modul', '' ) );
		$this->assertCount( 4, Konfigurator::filter_parts( $this->rows(), '', 'unsinn', '' ) );
	}

	public function test_filter_zustand() {
		$this->assertSame( array( 'HK-S600', 'HK-H80' ), $this->skus( Konfigurator::filter_parts( $this->rows(), '', '', 'aktiv' ) ) );
		$this->assertSame( array( 'HK-A150', 'HK-OLD' ), $this->skus( Konfigurator::filter_parts( $this->rows(), '', '', 'inaktiv' ) ) );
		$this->assertSame( array( 'HK-H80', 'HK-OLD' ), $this->skus( Konfigurator::filter_parts( $this->rows(), '', '', 'ohne-modell' ) ) );
	}

	public function test_filter_kombiniert() {
		$this->assertSame( array( 'HK-OLD' ), $this->skus( Konfigurator::filter_parts( $this->rows(), 'haken', 'modul', 'inaktiv' ) ) );
	}

	public function test_block_error() {
		$this->assertNotSame( '', Konfigurator::block_error( null ) );
		$this->assertSame( 'Sperrbereiche gibt es nur am Basismodul.', Konfigurator::block_error( array( 'role' => 'modul' ) ) );
		$this->assertSame( '', Konfigurator::block_error( array( 'role' => 'basis' ) ) );
	}
}
