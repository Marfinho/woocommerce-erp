<?php
/**
 * Kleinteilverbrauch: genau einmal je Teil.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\ComponentConsumption;

/**
 * Ein Gewindeeinsatz darf nicht beim Drucken und noch einmal beim Verkaufen
 * vom Bestand abgehen.
 */
class BetriebComponentConsumptionTest extends TestCase {

	/**
	 * Die Stange als Zukauf-Baugruppe mit zwei gedruckten Unterbaugruppen.
	 *
	 * @return array
	 */
	protected function ctx() {
		return array(
			'articles' => array(
				100 => array( 'id' => 100, 'sku' => 'HH-STANGE', 'sourcing_type' => 'zukauf' ),
				101 => array( 'id' => 101, 'sku' => 'HH-ABST', 'sourcing_type' => 'druck' ),
				1   => array( 'id' => 1, 'sku' => 'HH-001', 'sourcing_type' => 'druck' ),
				3   => array( 'id' => 3, 'sku' => 'HH-HYB', 'sourcing_type' => 'hybrid' ),
			),
			'bom'      => array(
				// Stange: gedruckte Abstandshalter plus eigene Kleinteile.
				100 => array(
					array( 'child_type' => 'artikel', 'child_id' => 101, 'qty' => 2 ),
					array( 'child_type' => 'komponente', 'child_id' => 21, 'qty' => 4 ),
					array( 'child_type' => 'komponente', 'child_id' => 23, 'qty' => 1 ),
				),
				// Abstandshalter: eigener Gewindeeinsatz.
				101 => array(
					array( 'child_type' => 'komponente', 'child_id' => 10, 'qty' => 1 ),
				),
				// Modul: Gewindeeinsatz und Klemmschraube.
				1   => array(
					array( 'child_type' => 'komponente', 'child_id' => 10, 'qty' => 1 ),
					array( 'child_type' => 'komponente', 'child_id' => 11, 'qty' => 1 ),
				),
				3   => array(
					array( 'child_type' => 'komponente', 'child_id' => 11, 'qty' => 2 ),
				),
			),
		);
	}

	/**
	 * Beim Verkauf der Stange gehen nur ihre eigenen Kleinteile ab – die
	 * Gewindeeinsätze der Abstandshalter wurden beim Drucken verbucht.
	 */
	public function test_verkauf_bucht_nur_kleinteile_der_zukaufbaugruppe() {
		$needed = ComponentConsumption::components_at_sale( 100, 1, $this->ctx() );

		$this->assertSame( array( 21 => 4.0, 23 => 1.0 ), $needed );
		$this->assertArrayNotHasKey( 10, $needed, 'Der Gewindeeinsatz des Abstandshalters gehört zum Druckjob.' );
	}

	/**
	 * Der Verkauf eines gedruckten Moduls bucht gar keine Kleinteile – sie
	 * sind beim Druckjob abgegangen.
	 */
	public function test_verkauf_eines_gedruckten_artikels_bucht_nichts() {
		$this->assertSame( array(), ComponentConsumption::components_at_sale( 1, 5, $this->ctx() ) );
	}

	/**
	 * Auch ein Hybridartikel fertigt selbst und bucht dort ab.
	 */
	public function test_hybridartikel_bucht_beim_druckjob() {
		$this->assertSame( array(), ComponentConsumption::components_at_sale( 3, 2, $this->ctx() ) );
	}

	/**
	 * Mengen vervielfachen sich über die Stückliste.
	 */
	public function test_mengen_vervielfachen_sich() {
		$needed = ComponentConsumption::components_at_sale( 100, 3, $this->ctx() );

		$this->assertEqualsWithDelta( 12.0, $needed[21], 0.00001 );
		$this->assertEqualsWithDelta( 3.0, $needed[23], 0.00001 );
	}

	/**
	 * Ist ein Artikel unbekannt, gilt er nicht als gefertigt – seine
	 * Kleinteile gehen dann beim Verkauf ab, statt gar nicht.
	 */
	public function test_unbekannter_artikel_gilt_nicht_als_gefertigt() {
		$ctx = $this->ctx();
		unset( $ctx['articles'][101] );

		$needed = ComponentConsumption::components_at_sale( 100, 1, $ctx );

		$this->assertArrayHasKey( 10, $needed, 'Lieber einmal zu viel abbuchen als den Verbrauch zu verlieren.' );
	}

	/**
	 * Beim Verkauf der Stange gehen ihre gedruckten Unterbaugruppen vom
	 * Bestand ab – sonst wüchse er unbegrenzt weiter.
	 */
	public function test_verkauf_bucht_unterbaugruppen_ab() {
		$needed = ComponentConsumption::assemblies_at_sale( 100, 1, $this->ctx() );

		$this->assertSame( array( 101 => 2.0 ), $needed );
	}

	/**
	 * Ein gedrucktes Modul hat keine Unterbaugruppen im Verkauf – wären
	 * welche vorhanden, wären sie beim Druckjob eingebaut worden.
	 */
	public function test_gedruckter_artikel_bucht_beim_verkauf_keine_baugruppen() {
		$this->assertSame( array(), ComponentConsumption::assemblies_at_sale( 1, 3, $this->ctx() ) );
	}

	/**
	 * Mengen der Unterbaugruppen vervielfachen sich mit der Bestellmenge.
	 */
	public function test_unterbaugruppen_mengen() {
		$needed = ComponentConsumption::assemblies_at_sale( 100, 4, $this->ctx() );

		$this->assertEqualsWithDelta( 8.0, $needed[101], 0.00001 );
	}

	/**
	 * Beim Fertigen werden die direkten Unterbaugruppen eingebaut.
	 */
	public function test_produktion_baut_direkte_unterbaugruppen_ein() {
		$rows = $this->ctx()['bom'][100];

		$this->assertSame( array( 101 => 6.0 ), ComponentConsumption::assemblies_at_production( $rows, 3 ) );
	}

	/**
	 * Ohne Unterbaugruppen bleibt die Liste leer.
	 */
	public function test_produktion_ohne_unterbaugruppen() {
		$rows = $this->ctx()['bom'][1];

		$this->assertSame( array(), ComponentConsumption::assemblies_at_production( $rows, 5 ) );
	}

	/**
	 * Jede Unterbaugruppe wird genau einmal verbucht: entweder beim Fertigen
	 * des Elternteils oder beim Verkauf, nie zweimal.
	 */
	public function test_unterbaugruppe_genau_einmal() {
		$ctx = $this->ctx();

		// Die Stange wird nicht gefertigt – also beim Verkauf.
		$beim_verkauf = ComponentConsumption::assemblies_at_sale( 100, 1, $ctx );
		$this->assertArrayHasKey( 101, $beim_verkauf );

		// Beim Fertigen des Abstandshalters entsteht kein weiterer Abgang
		// derselben Baugruppe.
		$beim_fertigen = ComponentConsumption::assemblies_at_production( $ctx['bom'][101], 1 );
		$this->assertSame( array(), $beim_fertigen );
	}

	/**
	 * Der Beschaffungsweg entscheidet, wo gebucht wird.
	 */
	public function test_is_produced() {
		$ctx = $this->ctx();

		$this->assertTrue( ComponentConsumption::is_produced( 1, $ctx ) );
		$this->assertTrue( ComponentConsumption::is_produced( 3, $ctx ) );
		$this->assertFalse( ComponentConsumption::is_produced( 100, $ctx ) );
		$this->assertFalse( ComponentConsumption::is_produced( 999, $ctx ) );
	}
}
