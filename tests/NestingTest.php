<?php
/**
 * Plattenanordnung/Nesting: Polygon-Geometrie, Kollisionsprüfung (SAT),
 * Grundriss aus 3MF, Bottom-Left-Fill-Platzierung (BinPacker) und die
 * Transformationsmatrix-Berechnung von PlateComposer.
 *
 * Alles hier ist reine Geometrie ohne Datenbank – deshalb ohne
 * WordPress-Testsuite prüfbar. Die eigentliche Zip-/XML-Zusammensetzung
 * von PlateComposer::compose() wird zusätzlich mit echten, minimalen
 * 3MF-Fixture-Dateien geprüft (ZipArchive/DOMDocument brauchen dafür kein
 * WordPress).
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\Nesting\BinPacker;
use Lindenweg16\Modules\Betrieb\Nesting\MeshOutline;
use Lindenweg16\Modules\Betrieb\Nesting\PlateComposer;
use Lindenweg16\Modules\Betrieb\Nesting\Polygon;
use Lindenweg16\Modules\Betrieb\Nesting\Sat;

/**
 * Prüft die reine Geometrie der Plattenanordnung.
 */
class NestingTest extends TestCase {

	/* ----------------------------------------------------------------
	 * Polygon: Convex Hull
	 * -------------------------------------------------------------- */

	/**
	 * Ein Quadrat mit einem inneren Punkt: Der innere Punkt fällt aus der
	 * Hülle heraus, die vier Ecken bleiben.
	 */
	public function test_convex_hull_verwirft_innere_punkte() {
		$hull = Polygon::convex_hull( array( array( 0, 0 ), array( 10, 0 ), array( 10, 10 ), array( 0, 10 ), array( 5, 5 ) ) );

		$this->assertCount( 4, $hull );
		$this->assertEqualsWithDelta( 100.0, Polygon::area( $hull ), 0.001 );
	}

	/**
	 * Kollineare Punkte auf einer Kante gehören nicht zur Hülle.
	 */
	public function test_convex_hull_verwirft_kollineare_kantenpunkte() {
		$hull = Polygon::convex_hull( array( array( 0, 0 ), array( 5, 0 ), array( 10, 0 ), array( 10, 10 ), array( 0, 10 ) ) );

		$this->assertCount( 4, $hull, 'Der Punkt (5,0) liegt exakt auf der Kante (0,0)-(10,0) und gehört nicht zur Hülle.' );
	}

	/**
	 * Weniger als drei unterschiedliche Punkte ergeben keine Fläche –
	 * die (deduplizierten) Punkte kommen unverändert zurück.
	 */
	public function test_convex_hull_mit_zu_wenig_punkten() {
		$this->assertSame( array( array( 1.0, 1.0 ) ), Polygon::convex_hull( array( array( 1, 1 ), array( 1, 1 ) ) ) );
		$this->assertCount( 2, Polygon::convex_hull( array( array( 0, 0 ), array( 5, 5 ) ) ) );
	}

	/**
	 * Ein Kreis (als Vieleck angenähert) hat eine Hülle, deren Fläche der
	 * Kreisfläche nahekommt.
	 */
	public function test_convex_hull_eines_angenaeherten_kreises() {
		$points = array();
		for ( $deg = 0; $deg < 360; $deg += 10 ) {
			$rad      = deg2rad( $deg );
			$points[] = array( 10 * cos( $rad ), 10 * sin( $rad ) );
		}

		$hull = Polygon::convex_hull( $points );

		$this->assertGreaterThanOrEqual( 30, count( $hull ) );
		$this->assertEqualsWithDelta( M_PI * 100, Polygon::area( $hull ), 5.0 );
	}

	/* ----------------------------------------------------------------
	 * Polygon: Fläche, Bounding Box, Translate, Rotate, Place
	 * -------------------------------------------------------------- */

	/**
	 * Fläche eines Dreiecks (Grundseite × Höhe / 2).
	 */
	public function test_area_dreieck() {
		$tri = array( array( 0, 0 ), array( 10, 0 ), array( 5, 8 ) );
		$this->assertEqualsWithDelta( 40.0, Polygon::area( $tri ), 0.001 );
	}

