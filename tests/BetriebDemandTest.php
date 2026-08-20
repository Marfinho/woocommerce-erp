<?php
/**
 * Mehrstufige Bedarfsrechnung.
 *
 * Der heikle Teil ist die Reihenfolge: Ein Halbzeug darf erst aufgelöst
 * werden, wenn sein voller Bedarf feststeht. Wird zu früh aufgelöst,
 * entstehen doppelte Aufträge – und das fällt erst auf, wenn zu viel im
 * Regal steht.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\DemandPlanner;

/**
 * Prüft Tiefenbestimmung, Verrechnung und Auflösung.
 */
class BetriebDemandTest extends TestCase {

	/**
	 * Basismodul → Halbzeug Stange → Rohling Abstandshalter,
	 * dazu ein Rohling Wandanker direkt unter dem Basismodul.
	 *
	 * @return array
	 */
	protected function ctx( array $stock = array(), array $planned = array() ) {
		return array(
			'articles' => array(
				1 => array( 'id' => 1, 'sku' => 'HH-BASIS', 'name' => 'Basismodul', 'sourcing_type' => 'montage' ),
				2 => array( 'id' => 2, 'sku' => 'HZ-STANGE', 'name' => 'Stange montiert', 'sourcing_type' => 'montage' ),
				3 => array( 'id' => 3, 'sku' => 'ROH-ABST', 'name' => 'Abstandshalter', 'sourcing_type' => 'druck' ),
				4 => array( 'id' => 4, 'sku' => 'HOLZ-60', 'name' => 'Holzstange', 'sourcing_type' => 'zukauf' ),
				5 => array( 'id' => 5, 'sku' => 'ROH-ANKER', 'name' => 'Wandanker', 'sourcing_type' => 'druck' ),
			),
			'bom'      => array(
				1 => array(
					array( 'child_type' => 'artikel', 'child_id' => 2, 'qty' => 1 ),
					array( 'child_type' => 'artikel', 'child_id' => 5, 'qty' => 2 ),
					array( 'child_type' => 'komponente', 'child_id' => 90, 'qty' => 1 ),
				),
				2 => array(
					array( 'child_type' => 'artikel', 'child_id' => 4, 'qty' => 1 ),
					array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => 2 ),
					array( 'child_type' => 'komponente', 'child_id' => 91, 'qty' => 4 ),
				),
			),
			'stock'    => $stock,
			'planned'  => $planned,
			'components'      => array(
				90 => array( 'code' => 'SET-MONT', 'name' => 'Montage-Set' ),
				91 => array( 'code' => 'SCHR-M4', 'name' => 'Schraube M4' ),
			),
			'component_stock' => array( 90 => 0, 91 => 0 ),
		);
	}

	/**
	 * Ein Bedarf über 10 Basismodule.
	 *
	 * @return array
	 */
	protected function demand( $qty = 10 ) {
		return array(
			array( 'article_id' => 1, 'qty' => $qty, 'source' => 'bestellung', 'label' => '#500' ),
		);
	}

	/**
	 * Zeile eines Artikels aus dem Ergebnis greifen.
	 *
	 * @param array  $plan Ergebnis.
	 * @param string $sku  Artikelnummer.
	 * @return array|null
	 */
	protected function row( array $plan, $sku ) {
		foreach ( $plan['articles'] as $row ) {
			if ( $sku === $row['sku'] ) {
				return $row;
			}
		}
		return null;
	}

	/* ================================================================
	 * Fertigungstiefe
	 * ============================================================== */

	/**
	 * Das Endprodukt liegt oben, der Rohling unter dem Halbzeug am tiefsten.
	 */
	public function test_tiefe_wird_richtig_bestimmt() {
		$depth = DemandPlanner::depths( $this->ctx()['bom'] );

		$this->assertSame( 0, $depth[1], 'Basismodul ist Endprodukt.' );
		$this->assertSame( 1, $depth[2], 'Halbzeug liegt eine Ebene darunter.' );
		$this->assertSame( 2, $depth[3], 'Rohling im Halbzeug liegt zwei Ebenen darunter.' );
		$this->assertSame( 1, $depth[5], 'Wandanker haengt direkt am Basismodul.' );
	}

	/**
	 * Wird ein Teil auf zwei Wegen gebraucht, zählt der tiefere – sonst
	 * würde es aufgelöst, bevor der zweite Bedarf feststeht.
	 */
	public function test_tiefstes_vorkommen_gewinnt() {
		$bom = array(
			1 => array(
				array( 'child_type' => 'artikel', 'child_id' => 2, 'qty' => 1 ),
				array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => 1 ),
			),
			2 => array( array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => 1 ) ),
		);

		$depth = DemandPlanner::depths( $bom );

		$this->assertSame( 2, $depth[3] );
	}

	/* ================================================================
	 * Auflösung über die Stufen
	 * ============================================================== */

	/**
	 * Aus 10 Basismodulen werden 10 Halbzeuge, 20 Wandanker, 20
	 * Abstandshalter und 10 Holzstangen.
	 */
	public function test_bedarf_wird_ueber_alle_stufen_aufgeloest() {
		$plan = DemandPlanner::plan( $this->demand( 10 ), $this->ctx() );

		$this->assertEqualsWithDelta( 10.0, $this->row( $plan, 'HH-BASIS' )['net'], 0.0001 );
		$this->assertEqualsWithDelta( 10.0, $this->row( $plan, 'HZ-STANGE' )['net'], 0.0001 );
		$this->assertEqualsWithDelta( 20.0, $this->row( $plan, 'ROH-ANKER' )['net'], 0.0001 );
		$this->assertEqualsWithDelta( 20.0, $this->row( $plan, 'ROH-ABST' )['net'], 0.0001 );
		$this->assertEqualsWithDelta( 10.0, $this->row( $plan, 'HOLZ-60' )['net'], 0.0001 );
	}

	/**
	 * Die Handlung folgt dem Beschaffungsweg.
	 */
	public function test_handlung_folgt_dem_beschaffungsweg() {
		$plan = DemandPlanner::plan( $this->demand( 1 ), $this->ctx() );

		$this->assertSame( 'montieren', $this->row( $plan, 'HH-BASIS' )['action'] );
		$this->assertSame( 'montieren', $this->row( $plan, 'HZ-STANGE' )['action'] );
		$this->assertSame( 'drucken', $this->row( $plan, 'ROH-ANKER' )['action'] );
		$this->assertSame( 'bestellen', $this->row( $plan, 'HOLZ-60' )['action'] );
	}

	/**
	 * Vorhandener Bestand des Halbzeugs bremst die Auflösung: Liegen sechs
	 * fertige Stangen im Regal, müssen nur vier gebaut werden – und nur
	 * dafür Rohlinge da sein.
	 */
	public function test_bestand_bremst_die_aufloesung() {
		$plan = DemandPlanner::plan( $this->demand( 10 ), $this->ctx( array( 2 => 6 ) ) );

		$this->assertEqualsWithDelta( 4.0, $this->row( $plan, 'HZ-STANGE' )['net'], 0.0001 );
		$this->assertEqualsWithDelta( 8.0, $this->row( $plan, 'ROH-ABST' )['net'], 0.0001, 'Nur fuer vier Stangen.' );
		$this->assertEqualsWithDelta( 4.0, $this->row( $plan, 'HOLZ-60' )['net'], 0.0001 );
	}

	/**
	 * Ein bereits laufender Fertigungsauftrag darf keinen zweiten Vorschlag
	 * erzeugen.
	 */
	public function test_laufende_auftraege_werden_verrechnet() {
		$plan = DemandPlanner::plan( $this->demand( 10 ), $this->ctx( array(), array( 2 => 10 ) ) );

		$stange = $this->row( $plan, 'HZ-STANGE' );

		$this->assertEqualsWithDelta( 0.0, $stange['net'], 0.0001 );
		$this->assertEqualsWithDelta( 10.0, $stange['in_work'], 0.0001 );
		$this->assertNull( $this->row( $plan, 'ROH-ABST' ), 'Ohne Fertigungsbedarf kein Rohlingsbedarf.' );
	}

	/**
	 * Ist alles vorrätig, gibt es nichts zu tun.
	 */
	public function test_voller_bestand_ergibt_nichts_zu_tun() {
		$plan = DemandPlanner::plan( $this->demand( 10 ), $this->ctx( array( 1 => 10 ) ) );

		$this->assertSame( array(), DemandPlanner::actionable( $plan['articles'] ) );
	}

	/* ================================================================
	 * Zusammenführen mehrerer Quellen
	 * ============================================================== */

	/**
	 * Bestellung, Sollbestand und Wunschliste summieren sich, bleiben aber
	 * getrennt ausweisbar.
	 */
	public function test_quellen_summieren_sich_und_bleiben_unterscheidbar() {
		$plan = DemandPlanner::plan(
			array(
				array( 'article_id' => 1, 'qty' => 4, 'source' => 'bestellung', 'label' => '#500' ),
				array( 'article_id' => 1, 'qty' => 3, 'source' => 'sollbestand', 'label' => 'anthrazit' ),
				array( 'article_id' => 1, 'qty' => 2, 'source' => 'wunschliste', 'label' => '2 Merkungen' ),
			),
			$this->ctx()
		);

		$basis = $this->row( $plan, 'HH-BASIS' );

		$this->assertEqualsWithDelta( 9.0, $basis['gross'], 0.0001 );
		$this->assertEqualsWithDelta( 4.0, $basis['sources']['bestellung']['qty'], 0.0001 );
		$this->assertEqualsWithDelta( 3.0, $basis['sources']['sollbestand']['qty'], 0.0001 );
		$this->assertEqualsWithDelta( 2.0, $basis['sources']['wunschliste']['qty'], 0.0001 );
	}

	/**
	 * Dieselbe Bestellung zweimal genannt zählt einmal je Zeile, aber die
	 * Bestellnummer erscheint nicht doppelt.
	 */
	public function test_gleiche_herkunft_wird_nicht_doppelt_genannt() {
		$plan = DemandPlanner::plan(
			array(
				array( 'article_id' => 1, 'qty' => 2, 'source' => 'bestellung', 'label' => '#500' ),
				array( 'article_id' => 1, 'qty' => 3, 'source' => 'bestellung', 'label' => '#500' ),
			),
			$this->ctx()
		);

		$basis = $this->row( $plan, 'HH-BASIS' );

		$this->assertEqualsWithDelta( 5.0, $basis['gross'], 0.0001 );
		$this->assertSame( array( '#500' ), $basis['sources']['bestellung']['labels'] );
	}

	/**
	 * Ein Teil, das an zwei Stellen steckt, wird einmal aufgeführt und
	 * einmal beschafft.
	 */
	public function test_mehrfach_verbautes_teil_wird_zusammengefasst() {
		$ctx = $this->ctx();
		// Der Wandanker steckt zusaetzlich im Halbzeug.
		$ctx['bom'][2][] = array( 'child_type' => 'artikel', 'child_id' => 5, 'qty' => 1 );

		$plan   = DemandPlanner::plan( $this->demand( 10 ), $ctx );
		$anker  = array_filter(
			$plan['articles'],
			static function ( $row ) {
				return 'ROH-ANKER' === $row['sku'];
			}
		);

		$this->assertCount( 1, $anker, 'Nur eine Zeile je Artikel.' );
		$this->assertEqualsWithDelta( 30.0, $this->row( $plan, 'ROH-ANKER' )['net'], 0.0001 );
	}

	/* ================================================================
	 * Kleinteile
	 * ============================================================== */

	/**
	 * Kleinteile werden über alle Stufen summiert und gegen ihren Bestand
	 * gerechnet.
	 */
	public function test_kleinteile_werden_summiert_und_verrechnet() {
		$ctx = $this->ctx();
		$ctx['component_stock'][91] = 25;

		$plan = DemandPlanner::plan( $this->demand( 10 ), $ctx );

		$byid = array();
		foreach ( $plan['components'] as $row ) {
			$byid[ $row['component_id'] ] = $row;
		}

		$this->assertEqualsWithDelta( 10.0, $byid[90]['gross'], 0.0001, 'Ein Montage-Set je Basismodul.' );
		$this->assertEqualsWithDelta( 40.0, $byid[91]['gross'], 0.0001, 'Vier Schrauben je Stange.' );
		$this->assertEqualsWithDelta( 15.0, $byid[91]['net'], 0.0001, '25 liegen im Regal.' );
	}

	/**
	 * Kleinteile unter einem gedeckten Halbzeug werden nicht mitbestellt –
	 * sie stecken schon darin.
	 */
	public function test_kleinteile_gedeckter_stufen_entfallen() {
		$plan = DemandPlanner::plan( $this->demand( 10 ), $this->ctx( array( 2 => 10 ) ) );

		$byid = array();
		foreach ( $plan['components'] as $row ) {
			$byid[ $row['component_id'] ] = $row;
		}

		$this->assertArrayNotHasKey( 91, $byid, 'Die Schrauben stecken in den fertigen Stangen.' );
		$this->assertArrayHasKey( 90, $byid );
	}

	/* ================================================================
	 * Robustheit
	 * ============================================================== */

	/**
	 * Ein Artikel in der Stückliste, den es im Stamm nicht gibt, wird
	 * gemeldet statt stillschweigend übergangen.
	 */
	public function test_unbekannter_artikel_wird_gemeldet() {
		$ctx = $this->ctx();
		unset( $ctx['articles'][3] );

		$plan = DemandPlanner::plan( $this->demand( 1 ), $ctx );

		$this->assertNotEmpty( $plan['warnings'] );
	}

	/**
	 * Ohne Bedarf kein Ergebnis – und kein Fehler.
	 */
	public function test_leerer_bedarf_ergibt_leeren_plan() {
		$plan = DemandPlanner::plan( array(), $this->ctx() );

		$this->assertSame( array(), $plan['articles'] );
		$this->assertSame( array(), $plan['components'] );
	}

	/**
	 * Die Ausgabe ist nach Tiefe sortiert: Was zuerst da sein muss, steht
	 * oben.
	 */
	public function test_ausgabe_ist_nach_tiefe_sortiert() {
		$plan  = DemandPlanner::plan( $this->demand( 10 ), $this->ctx() );
		$tiefe = array_column( $plan['articles'], 'depth' );

		$sorted = $tiefe;
		rsort( $sorted );

		$this->assertSame( $sorted, $tiefe );
	}
}
