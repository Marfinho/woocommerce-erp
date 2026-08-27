<?php
/**
 * Unit-Tests für die Kampagnenrechnung (Kostenstellen-Achse).
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Marketing\Kampagnenrechnung;
use Lindenweg16\Core\Calc;

/**
 * @covers \Lindenweg16\Modules\Marketing\Kampagnenrechnung
 */
class KampagnenrechnungTest extends TestCase {

	/* ----------------------------------------------------------------
	 * Anteilsverteilung
	 * -------------------------------------------------------------- */

	public function test_anteil_betrag_rechnet_auf_cent() {
		$this->assertSame( 33.33, Kampagnenrechnung::anteil_betrag( 100.00, 33.333 ) );
		$this->assertSame( 50.0, Kampagnenrechnung::anteil_betrag( 100.00, 50 ) );
	}

	public function test_anteil_ueber_hundert_prozent_wird_gedeckelt() {
		$this->assertSame( 80.0, Kampagnenrechnung::anteil_betrag( 80.00, 150 ) );
	}

	public function test_negativer_anteil_ergibt_null() {
		$this->assertSame( 0.0, Kampagnenrechnung::anteil_betrag( 80.00, -20 ) );
	}

	public function test_freier_anteil_eines_unverteilten_belegs() {
		$this->assertSame( 100.0, Kampagnenrechnung::freier_anteil( 0 ) );
	}

	public function test_freier_anteil_nach_teilverteilung() {
		$this->assertSame( 60.0, Kampagnenrechnung::freier_anteil( 40 ) );
	}

	public function test_vollstaendig_verteilter_beleg_hat_nichts_mehr_frei() {
		$this->assertSame( 0.0, Kampagnenrechnung::freier_anteil( 100 ) );
	}

	public function test_zulaessiger_anteil_wird_auf_den_rest_begrenzt() {
		// Flyerrechnung, 40 % hängen schon an einem anderen Markt: Wer
		// 100 % eintippt, meint den Rest.
		$this->assertSame( 60.0, Kampagnenrechnung::zulaessiger_anteil( 40, 100 ) );
	}

	public function test_zulaessiger_anteil_laesst_kleinere_wuensche_unberuehrt() {
		$this->assertSame( 25.0, Kampagnenrechnung::zulaessiger_anteil( 40, 25 ) );
	}

	public function test_kein_anteil_mehr_wenn_beleg_voll_verteilt_ist() {
		$this->assertSame( 0.0, Kampagnenrechnung::zulaessiger_anteil( 100, 50 ) );
	}

	/* ----------------------------------------------------------------
	 * Budget
	 * -------------------------------------------------------------- */

	public function test_budget_im_rahmen() {
		$b = Kampagnenrechnung::budget_status( 500, 200 );
		$this->assertSame( 300.0, $b['rest'] );
		$this->assertSame( 40.0, $b['auslastung'] );
		$this->assertSame( 'im_rahmen', $b['ampel'] );
	}

	public function test_budget_wird_knapp_ab_achtzig_prozent() {
		$this->assertSame( 'knapp', Kampagnenrechnung::budget_status( 500, 400 )['ampel'] );
	}

	public function test_ueberzogenes_budget_zeigt_negativen_rest() {
		$b = Kampagnenrechnung::budget_status( 500, 620 );
		$this->assertSame( 'ueberzogen', $b['ampel'] );
		$this->assertSame( -120.0, $b['rest'] );
	}

	public function test_ohne_budget_gibt_es_keine_auslastung() {
		$b = Kampagnenrechnung::budget_status( 0, 250 );
		$this->assertNull( $b['auslastung'] );
		$this->assertSame( 'kein_budget', $b['ampel'] );
		$this->assertSame( 250.0, $b['ist'] );
	}

	/* ----------------------------------------------------------------
	 * Kennzahlen
	 * -------------------------------------------------------------- */

	public function test_roas() {
		$this->assertSame( 3.5, Kampagnenrechnung::roas( 700, 200 ) );
	}

	public function test_roas_ohne_kosten_ist_keine_zahl() {
		// Ohne Kosten ist der Quotient nicht definiert – eine 0 oder ein
		// unendlich hoher Wert wäre hier eine erfundene Aussage.
		$this->assertNull( Kampagnenrechnung::roas( 700, 0 ) );
	}

	public function test_kosten_pro_bestellung() {
		$this->assertSame( 12.5, Kampagnenrechnung::kosten_pro_bestellung( 250, 20 ) );
	}

	public function test_kosten_pro_bestellung_ohne_bestellungen() {
		$this->assertNull( Kampagnenrechnung::kosten_pro_bestellung( 250, 0 ) );
	}

	public function test_ergebnis_kann_negativ_sein() {
		$this->assertSame( -80.0, Kampagnenrechnung::ergebnis( 320, 400 ) );
	}

	/* ----------------------------------------------------------------
	 * Kilometergeld und Kostenstellennummer
	 * -------------------------------------------------------------- */

	public function test_fahrtkosten_mit_standardpauschale() {
		$this->assertSame( 25.2, Calc::fahrtkosten( 84 ) );
	}

	public function test_fahrtkosten_mit_abweichender_pauschale() {
		$this->assertSame( 12.0, Calc::fahrtkosten( 40, 0.30 ) );
		$this->assertSame( 16.0, Calc::fahrtkosten( 40, 0.40 ) );
	}

	public function test_fahrtkosten_ohne_fahrt() {
		$this->assertSame( 0.0, Calc::fahrtkosten( 0 ) );
	}

	public function test_negative_kilometer_ergeben_keine_gutschrift() {
		$this->assertSame( 0.0, Calc::fahrtkosten( -100 ) );
	}

	public function test_kostenstellennummer_ist_dreistellig_aufgefuellt() {
		$this->assertSame( 'K-2026-004', Calc::kostenstelle( 2026, 4 ) );
		$this->assertSame( 'K-2026-042', Calc::kostenstelle( 2026, 42 ) );
	}
}
