<?php
/**
 * Tests für die Mengen-Abfragen des Lagerbestands.
 *
 * Die Lagerübersicht, das Dashboard-Widget und der Wochenbericht laufen
 * alle drei über den gesamten Produktstamm. Vorher fragte jede Zeile
 * einzeln nach ihrem Bestand. Diese Tests halten fest, was die
 * gebündelten Abfragen leisten müssen, damit die Umstellung nichts
 * verschluckt: fehlende Produkte müssen als Null erscheinen und nicht
 * fehlen, doppelte Kennungen dürfen nicht doppelt abgefragt werden, und
 * eine leere Liste darf die Datenbank gar nicht erst anfassen.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Inventory\Repository;

/**
 * $wpdb-Attrappe, die den erzeugten Befehl festhält.
 */
class LW16_StockWpdb {

	/** @var string Präfix wie in WordPress. */
	public $prefix = 'wp_';

	/** @var string Zuletzt abgesetzter Befehl. */
	public $last = '';

	/** @var array Was die Abfrage liefern soll. */
	public $rows = array();

	/**
	 * Platzhalter binden.
	 *
	 * @param string $query  Befehl.
	 * @param mixed  ...$args Werte.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$i = 0;

		return preg_replace_callback(
			'/%[sdf]/',
			static function ( $match ) use ( &$i, $args ) {
				$value = isset( $args[ $i ] ) ? $args[ $i ] : null;
				$i++;

				return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	/**
	 * Abfrage merken und die vorbereiteten Zeilen liefern.
	 *
	 * @param string $query Befehl.
	 * @param string $mode  Rückgabeform.
	 * @return array
	 */
	public function get_results( $query, $mode = null ) {
		$this->last = $query;

		return $this->rows;
	}
}

/**
 * @covers \Lindenweg16\Modules\Inventory\Repository
 */
class InventoryStockMapTest extends TestCase {

	/** @var LW16_StockWpdb */
	protected $wpdb;

	protected function setUp(): void {
		$this->wpdb      = new LW16_StockWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	public function test_bestand_wird_je_produkt_zugeordnet() {
		$this->wpdb->rows = array( array( 'produkt_id' => '5', 'menge' => '12.5' ) );

		$map = Repository::get_stock_map( array( 5, 7 ) );

		$this->assertSame( 12.5, $map[5] );
	}

	public function test_produkt_ohne_bestandszeile_erscheint_als_null() {
		$this->wpdb->rows = array( array( 'produkt_id' => '5', 'menge' => '12.5' ) );

		$map = Repository::get_stock_map( array( 5, 7 ) );

		// Wichtig: Der Schlüssel muss da sein. Die Ampel unterscheidet
		// zwischen „kein Bestand" und „Produkt unbekannt" nicht, aber die
		// Aufrufer verlassen sich darauf, dass jede Zeile einen Wert hat.
		$this->assertArrayHasKey( 7, $map );
		$this->assertSame( 0.0, $map[7] );
	}

	public function test_doppelte_und_leere_kennungen_werden_aussortiert() {
		Repository::get_stock_map( array( 5, 7, 5, 0, null ) );

		$this->assertStringContainsString( 'IN (5,7)', $this->wpdb->last );
	}

	public function test_leere_eingabe_fasst_die_datenbank_nicht_an() {
		$this->wpdb->last = 'UNBERUEHRT';

		$this->assertSame( array(), Repository::get_stock_map( array() ) );
		$this->assertSame( array(), Repository::get_stock_by_location_map( array() ) );
		$this->assertSame( 'UNBERUEHRT', $this->wpdb->last );
	}

	public function test_lagerorte_werden_nach_produkt_gruppiert() {
		$this->wpdb->rows = array(
			(object) array( 'produkt_id' => '5', 'kuerzel' => 'A' ),
			(object) array( 'produkt_id' => '5', 'kuerzel' => 'B' ),
			(object) array( 'produkt_id' => '9', 'kuerzel' => 'A' ),
		);

		$orte = Repository::get_stock_by_location_map( array( 5, 9 ) );

		$this->assertCount( 2, $orte[5] );
		$this->assertCount( 1, $orte[9] );
		$this->assertSame( 'A', $orte[5][0]->kuerzel, 'Die Reihenfolge aus der Abfrage bleibt erhalten.' );
	}
}
