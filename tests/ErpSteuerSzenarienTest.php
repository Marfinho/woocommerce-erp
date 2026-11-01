<?php
/**
 * Szenarien für Steuer & DATEV, ergänzend zu ErpSteuerTest: Grenzwerte der
 * Kleinunternehmerregelung, Hochrechnung im Schaltjahr, Rundung bei
 * Steuersätzen, Kasse/Konten im Buchungsstapel und das Dateiformat.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Tests\Szenarien;

use Lindenweg16\Erp\Pages\Steuer;
use PHPUnit\Framework\TestCase;

/**
 * Steuer und DATEV.
 */
class ErpSteuerSzenarienTest extends TestCase {

	/**
	 * DATEV-Einstellungen.
	 *
	 * @return array
	 */
	private function datev_settings() {
		return array( 'bank_konto' => '1200', 'kasse_konto' => '1000', 'erloes_konto' => '8400' );
	}

	/**
	 * Eine Buchung.
	 *
	 * @param array $over Abweichungen.
	 * @return array
	 */
	private function buchung( array $over ) {
		return array_merge(
			array(
				'id'             => 1,
				'buchungsnummer' => 'BU-2026-0001',
				'buchungsdatum'  => '2026-03-07 10:00:00',
				'typ'            => 'einnahme',
				'betrag'         => 119.0,
				'kategorie'      => 'Shop',
				'beschreibung'   => 'Bestellung 1001',
				'referenz_typ'   => 'bank',
				'referenz_id'    => 0,
				'storno_von_id'  => 0,
			),
			$over
		);
	}

	public function test_kleinunternehmer_text_und_quote() {
		$st = Steuer::ku_status( 12000, 3000, '2026-03-31' );

		$this->assertCount( 1, $st['text'] );
		$this->assertStringContainsString( 'Beide Grenzen', $st['text'][0] );
		$this->assertSame( 48.0, $st['quote_vorjahr'] );
	}

	public function test_vorjahresgrenze_genau_erreicht_ist_noch_erlaubt() {
		$this->assertSame( 'ok', Steuer::ku_status( 25000, 0, '2026-01-01' )['level'] );
		$this->assertSame( 'bad', Steuer::ku_status( 25000.01, 0, '2026-01-01' )['level'] );
	}

	public function test_laufendes_jahr_ueber_25000_warnt_fuer_das_folgejahr() {
		$st = Steuer::ku_status( 10000, 26000, '2026-11-01' );

		$this->assertSame( 'warn', $st['level'] );
		$this->assertStringContainsString( '2027', implode( ' ', $st['text'] ) );
	}

	public function test_hochrechnung_im_schaltjahr_und_am_jahresende() {
		$this->assertSame( 1000.0, Steuer::ku_status( 0, 1000, '2026-12-31' )['prognose'] );
		$this->assertSame( 1000.0, Steuer::ku_status( 0, 1000, '2028-12-31' )['prognose'] );
		$this->assertSame( 366.0, Steuer::ku_status( 0, 1, '2028-01-01' )['prognose'] );
		$this->assertSame( 365.0, Steuer::ku_status( 0, 1, '2026-01-01' )['prognose'] );
	}

	public function test_warnung_und_kritisch_zusammen_bleibt_kritisch() {
		$st = Steuer::ku_status( 30000, 90000, '2026-10-01' );

		$this->assertSame( 'bad', $st['level'] );
		$this->assertGreaterThanOrEqual( 3, count( $st['text'] ) );
	}

	public function test_steuersatz_bei_erstattung_rundung_und_kleinstbetraegen() {
		$this->assertSame( 0, Steuer::rate_of( 0, 0 ) );
		$this->assertSame( 19, Steuer::rate_of( -100, -19 ), 'Erstattung.' );
		$this->assertSame( 7, Steuer::rate_of( 10.08, 0.71 ), 'Rundungsdifferenz bei 7 %.' );
		$this->assertSame( 19, Steuer::rate_of( 8.39, 1.60 ), 'Rundungsdifferenz bei 19 %.' );
		$this->assertSame( 0, Steuer::rate_of( 0.004, 0.001 ) );
	}

