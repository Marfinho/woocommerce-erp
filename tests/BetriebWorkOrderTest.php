<?php
/**
 * Fertigungsaufträge und die Frage, wann was verbraucht wird.
 *
 * Der Kern ist die Buchungsregel: Jedes Teil geht genau einmal vom Bestand
 * ab. Ein Fehler hier fällt erst Monate später auf, wenn die Inventur nicht
 * mehr stimmt – deshalb wird die Regel hier von allen Seiten geprüft.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\ComponentConsumption;
use Lindenweg16\Modules\Betrieb\PicklistRepository;
use Lindenweg16\Modules\Betrieb\WorkOrderRepository;

/**
 * Prüft Materialbedarf, Fertigbarkeit und die Verbrauchsstufe.
 */
class BetriebWorkOrderTest extends TestCase {

	/**
	 * Ein kleiner Artikelstamm: Rohling, Halbzeug, Basismodul, Zukaufstange.
	 *
	 * @return array
	 */
	protected function articles() {
		return array(
			1 => array( 'id' => 1, 'sku' => 'ROH-ANKER', 'name' => 'Wandanker Rohling', 'sourcing_type' => 'druck' ),
			2 => array( 'id' => 2, 'sku' => 'HZ-ANKER', 'name' => 'Wandanker fertig', 'sourcing_type' => 'montage' ),
			3 => array( 'id' => 3, 'sku' => 'HOLZ-60', 'name' => 'Holzstange 60', 'sourcing_type' => 'zukauf' ),
			4 => array( 'id' => 4, 'sku' => 'HZ-STANGE', 'name' => 'Stange mit Abstandshalter', 'sourcing_type' => 'montage' ),
			5 => array( 'id' => 5, 'sku' => 'HH-BASIS', 'name' => 'Basismodul', 'sourcing_type' => 'montage' ),
		);
	}

	/* ================================================================
	 * Was lässt sich fertigen?
	 * ============================================================== */

	/**
	 * Montage und Hybrid werden gefertigt, Druck und Zukauf nicht.
	 */
	public function test_nur_montage_und_hybrid_sind_fertigbar() {
		$this->assertTrue( WorkOrderRepository::is_makeable( array( 'sourcing_type' => 'montage' ) ) );
		$this->assertTrue( WorkOrderRepository::is_makeable( array( 'sourcing_type' => 'hybrid' ) ) );
		$this->assertFalse( WorkOrderRepository::is_makeable( array( 'sourcing_type' => 'druck' ) ) );
		$this->assertFalse( WorkOrderRepository::is_makeable( array( 'sourcing_type' => 'zukauf' ) ) );
		$this->assertFalse( WorkOrderRepository::is_makeable( array() ) );
	}

	/* ================================================================
	 * Materialbedarf
	 * ============================================================== */

	/**
	 * Der Bedarf ist die Stückliste mal Auftragsmenge.
	 */
	public function test_bedarf_ist_stueckliste_mal_menge() {
		$need = WorkOrderRepository::material_need(
			array(
				array( 'child_type' => 'artikel', 'child_id' => 1, 'qty' => 1 ),
				array( 'child_type' => 'komponente', 'child_id' => 20, 'qty' => 2 ),
			),
			10
		);

		$this->assertCount( 2, $need );
		$this->assertEqualsWithDelta( 10.0, $need[0]['qty'], 0.0001 );
		$this->assertEqualsWithDelta( 20.0, $need[1]['qty'], 0.0001 );
	}

	/**
	 * Aufgelöst wird nur die erste Ebene. Wer Basismodule baut, holt fertige
	 * Halbzeuge aus dem Regal – nicht die Rohlinge, aus denen sie einmal
	 * entstanden sind.
	 */
	public function test_bedarf_loest_nur_die_erste_ebene_auf() {
		$need = WorkOrderRepository::material_need(
			array(
				array( 'child_type' => 'artikel', 'child_id' => 4, 'qty' => 1 ),
				array( 'child_type' => 'artikel', 'child_id' => 2, 'qty' => 2 ),
				array( 'child_type' => 'komponente', 'child_id' => 30, 'qty' => 1 ),
			),
			5
		);

		$ids = array_column( $need, 'child_id' );

		$this->assertSame( array( 4, 2, 30 ), $ids );
		$this->assertNotContains( 1, $ids, 'Der Rohling unter dem Halbzeug gehoert nicht auf diese Liste.' );
	}

