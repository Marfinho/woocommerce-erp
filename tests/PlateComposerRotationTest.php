<?php
/**
 * PlateComposer: Eine gedrehte Platzierung liegt mit der Ecke ihres GEDREHTEN
 * Begrenzungsrechtecks auf {x, y} – so wie BinPacker sie berechnet.
 *
 * Vorher drehte die Matrix um die Ecke des ungedrehten Teils; bei 90° lag es
 * dann um seine Höhe links der Zielposition (halb neben dem Bett oder auf dem
 * Nachbarn).
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\Nesting\PlateComposer;

class PlateComposerRotationTest extends TestCase {

	protected function write_cuboid( $path, $w, $d, $ox, $oy ) {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">'
			. '<resources><object id="1" type="model"><mesh><vertices>'
			. "<vertex x=\"$ox\" y=\"$oy\" z=\"0\"/><vertex x=\"" . ( $ox + $w ) . "\" y=\"$oy\" z=\"0\"/>"
			. '<vertex x="' . ( $ox + $w ) . '" y="' . ( $oy + $d ) . "\" z=\"0\"/><vertex x=\"$ox\" y=\"" . ( $oy + $d ) . '" z="0"/>'
			. '</vertices><triangles><triangle v1="0" v2="1" v3="2"/><triangle v1="0" v2="2" v3="3"/></triangles>'
			. '</mesh></object></resources><build><item objectid="1"/></build></model>';

		$zip = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml"/></Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rel0" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel" Target="/3D/3dmodel.model"/></Relationships>' );
		$zip->addFromString( '3D/3dmodel.model', $xml );
		$zip->close();
	}

	public function angles() {
		return array( array( 0 ), array( 90 ), array( 180 ), array( 270 ), array( 45 ), array( 30 ) );
	}

	/**
	 * @dataProvider angles
	 */
	public function test_gedrehtes_teil_liegt_mit_der_gedrehten_ecke_auf_der_zielposition( $deg ) {
		$dir = sys_get_temp_dir() . '/lw16-rot-test-' . getmypid();
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		// Fremder Nullpunkt, damit beides zusammen geprüft ist.
		$this->write_cuboid( $dir . '/q.3mf', 47, 36, 100, 50 );

		$out    = $dir . '/platte.3mf';
		$result = PlateComposer::compose( array( array( 'source_path' => $dir . '/q.3mf', 'x' => 20, 'y' => 30, 'rotation_deg' => $deg ) ), $out );
		$this->assertTrue( $result['ok'], $result['error'] );

		$zip = new \ZipArchive();
		$zip->open( $out );
		$xml = $zip->getFromName( '3D/3dmodel.model' );
		$zip->close();

		$doc  = new \DOMDocument();
		$doc->loadXML( $xml );
		$item = $doc->getElementsByTagName( 'item' )->item( 0 );
		$m    = array_map( 'floatval', explode( ' ', $item->getAttribute( 'transform' ) ) );

		$minx = INF;
		$miny = INF;
		foreach ( array( array( 100, 50 ), array( 147, 50 ), array( 147, 86 ), array( 100, 86 ) ) as $p ) {
			$minx = min( $minx, $p[0] * $m[0] + $p[1] * $m[3] + $m[9] );
			$miny = min( $miny, $p[0] * $m[1] + $p[1] * $m[4] + $m[10] );
		}

		$this->assertEqualsWithDelta( 20.0, $minx, 0.001 );
		$this->assertEqualsWithDelta( 30.0, $miny, 0.001 );
	}

	/**
	 * @dataProvider angles
	 */
	public function test_teile_werden_nie_gekippt_nur_um_die_senkrechte_achse_gedreht( $deg ) {
		$m = array_map( 'floatval', explode( ' ', PlateComposer::transform_matrix( $deg, 10, 20, 3 ) ) );

		// Dritte Spalte (z) und dritte Zeile: Einheit, keine Neigung; z wird nur angehoben.
		$this->assertEqualsWithDelta( 0.0, $m[2], 1e-9 );
		$this->assertEqualsWithDelta( 0.0, $m[5], 1e-9 );
		$this->assertEqualsWithDelta( 0.0, $m[6], 1e-9 );
		$this->assertEqualsWithDelta( 0.0, $m[7], 1e-9 );
		$this->assertEqualsWithDelta( 1.0, $m[8], 1e-9 );
	}

	public function test_standard_drehungen_sind_vierteldrehungen() {
		$this->assertSame( array( 0, 90, 180, 270 ), \Lindenweg16\Modules\Betrieb\Nesting\RasterPacker::DEFAULT_ROTATIONS );
	}
}