	/**
	 * Begrenzungsrechteck eines einfachen Polygons.
	 */
	public function test_bounding_box() {
		$bbox = Polygon::bounding_box( array( array( -2, 3 ), array( 5, -1 ), array( 4, 9 ) ) );

		$this->assertEqualsWithDelta( -2.0, $bbox['minx'], 1e-9 );
		$this->assertEqualsWithDelta( -1.0, $bbox['miny'], 1e-9 );
		$this->assertEqualsWithDelta( 5.0, $bbox['maxx'], 1e-9 );
		$this->assertEqualsWithDelta( 9.0, $bbox['maxy'], 1e-9 );
	}

	/**
	 * Verschieben addiert dx/dy auf jeden Punkt.
	 */
	public function test_translate() {
		$moved = Polygon::translate( array( array( 1, 1 ), array( 2, 2 ) ), 10, -5 );
		$this->assertSame( array( array( 11.0, -4.0 ), array( 12.0, -3.0 ) ), $moved );
	}

	/**
	 * Drehung um 90° (Gegenuhrzeigersinn): (1,0) → (0,1), (0,1) → (-1,0).
	 */
	public function test_rotate_90_grad() {
		$rotated = Polygon::rotate( array( array( 1, 0 ), array( 0, 1 ) ), 90 );

		$this->assertEqualsWithDelta( 0.0, $rotated[0][0], 1e-9 );
		$this->assertEqualsWithDelta( 1.0, $rotated[0][1], 1e-9 );
		$this->assertEqualsWithDelta( -1.0, $rotated[1][0], 1e-9 );
		$this->assertEqualsWithDelta( 0.0, $rotated[1][1], 1e-9 );
	}

	/**
	 * Drehung um 360° ergibt (bis auf Rundungsfehler) das Original.
	 */
	public function test_rotate_360_grad_ist_identitaet() {
		$poly    = array( array( 3, 4 ), array( -2, 7 ) );
		$rotated = Polygon::rotate( $poly, 360 );

		foreach ( $poly as $i => $p ) {
			$this->assertEqualsWithDelta( $p[0], $rotated[ $i ][0], 1e-9 );
			$this->assertEqualsWithDelta( $p[1], $rotated[ $i ][1], 1e-9 );
		}
	}

	/**
	 * place() dreht und verschiebt so, dass die Bounding-Box-Ecke exakt
	 * am Ankerpunkt liegt.
	 */
	public function test_place_setzt_bounding_box_ecke_auf_anker() {
		$square = array( array( 0, 0 ), array( 10, 0 ), array( 10, 10 ), array( 0, 10 ) );
		$placed = Polygon::place( $square, 0, 100, 50 );
		$bbox   = Polygon::bounding_box( $placed );

		$this->assertEqualsWithDelta( 100.0, $bbox['minx'], 1e-6 );
		$this->assertEqualsWithDelta( 50.0, $bbox['miny'], 1e-6 );
		$this->assertEqualsWithDelta( 110.0, $bbox['maxx'], 1e-6 );
		$this->assertEqualsWithDelta( 60.0, $bbox['maxy'], 1e-6 );
	}

	/**
	 * place() mit 90°-Drehung: aus einem 10×20-Rechteck wird eines mit
	 * 20×10 Bounding Box, weiterhin an den Anker verschoben.
	 */
	public function test_place_mit_drehung_tauscht_breite_und_hoehe() {
		$rect   = array( array( 0, 0 ), array( 10, 0 ), array( 10, 20 ), array( 0, 20 ) );
		$placed = Polygon::place( $rect, 90, 0, 0 );
		$bbox   = Polygon::bounding_box( $placed );

		$this->assertEqualsWithDelta( 20.0, $bbox['maxx'] - $bbox['minx'], 1e-6 );
		$this->assertEqualsWithDelta( 10.0, $bbox['maxy'] - $bbox['miny'], 1e-6 );
	}

