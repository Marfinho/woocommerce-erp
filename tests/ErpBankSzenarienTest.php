<?php
/**
 * Szenarien für den Bankabgleich, ergänzend zu ErpBankTest: weitere
 * Bank-Exporte, CAMT-Sonderfälle, Sicherheit beim XML, Fingerabdrücke und
 * Grenzfälle bei Vorschlägen und Regeln.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Tests\Szenarien;

use Lindenweg16\Erp\Pages\Bank;
use PHPUnit\Framework\TestCase;

/**
 * Bank.
 */
class ErpBankSzenarienTest extends TestCase {

	/**
	 * Umsatz.
	 *
	 * @param array $over Abweichungen.
	 * @return array
	 */
	private function tx( array $over = array() ) {
		return array_merge(
			array(
				'booking_date' => '2026-03-10',
				'amount'       => 119.0,
				'purpose'      => '',
				'counterparty' => '',
				'end_to_end'   => '',
			),
			$over
		);
	}

	/**
	 * Kandidat.
	 *
	 * @param array $over Abweichungen.
	 * @return array
	 */
	private function cand( array $over = array() ) {
		return array_merge(
			array(
				'type'   => 'auftrag',
				'id'     => 1,
				'label'  => 'Auftrag',
				'amount' => 119.0,
				'refs'   => array(),
				'name'   => '',
				'date'   => '',
			),
			$over
		);
	}

	/* ---------------------------------------------------------------- CSV */

	public function test_csv_valuta_iban_mit_leerzeichen_und_mehrfachem_leerraum() {
		$csv = "\"Umsätze Girokonto\";\"Kontonummer: DE89 3704 0044 0532 0130 00\"\n"
			. "\"Buchungstag\";\"Valutadatum\";\"Verwendungszweck\";\"Beguenstigter/Zahlungspflichtiger\";\"Kontonummer/IBAN\";\"Betrag\"\n"
			. "\"02.03.26\";\"03.03.26\";\"Rechnung 2026-0042  danke\";\"Max Muster\";\"DE02 1203 0000 0000 2020 51\";\"1.190,00\"\n";

		$row = Bank::parse_csv( $csv )['rows'][0];

		$this->assertSame( 'DE89370400440532013000', Bank::parse_csv( $csv )['iban'] );
		$this->assertSame( '2026-03-03', $row['value_date'] );
		$this->assertSame( 'Rechnung 2026-0042 danke', $row['purpose'] );
		$this->assertSame( 'DE02120300000000202051', $row['counterparty_iban'] );
	}

	public function test_csv_mit_soll_haben_ohne_betrag_und_ohne_waehrungsspalte() {
		$csv = "Buchungsdatum;Auftraggeber;Soll;Haben\n01.04.2026;Kunde A;;250,00\n03.04.2026;Leer;;\n";
		$out = Bank::parse_csv( $csv );

		$this->assertCount( 1, $out['rows'], 'Zeile ohne Betrag fällt weg.' );
		$this->assertSame( 'EUR', $out['rows'][0]['currency'] );
	}

	public function test_englische_csv_mit_komma() {
		$csv = "Date,Payee,Amount,Currency,Payment reference\n2026-04-01,Stripe,\"1,234.56\",eur,Payout\n";
		$out = Bank::parse_csv( $csv );

		$this->assertSame( 1234.56, $out['rows'][0]['amount'] );
		$this->assertSame( 'EUR', $out['rows'][0]['currency'] );
		$this->assertSame( 'Stripe', $out['rows'][0]['counterparty'] );
		$this->assertSame( 'Payout', $out['rows'][0]['purpose'] );
	}

	public function test_saldenzeilen_ohne_datum_sind_keine_umsaetze() {
		$csv = "Buchungstag;Betrag;Zweck\n01.05.2026;12,50-;Gebühr\nAnfangssaldo;1.000,00;\n";
		$out = Bank::parse_csv( $csv );

		$this->assertCount( 1, $out['rows'] );
		$this->assertSame( -12.5, $out['rows'][0]['amount'] );
	}

	public function test_leere_datei_und_nur_ueberschrift() {
		$this->assertNotSame( '', Bank::parse_csv( '' )['error'] );

		$only_head = Bank::parse_csv( "Buchungstag;Betrag\n" );
		$this->assertNotSame( '', $only_head['error'] );
		$this->assertSame( array(), $only_head['rows'] );
	}

	public function test_ueberschriften_werden_vereinheitlicht() {
		$this->assertSame( 'beguenstigterzahlungspflichtiger', Bank::norm_head( '"Begünstigter/Zahlungspflichtiger"' ) );
		$this->assertSame( 'betrageur', Bank::norm_head( " Betrag (EUR)\t" ) );
		$this->assertSame( 'buchungstag', Bank::norm_head( "\xEF\xBB\xBFBuchungstag" ) );
	}

	/* --------------------------------------------------------------- CAMT */

