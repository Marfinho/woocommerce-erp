<?php
/**
 * Die mitgelieferten Demo-Modelle.
 *
 * Sie sind die einzige Möglichkeit, den Konfigurator zu beurteilen, bevor
 * die eigenen Modelle fertig sind. Deshalb wird festgehalten, dass jede
 * angebotene Vorlage auch wirklich als Datei daliegt und ein glTF ist –
 * ein Eintrag, der ins Leere zeigt, wäre im Bild eine leere Fläche.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Configurator\WebModel;

/**
 * Prüft Verweise und Dateien der Demo-Modelle.
 */
class KonfiguratorDemoModelTest extends TestCase {

	/**
	 * Jede angebotene Vorlage liegt als glTF-Binärdatei vor.
	 */
	public function test_jedes_demo_modell_liegt_da() {
		$verzeichnis = dirname( __DIR__ ) . '/assets/models/demo/';
		$modelle     = WebModel::demo_models();

		$this->assertNotEmpty( $modelle, 'Ohne Vorlagen prüft der Test nichts.' );

		foreach ( array_keys( $modelle ) as $key ) {
			$datei = $verzeichnis . $key . '.glb';

			$this->assertFileExists( $datei );
			$this->assertSame( 'glTF', substr( (string) file_get_contents( $datei ), 0, 4 ), $key . ' ist kein glTF-Binärmodell.' );
		}
	}

	/**
	 * Ein Verweis auf eine Vorlage wird erkannt.
	 */
	public function test_verweis_wird_erkannt() {
		$this->assertSame( 'haken', WebModel::demo_key( 'demo:haken' ) );
		$this->assertSame( 'schiene', WebModel::demo_key( 'demo:schiene' ) );
	}

	/**
	 * Alles andere ist kein Verweis – auch nicht auf Umwegen.
	 *
	 * @dataProvider keine_verweise
	 * @param string $wert Eingabe.
	 */
	public function test_kein_verweis( $wert ) {
		$this->assertSame( '', WebModel::demo_key( $wert ) );
	}

	/**
	 * Werte, die keine Vorlage bezeichnen.
	 *
	 * @return array
	 */
	public function keine_verweise() {
		return array(
			'leer'          => array( '' ),
			'hochgeladen'   => array( 'basis-a1b2c3.glb' ),
			'unbekannt'     => array( 'demo:kettensaege' ),
			'pfadwechsel'   => array( 'demo:../../wp-config' ),
			'ohne_kennung'  => array( 'haken' ),
		);
	}

	/**
	 * Eine Vorlage wird nie gelöscht – sie gehört dem Plugin.
	 *
	 * Die Prüfung greift, bevor irgendeine Dateifunktion gerufen wird;
	 * genau deshalb läuft dieser Test ohne WordPress durch.
	 */
	public function test_vorlage_wird_nicht_geloescht() {
		$datei = dirname( __DIR__ ) . '/assets/models/demo/haken.glb';

		WebModel::delete( 'demo:haken' );

		$this->assertFileExists( $datei );
	}
}
