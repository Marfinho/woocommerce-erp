<?php
/**
 * Lager im ERP: reine Regeln der Seiten Lager, Lagerplätze und Bedarf.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Lager;
use PHPUnit\Framework\TestCase;

class ErpLagerTest extends TestCase {

	private function ctx( array $over = array() ) {
		return array_merge(
			array(
				'article' => array( 'id' => 7 ),
				'colors'  => array( 'weiss' => 'Weiß', 'ungeklaert' => 'ungeklärt' ),
				'bins'    => array(
					1 => array( 'code' => 'A01', 'is_active' => 1 ),
					2 => array( 'code' => 'A02', 'is_active' => 1 ),
					3 => array( 'code' => 'ALT', 'is_active' => 0 ),
				),
			),
			$over
		);
	}

	private function in( array $over = array() ) {
		return array_merge( array( 'kind' => 'zugang', 'article_id' => 7, 'color' => 'weiss', 'qty' => '5' ), $over );
	}

	public function test_parse_qty_deutsch_und_englisch() {
		$this->assertSame( 2.5, Lager::parse_qty( '2,5' ) );
		$this->assertSame( 2.5, Lager::parse_qty( '2.5' ) );
		$this->assertSame( 1250.0, Lager::parse_qty( '1.250' ) );
		$this->assertSame( 1234.5, Lager::parse_qty( '1.234,5' ) );
		$this->assertSame( -3.0, Lager::parse_qty( ' -3 ' ) );
		$this->assertNull( Lager::parse_qty( '' ) );
		$this->assertNull( Lager::parse_qty( 'abc' ) );
		$this->assertNull( Lager::parse_qty( '5 Stück' ) );
		$this->assertNull( Lager::parse_qty( array( 5 ) ) );
	}

	public function test_vorzeichen_gibt_die_art_vor() {
		$this->assertSame( 4.0, Lager::signed_qty( 'zugang', -4 ) );
		$this->assertSame( -4.0, Lager::signed_qty( 'abgang', 4 ) );
		$this->assertSame( -4.0, Lager::signed_qty( 'abgang', -4 ) );
		$this->assertSame( -4.0, Lager::signed_qty( 'korrektur', -4 ) );
		$this->assertSame( 4.0, Lager::signed_qty( 'korrektur', 4 ) );
	}

	public function test_zugang_wird_positiv_gebucht() {
		$r = Lager::validate_movement( $this->in( array( 'bin_id' => 1 ) ), $this->ctx() );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( 5.0, $r['data']['lines'][0]['qty'] );
		$this->assertSame( 1, $r['data']['lines'][0]['bin_id'] );
		$this->assertSame( 'produktion', $r['data']['common']['movement_type'] );
	}

	public function test_abgang_wird_negativ_gebucht() {
		$r = Lager::validate_movement( $this->in( array( 'kind' => 'abgang', 'reason' => 'ausschuss' ) ), $this->ctx() );
		$this->assertSame( -5.0, $r['data']['lines'][0]['qty'] );
		$this->assertSame( 'ausschuss', $r['data']['common']['movement_type'] );
	}

	public function test_korrektur_braucht_begruendung() {
		$r = Lager::validate_movement( $this->in( array( 'kind' => 'korrektur', 'qty' => '-2' ) ), $this->ctx() );
		$this->assertStringContainsString( 'Begründung', implode( ' ', $r['errors'] ) );

		$r = Lager::validate_movement( $this->in( array( 'kind' => 'korrektur', 'qty' => '-2', 'note' => 'Inventur' ) ), $this->ctx() );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( -2.0, $r['data']['lines'][0]['qty'] );
	}

	public function test_menge_null_text_und_riesig_werden_abgewiesen() {
		foreach ( array( '0', 'viel', '99999999' ) as $qty ) {
			$r = Lager::validate_movement( $this->in( array( 'qty' => $qty ) ), $this->ctx() );
			$this->assertNotEmpty( $r['errors'], $qty );
		}
	}

	public function test_unbekannte_art_artikel_und_farbe() {
		$this->assertNotEmpty( Lager::validate_movement( $this->in( array( 'kind' => 'verkauf' ) ), $this->ctx() )['errors'] );
		$this->assertNotEmpty( Lager::validate_movement( $this->in(), $this->ctx( array( 'article' => null ) ) )['errors'] );
		$this->assertNotEmpty( Lager::validate_movement( $this->in( array( 'color' => 'pink' ) ), $this->ctx() )['errors'] );
		$this->assertNotEmpty( Lager::validate_movement( $this->in( array( 'reason' => 'korrektur' ) ), $this->ctx() )['errors'] );
	}

	public function test_umlagerung_bucht_ein_paar() {
		$r = Lager::validate_movement( $this->in( array( 'kind' => 'umlagerung', 'bin_id' => 1, 'to_bin_id' => 2 ) ), $this->ctx() );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( -5.0, $r['data']['lines'][0]['qty'] );
		$this->assertSame( 1, $r['data']['lines'][0]['bin_id'] );
		$this->assertSame( 5.0, $r['data']['lines'][1]['qty'] );
		$this->assertSame( 2, $r['data']['lines'][1]['bin_id'] );
		$this->assertSame( 'umlagerung', $r['data']['common']['movement_type'] );
	}

	public function test_umlagerung_braucht_zwei_verschiedene_plaetze() {
		$this->assertNotEmpty( Lager::validate_movement( $this->in( array( 'kind' => 'umlagerung', 'bin_id' => 1 ) ), $this->ctx() )['errors'] );
		$this->assertNotEmpty( Lager::validate_movement( $this->in( array( 'kind' => 'umlagerung', 'bin_id' => 1, 'to_bin_id' => 1 ) ), $this->ctx() )['errors'] );
	}

	public function test_gesperrter_platz_nimmt_nichts_mehr_auf_gibt_aber_ab() {
		$in = $this->in( array( 'bin_id' => 3 ) );
		$this->assertStringContainsString( 'gesperrt', implode( ' ', Lager::validate_movement( $in, $this->ctx() )['errors'] ) );

		$out = $this->in( array( 'kind' => 'abgang', 'reason' => 'ausschuss', 'bin_id' => 3 ) );
		$this->assertSame( array(), Lager::validate_movement( $out, $this->ctx() )['errors'] );

		$move = $this->in( array( 'kind' => 'umlagerung', 'bin_id' => 1, 'to_bin_id' => 3 ) );
		$this->assertNotEmpty( Lager::validate_movement( $move, $this->ctx() )['errors'] );
		$move = $this->in( array( 'kind' => 'umlagerung', 'bin_id' => 3, 'to_bin_id' => 1 ) );
		$this->assertSame( array(), Lager::validate_movement( $move, $this->ctx() )['errors'] );
	}

	public function test_unbekannter_platz() {
		$this->assertNotEmpty( Lager::validate_movement( $this->in( array( 'bin_id' => 99 ) ), $this->ctx() )['errors'] );
	}

	public function test_warnungen_bei_negativem_bestand() {
		$lines = array( array( 'qty' => -5.0, 'bin_id' => 1 ) );
		$w     = Lager::warnings( $lines, 3.0, array( 1 => 2.0 ), array( 1 => 'A01' ) );
		$this->assertCount( 2, $w );
		$this->assertStringContainsString( 'A01', $w[1] );
		$this->assertSame( array(), Lager::warnings( $lines, 10.0, array( 1 => 6.0 ), array( 1 => 'A01' ) ) );
	}

	public function test_client_ref_wird_bereinigt() {
		$this->assertSame( 'abc-12x', Lager::client_ref( 'abc-12;<x>' ) );
		$this->assertSame( '', Lager::client_ref( array( 'x' ) ) );
	}

	public function test_zeilen_vereinigen_bewegungen_und_soll_und_setzen_kennzeichen() {
		$balances = array(
			1 => array( 'weiss' => array( 'lager' => 10.0, 'kommission' => 2.0 ) ),
			2 => array( 'weiss' => array( 'lager' => 0.0 ) ),
		);
		$targets  = array(
			1 => array( 'weiss' => 20.0 ),
			3 => array( 'rot' => 5.0 ),
		);
		$located  = array( 1 => array( 'weiss' => 4.0 ) );
		$articles = array(
			1 => array( 'sku' => 'B-1', 'name' => 'Eins' ),
			2 => array( 'sku' => 'A-2', 'name' => 'Zwei' ),
			3 => array( 'sku' => 'C-3', 'name' => 'Drei' ),
		);
		$rows     = Lager::build_rows( $balances, $targets, $located, $articles, array( 1 => 8.0 ), 4 );

		$this->assertSame( array( 'A-2', 'B-1', 'C-3' ), array_column( $rows, 'sku' ) );

		$eins = $rows[1];
		$this->assertSame( 12.0, $eins['gesamt'] );
		$this->assertSame( -8.0, $eins['diff'] );
		$this->assertSame( 6.0, $eins['unplaced'] );
		$this->assertSame( 5.0, $eins['reach'] );
		$this->assertTrue( $eins['flags']['unter_soll'] );
		$this->assertTrue( $eins['flags']['ohne_platz'] );
		$this->assertFalse( $eins['flags']['leer'] );

		$this->assertTrue( $rows[0]['flags']['leer'] );
		$this->assertFalse( $rows[0]['flags']['unter_soll'] );
		$this->assertNull( $rows[0]['reach'] );

		$this->assertTrue( $rows[2]['flags']['unter_soll'] );
		$this->assertTrue( $rows[2]['flags']['leer'] );
	}

	public function test_ohne_platz_wird_nie_negativ() {
		$this->assertSame( 0.0, Lager::unplaced( 3, 5 ) );
		$this->assertSame( 2.0, Lager::unplaced( 5, 3 ) );
	}

	public function test_journalzustand() {
		$voided = array( 5 => true );
		$this->assertSame( 'storniert', Lager::journal_state( array( 'id' => 5, 'reverses_id' => null ), $voided ) );
		$this->assertSame( 'gegenbuchung', Lager::journal_state( array( 'id' => 6, 'reverses_id' => 5 ), $voided ) );
		$this->assertSame( 'offen', Lager::journal_state( array( 'id' => 4, 'reverses_id' => null ), $voided ) );
	}

	public function test_herkunft_in_klartext() {
		$this->assertSame( 'von Hand gebucht', Lager::ref_label( 'manual', 0 ) );
		$this->assertSame( 'Druckjob #12', Lager::ref_label( 'print_job', 12 ) );
		$this->assertSame( '', Lager::ref_label( '', 0 ) );
	}

	public function test_teilekorrektur() {
		$this->assertSame( array(), Lager::validate_part( array( 'part_type' => 'supply', 'part_id' => 3, 'qty' => '-2,5', 'note' => 'Inventur' ) )['errors'] );
		$this->assertCount( 3, Lager::validate_part( array( 'part_id' => 0, 'qty' => '0', 'note' => '' ) )['errors'] );
		$this->assertSame( 'component', Lager::validate_part( array( 'part_type' => 'x', 'part_id' => 1, 'qty' => '1', 'note' => 'a' ) )['data']['type'] );
	}

	public function test_sollbestand() {
		$ctx = array( 'article' => array( 'id' => 1 ), 'colors' => array( 'weiss' => 'Weiß' ) );
		$this->assertSame( array(), Lager::validate_target( array( 'color' => 'weiss', 'target_qty' => '20' ), $ctx )['errors'] );
		$this->assertNotEmpty( Lager::validate_target( array( 'color' => 'weiss', 'target_qty' => '-1' ), $ctx )['errors'] );
		$this->assertNotEmpty( Lager::validate_target( array( 'color' => 'weiss', 'target_qty' => 'x' ), $ctx )['errors'] );
		// Entfernen braucht keine Menge; null ist erlaubt und etwas anderes als Entfernen.
		$this->assertSame( array(), Lager::validate_target( array( 'color' => 'weiss', 'remove' => true ), $ctx )['errors'] );
		$zero = Lager::validate_target( array( 'color' => 'weiss', 'target_qty' => '0' ), $ctx );
		$this->assertSame( array(), $zero['errors'] );
		$this->assertFalse( $zero['data']['remove'] );
	}

	public function test_sperrhinweis() {
		$this->assertSame( '', Lager::lock_warning( array() ) );
		$this->assertSame( '', Lager::lock_warning( array( array( 'qty' => 0 ) ) ) );
		$this->assertStringContainsString( '7', Lager::lock_warning( array( array( 'qty' => 7 ) ) ) );
	}

	public function test_platzeingaben_behalten_die_notiz() {
		$in = Lager::bin_input( array( 'code' => 'a1', 'note' => 'Keller', 'is_active' => true, 'sort_order' => '3' ) );
		$this->assertSame( 'Keller', $in['note'] );
		$this->assertSame( '1', $in['is_active'] );
		$this->assertSame( '', Lager::bin_input( array( 'code' => 'a1' ) )['is_active'] );
	}

	public function test_bedarf_verweist_auf_workflow_oder_einkauf() {
		$this->assertSame( 'einkauf', Lager::demand_target( 'bestellen' ) );
		$this->assertSame( 'workflow', Lager::demand_target( 'drucken' ) );
		$this->assertSame( 'workflow', Lager::demand_target( 'montieren' ) );
		$why = Lager::demand_reasons( array( 'bestellung' => array( 'qty' => 4, 'labels' => array( '#1', '#2' ) ) ), array( 'bestellung' => 'Bestellung' ) );
		$this->assertSame( array( '4 Bestellung (#1, #2)' ), $why );
	}

	public function test_journal_filter_bereinigt_und_begrenzt() {
		$f = Lager::journal_filter(
			array(
				'q'          => '  #42 ',
				'article_id' => '7',
				'color'      => 'Weiss; DROP',
				'state'      => 'kaputt',
				'dir'        => 'abgang',
				'from'       => '2026-10-31',
				'to'         => '2026-10-01',
				'per_page'   => '5000',
				'page'       => '-3',
			)
		);
		$this->assertSame( '#42', $f['q'] );
		$this->assertSame( 7, $f['article_id'] );
		$this->assertSame( 'weissdrop', $f['color'] );
		$this->assertSame( '', $f['state'] );
		$this->assertSame( 'abgang', $f['dir'] );
		$this->assertSame( '2026-10-01', $f['from'], 'Von und Bis vertauscht wird gedreht' );
		$this->assertSame( '2026-10-31', $f['to'] );
		$this->assertSame( 200, $f['per_page'] );
		$this->assertSame( 1, $f['page'] );

		$leer = Lager::journal_filter( array( 'from' => '2026-02-30', 'per_page' => array( 1 ) ) );
		$this->assertSame( '', $leer['from'], 'Ungültiges Datum fällt weg' );
		$this->assertSame( 50, $leer['per_page'] );
	}

	public function test_parse_search_nummer_und_text() {
		$this->assertSame( array( 'ids' => array( 123 ), 'text' => '' ), Lager::parse_search( '#123' ) );
		$this->assertSame( array( 'ids' => array( 123 ), 'text' => '123' ), Lager::parse_search( '123' ) );
		$this->assertSame( array( 'ids' => array(), 'text' => 'Vase' ), Lager::parse_search( ' Vase ' ) );
	}

	public function test_journal_where_ohne_filter() {
		$f = Lager::journal_filter( array() );
		$this->assertSame( array( '1 = 1', array() ), Lager::journal_where( $f, array(), 'wp_t' ) );
	}

	public function test_journal_where_kombiniert_filter() {
		$f = Lager::journal_filter(
			array(
				'article_id' => 7,
				'color'      => 'weiss',
				'type'       => 'verkauf',
				'place'      => 'lager',
				'bin_id'     => 3,
				'origin'     => 'wc_order',
				'user_id'    => 9,
				'dir'        => 'abgang',
				'state'      => 'offen',
			)
		);
		list( $sql, $args ) = Lager::journal_where( $f, array(), 'wp_t', array( 'from' => '2026-10-01 00:00:00', 'to' => '2026-11-01 00:00:00' ) );

		$this->assertStringContainsString( 'article_id = %d', $sql );
		$this->assertStringContainsString( 'color = %s', $sql );
		$this->assertStringContainsString( 'movement_type = %s', $sql );
		$this->assertStringContainsString( 'moved_at >= %s AND moved_at < %s', $sql );
		$this->assertStringContainsString( 'qty < 0', $sql );
		$this->assertStringContainsString( 'id NOT IN (SELECT reverses_id FROM wp_t', $sql );
		$this->assertSame( array( 7, 'weiss', 'verkauf', 'lager', 'wc_order', 3, 9, '2026-10-01 00:00:00', '2026-11-01 00:00:00' ), $args );
		$this->assertSame( substr_count( $sql, '%' ), count( $args ), 'Jeder Platzhalter hat einen Wert' );
	}

	public function test_journal_where_ungeklaerte_farbe_trifft_leere() {
		$f = Lager::journal_filter( array( 'color' => 'ungeklaert' ) );
		list( $sql, $args ) = Lager::journal_where( $f, array(), 'wp_t' );
		$this->assertStringContainsString( "color IS NULL OR color = ''", $sql );
		$this->assertSame( array( 'ungeklaert' ), $args );
	}

	public function test_journal_where_suche_nummer_artikel_und_notiz() {
		$f = Lager::journal_filter( array( 'q' => '1234' ) );
		list( $sql, $args ) = Lager::journal_where( $f, array( 5, 5, 8 ), 'wp_t' );
		$this->assertStringContainsString( 'id = %d OR ref_id = %d OR reverses_id = %d', $sql );
		$this->assertStringContainsString( 'article_id IN (%d,%d)', $sql );
		$this->assertStringContainsString( 'note LIKE %s OR void_reason LIKE %s', $sql );
		$this->assertSame( array( 1234, 1234, 1234, 5, 8, '%1234%', '%1234%' ), $args );

		$f = Lager::journal_filter( array( 'q' => '50%_rabatt' ) );
		list( , $args ) = Lager::journal_where( $f, array(), 'wp_t' );
		$this->assertSame( '%50\\%\\_rabatt%', $args[0], 'Platzhalter im Suchtext werden maskiert' );
	}

	public function test_journal_where_status() {
		$f = Lager::journal_filter( array( 'state' => 'gegenbuchung' ) );
		$this->assertSame( 'reverses_id IS NOT NULL', Lager::journal_where( $f, array(), 'wp_t' )[0] );
		$f = Lager::journal_filter( array( 'state' => 'storniert' ) );
		$this->assertSame( 'id IN (SELECT reverses_id FROM wp_t WHERE reverses_id IS NOT NULL)', Lager::journal_where( $f, array(), 'wp_t' )[0] );
	}

	public function test_match_articles_sku_und_name() {
		$articles = array(
			3 => array( 'sku' => 'VASE-01', 'name' => 'Vase groß' ),
			4 => array( 'sku' => 'SCH-02', 'name' => 'Schale' ),
		);
		$this->assertSame( array( 3 ), Lager::match_articles( $articles, 'vase' ) );
		$this->assertSame( array( 4 ), Lager::match_articles( $articles, 'sch-' ) );
		$this->assertSame( array(), Lager::match_articles( $articles, '' ) );
	}
}
