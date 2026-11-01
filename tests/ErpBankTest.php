<?php
/**
 * Bankabgleich: Einlesen von CAMT und CSV, Fingerabdruck, Vorschläge.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\Pages\Bank;
use PHPUnit\Framework\TestCase;

class ErpBankTest extends TestCase {

	private function camt() {
		return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">
 <BkToCstmrStmt><Stmt>
  <Acct><Id><IBAN>DE02120300000000202051</IBAN></Id></Acct>
  <Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp><Amt Ccy="EUR">100.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-10-01</Dt></Dt></Bal>
  <Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp><Amt Ccy="EUR">1234.56</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-10-31</Dt></Dt></Bal>
  <Ntry>
   <Amt Ccy="EUR">149.90</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts><Cd>BOOK</Cd></Sts>
   <BookgDt><Dt>2026-10-05</Dt></BookgDt><ValDt><Dt>2026-10-05</Dt></ValDt>
   <NtryDtls><TxDtls>
    <Refs><EndToEndId>NOTPROVIDED</EndToEndId></Refs>
    <RltdPties><Dbtr><Pty><Nm>Blumen Müller GmbH</Nm></Pty></Dbtr><DbtrAcct><Id><IBAN>DE89370400440532013000</IBAN></Id></DbtrAcct></RltdPties>
    <RmtInf><Ustrd>Rechnung RE-2026-0042 Bestellung 1234</Ustrd></RmtInf>
   </TxDtls></NtryDtls>
  </Ntry>
  <Ntry>
   <Amt Ccy="EUR">19.99</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts><Cd>BOOK</Cd></Sts>
   <BookgDt><Dt>2026-10-06</Dt></BookgDt>
   <NtryDtls><TxDtls>
    <RltdPties><Cdtr><Pty><Nm>Filament Shop</Nm></Pty></Cdtr></RltdPties>
    <RmtInf><Ustrd>Kd 77</Ustrd></RmtInf>
   </TxDtls></NtryDtls>
  </Ntry>
  <Ntry>
   <Amt Ccy="EUR">5.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts><Cd>PDNG</Cd></Sts>
   <BookgDt><Dt>2026-10-07</Dt></BookgDt>
  </Ntry>
 </Stmt></BkToCstmrStmt>
</Document>
XML;
	}

	public function test_kit_betraege_und_daten() {
		$this->assertSame( 1234.5, Kit::amount( '1.234,50' ) );
		$this->assertSame( -12.5, Kit::amount( '-12,50' ) );
		$this->assertSame( -12.5, Kit::amount( '12,50-' ) );
		$this->assertSame( 1234.5, Kit::amount( '1,234.50' ) );
		$this->assertSame( 1234.5, Kit::amount( '1234.50 €' ) );
		$this->assertNull( Kit::amount( 'abc' ) );
		$this->assertSame( '2026-10-05', Kit::date( '05.10.2026' ) );
		$this->assertSame( '2026-10-05', Kit::date( '5.10.26' ) );
		$this->assertSame( '2026-10-05', Kit::date( '2026-10-05T10:00:00' ) );
		$this->assertSame( '', Kit::date( '31.02.2026' ) );
		$this->assertSame( 'AN-2026-0003', Kit::next_number( 'AN', 2026, array( 'AN-2026-0001', 'AN-2026-0002', 'AN-2025-0009' ) ) );
		$this->assertSame( 'AN-2026-0001', Kit::next_number( 'AN', 2026, array() ) );
	}

	public function test_camt_liest_umsaetze_saldo_und_laesst_vorgemerkte_weg() {
		$this->assertTrue( Bank::is_camt( $this->camt() ) );
		$r = Bank::parse_camt( $this->camt() );

		$this->assertSame( '', $r['error'] );
		$this->assertSame( 'DE02120300000000202051', $r['iban'] );
		$this->assertSame( 1234.56, $r['closing_balance'] );
		$this->assertSame( '2026-10-31', $r['closing_date'] );
		$this->assertCount( 2, $r['rows'] );
		$this->assertSame( 149.9, $r['rows'][0]['amount'] );
		$this->assertSame( 'Blumen Müller GmbH', $r['rows'][0]['counterparty'] );
		$this->assertSame( 'DE89370400440532013000', $r['rows'][0]['counterparty_iban'] );
		$this->assertSame( 'Rechnung RE-2026-0042 Bestellung 1234', $r['rows'][0]['purpose'] );
		$this->assertSame( '', $r['rows'][0]['end_to_end'] );
		$this->assertSame( -19.99, $r['rows'][1]['amount'] );
		$this->assertSame( 'Filament Shop', $r['rows'][1]['counterparty'] );
	}

	public function test_camt_kaputt() {
		$this->assertNotSame( '', Bank::parse_camt( '<Document><x>' )['error'] );
	}

	public function test_csv_sparkasse_mit_vorspann() {
		$csv = "\"Umsätze Girokonto\";\"DE02 1203 0000 0000 2020 51\"\n\n"
			. "\"Auftragskonto\";\"Buchungstag\";\"Valutadatum\";\"Buchungstext\";\"Verwendungszweck\";\"Beguenstigter/Zahlungspflichtiger\";\"Kontonummer/IBAN\";\"Betrag\";\"Waehrung\"\n"
			. "\"DE02\";\"05.10.26\";\"05.10.26\";\"GUTSCHRIFT\";\"Bestellung 1234\";\"Blumen Müller\";\"DE89370400440532013000\";\"149,90\";\"EUR\"\n"
			. "\"DE02\";\"06.10.26\";\"06.10.26\";\"LASTSCHRIFT\";\"Kd 77\";\"Filament Shop\";\"\";\"-1.019,99\";\"EUR\"\n";
		$r = Bank::parse_csv( $csv );

		$this->assertSame( '', $r['error'] );
		$this->assertSame( 'DE02120300000000202051', $r['iban'] );
		$this->assertCount( 2, $r['rows'] );
		$this->assertSame( '2026-10-05', $r['rows'][0]['booking_date'] );
		$this->assertSame( 149.9, $r['rows'][0]['amount'] );
		$this->assertSame( 'Bestellung 1234', $r['rows'][0]['purpose'] );
		$this->assertSame( 'Blumen Müller', $r['rows'][0]['counterparty'] );
		$this->assertSame( -1019.99, $r['rows'][1]['amount'] );
	}

	public function test_csv_mit_soll_und_haben_und_windows1252() {
		$csv = mb_convert_encoding( "Datum;Empfänger;Verwendungszweck;Soll;Haben\n01.10.2026;Müller;Miete;800,00;\n02.10.2026;Kunde;RE 5;;50,00\n", 'Windows-1252', 'UTF-8' );
		$r   = Bank::parse_csv( $csv );

		$this->assertSame( '', $r['error'] );
		$this->assertSame( -800.0, $r['rows'][0]['amount'] );
		$this->assertSame( 'Müller', $r['rows'][0]['counterparty'] );
		$this->assertSame( 50.0, $r['rows'][1]['amount'] );
	}

	public function test_csv_ohne_kopfzeile() {
		$this->assertNotSame( '', Bank::parse_csv( "a;b;c\n1;2;3\n" )['error'] );
	}

	public function test_fingerabdruck_trennt_gleiche_umsaetze() {
		$row    = array( 'booking_date' => '2026-10-05', 'amount' => 9.9, 'purpose' => 'Kaffee', 'counterparty' => 'Bäcker', 'end_to_end' => '' );
		$prints = Bank::fingerprints( array( $row, $row ), 'DE02' );

		$this->assertNotSame( $prints[0], $prints[1] );
		$this->assertSame( $prints, Bank::fingerprints( array( $row, $row ), 'DE02' ), 'Zweites Einlesen ergibt dieselben Abdrücke' );
		$this->assertSame( 40, strlen( $prints[0] ) );
	}

	public function test_vorschlaege_betrag_nummer_name() {
		$tx = array( 'amount' => 149.9, 'purpose' => 'Rechnung RE-2026-0042 Bestellung 1234', 'counterparty' => 'Blumen Müller GmbH', 'booking_date' => '2026-10-05' );
		$c  = array(
			array( 'type' => 'auftrag', 'id' => 1, 'label' => 'A', 'amount' => 149.9, 'refs' => array( '1234', 'RE-2026-0042' ), 'name' => 'Blumen Müller', 'date' => '2026-09-30' ),
			array( 'type' => 'auftrag', 'id' => 2, 'label' => 'B', 'amount' => 149.9, 'refs' => array( '999' ), 'name' => 'Andere', 'date' => '2026-01-01' ),
			array( 'type' => 'shop', 'id' => 3, 'label' => 'C', 'amount' => 20.0, 'refs' => array( '777' ), 'name' => 'Müller', 'date' => '2026-10-01' ),
			array( 'type' => 'beleg', 'id' => 4, 'label' => 'D', 'amount' => -149.9, 'refs' => array( '1234' ), 'name' => 'Blumen Müller', 'date' => '2026-10-01' ),
		);
		$s = Bank::suggest( $tx, $c );

		$this->assertSame( array( 1, 2 ), array_column( $s, 'id' ), 'Name allein reicht nicht, Ausgaben passen nie zu Eingängen' );
		$this->assertSame( 110, $s[0]['score'] );
		$this->assertSame( 50, $s[1]['score'] );
	}

	public function test_nummer_nicht_als_teil_einer_groesseren_zahl() {
		$tx = array( 'amount' => 10.0, 'purpose' => 'Kunde 12345', 'counterparty' => '', 'booking_date' => '2026-10-05' );
		$c  = array( array( 'type' => 'shop', 'id' => 1, 'label' => 'A', 'amount' => 99.0, 'refs' => array( '1234' ), 'name' => '', 'date' => '' ) );
		$this->assertSame( array(), Bank::suggest( $tx, $c ) );
	}
}
