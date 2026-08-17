<?php
/**
 * Unit-Tests für GoBD-Funktionen: Hash-Kette, Nummernformate, Storno-Logik.
 *
 * Testet die reinen, seiteneffektfreien Methoden aus Calc und HashChain.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Core\Calc;
use Lindenweg16\Core\HashChain;

/**
 * @covers \Lindenweg16\Core\Calc
 * @covers \Lindenweg16\Core\HashChain
 */
class GobdTest extends TestCase {

	/* ----------------------------------------------------------------
	 * Belegnummer-Format
	 * -------------------------------------------------------------- */

	public function test_belegnummer_format() {
		$this->assertSame( 'B-2026-0001', Calc::belegnummer( 2026, 1 ) );
		$this->assertSame( 'B-2026-0042', Calc::belegnummer( 2026, 42 ) );
		$this->assertSame( 'B-2025-9999', Calc::belegnummer( 2025, 9999 ) );
	}

	public function test_belegnummer_zero_padded() {
		$nr = Calc::belegnummer( 2026, 5 );
		$this->assertMatchesRegularExpression( '/^B-\d{4}-\d{4}$/', $nr );
	}

	/* ----------------------------------------------------------------
	 * Buchungsnummer-Format
	 * -------------------------------------------------------------- */

	public function test_buchungsnummer_format() {
		$this->assertSame( 'BU-2026-0001', Calc::buchungsnummer( 2026, 1 ) );
		$this->assertSame( 'BU-2026-0123', Calc::buchungsnummer( 2026, 123 ) );
	}

	public function test_buchungsnummer_zero_padded() {
		$nr = Calc::buchungsnummer( 2026, 7 );
		$this->assertMatchesRegularExpression( '/^BU-\d{4}-\d{4}$/', $nr );
	}

	/* ----------------------------------------------------------------
	 * Rechnungsnummer (bestehend, Regression)
	 * -------------------------------------------------------------- */

	public function test_rechnungsnummer_jahr_modus() {
		$this->assertSame( '2026-0001', Calc::rechnungsnummer( 'jahr', 2026, 1 ) );
		$this->assertSame( '2026-0099', Calc::rechnungsnummer( 'jahr', 2026, 99 ) );
	}

	public function test_rechnungsnummer_durchlaufend_modus() {
		$this->assertSame( 'RE-00001', Calc::rechnungsnummer( 'durchlaufend', 2026, 1 ) );
		$this->assertSame( 'RE-01234', Calc::rechnungsnummer( 'durchlaufend', 2026, 1234 ) );
	}

	/* ----------------------------------------------------------------
	 * Hash-Berechnung
	 * -------------------------------------------------------------- */

	public function test_hash_compute_returns_64_hex_chars() {
		$hash = HashChain::compute( 'belege', array(
			'belegnummer' => 'B-2026-0001',
			'datei_pfad'  => 'test.jpg',
			'betrag'      => '12.50',
			'datum'       => '2026-01-15',
			'haendler'    => 'Testfirma',
			'eur_kategorie' => 'Büromaterial',
			'notiz'       => '',
			'quelle'      => 'manuell',
			'erstellt_am' => '2026-01-15 10:00:00',
			'erstellt_von' => '1',
		), '' );
		$this->assertSame( 64, strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $hash );
	}

	public function test_hash_compute_deterministic() {
		$data = array(
			'belegnummer' => 'B-2026-0001',
			'datei_pfad'  => 'test.jpg',
			'betrag'      => '12.50',
			'datum'       => '2026-01-15',
			'haendler'    => 'Testfirma',
			'eur_kategorie' => 'Büromaterial',
			'notiz'       => '',
			'quelle'      => 'manuell',
			'erstellt_am' => '2026-01-15 10:00:00',
			'erstellt_von' => '1',
		);
		$hash1 = HashChain::compute( 'belege', $data, '' );
		$hash2 = HashChain::compute( 'belege', $data, '' );
		$this->assertSame( $hash1, $hash2 );
	}

