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

	public function test_default_tiers_are_valid_and_tier_lookup_works() {
		$check = LoyaltyRules::sanitize_tiers( LoyaltyRules::default_tiers() );
		$this->assertSame( '', $check['error'] );
		$tiers = $check['tiers'];

		$r = LoyaltyRules::tier_for( 0, $tiers );
		$this->assertSame( 0, $r['index'] );
		$this->assertSame( 100.0, $r['missing'] );

		$r = LoyaltyRules::tier_for( 260, $tiers );
		$this->assertSame( 'Geölt', $r['tier']['name'] );
		$this->assertSame( 'Meisterstück', $r['next']['name'] );
		$this->assertSame( 240.0, $r['missing'] );

		$r = LoyaltyRules::tier_for( 9999, $tiers );
		$this->assertNull( $r['next'] );
		$this->assertSame( 0.0, $r['missing'] );
	}

	public function test_tier_validation() {
		$t = function ( $ab, $f, $n = 'X' ) {
			return array( 'name' => $n, 'ab' => $ab, 'faktor' => $f );
		};
		$this->assertNull( LoyaltyRules::sanitize_tiers( array( $t( 0, 1 ) ) )['tiers'] );
		$this->assertNull( LoyaltyRules::sanitize_tiers( array( $t( 10, 1 ), $t( 20, 1.2 ) ) )['tiers'] );
		$this->assertNull( LoyaltyRules::sanitize_tiers( array( $t( 0, 1 ), $t( 0, 1.2 ) ) )['tiers'] );
		$this->assertNull( LoyaltyRules::sanitize_tiers( array( $t( 0, 1.5 ), $t( 100, 1.2 ) ) )['tiers'] );
		$this->assertNull( LoyaltyRules::sanitize_tiers( array( $t( 0, 1 ), $t( 100, 4 ) ) )['tiers'] );
		$this->assertNull( LoyaltyRules::sanitize_tiers( array( $t( 0, 1 ), $t( 100, 1.2, ' ' ) ) )['tiers'] );
		$ok = LoyaltyRules::sanitize_tiers( array( $t( '0', '1' ), $t( '99,5', '1,25' ) ) );
		$this->assertSame( 99.5, $ok['tiers'][1]['ab'] );
		$this->assertSame( 1.25, $ok['tiers'][1]['faktor'] );
	}

	public function test_apply_tier_rounds_down() {
		$this->assertSame( 110, LoyaltyRules::apply_tier( 100, 1.1 ) );
		$this->assertSame( 13, LoyaltyRules::apply_tier( 9, 1.5 ) );
		$this->assertSame( 50, LoyaltyRules::apply_tier( 50, 0.5 ) );
	}

	public function test_expiry_counts_from_last_activity_but_not_before_start() {
		$day   = 86400;
		$start = gmmktime( 0, 0, 0, 1, 1, 2026 );
		$old   = gmmktime( 0, 0, 0, 1, 1, 2024 );

		// Verfall frisch eingeschaltet: alte Aktivität zählt ab Start.
		$r = LoyaltyRules::expiry_state( $old, $start, 12, 30, $start + $day );
		$this->assertSame( 'ok', $r['state'] );
		$this->assertSame( gmmktime( 0, 0, 0, 1, 1, 2027 ), $r['expires'] );

		$this->assertSame( 'remind', LoyaltyRules::expiry_state( $old, $start, 12, 30, $r['expires'] - 10 * $day )['state'] );
		$this->assertSame( 'ok', LoyaltyRules::expiry_state( $old, $start, 12, 0, $r['expires'] - 10 * $day )['state'] );
		$this->assertSame( 'expire', LoyaltyRules::expiry_state( $old, $start, 12, 30, $r['expires'] )['state'] );

		// Neue Aktivität verlängert.
		$later = gmmktime( 0, 0, 0, 6, 1, 2026 );
		$this->assertSame( gmmktime( 0, 0, 0, 6, 1, 2027 ), LoyaltyRules::expiry_state( $later, $start, 12, 30, $later )['expires'] );

		$this->assertSame( 'never', LoyaltyRules::expiry_state( $old, $start, 0, 30, time() )['state'] );
	}

	public function test_profile_missing() {
		$full = array( 'first_name' => 'Sven', 'last_name' => 'M', 'address_1' => 'Lindenweg 16', 'postcode' => '31559', 'city' => 'Haste', 'phone' => '0123' );
		$this->assertSame( array(), LoyaltyRules::profile_missing( $full ) );
		$full['phone'] = ' ';
		unset( $full['city'] );
		$this->assertSame( array( 'Ort', 'Telefon' ), LoyaltyRules::profile_missing( $full ) );
	}

	public function test_anniversary_years() {
		$this->assertSame( 1, LoyaltyRules::anniversary_years( '2025-10-28', '2026-10-28' ) );
		$this->assertSame( 0, LoyaltyRules::anniversary_years( '2025-10-28', '2026-10-27' ) );
		$this->assertSame( 0, LoyaltyRules::anniversary_years( '2026-10-28', '2026-10-28' ) );
		$this->assertSame( 2, LoyaltyRules::anniversary_years( '2024-02-29', '2026-02-28' ) );
		$this->assertSame( 0, LoyaltyRules::anniversary_years( '-', '2026-10-28' ) );
	}

	public function test_redeem_request_is_bounded_by_balance() {
		$this->assertSame( array( 'credit' => 12.5, 'points' => 300 ), LoyaltyRules::redeem_request( true, 300, 12.5, 1240 ) );
		$this->assertSame( array( 'credit' => 0.0, 'points' => 1240 ), LoyaltyRules::redeem_request( false, 99999, 12.5, 1240 ) );
		$this->assertSame( array( 'credit' => 0.0, 'points' => 0 ), LoyaltyRules::redeem_request( false, -50, 12.5, 1240 ) );
		$this->assertSame( array( 'credit' => 0.0, 'points' => 0 ), LoyaltyRules::redeem_request( true, 10, -3, 0 ) );
	}

	public function test_redeem_split_only_books_what_the_capped_discount_uses() {
		// Passt in die Summe: alles wie gewünscht.
		$this->assertSame( array( 'credit' => 5.0, 'points' => 300, 'euro' => 8.0 ), LoyaltyRules::redeem_split( 5, 300, 100, 20 ) );

		// 50 € Guthaben, 20 € Warenkorb: nur 20 € Guthaben, keine Punkte.
		$this->assertSame( array( 'credit' => 20.0, 'points' => 0, 'euro' => 20.0 ), LoyaltyRules::redeem_split( 50, 1000, 100, 20 ) );

		// 5 € Guthaben + 2.000 Punkte (20 €), 12,34 € Warenkorb: 5 € + 734 Punkte.
		$this->assertSame( array( 'credit' => 5.0, 'points' => 734, 'euro' => 12.34 ), LoyaltyRules::redeem_split( 5, 2000, 100, 12.34 ) );

		// Ungerader Einlösewert: aufrunden, damit der Rabatt gedeckt ist.
		$r = LoyaltyRules::redeem_split( 0, 1000, 30, 10 );
		$this->assertSame( 300, $r['points'] );
	}
}