	public function test_leere_liste_hat_alle_saetze_mit_null() {
		$out = Steuer::by_rate( array() );
		$this->assertSame( array( 19, 7, 0 ), array_keys( $out ) );
		foreach ( array( 19, 7, 0 ) as $rate ) {
			$this->assertSame( array( 'net' => 0.0, 'tax' => 0.0 ), $out[ $rate ] );
		}
	}

	public function test_zeitraeume_bezeichnungen_und_quartalsenden() {
		$months = Steuer::periods( 2028, 'monat' );
		$this->assertCount( 12, $months );
		$this->assertSame( 'Februar', $months[1]['label'] );
		$this->assertSame( '2028-12-31', $months[11]['to'] );

		$q = Steuer::periods( 2026, 'quartal' );
		$this->assertSame( array( '2026-04-01', '2026-06-30' ), array( $q[1]['from'], $q[1]['to'] ) );
		$this->assertSame( array( '2026-10-01', '2026-12-31' ), array( $q[3]['from'], $q[3]['to'] ) );
		$this->assertSame( '3. Quartal', $q[2]['label'] );
	}

	public function test_zeitraeume_schliessen_lueckenlos_aneinander() {
		$months = Steuer::periods( 2026, 'monat' );
		for ( $i = 1; $i < 12; $i++ ) {
			$this->assertSame( gmdate( 'Y-m-d', strtotime( $months[ $i - 1 ]['to'] . ' +1 day' ) ), $months[ $i ]['from'] );
		}
	}

	public function test_steuer_aus_krummem_brutto_verliert_keinen_cent() {
		$odd = Steuer::vat_from_gross( 9.99, 19 );
		$this->assertSame( 1.6, $odd['tax'] );
		$this->assertSame( 9.99, round( $odd['net'] + $odd['tax'], 2 ) );
	}

	public function test_voranmeldung_kennzahlen_und_rundung_der_vorsteuer() {
		$vat = array(
			19 => array( 'net' => 1000.0, 'tax' => 190.0 ),
			7  => array( 'net' => 200.0, 'tax' => 14.0 ),
			0  => array( 'net' => 30.0, 'tax' => 0.0 ),
		);

		$row = Steuer::ustva_row( $vat, 54.444 );
		$this->assertSame( 1000.0, $row['kz81'] );
		$this->assertSame( 190.0, $row['ust19'] );
		$this->assertSame( 200.0, $row['kz86'] );
		$this->assertSame( 14.0, $row['ust7'] );
		$this->assertSame( 30.0, $row['frei'] );
		$this->assertSame( 54.44, $row['kz66'] );
		$this->assertSame( 149.56, $row['zahllast'] );
	}

	public function test_konto_aus_kategorie() {
		$this->assertSame( '4930', Steuer::account_of( array( 'kategorie' => '4930 Bürobedarf', 'typ' => 'ausgabe' ), '8400' ) );
		$this->assertSame( '4900', Steuer::account_of( array( 'kategorie' => 'Bürobedarf', 'typ' => 'ausgabe' ), '8400' ) );
		$this->assertSame( '8400', Steuer::account_of( array( 'kategorie' => 'Shop', 'typ' => 'einnahme' ), '8400' ) );
		$this->assertSame( '4900', Steuer::account_of( array( 'kategorie' => '123 Kurz', 'typ' => 'ausgabe' ), '8400' ), 'Drei Ziffern sind keine Kontonummer.' );
		$this->assertSame( '6815', Steuer::account_of( array( 'kategorie' => '4930x', 'typ' => 'ausgabe' ), '8400', '6815' ) );
	}

	public function test_datev_bar_bezahlte_ausgabe_aufwand_an_kasse() {
		$res = Steuer::datev_rows(
			array( $this->buchung( array( 'typ' => 'ausgabe', 'betrag' => -23.8, 'kategorie' => '4930 Bürobedarf', 'referenz_typ' => 'kasse' ) ) ),
			$this->datev_settings()
		);
		$row = $res['rows'][0];

		$this->assertSame( '23,80', $row[0], 'Betrag ohne Vorzeichen.' );
		$this->assertSame( '4930', $row[6] );
		$this->assertSame( '1000', $row[7] );
	}

