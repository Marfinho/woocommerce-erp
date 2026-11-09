<?php
/**
 * Varianten: ein Shop-Produkt, mehrere Artikel; Variantentausch im Set.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\ShopStock;
use Lindenweg16\Modules\Betrieb\Variants;
use PHPUnit\Framework\TestCase;

/**
 * Prüft Variants und die Set-Mengen in ShopStock.
 */
class BetriebVariantsTest extends TestCase {

	/**
	 * Schuhhalter SPD (10) und SPD-SL (11) hinter Produkt 95, Basismodul
	 * Fichte (3) und Walnuss (12) hinter Produkt 481, Brillenhalter (1)
	 * allein hinter 97, Set GARAGE (23) hinter 485 mit BSM, SHR, BHR und
	 * einem Montageset (Komponente 7).
	 *
	 * @return array
	 */
	protected function ctx() {
		$articles = array(
			1  => array( 'id' => 1, 'sku' => 'M-BHR', 'wc_product_id' => 97, 'wc_variant' => '', 'status' => 'aktiv' ),
			3  => array( 'id' => 3, 'sku' => 'M-BSM-FICHTE', 'wc_product_id' => 481, 'wc_variant' => 'pa_holz=fichte', 'status' => 'aktiv' ),
			12 => array( 'id' => 12, 'sku' => 'M-BSM-WALNUSS', 'wc_product_id' => 481, 'wc_variant' => 'pa_holz=walnuss', 'status' => 'aktiv' ),
			10 => array( 'id' => 10, 'sku' => 'M-SHR-SPD', 'wc_product_id' => 95, 'wc_variant' => 'pa_schuhsystem=spd', 'status' => 'aktiv' ),
			11 => array( 'id' => 11, 'sku' => 'M-SHR-SPDSL', 'wc_product_id' => 95, 'wc_variant' => 'pa_schuhsystem=spd-sl', 'status' => 'aktiv' ),
			23 => array( 'id' => 23, 'sku' => 'S-GARAGE', 'wc_product_id' => 485, 'wc_variant' => '', 'status' => 'aktiv' ),
			27 => array( 'id' => 27, 'sku' => 'HZ-SHRG', 'wc_product_id' => null, 'wc_variant' => '', 'status' => 'aktiv' ),
		);
		$bom = array(
			23 => array(
				array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => 1 ),
				array( 'child_type' => 'artikel', 'child_id' => 10, 'qty' => 1 ),
				array( 'child_type' => 'artikel', 'child_id' => 1, 'qty' => 2 ),
				array( 'child_type' => 'komponente', 'child_id' => 7, 'qty' => 1 ),
			),
			10 => array(
				array( 'child_type' => 'artikel', 'child_id' => 27, 'qty' => 1 ),
			),
		);

