<?php
/**
 * Standardlagerplatz: Vorschlag, nicht Zuordnung.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\BinRepository;
use Lindenweg16\Modules\Betrieb\PicklistRepository;
use PHPUnit\Framework\TestCase;

/**
 * Der gemessene Platz muss den hinterlegten immer schlagen.
 */
class BetriebBinHintTest extends TestCase {

	/**
	 * Der Platz mit dem größten Bestand gewinnt.
	 */
	public function test_groesster_bestand_gewinnt() {
		$balances = array(
			7 => array( 42 => array( 'anthrazit' => 3 ) ),
			9 => array( 42 => array( 'anthrazit' => 11 ) ),
		);

		$this->assertSame( array( 42 => 9 ), BinRepository::busiest( $balances ) );
	}

	/**
	 * Ein leerer Platz zählt nicht als Fundort.
	 */
	public function test_leerer_platz_zaehlt_nicht() {
		$balances = array(
			7 => array( 42 => array( 'anthrazit' => 0 ) ),
			9 => array( 42 => array( 'anthrazit' => 2 ) ),
		);

		$this->assertSame( array( 42 => 9 ), BinRepository::busiest( $balances ) );
	}

	/**
	 * Ohne Bewegung greift der hinterlegte Platz.
	 */
	public function test_standardplatz_als_rueckfall() {
		$hints = BinRepository::with_defaults(
			array(),
			array( array( 'id' => 42, 'default_bin_id' => 3 ) )
		);

		$this->assertSame( array( 42 => 3 ), $hints );
	}

	/**
	 * Wo gemessen wurde, gewinnt die Messung – der Standardplatz sagt nur,
	 * wo das Teil liegen sollte, nicht wo es liegt.
	 */
	public function test_messung_schlaegt_hinterlegten_platz() {
		$hints = BinRepository::with_defaults(
			array( 42 => 9 ),
			array( array( 'id' => 42, 'default_bin_id' => 3 ) )
		);

		$this->assertSame( array( 42 => 9 ), $hints );
	}

	/**
	 * Ohne hinterlegten Platz bleibt die Zeile ohne Platz.
	 */
	public function test_ohne_angabe_bleibt_es_leer() {
		$hints = BinRepository::with_defaults(
			array(),
			array( array( 'id' => 42, 'default_bin_id' => null ) )
		);

		$this->assertSame( array(), $hints );
	}

	/**
	 * Ein Kleinteil bekommt seinen Platz aus der eigenen Liste, nicht aus
	 * der Artikelliste – die IDs überschneiden sich.
	 */
	public function test_kleinteil_nimmt_die_eigene_liste() {
		$rows = PicklistRepository::condense(
			array(
				array( 'article_id' => 5, 'qty' => 2, 'item_type' => 'komponente' ),
			),
			array( 5 => 11 ),
			array( 5 => 22 )
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 22, $rows[0]['bin_id'] );
	}

	/**
	 * Ein Artikel nimmt die Artikelliste.
	 */
	public function test_artikel_nimmt_die_artikelliste() {
		$rows = PicklistRepository::condense(
			array(
				array( 'article_id' => 5, 'qty' => 2 ),
			),
			array( 5 => 11 ),
			array( 5 => 22 )
		);

		$this->assertSame( 11, $rows[0]['bin_id'] );
	}

	/**
	 * Was an der Zeile steht, geht allem vor.
	 */
	public function test_zeile_schlaegt_beide_listen() {
		$rows = PicklistRepository::condense(
			array(
				array( 'article_id' => 5, 'qty' => 2, 'bin_id' => 99 ),
			),
			array( 5 => 11 ),
			array( 5 => 22 )
		);

		$this->assertSame( 99, $rows[0]['bin_id'] );
	}
}