	public function test_datev_beleg_ohne_bekannten_steuersatz_ohne_bu_schluessel() {
		$res = Steuer::datev_rows( array( $this->buchung( array( 'typ' => 'ausgabe', 'referenz_typ' => 'beleg', 'referenz_id' => 9 ) ) ), $this->datev_settings(), array( 7 => 19 ) );
		$this->assertSame( '', $res['rows'][0][8] );
	}

	public function test_datev_storno_mit_original_aus_frueherem_zeitraum() {
		$storno = $this->buchung(
			array(
				'id'            => 9,
				'typ'           => 'storno',
				'storno_von_id' => 3,
				'original'      => $this->buchung( array( 'id' => 3, 'typ' => 'einnahme' ) ),
			)
		);
		$res    = Steuer::datev_rows( array( $storno ), $this->datev_settings() );

		$this->assertSame( array( 'H', '1200', '8400' ), array( $res['rows'][0][1], $res['rows'][0][6], $res['rows'][0][7] ) );
	}

	public function test_datev_nullbuchungen_entfallen_und_felder_sind_datev_tauglich() {
		$res = Steuer::datev_rows(
			array(
				$this->buchung( array( 'betrag' => 0.004 ) ),
				$this->buchung( array( 'buchungsnummer' => 'BU/2026 Ä#0002', 'beschreibung' => '   ', 'kategorie' => str_repeat( 'Lang ', 20 ) ) ),
			),
			$this->datev_settings()
		);

		$this->assertCount( 1, $res['rows'] );
		$this->assertSame( 'BU/20260002', $res['rows'][0][10], 'Nur Zeichen, die DATEV im Belegfeld erlaubt.' );
		$this->assertSame( 60, mb_strlen( $res['rows'][0][13] ), 'Buchungstext höchstens 60 Zeichen, sonst Kategorie.' );
	}

	public function test_datev_kopfzeile_mit_abweichendem_wirtschaftsjahr_und_grenzen() {
		$head = Steuer::datev_header(
			array( 'berater' => '1234567', 'mandant' => '00042', 'sachkonten' => 12, 'wj_beginn' => '07-01', 'diktat' => 'LWX' ),
			'2026-07-01',
			'2026-09-30',
			'20261001123000'
		);

		$this->assertSame( '20261001123000000', $head[5] );
		$this->assertSame( 42, $head[11] );
		$this->assertSame( '20260701', $head[12] );
		$this->assertSame( 8, $head[13], 'Sachkontenlänge höchstens 8.' );
		$this->assertSame( '20260701', $head[14] );
		$this->assertSame( 'LW', $head[17] );

		$small = Steuer::datev_header( array( 'berater' => '', 'mandant' => '', 'sachkonten' => 2, 'wj_beginn' => 'Januar', 'diktat' => '' ), '2026-01-01', '2026-01-31', '20260201000000' );
		$this->assertSame( 4, $small[13], 'Sachkontenlänge mindestens 4.' );
		$this->assertSame( '20260101', $small[12], 'Unlesbarer Wirtschaftsjahresbeginn = 1. Januar.' );
	}

	public function test_datev_zeile_anfuehrungszeichen() {
		$this->assertSame( '"a""b";12;"x"', Steuer::datev_line( array( 'a"b', '12', 'x' ) ) );
		$this->assertSame( '"12";;7', Steuer::datev_line( array( '12', '', 7 ), array( 0 ) ) );
	}

	public function test_datev_datei_endet_mit_crlf_und_hat_14_spalten() {
		$file  = Steuer::datev_file( array( 'EXTF', 700 ), array() );
		$lines = explode( "\r\n", $file );

		$this->assertStringEndsWith( "\r\n", $file );
		$this->assertCount( 3, $lines );
		$this->assertSame( 14, count( explode( ';', $lines[1] ) ) );
	}
}