	/**
	 * CAMT.053 mit Schlusssaldo im Soll, einem Eingang mit zwei Zweckzeilen
	 * und einem Ausgang mit Zeitstempel und Zusatzinfo.
	 *
	 * @return string
	 */
	private function camt() {
		return '<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">
 <BkToCstmrStmt><Stmt>
  <Acct><Id><IBAN>DE89 3704 0044 0532 0130 00</IBAN></Id></Acct>
  <Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp><Amt Ccy="EUR">250.50</Amt><CdtDbtInd>DBIT</CdtDbtInd><Dt><Dt>2026-03-31</Dt></Dt></Bal>
  <Ntry>
   <Amt Ccy="EUR">119.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts><Cd>BOOK</Cd></Sts>
   <BookgDt><Dt>2026-03-05</Dt></BookgDt><ValDt><Dt>2026-03-06</Dt></ValDt>
   <NtryDtls><TxDtls>
    <Refs><EndToEndId>RE-2026-0042</EndToEndId></Refs>
    <RltdPties><Dbtr><Pty><Nm>Erika Mustermann</Nm></Pty></Dbtr><Cdtr><Pty><Nm>Lindenweg16</Nm></Pty></Cdtr></RltdPties>
    <RmtInf><Ustrd>Rechnung 2026-0042</Ustrd><Ustrd>Danke</Ustrd></RmtInf>
   </TxDtls></NtryDtls>
  </Ntry>
  <Ntry>
   <Amt Ccy="EUR">45.90</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
   <BookgDt><DtTm>2026-03-07T10:15:00</DtTm></BookgDt>
   <NtryDtls><TxDtls>
    <RltdPties><Cdtr><Nm>Druckstoff GmbH</Nm></Cdtr><CdtrAcct><Id><IBAN>DE12 5001 0517 0648 4898 90</IBAN></Id></CdtrAcct></RltdPties>
   </TxDtls></NtryDtls>
   <AddtlNtryInf>SEPA Lastschrift</AddtlNtryInf>
  </Ntry>
 </Stmt></BkToCstmrStmt>
</Document>';
	}

	public function test_kein_camt_bei_csv_oder_html() {
		$this->assertFalse( Bank::is_camt( "Buchungstag;Betrag\n01.01.2026;1,00" ) );
		$this->assertFalse( Bank::is_camt( '<html><body>Login</body></html>' ) );
	}

	public function test_camt_schlusssaldo_im_soll_und_iban_mit_leerzeichen() {
		$out = Bank::parse_camt( $this->camt() );

		$this->assertSame( 'DE89370400440532013000', $out['iban'] );
		$this->assertSame( -250.5, $out['closing_balance'], 'Schlusssaldo im Soll ist negativ.' );
	}

	public function test_camt_eingang_mit_valuta_mehreren_zweckzeilen_und_referenz() {
		$row = Bank::parse_camt( $this->camt() )['rows'][0];

		$this->assertSame( '2026-03-06', $row['value_date'] );
		$this->assertSame( 'Erika Mustermann', $row['counterparty'], 'Bei Eingang der Zahler, nicht wir selbst.' );
		$this->assertSame( 'Rechnung 2026-0042 Danke', $row['purpose'] );
		$this->assertSame( 'RE-2026-0042', $row['end_to_end'] );
		$this->assertSame( 'EUR', $row['currency'] );
	}

	public function test_camt_ausgang_mit_zeitstempel_empfaengerkonto_und_zusatzinfo() {
		$row = Bank::parse_camt( $this->camt() )['rows'][1];

		$this->assertSame( '2026-03-07', $row['booking_date'], 'Datum aus DtTm.' );
		$this->assertSame( 'DE12500105170648489890', $row['counterparty_iban'] );
		$this->assertSame( 'SEPA Lastschrift', $row['purpose'] );
	}

	public function test_camt_ohne_umsaetze() {
		$empty = Bank::parse_camt( '<Document><BkToCstmrStmt><Stmt></Stmt></BkToCstmrStmt></Document>' );
		$this->assertNotSame( '', $empty['error'] );
		$this->assertSame( array(), $empty['rows'] );
	}

	public function test_camt_externe_entitaeten_werden_nicht_geladen() {
		$xml = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
			. '<Document><BkToCstmrStmt><Stmt><Ntry><Amt Ccy="EUR">1.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>'
			. '<BookgDt><Dt>2026-01-01</Dt></BookgDt><AddtlNtryInf>&x;</AddtlNtryInf></Ntry></Stmt></BkToCstmrStmt></Document>';
		$out = Bank::parse_camt( $xml );

		foreach ( $out['rows'] as $row ) {
			$this->assertStringNotContainsString( 'root:', $row['purpose'] );
		}
	}

	/* ------------------------------------------------------ Fingerabdrücke */

	public function test_fingerabdruck_unabhaengig_von_der_schreibweise_der_iban() {
		$rows = array( $this->tx( array( 'purpose' => 'A' ) ), $this->tx( array( 'purpose' => 'A' ) ), $this->tx( array( 'purpose' => 'B' ) ) );
		$this->assertSame( Bank::fingerprints( $rows, 'de89' ), Bank::fingerprints( $rows, 'DE89' ) );
	}

