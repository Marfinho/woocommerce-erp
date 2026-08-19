<?php
/**
 * Auflösung der Rabattstaffel.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\DiscountResolver;

/**
 * Standardstaffel, Sonderkonditionen, Schwellen und nicht rabattfähige
 * Positionen.
 */
class BetriebDiscountTest extends TestCase {

	/**
	 * Die Startstaffel.
	 *
	 * @return array
	 */
	protected function tiers() {
		return array(
			array( 'id' => 1, 'tier_name' => 'Stufe 1', 'min_order_value' => 150.0, 'discount_pct' => 40.0 ),
			array( 'id' => 2, 'tier_name' => 'Stufe 2', 'min_order_value' => 400.0, 'discount_pct' => 45.0 ),
			array( 'id' => 3, 'tier_name' => 'Stufe 3', 'min_order_value' => 800.0, 'discount_pct' => 50.0 ),
		);
	}

	/**
	 * Unter der ersten Schwelle gibt es keinen Rabatt.
	 */
	public function test_unter_der_ersten_schwelle_kein_rabatt() {
		$result = DiscountResolver::resolve( $this->tiers(), 149.99 );

		$this->assertSame( 0.0, $result['discount_pct'] );
		$this->assertNull( $result['tier'] );
		$this->assertEqualsWithDelta( 0.01, $result['missing'], 0.001 );
	}

	/**
	 * Genau auf der Schwelle gilt die Stufe bereits.
	 */
	public function test_genau_auf_der_schwelle() {
		$result = DiscountResolver::resolve( $this->tiers(), 150.0 );

		$this->assertSame( 40.0, $result['discount_pct'] );
		$this->assertSame( 1, $result['tier_id'] );
	}

	/**
	 * Zwischen zwei Schwellen gilt die niedrigere.
	 */
	public function test_zwischen_zwei_schwellen() {
		$result = DiscountResolver::resolve( $this->tiers(), 399.99 );

		$this->assertSame( 40.0, $result['discount_pct'] );
		$this->assertSame( 2, (int) $result['next']['id'] );
		$this->assertEqualsWithDelta( 0.01, $result['missing'], 0.001 );
	}

	/**
	 * Die höchste erreichte Stufe gewinnt.
	 */
	public function test_hoechste_erreichte_stufe() {
		$this->assertSame( 45.0, DiscountResolver::resolve( $this->tiers(), 500.0 )['discount_pct'] );
		$this->assertSame( 50.0, DiscountResolver::resolve( $this->tiers(), 2000.0 )['discount_pct'] );
	}

	/**
	 * Über der höchsten Stufe gibt es keine nächste mehr.
	 */
	public function test_keine_naechste_stufe() {
		$result = DiscountResolver::resolve( $this->tiers(), 1000.0 );

		$this->assertNull( $result['next'] );
		$this->assertSame( 0.0, $result['missing'] );
	}

	/**
	 * Ohne Stufen gibt es keinen Rabatt statt eines Fehlers.
	 */
	public function test_ohne_stufen() {
		$result = DiscountResolver::resolve( array(), 5000.0 );

		$this->assertSame( 0.0, $result['discount_pct'] );
	}

	/**
	 * Nicht rabattfähige Positionen zählen nicht in die Schwelle.
	 */
	public function test_nicht_rabattfaehige_position_zaehlt_nicht_zur_schwelle() {
		$items = array(
			array( 'qty' => 5, 'unit_price_gross' => 20.0, 'discountable' => 1 ),   // 100 €
			array( 'qty' => 2, 'unit_price_gross' => 40.0, 'discountable' => 0 ),   // 80 € personalisiert
		);

		$base = DiscountResolver::base_value( $items );

		$this->assertEqualsWithDelta( 100.0, $base['discountable'], 0.00001 );
		$this->assertEqualsWithDelta( 80.0, $base['fixed'], 0.00001 );
		$this->assertEqualsWithDelta( 180.0, $base['total'], 0.00001 );

		// 180 € Warenkorb, aber nur 100 € rabattfähig – Stufe 1 nicht erreicht.
		$this->assertSame( 0.0, DiscountResolver::resolve( $this->tiers(), $base['discountable'] )['discount_pct'] );
	}

	/**
	 * Der Rabatt greift nur auf den rabattfähigen Teil.
	 */
	public function test_rabatt_nur_auf_rabattfaehiges() {
		$items = array(
			array( 'qty' => 10, 'unit_price_gross' => 20.0, 'discountable' => 1 ),  // 200 €
			array( 'qty' => 1, 'unit_price_gross' => 50.0, 'discountable' => 0 ),   // 50 € personalisiert
		);

		// 200 € rabattfähig → Stufe 1, 40 %.
		// 200 × 0,6 = 120 € plus 50 € = 170 €.
		$this->assertEqualsWithDelta( 170.0, DiscountResolver::net_value( $items, 40.0 ), 0.00001 );
	}

	/**
	 * Der Bestellwert wird zum UVP gerechnet, vor Rabatt: Sonst fiele eine
	 * Bestellung durch den Rabatt unter die eigene Schwelle.
	 */
	public function test_schwelle_gilt_vor_rabatt() {
		$items = array( array( 'qty' => 10, 'unit_price_gross' => 16.0, 'discountable' => 1 ) ); // 160 €
		$base  = DiscountResolver::base_value( $items );

		// Zum UVP über der Schwelle von 150.
		$this->assertSame( 40.0, DiscountResolver::resolve( $this->tiers(), $base['discountable'] )['discount_pct'] );

		// Nach Rabatt wären es nur noch 96 € – die Stufe darf dadurch nicht
		// wieder verloren gehen.
		$nach_rabatt = DiscountResolver::net_value( $items, 40.0 );
		$this->assertEqualsWithDelta( 96.0, $nach_rabatt, 0.00001 );
		$this->assertLessThan( 150.0, $nach_rabatt );
	}

	/**
	 * Sonderkonditionen können eine eigene Staffel bilden.
	 */
	public function test_sonderkondition_ersetzt_die_standardstaffel() {
		$sonder = array(
			array( 'id' => 9, 'min_order_value' => 100.0, 'discount_pct' => 47.0 ),
		);

		$this->assertSame( 47.0, DiscountResolver::resolve( $sonder, 120.0 )['discount_pct'] );
		$this->assertSame( 0.0, DiscountResolver::resolve( $sonder, 99.0 )['discount_pct'] );
	}

	/**
	 * Bei gleicher Schwelle gewinnt der höhere Rabatt.
	 */
	public function test_gleiche_schwelle_hoeherer_rabatt_gewinnt() {
		$tiers = array(
			array( 'id' => 1, 'min_order_value' => 150.0, 'discount_pct' => 40.0 ),
			array( 'id' => 2, 'min_order_value' => 150.0, 'discount_pct' => 42.0 ),
		);

		$this->assertSame( 42.0, DiscountResolver::resolve( $tiers, 200.0 )['discount_pct'] );
	}

	/**
	 * Eine leere Position darf die Rechnung nicht kippen.
	 */
	public function test_leere_positionen() {
		$this->assertEqualsWithDelta( 0.0, DiscountResolver::base_value( array() )['total'], 0.00001 );
		$this->assertEqualsWithDelta( 0.0, DiscountResolver::net_value( array(), 40.0 ), 0.00001 );
	}
}
