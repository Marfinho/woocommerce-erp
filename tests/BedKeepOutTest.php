<?php
/**
 * Sperrzonen am vorderen Bettrand (Lade- und Kalibrierlinien der X1/P1).
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\Nesting\BedKeepOut;
use Lindenweg16\Modules\Betrieb\Nesting\BinPacker;
use Lindenweg16\Modules\Betrieb\Nesting\Polygon;
use Lindenweg16\Modules\Betrieb\Nesting\RasterPacker;
use Lindenweg16\Modules\Betrieb\Nesting\Silhouette;

/**
 * Prüft Erkennung, Zonen, Profil-Ergänzung und die Wirkung auf die Anordnung.
 */
class BedKeepOutTest extends TestCase {

	/**
	 * X1/P1 als Kurz- wie als Profilname; A1 und andere nicht.
	 */
	public function test_erkennt_x1_und_p1() {
		foreach ( array( 'X1C', 'x1c', 'X1E', 'X1', 'P1S', 'P1P', 'Bambu Lab X1 Carbon', 'Bambu Lab X1 Carbon 0.4 nozzle', 'Bambu Lab P1S 0.4 nozzle' ) as $model ) {
			$this->assertTrue( BedKeepOut::applies_to( $model ), $model );
		}
		foreach ( array( '', 'A1', 'A1 mini', 'H2D', 'X10', 'Prusa MK4' ) as $model ) {
			$this->assertFalse( BedKeepOut::applies_to( $model ), $model );
		}
	}

	/**
	 * Für die Anordnung: Sperrecke und vorderer Streifen als getrennte Rechtecke.
	 */
	public function test_zonen_sind_ecke_und_streifen() {
		$zones = BedKeepOut::zones( 'X1C', 256 );

		$this->assertCount( 2, $zones );
		$this->assertSame( array( 18.0, 28.0 ), array( max( array_column( $zones[0], 0 ) ), max( array_column( $zones[0], 1 ) ) ) );
		$this->assertSame( array( 256.0, 15.0 ), array( max( array_column( $zones[1], 0 ) ), max( array_column( $zones[1], 1 ) ) ) );
		$this->assertSame( array(), BedKeepOut::zones( 'A1', 256 ) );
	}

	/**
	 * Für OrcaSlicer: ein einziges L-förmiges Polygon.
	 */
	public function test_sperrflaeche_fuer_orcaslicer_ist_ein_l() {
		$this->assertSame(
			array( '0x0', '256x0', '256x15', '18x15', '18x28', '0x28' ),
			BedKeepOut::exclude_area( 'X1C', 256 )
		);
		$this->assertSame( array(), BedKeepOut::exclude_area( 'A1', 256 ) );
	}

	/**
	 * Ein Differenzprofil, das vom X1C erbt, bekommt die Sperrfläche; die
	 * übrigen Werte – auch ein leeres Objekt – bleiben erhalten.
	 */
	public function test_differenzprofil_bekommt_die_sperrflaeche() {
		$json = '{"inherits":"Bambu Lab X1 Carbon 0.4 nozzle","name":"Meins","extra":{},"printable_area":["0x0","256x0","256x256","0x256"]}';
		$out  = json_decode( BedKeepOut::apply_to_profile( $json ), true );

		$this->assertSame( array( '0x0', '256x0', '256x15', '18x15', '18x28', '0x28' ), $out['bed_exclude_area'] );
		$this->assertSame( 'Meins', $out['name'] );
		$this->assertStringContainsString( '"extra":{}', str_replace( array( ' ', "\n" ), '', BedKeepOut::apply_to_profile( $json ) ) );
	}

	/**
	 * Die übliche Sperrecke aus dem Profil liegt ganz in unseren Zonen und
	 * wird ersetzt.
	 */
	public function test_uebliche_sperrecke_wird_ersetzt() {
		$json = '{"printer_model":"Bambu Lab X1 Carbon","bed_exclude_area":["0x0","18x0","18x28","0x28"]}';
		$out  = json_decode( BedKeepOut::apply_to_profile( $json ), true );

		$this->assertCount( 6, $out['bed_exclude_area'] );
	}

	/**
	 * Eine eigene, abweichende Sperrfläche bleibt unangetastet.
	 */
	public function test_eigene_sperrflaeche_bleibt() {
		$json = '{"printer_model":"Bambu Lab X1 Carbon","bed_exclude_area":["200x200","256x200","256x256","200x256"]}';

		$this->assertSame( $json, BedKeepOut::apply_to_profile( $json ) );
	}

	/**
	 * Andere Drucker und kaputte Dateien bleiben, wie sie sind.
	 */
	public function test_andere_drucker_bleiben_unveraendert() {
		$a1 = '{"printer_model":"Bambu Lab A1"}';

		$this->assertSame( $a1, BedKeepOut::apply_to_profile( $a1 ) );
		$this->assertSame( 'kein json', BedKeepOut::apply_to_profile( 'kein json' ) );
	}

	/**
	 * Das Modell des Bambu-Druckers zählt, auch wenn das Profil keins nennt.
	 */
	public function test_modell_des_druckers_reicht() {
		$out = json_decode( BedKeepOut::apply_to_profile( '{"name":"Mein Drucker"}', 'X1C', 256 ), true );

		$this->assertSame( '256x15', $out['bed_exclude_area'][2] );
	}

	/**
	 * Die Anordnung legt kein Teil mehr auf den vorderen Streifen – bisher
	 * landete das erste Teil vorn links, genau auf der Ladelinie.
	 */
	public function test_anordnung_meidet_den_vorderen_streifen() {
		$square = array( array( 0, 0 ), array( 30, 0 ), array( 30, 30 ), array( 0, 30 ) );
		$parts  = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$parts[] = array( 'key' => 'p' . $i, 'hull' => $square );
		}

		$result = BinPacker::arrange( $parts, 256, 256, 2.0, array( 0 ), BedKeepOut::zones( 'X1C', 256 ) );

		$this->assertNotEmpty( $result['bins'] );
		foreach ( $result['bins'] as $bin ) {
			foreach ( $bin['placements'] as $placement ) {
				$bbox = Polygon::bounding_box( Polygon::place( $square, $placement['rotation_deg'], $placement['x'], $placement['y'] ) );
				$this->assertGreaterThanOrEqual( 15.0, $bbox['miny'], "Teil {$placement['key']} liegt auf dem vorderen Streifen." );
			}
		}
	}

	/**
	 * Dasselbe für den Raster-Packer, den die Plattenanordnung zuerst versucht.
	 */
	public function test_raster_anordnung_meidet_den_vorderen_streifen() {
		$square = array( array( 0, 0 ), array( 30, 0 ), array( 30, 30 ), array( 0, 30 ) );
		$sil    = Silhouette::from_hull( $square );
		$parts  = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$parts[] = array( 'key' => 'p' . $i, 'sil' => $sil );
		}

		$result = RasterPacker::arrange( $parts, 256, 256, 2.0, array( 0 ), BedKeepOut::zones( 'X1C', 256 ) );

		$this->assertNotEmpty( $result['bins'] );
		foreach ( $result['bins'] as $bin ) {
			foreach ( $bin['placements'] as $placement ) {
				$bbox = Polygon::bounding_box( Polygon::place( $square, $placement['rotation_deg'], $placement['x'], $placement['y'] ) );
				$this->assertGreaterThanOrEqual( 15.0, $bbox['miny'], "Teil {$placement['key']} liegt auf dem vorderen Streifen." );
			}
		}
	}
}
