<?php
/**
 * Galerie eines Sets: Hauptbilder der Module in der gewählten Variante.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\VariantGallery;
use Lindenweg16\Modules\Betrieb\Variants;
use PHPUnit\Framework\TestCase;

/**
 * Prüft VariantGallery::module_images().
 */
class BetriebVariantGalleryTest extends TestCase {

	public function test_set_shows_main_image_of_chosen_modules() {
		$articles = array(
			3  => array( 'id' => 3, 'wc_product_id' => 481, 'wc_variant' => 'pa_holz=buche', 'status' => 'aktiv' ),
			12 => array( 'id' => 12, 'wc_product_id' => 481, 'wc_variant' => 'pa_holz=walnuss', 'status' => 'aktiv' ),
			10 => array( 'id' => 10, 'wc_product_id' => 95, 'wc_variant' => 'pa_schuhsystem=spd', 'status' => 'aktiv' ),
			11 => array( 'id' => 11, 'wc_product_id' => 95, 'wc_variant' => 'pa_schuhsystem=spd-sl', 'status' => 'aktiv' ),
			23 => array( 'id' => 23, 'wc_product_id' => 485, 'wc_variant' => '', 'status' => 'aktiv' ),
			29 => array( 'id' => 29, 'wc_product_id' => null, 'wc_variant' => '', 'status' => 'aktiv' ),
		);
		$bom = array(
			23 => array(
				array( 'child_type' => 'artikel', 'child_id' => 3, 'qty' => 1 ),
				array( 'child_type' => 'artikel', 'child_id' => 10, 'qty' => 1 ),
				array( 'child_type' => 'artikel', 'child_id' => 29, 'qty' => 2 ),
				array( 'child_type' => 'komponente', 'child_id' => 7, 'qty' => 1 ),
			),
		);
		$rows = array(
			3  => array( array( 'id' => 1, 'attachment_id' => 301, 'tags' => '', 'sort_order' => 1 ) ),
			12 => array(
				array( 'id' => 2, 'attachment_id' => 1201, 'tags' => '', 'sort_order' => 1 ),
				array( 'id' => 3, 'attachment_id' => 1202, 'tags' => '', 'sort_order' => 2 ),
			),
			10 => array( array( 'id' => 4, 'attachment_id' => 1001, 'tags' => '', 'sort_order' => 1 ) ),
			11 => array(
				array( 'id' => 5, 'attachment_id' => 1101, 'tags' => 'pa_farbe=schwarz', 'sort_order' => 1 ),
				array( 'id' => 6, 'attachment_id' => 1102, 'tags' => '', 'sort_order' => 2 ),
			),
		);
		$ctx = Variants::context( $articles, $bom );

		$this->assertSame( array( 301, 1001 ), VariantGallery::module_images( 23, array( 'pa_farbe' => 'weiss' ), $rows, $ctx ) );
		$this->assertSame(
			array( 1201, 1102 ),
			VariantGallery::module_images( 23, array( 'pa_farbe' => 'weiss', 'pa_holz' => 'walnuss', 'pa_schuhsystem' => 'spd-sl' ), $rows, $ctx )
		);
		$this->assertSame(
			array( 1201, 1101 ),
			VariantGallery::module_images( 23, array( 'pa_farbe' => 'schwarz', 'pa_holz' => 'walnuss', 'pa_schuhsystem' => 'spd-sl' ), $rows, $ctx )
		);
	}
}
