<?php
/**
 * Der Konfigurationscode.
 *
 * Er steht in geteilten Links, im versteckten Formularfeld und an der
 * Bestellposition. Zwei Dinge müssen deshalb stimmen: Was der Browser
 * schreibt, muss der Server unverändert zurücklesen – und alles, was
 * nicht genau der Form entspricht, muss abgewiesen werden, statt
 * halbwegs verstanden zu werden.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Configurator\ConfigCodec;

/**
 * Prüft Schreiben und Lesen des Codes.
 */
class KonfiguratorCodecTest extends TestCase {

	/**
	 * Ein Haken mit Modulen.
	 */
	public function test_code_enthaelt_basis_farbe_und_module() {
		$code = ConfigCodec::encode(
			array(
				'base'    => 12,
				'color'   => 'weiss',
				'modules' => array(
					array( 'article' => 34, 'pos' => 0 ),
					array( 'article' => 35, 'pos' => 240.5 ),
				),
			)
		);

		$this->assertSame( 'v1.12.weiss.34@0,35@240.5', $code );
	}

	/**
	 * Ohne Module endet der Code nach der Farbe.
	 */
	public function test_basis_allein() {
		$this->assertSame(
			'v1.12.anthrazit',
			ConfigCodec::encode(
				array(
					'base'    => 12,
					'color'   => 'anthrazit',
					'modules' => array(),
				)
			)
		);
	}

	/**
	 * Ohne Basismodul gibt es keinen Code.
	 */
	public function test_ohne_basis_kein_code() {
		$this->assertSame( '', ConfigCodec::encode( array( 'base' => 0 ) ) );
	}

	/**
	 * Was geschrieben wurde, kommt unverändert zurück.
	 */
	public function test_hin_und_zurueck() {
		$config = array(
			'base'    => 7,
			'color'   => 'schwarz',
			'modules' => array(
				array( 'article' => 8, 'pos' => 12.25 ),
				array( 'article' => 9, 'pos' => 480.0 ),
			),
		);

		$decoded = ConfigCodec::decode( ConfigCodec::encode( $config ) );

		$this->assertSame( 7, $decoded['base'] );
		$this->assertSame( 'schwarz', $decoded['color'] );
		$this->assertSame( 12.25, $decoded['modules'][0]['pos'] );
		$this->assertSame( 9, $decoded['modules'][1]['article'] );
		$this->assertSame( 480.0, $decoded['modules'][1]['pos'] );
	}

	/**
	 * Farb-Slugs mit Bindestrich überstehen die Runde.
	 */
	public function test_farbe_mit_bindestrich() {
		$decoded = ConfigCodec::decode( 'v1.3.matt-schwarz.4@10' );

		$this->assertSame( 'matt-schwarz', $decoded['color'] );
	}

	/**
	 * Alles, was nicht der Form entspricht, wird abgewiesen.
	 *
	 * @dataProvider unfug
	 * @param string $code Eingabe.
	 */
	public function test_unfug_wird_abgewiesen( $code ) {
		$this->assertNull( ConfigCodec::decode( $code ) );
	}

	/**
	 * Eingaben, die kein Code sind.
	 *
	 * @return array
	 */
	public function unfug() {
		return array(
			'leer'              => array( '' ),
			'falsche_fassung'   => array( 'v2.1.weiss' ),
			'ohne_fassung'      => array( '1.weiss.2@0' ),
			'basis_keine_zahl'  => array( 'v1.abc.weiss' ),
			'basis_null'        => array( 'v1.0.weiss' ),
			'modul_ohne_platz'  => array( 'v1.1.weiss.4' ),
			'position_negativ'  => array( 'v1.1.weiss.4@-10' ),
			'position_text'     => array( 'v1.1.weiss.4@oben' ),
			'zu_genau'          => array( 'v1.1.weiss.4@10.12345' ),
			'leeres_modul'      => array( 'v1.1.weiss.4@0,' ),
			'skript'            => array( 'v1.1.<script>.4@0' ),
		);
	}

	/**
	 * Ein überlanger Code wird gar nicht erst gelesen.
	 */
	public function test_ueberlanger_code() {
		$this->assertNull( ConfigCodec::decode( 'v1.1.weiss.' . str_repeat( '4@10,', 500 ) . '4@20' ) );
	}

	/**
	 * Mehr Module als erlaubt sind kein gültiger Code.
	 */
	public function test_zu_viele_module() {
		$teile = array();

		for ( $i = 0; $i <= 40; $i++ ) {
			$teile[] = '4@' . ( $i * 10 );
		}

		$this->assertNull( ConfigCodec::decode( 'v1.1.weiss.' . implode( ',', $teile ) ) );
	}

	/**
	 * Eine fehlende Farbe ist zulässig – dann gilt die Standardfarbe.
	 */
	public function test_ohne_farbe() {
		$decoded = ConfigCodec::decode( 'v1.5.' );

		$this->assertSame( 5, $decoded['base'] );
		$this->assertSame( '', $decoded['color'] );
	}
}
