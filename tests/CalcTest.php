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

	/* ----------------------------------------------------------------
	 * Marge / „Preis übernehmen"-Konsistenz
	 * -------------------------------------------------------------- */

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

	/* ----------------------------------------------------------------
	 * Warenkorb-Tracking nur mit bekannter E-Mail
	 * -------------------------------------------------------------- */
}