	/**
	 * Dieselbe Position zweimal in der Stückliste ergibt eine Zeile.
	 */
	public function test_doppelte_stuecklistenzeile_wird_addiert() {
		$need = WorkOrderRepository::material_need(
			array(
				array( 'child_type' => 'komponente', 'child_id' => 20, 'qty' => 2 ),
				array( 'child_type' => 'komponente', 'child_id' => 20, 'qty' => 3 ),
			),
			2
		);

		$this->assertCount( 1, $need );
		$this->assertEqualsWithDelta( 10.0, $need[0]['qty'], 0.0001 );
	}

	/**
	 * Artikel und Komponente mit derselben ID sind zwei verschiedene Dinge.
	 */
	public function test_artikel_und_komponente_werden_nicht_verwechselt() {
		$need = WorkOrderRepository::material_need(
			array(
				array( 'child_type' => 'artikel', 'child_id' => 7, 'qty' => 1 ),
				array( 'child_type' => 'komponente', 'child_id' => 7, 'qty' => 1 ),
			),
			1
		);

		$this->assertCount( 2, $need );
	}

	/**
	 * Ohne Menge kein Bedarf.
	 */
	public function test_menge_null_ergibt_keinen_bedarf() {
		$this->assertSame(
			array(),
			WorkOrderRepository::material_need( array( array( 'child_type' => 'artikel', 'child_id' => 1, 'qty' => 1 ) ), 0 )
		);
	}

	/* ================================================================
	 * Verbrauchsstufe: wann geht was ab?
	 * ============================================================== */

	/**
	 * Ein Halbzeug ist ein Fertigungsschritt. Seine Kleinteile gehen beim
	 * Fertigmelden ab, nicht beim Verkauf – sonst zweimal.
	 */
	public function test_halbzeug_gilt_als_gefertigt() {
		$ctx = array( 'articles' => $this->articles() );

		$this->assertTrue( ComponentConsumption::is_produced( 2, $ctx ), 'Halbzeug' );
		$this->assertTrue( ComponentConsumption::is_produced( 1, $ctx ), 'Rohling' );
		$this->assertFalse( ComponentConsumption::is_produced( 3, $ctx ), 'Zukauf' );
	}

	/**
	 * Bei einem montierten Artikel geht beim Verkauf gar kein Kleinteil mehr
	 * ab – weder das aus dem Halbzeug noch das eigene. Beides wurde beim
	 * jeweiligen Fertigungsauftrag verbraucht. Verkauft wird nur noch das
	 * fertige Basismodul selbst.
	 */
	public function test_bei_montage_geht_beim_verkauf_kein_kleinteil_mehr_ab() {
		$ctx = array(
			'articles' => $this->articles(),
			'bom'      => array(
				// Basismodul enthaelt das Halbzeug Stange und ein Montage-Set.
				5 => array(
					array( 'child_type' => 'artikel', 'child_id' => 4, 'qty' => 1 ),
					array( 'child_type' => 'komponente', 'child_id' => 99, 'qty' => 1 ),
				),
				// Das Halbzeug selbst enthaelt Schrauben.
				4 => array(
					array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => 1 ),
					array( 'child_type' => 'komponente', 'child_id' => 20, 'qty' => 4 ),
				),
			),
		);

		$needed = ComponentConsumption::components_at_sale( 5, 1, $ctx );

