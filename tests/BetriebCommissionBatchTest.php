<?php
/**
 * Prüft die Kommissionsliste.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\CommissionBatch;
use Lindenweg16\Modules\Betrieb\StockLedger;
use PHPUnit\Framework\TestCase;

/**
 * Kommissions-Tests.
 */
class BetriebCommissionBatchTest extends TestCase {

	/**
	 * Eine Zeile.
	 *
	 * @param int    $article Artikel.
	 * @param string $color   Farbe.
	 * @param mixed  $qty     Menge.
	 * @return array
	 */
	protected function zeile( $article, $color, $qty ) {
		return array( 'article_id' => $article, 'color' => $color, 'qty' => $qty );
	}

	/**
	 * Leere Felder sind keine Buchung.
	 *
	 * In einer Liste über alle Artikel stehen die meisten Felder auf leer.
	 * Landeten sie als Buchung über null Stück in der Bewegungstabelle,
	 * wäre jede Lieferung sechzig Zeilen lang und keine davon lesbar.
	 */
	public function test_leere_felder_sind_keine_buchung() {
		$result = CommissionBatch::validate(
			array(
				$this->zeile( 7, 'weiss', '' ),
				$this->zeile( 7, 'anthrazit', '  ' ),
				$this->zeile( 8, 'weiss', '3' ),
				$this->zeile( 9, 'weiss', '0' ),
			),
			'kommission_raus',
			42
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertCount( 1, $result['lines'] );
		$this->assertSame( 8, $result['lines'][0]['article_id'] );
		$this->assertSame( 3.0, $result['lines'][0]['qty'] );
	}

	/**
	 * Komma als Dezimaltrennzeichen ist das, was jemand tippt.
	 */
	public function test_komma_wird_gelesen() {
		$result = CommissionBatch::validate( array( $this->zeile( 7, 'weiss', '2,5' ) ), 'kommission_raus', 42 );

		$this->assertSame( 2.5, $result['lines'][0]['qty'] );
	}

	/**
	 * Dieselbe Ware zweimal wird zusammengezählt.
	 *
	 * Die Oberfläche zeigt jede Kombination nur einmal. Kommt sie trotzdem
	 * doppelt an, ist die Summe die einzige Lesart, die nicht überrascht –
	 * zwei getrennte Buchungen über dieselbe Ware sähen im Protokoll aus
	 * wie zwei Lieferungen.
	 */
	public function test_dieselbe_ware_wird_zusammengezaehlt() {
		$result = CommissionBatch::validate(
			array(
				$this->zeile( 7, 'weiss', '3' ),
				$this->zeile( 7, 'weiss', '2' ),
				$this->zeile( 7, 'anthrazit', '1' ),
			),
			'kommission_raus',
			42
		);

		$this->assertCount( 2, $result['lines'] );
		$this->assertSame( 5.0, $result['lines'][0]['qty'] );
		$this->assertSame( 'weiss', $result['lines'][0]['color'] );
	}

	/**
	 * Entweder alles oder nichts.
	 *
	 * Eine halb gebuchte Lieferung ist schlimmer als eine nicht gebuchte:
	 * Sie sieht aus wie eine fertige.
	 */
	public function test_ein_fehler_verhindert_die_ganze_liste() {
		$result = CommissionBatch::validate(
			array(
				$this->zeile( 7, 'weiss', '3' ),
				$this->zeile( 0, 'weiss', '5' ),
			),
			'kommission_raus',
			42
		);

		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( array(), $result['lines'] );
	}

	/**
	 * Negative Mengen gibt es nicht.
	 *
	 * Für die Gegenrichtung gibt es den Vorgang. Eine negative Menge würde
	 * das Buchungspaar umdrehen und aus einer Auslieferung eine Rücknahme
	 * machen, ohne dass es irgendwo stünde.
	 */
	public function test_negative_mengen_werden_abgewiesen() {
		$result = CommissionBatch::validate( array( $this->zeile( 7, 'weiss', '-3' ) ), 'kommission_raus', 42 );

		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Ohne Händler und ohne bekannten Vorgang wird nichts gebucht.
	 */
	public function test_ohne_haendler_oder_vorgang_wird_nichts_gebucht() {
		$ohne_haendler = CommissionBatch::validate( array( $this->zeile( 7, 'weiss', '3' ) ), 'kommission_raus', 0 );
		$this->assertNotEmpty( $ohne_haendler['errors'] );

		$falsch = CommissionBatch::validate( array( $this->zeile( 7, 'weiss', '3' ) ), 'verkauf', 42 );
		$this->assertNotEmpty( $falsch['errors'] );
	}

	/**
	 * Eine leere Liste ist ein Fehler, kein stiller Erfolg.
	 */
	public function test_leere_liste_meldet_sich() {
		$result = CommissionBatch::validate( array(), 'kommission_raus', 42 );

		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Die Zusammenfassung zählt Positionen und Stück.
	 */
	public function test_zusammenfassung() {
		$result = CommissionBatch::validate(
			array(
				$this->zeile( 7, 'weiss', '3' ),
				$this->zeile( 8, 'anthrazit', '4' ),
			),
			'kommission_raus',
			42
		);

		$this->assertSame( 2, $result['positions'] );
		$this->assertSame( 7.0, $result['pieces'] );
	}

	/**
	 * Je Zeile entstehen dieselben Bewegungen wie bei der Einzelbuchung.
	 *
	 * Die Regel, was eine Kommissionsbewegung ist, steht weiterhin nur in
	 * StockLedger – die Liste ruft sie auf, sie schreibt sie nicht neu.
	 */
	public function test_die_bewegungen_stammen_aus_dem_ledger() {
		$lines = array(
			array( 'article_id' => 7, 'color' => 'weiss', 'qty' => 3.0 ),
			array( 'article_id' => 8, 'color' => 'anthrazit', 'qty' => 4.0 ),
		);

		$aus_liste = CommissionBatch::movements( $lines, 'kommission_raus', 42 );

		$einzeln = array_merge(
			StockLedger::commission_pair( 'kommission_raus', 7, 'weiss', 3.0, 42 ),
			StockLedger::commission_pair( 'kommission_raus', 8, 'anthrazit', 4.0, 42 )
		);

		$this->assertEquals( $einzeln, $aus_liste );
	}

	/**
	 * Eine Auslieferung ist immer ein Paar: raus aus dem Lager, rein beim
	 * Händler. Der Gesamtbestand bleibt gleich.
	 */
	public function test_die_auslieferung_bleibt_ausgeglichen() {
		$lines = array(
			array( 'article_id' => 7, 'color' => 'weiss', 'qty' => 3.0 ),
			array( 'article_id' => 8, 'color' => 'weiss', 'qty' => 4.0 ),
		);

		$movements = CommissionBatch::movements( $lines, 'kommission_raus', 42 );

		$this->assertCount( 4, $movements );
		$this->assertSame( 0.0, StockLedger::total( $movements ) );
	}

	/**
	 * Beim Abrechnen verlässt die Ware den Bestand endgültig.
	 */
	public function test_abrechnen_nimmt_die_ware_aus_dem_bestand() {
		$lines     = array( array( 'article_id' => 7, 'color' => 'weiss', 'qty' => 3.0 ) );
		$movements = CommissionBatch::movements( $lines, 'kommission_abgerechnet', 42 );

		$this->assertCount( 1, $movements );
		$this->assertSame( -3.0, StockLedger::total( $movements ) );
	}

	/**
	 * Eine unverhältnismässig lange Liste wird abgewiesen.
	 */
	public function test_zu_lange_liste_wird_abgewiesen() {
		$this->assertFalse( CommissionBatch::too_long( array_fill( 0, CommissionBatch::MAX_LINES, array() ) ) );
		$this->assertTrue( CommissionBatch::too_long( array_fill( 0, CommissionBatch::MAX_LINES + 1, array() ) ) );
	}

	/**
	 * Jeder Vorgang der Liste ist auch einer, den das Ledger kennt.
	 *
	 * Sonst käme eine Auswahl zustande, die beim Buchen still nichts tut:
	 * commission_pair() liefert für einen unbekannten Vorgang ein leeres
	 * Feld, und die Liste wäre gebucht, ohne dass sich etwas bewegt.
	 */
	public function test_jeder_vorgang_erzeugt_bewegungen() {
		foreach ( array_keys( CommissionBatch::types() ) as $type ) {
			$movements = StockLedger::commission_pair( $type, 7, 'weiss', 1.0, 42 );

			$this->assertNotEmpty( $movements, $type . ' erzeugt keine Bewegung.' );
		}
	}

	/**
	 * Handy, Schreibtisch und Schnittstelle benutzen dieselben Kennungen.
	 *
	 * Drei Stellen bauen dieselbe Liste: das Formular auf der Kontaktseite,
	 * die Maske am Handy und die Route dazwischen. Verrutscht eine Kennung,
	 * kommt am Server eine leere Liste an und die Lieferung wird stumm nicht
	 * gebucht – der schlimmste Fall, weil er wie Erfolg aussieht.
	 */
	public function test_handy_und_schnittstelle_passen_zusammen() {
		$basis  = dirname( __DIR__ );
		$seite  = file_get_contents( $basis . '/includes/Modules/Betrieb/MobileCapture.php' );
		$skript = file_get_contents( $basis . '/assets/js/betrieb-mobile.js' );
		$rest   = file_get_contents( $basis . '/includes/Modules/Betrieb/RestApi.php' );

		foreach ( array(
			'lw16-komm-contact',
			'lw16-komm-type',
			'lw16-komm-liste',
			'lw16-komm-suche',
			'lw16-komm-summe',
			'lw16-form-kommission',
		) as $kennung ) {
			$this->assertStringContainsString( $kennung, $seite, $kennung . ' fehlt im Markup.' );
			$this->assertStringContainsString( $kennung, $skript, $kennung . ' fehlt im Skript.' );
		}

		// Der Reiter ohne sein Feld waere ein Knopf, der auf nichts zeigt.
		$this->assertStringContainsString( 'data-tab="kommission"', $seite );
		$this->assertStringContainsString( 'data-panel="kommission"', $seite );

		// Die Felder, die das Skript sendet, muss die Route auch lesen.
		foreach ( array( 'contact_id', 'movement_type', 'lines' ) as $feld ) {
			$this->assertStringContainsString( $feld, $skript, $feld . ' wird vom Skript nicht gesendet.' );
			$this->assertStringContainsString( "'" . $feld . "'", $rest, $feld . ' wird von der Route nicht gelesen.' );
		}

		// Und die Route muss es überhaupt geben.
		$this->assertStringContainsString( "'/kommission'", $rest );
		$this->assertStringContainsString( "submit( 'kommission'", $skript );
	}

	/**
	 * Die Texte, die das Skript anzeigt, liefert der Server auch.
	 */
	public function test_die_texte_des_skripts_werden_geliefert() {
		$basis  = dirname( __DIR__ );
		$seite  = file_get_contents( $basis . '/includes/Modules/Betrieb/MobileCapture.php' );
		$skript = file_get_contents( $basis . '/assets/js/betrieb-mobile.js' );

		foreach ( array( 'keineMenge', 'position', 'positionen', 'stueck' ) as $schluessel ) {
			$this->assertStringContainsString( 'config.i18n.' . $schluessel, $skript, $schluessel . ' wird nicht benutzt.' );
			$this->assertStringContainsString( "'" . $schluessel . "'", $seite, $schluessel . ' wird nicht geliefert.' );
		}
	}

	/**
	 * Das Formular am Schreibtisch sendet an denselben Handler.
	 */
	public function test_das_formular_am_schreibtisch_passt() {
		$seite = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Admin/SimplePages.php' );

		$this->assertStringContainsString( "form( 'commission_batch' )", $seite );
		$this->assertStringContainsString( 'handle_commission_batch', $seite );
		$this->assertStringContainsString( "'commission_batch'", $seite );

		// Die Feldnamen tragen Artikel und Farbe – der Handler liest sie so.
		$this->assertStringContainsString( 'name="menge[%4$d][%5$s]"', $seite );
		$this->assertStringContainsString( "\$_POST['menge']", $seite );
	}
}

