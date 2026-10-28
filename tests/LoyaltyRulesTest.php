<?php
/**
 * Tests: Regeln des Prämienprogramms (Einstellungen, Aktionen, Geburtstag, Bewertungsfotos).
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Loyalty\LoyaltyRules;
use Lindenweg16\Modules\Loyalty\ReviewPhotos;
use PHPUnit\Framework\TestCase;

class LoyaltyRulesTest extends TestCase {

	private function campaign( $faktor, array $cats = array(), $name = 'Aktion', $von = '2026-10-01', $bis = '2026-10-31' ) {
		return array( 'name' => $name, 'von' => $von, 'bis' => $bis, 'faktor' => $faktor, 'kategorien' => $cats );
	}

	public function test_order_points_without_campaign_match_points_per_euro() {
		$r = LoyaltyRules::order_points( 49.99, array( array( 'total' => 49.99, 'cats' => array( 3 ) ) ), 1, array() );
		$this->assertSame( 49, $r['points'] );
		$this->assertSame( 0, $r['bonus'] );
		$this->assertSame( array(), $r['names'] );
	}

	public function test_shop_wide_double_points_double_the_item_share() {
		// 40 € Ware + 5 € Versand: verdoppelt wird nur die Ware.
		$r = LoyaltyRules::order_points( 45, array( array( 'total' => 40, 'cats' => array() ) ), 1, array( $this->campaign( 2, array(), 'Herbst' ) ) );
		$this->assertSame( 85, $r['points'] );
		$this->assertSame( 45, $r['base'] );
		$this->assertSame( 40, $r['bonus'] );
		$this->assertSame( array( 'Herbst' ), $r['names'] );
	}

	public function test_category_campaign_only_counts_matching_lines() {
		$lines = array(
			array( 'total' => 20, 'cats' => array( 5 ) ),
			array( 'total' => 30, 'cats' => array( 6 ) ),
		);
		$r = LoyaltyRules::order_points( 50, $lines, 1, array( $this->campaign( 3, array( 5 ) ) ) );
		$this->assertSame( 90, $r['points'] ); // 50 + 20 × 2.
	}

	public function test_overlapping_campaigns_do_not_stack() {
		$r = LoyaltyRules::order_points(
			10,
			array( array( 'total' => 10, 'cats' => array( 5 ) ) ),
			1,
			array( $this->campaign( 2, array(), 'Shop' ), $this->campaign( 3, array( 5 ), 'Kategorie' ) )
		);
		$this->assertSame( 30, $r['points'] );
		$this->assertSame( array( 'Kategorie' ), $r['names'] );
	}

	public function test_points_per_euro_factor_applies_after_campaign() {
		$r = LoyaltyRules::order_points( 10, array( array( 'total' => 10, 'cats' => array() ) ), 2, array( $this->campaign( 1.5 ) ) );
		$this->assertSame( 30, $r['points'] ); // (10 + 5) × 2.
	}

	public function test_active_on_includes_start_and_end_day() {
		$c = array( $this->campaign( 2, array(), 'A', '2026-10-10', '2026-10-12' ) );
		$this->assertCount( 0, LoyaltyRules::active_on( $c, '2026-10-09' ) );
		$this->assertCount( 1, LoyaltyRules::active_on( $c, '2026-10-10' ) );
		$this->assertCount( 1, LoyaltyRules::active_on( $c, '2026-10-12' ) );
		$this->assertCount( 0, LoyaltyRules::active_on( $c, '2026-10-13' ) );
	}

	public function test_campaign_validation() {
		$ok = LoyaltyRules::sanitize_campaign( array( 'name' => ' Herbst ', 'von' => '2026-10-01', 'bis' => '2026-10-31', 'faktor' => '1,5', 'kategorien' => array( '5', 5, 0 ) ), array( 5, 6 ) );
		$this->assertSame( '', $ok['error'] );
		$this->assertSame( 'Herbst', $ok['campaign']['name'] );
		$this->assertSame( 1.5, $ok['campaign']['faktor'] );
		$this->assertSame( array( 5 ), $ok['campaign']['kategorien'] );

		$this->assertNull( LoyaltyRules::sanitize_campaign( array( 'name' => '', 'von' => '2026-10-01', 'bis' => '2026-10-31', 'faktor' => 2 ) )['campaign'] );
		$this->assertNull( LoyaltyRules::sanitize_campaign( array( 'name' => 'X', 'von' => '2026-10-31', 'bis' => '2026-10-01', 'faktor' => 2 ) )['campaign'] );
		$this->assertNull( LoyaltyRules::sanitize_campaign( array( 'name' => 'X', 'von' => '2026-02-30', 'bis' => '2026-03-01', 'faktor' => 2 ) )['campaign'] );
		$this->assertNull( LoyaltyRules::sanitize_campaign( array( 'name' => 'X', 'von' => '2026-10-01', 'bis' => '2026-10-02', 'faktor' => 1 ) )['campaign'] );
		$this->assertNull( LoyaltyRules::sanitize_campaign( array( 'name' => 'X', 'von' => '2026-10-01', 'bis' => '2026-10-02', 'faktor' => 11 ) )['campaign'] );
		$this->assertNull( LoyaltyRules::sanitize_campaign( array( 'name' => 'X', 'von' => '2026-10-01', 'bis' => '2026-10-02', 'faktor' => 2, 'kategorien' => array( 9 ) ), array( 5 ) )['campaign'] );
	}

	public function test_rule_values_are_checked_and_bounded() {
		$r = LoyaltyRules::sanitize( array( 'punkte_pro_euro' => '1,5', 'punkte_registrierung' => '50', 'review_nur_kaeufer' => 0, 'unbekannt' => 5 ) );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( array( 'punkte_pro_euro' => 1.5, 'punkte_registrierung' => 50, 'review_nur_kaeufer' => 0 ), $r['values'] );

		$bad = LoyaltyRules::sanitize( array( 'punkte_einloesewert' => '0', 'punkte_newsletter' => '-5', 'punkte_geburtstag' => 'viele' ) );
		$this->assertCount( 3, $bad['errors'] );
		$this->assertSame( array(), $bad['values'] );
	}

	public function test_every_field_has_a_known_group_and_suggestion_inside_bounds() {
		$groups = LoyaltyRules::groups();
		foreach ( LoyaltyRules::fields() as $key => $def ) {
			$this->assertArrayHasKey( $def['group'], $groups, $key );
			$this->assertGreaterThanOrEqual( $def['min'], $def['suggest'], $key );
			$this->assertLessThanOrEqual( $def['max'], $def['suggest'], $key );
		}
	}

	public function test_birthday() {
		$this->assertTrue( LoyaltyRules::is_birthday( '10-28', '2026-10-28' ) );
		$this->assertFalse( LoyaltyRules::is_birthday( '10-28', '2026-10-27' ) );
		// 29. Februar: in Nicht-Schaltjahren am 28.
		$this->assertTrue( LoyaltyRules::is_birthday( '02-29', '2026-02-28' ) );
		$this->assertFalse( LoyaltyRules::is_birthday( '02-29', '2028-02-28' ) );
		$this->assertTrue( LoyaltyRules::is_birthday( '02-29', '2028-02-29' ) );
		$this->assertFalse( LoyaltyRules::is_birthday( '', '2026-10-28' ) );
	}

	public function test_review_photo_upload_is_normalized_and_checked() {
		$files = ReviewPhotos::normalize(
			array(
				'name'     => array( 'a.jpg', '', 'b.PNG' ),
				'type'     => array( 'image/jpeg', '', 'image/png' ),
				'tmp_name' => array( '/tmp/a', '', '/tmp/b' ),
				'error'    => array( UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK ),
				'size'     => array( 1000, 0, 2000 ),
			)
		);
		$this->assertCount( 2, $files );
		$this->assertSame( '', ReviewPhotos::problem( $files, 3, 5000 ) );
		$this->assertNotSame( '', ReviewPhotos::problem( $files, 1, 5000 ) );
		$this->assertNotSame( '', ReviewPhotos::problem( $files, 3, 1500 ) );

		$evil = ReviewPhotos::normalize( array( 'name' => array( 'x.php' ), 'type' => array( 'image/jpeg' ), 'tmp_name' => array( '/tmp/x' ), 'error' => array( 0 ), 'size' => array( 10 ) ) );
		$this->assertNotSame( '', ReviewPhotos::problem( $evil, 3, 5000 ) );
		$this->assertSame( array(), ReviewPhotos::normalize( null ) );
	}
}
