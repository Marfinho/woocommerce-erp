<?php
/**
 * Erkennt der Konfigurator, wenn eine Wunschkonfiguration einem Set entspricht?
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Configurator\SetMatcher;

/**
 * Prüft das Finden der Sets und den Abgleich.
 */
class KonfiguratorSetMatcherTest extends TestCase {

	/**
	 * Teile im Konfigurator: 3 = Basismodul, 1/4/10 = Module.
	 *
	 * @return array
	 */
	protected function parts() {
		return array(
			3  => array( 'role' => 'basis', 'name' => 'Basismodul' ),
			1  => array( 'role' => 'modul', 'name' => 'Brillenhalter' ),
			4  => array( 'role' => 'modul', 'name' => 'Flaschenhalter' ),
			10 => array( 'role' => 'modul', 'name' => 'Schuhhalter' ),
		);
	}

	/**
	 * Artikelstamm.
	 *
	 * @return array
	 */
	protected function articles() {
		$a = static function ( $id, $sku, $name, $wc, $status = 'aktiv' ) {
			return array( 'id' => $id, 'sku' => $sku, 'name' => $name, 'wc_product_id' => $wc, 'status' => $status, 'sellable' => 1, 'pos_only' => 0 );
		};

		return array(
			3  => $a( 3, 'M-BSM', 'Basismodul', 481 ),
			1  => $a( 1, 'M-BHR', 'Brillenhalter', 97 ),
			4  => $a( 4, 'M-FHR', 'Flaschenhalter', 96 ),
			5  => $a( 5, 'M-HMHR', 'Helmhalter', 94 ),
			10 => $a( 10, 'M-SHRG', 'Schuhhalter', 95 ),
			20 => $a( 20, 'S-BASE', 'BASE', 482 ),
			22 => $a( 22, 'S-DOPPEL', 'DOPPEL', 490 ),
			23 => $a( 23, 'S-GARAGE', 'GARAGE', 485 ),
			24 => $a( 24, 'S-ALT', 'ALT', 491, 'ausgelaufen' ),
		);
	}

	/**
	 * Stückliste.
	 *
	 * @return array
	 */
	protected function bom() {
		$row = static function ( $child, $qty = 1, $type = 'artikel' ) {
			return array( 'child_type' => $type, 'child_id' => $child, 'qty' => (string) $qty );
		};

		return array(
			// Das Basismodul selbst hat eine Stückliste, ist aber kein Set.
			3  => array( $row( 29, 2 ), $row( 3, 4, 'komponente' ) ),
			20 => array( $row( 1 ), $row( 3 ), $row( 4 ), $row( 7, 1, 'komponente' ) ),
			22 => array( $row( 3 ), $row( 1, 2 ) ),
			// Helmhalter (5) ist kein Konfigurator-Teil – nicht nachbaubar.
			23 => array( $row( 3 ), $row( 1 ), $row( 5 ), $row( 10 ) ),
			24 => array( $row( 3 ), $row( 10 ) ),
		);
	}

	/**
	 * Sets.
	 *
	 * @return array
	 */
	protected function sets() {
		return SetMatcher::sets( $this->parts(), $this->articles(), $this->bom() );
	}

	/**
	 * Nur nachbaubare, aktive Sets zählen.
	 */
	public function test_nur_nachbaubare_sets() {
		$skus = wp_list_pluck_test( $this->sets(), 'sku' );

		$this->assertSame( array( 'S-BASE', 'S-DOPPEL' ), $skus );
	}

	/**
	 * Gleiche Teile in anderer Reihenfolge und an beliebiger Stelle passen.
	 */
	public function test_gleiche_teile_passen_unabhaengig_von_position() {
		$set = SetMatcher::match(
			$this->sets(),
			3,
			array(
				array( 'article' => 4, 'pos' => 500 ),
				array( 'article' => 1, 'pos' => 20 ),
			)
		);

		$this->assertNotNull( $set );
		$this->assertSame( 20, $set['article'] );
		$this->assertSame( 482, $set['wc_product_id'] );
	}

	/**
	 * Ein Modul zu viel oder zu wenig ist kein Set.
	 */
	public function test_abweichung_ist_kein_set() {
		$sets = $this->sets();

		$this->assertNull( SetMatcher::match( $sets, 3, array( array( 'article' => 1, 'pos' => 0 ) ) ) );
		$this->assertNull(
			SetMatcher::match(
				$sets,
				3,
				array(
					array( 'article' => 1, 'pos' => 0 ),
					array( 'article' => 4, 'pos' => 200 ),
					array( 'article' => 10, 'pos' => 400 ),
				)
			)
		);
		$this->assertNull( SetMatcher::match( $sets, 99, array( array( 'article' => 1, 'pos' => 0 ), array( 'article' => 4, 'pos' => 200 ) ) ) );
	}

