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

	/**
	 * Hilfsfunktion: eine 3MF-ZIP-Datei mit den übergebenen Einträgen
	 * (Pfad => Inhalt) im temporären Testverzeichnis anlegen.
	 *
	 * @param array $entries Pfad => Inhalt.
	 * @return string Absoluter Pfad zur angelegten Datei.
	 */
	protected function zip_3mf( array $entries ) {
		$path = sys_get_temp_dir() . '/lw16-test-' . uniqid( '', true ) . '.3mf';
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE );
		foreach ( $entries as $name => $content ) {
			$zip->addFromString( $name, $content );
		}
		$zip->close();

		// Am Ende des Tests aufräumen.
		register_shutdown_function(
			static function () use ( $path ) {
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
		);

		return $path;
	}

	/**
	 * Bambu Studio lagert die Geometrie mancher Objekte in eine eigene
	 * Datei innerhalb der 3MF aus (Production Extension) statt sie im
	 * Wurzel-3dmodel.model einzubetten - genau der vom Nutzer live
	 * gemeldete Fall ("keine auswertbare Geometrie ... gefunden", obwohl
	 * die Datei eine gültige Projekt-3MF war). xy_hull_from_file() muss
	 * dem <component p:path="..."> folgen und die dort liegende Geometrie
	 * lesen.
	 */
	public function test_mesh_outline_loest_ausgelagerte_objektdatei_auf() {
		$ns   = 'xmlns:p="http://schemas.microsoft.com/3dmanufacturing/production/2015/06"';
		$root = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02" ' . $ns . ' requiredextensions="p">'
			. '<resources><object id="2" type="model"><components>'
			. '<component p:path="/3D/Objects/object_8.model" objectid="1" transform="1 0 0 0 1 0 0 0 1 0 0 0"/>'
			. '</components></object></resources>'
			. '<build><item objectid="2" transform="1 0 0 0 1 0 0 0 1 128 128 20"/></build></model>';

		$sub = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02" ' . $ns . '>'
			. '<resources><object id="1" type="model"><mesh><vertices>'
			. '<vertex x="0" y="0" z="0"/><vertex x="30" y="0" z="0"/>'
			. '<vertex x="30" y="20" z="0"/><vertex x="0" y="20" z="0"/>'
			. '</vertices><triangles><triangle v1="0" v2="1" v3="2"/><triangle v1="0" v2="2" v3="3"/></triangles>'
			. '</mesh></object></resources></model>';

		$path = $this->zip_3mf(
			array(
				'3D/3dmodel.model'      => $root,
				'3D/Objects/object_8.model' => $sub,
			)
		);

		$hull = MeshOutline::xy_hull_from_file( $path );

		$this->assertNotNull( $hull );
		$this->assertEqualsWithDelta( 600.0, Polygon::area( $hull ), 0.001 );
	}

	/**
	 * Ein transform-Attribut am <component> (Rotation um 90° plus
	 * Verschiebung) muss auf die ausgelagerte Geometrie angewandt werden,
	 * bevor die Hülle berechnet wird.
	 */
	public function test_mesh_outline_wendet_transform_der_komponente_an() {
		$ns   = 'xmlns:p="http://schemas.microsoft.com/3dmanufacturing/production/2015/06"';
		// 90°-Drehung um Z (x'=-y, y'=x) plus Verschiebung um (100, 0).
		$root = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02" ' . $ns . ' requiredextensions="p">'
			. '<resources><object id="2" type="model"><components>'
			. '<component p:path="/3D/Objects/object_1.model" objectid="1" transform="0 1 0 -1 0 0 0 0 1 100 0 0"/>'
			. '</components></object></resources>'
			. '<build><item objectid="2"/></build></model>';

		$sub = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02" ' . $ns . '>'
			. '<resources><object id="1" type="model"><mesh><vertices>'
			. '<vertex x="0" y="0" z="0"/><vertex x="10" y="0" z="0"/>'
			. '<vertex x="10" y="5" z="0"/><vertex x="0" y="5" z="0"/>'
			. '</vertices><triangles><triangle v1="0" v2="1" v3="2"/><triangle v1="0" v2="2" v3="3"/></triangles>'
			. '</mesh></object></resources></model>';

		$path = $this->zip_3mf(
			array(
				'3D/3dmodel.model'      => $root,
				'3D/Objects/object_1.model' => $sub,
			)
		);

		$hull = MeshOutline::xy_hull_from_file( $path );
		$bbox = Polygon::bounding_box( $hull );

		// Der 10x5-Quader (X x Y) steht nach der 90°-Drehung als 5x10 da
		// (Ausdehnungen vertauscht) und um 100 in X verschoben.
		$this->assertEqualsWithDelta( 5.0, $bbox['maxx'] - $bbox['minx'], 0.001 );
		$this->assertEqualsWithDelta( 10.0, $bbox['maxy'] - $bbox['miny'], 0.001 );
		$this->assertEqualsWithDelta( 95.0, $bbox['minx'], 0.001 );
		$this->assertEqualsWithDelta( 0.0, $bbox['miny'], 0.001 );
	}

	/**
	 * Ohne auflösbare Geometrie (weder eigener Mesh-Block noch eine
	 * auflösbare Komponente) bleibt es bei null statt eines Fehlers.
	 */
	public function test_mesh_outline_from_file_ohne_geometrie_ist_null() {
		$this->assertNull( MeshOutline::xy_hull_from_file( '/pfad/existiert/nicht.3mf' ) );

		$root = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">'
			. '<resources><object id="1" type="model"></object></resources><build><item objectid="1"/></build></model>';
		$path = $this->zip_3mf( array( '3D/3dmodel.model' => $root ) );

		$this->assertNull( MeshOutline::xy_hull_from_file( $path ) );
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

	/**
	 * Ohne Drehung rechnet sich ein eigener Nullpunkt-Versatz ($ox,$oy)
	 * direkt aus der Zielposition heraus: wendet man die Matrix auf einen
	 * Punkt an, der genau am eigenen Nullpunkt liegt (x=$ox, y=$oy), muss
	 * exakt die Zielposition ($tx,$ty) herauskommen (siehe Klassendoku
	 * "Nullpunkt-Versatz" - genau der vom Nutzer live gemeldete Fall:
	 * "ein paar Teile liegen ausserhalb der Platte").
	 */
	public function test_transform_matrix_nullpunkt_versatz_ohne_drehung() {
		$matrix  = PlateComposer::transform_matrix( 0, 5, 5, 0, 100, 50 );
		$numbers = array_map( 'floatval', explode( ' ', $matrix ) );

		// m30, m31 (Index 9, 10): Punkt (100,50) transformiert muss (5,5) ergeben.
		$x = 100 * $numbers[0] + 50 * $numbers[3] + $numbers[9];
		$y = 100 * $numbers[1] + 50 * $numbers[4] + $numbers[10];

		$this->assertEqualsWithDelta( 5.0, $x, 0.0001 );
		$this->assertEqualsWithDelta( 5.0, $y, 0.0001 );
	}

	/**
	 * Mit 90°-Drehung UND Nullpunkt-Versatz zusammen: derselbe Punkt
	 * (eigener Nullpunkt) muss nach Drehung + Verschiebung trotzdem genau
	 * auf der Zielposition landen - die Reihenfolge (erst Versatz
	 * herausrechnen, dann drehen, dann verschieben) muss stimmen.
	 */
	public function test_transform_matrix_nullpunkt_versatz_mit_drehung() {
		$matrix  = PlateComposer::transform_matrix( 90, 5, 5, 0, 100, 50 );
		$numbers = array_map( 'floatval', explode( ' ', $matrix ) );

		$x = 100 * $numbers[0] + 50 * $numbers[3] + $numbers[9];
		$y = 100 * $numbers[1] + 50 * $numbers[4] + $numbers[10];

		$this->assertEqualsWithDelta( 5.0, $x, 0.0001 );
		$this->assertEqualsWithDelta( 5.0, $y, 0.0001 );
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

	/**
	 * Wie write_cuboid_3mf(), aber mit einem Mesh-Nullpunkt, der NICHT an
	 * der eigenen Bounding-Box-Ecke liegt (Vertices von ($ox,$oy) bis
	 * ($ox+$w,$oy+$d)) - der Regelfall bei echten Exporten (siehe
	 * Klassendoku "Nullpunkt-Versatz" von PlateComposer) und genau der vom
	 * Nutzer live gemeldete Fall ("ein paar Teile liegen ausserhalb der
	 * Platte").
	 *
	 * @param string $path Zielpfad.
	 * @param float  $w    Breite.
	 * @param float  $d    Tiefe.
	 * @param float  $ox   Eigener Nullpunkt-Versatz X.
	 * @param float  $oy   Eigener Nullpunkt-Versatz Y.
	 */
	protected function write_offset_cuboid_3mf( $path, $w, $d, $ox, $oy ) {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">'
			. '<resources><object id="1" type="model"><mesh><vertices>'
			. "<vertex x=\"$ox\" y=\"$oy\" z=\"0\"/><vertex x=\"" . ( $ox + $w ) . "\" y=\"$oy\" z=\"0\"/>"
			. "<vertex x=\"" . ( $ox + $w ) . "\" y=\"" . ( $oy + $d ) . "\" z=\"0\"/><vertex x=\"$ox\" y=\"" . ( $oy + $d ) . '" z="0"/>'
			. '</vertices><triangles><triangle v1="0" v2="1" v3="2"/><triangle v1="0" v2="2" v3="3"/></triangles>'
			. '</mesh></object></resources><build><item objectid="1"/></build></model>';

		$zip = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml"/></Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rel0" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel" Target="/3D/3dmodel.model"/></Relationships>' );
		$zip->addFromString( '3D/3dmodel.model', $xml );
		$zip->close();
	}

	/**
	 * Ein Bauteil, dessen Mesh weit ausserhalb des eigenen Nullpunkts
	 * liegt (wie bei echten Exporten üblich), muss nach dem Zusammensetzen
	 * trotzdem genau an der von BinPacker vorgegebenen Position landen -
	 * nicht um seinen eigenen Versatz daneben (der vom Nutzer live
	 * gemeldete Fall: "ein paar Teile liegen ausserhalb der Platte").
	 */
	public function test_plate_composer_platziert_bauteil_mit_fremdem_nullpunkt_richtig() {
		$dir = $this->fixture_dir();
		// Das Mesh liegt lokal bei X=100..130, Y=50..70 - weit weg von
		// (0,0). Ohne Korrektur würde das Bauteil bei der Zielposition
		// (5,5) plus diesem Versatz landen, also bei (105,55).
		$this->write_offset_cuboid_3mf( $dir . '/versetzt.3mf', 30, 20, 100, 50 );

		$placements = array(
			array( 'source_path' => $dir . '/versetzt.3mf', 'x' => 5, 'y' => 5, 'rotation_deg' => 0 ),
		);

		$out    = $dir . '/combined-versetzt.3mf';
		$result = PlateComposer::compose( $placements, $out );

		$this->assertTrue( $result['ok'], $result['error'] );

		$zip = new \ZipArchive();
		$zip->open( $out );
		$xml = $zip->getFromName( '3D/3dmodel.model' );
		$zip->close();

		$doc  = new \DOMDocument();
		$doc->loadXML( $xml );
		$item = $doc->getElementsByTagName( 'item' )->item( 0 );

		$m = array_map( 'floatval', explode( ' ', $item->getAttribute( 'transform' ) ) );

		// Rohe Vertices (100,50) und (130,70) durch die Matrix schicken -
		// muss exakt auf (5,5)..(35,25) landen, nicht auf (105,55)..(135,75).
		$transform = static function ( $x, $y ) use ( $m ) {
			return array( $x * $m[0] + $y * $m[3] + $m[9], $x * $m[1] + $y * $m[4] + $m[10] );
		};

		list( $minx, $miny ) = $transform( 100, 50 );
		list( $maxx, $maxy ) = $transform( 130, 70 );

		$this->assertEqualsWithDelta( 5.0, $minx, 0.001 );
		$this->assertEqualsWithDelta( 5.0, $miny, 0.001 );
		$this->assertEqualsWithDelta( 35.0, $maxx, 0.001 );
		$this->assertEqualsWithDelta( 25.0, $maxy, 0.001 );
	}

	/**
	 * Eine 3MF mit Geometrie in einer ausgelagerten Objektdatei (Bambu-
	 * Studio-Production-Extension, siehe MeshOutline) anlegen – dieselbe
	 * Struktur wie beim Nutzer live gemeldet ("Keine Platte konnte
	 * gespeichert werden", weil compose() das Objekt bisher als
	 * unauflösbare Unterbaugruppe ablehnte).
	 *
	 * @param string $path Zielpfad.
	 * @param float  $w    Breite.
	 * @param float  $d    Tiefe.
	 */
	protected function write_component_3mf( $path, $w, $d ) {
		$ns   = 'xmlns:p="http://schemas.microsoft.com/3dmanufacturing/production/2015/06"';
		$root = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02" ' . $ns . ' requiredextensions="p">'
			. '<resources><object id="2" type="model"><components>'
			. '<component p:path="/3D/Objects/object_8.model" objectid="1" transform="1 0 0 0 1 0 0 0 1 0 0 0"/>'
			. '</components></object></resources>'
			. '<build><item objectid="2"/></build></model>';

		$sub = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02" ' . $ns . '>'
			. '<resources><object id="1" type="model"><mesh><vertices>'
			. "<vertex x=\"0\" y=\"0\" z=\"0\"/><vertex x=\"$w\" y=\"0\" z=\"0\"/>"
			. "<vertex x=\"$w\" y=\"$d\" z=\"0\"/><vertex x=\"0\" y=\"$d\" z=\"0\"/>"
			. '</vertices><triangles><triangle v1="0" v2="1" v3="2"/><triangle v1="0" v2="2" v3="3"/></triangles>'
			. '</mesh></object></resources></model>';

		$zip = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml"/></Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rel0" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel" Target="/3D/3dmodel.model"/></Relationships>' );
		$zip->addFromString( '3D/3dmodel.model', $root );
		$zip->addFromString( '3D/Objects/object_8.model', $sub );
		$zip->close();
	}

	/**
	 * compose() muss eine ausgelagerte Objektdatei (Production Extension)
	 * genauso zusammensetzen können wie ein Bauteil mit eingebettetem
	 * Mesh – als Basis-Platzierung UND als weitere (importierte) Quelle.
	 */
	public function test_plate_composer_loest_ausgelagerte_objektdatei_auf() {
		$dir = $this->fixture_dir();
		$this->write_component_3mf( $dir . '/hmhr.3mf', 30, 20 );
		$this->write_cuboid_3mf( $dir . '/b.3mf', 15, 15 );

		$placements = array(
			array( 'source_path' => $dir . '/hmhr.3mf', 'x' => 5, 'y' => 5, 'rotation_deg' => 0 ),
			array( 'source_path' => $dir . '/b.3mf', 'x' => 40, 'y' => 5, 'rotation_deg' => 0 ),
		);

		$out    = $dir . '/combined-hmhr.3mf';
		$result = PlateComposer::compose( $placements, $out );

		$this->assertTrue( $result['ok'], $result['error'] );

		$zip = new \ZipArchive();
		$zip->open( $out );
		$xml = $zip->getFromName( '3D/3dmodel.model' );
		$zip->close();

		$doc = new \DOMDocument();
		$doc->loadXML( $xml );

		$this->assertSame( 2, $doc->getElementsByTagName( 'object' )->length );
		// Die zusammengesetzte Datei muss ein eingebettetes Mesh haben,
		// keine <components> mehr - die Hülle des ersten Objekts (30x20)
		// muss auslesbar sein.
		$hull = MeshOutline::xy_hull_from_xml( $xml );
		$this->assertEqualsWithDelta( 600.0, Polygon::area( $hull ), 0.001 );
	}

	/**
	 * Verweist die ausgelagerte Komponente auf ein Objekt, das nicht
	 * existiert, bleibt es beim verständlichen Fehler statt einer
	 * kaputten Datei.
	 */
	public function test_plate_composer_ausgelagerte_komponente_ohne_treffer_ist_fehler() {
		$dir  = $this->fixture_dir();
		$path = $dir . '/kaputt.3mf';

		$ns   = 'xmlns:p="http://schemas.microsoft.com/3dmanufacturing/production/2015/06"';
		$root = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02" ' . $ns . ' requiredextensions="p">'
			. '<resources><object id="2" type="model"><components>'
			. '<component p:path="/3D/Objects/fehlt.model" objectid="1" transform="1 0 0 0 1 0 0 0 1 0 0 0"/>'
			. '</components></object></resources>'
			. '<build><item objectid="2"/></build></model>';

		$zip = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml"/></Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rel0" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel" Target="/3D/3dmodel.model"/></Relationships>' );
		$zip->addFromString( '3D/3dmodel.model', $root );
		$zip->close();

		$result = PlateComposer::compose(
			array( array( 'source_path' => $path, 'x' => 0, 'y' => 0, 'rotation_deg' => 0 ) ),
			$dir . '/kaputt-out.3mf'
		);

		$this->assertFalse( $result['ok'] );
		$this->assertNotSame( '', $result['error'] );
	}

	/**
	 * Liegt der tiefste Punkt eines Objekts unter Z=0 (Mesh-Nullpunkt im
	 * geometrischen Zentrum statt an der Unterseite - der vom Nutzer live
	 * gemeldete Fall "Modelle liegen unter statt auf der Platte"), muss
	 * compose() das Objekt genau um diesen Betrag anheben.
	 */
	public function test_plate_composer_hebt_objekt_mit_negativem_z_auf_die_druckplatte() {
		$dir = $this->fixture_dir();
		$this->write_component_3mf_mit_z( $dir . '/tief.3mf', 30, 20, -18.5 );

		$placements = array(
			array( 'source_path' => $dir . '/tief.3mf', 'x' => 5, 'y' => 5, 'rotation_deg' => 0 ),
		);

		$out    = $dir . '/combined-tief.3mf';
		$result = PlateComposer::compose( $placements, $out );

		$this->assertTrue( $result['ok'], $result['error'] );

		$zip = new \ZipArchive();
		$zip->open( $out );
		$xml = $zip->getFromName( '3D/3dmodel.model' );
		$zip->close();

		$doc  = new \DOMDocument();
		$doc->loadXML( $xml );
		$item = $doc->getElementsByTagName( 'item' )->item( 0 );
		$m    = array_map( 'floatval', explode( ' ', $item->getAttribute( 'transform' ) ) );

		// Ohne Anhebung läge das Objekt bei Z=-18.5 (unter der
		// Druckplatte) - m32 (Index 11) muss das exakt ausgleichen.
		$this->assertEqualsWithDelta( 18.5, $m[11], 0.001 );
	}

	/**
	 * Wie write_component_3mf(), aber mit wählbarem Z statt fest 0 - fürs
	 * Prüfen des Z-Versatzes.
	 *
	 * @param string $path Zielpfad.
	 * @param float  $w    Breite.
	 * @param float  $d    Tiefe.
	 * @param float  $z    Z-Koordinate aller Vertices.
	 */
	protected function write_component_3mf_mit_z( $path, $w, $d, $z ) {
		$ns   = 'xmlns:p="http://schemas.microsoft.com/3dmanufacturing/production/2015/06"';
		$root = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02" ' . $ns . ' requiredextensions="p">'
			. '<resources><object id="2" type="model"><components>'
			. '<component p:path="/3D/Objects/object_8.model" objectid="1" transform="1 0 0 0 1 0 0 0 1 0 0 0"/>'
			. '</components></object></resources>'
			. '<build><item objectid="2"/></build></model>';

		$sub = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02" ' . $ns . '>'
			. '<resources><object id="1" type="model"><mesh><vertices>'
			. "<vertex x=\"0\" y=\"0\" z=\"$z\"/><vertex x=\"$w\" y=\"0\" z=\"$z\"/>"
			. "<vertex x=\"$w\" y=\"$d\" z=\"$z\"/><vertex x=\"0\" y=\"$d\" z=\"$z\"/>"
			. '</vertices><triangles><triangle v1="0" v2="1" v3="2"/><triangle v1="0" v2="2" v3="3"/></triangles>'
			. '</mesh></object></resources></model>';

		$zip = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml"/></Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rel0" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel" Target="/3D/3dmodel.model"/></Relationships>' );
		$zip->addFromString( '3D/3dmodel.model', $root );
		$zip->addFromString( '3D/Objects/object_8.model', $sub );
		$zip->close();
	}

	/**
	 * Der beim vorigen Fix (#54) behobene "zip zu früh geschlossen"-Bug
	 * betraf nur die BASIS-Platzierung (erste in der Liste) - dieser Test
	 * prüft ausdrücklich die ausgelagerte Objektdatei als ZWEITE
	 * (importierte) Quelle, wo das ZIP-Handle zum Zeitpunkt des
	 * p:path-Zugriffs schon geschlossen gewesen wäre.
	 */
	public function test_plate_composer_loest_ausgelagerte_objektdatei_als_zweite_quelle_auf() {
		$dir = $this->fixture_dir();
		$this->write_cuboid_3mf( $dir . '/a.3mf', 15, 15 );
		$this->write_component_3mf( $dir . '/hmhr.3mf', 30, 20 );

		$placements = array(
			array( 'source_path' => $dir . '/a.3mf', 'x' => 5, 'y' => 5, 'rotation_deg' => 0 ),
			array( 'source_path' => $dir . '/hmhr.3mf', 'x' => 40, 'y' => 5, 'rotation_deg' => 0 ),
		);

		$out    = $dir . '/combined-hmhr-zweite.3mf';
		$result = PlateComposer::compose( $placements, $out );

		$this->assertTrue( $result['ok'], $result['error'] );
	}
}
