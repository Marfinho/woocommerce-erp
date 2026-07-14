<?php
/**
 * Unit-Tests für die reinen Rechenregeln (Lindenweg16\Core\Calc).
 *
 * Deckt die im Auftrag geforderten Testfälle ab: Reward-Berechnung,
 * Selbstwerbungs-Schutz, Punkte-Rundung, Einlöse-Deckelung,
 * EÜR-/Rücklagen-Rechnung, Preiskalkulations-Rundung und die Konsistenz
 * zwischen „Preis übernehmen" und der im Referral-Programm genutzten Marge,
 * sowie die lückenlose Rechnungsnummern-Konvention.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Core\Calc;

/**
 * @covers \Lindenweg16\Core\Calc
 */
class CalcTest extends TestCase {

	/* ----------------------------------------------------------------
	 * Punkte-Rundung
	 * -------------------------------------------------------------- */

	public function test_points_for_amount_rounds_down_to_full_euro() {
		$this->assertSame( 12, Calc::points_for_amount( 12.99 ) );
		$this->assertSame( 0, Calc::points_for_amount( 0.99 ) );
		$this->assertSame( 25, Calc::points_for_amount( 25.00 ) );
	}

	public function test_points_for_amount_with_factor() {
		$this->assertSame( 24, Calc::points_for_amount( 12.50, 2 ) );
	}

	public function test_points_for_amount_never_negative() {
		$this->assertSame( 0, Calc::points_for_amount( -5 ) );
	}

	public function test_points_to_euro_floors_to_cent() {
		// 250 Punkte bei 100 Punkte = 1 € -> 2,50 €.
		$this->assertSame( 2.5, Calc::points_to_euro( 250, 100 ) );
		// 199 Punkte -> 1,99 € (kein Aufrunden).
		$this->assertSame( 1.99, Calc::points_to_euro( 199, 100 ) );
	}

	public function test_points_to_euro_guards_zero_divisor() {
		$this->assertSame( (float) 50, Calc::points_to_euro( 50, 0 ) );
	}

	/* ----------------------------------------------------------------
	 * Reward-Berechnung
	 * -------------------------------------------------------------- */

	public function test_referral_reward_basic() {
		// 100 € Position × 40 % Marge × 5 % = 2,00 €.
		$this->assertSame( 2.0, Calc::referral_reward( 100, 40, 5 ) );
	}

	public function test_referral_reward_rounds_two_decimals() {
		// 33,33 € × 40 % × 5 % = 0,66666 -> 0,67 €.
		$this->assertSame( 0.67, Calc::referral_reward( 33.33, 40, 5 ) );
	}

	public function test_referral_reward_zero_margin() {
		$this->assertSame( 0.0, Calc::referral_reward( 100, 0, 5 ) );
	}

	/* ----------------------------------------------------------------
	 * Selbstwerbungs-Schutz
	 * -------------------------------------------------------------- */

	public function test_self_referral_same_user_id() {
		$this->assertTrue( Calc::is_self_referral( 5, 5, 'a@x.de', 'b@x.de' ) );
	}

	public function test_self_referral_same_email_case_insensitive() {
		$this->assertTrue( Calc::is_self_referral( 5, 9, 'Sven@X.de', ' sven@x.de ' ) );
	}

	public function test_self_referral_distinct_users() {
		$this->assertFalse( Calc::is_self_referral( 5, 9, 'a@x.de', 'b@x.de' ) );
	}

	public function test_self_referral_guest_without_email_match() {
		// Gast (ID 0) mit anderer E-Mail -> kein Selbstverweis.
		$this->assertFalse( Calc::is_self_referral( 5, 0, 'a@x.de', 'b@x.de' ) );
	}

	/* ----------------------------------------------------------------
	 * Einlöse-Deckelung
	 * -------------------------------------------------------------- */

	public function test_cap_redemption_within_total() {
		$this->assertSame( 10.0, Calc::cap_redemption( 10, 50 ) );
	}

	public function test_cap_redemption_capped_to_total() {
		$this->assertSame( 50.0, Calc::cap_redemption( 80, 50 ) );
	}

	public function test_cap_redemption_never_below_zero() {
		$this->assertSame( 0.0, Calc::cap_redemption( -5, 50 ) );
		$this->assertSame( 0.0, Calc::cap_redemption( 10, 0 ) );
	}

	/* ----------------------------------------------------------------
	 * Preiskalkulation
	 * -------------------------------------------------------------- */

	public function test_drucker_kosten_pro_stunde() {
		// 500/5000 + 0,02 + 0,15*0,35 = 0,1 + 0,02 + 0,0525 = 0,1725.
		$this->assertEqualsWithDelta( 0.1725, Calc::drucker_kosten_pro_stunde( 500, 5000, 0.02, 0.15, 0.35 ), 0.0001 );
	}

