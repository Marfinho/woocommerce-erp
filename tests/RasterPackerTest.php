<?php
/**
 * Silhouette und RasterPacker: Anordnung nach dem echten Umriss.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\Nesting\BinPacker;
use Lindenweg16\Modules\Betrieb\Nesting\PlateComposer;
use Lindenweg16\Modules\Betrieb\Nesting\Polygon;
use Lindenweg16\Modules\Betrieb\Nesting\RasterPacker;
use Lindenweg16\Modules\Betrieb\Nesting\Sat;
use Lindenweg16\Modules\Betrieb\Nesting\Silhouette;

class RasterPackerTest extends TestCase {

	protected function quad( array &$v, array &$t, $x0, $y0, $x1, $y1 ) {
		$o   = count( $v );
		$v[] = array( $x0, $y0, 0 );
		$v[] = array( $x1, $y0, 0 );
		$v[] = array( $x1, $y1, 0 );
		$v[] = array( $x0, $y1, 0 );
		$t[] = array( $o, $o + 1, $o + 2 );
		$t[] = array( $o, $o + 2, $o + 3 );
	}

	/** C-Form 40×40, Balken 6 mm, Öffnung nach rechts. */
	protected function c_shape() {
		$v = array();
		$t = array();
		$this->quad( $v, $t, 0, 0, 40, 6 );
		$this->quad( $v, $t, 0, 34, 40, 40 );
		$this->quad( $v, $t, 0, 0, 6, 40 );

		return Silhouette::from_mesh( $v, $t );
	}

	protected function box( $w, $h ) {
		$v = array();
		$t = array();
		$this->quad( $v, $t, 0, 0, $w, $h );

		return Silhouette::from_mesh( $v, $t );
	}

	public function test_silhouette_behaelt_die_einbuchtung() {
		$sil = $this->c_shape();

		// Zeile bei y = 20 mm (Mitte): nur der linke Balken (6 mm = 12 Zellen), nicht die Hülle.
		$row = $sil['runs'][40];
		$this->assertSame( 0, $row[0][0] );
		$this->assertLessThanOrEqual( 13, $row[0][1] );

		// Hülle dagegen ist das volle Quadrat.
		$this->assertEqualsWithDelta( 1600.0, Polygon::area( $sil['hull'] ), 0.5 );
	}

	public function test_pfad_fasst_gleiche_zeilen_zusammen() {
		$d = Silhouette::path_d( $this->c_shape() );

		$this->assertSame( 3, substr_count( $d, 'M' ), 'Drei Balken, drei Rechtecke.' );
	}

	public function test_teil_passt_in_die_oeffnung_eines_c() {
		$bed   = array( 55, 48 );
		$parts = array(
			array( 'key' => 'c', 'sil' => $this->c_shape() ),
			array( 'key' => 'q', 'sil' => $this->box( 20, 20 ) ),
		);

		$raster = RasterPacker::arrange( $parts, $bed[0], $bed[1], 2.0, array( 0 ) );
		$convex = BinPacker::arrange(
			array(
				array( 'key' => 'c', 'hull' => $parts[0]['sil']['hull'] ),
				array( 'key' => 'q', 'hull' => $parts[1]['sil']['hull'] ),
			),
			$bed[0],
			$bed[1],
			2.0,
			array( 0 )
		);

		$this->assertCount( 2, $convex['bins'], 'Die Hülle füllt die Öffnung aus, das Quadrat braucht eine zweite Platte.' );
		$this->assertCount( 1, $raster['bins'], 'Der echte Umriss lässt das Quadrat in die Öffnung.' );
		$this->assertCount( 2, $raster['bins'][0]['placements'] );
	}

	/**
	 * Fein gerasterte Weltzellen eines platzierten Teils.
	 */
	protected function world_cells( array $sil, array $placement ) {
		$hull   = $sil['hull'];
		$rot    = Polygon::bounding_box( Polygon::rotate( $hull, $placement['rotation_deg'] ) );
		$rad    = deg2rad( $placement['rotation_deg'] );
		$cos    = cos( $rad );
		$sin    = sin( $rad );
		$tx     = $placement['x'] - $rot['minx'];
		$ty     = $placement['y'] - $rot['miny'];
		$f      = $sil['f'];
		$cells  = array();

		foreach ( $sil['runs'] as $r => $runs ) {
			foreach ( $runs as $run ) {
				for ( $c = $run[0]; $c <= $run[1]; $c++ ) {
					$px = ( $c + 0.5 ) * $f;
					$py = ( $r + 0.5 ) * $f;
					$cells[ floor( ( $px * $cos - $py * $sin + $tx ) / $f ) . ':' . floor( ( $px * $sin + $py * $cos + $ty ) / $f ) ] = true;
				}
			}
		}

		return $cells;
	}

	public function test_gemischte_teile_ueberlappen_nicht_und_bleiben_im_bett() {
		$sils  = array( 'c' => $this->c_shape(), 'b' => $this->box( 31, 16 ), 'm' => $this->box( 47, 36 ) );
		$parts = array();
		foreach ( array( 'c' => 4, 'b' => 3, 'm' => 3 ) as $k => $n ) {
			for ( $i = 0; $i < $n; $i++ ) {
				$parts[] = array( 'key' => $k . $i, 'sil' => $sils[ $k ] );
			}
		}

		$result = RasterPacker::arrange( $parts, 150, 130, 3.0 );
		$this->assertSame( array(), $result['errors'] );

		$seen = 0;
		foreach ( $result['bins'] as $bin ) {
			$all = array();

			foreach ( $bin['placements'] as $p ) {
				++$seen;
				$cells = $this->world_cells( $sils[ $p['key'][0] ], $p );

				foreach ( $cells as $key => $_ ) {
					list( $cx, $cy ) = array_map( 'intval', explode( ':', $key ) );
					$this->assertGreaterThanOrEqual( 2.5, $cx * 0.5, 'Randabstand links.' );
					$this->assertGreaterThanOrEqual( 2.5, $cy * 0.5, 'Randabstand unten.' );
					$this->assertLessThanOrEqual( 150 - 2.5, ( $cx + 1 ) * 0.5, 'Randabstand rechts.' );
					$this->assertLessThanOrEqual( 130 - 2.5, ( $cy + 1 ) * 0.5, 'Randabstand oben.' );
					$this->assertArrayNotHasKey( $key, $all, 'Zwei Teile belegen dieselbe Stelle.' );
					$all[ $key ] = true;
				}
			}
		}

		$this->assertSame( count( $parts ), $seen );
	}

	public function test_sperrflaeche_bleibt_frei() {
		$zone  = array( array( 0, 0 ), array( 40, 0 ), array( 40, 40 ), array( 0, 40 ) );
		$sil   = $this->box( 30, 30 );
		$parts = array( array( 'key' => 'a', 'sil' => $sil ), array( 'key' => 'b', 'sil' => $sil ) );

		$result = RasterPacker::arrange( $parts, 100, 100, 4.0, array( 0, 90 ), array( $zone ) );

		foreach ( $result['bins'] as $bin ) {
			foreach ( $bin['placements'] as $p ) {
				$overlaps_x = $p['x'] < 40 + 4 && $p['x'] + 30 > 0;
				$overlaps_y = $p['y'] < 40 + 4 && $p['y'] + 30 > 0;
				$this->assertFalse( $overlaps_x && $overlaps_y, 'Teil ragt in die Sperrfläche.' );
			}
		}
	}

	public function test_zu_grosses_teil_wird_gemeldet() {
		$result = RasterPacker::arrange( array( array( 'key' => 'x', 'sil' => $this->box( 300, 300 ) ) ), 100, 100, 5.0 );

		$this->assertCount( 1, $result['errors'] );
		$this->assertSame( array(), $result['bins'] );
	}

	public function test_ergebnis_ist_deterministisch() {
		$sil   = $this->box( 33, 21 );
		$parts = array();
		for ( $i = 0; $i < 9; $i++ ) {
			$parts[] = array( 'key' => 'p' . $i, 'sil' => $sil );
		}

		$this->assertSame( RasterPacker::arrange( $parts, 120, 100, 5.0, RasterPacker::DEFAULT_ROTATIONS, array(), 0.0 ), RasterPacker::arrange( $parts, 120, 100, 5.0, RasterPacker::DEFAULT_ROTATIONS, array(), 0.0 ) );
	}

	/**
	 * Von der Datei bis zur fertigen Platte: Silhouette lesen, anordnen,
	 * zusammensetzen – danach liegen die Teile dort, wo der Packer sie meint
	 * (auch bei 45° und mehr, wo vorher die Drehecke danebenlag).
	 */
	public function test_von_der_datei_bis_zur_platte() {
		$dir = sys_get_temp_dir() . '/lw16-raster-test-' . getmypid();
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		$sizes = array( 'a' => array( 60, 12 ), 'b' => array( 30, 25 ) );
		$sils  = array();
		foreach ( $sizes as $k => $wd ) {
			$this->write_cuboid( $dir . "/$k.3mf", $wd[0], $wd[1], 100, 50 );
			$sils[ $k ] = Silhouette::from_file( $dir . "/$k.3mf" );
			$this->assertNotNull( $sils[ $k ] );
			$this->assertEqualsWithDelta( $wd[0], $sils[ $k ]['width_mm'], 0.001 );
		}

		$parts = array();
		foreach ( array( 'a' => 5, 'b' => 4 ) as $k => $n ) {
			for ( $i = 0; $i < $n; $i++ ) {
				$parts[] = array( 'key' => $k . $i, 'sil' => $sils[ $k ] );
			}
		}

		$result = RasterPacker::arrange( $parts, 110, 90, 3.0 );
		$this->assertSame( array(), $result['errors'] );

		foreach ( $result['bins'] as $bin ) {
			$placements = array();
			foreach ( $bin['placements'] as $p ) {
				$p['source_path'] = $dir . '/' . $p['key'][0] . '.3mf';
				$placements[]     = $p;
			}

			$out = $dir . '/platte.3mf';
			$this->assertTrue( PlateComposer::compose( $placements, $out )['ok'] );

			$zip = new \ZipArchive();
			$zip->open( $out );
			$doc = new \DOMDocument();
			$doc->loadXML( $zip->getFromName( '3D/3dmodel.model' ) );
			$zip->close();

			$objects = array();
			foreach ( $doc->getElementsByTagName( 'object' ) as $o ) {
				$points = array();
				foreach ( $o->getElementsByTagName( 'vertex' ) as $vx ) {
					$points[] = array( (float) $vx->getAttribute( 'x' ), (float) $vx->getAttribute( 'y' ) );
				}
				$objects[ $o->getAttribute( 'id' ) ] = $points;
			}

			$polys = array();
			foreach ( $doc->getElementsByTagName( 'item' ) as $item ) {
				$m   = array_map( 'floatval', explode( ' ', $item->getAttribute( 'transform' ) ) );
				$poly = array();
				foreach ( $objects[ $item->getAttribute( 'objectid' ) ] as $p ) {
					$poly[] = array( $p[0] * $m[0] + $p[1] * $m[3] + $m[9], $p[0] * $m[1] + $p[1] * $m[4] + $m[10] );
				}
				$poly = Polygon::convex_hull( $poly );
				$box  = Polygon::bounding_box( $poly );

				$this->assertGreaterThanOrEqual( 2.5, $box['minx'] );
				$this->assertGreaterThanOrEqual( 2.5, $box['miny'] );
				$this->assertLessThanOrEqual( 107.5, $box['maxx'] );
				$this->assertLessThanOrEqual( 87.5, $box['maxy'] );

				foreach ( $polys as $other ) {
					$this->assertFalse( Sat::overlap( $poly, $other ), 'Fertige Teile überlappen.' );
				}
				$polys[] = $poly;
			}
		}
	}

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
}