		$this->assertSame( array(), $needed, 'Alles wurde bereits beim Fertigen verbraucht.' );
	}

	/**
	 * Wird das Basismodul dagegen erst beim Packen zusammengelegt, ist es
	 * kein Fertigungsschritt, sondern ein Set – dann geht sein Montage-Set
	 * beim Verkauf ab, die Schrauben im Halbzeug aber weiterhin nicht.
	 */
	public function test_beim_set_geht_nur_die_eigene_ebene_beim_verkauf_ab() {
		$articles                     = $this->articles();
		$articles[5]['sourcing_type'] = 'zukauf';

		$ctx = array(
			'articles' => $articles,
			'bom'      => array(
				5 => array(
					array( 'child_type' => 'artikel', 'child_id' => 4, 'qty' => 1 ),
					array( 'child_type' => 'komponente', 'child_id' => 99, 'qty' => 1 ),
				),
				4 => array(
					array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => 1 ),
					array( 'child_type' => 'komponente', 'child_id' => 20, 'qty' => 4 ),
				),
			),
		);

		$needed = ComponentConsumption::components_at_sale( 5, 1, $ctx );

		$this->assertArrayHasKey( 99, $needed, 'Das Montage-Set liegt direkt unter dem Set.' );
		$this->assertArrayNotHasKey( 20, $needed, 'Die Schrauben im Halbzeug wurden beim Fertigen verbraucht.' );
	}

	/**
	 * Unterbaugruppen unter einem montierten Artikel gehen beim Verkauf
	 * ebenfalls nicht ab – der Fertigungsauftrag hat sie schon verbraucht.
	 */
	public function test_unterbaugruppen_unter_halbzeug_gehen_beim_verkauf_nicht_ab() {
		$ctx = array(
			'articles' => $this->articles(),
			'bom'      => array(
				5 => array( array( 'child_type' => 'artikel', 'child_id' => 4, 'qty' => 1 ) ),
				4 => array( array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => 1 ) ),
			),
		);

		$needed = ComponentConsumption::assemblies_at_sale( 5, 1, $ctx );

		$this->assertArrayNotHasKey( 3, $needed, 'Die Stange steckt schon im Halbzeug.' );
	}

	/**
	 * Beim Zukaufartikel als Elternteil gilt das Gegenteil: Dort gibt es
	 * keinen Fertigungsvorgang, also geht alles beim Packen ab.
	 */
	public function test_unter_zukauf_geht_alles_beim_verkauf_ab() {
		$articles      = $this->articles();
		$articles[5]['sourcing_type'] = 'zukauf';

		$ctx = array(
			'articles' => $articles,
			'bom'      => array(
				5 => array(
					array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => 1 ),
					array( 'child_type' => 'komponente', 'child_id' => 20, 'qty' => 4 ),
				),
			),
		);

		$this->assertArrayHasKey( 20, ComponentConsumption::components_at_sale( 5, 1, $ctx ) );
		$this->assertArrayHasKey( 3, ComponentConsumption::assemblies_at_sale( 5, 1, $ctx ) );
	}

	/* ================================================================
	 * Pickliste eines Fertigungsauftrags
	 * ============================================================== */

	/**
	 * Die Materialliste trägt Artikel und Kleinteile nebeneinander.
	 */
	public function test_materialliste_traegt_beide_arten() {
		$rows = PicklistRepository::condense(
			array(
				array( 'item_type' => 'artikel', 'article_id' => 4, 'color' => 'anthrazit', 'qty' => 5 ),
				array( 'item_type' => 'komponente', 'article_id' => 20, 'color' => '', 'qty' => 20 ),
			)
		);

		$this->assertCount( 2, $rows );

		$types = array_column( $rows, 'item_type' );
		$this->assertContains( 'artikel', $types );
		$this->assertContains( 'komponente', $types );
	}

	/**
	 * Artikel 20 und Komponente 20 sind zwei Zeilen, keine addierte.
	 */
	public function test_gleiche_id_verschiedene_art_bleibt_getrennt() {
		$rows = PicklistRepository::condense(
			array(
				array( 'item_type' => 'artikel', 'article_id' => 20, 'qty' => 1 ),
				array( 'item_type' => 'komponente', 'article_id' => 20, 'qty' => 1 ),
			)
		);

		$this->assertCount( 2, $rows );
	}

	/**
	 * Eine Komponente bekommt keinen Lagerplatz aus der Artikelvermutung
	 * untergeschoben – sie liegt woanders.
	 */
	public function test_komponente_erbt_keinen_artikelplatz() {
		$rows = PicklistRepository::condense(
			array( array( 'item_type' => 'komponente', 'article_id' => 20, 'qty' => 1 ) ),
			array( 20 => 5 )
		);

		$this->assertSame( 0, $rows[0]['bin_id'] );
	}
}
