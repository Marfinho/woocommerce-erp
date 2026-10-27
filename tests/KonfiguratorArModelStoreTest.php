<?php
/**
 * Annahme der Konfigurationen für die AR-Ansicht.
 *
 * Die Schnittstelle ist öffentlich und nimmt Dateien an. Diese Tests prüfen,
 * dass nur eine in sich geschlossene glTF-Binärdatei durchkommt.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Configurator\ArModelStore;
use PHPUnit\Framework\TestCase;

/**
 * Prüft die Eingangskontrolle.
 */
class KonfiguratorArModelStoreTest extends TestCase {

	/**
	 * Eine minimale, gültige .glb bauen.
	 *
	 * @param array $json Inhalt des JSON-Blocks.
	 * @return string
	 */
	protected function glb( array $json ) {
		$text = json_encode( $json );
		$text .= str_repeat( ' ', ( 4 - strlen( $text ) % 4 ) % 4 );

		return 'glTF' . pack( 'V', 2 ) . pack( 'V', 20 + strlen( $text ) ) . pack( 'V', strlen( $text ) ) . pack( 'V', 0x4E4F534A ) . $text;
	}

	/**
	 * Ein Modell mit allem Nötigen.
	 *
	 * @return array
	 */
	protected function modell() {
		return array(
			'asset'  => array( 'version' => '2.0' ),
			'nodes'  => array( array( 'mesh' => 0 ) ),
			'meshes' => array( array( 'primitives' => array() ) ),
		);
	}

	/**
	 * Eine gültige Datei kommt durch.
	 */
	public function test_gueltige_datei() {
		$this->assertSame( '', ArModelStore::check( $this->glb( $this->modell() ) ) );
	}

	/**
	 * Eingebettete Daten sind erlaubt, Verweise ins Netz nicht.
	 */
	public function test_verweise_ins_netz_werden_abgelehnt() {
		$ok          = $this->modell();
		$ok['buffers'] = array( array( 'uri' => 'data:application/octet-stream;base64,AAAA', 'byteLength' => 3 ) );
		$this->assertSame( '', ArModelStore::check( $this->glb( $ok ) ) );

		$boese          = $this->modell();
		$boese['buffers'] = array( array( 'uri' => 'https://boese.example/x.bin', 'byteLength' => 3 ) );
		$this->assertNotSame( '', ArModelStore::check( $this->glb( $boese ) ) );

		$bild          = $this->modell();
		$bild['images'] = array( array( 'uri' => 'https://boese.example/x.png' ) );
		$this->assertNotSame( '', ArModelStore::check( $this->glb( $bild ) ) );
	}

	/**
	 * Falsche Kennung, falsche Länge, leere Daten und fehlendes Modell werden abgelehnt.
	 */
	public function test_ungueltiges_wird_abgelehnt() {
		$gut = $this->glb( $this->modell() );

		$this->assertNotSame( '', ArModelStore::check( '' ) );
		$this->assertNotSame( '', ArModelStore::check( 'GLTF' . substr( $gut, 4 ) ) );
		$this->assertNotSame( '', ArModelStore::check( $gut . 'xxxx' ), 'Die angegebene Länge muss stimmen.' );
		$this->assertNotSame( '', ArModelStore::check( $this->glb( array( 'asset' => array( 'version' => '2.0' ) ) ) ) );
		$this->assertNotSame( '', ArModelStore::check( str_repeat( 'a', 100 ) ) );
	}

	/**
	 * Zu große Dateien werden abgelehnt.
	 */
	public function test_zu_gross() {
		$this->assertNotSame( '', ArModelStore::check( str_repeat( 'a', ArModelStore::MAX_BYTES + 1 ) ) );
	}

	/**
	 * Der Name ist der Hash des Inhalts: gleich für gleiche Daten, nicht wählbar.
	 */
	public function test_name_folgt_dem_inhalt() {
		$a = $this->glb( $this->modell() );

		$this->assertSame( ArModelStore::file_name( $a ), ArModelStore::file_name( $a ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{40}\.glb$/', ArModelStore::file_name( $a ) );
		$this->assertNotSame( ArModelStore::file_name( $a ), ArModelStore::file_name( $a . ' ' ) );
	}
}