	public function test_fingerabdruck_ignoriert_leerraum_und_gross_klein() {
		$a = $this->tx( array( 'purpose' => 'Rechnung 42', 'counterparty' => 'Max Muster' ) );
		$b = $this->tx( array( 'purpose' => "RECHNUNG\n42", 'counterparty' => 'max  muster' ) );
		$this->assertSame( Bank::fingerprint( $a, 'DE89' ), Bank::fingerprint( $b, 'DE89' ) );

		$c = $this->tx( array( 'purpose' => 'Rechnung 42', 'counterparty' => 'Max Muster', 'amount' => 119.01 ) );
		$this->assertNotSame( Bank::fingerprint( $a, 'DE89' ), Bank::fingerprint( $c, 'DE89' ) );
		$this->assertNotSame( Bank::fingerprint( $a, 'DE89' ), Bank::fingerprint( $a, 'DE12' ) );
	}

	/* ------------------------------------------------------- Zuordnung */

	public function test_namensvergleich_ohne_rechtsform_und_fuellwoerter() {
		$this->assertSame( array( 'mueller', 'soehne' ), Bank::words( 'Müller & Söhne GmbH & Co. KG' ) );
		$this->assertSame( array( 'erika', 'mustermann' ), Bank::words( 'Frau Erika Mustermann' ) );
		$this->assertSame( array(), Bank::words( 'AB UG' ) );
	}

	public function test_nummer_allein_reicht_nicht_und_wird_vor_satzzeichen_erkannt() {
		$tx = $this->tx( array( 'amount' => 50.0, 'purpose' => 'Bestellung #1042, Teilzahlung' ) );
		$this->assertSame( array(), Bank::suggest( $tx, array( $this->cand( array( 'amount' => 60.0, 'refs' => array( '#1042' ) ) ) ) ) );

		$out = Bank::suggest( $tx, array( $this->cand( array( 'amount' => 50.0, 'refs' => array( '#1042' ) ) ) ) );
		$this->assertSame( 90, $out[0]['score'] );
	}

	public function test_vorschlaege_beste_zuerst_und_begrenzt() {
		$tx    = $this->tx( array( 'purpose' => 'RE-7', 'counterparty' => 'Max Muster' ) );
		$cands = array(
			$this->cand( array( 'id' => 1 ) ),
			$this->cand( array( 'id' => 2, 'name' => 'Max Muster' ) ),
			$this->cand( array( 'id' => 3, 'refs' => array( 'RE-7' ), 'name' => 'Muster' ) ),
			$this->cand( array( 'id' => 4, 'date' => '2026-03-01' ) ),
		);
		$out   = Bank::suggest( $tx, $cands, 2 );

		$this->assertSame( array( 3, 2 ), array_column( $out, 'id' ) );
	}

	public function test_kontoauszug_ergaenzt_nur_bei_gleichem_betrag_und_hoechstens_drei_tagen() {
		$row   = array( 'amount' => -45.9, 'booking_date' => '2026-03-07' );
		$cands = array(
			array( 'id' => 1, 'amount' => -45.9, 'booking_date' => '2026-03-03', 'enriched' => 0 ),
			array( 'id' => 4, 'amount' => -45.0, 'booking_date' => '2026-03-07', 'enriched' => 0 ),
		);

		$this->assertNull( Bank::merge_target( $row, $cands ) );
		$this->assertSame( 1, Bank::merge_target( array( 'amount' => -45.9, 'booking_date' => '2026-03-06' ), $cands ), 'Genau drei Tage.' );
	}

	/* ------------------------------------------------------------ Regeln */

	public function test_regel_mit_umlauten_leerem_text_und_feld_zweck() {
		$rules = array(
			array( 'text' => '  ', 'field' => 'beide', 'direction' => 'alle', 'note' => 'leer' ),
			array( 'text' => 'MIETE', 'field' => 'zweck', 'direction' => 'aus', 'note' => 'Miete Werkstatt' ),
			array( 'text' => 'ÖBB', 'field' => 'name', 'direction' => 'alle', 'note' => 'Reise' ),
		);

		$this->assertSame( 'Reise', Bank::rule_for( array( 'counterparty' => 'öbb Personenverkehr', 'purpose' => '', 'amount' => -50 ), $rules )['note'] );
		$this->assertNull( Bank::rule_for( array( 'counterparty' => 'Miete GmbH', 'purpose' => 'x', 'amount' => -1 ), $rules ), 'Feld „zweck“ schaut nicht in den Namen.' );
		$this->assertNull( Bank::rule_for( array( 'counterparty' => 'x', 'purpose' => 'y', 'amount' => 0 ), $rules ), 'Leere Regel trifft nie.' );
	}

	public function test_regeln_ohne_notiz_mit_html_und_hoechstens_50() {
		$out = Bank::clean_rules(
			array(
				array( 'text' => '<b>Amazon</b>', 'note' => 'Material' ),
				array( 'text' => 'Ohne Notiz', 'note' => '' ),
			)
		);

		$this->assertSame( array( array( 'text' => 'Amazon', 'field' => 'beide', 'direction' => 'alle', 'note' => 'Material' ) ), $out );
		$this->assertCount( 50, Bank::clean_rules( array_fill( 0, 70, array( 'text' => 'a', 'note' => 'b' ) ) ) );
	}
}
