<?php
/**
 * Steuer & DATEV: Kleinunternehmergrenzen, Steuersätze, Buchungsstapel.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Steuer;
use PHPUnit\Framework\TestCase;

class ErpSteuerTest extends TestCase {

	private function settings() {
		return array( 'berater' => '1234567', 'mandant' => '10001', 'sachkonten' => 4, 'wj_beginn' => '01-01', 'bank_konto' => '1200', 'kasse_konto' => '1000', 'diktat' => 'LW', 'erloes_konto' => '8195' );
	}

	public function test_ku_alles_gut() {
		$s = Steuer::ku_status( 12000, 5000, '2026-07-01' );
		$this->assertSame( 'ok', $s['level'] );
		$this->assertEqualsWithDelta( 10000, $s['prognose'], 100 );
	}

	public function test_ku_vorjahr_ueber_grenze() {
		$this->assertSame( 'bad', Steuer::ku_status( 26000, 1000, '2026-02-01' )['level'] );
	}

	public function test_ku_hochrechnung_warnt() {
		$s = Steuer::ku_status( 10000, 15000, '2026-06-30' );
		$this->assertSame( 'warn', $s['level'] );
		$this->assertGreaterThan( 25000, $s['prognose'] );
	}

	public function test_ku_laufend_ueber_100000() {
		$this->assertSame( 'bad', Steuer::ku_status( 10000, 100001, '2026-11-01' )['level'] );
	}

	public function test_steuersatz_und_summen() {
		$this->assertSame( 19, Steuer::rate_of( 100, 19 ) );
		$this->assertSame( 7, Steuer::rate_of( 100, 7.01 ) );
		$this->assertSame( 0, Steuer::rate_of( 100, 0 ) );
		$r = Steuer::by_rate( array( array( 'net' => 100, 'tax' => 19 ), array( 'net' => 50, 'tax' => 3.5 ), array( 'net' => -10, 'tax' => -1.9 ), array( 'net' => 5, 'tax' => 0 ) ) );
		$this->assertSame( array( 'net' => 90.0, 'tax' => 17.1 ), $r[19] );
		$this->assertSame( array( 'net' => 50.0, 'tax' => 3.5 ), $r[7] );
		$this->assertSame( array( 'net' => 5.0, 'tax' => 0.0 ), $r[0] );
	}

	public function test_zeitraeume() {
		$q = Steuer::periods( 2024, 'quartal' );
		$this->assertCount( 4, $q );
		$this->assertSame( '2024-03-31', $q[0]['to'] );
		$m = Steuer::periods( 2024, 'monat' );
		$this->assertSame( '2024-02-29', $m[1]['to'] );
	}

	public function test_datev_zeilen_einnahme_ausgabe_storno() {
		$b = array(
			array( 'id' => 1, 'buchungsnummer' => 'B-2026-0001', 'buchungsdatum' => '2026-10-05', 'typ' => 'einnahme', 'betrag' => 149.9, 'kategorie' => 'Einnahmen', 'beschreibung' => 'Rechnung RE-1 / Bestellung #1234', 'referenz_typ' => 'rechnung', 'storno_von_id' => null ),
			array( 'id' => 2, 'buchungsnummer' => 'B-2026-0002', 'buchungsdatum' => '2026-10-06', 'typ' => 'ausgabe', 'betrag' => 19.99, 'kategorie' => '4600 Werbekosten', 'beschreibung' => 'Flyer', 'referenz_typ' => 'beleg', 'storno_von_id' => null ),
			array( 'id' => 3, 'buchungsnummer' => 'B-2026-0003', 'buchungsdatum' => '2026-10-07', 'typ' => 'storno', 'betrag' => 19.99, 'kategorie' => '', 'beschreibung' => 'Storno', 'referenz_typ' => 'storno', 'storno_von_id' => 2 ),
			array( 'id' => 4, 'buchungsnummer' => 'B-2026-0004', 'buchungsdatum' => '2026-10-08', 'typ' => 'storno', 'betrag' => 5, 'kategorie' => '', 'beschreibung' => '', 'referenz_typ' => 'storno', 'storno_von_id' => 99 ),
		);
		$r = Steuer::datev_rows( $b, $this->settings() );

		$this->assertSame( array( 'B-2026-0004' ), $r['skipped'], 'Storno ohne Original wird gemeldet, nicht geraten' );
		$this->assertCount( 3, $r['rows'] );
		$this->assertSame( array( '149,90', 'S', 'EUR', '', '', '', '1200', '8195', '', '0510', 'B-2026-0001' ), array_slice( $r['rows'][0], 0, 11 ) );
		$this->assertSame( array( '4600', '1200' ), array( $r['rows'][1][6], $r['rows'][1][7] ) );
		$this->assertSame( array( 'H', '4600', '1200' ), array( $r['rows'][2][1], $r['rows'][2][6], $r['rows'][2][7] ) );
	}

	public function test_datev_datei_kopf_und_kodierung() {
		$s    = $this->settings();
		$head = Steuer::datev_header( $s, '2026-01-01', '2026-03-31', '20260401120000' );
		$this->assertSame( 'EXTF', $head[0] );
		$this->assertSame( 700, $head[1] );
		$this->assertSame( 21, $head[2] );
		$this->assertSame( 1234567, $head[10] );
		$this->assertSame( '20260101', $head[12] );
		$this->assertSame( '20260331', $head[15] );

		$file  = Steuer::datev_file( $head, array( array( '1,00', 'S', 'EUR', '', '', '', '1200', '8195', '', '0101', 'B1', '', '', 'Müller "groß"' ) ) );
		$lines = explode( "\r\n", $file );
		$this->assertStringStartsWith( '"EXTF";700;21;"Buchungsstapel";13;', $lines[0] );
		$this->assertStringStartsWith( 'Umsatz (ohne Soll/Haben-Kz);Soll/Haben-Kennzeichen', $lines[1] );
		$this->assertSame( mb_convert_encoding( '1,00;"S";"EUR";;;;1200;8195;;0101;"B1";"";;"Müller ""groß"""', 'Windows-1252', 'UTF-8' ), $lines[2] );
	}

	public function test_steuer_aus_brutto_und_zahllast() {
		$this->assertSame( array( 'net' => 100.0, 'tax' => 19.0 ), Steuer::vat_from_gross( 119, 19 ) );
		$this->assertSame( array( 'net' => 100.0, 'tax' => 7.0 ), Steuer::vat_from_gross( 107, 7 ) );
		$this->assertSame( array( 'net' => 50.0, 'tax' => 0.0 ), Steuer::vat_from_gross( 50, 0 ) );

		$row = Steuer::ustva_row( array( 19 => array( 'net' => 1000.0, 'tax' => 190.0 ), 7 => array( 'net' => 100.0, 'tax' => 7.0 ), 0 => array( 'net' => 5.0, 'tax' => 0.0 ) ), 250.5 );
		$this->assertSame( 197.0, $row['summe'] );
		$this->assertSame( 250.5, $row['kz66'] );
		$this->assertSame( -53.5, $row['zahllast'], 'Mehr Vorsteuer als Umsatzsteuer = Erstattung' );
	}

	public function test_datev_bu_schluessel_aus_vorsteuer() {
		$b = array(
			array( 'id' => 1, 'buchungsnummer' => 'B1', 'buchungsdatum' => '2026-10-06', 'typ' => 'ausgabe', 'betrag' => 119, 'kategorie' => '4600 Werbekosten', 'beschreibung' => 'Flyer', 'referenz_typ' => 'beleg', 'referenz_id' => 5, 'storno_von_id' => null ),
			array( 'id' => 2, 'buchungsnummer' => 'B2', 'buchungsdatum' => '2026-10-06', 'typ' => 'ausgabe', 'betrag' => 107, 'kategorie' => '3200 Wareneingang', 'beschreibung' => 'Buch', 'referenz_typ' => 'beleg', 'referenz_id' => 6, 'storno_von_id' => null ),
			array( 'id' => 3, 'buchungsnummer' => 'B3', 'buchungsdatum' => '2026-10-07', 'typ' => 'storno', 'betrag' => 119, 'kategorie' => '', 'beschreibung' => 'Storno', 'referenz_typ' => 'storno', 'referenz_id' => 1, 'storno_von_id' => 1 ),
			array( 'id' => 4, 'buchungsnummer' => 'B4', 'buchungsdatum' => '2026-10-07', 'typ' => 'einnahme', 'betrag' => 50, 'kategorie' => 'Einnahmen', 'beschreibung' => 'RE', 'referenz_typ' => 'rechnung', 'referenz_id' => 5, 'storno_von_id' => null ),
		);
		$r = Steuer::datev_rows( $b, array( 'bank_konto' => '1200', 'kasse_konto' => '1000', 'erloes_konto' => '8400' ), array( 5 => 19, 6 => 7 ) );

		$this->assertSame( array( '9', '8', '9', '' ), array_column( $r['rows'], 8 ), 'Storno trägt den Schlüssel des Originals, Einnahmen keinen' );
	}

	public function test_euer_einnahmen_aus_teilen() {
		$this->assertSame( array( 'shop' => 1000.0, 'auftraege' => 250.5, 'sonstige' => 5.0, 'kasse' => 0.0, 'summe' => 1255.5 ), \Lindenweg16\Modules\Accounting\EuerCalculator::income_parts( 1000, 250.499, 5 ) );
		$this->assertSame( 1355.5, \Lindenweg16\Modules\Accounting\EuerCalculator::income_parts( 1000, 250.5, 5, 100 )['summe'] );
	}

	public function test_datev_marktumsatz_ist_bareinnahme() {
		$b = array(
			array( 'id' => 1, 'buchungsnummer' => 'B1', 'buchungsdatum' => '2026-10-10', 'typ' => 'ausgabe', 'betrag' => 320, 'kategorie' => '8195 Erlöse als Kleinunternehmer §19 UStG', 'beschreibung' => 'Markt', 'referenz_typ' => 'beleg', 'referenz_id' => 7, 'storno_von_id' => null ),
			array( 'id' => 2, 'buchungsnummer' => 'B2', 'buchungsdatum' => '2026-10-11', 'typ' => 'einnahme', 'betrag' => 200, 'kategorie' => '8195 Erlöse als Kleinunternehmer §19 UStG', 'beschreibung' => 'Markt', 'referenz_typ' => 'beleg', 'referenz_id' => 8, 'storno_von_id' => null ),
		);
		$r = Steuer::datev_rows( $b, array( 'bank_konto' => '1200', 'kasse_konto' => '1000', 'erloes_konto' => '8195' ) );
		$this->assertSame( array( 'S', '1000', '8195' ), array( $r['rows'][0][1], $r['rows'][0][6], $r['rows'][0][7] ), 'Alte Ausgabe-Buchung wird zur Bareinnahme' );
		$this->assertSame( array( 'S', '1000', '8195' ), array( $r['rows'][1][1], $r['rows'][1][6], $r['rows'][1][7] ) );
	}
}
