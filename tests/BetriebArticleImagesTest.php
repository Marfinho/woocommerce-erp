<?php
/**
 * Fotos je Artikel: Einschränkung auf Merkmale und Auswahl je Variante.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\ArticleImages;
use PHPUnit\Framework\TestCase;

/**
 * Prüft die reinen Regeln von ArticleImages.
 */
class BetriebArticleImagesTest extends TestCase {

	public function test_tags_are_normalized_and_grouped() {
		$this->assertSame(
			array(
				'pa_farbe' => array( 'schwarz', 'weiss' ),
				'pa_holz'  => array( 'walnuss' ),
			),
			ArticleImages::parse_tags( 'pa_holz=Walnuss&pa_farbe=weiss&attribute_pa_farbe=Schwarz' )
		);
		$this->assertSame( 'pa_farbe=schwarz&pa_farbe=weiss', ArticleImages::format_tags( array( 'pa_farbe=weiss', 'pa_farbe=schwarz', 'kaputt' ) ) );
		$this->assertSame( '', ArticleImages::format_tags( array() ) );
	}

	public function test_photo_without_tags_fits_every_variant() {
		$this->assertTrue( ArticleImages::fits( array(), array( 'pa_farbe' => 'weiss' ) ) );
	}

	public function test_photo_tags_must_all_match() {
		$tags = ArticleImages::parse_tags( 'pa_farbe=weiss&pa_farbe=schwarz&pa_holz=walnuss' );

		$this->assertTrue( ArticleImages::fits( $tags, array( 'pa_farbe' => 'schwarz', 'pa_holz' => 'walnuss' ) ) );
		$this->assertFalse( ArticleImages::fits( $tags, array( 'pa_farbe' => 'schwarz', 'pa_holz' => 'buche' ) ) );
		$this->assertFalse( ArticleImages::fits( $tags, array( 'pa_farbe' => 'schwarz' ) ) );
	}

	public function test_select_keeps_order_and_filters() {
		$rows = array(
			array( 'id' => 3, 'attachment_id' => 30, 'tags' => '', 'sort_order' => 2 ),
			array( 'id' => 1, 'attachment_id' => 10, 'tags' => 'pa_farbe=schwarz', 'sort_order' => 1 ),
			array( 'id' => 2, 'attachment_id' => 20, 'tags' => 'pa_farbe=weiss', 'sort_order' => 1 ),
			array( 'id' => 4, 'attachment_id' => 30, 'tags' => '', 'sort_order' => 3 ),
		);

		$this->assertSame( array( 20, 30 ), ArticleImages::select( $rows, array( 'pa_farbe' => 'weiss' ) ) );
		$this->assertSame( array( 10, 30 ), ArticleImages::select( $rows, array( 'pa_farbe' => 'schwarz' ) ) );
		$this->assertSame( array( 30 ), ArticleImages::select( $rows, array() ) );
	}

	public function test_choices_are_grouped_without_fixed_attribute() {
		$groups = ArticleImages::group_choices(
			array(
				array( 'value' => 'pa_farbe=weiss', 'label' => 'Farbe: Weiß' ),
				array( 'value' => 'pa_farbe=schwarz', 'label' => 'Farbe: Schwarz' ),
				array( 'value' => 'pa_schuhsystem=spd', 'label' => 'Schuhsystem: SPD' ),
			),
			array( 'pa_schuhsystem' => 'spd' )
		);

		$this->assertCount( 1, $groups );
		$this->assertSame( 'Farbe', $groups[0]['label'] );
		$this->assertSame( array( 'pa_farbe=weiss', 'pa_farbe=schwarz' ), array_column( $groups[0]['values'], 'value' ) );
		$this->assertSame( 'Schwarz', $groups[0]['values'][1]['label'] );
	}
}