	public function test_drucker_kosten_guards_zero_lifetime() {
		$this->assertEqualsWithDelta( 0.02, Calc::drucker_kosten_pro_stunde( 500, 0, 0.02, 0, 0 ), 0.0001 );
	}

	public function test_modul_kosten_with_scrap_buffer() {
		$args = array(
			'filament_gramm'      => 50,
			'filamentpreis_pro_kg' => 20,   // 50 g -> 1,00 €.
			'drucker_kosten_h'    => 0.1725,
			'druckzeit_stunden'   => 2,     // 0,345 €.
			'arbeitszeit_minuten' => 15,
			'stundenlohn'         => 16,    // 0,25 h * 16 = 4,00 €.
			'verpackung_euro'     => 0.5,
			'zusatzmaterial_euro' => 0.25,
			'ausschuss_prozent'   => 5,
		);
		// Zwischensumme = 1 + 0,345 + 4 + 0,5 + 0,25 = 6,095; ×1,05 = 6,39975 -> 6,40.
		$this->assertSame( 6.4, Calc::modul_kosten( $args ) );
	}

	public function test_preis_aus_marge_rounds_to_full_euro() {
		// 6,40 / (1 - 0,5) = 12,80 -> 13.
		$this->assertSame( 13.0, Calc::preis_aus_marge( 6.4, 50 ) );
	}

	public function test_set_preis_applies_discount_and_rounds() {
		// (13 + 13) * (1 - 0,1) = 23,4 -> 23.
		$this->assertSame( 23.0, Calc::set_preis( 26, 10 ) );
	}

	/* ----------------------------------------------------------------
	 * Marge / „Preis übernehmen"-Konsistenz
	 * -------------------------------------------------------------- */

	public function test_marge_prozent() {
		// Kosten 6,40, Preis 13 -> (13-6,4)/13 = 50,77 %.
		$this->assertSame( 50.77, Calc::marge_prozent( 6.4, 13 ) );
	}

	public function test_marge_prozent_zero_price() {
		$this->assertSame( 0.0, Calc::marge_prozent( 5, 0 ) );
	}

	public function test_preis_uebernehmen_matches_referral_margin() {
		// Der beim „Preis übernehmen" gesetzte Preis und die daraus
		// berechnete Marge müssen konsistent sein: die Marge, die ins
		// Referral-Programm geschrieben wird, ist marge_prozent(kosten, preis).
		$kosten = Calc::modul_kosten(
			array(
				'filament_gramm'      => 50,
				'filamentpreis_pro_kg' => 20,
				'drucker_kosten_h'    => 0.1725,
				'druckzeit_stunden'   => 2,
				'arbeitszeit_minuten' => 15,
				'stundenlohn'         => 16,
				'verpackung_euro'     => 0.5,
				'zusatzmaterial_euro' => 0.25,
				'ausschuss_prozent'   => 5,
			)
		);
		$preis = Calc::preis_aus_marge( $kosten, 50 );
		$marge = Calc::marge_prozent( $kosten, $preis );
		// Reward mit genau dieser Marge muss stabil reproduzierbar sein.
		$reward = Calc::referral_reward( $preis, $marge, 5 );
		$this->assertGreaterThan( 0, $reward );
		// Gegenprobe: Reward = Preis × Marge% × 5%.
		$this->assertSame( round( $preis * ( $marge / 100 ) * 0.05, 2 ), $reward );
	}

	/* ----------------------------------------------------------------
	 * EÜR / Rücklage
	 * -------------------------------------------------------------- */

	public function test_ruecklage() {
		$this->assertSame( 310.0, Calc::ruecklage( 1240, 25 ) );
	}

	public function test_ruecklage_negative_profit_is_zero() {
		$this->assertSame( 0.0, Calc::ruecklage( -500, 25 ) );
	}

	/* ----------------------------------------------------------------
	 * Rechnungsnummern-Konvention (Lückenlosigkeit / Format)
	 * -------------------------------------------------------------- */

	public function test_rechnungsnummer_yearly_format() {
		$this->assertSame( '2026-0001', Calc::rechnungsnummer( 'jahr', 2026, 1 ) );
		$this->assertSame( '2026-0042', Calc::rechnungsnummer( 'jahr', 2026, 42 ) );
	}

	public function test_rechnungsnummer_continuous_format() {
		$this->assertSame( 'RE-00007', Calc::rechnungsnummer( 'durchlaufend', 2026, 7 ) );
	}

