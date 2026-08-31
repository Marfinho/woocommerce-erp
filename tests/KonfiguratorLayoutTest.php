<?php
/**
 * Die Regeln des Hakenaufbaus.
 *
 * Geprüft wird, was der Server beim Absenden entscheidet: Überlappungen,
 * Sperrbereiche, Grenzen der Schiene und das Finden freier Plätze. Der
 * Viewer bildet dieselben Regeln ab; wenn hier etwas rot wird, würde er
 * dem Kunden einen Haken zeigen, den er nicht bestellen kann.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Configurator\Layout;

/**
 * Prüft Anordnung und Grenzen.
 */
class KonfiguratorLayoutTest extends TestCase {

	/**
	 * Stammdaten: eine Schiene von 600 mm mit zwei Verschraubungen und
	 * zwei Modulen unterschiedlicher Höhe.
	 *
	 * @return array
	 */
	protected function kontext() {
		return array(
			'parts'  => array(
				1 => array(
					'role'        => 'basis',
					'name'        => 'Basismodul 600',
					'height_mm'   => 600.0,
					'max_modules' => 0,
					'is_active'   => 1,
				),
				2 => array(
					'role'        => 'modul',
					'name'        => 'Haken kurz',
					'height_mm'   => 80.0,
					'max_modules' => 0,
					'is_active'   => 1,
				),
				3 => array(
					'role'        => 'modul',
					'name'        => 'Ablage',
					'height_mm'   => 150.0,
					'max_modules' => 0,
					'is_active'   => 1,
				),
				4 => array(
					'role'        => 'modul',
					'name'        => 'Stillgelegt',
					'height_mm'   => 50.0,
					'max_modules' => 0,
					'is_active'   => 0,
				),
			),
			'blocks' => array(
				1 => array(
					array(
						'from_mm' => 40.0,
						'to_mm'   => 90.0,
						'label'   => 'Wandbefestigung unten',
					),
					array(
						'from_mm' => 500.0,
						'to_mm'   => 550.0,
						'label'   => 'Wandbefestigung oben',
					),
				),
			),
		);
	}

	/**
	 * Eine saubere Anordnung geht durch und kommt sortiert zurück.
	 */
	public function test_gueltige_anordnung() {
		$result = Layout::validate(
			array(
				'base'    => 1,
				'qty'     => 2,
				'modules' => array(
					array( 'article' => 3, 'pos' => 300 ),
					array( 'article' => 2, 'pos' => 100 ),
				),
			),
			$this->kontext()
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertCount( 2, $result['modules'] );
		$this->assertSame( 2, $result['modules'][0]['article'] );
		$this->assertSame( 3, $result['modules'][1]['article'] );
	}

	/**
	 * Zwei Module dürfen nicht ineinander stehen.
	 */
	public function test_ueberlappung_wird_gemeldet() {
		$result = Layout::validate(
			array(
				'base'    => 1,
				'modules' => array(
					array( 'article' => 2, 'pos' => 100 ),
					array( 'article' => 3, 'pos' => 150 ),
				),
			),
			$this->kontext()
		);

		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'überschneiden', $result['errors'][0] );
	}

	/**
	 * Bündig übereinander ist keine Überlappung.
	 */
	public function test_buendig_ist_erlaubt() {
		$result = Layout::validate(
			array(
				'base'    => 1,
				'modules' => array(
					array( 'article' => 2, 'pos' => 100 ),
					array( 'article' => 3, 'pos' => 180 ),
				),
			),
			$this->kontext()
		);

		$this->assertSame( array(), $result['errors'] );
	}

