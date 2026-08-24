<?php
/**
 * Prüft das Lesen und Vervielfältigen von 3MF-Modellen.
 *
 * Die Testdateien werden hier erzeugt, nicht mitgeliefert: so ist im Test
 * sichtbar, welche Geometrie erwartet wird.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\ModelFile;
use Lindenweg16\Modules\Betrieb\ThreeMf;
use PHPUnit\Framework\TestCase;

/**
 * 3MF-Tests.
 */
class BetriebThreeMfTest extends TestCase {

	/**
	 * Angelegte Dateien, die nach dem Test wieder verschwinden.
	 *
	 * @var array
	 */
	protected $temp = array();

	/**
	 * Aufräumen.
	 */
	protected function tearDown(): void {
		foreach ( $this->temp as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp = array();
	}

	/**
	 * Ein Quader als Modell-XML.
	 *
	 * @param float  $width     Breite.
	 * @param float  $depth     Tiefe.
	 * @param float  $height    Höhe.
	 * @param string $transform Transform des Bauteils.
	 * @param int    $items     Anzahl der Positionen im Build.
	 * @return string
	 */
	protected function box( $width = 20.0, $depth = 30.0, $height = 10.0, $transform = '', $items = 1 ) {
		$corners = array(
			array( 0, 0, 0 ),
			array( $width, 0, 0 ),
			array( $width, $depth, 0 ),
			array( 0, $depth, 0 ),
			array( 0, 0, $height ),
			array( $width, 0, $height ),
			array( $width, $depth, $height ),
			array( 0, $depth, $height ),
		);

		$vertices = '';
		foreach ( $corners as $corner ) {
			$vertices .= sprintf( '<vertex x="%s" y="%s" z="%s" />', $corner[0], $corner[1], $corner[2] );
		}

		$attr = '' === $transform ? '' : ' transform="' . $transform . '"';
		$item = '<item objectid="1"' . $attr . ' />';

		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">'
			. '<resources><object id="1" type="model"><mesh><vertices>' . $vertices . '</vertices>'
			. '<triangles><triangle v1="0" v2="1" v3="2" /></triangles></mesh></object></resources>'
			. '<build>' . str_repeat( $item, $items ) . '</build></model>';
	}

	/**
	 * Aus einem Modell-XML eine echte 3MF-Datei bauen.
	 *
	 * @param string $xml Modell.
	 * @return string Pfad.
	 */
	protected function file( $xml ) {
		$path = tempnam( sys_get_temp_dir(), 'lw16' ) . '.3mf';

		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		$zip->addFromString( '[Content_Types].xml', '<Types />' );
		$zip->addFromString( ThreeMf::MODEL_PATH, $xml );
		$zip->close();

		$this->temp[] = $path;
		return $path;
	}

	/**
	 * Die Positionen eines Modells mit ihrem verschobenen Hüllquader.
	 *
	 * @param string $xml Modell.
	 * @return array
	 */
	protected function placements( $xml ) {
		$doc     = ThreeMf::document( $xml );
		$objects = ThreeMf::objects( $doc );

		$out = array();
		foreach ( ThreeMf::items( $doc ) as $item ) {
			$out[] = ThreeMf::bbox(
				$objects,
				$item->getAttribute( 'objectid' ),
				ThreeMf::matrix( $item->getAttribute( 'transform' ) )
			);
		}

		return $out;
	}

	/**
	 * Ein fehlendes oder kaputtes Transform ist die Einheitsmatrix.
	 */
	public function test_matrix_faellt_auf_einheit_zurueck() {
		$this->assertSame( ThreeMf::identity(), ThreeMf::matrix( '' ) );
		$this->assertSame( ThreeMf::identity(), ThreeMf::matrix( '1 2 3' ) );
		$this->assertSame( ThreeMf::identity(), ThreeMf::matrix( '1 0 0 0 1 0 0 0 1 0 0 x' ) );
	}

	/**
	 * Geschrieben und wieder gelesen ergibt dieselbe Matrix.
	 */
	public function test_matrix_ueberlebt_den_umweg_ueber_den_text() {
		$m = array( 1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0, 12.5, -7.25, 0.0 );

		$this->assertSame( $m, ThreeMf::matrix( ThreeMf::matrix_string( $m ) ) );
	}

	/**
	 * Verkettung entspricht: erst innen, dann aussen anwenden.
	 */
	public function test_verkettung_entspricht_zwei_schritten() {
		$dreh    = array( 0.0, 1.0, 0.0, -1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0 );
		$schieb  = ThreeMf::translate( ThreeMf::identity(), 10.0, 5.0 );
		$zusamm  = ThreeMf::compose( $dreh, $schieb );
		$schritt = ThreeMf::apply( $dreh, ...ThreeMf::apply( $schieb, 3.0, 4.0, 0.0 ) );

		$einmal = ThreeMf::apply( $zusamm, 3.0, 4.0, 0.0 );

		foreach ( array( 0, 1, 2 ) as $axis ) {
			$this->assertEqualsWithDelta( $schritt[ $axis ], $einmal[ $axis ], 0.0001 );
		}
	}

	/**
	 * Der Hüllquader misst das Bauteil samt seinem Transform.
	 */
	public function test_huellquader_beruecksichtigt_das_transform() {
		$xml = $this->box( 20.0, 30.0, 10.0, '1 0 0 0 1 0 0 0 1 100 50 0' );
		$box = $this->placements( $xml )[0];

		$this->assertEqualsWithDelta( 100.0, $box[0], 0.0001 );
		$this->assertEqualsWithDelta( 50.0, $box[1], 0.0001 );
		$this->assertEqualsWithDelta( 20.0, $box[3] - $box[0], 0.0001 );
		$this->assertEqualsWithDelta( 30.0, $box[4] - $box[1], 0.0001 );
	}

	/**
	 * Das Raster nennt die Zahl, die tatsächlich passt.
	 */
	public function test_raster_meldet_die_moegliche_stueckzahl() {
		// 100 mm Platte, 40 mm Teil, 10 mm Abstand: zwei je Achse.
		$grid = ThreeMf::grid( 9, 40.0, 40.0, 10.0, 100.0, 100.0 );

		$this->assertFalse( $grid['fits'] );
		$this->assertSame( 4, $grid['max'] );
	}

	/**
	 * Ein Bauteil, das grösser ist als die Platte, wird nicht gerechnet.
	 */
	public function test_zu_grosses_bauteil_wird_abgelehnt() {
		$grid = ThreeMf::grid( 1, 300.0, 40.0, 5.0, 256.0, 256.0 );

		$this->assertFalse( $grid['fits'] );
		$this->assertSame( 0, $grid['max'] );
	}

	/**
	 * Zehn Teile ergeben zehn Positionen – und nur eine Geometrie.
	 */
	public function test_zehn_teile_ergeben_zehn_positionen() {
		$result = ThreeMf::fill_plate( $this->box(), 10, array( 'gap' => 6.0, 'plate_w' => 256.0, 'plate_d' => 256.0 ) );

		$this->assertTrue( $result['success'], $result['message'] );
		$this->assertSame( 10, $result['placed'] );

		$doc = ThreeMf::document( $result['xml'] );
		$this->assertCount( 10, ThreeMf::items( $doc ) );
		$this->assertCount( 1, ThreeMf::objects( $doc ) );
	}

	/**
	 * Kein Teil überlappt ein anderes und keines hängt über die Platte.
	 */
	public function test_die_teile_liegen_frei_auf_der_platte() {
		$result = ThreeMf::fill_plate( $this->box( 20.0, 30.0, 10.0 ), 7, array( 'gap' => 4.0, 'plate_w' => 200.0, 'plate_d' => 200.0 ) );
		$this->assertTrue( $result['success'], $result['message'] );

		$boxes = $this->placements( $result['xml'] );
		$this->assertCount( 7, $boxes );

		foreach ( $boxes as $box ) {
			$this->assertGreaterThanOrEqual( -0.0001, $box[0] );
			$this->assertGreaterThanOrEqual( -0.0001, $box[1] );
			$this->assertLessThanOrEqual( 200.0001, $box[3] );
			$this->assertLessThanOrEqual( 200.0001, $box[4] );
		}

		foreach ( $boxes as $i => $a ) {
			foreach ( $boxes as $j => $b ) {
				if ( $j <= $i ) {
					continue;
				}
				$ueberlappt = $a[0] < $b[3] - 0.0001 && $b[0] < $a[3] - 0.0001
					&& $a[1] < $b[4] - 0.0001 && $b[1] < $a[4] - 0.0001;
				$this->assertFalse( $ueberlappt, sprintf( 'Teil %d und %d überlappen.', $i, $j ) );
			}
		}
	}

	/**
	 * Ein vorhandenes Transform bleibt erhalten: gedrehte Teile bleiben
	 * gedreht, sie werden nur verschoben.
	 */
	public function test_eine_vorhandene_drehung_bleibt_erhalten() {
		$xml    = $this->box( 20.0, 30.0, 10.0, '0 1 0 -1 0 0 0 0 1 0 0 0' );
		$result = ThreeMf::fill_plate( $xml, 4, array( 'gap' => 5.0, 'plate_w' => 200.0, 'plate_d' => 200.0 ) );
		$this->assertTrue( $result['success'], $result['message'] );

		// Gedreht ist das Teil 30 mm breit und 20 mm tief.
		$this->assertEqualsWithDelta( 30.0, $result['size'][0], 0.0001 );
		$this->assertEqualsWithDelta( 20.0, $result['size'][1], 0.0001 );

		foreach ( $this->placements( $result['xml'] ) as $box ) {
			$this->assertEqualsWithDelta( 30.0, $box[3] - $box[0], 0.0001 );
			$this->assertEqualsWithDelta( 20.0, $box[4] - $box[1], 0.0001 );
		}
	}

	/**
	 * Liegen schon mehrere Teile in der Datei, wird nicht geraten.
	 */
	public function test_datei_mit_mehreren_teilen_wird_abgelehnt() {
		$result = ThreeMf::fill_plate( $this->box( 20.0, 30.0, 10.0, '', 3 ), 5 );

		$this->assertFalse( $result['success'] );
		$this->assertNotSame( '', $result['message'] );
	}

	/**
	 * Unsinnige Stückzahlen werden abgewiesen.
	 */
	public function test_stueckzahl_wird_begrenzt() {
		$this->assertFalse( ThreeMf::fill_plate( $this->box(), 0 )['success'] );
		$this->assertFalse( ThreeMf::fill_plate( $this->box(), ThreeMf::MAX_COPIES + 1 )['success'] );
	}

	/**
	 * Etwas, das kein 3MF ist, wird als solches erkannt.
	 */
	public function test_fremde_datei_wird_erkannt() {
		$this->assertNull( ThreeMf::document( 'kein XML' ) );
		$this->assertNull( ThreeMf::document( '<html><body /></html>' ) );
	}

	/**
	 * Der Weg über die echte Datei: Kopie mit zehn Teilen, Vorlage
	 * unverändert.
	 */
	public function test_die_kopie_enthaelt_die_platte_und_die_vorlage_bleibt() {
		$source = $this->file( $this->box() );
		$before = sha1_file( $source );

		$target       = tempnam( sys_get_temp_dir(), 'lw16' ) . '.3mf';
		$this->temp[] = $target;

		$result = ModelFile::fill_plate( $source, $target, 10, array( 'gap' => 6.0, 'plate_w' => 256.0, 'plate_d' => 256.0 ) );

		$this->assertTrue( $result['success'], $result['message'] );
		$this->assertSame( $before, sha1_file( $source ) );

		$doc = ThreeMf::document( ModelFile::read_model( $target ) );
		$this->assertNotNull( $doc );
		$this->assertCount( 10, ThreeMf::items( $doc ) );
	}

	/**
	 * Eine Projektdatei aus Bambu Studio ist an ihrer Beilage erkennbar.
	 */
	public function test_projektdatei_wird_erkannt() {
		$plain = $this->file( $this->box() );
		$this->assertFalse( ModelFile::is_project( $plain ) );

		$zip = new ZipArchive();
		$zip->open( $plain );
		$zip->addFromString( ThreeMf::PROJECT_MARKER, '<config />' );
		$zip->close();

		$this->assertTrue( ModelFile::is_project( $plain ) );
	}
}