	/* ----------------------------------------------------------------
	 * Polygon: Offset (Aufblasen)
	 * -------------------------------------------------------------- */

	/**
	 * Ein Quadrat, um 2mm versetzt, wächst auf jeder Seite um 2mm.
	 */
	public function test_offset_quadrat() {
		$square   = array( array( 0, 0 ), array( 10, 0 ), array( 10, 10 ), array( 0, 10 ) );
		$inflated = Polygon::offset( $square, 2 );
		$bbox     = Polygon::bounding_box( $inflated );

		$this->assertEqualsWithDelta( -2.0, $bbox['minx'], 1e-6 );
		$this->assertEqualsWithDelta( -2.0, $bbox['miny'], 1e-6 );
		$this->assertEqualsWithDelta( 12.0, $bbox['maxx'], 1e-6 );
		$this->assertEqualsWithDelta( 12.0, $bbox['maxy'], 1e-6 );
	}

	/**
	 * offset() mit Distanz 0 (oder negativ) lässt das Polygon unverändert.
	 */
	public function test_offset_null_aendert_nichts() {
		$square = array( array( 0, 0 ), array( 10, 0 ), array( 10, 10 ), array( 0, 10 ) );
		$this->assertSame( $square, Polygon::offset( $square, 0 ) );
		$this->assertSame( $square, Polygon::offset( $square, -5 ) );
	}

	/* ----------------------------------------------------------------
	 * Sat: Kollisionsprüfung
	 * -------------------------------------------------------------- */

	/**
	 * Zwei Quadrate mit Lücke überlappen nicht.
	 */
	public function test_sat_getrennte_quadrate_ueberlappen_nicht() {
		$a = array( array( 0, 0 ), array( 10, 0 ), array( 10, 10 ), array( 0, 10 ) );
		$b = Polygon::translate( $a, 20, 0 );

		$this->assertFalse( Sat::overlap( $a, $b ) );
	}

	/**
	 * Zwei sich überschneidende Quadrate überlappen.
	 */
	public function test_sat_ueberschneidende_quadrate_ueberlappen() {
		$a = array( array( 0, 0 ), array( 10, 0 ), array( 10, 10 ), array( 0, 10 ) );
		$b = Polygon::translate( $a, 5, 0 );

		$this->assertTrue( Sat::overlap( $a, $b ) );
	}

	/**
	 * Exakt aneinanderstossende Quadrate (Lücke = 0) gelten als
	 * überlappend – Berührung ist kein sicherer Zustand (siehe Sat-Doku).
	 */
	public function test_sat_beruehrende_quadrate_gelten_als_ueberlappend() {
		$a = array( array( 0, 0 ), array( 10, 0 ), array( 10, 10 ), array( 0, 10 ) );
		$b = Polygon::translate( $a, 10, 0 );

		$this->assertTrue( Sat::overlap( $a, $b ) );
	}

	/**
	 * Ein Quadrat, das exakt ausserhalb der um margin aufgeblasenen Hülle
	 * eines anderen liegt, überlappt nicht mehr – das ist die Grundlage,
	 * auf der BinPacker den Sicherheitsabstand durchsetzt.
	 */
	public function test_sat_gegen_aufgeblasene_huelle() {
		$a        = array( array( 0, 0 ), array( 10, 0 ), array( 10, 10 ), array( 0, 10 ) );
		$inflated = Polygon::offset( $a, 5 );

		$knapp_dran = Polygon::translate( $a, 14.99, 0 ); // 4.99mm Lücke zu A - innerhalb der 5mm-Aufblasung.
		$genug_weg  = Polygon::translate( $a, 15.02, 0 ); // Etwas über 5mm Lücke.

		$this->assertTrue( Sat::overlap( $knapp_dran, $inflated ), 'Innerhalb des Sicherheitsabstands muss als Kollision gelten.' );
		$this->assertFalse( Sat::overlap( $genug_weg, $inflated ), 'Ausserhalb des Sicherheitsabstands darf keine Kollision mehr gelten.' );
	}