	/**
	 * Auf der Wandbefestigung sitzt kein Modul.
	 */
	public function test_sperrbereich_wird_gemeldet() {
		$result = Layout::validate(
			array(
				'base'    => 1,
				'modules' => array( array( 'article' => 2, 'pos' => 60 ) ),
			),
			$this->kontext()
		);

		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'Wandbefestigung unten', $result['errors'][0] );
	}

	/**
	 * Ein Modul, das den Sperrbereich überspannt, sitzt ebenfalls darauf.
	 */
	public function test_sperrbereich_wird_auch_ueberspannt_gemeldet() {
		$result = Layout::validate(
			array(
				'base'    => 1,
				'modules' => array( array( 'article' => 3, 'pos' => 20 ) ),
			),
			$this->kontext()
		);

		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Direkt unter und über einem Sperrbereich ist Platz.
	 */
	public function test_neben_dem_sperrbereich_ist_platz() {
		$result = Layout::validate(
			array(
				'base'    => 1,
				'modules' => array(
					array( 'article' => 2, 'pos' => 90 ),
					array( 'article' => 3, 'pos' => 350 ),
				),
			),
			$this->kontext()
		);

		$this->assertSame( array(), $result['errors'] );
	}

	/**
	 * Über die Schiene hinaus geht nichts.
	 */
	public function test_ueberstand_wird_gemeldet() {
		$result = Layout::validate(
			array(
				'base'    => 1,
				'modules' => array( array( 'article' => 3, 'pos' => 480 ) ),
			),
			$this->kontext()
		);

		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Ein stillgelegtes Modul ist kein Angebot mehr.
	 */
	public function test_stillgelegtes_modul_faellt_auf() {
		$result = Layout::validate(
			array(
				'base'    => 1,
				'modules' => array( array( 'article' => 4, 'pos' => 200 ) ),
			),
			$this->kontext()
		);

		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Ohne Basismodul gibt es nichts zu prüfen.
	 */
	public function test_ohne_basis_kein_haken() {
		$result = Layout::validate( array( 'base' => 2 ), $this->kontext() );

		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( array(), $result['modules'] );
	}

	/**
	 * Das Basismodul allein ist eine gültige Bestellung.
	 */
	public function test_basis_allein_ist_gueltig() {
		$result = Layout::validate(
			array(
				'base'    => 1,
				'modules' => array(),
			),
			$this->kontext()
		);

		$this->assertSame( array(), $result['errors'] );
	}

	/**
	 * Die Modulzahl des Basismoduls wird eingehalten.
	 */
	public function test_hoechstzahl_am_basismodul() {
		$kontext = $this->kontext();
		$kontext['parts'][1]['max_modules'] = 1;

		$result = Layout::validate(
			array(
				'base'    => 1,
				'modules' => array(
					array( 'article' => 2, 'pos' => 100 ),
					array( 'article' => 2, 'pos' => 200 ),
				),
			),
			$kontext
		);

		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Die Stückzahl hat Grenzen.
	 */
	public function test_stueckzahl_wird_begrenzt() {
		$result = Layout::validate(
			array(
				'base'    => 1,
				'qty'     => 0,
				'modules' => array(),
			),
			$this->kontext()
		);

		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Die erste freie Stelle liegt unten – aber nicht im Sperrbereich.
	 */
	public function test_erste_freie_position() {
		$blocks = $this->kontext()['blocks'][1];

		$this->assertSame( 0.0, Layout::first_free_position( 40, 600, array(), $blocks ) );

		// 80 mm passen nicht mehr zwischen Unterkante und Verschraubung
		// bei 40 mm, also geht es erst oberhalb weiter.
		$this->assertSame( 90.0, Layout::first_free_position( 80, 600, array(), $blocks ) );
	}

	/**
	 * Belegte Plätze werden übersprungen.
	 */
	public function test_erste_freie_position_hinter_einem_modul() {
		$blocks  = $this->kontext()['blocks'][1];
		$belegt  = array( array( 'pos' => 90.0, 'height' => 80.0 ) );

		$this->assertSame( 170.0, Layout::first_free_position( 80, 600, $belegt, $blocks ) );
	}

	/**
	 * Passt gar nichts mehr, sagt die Suche das auch.
	 */
	public function test_kein_platz_mehr() {
		$this->assertNull( Layout::first_free_position( 700, 600, array(), array() ) );
	}

	/**
	 * Millimeterangaben bleiben lesbar.
	 */
	public function test_millimeter_werden_knapp_geschrieben() {
		$this->assertSame( '120', Layout::format_mm( 120.0 ) );
		$this->assertSame( '120,5', Layout::format_mm( 120.45 ) );
	}

	/**
	 * Die Beschreibung an der Bestellposition nennt Modul und Höhe.
	 */
	public function test_beschreibung_nennt_position() {
		$result = Layout::validate(
			array(
				'base'    => 1,
				'modules' => array( array( 'article' => 2, 'pos' => 210 ) ),
			),
			$this->kontext()
		);

		$this->assertSame( array( 'Haken kurz bei 210 mm' ), Layout::describe( $result['modules'] ) );
	}
}
