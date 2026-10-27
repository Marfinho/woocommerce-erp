<?php
/**
 * Maße aus dem Modell und Lage der Halter an der Holzstange.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Configurator\GlbMeasure;
use Lindenweg16\Modules\Configurator\Layout;
use PHPUnit\Framework\TestCase;

/**
 * Prüft das Auslesen der Maße und die Halterpositionen.
 */
class KonfiguratorGlbTest extends TestCase {

	/**
	 * Ein Modell in Metern wird in Millimeter umgerechnet.
	 */
	public function test_meter_werden_zu_millimetern() {
		$json = wp_json_encode(
			array(
				'scenes'    => array( array( 'nodes' => array( 0 ) ) ),
				'nodes'     => array( array( 'mesh' => 0 ) ),
				'meshes'    => array( array( 'primitives' => array( array( 'attributes' => array( 'POSITION' => 0 ) ) ) ) ),
				'accessors' => array(
					array(
						'min' => array( -0.014, 0, 0 ),
						'max' => array( 0.014, 0.08, 0.07 ),
					),
				),
			)
		);

		$this->assertSame(
			array(
				'width'  => 28.0,
				'height' => 80.0,
				'depth'  => 70.0,
			),
			GlbMeasure::measure_json( $json )
		);
	}

	/**
	 * Zahlen, die für Meter zu groß sind, gelten als Millimeter.
	 */
	public function test_millimeter_bleiben_millimeter() {
		$json = wp_json_encode(
			array(
				'scenes'    => array( array( 'nodes' => array( 0 ) ) ),
				'nodes'     => array( array( 'mesh' => 0 ) ),
				'meshes'    => array( array( 'primitives' => array( array( 'attributes' => array( 'POSITION' => 0 ) ) ) ) ),
				'accessors' => array(
					array(
						'min' => array( -10, -25, -22.5 ),
						'max' => array( 10, 25, 22.5 ),
					),
				),
			)
		);

		$this->assertSame(
			array(
				'width'  => 20.0,
				'height' => 50.0,
				'depth'  => 45.0,
			),
			GlbMeasure::measure_json( $json )
		);
	}

	/**
	 * Eine Drehung um 90 Grad vertauscht die Achsen.
	 */
	public function test_drehung_wird_angewendet() {
		$s    = sqrt( 0.5 );
		$json = wp_json_encode(
			array(
				'scenes'    => array( array( 'nodes' => array( 0 ) ) ),
				'nodes'     => array(
					array(
						'mesh'     => 0,
						'rotation' => array( 0, 0, $s, $s ), // 90° um Z: X wird Y.
					),
				),
				'meshes'    => array( array( 'primitives' => array( array( 'attributes' => array( 'POSITION' => 0 ) ) ) ) ),
				'accessors' => array(
					array(
						'min' => array( 0, 0, 0 ),
						'max' => array( 0.1, 0.02, 0.03 ),
					),
				),
			)
		);

		$this->assertSame(
			array(
				'width'  => 20.0,
				'height' => 100.0,
				'depth'  => 30.0,
			),
			GlbMeasure::measure_json( $json )
		);
	}

	/**
	 * Ohne Positionsgrenzen gibt es keine Maße – und keinen Fehler.
	 */
	public function test_ohne_grenzen_keine_masse() {
		$this->assertNull( GlbMeasure::measure_json( '{"nodes":[{"mesh":0}],"meshes":[{"primitives":[{"attributes":{"POSITION":0}}]}],"accessors":[{}]}' ) );
		$this->assertNull( GlbMeasure::measure_json( 'kein json' ) );
	}

	/**
	 * Die mitgelieferten Demo-Modelle messen, was ihre Beschriftung sagt.
	 */
	public function test_demo_modelle_messen_wie_beschriftet() {
		$dir = dirname( __DIR__ ) . '/assets/models/demo/';

		$this->assertSame( 600.0, GlbMeasure::measure_file( $dir . 'schiene.glb' )['height'] );
		$this->assertSame( 80.0, GlbMeasure::measure_file( $dir . 'haken.glb' )['height'] );
		$this->assertSame( 150.0, GlbMeasure::measure_file( $dir . 'ablage.glb' )['height'] );
	}

	/**
	 * Der Halter der Holzstange: 20 breit, 50 hoch, 45 tief.
	 */
	public function test_halter_der_holzstange() {
		$mass = GlbMeasure::measure_file( dirname( __DIR__ ) . '/assets/models/demo/holzstange.glb' );

		$this->assertSame( 20.0, $mass['width'] );
		$this->assertSame( 50.0, $mass['height'] );
		$this->assertSame( 45.0, $mass['depth'] );
	}

	/**
	 * Zwei Halter im Abstand von 900 mm, mittig auf einer 1000-mm-Stange.
	 */
	public function test_halter_mittig_im_abstand_900() {
		$zones = Layout::holder_zones( 1000 );

		$this->assertEquals(
			array(
				array(
					'from' => 25,
					'to'   => 75,
				),
				array(
					'from' => 925,
					'to'   => 975,
				),
			),
			$zones
		);
	}

	/**
	 * Eine kürzere Stange rückt die Halter zusammen, eine längere hält 900 mm.
	 */
	public function test_halter_kurze_und_lange_stange() {
		$kurz = Layout::holder_zones( 600 );

		$this->assertSame( 25.0, (float) $kurz[0]['from'] );
		$this->assertSame( 525.0, (float) $kurz[1]['from'] );
		$this->assertLessThanOrEqual( 600.0, $kurz[1]['to'] );

		$lang = Layout::holder_zones( 1200 );

		$this->assertSame( 900.0, (float) ( $lang[1]['from'] - $lang[0]['from'] ) );
		$this->assertSame( 125.0, (float) $lang[0]['from'] );
	}
}
