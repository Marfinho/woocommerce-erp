<?php
/**
 * Spulenverwaltung: Eingabeprüfung und Quelltext-Wächter.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Spools;
use PHPUnit\Framework\TestCase;

class ErpSpoolsTest extends TestCase {

	private $farben = array( 'weiss' => 'Weiß', 'schwarz' => 'Schwarz' );

	public function test_neue_rolle() {
		$r = Spools::validate( array( 'material' => 'petg', 'color' => 'weiss', 'mode' => 'neu', 'grams' => '1000' ), 37, $this->farben );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( 'PETG', $r['data']['material'] );
		$this->assertSame( 1000, $r['data']['grams'] );
		$this->assertFalse( $r['data']['partial'] );
	}

	public function test_angebrochene_rolle_wird_gewogen() {
		$r = Spools::validate( array( 'material' => 'PLA', 'color' => 'schwarz', 'mode' => 'angebrochen', 'gross_g' => '537,5', 'tare_g' => '' ), 37, $this->farben );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( 501, $r['data']['grams'] ); // 537,5 − 37 = 500,5 → 501
		$this->assertTrue( $r['data']['partial'] );

		$r = Spools::validate( array( 'material' => 'PLA', 'color' => 'schwarz', 'mode' => 'angebrochen', 'gross_g' => '1200', 'tare_g' => '215' ), 37, $this->farben );
		$this->assertSame( 985, $r['data']['grams'] );
	}

	public function test_ungueltige_eingaben() {
		$r = Spools::validate( array( 'material' => 'XYZ', 'color' => '', 'mode' => 'neu', 'grams' => 'abc' ), 37, $this->farben );
		$this->assertCount( 3, $r['errors'] );

		$r = Spools::validate( array( 'material' => 'PETG', 'color' => 'rot', 'mode' => 'neu', 'grams' => '1000' ), 37, $this->farben );
		$this->assertCount( 1, $r['errors'], 'Unbekannte Farbe' );

		$r = Spools::validate( array( 'material' => 'PETG', 'color' => 'weiss', 'mode' => 'angebrochen', 'gross_g' => '30' ), 37, $this->farben );
		$this->assertCount( 1, $r['errors'], 'Leichter als die leere Spule' );

		$r = Spools::validate( array( 'material' => 'PETG', 'color' => 'weiss', 'mode' => 'neu', 'grams' => '50000' ), 37, $this->farben );
		$this->assertCount( 1, $r['errors'], 'Zu groß' );

		$r = Spools::validate( array( 'material' => 'PETG', 'color' => 'weiss', 'mode' => 'neu', 'grams' => '0' ), 37, $this->farben );
		$this->assertCount( 1, $r['errors'], 'Null' );
	}

	public function test_quelltext_waechter() {
		$root = dirname( __DIR__ );
		$api  = file_get_contents( $root . '/includes/Erp/RestApi.php' );
		// Spule anlegen verlangt das Recht zum Lagerbuchen, das Etikett das für Etiketten.
		$this->assertMatchesRegularExpression( "#/erp/material/spule'.*?'lager\.material', 'lager\.buchen'#s", $api );
		$this->assertMatchesRegularExpression( "#/erp/material/spule/etikett'.*?'lager\.material', 'verwaltung\.etiketten'#s", $api );
		// Eine Spule ist eine Rolle: das Rollengewicht ist die ganze Menge.
		$this->assertStringContainsString( 'FilamentRepository::insert( $result[\'data\'], $d[\'grams\'] )', file_get_contents( $root . '/includes/Erp/Spools.php' ) );
	}
}