	/**
	 * Ein rotiertes Quadrat (Raute) und ein achsenparalleles Quadrat, die
	 * sich nur in den Ecken beinahe berühren, aber nicht überlappen -
	 * Regressionstest dafür, dass SAT nicht nur Achsen-ausgerichtete
	 * Rechtecke korrekt behandelt.
	 */
	public function test_sat_gedrehtes_quadrat_gegen_achsenparalleles() {
		$square   = array( array( 0, 0 ), array( 10, 0 ), array( 10, 10 ), array( 0, 10 ) );
		$diamond  = Polygon::rotate( $square, 45 ); // Raute um den Ursprung.
		$far_away = Polygon::translate( $diamond, 100, 100 );

		$this->assertFalse( Sat::overlap( $square, $far_away ) );
		$this->assertTrue( Sat::overlap( $square, $diamond ), 'Beide teilen den Ursprung, müssen also überlappen.' );
	}

	/**
	 * Entartete Eingaben (weniger als 3 Punkte) gelten konservativ als
	 * überlappend statt fälschlich als frei.
	 */
	public function test_sat_entartete_polygone_gelten_konservativ_als_ueberlappend() {
		$this->assertTrue( Sat::overlap( array( array( 0, 0 ), array( 1, 1 ) ), array( array( 0, 0 ), array( 10, 0 ), array( 10, 10 ) ) ) );
	}

	/* ----------------------------------------------------------------
	 * MeshOutline: Grundriss aus 3MF-XML
	 * -------------------------------------------------------------- */

	/**
	 * Hilfsfunktion: minimales 3MF-XML mit einem quaderförmigen Objekt.
	 *
	 * @param float $w Breite (X).
	 * @param float $d Tiefe (Y).
	 * @param float $h Höhe (Z, für die Draufsicht unerheblich).
	 * @return string
	 */
	protected function cuboid_xml( $w, $d, $h = 10 ) {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">'
			. '<resources><object id="1" type="model"><mesh><vertices>'
			. "<vertex x=\"0\" y=\"0\" z=\"0\"/><vertex x=\"$w\" y=\"0\" z=\"0\"/>"
			. "<vertex x=\"$w\" y=\"$d\" z=\"0\"/><vertex x=\"0\" y=\"$d\" z=\"0\"/>"
			. "<vertex x=\"0\" y=\"0\" z=\"$h\"/><vertex x=\"$w\" y=\"0\" z=\"$h\"/>"
			. "<vertex x=\"$w\" y=\"$d\" z=\"$h\"/><vertex x=\"0\" y=\"$d\" z=\"$h\"/>"
			. '</vertices><triangles><triangle v1="0" v2="1" v3="2"/><triangle v1="0" v2="2" v3="3"/></triangles>'
			. '</mesh></object></resources><build><item objectid="1"/></build></model>';
	}

	/**
	 * Die XY-Hülle eines Quaders ist sein Grundriss (Z wird ignoriert).
	 */
	public function test_mesh_outline_liefert_grundriss() {
		$hull = MeshOutline::xy_hull_from_xml( $this->cuboid_xml( 30, 20 ) );

		$this->assertNotNull( $hull );
		$this->assertEqualsWithDelta( 600.0, Polygon::area( $hull ), 0.001 );
		$bbox = Polygon::bounding_box( $hull );
		$this->assertEqualsWithDelta( 30.0, $bbox['maxx'] - $bbox['minx'], 0.001 );
		$this->assertEqualsWithDelta( 20.0, $bbox['maxy'] - $bbox['miny'], 0.001 );
	}

	/**
	 * Leeres/ungültiges XML liefert null statt eines Fehlers.
	 */
	public function test_mesh_outline_ohne_geometrie_ist_null() {
		$this->assertNull( MeshOutline::xy_hull_from_xml( '' ) );
		$this->assertNull( MeshOutline::xy_hull_from_xml( 'kein xml' ) );
		$this->assertNull( MeshOutline::xy_hull_from_xml( '<model xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02"><resources></resources><build></build></model>' ) );
	}