	/**
	 * Mengen zählen: zweimal dasselbe Modul.
	 */
	public function test_mengen_zaehlen() {
		$sets = $this->sets();
		$set  = SetMatcher::match( $sets, 3, array( array( 'article' => 1, 'pos' => 0 ), array( 'article' => 1, 'pos' => 300 ) ) );

		$this->assertNotNull( $set );
		$this->assertSame( 'S-DOPPEL', $set['sku'] );
	}

	/**
	 * Ein Set mit Bruchmenge oder zwei Basismodulen ist keins.
	 */
	public function test_unklare_stueckliste_ist_kein_set() {
		$bom = array(
			20 => array( array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => '1' ), array( 'child_type' => 'artikel', 'child_id' => 1, 'qty' => '0.5' ) ),
			22 => array( array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => '2' ), array( 'child_type' => 'artikel', 'child_id' => 1, 'qty' => '1' ) ),
		);

		$this->assertSame( array(), SetMatcher::sets( $this->parts(), $this->articles(), $bom ) );
	}
	/**
	 * Mit Holz- und Typ-Varianten gibt es das Set in jeder Kombination; der
	 * Abgleich nennt die Merkmale, die die Set-Variante auswählen.
	 */
	public function test_set_mit_varianten() {
		$parts                 = $this->parts();
		$parts[12]             = array( 'role' => 'basis', 'name' => 'Basismodul Walnuss' );
		$parts[11]             = array( 'role' => 'modul', 'name' => 'Schuhhalter SPD-SL' );
		$articles              = $this->articles();
		$articles[3]['wc_variant']  = 'pa_holz=fichte';
		$articles[12]          = array_merge( $articles[3], array( 'id' => 12, 'sku' => 'M-BSM-WAL', 'wc_variant' => 'pa_holz=walnuss' ) );
		$articles[10]['wc_variant'] = 'pa_schuhsystem=spd';
		$articles[11]          = array_merge( $articles[10], array( 'id' => 11, 'sku' => 'M-SHR-SL', 'wc_variant' => 'pa_schuhsystem=spd-sl' ) );
		$articles[25]          = array( 'id' => 25, 'sku' => 'S-SCHUH', 'name' => 'SCHUH', 'wc_product_id' => 492, 'status' => 'aktiv', 'sellable' => 1, 'pos_only' => 0 );
		$bom                   = $this->bom();
		$bom[25]               = array(
			array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => '1' ),
			array( 'child_type' => 'artikel', 'child_id' => 10, 'qty' => '1' ),
		);

		$sets  = SetMatcher::sets( $parts, $articles, $bom );
		$schuh = array_values( array_filter( $sets, static function ( $set ) { return 'S-SCHUH' === $set['sku']; } ) );

		$this->assertCount( 4, $schuh );

		$set = SetMatcher::match( $sets, 12, array( array( 'article' => 11, 'pos' => 100 ) ) );

		$this->assertSame( 'S-SCHUH', $set['sku'] );
		$this->assertSame( 'pa_holz=walnuss&pa_schuhsystem=spd-sl', $set['variant'] );
	}

	/**
	 * Zwei gleiche Module mit verschiedenem Typ passen in kein Set-Produkt.
	 */
	public function test_widerspruechliche_varianten_entfallen() {
		$parts                      = $this->parts();
		$parts[11]                  = array( 'role' => 'modul', 'name' => 'Schuhhalter SPD-SL' );
		$articles                   = $this->articles();
		$articles[10]['wc_variant'] = 'pa_schuhsystem=spd';
		$articles[11]               = array_merge( $articles[10], array( 'id' => 11, 'sku' => 'M-SHR-SL', 'wc_variant' => 'pa_schuhsystem=spd-sl' ) );
		$bom                        = $this->bom();
		$bom[22]                    = array(
			array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => '1' ),
			array( 'child_type' => 'artikel', 'child_id' => 10, 'qty' => '2' ),
		);
		$sets = SetMatcher::sets( $parts, $articles, $bom );

		$this->assertNull( SetMatcher::match( $sets, 3, array( array( 'article' => 10, 'pos' => 0 ), array( 'article' => 11, 'pos' => 300 ) ) ) );
		$this->assertNotNull( SetMatcher::match( $sets, 3, array( array( 'article' => 11, 'pos' => 0 ), array( 'article' => 11, 'pos' => 300 ) ) ) );
	}
}

/**
 * Spalte aus einer Liste.
 *
 * @param array  $rows Zeilen.
 * @param string $key  Spalte.
 * @return array
 */
function wp_list_pluck_test( array $rows, $key ) {
	return array_values( array_map( static function ( $row ) use ( $key ) { return $row[ $key ]; }, $rows ) );
}
