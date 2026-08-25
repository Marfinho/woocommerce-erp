<?php
/**
 * Prüft das Lesen von 3MF-Dateien.
 *
 * Die Testdateien werden hier erzeugt, nicht mitgeliefert: so ist im Test
 * sichtbar, was erwartet wird.
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
	 * Eine Projektdatei hat eine Position im Build, eine Druckdatei nicht –
	 * daran hängt die ganze Unterscheidung.
	 */
	public function test_ein_modell_hat_eine_position() {
		$doc = ThreeMf::document( $this->box() );

		$this->assertNotNull( $doc );
		$this->assertCount( 1, ThreeMf::items( $doc ) );
	}

	/**
	 * Etwas, das kein 3MF ist, wird als solches erkannt.
	 */
	public function test_fremde_datei_wird_erkannt() {
		$this->assertNull( ThreeMf::document( 'kein XML' ) );
		$this->assertNull( ThreeMf::document( '<html><body /></html>' ) );
	}

	/**
	 * Eine geslicte Druckdatei, wie Bambu Studio sie exportiert: leeres
	 * Modell, dafuer G-Code und die Beilage mit den Slicer-Zahlen.
	 *
	 * @param int $objects Wie viele Teile auf der Platte liegen.
	 * @return string Pfad.
	 */
	protected function sliced_file( $objects = 1 ) {
		$leer = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">'
			. '<resources></resources><build/></model>';

		$liste = '';
		for ( $i = 0; $i < $objects; $i++ ) {
			$liste .= '<object identify_id="' . ( 75 + $i ) . '" name="Brillenhalter.stl" skipped="false" />';
		}

		$info = '<?xml version="1.0" encoding="UTF-8"?><config><plate>'
			. '<metadata key="index" value="1"/>'
			. '<metadata key="printer_model_id" value="BL-P001"/>'
			. '<metadata key="nozzle_diameters" value="0.4"/>'
			. '<metadata key="prediction" value="2335"/>'
			. '<metadata key="weight" value="16.74"/>'
			. $liste
			. '<filament id="1" type="PLA" color="#FFF144" used_m="5.61" used_g="16.74" />'
			. '</plate></config>';

		$path = tempnam( sys_get_temp_dir(), 'lw16' ) . '.3mf';

		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		$zip->addFromString( '[Content_Types].xml', '<Types />' );
		$zip->addFromString( ThreeMf::MODEL_PATH, $leer );
		$zip->addFromString( 'Metadata/plate_1.gcode', "; erzeugt vom Slicer\nG28\n" );
		$zip->addFromString( ThreeMf::SLICE_INFO, $info );
		$zip->addFromString( ThreeMf::PROJECT_MARKER, '<config />' );
		$zip->close();

		$this->temp[] = $path;
		return $path;
	}

	/**
	 * Projektdatei und geslicte Druckdatei werden auseinandergehalten.
	 * Beide heissen .3mf und sehen von aussen gleich aus.
	 */
	public function test_druckdatei_und_modell_werden_unterschieden() {
		$this->assertSame( 'modell', ModelFile::kind( $this->file( $this->box() ) ) );
		$this->assertSame( 'druckdatei', ModelFile::kind( $this->sliced_file() ) );
	}

	/**
	 * Eine Druckdatei wird angenommen – sie ist das, was gedruckt wird.
	 */
	public function test_druckdatei_wird_angenommen() {
		$check = ModelFile::inspect( $this->sliced_file() );

		$this->assertTrue( $check['ok'], $check['message'] );
		$this->assertSame( 'druckdatei', $check['kind'] );
	}

	/**
	 * Eine 3MF ohne Bauteil und ohne G-Code ist leer und wird abgelehnt.
	 */
	public function test_leere_datei_wird_abgelehnt() {
		$leer = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<model unit="millimeter" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">'
			. '<resources></resources><build/></model>';

		$check = ModelFile::inspect( $this->file( $leer ) );

		$this->assertFalse( $check['ok'] );
		$this->assertStringContainsString( 'leer', $check['message'] );
	}

	/**
	 * Die Zahlen des Slicers werden gelesen.
	 */
	public function test_die_slicer_zahlen_werden_gelesen() {
		$info = ModelFile::slice_info( $this->sliced_file() );

		$this->assertSame( 2335, $info['seconds'] );
		$this->assertSame( 16.74, $info['weight_g'] );
		$this->assertSame( 1, $info['parts'] );
		$this->assertSame( 'PLA', $info['material'] );
		$this->assertSame( 0.4, $info['nozzle_mm'] );
		$this->assertSame( 'BL-P001', $info['printer'] );
	}

	/**
	 * Der Slicer nennt Dauer und Verbrauch fuer die ganze Platte, die
	 * Revision fuehrt das Gewicht je Teil. Ohne die Division waere jede
	 * Kalkulation um den Faktor der Stueckzahl daneben.
	 */
	public function test_plattenwerte_werden_auf_das_einzelteil_gerechnet() {
		$eins = ThreeMf::revision_values( ModelFile::slice_info( $this->sliced_file( 1 ) ) );
		$vier = ThreeMf::revision_values( ModelFile::slice_info( $this->sliced_file( 4 ) ) );

		// Die Druckzeit gilt fuer die Platte und bleibt deshalb gleich.
		$this->assertSame( 0.649, $eins['print_time_plate_h'] );
		$this->assertSame( 0.649, $vier['print_time_plate_h'] );

		$this->assertSame( 16.74, $eins['weight_g'] );
		$this->assertSame( 1, $eins['parts_per_plate'] );

		$this->assertSame( 4.185, $vier['weight_g'] );
		$this->assertSame( 4, $vier['parts_per_plate'] );
	}

	/**
	 * Fehlende Angaben werden nicht erfunden.
	 */
	public function test_fehlende_slicer_angaben_bleiben_leer() {
		$info = ThreeMf::slice_info( '<config><plate><metadata key="index" value="1"/></plate></config>' );

		$this->assertNull( $info['seconds'] );
		$this->assertNull( $info['weight_g'] );
		$this->assertNull( $info['nozzle_mm'] );
		$this->assertSame( 0, $info['parts'] );
	}

	/**
	 * Kein XML, keine Auskunft.
	 */
	public function test_unlesbare_beilage_ergibt_nichts() {
		$this->assertNull( ThreeMf::slice_info( '' ) );
		$this->assertNull( ThreeMf::slice_info( 'kein XML' ) );
	}

	/**
	 * Mehrfarbige Platten: der Verbrauch summiert sich ueber die Filamente.
	 */
	public function test_mehrere_filamente_werden_summiert() {
		$info = ThreeMf::slice_info(
			'<config><plate><metadata key="prediction" value="3600"/>'
			. '<object name="a.stl" /><object name="b.stl" />'
			. '<filament id="1" type="PETG" used_g="10.0" />'
			. '<filament id="2" type="PETG" used_g="5.5" />'
			. '</plate></config>'
		);

		$this->assertSame( 15.5, $info['weight_g'] );
		$this->assertSame( 2, $info['parts'] );
	}
}
