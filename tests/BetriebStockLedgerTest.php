<?php
/**
 * Lagerbewegungen: Bestände, Storno und Kommissionskette.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\StockLedger;

/**
 * Der Bestand ist immer die Summe der Bewegungen.
 */
class BetriebStockLedgerTest extends TestCase {

	/**
	 * Zugang aus der Produktion.
	 */
	public function test_zugang_aus_produktion() {
		$movements = array(
			array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 24, 'movement_type' => 'produktion', 'location' => 'lager' ),
		);

		$balance = StockLedger::balance_for( $movements, 1, 'anthrazit' );

		$this->assertEqualsWithDelta( 24.0, $balance['lager'], 0.00001 );
		$this->assertEqualsWithDelta( 24.0, $balance['gesamt'], 0.00001 );
	}

	/**
	 * Verkauf mindert den Bestand.
	 */
	public function test_verkauf_mindert_bestand() {
		$movements = array(
			array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 24, 'movement_type' => 'produktion', 'location' => 'lager' ),
			array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => -3, 'movement_type' => 'verkauf', 'location' => 'lager' ),
		);

		$this->assertEqualsWithDelta( 21.0, StockLedger::balance_for( $movements, 1, 'anthrazit' )['lager'], 0.00001 );
	}

	/**
	 * Farben werden getrennt geführt.
	 */
	public function test_farben_werden_getrennt_gefuehrt() {
		$movements = array(
			array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 24, 'movement_type' => 'produktion', 'location' => 'lager' ),
			array( 'article_id' => 1, 'color' => 'gruen', 'qty' => 6, 'movement_type' => 'produktion', 'location' => 'lager' ),
		);

		$this->assertEqualsWithDelta( 24.0, StockLedger::balance_for( $movements, 1, 'anthrazit' )['lager'], 0.00001 );
		$this->assertEqualsWithDelta( 6.0, StockLedger::balance_for( $movements, 1, 'gruen' )['lager'], 0.00001 );
	}

	/**
	 * Ein Storno wird gegengebucht, nicht gelöscht: Beide Zeilen bleiben in
	 * der Summe und heben sich auf.
	 */
	public function test_storno_hebt_sich_auf() {
		$original = array( 'id' => 7, 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 24, 'movement_type' => 'produktion', 'location' => 'lager' );
		$storno   = StockLedger::reversal( $original, 'Teile doppelt gezählt' );

		$this->assertEqualsWithDelta( -24.0, $storno['qty'], 0.00001 );
		$this->assertSame( 7, $storno['reverses_id'] );
		$this->assertSame( 'Teile doppelt gezählt', $storno['void_reason'] );

		$balance = StockLedger::balance_for( array( $original, $storno ), 1, 'anthrazit' );
		$this->assertEqualsWithDelta( 0.0, $balance['gesamt'], 0.00001 );
	}

	/**
	 * Kommissionsware verlässt das Lager, bleibt aber im Gesamtbestand –
	 * sie ist weiterhin Eigentum des Hauses.
	 */
	public function test_kommission_raus_bleibt_im_gesamtbestand() {
		$movements = array_merge(
			array( array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 24, 'movement_type' => 'produktion', 'location' => 'lager' ) ),
			StockLedger::commission_pair( 'kommission_raus', 1, 'anthrazit', 10, 5 )
		);

		$balance = StockLedger::balance_for( $movements, 1, 'anthrazit' );

		$this->assertEqualsWithDelta( 14.0, $balance['lager'], 0.00001, 'Verfügbares Lager sinkt.' );
		$this->assertEqualsWithDelta( 10.0, $balance['kommission'], 0.00001, 'Beim Händler liegen 10.' );
		$this->assertEqualsWithDelta( 24.0, $balance['gesamt'], 0.00001, 'Der Gesamtbestand ändert sich nicht.' );
	}

	/**
	 * Die vollständige Kette: raus, ein Teil abgerechnet, der Rest zurück.
	 * Am Ende muss die Rechnung aufgehen.
	 */
	public function test_kommissionskette_geht_auf() {
		$movements = array(
			array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 24, 'movement_type' => 'produktion', 'location' => 'lager' ),
		);

		// 10 Stück zum Händler.
		$movements = array_merge( $movements, StockLedger::commission_pair( 'kommission_raus', 1, 'anthrazit', 10, 5 ) );
		$this->assertEqualsWithDelta( 24.0, StockLedger::total( $movements ), 0.00001 );

		// Der Händler meldet 6 verkaufte Stück.
		$movements = array_merge( $movements, StockLedger::commission_pair( 'kommission_abgerechnet', 1, 'anthrazit', 6, 5 ) );

		// 4 Stück kommen zurück.
		$movements = array_merge( $movements, StockLedger::commission_pair( 'kommission_retour', 1, 'anthrazit', 4, 5 ) );

		$balance = StockLedger::balance_for( $movements, 1, 'anthrazit' );

		$this->assertEqualsWithDelta( 18.0, $balance['lager'], 0.00001, '24 minus 10 plus 4 zurück.' );
		$this->assertEqualsWithDelta( 0.0, $balance['kommission'], 0.00001, 'Beim Händler liegt nichts mehr.' );
		$this->assertEqualsWithDelta( 18.0, $balance['gesamt'], 0.00001, 'Nur die 6 abgerechneten Stück haben den Bestand verlassen.' );
	}

	/**
	 * Umbuchungen verändern den Gesamtbestand nicht, Abgänge schon.
	 */
	public function test_umbuchung_gegen_abgang() {
		$this->assertFalse( StockLedger::MOVEMENT_TYPES['kommission_raus'], 'Umbuchung.' );
		$this->assertFalse( StockLedger::MOVEMENT_TYPES['kommission_retour'], 'Umbuchung.' );
		$this->assertTrue( StockLedger::MOVEMENT_TYPES['kommission_abgerechnet'], 'Verlässt den Bestand.' );
		$this->assertTrue( StockLedger::MOVEMENT_TYPES['verkauf'] );
		$this->assertTrue( StockLedger::MOVEMENT_TYPES['muster'], 'Muster sind kein Umsatz, verlassen aber den Bestand.' );
	}

	/**
	 * Kommission raus und retour heben sich auf den Gesamtbestand exakt auf.
	 */
	public function test_raus_und_retour_sind_bestandsneutral() {
		$raus   = StockLedger::commission_pair( 'kommission_raus', 1, 'anthrazit', 10, 5 );
		$retour = StockLedger::commission_pair( 'kommission_retour', 1, 'anthrazit', 10, 5 );

		$this->assertEqualsWithDelta( 0.0, StockLedger::total( $raus ), 0.00001 );
		$this->assertEqualsWithDelta( 0.0, StockLedger::total( $retour ), 0.00001 );
	}

	/**
	 * Muster mindern den Bestand ohne Umsatz.
	 */
	public function test_muster_mindert_bestand() {
		$movements = array(
			array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 24, 'movement_type' => 'produktion', 'location' => 'lager' ),
			array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => -2, 'movement_type' => 'muster', 'location' => 'lager', 'contact_id' => 5 ),
		);

		$this->assertEqualsWithDelta( 22.0, StockLedger::balance_for( $movements, 1, 'anthrazit' )['gesamt'], 0.00001 );
	}

	/**
	 * Reichweite aus Bestand und Verbrauch der letzten vier Wochen.
	 */
	public function test_reichweite() {
		// 40 Stück Bestand, 20 Stück in vier Wochen verbraucht → 5 Stück je
		// Woche → 8 Wochen Reichweite.
		$this->assertEqualsWithDelta( 8.0, StockLedger::coverage_weeks( 40, 20, 4 ), 0.00001 );
	}

	/**
	 * Ohne Verbrauch lässt sich keine Reichweite bilden – dann null statt
	 * einer erfundenen Zahl.
	 */
	public function test_reichweite_ohne_verbrauch() {
		$this->assertNull( StockLedger::coverage_weeks( 40, 0, 4 ) );
	}

	/**
	 * Der Gesamtbestand umfasst jeden bekannten Lagerort.
	 *
	 * Vorher standen in pick() drei fest geschriebene Namen. Käme ein vierter
	 * Lagerort dazu, fehlte er in der Summe – jede Spalte für sich wäre
	 * richtig, nur der Gesamtbestand zu niedrig, und nichts würde rot.
	 */
	public function test_der_gesamtbestand_umfasst_jeden_lagerort() {
		$orte = array_keys( StockLedger::location_labels() );

		$bewegungen = array();

		foreach ( $orte as $i => $ort ) {
			$bewegungen[] = array(
				'article_id' => 7,
				'color'      => 'weiss',
				'location'   => $ort,
				'qty'        => $i + 1,
			);
		}

		$stand = StockLedger::balance_for( $bewegungen, 7, 'weiss' );

		foreach ( $orte as $i => $ort ) {
			$this->assertSame( (float) ( $i + 1 ), $stand[ $ort ], $ort );
		}

		$this->assertSame(
			array_sum( range( 1, count( $orte ) ) ) * 1.0,
			$stand['gesamt'],
			'Ein Lagerort fehlt in der Summe.'
		);
	}
}