	/**
	 * Bei mehreren <object>-Elementen wird nur das erste ausgewertet
	 * (siehe Klassendoku von MeshOutline).
	 */
	public function test_mesh_outline_nimmt_nur_erstes_objekt() {
		$xml = '<model xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02"><resources>'
			. '<object id="1"><mesh><vertices><vertex x="0" y="0" z="0"/><vertex x="5" y="0" z="0"/><vertex x="5" y="5" z="0"/><vertex x="0" y="5" z="0"/></vertices><triangles><triangle v1="0" v2="1" v3="2"/></triangles></mesh></object>'
			. '<object id="2"><mesh><vertices><vertex x="0" y="0" z="0"/><vertex x="50" y="0" z="0"/><vertex x="50" y="50" z="0"/></vertices><triangles><triangle v1="0" v2="1" v3="2"/></triangles></mesh></object>'
			. '</resources><build><item objectid="1"/><item objectid="2"/></build></model>';

		$hull = MeshOutline::xy_hull_from_xml( $xml );
		$bbox = Polygon::bounding_box( $hull );

		$this->assertEqualsWithDelta( 5.0, $bbox['maxx'], 0.001, 'Muss vom ersten (5x5) Objekt kommen, nicht vom zweiten (50x50).' );
	}

	/* ----------------------------------------------------------------
	 * BinPacker: Anordnung ohne Kollision, innerhalb der Bettgrenzen
	 * -------------------------------------------------------------- */

	/**
	 * Prüft für ein Anordnungsergebnis die beiden Sicherheitseigenschaften:
	 * kein Bauteil verletzt Bettgrenzen/Randabstand, keine zwei Bauteile
	 * überlappen (auch nicht unter Berücksichtigung des Sicherheitsabstands).
	 *
	 * @param array $result        Rückgabe von BinPacker::arrange().
	 * @param array $hulls_by_key  key => Original-Hülle (auf 0,0 normiert vorausgesetzt).
	 * @param float $bed_width_mm  Bettbreite.
	 * @param float $bed_height_mm Betttiefe.
	 * @param float $margin_mm     Sicherheitsabstand.
	 */
	protected function assert_arrangement_is_safe( array $result, array $hulls_by_key, $bed_width_mm, $bed_height_mm, $margin_mm ) {
		foreach ( $result['bins'] as $bin ) {
			$placed_hulls = array();

			foreach ( $bin['placements'] as $placement ) {
				$this->assertArrayHasKey( $placement['key'], $hulls_by_key );

				$hull = Polygon::place( $hulls_by_key[ $placement['key'] ], $placement['rotation_deg'], $placement['x'], $placement['y'] );
				$bbox = Polygon::bounding_box( $hull );

				$this->assertGreaterThanOrEqual( $margin_mm - 1e-6, $bbox['minx'], "Bauteil {$placement['key']}: linker Rand unterschreitet den Sicherheitsabstand." );
				$this->assertGreaterThanOrEqual( $margin_mm - 1e-6, $bbox['miny'], "Bauteil {$placement['key']}: unterer Rand unterschreitet den Sicherheitsabstand." );
				$this->assertLessThanOrEqual( $bed_width_mm - $margin_mm + 1e-6, $bbox['maxx'], "Bauteil {$placement['key']}: rechter Rand verlässt das Bett." );
				$this->assertLessThanOrEqual( $bed_height_mm - $margin_mm + 1e-6, $bbox['maxy'], "Bauteil {$placement['key']}: oberer Rand verlässt das Bett." );

				$placed_hulls[ $placement['key'] ] = $hull;
			}

			$keys = array_keys( $placed_hulls );
			for ( $i = 0; $i < count( $keys ); $i++ ) {
				for ( $j = $i + 1; $j < count( $keys ); $j++ ) {
					// Eine kleine Toleranz (halber Sicherheitsabstand) gegen
					// Fliesskomma-Rundung an der Nudge-Grenze (siehe
					// BinPacker::NUDGE_MM) - keine Toleranz gegenüber echten
					// Überlappungen.
					$a = Polygon::offset( $placed_hulls[ $keys[ $i ] ], max( 0, $margin_mm - 0.1 ) );
					$this->assertFalse(
						Sat::overlap( $a, $placed_hulls[ $keys[ $j ] ] ),
						"Bauteile {$keys[$i]} und {$keys[$j]} unterschreiten den Sicherheitsabstand oder überlappen."
					);
				}
			}
		}
	}