	public function test_notiz_change_does_not_break_hash() {
		$data = array(
			'belegnummer'   => 'B-2026-0001',
			'datei_pfad'    => 'test.jpg',
			'betrag'        => '12.50',
			'datum'         => '2026-01-15',
			'haendler'      => 'Testfirma',
			'eur_kategorie' => 'Büromaterial',
			'notiz'         => 'Alte Notiz',
			'quelle'        => 'manuell',
			'erstellt_am'   => '2026-01-15 10:00:00',
			'erstellt_von'  => '1',
		);
		$hash1 = HashChain::compute( 'belege', $data, '' );

		$data['notiz'] = 'Neue, geänderte Notiz';
		$hash2 = HashChain::compute( 'belege', $data, '' );

		$this->assertSame( $hash1, $hash2, 'Notiz changes must not affect the hash (notiz is updatable after festschreiben)' );
	}

	public function test_hash_changes_with_different_data() {
		$data = array(
			'belegnummer' => 'B-2026-0001',
			'datei_pfad'  => 'test.jpg',
			'betrag'      => '12.50',
			'datum'       => '2026-01-15',
			'haendler'    => 'Testfirma',
			'eur_kategorie' => 'Büromaterial',
			'notiz'       => '',
			'quelle'      => 'manuell',
			'erstellt_am' => '2026-01-15 10:00:00',
			'erstellt_von' => '1',
		);
		$hash1 = HashChain::compute( 'belege', $data, '' );

		$data['betrag'] = '13.00';
		$hash2 = HashChain::compute( 'belege', $data, '' );

		$this->assertNotSame( $hash1, $hash2 );
	}

	public function test_hash_changes_with_different_previous_hash() {
		$data = array(
			'belegnummer' => 'B-2026-0001',
			'datei_pfad'  => 'test.jpg',
			'betrag'      => '12.50',
			'datum'       => '2026-01-15',
			'haendler'    => 'Testfirma',
			'eur_kategorie' => 'Büromaterial',
			'notiz'       => '',
			'quelle'      => 'manuell',
			'erstellt_am' => '2026-01-15 10:00:00',
			'erstellt_von' => '1',
		);
		$hash1 = HashChain::compute( 'belege', $data, '' );
		$hash2 = HashChain::compute( 'belege', $data, 'abc123' );

		$this->assertNotSame( $hash1, $hash2 );
	}

	public function test_hash_genesis_used_for_first_record() {
		$data = array(
			'belegnummer' => 'B-2026-0001',
			'datei_pfad'  => '',
			'betrag'      => '0',
			'datum'       => '2026-01-01',
			'haendler'    => '',
			'eur_kategorie' => '',
			'notiz'       => '',
			'quelle'      => 'manuell',
			'erstellt_am' => '2026-01-01 00:00:00',
			'erstellt_von' => '0',
		);
		// With empty previous hash, GENESIS is used internally.
		$hash = HashChain::compute( 'belege', $data, '' );
		$this->assertNotEmpty( $hash );
	}

	public function test_hash_chain_consecutive_records() {
		$data1 = array(
			'belegnummer' => 'B-2026-0001',
			'datei_pfad'  => 'a.jpg',
			'betrag'      => '10',
			'datum'       => '2026-01-01',
			'haendler'    => 'A',
			'eur_kategorie' => 'Büromaterial',
			'notiz'       => '',
			'quelle'      => 'manuell',
			'erstellt_am' => '2026-01-01 10:00:00',
			'erstellt_von' => '1',
		);
		$hash1 = HashChain::compute( 'belege', $data1, '' );

		$data2 = array(
			'belegnummer' => 'B-2026-0002',
			'datei_pfad'  => 'b.jpg',
			'betrag'      => '20',
			'datum'       => '2026-01-02',
			'haendler'    => 'B',
			'eur_kategorie' => 'Porto/Versand',
			'notiz'       => '',
			'quelle'      => 'manuell',
			'erstellt_am' => '2026-01-02 10:00:00',
			'erstellt_von' => '1',
		);
		$hash2 = HashChain::compute( 'belege', $data2, $hash1 );

		// Hash2 depends on hash1.
		$hash2_alt = HashChain::compute( 'belege', $data2, '' );
		$this->assertNotSame( $hash2, $hash2_alt );
	}

