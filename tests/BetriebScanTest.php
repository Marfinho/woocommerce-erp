<?php
/**
 * Scan-Auflösung, Lagerplatzbestände und Pickliste.
 *
 * Geprüft wird der Teil, der ohne Datenbank auskommt – also genau die Logik,
 * bei der ein Fehler still bliebe: Was ist das für ein Code, was liegt wo,
 * welche Zeile gehört zu welchem Platz.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\BinRepository;
use Lindenweg16\Modules\Betrieb\PicklistRepository;
use Lindenweg16\Modules\Betrieb\ScanResolver;

/**
 * Prüft Codeerkennung, Platzbestände und das Zusammenfassen von Pickzeilen.
 */
class BetriebScanTest extends TestCase {

	/* ================================================================
	 * Codes säubern und zerlegen
	 * ============================================================== */

	/**
	 * Scanner hängen gern ein Zeilenende an. Bliebe es stehen, würde kein
	 * einziger Vergleich mehr greifen.
	 */
	public function test_scan_wird_gesaeubert() {
		$this->assertSame( 'A01', ScanResolver::clean( "A01\r\n" ) );
		$this->assertSame( 'A01', ScanResolver::clean( "  A01\t" ) );
		$this->assertSame( '', ScanResolver::clean( "\n" ) );
	}

	/**
	 * Eigene Präfixe werden erkannt und abgetrennt.
	 */
	public function test_eigene_praefixe_werden_erkannt() {
		$bin = ScanResolver::split( ScanResolver::PREFIX_BIN . 'A01' );
		$this->assertSame( 'bin', $bin['kind'] );
		$this->assertSame( 'A01', $bin['value'] );

		$article = ScanResolver::split( ScanResolver::PREFIX_ARTICLE . 'HH-MOD-01' );
		$this->assertSame( 'article', $article['kind'] );
		$this->assertSame( 'HH-MOD-01', $article['value'] );

		$batch = ScanResolver::split( ScanResolver::PREFIX_BATCH . '2608-HH-001' );
		$this->assertSame( 'batch', $batch['kind'] );
		$this->assertSame( '2608-HH-001', $batch['value'] );
	}

	/**
	 * Ein fremder Barcode trägt kein Präfix und bleibt unverändert.
	 */
	public function test_fremder_barcode_bleibt_unveraendert() {
		$result = ScanResolver::split( '4006381333931' );

		$this->assertSame( 'unknown', $result['kind'] );
		$this->assertSame( '4006381333931', $result['value'] );
	}

	/**
	 * Groß- und Kleinschreibung darf beim Präfix keine Rolle spielen.
	 */
	public function test_praefix_ist_schreibweisenunabhaengig() {
		$result = ScanResolver::split( strtolower( ScanResolver::PREFIX_BIN ) . 'A01' );

		$this->assertSame( 'bin', $result['kind'] );
	}

	/* ================================================================
	 * Lagerplatzcodes
	 * ============================================================== */

	/**
	 * Codes werden vereinheitlicht, damit derselbe Platz nicht zweimal
	 * entsteht, nur weil jemand klein geschrieben hat.
	 */
	public function test_platzcodes_werden_vereinheitlicht() {
		$this->assertSame( 'A01', BinRepository::normalize_code( ' a01 ' ) );
		$this->assertSame( 'REGAL-2', BinRepository::normalize_code( 'regal-2' ) );
		$this->assertSame( 'KISTE3', BinRepository::normalize_code( 'Kiste 3' ) );
		$this->assertSame( 'A01', BinRepository::normalize_code( 'A/01' ) );
	}

	/* ================================================================
	 * Bestand je Lagerplatz
	 * ============================================================== */

	/**
	 * Bewegungen mit Platz summieren sich je Platz, Artikel und Farbe.
	 */
	public function test_platzbestand_summiert_bewegungen() {
		$movements = array(
			array( 'bin_id' => 1, 'article_id' => 10, 'color' => 'anthrazit', 'qty' => 12 ),
			array( 'bin_id' => 1, 'article_id' => 10, 'color' => 'anthrazit', 'qty' => -4 ),
			array( 'bin_id' => 1, 'article_id' => 11, 'color' => 'anthrazit', 'qty' => 3 ),
			array( 'bin_id' => 2, 'article_id' => 10, 'color' => 'anthrazit', 'qty' => 5 ),
		);

		$balances = BinRepository::balances( $movements );

		$this->assertEqualsWithDelta( 8.0, $balances[1][10]['anthrazit'], 0.0001 );
		$this->assertEqualsWithDelta( 3.0, $balances[1][11]['anthrazit'], 0.0001 );
		$this->assertEqualsWithDelta( 5.0, $balances[2][10]['anthrazit'], 0.0001 );
	}

	/**
	 * Bewegungen ohne Platz zählen nicht in einen Platz hinein – sie sind
	 * Bestand, nur eben ortlos.
	 */
	public function test_bewegungen_ohne_platz_zaehlen_nicht_mit() {
		$balances = BinRepository::balances(
			array(
				array( 'bin_id' => 0, 'article_id' => 10, 'color' => 'anthrazit', 'qty' => 99 ),
				array( 'article_id' => 10, 'color' => 'anthrazit', 'qty' => 50 ),
				array( 'bin_id' => 1, 'article_id' => 10, 'color' => 'anthrazit', 'qty' => 7 ),
			)
		);

		$this->assertArrayNotHasKey( 0, $balances );
		$this->assertCount( 1, $balances );
		$this->assertEqualsWithDelta( 7.0, $balances[1][10]['anthrazit'], 0.0001 );
	}