		return Variants::context( $articles, $bom );
	}

	public function test_parse_and_format_normalize() {
		$this->assertSame( array( 'pa_holz' => 'walnuss', 'pa_schuhsystem' => 'spd-sl' ), Variants::parse( 'pa_schuhsystem=SPD-SL & attribute_pa_holz = Walnuss' ) );
		$this->assertSame( 'holz=weiss', Variants::format( array( 'Holz' => 'Weiß' ) ) );
		$this->assertSame( array(), Variants::parse( 'nur text' ) );
	}

	public function test_variation_picks_its_article() {
		$ctx = $this->ctx();

		$this->assertSame( 11, Variants::pick( $ctx['groups'][95], array( 'pa_farbe' => 'weiss', 'pa_schuhsystem' => 'spd-sl' ) )['article_id'] );
		$this->assertSame( 10, Variants::pick( $ctx['groups'][95], array( 'pa_schuhsystem' => 'spd' ) )['article_id'] );
		$this->assertSame( 1, Variants::pick( $ctx['groups'][97], array( 'pa_farbe' => 'schwarz' ) )['article_id'] );
	}

	public function test_unknown_variant_falls_back_and_is_flagged() {
		$ctx  = $this->ctx();
		$pick = Variants::pick( $ctx['groups'][95], array( 'pa_schuhsystem' => 'look-keo' ) );

		$this->assertSame( 10, $pick['article_id'] );
		$this->assertFalse( $pick['exact'] );
	}

	public function test_discontinued_article_does_not_compete() {
		$ctx                        = $this->ctx();
		$ctx['articles'][99]        = array( 'id' => 99, 'wc_product_id' => 95, 'wc_variant' => 'pa_schuhsystem=spd', 'status' => 'ausgelaufen' );
		$groups                     = Variants::groups( $ctx['articles'] );

		$this->assertArrayNotHasKey( 99, $groups[95] );
	}

	public function test_set_swaps_modules_by_choice_and_packs_at_sale() {
		$ctx = $this->ctx();

		$this->assertTrue( Variants::packs_at_sale( 23, $ctx ) );
		$this->assertFalse( Variants::packs_at_sale( 10, $ctx ) );

		$pack = Variants::pack( 23, 2, array( 'pa_holz' => 'walnuss', 'pa_schuhsystem' => 'spd-sl' ), $ctx );

		$this->assertSame( array( 12 => 2.0, 11 => 2.0, 1 => 4.0 ), $pack['articles'] );
		$this->assertSame( array( 7 => 2.0 ), $pack['components'] );
	}

	public function test_set_without_choice_keeps_bom() {
		$pack = Variants::pack( 23, 1, array(), $this->ctx() );

		$this->assertSame( array( 3 => 1.0, 10 => 1.0, 1 => 2.0 ), $pack['articles'] );
	}

	public function test_order_lines_of_set_are_its_modules() {
		$lines = Variants::lines( 485, array( 'pa_farbe' => 'schwarz', 'pa_schuhsystem' => 'spd-sl', 'pa_holz' => 'fichte' ), 'schwarz', 'variante', 1, $this->ctx() );

		$this->assertSame( array( 3, 11, 1 ), array_column( $lines, 'article_id' ) );
		$this->assertSame( array( 23, 23, 23 ), array_column( $lines, 'set_id' ) );
		$this->assertSame( array( 7 => 1.0 ), $lines[0]['components'] );
		$this->assertSame( array(), $lines[1]['components'] );
		$this->assertSame( 'schwarz', $lines[2]['color'] );
	}

	public function test_order_line_of_module_variant() {
		$lines = Variants::lines( 95, array( 'pa_schuhsystem' => 'spd-sl' ), 'weiss', 'variante', 3, $this->ctx() );

		$this->assertCount( 1, $lines );
		$this->assertSame( 11, $lines[0]['article_id'] );
		$this->assertSame( 3.0, $lines[0]['qty'] );
		$this->assertSame( array(), Variants::lines( 4711, array(), 'weiss', 'variante', 1, $this->ctx() ) );
	}

	public function test_fits_treats_any_as_wildcard() {
		$this->assertTrue( Variants::fits( array( 'pa_schuhsystem' => 'spd' ), array( 'attribute_pa_farbe' => 'weiss', 'attribute_pa_schuhsystem' => '' ) ) );
		$this->assertFalse( Variants::fits( array( 'pa_schuhsystem' => 'spd' ), array( 'attribute_pa_schuhsystem' => 'spd-sl' ) ) );
		$this->assertFalse( Variants::fits( array( 'pa_schuhsystem' => 'spd' ), array( 'attribute_pa_farbe' => 'weiss' ) ) );
	}

	public function test_shop_units_of_variant_and_packed_set() {
		$ctx  = $this->ctx();
		$free = array(
			1  => array( 'schwarz' => 5.0, 'weiss' => 1.0 ),
			3  => array( 'schwarz' => 4.0 ),
			12 => array( 'schwarz' => 1.0 ),
			10 => array( 'schwarz' => 3.0 ),
			11 => array( 'schwarz' => 0.5 ),
		);

		$this->assertSame( 0, ShopStock::variant_units( $free, 11, 'schwarz', array(), $ctx ) );
		$this->assertSame( 3, ShopStock::variant_units( $free, 10, 'schwarz', array(), $ctx ) );

		// Fichte + SPD: BSM 4, SHR 3, BHR 5/2 → 2 Sets.
		$this->assertSame( 2, ShopStock::variant_units( $free, 23, 'schwarz', array( 'pa_holz' => 'fichte', 'pa_schuhsystem' => 'spd' ), $ctx ) );
		// Walnuss + SPD-SL: SHR-SL nur 0,5 frei → kein Set.
		$this->assertSame( 0, ShopStock::variant_units( $free, 23, 'schwarz', array( 'pa_holz' => 'walnuss', 'pa_schuhsystem' => 'spd-sl' ), $ctx ) );
		// In Weiß fehlt das Basismodul.
		$this->assertSame( 0, ShopStock::variant_units( $free, 23, 'weiss', array( 'pa_holz' => 'fichte', 'pa_schuhsystem' => 'spd' ), $ctx ) );
	}

	public function test_products_touched_by_module_change_include_sets() {
		$ctx = $this->ctx();

		$this->assertEqualsCanonicalizing( array( 95, 485 ), ShopStock::products_for( array( 11 ), $ctx ) );
		$this->assertSame( array( 97 ), ShopStock::products_for( array( 1 ), Variants::context( array( 1 => $ctx['articles'][1] ), array() ) ) );
	}
}