	/* ----------------------------------------------------------------
	 * Hash-Felder-Konfiguration
	 * -------------------------------------------------------------- */

	public function test_hash_fields_defined_for_all_tables() {
		$expected_tables = array( 'belege', 'rechnungen', 'buchungen', 'audit_log', 'erechnung_eingang', 'kassenbuch' );
		foreach ( $expected_tables as $table ) {
			$this->assertArrayHasKey( $table, HashChain::HASH_FIELDS, "Hash fields missing for table: {$table}" );
			$this->assertNotEmpty( HashChain::HASH_FIELDS[ $table ], "Hash fields empty for table: {$table}" );
		}
	}

	public function test_storniert_not_in_hash_fields() {
		// storniert must NOT be in hash fields, because it can be updated after creation.
		foreach ( HashChain::HASH_FIELDS as $table => $fields ) {
			$this->assertNotContains( 'storniert', $fields, "storniert should not be in hash fields for {$table}" );
		}
	}

	public function test_hash_fields_exclude_id_and_hash() {
		// id, hash, vorheriger_hash should never be in hash fields.
		foreach ( HashChain::HASH_FIELDS as $table => $fields ) {
			$this->assertNotContains( 'id', $fields, "id should not be in hash fields for {$table}" );
			$this->assertNotContains( 'hash', $fields, "hash should not be in hash fields for {$table}" );
			$this->assertNotContains( 'vorheriger_hash', $fields, "vorheriger_hash should not be in hash fields for {$table}" );
		}
	}

	/* ----------------------------------------------------------------
	 * table_key Extraktion
	 * -------------------------------------------------------------- */

	public function test_table_key_from_full_name() {
		$this->assertSame( 'belege', HashChain::table_key( 'wp_lw16_belege' ) );
		$this->assertSame( 'audit_log', HashChain::table_key( 'wp_lw16_audit_log' ) );
		$this->assertSame( 'buchungen', HashChain::table_key( 'custom_prefix_lw16_buchungen' ) );
		$this->assertSame( 'erechnung_eingang', HashChain::table_key( 'wp_lw16_erechnung_eingang' ) );
	}

	public function test_table_key_empty_for_unknown() {
		$this->assertSame( '', HashChain::table_key( 'wp_other_table' ) );
	}

	/* ----------------------------------------------------------------
	 * Hash-Berechnung für verschiedene Tabellen
	 * -------------------------------------------------------------- */

	public function test_hash_buchungen_table() {
		$data = array(
			'buchungsnummer' => 'BU-2026-0001',
			'buchungsdatum'  => '2026-01-15',
			'erfasst_am'     => '2026-01-15 10:00:00',
			'typ'            => 'ausgabe',
			'betrag'         => '42.50',
			'kategorie'      => 'Büromaterial',
			'beschreibung'   => 'Stifte',
			'referenz_typ'   => 'beleg',
			'referenz_id'    => '5',
			'erstellt_von'   => '1',
		);
		$hash = HashChain::compute( 'buchungen', $data, '' );
		$this->assertSame( 64, strlen( $hash ) );
	}

	public function test_hash_audit_log_table() {
		$data = array(
			'user_id'    => '1',
			'aktion'     => 'beleg_festgeschrieben',
			'objekt_typ' => 'beleg',
			'objekt_id'  => '5',
			'details'    => '{"betrag":"42.50"}',
			'erstellt_am' => '2026-01-15 10:00:00',
		);
		$hash = HashChain::compute( 'audit_log', $data, '' );
		$this->assertSame( 64, strlen( $hash ) );
	}

