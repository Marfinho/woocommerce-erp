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
	 * Einlöse-Aufteilung (Guthaben + Punkte)
	 * -------------------------------------------------------------- */

	public function test_split_redemption_within_basis() {
		// 10 € Guthaben + 500 Punkte (= 5 €) auf 50 € Warenkorb: alles nutzbar.
		$r = Calc::split_redemption( 10, 500, 50, 100 );
		$this->assertSame( 10.0, $r['credit'] );
		$this->assertSame( 500, $r['points'] );
		$this->assertSame( 15.0, $r['rabatt'] );
	}

	/**
	 * Der eigentliche Fehlerfall: der Rabatt wurde gedeckelt, abgebucht
	 * wurde aber der volle Wunschbetrag. Es darf nie mehr abgebucht als
	 * gewährt werden.
	 */
	public function test_split_redemption_never_deducts_more_than_granted() {
		$r = Calc::split_redemption( 50, 0, 10, 100 );
		$this->assertSame( 10.0, $r['rabatt'] );
		$this->assertSame( 10.0, $r['credit'], 'Es darf nur das abgebucht werden, was auch Rabatt wurde.' );
		$this->assertSame( 0, $r['points'] );
	}

	public function test_split_redemption_keeps_unused_points() {
		// 2 € Warenkorb, 10 € Guthaben: die Punkte werden gar nicht gebraucht.
		$r = Calc::split_redemption( 10, 1000, 2, 100 );
		$this->assertSame( 2.0, $r['credit'] );
		$this->assertSame( 0, $r['points'], 'Nicht benötigte Punkte bleiben erhalten.' );
		$this->assertSame( 2.0, $r['rabatt'] );
	}

	public function test_split_redemption_uses_credit_before_points() {
		// 5 € Guthaben + 1000 Punkte (= 10 €) auf 8 € Warenkorb.
		// Guthaben zuerst: 5 €, Rest 3 € aus 300 Punkten.
		$r = Calc::split_redemption( 5, 1000, 8, 100 );
		$this->assertSame( 5.0, $r['credit'] );
		$this->assertSame( 300, $r['points'] );
		$this->assertSame( 8.0, $r['rabatt'] );
	}

	public function test_split_redemption_points_only() {
		$r = Calc::split_redemption( 0, 250, 100, 100 );
		$this->assertSame( 0.0, $r['credit'] );
		$this->assertSame( 250, $r['points'] );
		$this->assertSame( 2.5, $r['rabatt'] );
	}

	public function test_split_redemption_zero_basis() {
		$r = Calc::split_redemption( 50, 5000, 0, 100 );
		$this->assertSame( 0.0, $r['credit'] );
		$this->assertSame( 0, $r['points'] );
		$this->assertSame( 0.0, $r['rabatt'] );
	}

	public function test_split_redemption_never_exceeds_available_points() {
		// Deckelung darf nie mehr Punkte einziehen, als gewünscht waren.
		$r = Calc::split_redemption( 0, 99, 100, 100 );
		$this->assertLessThanOrEqual( 99, $r['points'] );
		$this->assertSame( 0.99, $r['rabatt'] );
	}

	public function test_split_redemption_guards_invalid_input() {
		$r = Calc::split_redemption( -5, -100, 50, 0 );
		$this->assertSame( 0.0, $r['credit'] );
		$this->assertSame( 0, $r['points'] );
		$this->assertSame( 0.0, $r['rabatt'] );
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

	/**
	 * Baumknoten bauen (entspricht StockManager::build_tree()).
	 *
	 * @param int    $id       Produkt-ID.
	 * @param string $typ      set|modul|rohmaterial.
	 * @param float  $menge    Menge pro Elterneinheit.
	 * @param array  $children Kindknoten.
	 * @return array
	 */
	private function node( $id, $typ, $menge = 1.0, array $children = array() ) {
		return array(
			'produkt_id' => $id,
			'typ'        => $typ,
			'menge'      => $menge,
			'children'   => $children,
		);
	}

	public function test_cascade_set_has_no_own_stock_movement() {
		// Ein Set hat selbst keinen Bestand, es löst sich nur auf.
		$tree = $this->node( 1, 'set', 1.0, array(
			$this->node( 10, 'modul', 1 ),
			$this->node( 20, 'modul', 2 ),
		) );
		$result = Calc::cascade_deductions( 3, $tree );
		$this->assertArrayNotHasKey( 1, $result, 'Set darf keine eigene Bestandsbewegung erzeugen.' );
		$this->assertSame( -3.0, $result[10] );
		$this->assertSame( -6.0, $result[20] );
	}

	public function test_cascade_modules_to_raw_materials() {
		$tree = $this->node( 1, 'set', 1.0, array(
			$this->node( 10, 'modul', 1, array(
				$this->node( 100, 'rohmaterial', 0.5 ),
				$this->node( 200, 'rohmaterial', 0.1 ),
			) ),
		) );
		$result = Calc::cascade_deductions( 2, $tree );
		$this->assertSame( -2.0, $result[10] );
		$this->assertSame( -1.0, $result[100] );
		$this->assertEqualsWithDelta( -0.2, $result[200], 0.0001 );
	}

	public function test_cascade_full_chain() {
		// Set enthält 2× Modul A; Modul A braucht 50 g Rohmaterial R.
		$tree = $this->node( 1, 'set', 1.0, array(
			$this->node( 5, 'modul', 2, array(
				$this->node( 50, 'rohmaterial', 50 ),
			) ),
		) );
		$result = Calc::cascade_deductions( 1, $tree );
		$this->assertSame( -2.0, $result[5] );
		$this->assertSame( -100.0, $result[50] );
	}

	public function test_cascade_sold_module_moves_itself() {
		// Direkt verkauftes Modul (kein Set) bewegt seinen eigenen Bestand.
		$tree   = $this->node( 7, 'modul', 1.0, array( $this->node( 70, 'rohmaterial', 3 ) ) );
		$result = Calc::cascade_deductions( 2, $tree );
		$this->assertSame( -2.0, $result[7] );
		$this->assertSame( -6.0, $result[70] );
	}

	public function test_cascade_aggregates_same_product_across_branches() {
		// Dasselbe Rohmaterial in zwei Modulen muss summiert werden.
		$tree = $this->node( 1, 'set', 1.0, array(
			$this->node( 10, 'modul', 1, array( $this->node( 99, 'rohmaterial', 2 ) ) ),
			$this->node( 20, 'modul', 1, array( $this->node( 99, 'rohmaterial', 3 ) ) ),
		) );
		$result = Calc::cascade_deductions( 1, $tree );
		$this->assertSame( -5.0, $result[99] );
	}

	public function test_cascade_return_uses_positive_sign() {
		// Retoure ist das Spiegelbild des Verkaufs: gleiche Verteilung, +1.
		$tree = $this->node( 1, 'set', 1.0, array(
			$this->node( 5, 'modul', 2, array( $this->node( 50, 'rohmaterial', 50 ) ) ),
		) );
		$verkauf = Calc::cascade_deductions( 1, $tree, -1 );
		$retoure = Calc::cascade_deductions( 1, $tree, 1 );
		$this->assertSame( array_keys( $verkauf ), array_keys( $retoure ) );
		foreach ( $verkauf as $id => $delta ) {
			$this->assertSame( -1 * $delta, $retoure[ $id ], "Produkt {$id} muss sich exakt aufheben." );
		}
	}

	public function test_cascade_survives_self_referencing_set() {
		// Ein Set, das sich selbst enthält, darf nicht in eine
		// Endlosrekursion laufen, sondern muss den Ast abbrechen.
		$tree = $this->node( 1, 'set', 1.0, array(
			$this->node( 10, 'modul', 1 ),
		) );
		$tree['children'][] = $this->node( 1, 'set', 1.0, array( $this->node( 10, 'modul', 1 ) ) );

		$result = Calc::cascade_deductions( 1, $tree );
		$this->assertSame( -1.0, $result[10], 'Der zyklische Ast darf nicht zusätzlich buchen.' );
	}

	public function test_cascade_ignores_zero_and_negative_factors() {
		$tree = $this->node( 1, 'set', 1.0, array(
			$this->node( 10, 'modul', 0 ),
			$this->node( 20, 'modul', -5 ),
			$this->node( 30, 'modul', 1 ),
		) );
		$result = Calc::cascade_deductions( 1, $tree );
		$this->assertArrayNotHasKey( 10, $result );
		$this->assertArrayNotHasKey( 20, $result );
		$this->assertSame( -1.0, $result[30] );
	}

	/* ----------------------------------------------------------------
	 * Bewertungs-Punkte nur bei echter Bewertung
	 * -------------------------------------------------------------- */

	public function test_review_bonus_eligible_real_review() {
		$this->assertTrue( Calc::review_bonus_eligible( 'product', 20, 15, 42, false, true, true ) );
	}

	public function test_review_bonus_not_eligible_wrong_type() {
		$this->assertFalse( Calc::review_bonus_eligible( 'post', 20, 15, 42, false, true, true ) );
	}

	public function test_review_bonus_not_eligible_too_short() {
		$this->assertFalse( Calc::review_bonus_eligible( 'product', 10, 15, 42, false, true, true ) );
	}

	public function test_review_bonus_not_eligible_guest() {
		$this->assertFalse( Calc::review_bonus_eligible( 'product', 20, 15, 0, false, true, true ) );
	}

	public function test_review_bonus_not_eligible_already_awarded() {
		$this->assertFalse( Calc::review_bonus_eligible( 'product', 20, 15, 42, true, true, true ) );
	}

	public function test_review_bonus_not_eligible_while_unapproved() {
		// Kommentare in Moderation oder im Spam dürfen keine Punkte auslösen.
		$this->assertFalse( Calc::review_bonus_eligible( 'product', 20, 15, 42, false, false, true ) );
	}

	public function test_review_bonus_not_eligible_without_purchase() {
		$this->assertFalse( Calc::review_bonus_eligible( 'product', 20, 15, 42, false, true, false ) );
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