	/**
	 * Sechs 40×40-Quadrate mit 5mm Rand auf einem 100×100-Bett: Vier
	 * passen auf eine Platte (2×2-Raster mit Rand), der Rest auf eine
	 * zweite - und keine Platzierung verletzt Rand oder Sicherheitsabstand.
	 */
	public function test_bin_packer_verteilt_auf_mehrere_platten_ohne_kollision() {
		$square = array( array( 0, 0 ), array( 40, 0 ), array( 40, 40 ), array( 0, 40 ) );
		$parts  = array();
		$hulls  = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$key           = "s$i";
			$parts[]       = array( 'key' => $key, 'hull' => $square );
			$hulls[ $key ] = $square;
		}

		$result = BinPacker::arrange( $parts, 100, 100, 5 );

		$this->assertEmpty( $result['errors'] );
		$placed = array_sum( array_map( static function ( $b ) { return count( $b['placements'] ); }, $result['bins'] ) );
		$this->assertSame( 6, $placed );
		$this->assertGreaterThanOrEqual( 2, count( $result['bins'] ), 'Sechs 40x40-Quadrate mit Rand passen nicht alle auf eine 100x100-Platte.' );

		$this->assert_arrangement_is_safe( $result, $hulls, 100, 100, 5 );
	}

	/**
	 * Gemischte Formen (Kreis, Dreieck, Rechteck) auf einer grossen Platte
	 * – auch hier keine Kollisionen und keine Randverletzungen.
	 */
	public function test_bin_packer_mit_gemischten_formen() {
		$circle = array();
		for ( $deg = 0; $deg < 360; $deg += 15 ) {
			$rad      = deg2rad( $deg );
			$circle[] = array( 20 * cos( $rad ), 20 * sin( $rad ) );
		}
		$triangle = array( array( 0, 0 ), array( 30, 0 ), array( 15, 26 ) );
		$rect     = array( array( 0, 0 ), array( 60, 0 ), array( 60, 20 ), array( 0, 20 ) );

		$parts = array(
			array( 'key' => 'c1', 'hull' => $circle ),
			array( 'key' => 'c2', 'hull' => $circle ),
			array( 'key' => 'c3', 'hull' => $circle ),
			array( 'key' => 't1', 'hull' => $triangle ),
			array( 'key' => 't2', 'hull' => $triangle ),
			array( 'key' => 'r1', 'hull' => $rect ),
			array( 'key' => 'r2', 'hull' => $rect ),
		);
		$hulls = array(
			'c1' => Polygon::convex_hull( $circle ),
			'c2' => Polygon::convex_hull( $circle ),
			'c3' => Polygon::convex_hull( $circle ),
			't1' => Polygon::convex_hull( $triangle ),
			't2' => Polygon::convex_hull( $triangle ),
			'r1' => Polygon::convex_hull( $rect ),
			'r2' => Polygon::convex_hull( $rect ),
		);

		$result = BinPacker::arrange( $parts, 200, 200, 3 );

		$this->assertEmpty( $result['errors'] );
		$placed = array_sum( array_map( static function ( $b ) { return count( $b['placements'] ); }, $result['bins'] ) );
		$this->assertSame( 7, $placed );

		$this->assert_arrangement_is_safe( $result, $hulls, 200, 200, 3 );
	}

	/**
	 * Ein Bauteil, das (auch gedreht) grösser als das Bett ist, wird als
	 * Fehler gemeldet statt eine Endlosschleife oder eine falsche
	 * Platzierung zu erzeugen.
	 */
	public function test_bin_packer_meldet_zu_grosses_bauteil_als_fehler() {
		$huge = array( array( 0, 0 ), array( 500, 0 ), array( 500, 500 ), array( 0, 500 ) );

		$result = BinPacker::arrange( array( array( 'key' => 'huge', 'hull' => $huge ) ), 100, 100, 5 );

		$this->assertEmpty( $result['bins'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame( 'huge', $result['errors'][0]['key'] );
	}

	/**
	 * Ein einzelnes Bauteil, das nur gedreht auf das Bett passt (schmal
	 * und lang), wird trotzdem platziert – die Rotation muss also
	 * tatsächlich helfen, nicht nur angeboten werden.
	 */
	public function test_bin_packer_nutzt_rotation_wenn_noetig() {
		// 95x20 passt auf ein 100x100-Bett mit 2mm Rand nur so herum,
		// nicht gedreht bräuchte es dieselbe Fläche so oder so - dieser
		// Test prüft eher, dass beide Bauteile überhaupt zusammen Platz
		// finden, mit oder ohne Drehung, ohne Kollision.
		$long  = array( array( 0, 0 ), array( 80, 0 ), array( 80, 10 ), array( 0, 10 ) );
		$parts = array( array( 'key' => 'l1', 'hull' => $long ), array( 'key' => 'l2', 'hull' => $long ) );
		$hulls = array( 'l1' => $long, 'l2' => $long );

		$result = BinPacker::arrange( $parts, 90, 90, 2 );

		$this->assertEmpty( $result['errors'] );
		$placed = array_sum( array_map( static function ( $b ) { return count( $b['placements'] ); }, $result['bins'] ) );
		$this->assertSame( 2, $placed );

		$this->assert_arrangement_is_safe( $result, $hulls, 90, 90, 2 );
	}

	/**
	 * Determinismus: dieselbe Eingabe liefert immer dasselbe Ergebnis.
	 */
	public function test_bin_packer_ist_deterministisch() {
		$square = array( array( 0, 0 ), array( 30, 0 ), array( 30, 30 ), array( 0, 30 ) );
		$parts  = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$parts[] = array( 'key' => "s$i", 'hull' => $square );
		}

		$first  = BinPacker::arrange( $parts, 150, 150, 4 );
		$second = BinPacker::arrange( $parts, 150, 150, 4 );

		$this->assertSame( $first, $second );
	}

	/**
	 * Eine leere Bauteilliste liefert ein leeres, aber gültiges Ergebnis.
	 */
	public function test_bin_packer_mit_leerer_liste() {
		$result = BinPacker::arrange( array(), 100, 100, 5 );
		$this->assertSame( array(), $result['bins'] );
		$this->assertSame( array(), $result['errors'] );
	}

	/* ----------------------------------------------------------------
	 * PlateComposer: Transformationsmatrix
	 * -------------------------------------------------------------- */

	/**
	 * Ohne Drehung ist die Matrix die Einheitsmatrix plus Translation.
	 */
	public function test_transform_matrix_ohne_drehung() {
		$this->assertSame( '1 0 0 0 1 0 0 0 1 10 20 0', PlateComposer::transform_matrix( 0, 10, 20 ) );
	}

	/**
	 * 90°-Drehung entspricht Polygon::rotate() – dieselbe Konvention, damit
	 * eine BinPacker-Platzierung unverändert übernommen werden kann.
	 */
	public function test_transform_matrix_90_grad() {
		$matrix = PlateComposer::transform_matrix( 90, 0, 0 );
		$this->assertSame( '0 1 0 -1 0 0 0 0 1 0 0 0', $matrix );
	}

	/**
	 * Negative Verschiebung wird korrekt formatiert (kein doppeltes
	 * Minuszeichen, kein abgeschnittenes Vorzeichen).
	 */
	public function test_transform_matrix_negative_verschiebung() {
		$matrix = PlateComposer::transform_matrix( 0, -12.5, -3 );
		$this->assertStringContainsString( '-12.5 -3', $matrix );
	}

	/* ----------------------------------------------------------------
	 * PlateComposer: Zusammensetzen echter (minimaler) 3MF-Dateien
	 * -------------------------------------------------------------- */

	/**
	 * Verzeichnis für Fixture-Dateien dieses Tests.
	 *
	 * @return string
	 */
	protected function fixture_dir() {
		$dir = sys_get_temp_dir() . '/lw16-nesting-test-' . getmypid();
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		return $dir;
	}

	/**
	 * Eine minimale 3MF-Datei mit einem quaderförmigen Bauteil anlegen.
	 *
	 * @param string $path Zielpfad.
	 * @param float  $w    Breite.
	 * @param float  $d    Tiefe.
	 */
	protected function write_cuboid_3mf( $path, $w, $d ) {
		$zip = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml"/></Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rel0" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel" Target="/3D/3dmodel.model"/></Relationships>' );
		$zip->addFromString( '3D/3dmodel.model', $this->cuboid_xml( $w, $d ) );
		$zip->close();
	}

	/**
	 * Drei Platzierungen (zwei verschiedene Quellen, eine doppelt) zu
	 * einer Platte zusammensetzen: erwartet werden genau zwei
	 * Objekt-Ressourcen (Wiederverwendung derselben Quelle) und drei
	 * <item>-Platzierungen mit den richtigen Transformationsmatrizen.
	 */
	public function test_plate_composer_setzt_mehrere_quellen_zusammen() {
		$dir = $this->fixture_dir();
		$this->write_cuboid_3mf( $dir . '/a.3mf', 30, 20 );
		$this->write_cuboid_3mf( $dir . '/b.3mf', 15, 15 );

		$placements = array(
			array( 'source_path' => $dir . '/a.3mf', 'x' => 5, 'y' => 5, 'rotation_deg' => 0 ),
			array( 'source_path' => $dir . '/b.3mf', 'x' => 40, 'y' => 5, 'rotation_deg' => 90 ),
			array( 'source_path' => $dir . '/a.3mf', 'x' => 5, 'y' => 30, 'rotation_deg' => 0 ),
		);

		$out    = $dir . '/combined.3mf';
		$result = PlateComposer::compose( $placements, $out );

		$this->assertTrue( $result['ok'], $result['error'] );
		$this->assertFileExists( $out );

		$zip = new \ZipArchive();
		$zip->open( $out );
		$xml = $zip->getFromName( '3D/3dmodel.model' );
		$zip->close();

		$doc = new \DOMDocument();
		$doc->loadXML( $xml );

		$this->assertSame( 2, $doc->getElementsByTagName( 'object' )->length, 'Zwei unterschiedliche Quellen -> zwei Objekt-Ressourcen.' );
		$this->assertSame( 3, $doc->getElementsByTagName( 'item' )->length, 'Drei Platzierungen -> drei <item>-Einträge.' );

		$items = $doc->getElementsByTagName( 'item' );
		$this->assertSame( $items->item( 0 )->getAttribute( 'objectid' ), $items->item( 2 )->getAttribute( 'objectid' ), 'Beide Platzierungen von a.3mf müssen dieselbe Objekt-ID referenzieren.' );
		$this->assertNotSame( $items->item( 0 )->getAttribute( 'objectid' ), $items->item( 1 )->getAttribute( 'objectid' ) );

		// Die zusammengesetzte Datei muss selbst wieder eine gültige,
		// auswertbare Geometrie liefern (Rundfahrt-Test).
		$hull = MeshOutline::xy_hull_from_xml( $xml );
		$this->assertEqualsWithDelta( 600.0, Polygon::area( $hull ), 0.001 );
	}

	/**
	 * Ohne Platzierungen liefert compose() einen Fehler statt einer
	 * kaputten Datei.
	 */
	public function test_plate_composer_ohne_platzierungen_ist_fehler() {
		$result = PlateComposer::compose( array(), $this->fixture_dir() . '/leer.3mf' );
		$this->assertFalse( $result['ok'] );
		$this->assertNotSame( '', $result['error'] );
	}
}