	public function test_hash_rechnungen_table() {
		$data = array(
			'order_id'        => '100',
			'rechnungsnummer' => '2026-0001',
			'jahr'            => '2026',
			'laufnummer'      => '1',
			'datei_pfad'      => 'rechnung-2026-0001-abc.pdf',
			'betrag'          => '99.99',
			'erstellt_am'     => '2026-01-15 10:00:00',
		);
		$hash = HashChain::compute( 'rechnungen', $data, '' );
		$this->assertSame( 64, strlen( $hash ) );
	}

	public function test_hash_erechnung_eingang_table() {
		$data = array(
			'datei_pfad'          => 'erechnung-20260115.xml',
			'format'              => 'xrechnung',
			'absender_name'       => 'Lieferant GmbH',
			'absender_steuer_id'  => 'DE123456789',
			'rechnungsnummer_ext' => 'LF-2026-0042',
			'betrag'              => '250.00',
			'datum'               => '2026-01-15',
			'erstellt_am'         => '2026-01-15 10:00:00',
		);
		$hash = HashChain::compute( 'erechnung_eingang', $data, '' );
		$this->assertSame( 64, strlen( $hash ) );
	}

	public function test_erechnung_status_not_in_hash() {
		// status und beleg_id dürfen sich nach Import ändern, also nicht im Hash.
		$this->assertNotContains( 'status', HashChain::HASH_FIELDS['erechnung_eingang'] );
		$this->assertNotContains( 'beleg_id', HashChain::HASH_FIELDS['erechnung_eingang'] );
	}

	/* ----------------------------------------------------------------
	 * Nummern-Konsistenz
	 * -------------------------------------------------------------- */

	public function test_belegnummer_sequential() {
		$nr1 = Calc::belegnummer( 2026, 1 );
		$nr2 = Calc::belegnummer( 2026, 2 );
		$nr3 = Calc::belegnummer( 2026, 3 );

		$this->assertSame( 'B-2026-0001', $nr1 );
		$this->assertSame( 'B-2026-0002', $nr2 );
		$this->assertSame( 'B-2026-0003', $nr3 );
	}

	public function test_buchungsnummer_sequential() {
		$nr1 = Calc::buchungsnummer( 2026, 1 );
		$nr2 = Calc::buchungsnummer( 2026, 2 );

		$this->assertSame( 'BU-2026-0001', $nr1 );
		$this->assertSame( 'BU-2026-0002', $nr2 );
	}

	public function test_nummern_different_years_independent() {
		$this->assertSame( 'B-2025-0001', Calc::belegnummer( 2025, 1 ) );
		$this->assertSame( 'B-2026-0001', Calc::belegnummer( 2026, 1 ) );
	}

	/* ----------------------------------------------------------------
	 * Kassenbuchnummer-Format
	 * -------------------------------------------------------------- */

	public function test_kassenbuchnummer_format() {
		$this->assertSame( 'KB-2026-0001', Calc::kassenbuchnummer( 2026, 1 ) );
		$this->assertSame( 'KB-2026-0042', Calc::kassenbuchnummer( 2026, 42 ) );
		$this->assertSame( 'KB-2025-9999', Calc::kassenbuchnummer( 2025, 9999 ) );
	}

	public function test_kassenbuchnummer_zero_padded() {
		$nr = Calc::kassenbuchnummer( 2026, 5 );
		$this->assertMatchesRegularExpression( '/^KB-\d{4}-\d{4}$/', $nr );
	}

	public function test_kassenbuchnummer_sequential() {
		$nr1 = Calc::kassenbuchnummer( 2026, 1 );
		$nr2 = Calc::kassenbuchnummer( 2026, 2 );
		$nr3 = Calc::kassenbuchnummer( 2026, 3 );

		$this->assertSame( 'KB-2026-0001', $nr1 );
		$this->assertSame( 'KB-2026-0002', $nr2 );
		$this->assertSame( 'KB-2026-0003', $nr3 );
	}