	public function test_rechnungsnummer_sequence_is_gapless() {
		// Simuliert fortlaufende Vergabe: jede Nummer folgt lückenlos.
		$prev = 0;
		for ( $i = 1; $i <= 5; $i++ ) {
			$nummer = Calc::rechnungsnummer( 'jahr', 2026, $i );
			$lauf   = (int) substr( $nummer, -4 );
			$this->assertSame( $prev + 1, $lauf );
			$prev = $lauf;
		}
	}

	/* ----------------------------------------------------------------
	 * Lagerabzug bei Sets/Stücklisten (Kaskade)
	 * -------------------------------------------------------------- */

	public function test_cascade_deductions_set_to_modules() {
		$stueckliste = array(
			array( 'modul_id' => 10, 'menge' => 1 ),
			array( 'modul_id' => 20, 'menge' => 2 ),
		);
		$rezepturen = array();
		$result = Calc::cascade_deductions( 3, $stueckliste, $rezepturen );
		$this->assertSame( -3.0, $result[10] );
		$this->assertSame( -6.0, $result[20] );
	}

	public function test_cascade_deductions_modules_to_raw_materials() {
		$stueckliste = array(
			array( 'modul_id' => 10, 'menge' => 1 ),
		);
		$rezepturen = array(
			10 => array(
				array( 'rohmaterial_id' => 100, 'menge' => 0.5 ),
				array( 'rohmaterial_id' => 200, 'menge' => 0.1 ),
			),
		);
		$result = Calc::cascade_deductions( 2, $stueckliste, $rezepturen );
		// Modul 10: -2.
		$this->assertSame( -2.0, $result[10] );
		// Rohmaterial 100: 2 × 0.5 = -1.0.
		$this->assertSame( -1.0, $result[100] );
		// Rohmaterial 200: 2 × 0.1 = -0.2.
		$this->assertEqualsWithDelta( -0.2, $result[200], 0.0001 );
	}

	public function test_cascade_deductions_full_chain() {
		// Set enthält 2× Modul A; Modul A braucht 50g Rohmaterial R.
		$stueckliste = array(
			array( 'modul_id' => 5, 'menge' => 2 ),
		);
		$rezepturen = array(
			5 => array(
				array( 'rohmaterial_id' => 50, 'menge' => 50 ),
			),
		);
		// Verkauf von 1 Set.
		$result = Calc::cascade_deductions( 1, $stueckliste, $rezepturen );
		$this->assertSame( -2.0, $result[5] );
		$this->assertSame( -100.0, $result[50] );
	}

	/* ----------------------------------------------------------------
	 * Bewertungs-Punkte nur bei echter Bewertung
	 * -------------------------------------------------------------- */

	public function test_review_bonus_eligible_real_review() {
		$this->assertTrue( Calc::review_bonus_eligible( 'product', 20, 15, 42, false ) );
	}

	public function test_review_bonus_not_eligible_wrong_type() {
		$this->assertFalse( Calc::review_bonus_eligible( 'post', 20, 15, 42, false ) );
	}

	public function test_review_bonus_not_eligible_too_short() {
		$this->assertFalse( Calc::review_bonus_eligible( 'product', 10, 15, 42, false ) );
	}

	public function test_review_bonus_not_eligible_guest() {
		$this->assertFalse( Calc::review_bonus_eligible( 'product', 20, 15, 0, false ) );
	}

	public function test_review_bonus_not_eligible_already_awarded() {
		$this->assertFalse( Calc::review_bonus_eligible( 'product', 20, 15, 42, true ) );
	}

	/* ----------------------------------------------------------------
	 * Warteliste: nur einmal benachrichtigen
	 * -------------------------------------------------------------- */

	public function test_waitlist_should_notify_first_time() {
		$this->assertTrue( Calc::waitlist_should_notify( false ) );
	}

	public function test_waitlist_should_not_notify_again() {
		$this->assertFalse( Calc::waitlist_should_notify( true ) );
	}

	/* ----------------------------------------------------------------
	 * Warenkorb-Tracking nur mit bekannter E-Mail
	 * -------------------------------------------------------------- */

	public function test_cart_should_track_with_email() {
		$this->assertTrue( Calc::cart_should_track( 'test@example.com', false, false ) );
	}

	public function test_cart_should_not_track_without_email() {
		$this->assertFalse( Calc::cart_should_track( '', false, false ) );
	}

	public function test_cart_should_not_track_invalid_email() {
		$this->assertFalse( Calc::cart_should_track( 'not-an-email', false, false ) );
	}

	public function test_cart_should_not_track_empty_cart() {
		$this->assertFalse( Calc::cart_should_track( 'test@example.com', true, false ) );
	}

	public function test_cart_should_not_track_admin_request() {
		$this->assertFalse( Calc::cart_should_track( 'test@example.com', false, true ) );
	}
}
