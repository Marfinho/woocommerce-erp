<?php
/**
 * Szenarien für Inventur und Liquiditätsvorschau, ergänzend zu
 * ErpInventurTest und ErpLiquiditaetTest: Rundungsreste, leer übergebene
 * Zählmengen, Kalenderwoche 53, alle Intervalle und Termine am Monatsende.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Tests\Szenarien;

use Lindenweg16\Erp\Pages\Inventur;
use Lindenweg16\Erp\Pages\Liquiditaet;
use PHPUnit\Framework\TestCase;

/**
 * Inventur und Liquidität.
 */
class ErpInventurLiquiditaetSzenarienTest extends TestCase {

	/* ----------------------------------------------------------- Inventur */

	public function test_vor_der_ersten_bewegung_ist_nichts_da() {
		$m = array( array( 'article_id' => 1, 'color' => 'rot', 'location' => 'lager', 'qty' => 10, 'moved_at' => '2026-01-05 08:00:00' ) );
		$this->assertSame( 0.0, Inventur::balance_at( $m, 1, 'rot', 'lager', '2026-01-01 00:00:00' ) );
	}

	public function test_leere_farbe_wird_nicht_als_leerer_schluessel_gefunden() {
		$m = array( array( 'article_id' => 1, 'color' => '', 'location' => 'lager', 'qty' => 1.5, 'moved_at' => '2026-01-02 00:00:00' ) );
		$this->assertSame( 0.0, Inventur::balance_at( $m, 1, '', 'lager', '2026-12-31 00:00:00' ) );
	}

	public function test_rundungsreste_gelten_als_null() {
		$m = array(
			array( 'article_id' => 3, 'color' => 'x', 'location' => 'l', 'qty' => 0.1, 'moved_at' => '2026-01-01' ),
			array( 'article_id' => 3, 'color' => 'x', 'location' => 'l', 'qty' => 0.2, 'moved_at' => '2026-01-01' ),
			array( 'article_id' => 3, 'color' => 'x', 'location' => 'l', 'qty' => -0.3, 'moved_at' => '2026-01-01' ),
		);
		$this->assertSame( array(), Inventur::balances_at( $m, '2026-12-31' ) );
	}

	public function test_leer_uebergebene_zaehlmenge_ist_offen() {
		$s = Inventur::summary( array( array( 'expected_qty' => 1, 'counted_qty' => '', 'unit_value' => 1.0 ) ) );
		$this->assertSame( 0, $s['counted'] );
		$this->assertSame( 1, $s['open'] );
		$this->assertSame( 1.0, $s['counted_value'] );
	}

	public function test_gezaehlte_null_ist_keine_offene_zeile() {
		$s = Inventur::summary( array( array( 'expected_qty' => 3, 'counted_qty' => 0, 'unit_value' => 1 ) ) );
		$this->assertSame( 1, $s['counted'] );
		$this->assertSame( 0, $s['open'] );
		$this->assertSame( -3.0, $s['diff_qty'] );
	}

	public function test_leere_zaehlliste() {
		$s = Inventur::summary( array() );
		$this->assertSame( 0, $s['lines'] );
		$this->assertSame( 0.0, $s['diff_value'] );
	}

	public function test_korrektur_auf_drei_nachkommastellen() {
		$this->assertSame( 0.125, Inventur::correction( 0.3754, 0.25 ) );
	}

	/* -------------------------------------------------------- Liquidität */

	public function test_wochen_ueber_den_jahreswechsel() {
		$w = Liquiditaet::weeks( '2026-12-30', 2 );
		$this->assertSame( 'KW 53', $w[0]['label'] );
		$this->assertSame( '2027-01-04', $w[1]['from'] );
		$this->assertSame( 'KW 1', $w[1]['label'] );
	}

	public function test_naechster_termin_je_intervall() {
		$this->assertSame( '2026-03-08', Liquiditaet::next_date( '2026-03-01', 'woechentlich' ) );
		$this->assertSame( '2026-04-15', Liquiditaet::next_date( '2026-03-15', 'monatlich' ) );
		$this->assertSame( '2026-06-15', Liquiditaet::next_date( '2026-03-15', 'quartalsweise' ) );
		$this->assertSame( '2026-09-15', Liquiditaet::next_date( '2026-03-15', 'halbjaehrlich' ) );
		$this->assertSame( '2027-03-15', Liquiditaet::next_date( '2026-03-15', 'jaehrlich' ) );
		$this->assertSame( '2026-04-15', Liquiditaet::next_date( '2026-03-15', 'unbekannt' ), 'Unbekannt = monatlich.' );
	}

	public function test_monatlicher_termin_am_monatsende_ueberspringt_keinen_monat() {
		// Miete am 31.: Der nächste Termin liegt im Februar, nicht am 3. März.
		$this->assertSame( '2026-02-28', Liquiditaet::next_date( '2026-01-31', 'monatlich' ) );
		$this->assertSame( '2028-02-29', Liquiditaet::next_date( '2028-01-31', 'monatlich' ) );
		$this->assertSame( '2026-11-30', Liquiditaet::next_date( '2026-08-31', 'quartalsweise' ) );
		$this->assertSame( '2027-02-28', Liquiditaet::next_date( '2026-08-31', 'halbjaehrlich' ) );
		$this->assertSame( '2029-02-28', Liquiditaet::next_date( '2028-02-29', 'jaehrlich' ) );
	}

	public function test_monatliche_reihe_am_31_kehrt_nach_dem_februar_zurueck() {
		$this->assertSame(
			array( '2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30' ),
			Liquiditaet::occurrences( '2026-01-31', 'monatlich', '2026-04-30' )
		);

		// Gespeichert ist schon der 28.02.; das Startdatum gibt den 31. vor.
		$this->assertSame(
			array( '2026-02-28', '2026-03-31' ),
			Liquiditaet::occurrences( '2026-02-28', 'monatlich', '2026-03-31', '', '2026-01-31' )
		);
	}

	public function test_termine_inklusive_horizont_ohne_start_und_begrenzt() {
		$this->assertSame( array( '2026-03-01', '2026-03-08', '2026-03-15' ), Liquiditaet::occurrences( '2026-03-01', 'woechentlich', '2026-03-15' ) );
		$this->assertSame( array(), Liquiditaet::occurrences( '', 'monatlich', '2026-04-30' ) );
		$this->assertCount( 60, Liquiditaet::occurrences( '2026-01-01', 'woechentlich', '2040-01-01' ), 'Höchstens 60 Termine.' );
	}

	public function test_woche_ohne_posten_uebernimmt_den_stand() {
		$rows = Liquiditaet::project( 500, Liquiditaet::weeks( '2026-03-02', 2 ), array( array( 'date' => '2026-03-03', 'amount' => -100, 'label' => 'x', 'kind' => 'dauer' ) ) );
		$this->assertSame( 400.0, $rows[1]['balance'] );
		$this->assertSame( array(), $rows[1]['items'] );
		$this->assertSame( 0.0, $rows[1]['in'] );
	}

	public function test_puffer_genau_erreicht_ist_nicht_darunter() {
		$this->assertSame( -1, Liquiditaet::first_below( array( array( 'balance' => 1000.0 ) ), 1000 ) );
	}
}