	/* ----------------------------------------------------------------
	 * Anlagennummer-Format
	 * -------------------------------------------------------------- */

	public function test_anlagennummer_format() {
		$this->assertSame( 'AN-2026-0001', Calc::anlagennummer( 2026, 1 ) );
		$this->assertSame( 'AN-2026-0042', Calc::anlagennummer( 2026, 42 ) );
		$this->assertSame( 'AN-2025-9999', Calc::anlagennummer( 2025, 9999 ) );
	}

	public function test_anlagennummer_zero_padded() {
		$nr = Calc::anlagennummer( 2026, 5 );
		$this->assertMatchesRegularExpression( '/^AN-\d{4}-\d{4}$/', $nr );
	}

	/* ----------------------------------------------------------------
	 * Hash-Berechnung: Kassenbuch
	 * -------------------------------------------------------------- */

	public function test_hash_kassenbuch_table() {
		$data = array(
			'kassenbuchnummer' => 'KB-2026-0001',
			'datum'            => '2026-01-15',
			'beschreibung'     => 'Barverkauf',
			'einnahme'         => '50.00',
			'ausgabe'          => '0.00',
			'saldo'            => '50.00',
			'beleg_id'         => '3',
			'erstellt_am'      => '2026-01-15 10:00:00',
			'erstellt_von'     => '1',
		);
		$hash = HashChain::compute( 'kassenbuch', $data, '' );
		$this->assertSame( 64, strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $hash );
	}

	public function test_hash_kassenbuch_deterministic() {
		$data = array(
			'kassenbuchnummer' => 'KB-2026-0001',
			'datum'            => '2026-01-15',
			'beschreibung'     => 'Barverkauf',
			'einnahme'         => '50.00',
			'ausgabe'          => '0.00',
			'saldo'            => '50.00',
			'beleg_id'         => '3',
			'erstellt_am'      => '2026-01-15 10:00:00',
			'erstellt_von'     => '1',
		);
		$hash1 = HashChain::compute( 'kassenbuch', $data, '' );
		$hash2 = HashChain::compute( 'kassenbuch', $data, '' );
		$this->assertSame( $hash1, $hash2 );
	}

	public function test_kassenbuch_storniert_not_in_hash() {
		$this->assertNotContains( 'storniert', HashChain::HASH_FIELDS['kassenbuch'] );
		$this->assertNotContains( 'storno_von_id', HashChain::HASH_FIELDS['kassenbuch'] );
	}

	public function test_kassenbuch_hash_chain() {
		$data1 = array(
			'kassenbuchnummer' => 'KB-2026-0001',
			'datum'            => '2026-01-15',
			'beschreibung'     => 'Barverkauf',
			'einnahme'         => '50.00',
			'ausgabe'          => '0.00',
			'saldo'            => '50.00',
			'beleg_id'         => '',
			'erstellt_am'      => '2026-01-15 10:00:00',
			'erstellt_von'     => '1',
		);
		$hash1 = HashChain::compute( 'kassenbuch', $data1, '' );

		$data2 = array(
			'kassenbuchnummer' => 'KB-2026-0002',
			'datum'            => '2026-01-15',
			'beschreibung'     => 'Briefmarken',
			'einnahme'         => '0.00',
			'ausgabe'          => '5.80',
			'saldo'            => '44.20',
			'beleg_id'         => '',
			'erstellt_am'      => '2026-01-15 11:00:00',
			'erstellt_von'     => '1',
		);
		$hash2 = HashChain::compute( 'kassenbuch', $data2, $hash1 );
		$hash2_alt = HashChain::compute( 'kassenbuch', $data2, '' );

		// Hash2 depends on hash1 (chain).
		$this->assertNotSame( $hash2, $hash2_alt );
	}
}