	/**
	 * Ein leergeräumter Platz wird nicht mit einer Null angezeigt.
	 */
	public function test_leergeraeumter_platz_verschwindet_aus_der_anzeige() {
		$balances = BinRepository::balances(
			array(
				array( 'bin_id' => 1, 'article_id' => 10, 'color' => 'anthrazit', 'qty' => 6 ),
				array( 'bin_id' => 1, 'article_id' => 10, 'color' => 'anthrazit', 'qty' => -6 ),
				array( 'bin_id' => 1, 'article_id' => 11, 'color' => 'anthrazit', 'qty' => 2 ),
			)
		);

		$stock = BinRepository::stock_of( $balances, 1 );

		$this->assertArrayNotHasKey( 10, $stock );
		$this->assertEqualsWithDelta( 2.0, $stock[11]['anthrazit'], 0.0001 );
	}

	/* ================================================================
	 * Pickliste
	 * ============================================================== */

	/**
	 * Gleiche Artikel derselben Bestellung werden zu einer Zeile.
	 */
	public function test_gleiche_position_wird_zusammengefasst() {
		$rows = PicklistRepository::condense(
			array(
				array( 'article_id' => 10, 'color' => 'anthrazit', 'qty' => 2, 'wc_order_id' => 500 ),
				array( 'article_id' => 10, 'color' => 'anthrazit', 'qty' => 3, 'wc_order_id' => 500 ),
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertEqualsWithDelta( 5.0, $rows[0]['qty_required'], 0.0001 );
	}

	/**
	 * Verschiedene Bestellungen bleiben getrennt – sonst wüsste am Packtisch
	 * niemand mehr, wessen Teil das ist.
	 */
	public function test_verschiedene_bestellungen_bleiben_getrennt() {
		$rows = PicklistRepository::condense(
			array(
				array( 'article_id' => 10, 'color' => 'anthrazit', 'qty' => 2, 'wc_order_id' => 500 ),
				array( 'article_id' => 10, 'color' => 'anthrazit', 'qty' => 2, 'wc_order_id' => 501 ),
			)
		);

		$this->assertCount( 2, $rows );
	}

	/**
	 * Verschiedene Farben bleiben getrennt.
	 */
	public function test_verschiedene_farben_bleiben_getrennt() {
		$rows = PicklistRepository::condense(
			array(
				array( 'article_id' => 10, 'color' => 'anthrazit', 'qty' => 1, 'wc_order_id' => 500 ),
				array( 'article_id' => 10, 'color' => 'weiss', 'qty' => 1, 'wc_order_id' => 500 ),
			)
		);

		$this->assertCount( 2, $rows );
	}

	/**
	 * Sortiert wird nach Lagerplatz – der Weg durchs Regal ist die knappe
	 * Ressource. Zeilen ohne Platz stehen am Ende, weil sie ohnehin gesucht
	 * werden müssen.
	 */
	public function test_sortierung_folgt_dem_lagerplatz() {
		$rows = PicklistRepository::condense(
			array(
				array( 'article_id' => 10, 'qty' => 1, 'wc_order_id' => 500 ),
				array( 'article_id' => 11, 'qty' => 1, 'wc_order_id' => 500 ),
				array( 'article_id' => 12, 'qty' => 1, 'wc_order_id' => 500 ),
			),
			array( 10 => 7, 11 => 0, 12 => 2 )
		);

		$this->assertSame( 12, $rows[0]['article_id'], 'Platz 2 kommt vor Platz 7.' );
		$this->assertSame( 10, $rows[1]['article_id'] );
		$this->assertSame( 11, $rows[2]['article_id'], 'Ohne Platz gehoert ans Ende.' );
	}

	/**
	 * Zeilen ohne Menge sind keine Arbeit und gehören nicht auf den Zettel.
	 */
	public function test_zeilen_ohne_menge_entfallen() {
		$rows = PicklistRepository::condense(
			array(
				array( 'article_id' => 10, 'qty' => 0, 'wc_order_id' => 500 ),
				array( 'article_id' => 11, 'qty' => -2, 'wc_order_id' => 500 ),
				array( 'article_id' => 12, 'qty' => 1, 'wc_order_id' => 500 ),
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 12, $rows[0]['article_id'] );
	}

	/* ================================================================
	 * Scan-Absicherung beim Picken
	 * ============================================================== */

	/**
	 * Der passende Code bestätigt die Position.
	 */
	public function test_passender_scan_bestaetigt() {
		$this->assertTrue( PicklistRepository::scan_matches( 'HH-MOD-01', 'HH-MOD-01' ) );
		$this->assertTrue( PicklistRepository::scan_matches( ScanResolver::PREFIX_ARTICLE . 'HH-MOD-01', 'HH-MOD-01' ) );
		$this->assertTrue( PicklistRepository::scan_matches( 'hh-mod-01', 'HH-MOD-01' ) );
		$this->assertTrue( PicklistRepository::scan_matches( '4006381333931', 'HH-MOD-01', '4006381333931' ) );
	}

	/**
	 * Ein fremder Code darf die Position nicht bestätigen – sonst wäre der
	 * Scan reine Zierde.
	 */
	public function test_falscher_scan_wird_abgewiesen() {
		$this->assertFalse( PicklistRepository::scan_matches( 'HH-MOD-02', 'HH-MOD-01' ) );
		$this->assertFalse( PicklistRepository::scan_matches( '4006381333931', 'HH-MOD-01' ) );
	}

	/**
	 * Ohne Scan bleibt das Abhaken möglich: Der Scan sichert ab, er blockiert
	 * nicht.
	 */
	public function test_ohne_scan_bleibt_abhaken_moeglich() {
		$this->assertTrue( PicklistRepository::scan_matches( '', 'HH-MOD-01' ) );
		$this->assertTrue( PicklistRepository::scan_matches( "  \n", 'HH-MOD-01' ) );
	}
}
