<?php
/**
 * Buchhaltung im ERP (Rechnungen, Kasse, Journal, Dauerbuchungen):
 * reine Regeln und Rechte der Routen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\BuchBuchungen;
use PHPUnit\Framework\TestCase;

class ErpBuchBuchungenTest extends TestCase {

	private function src( $file ) {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $file );
	}

	/* ---------- Filter ---------- */

	public function test_filter_wird_bereinigt() {
		$f = BuchBuchungen::clean_filter(
			array(
				'von'       => '2026-02-30',
				'bis'       => '2026-03-31',
				'status'    => 'quatsch',
				'typ'       => 'einnahme',
				'kategorie' => '  Büro  ',
				'q'         => '50%_x',
				'seite'     => '-3',
			)
		);

		$this->assertSame( '', $f['von'] );
		$this->assertSame( '2026-03-31', $f['bis'] );
		$this->assertSame( '', $f['status'] );
		$this->assertSame( 'einnahme', $f['typ'] );
		$this->assertSame( 'Büro', $f['kategorie'] );
		$this->assertSame( '50\\%\\_x', $f['q'] );
		$this->assertSame( 1, $f['seite'] );
	}

	public function test_filter_leer_und_fremde_typen() {
		$f = BuchBuchungen::clean_filter( array( 'typ' => 'x; DROP', 'status' => array( 'aktiv' ), 'q' => array( 'a' ) ) );

		$this->assertSame( '', $f['typ'] );
		$this->assertSame( '', $f['status'] );
		$this->assertSame( '', $f['q'] );
		$this->assertSame( '', $f['von'] );
	}

	public function test_zeitraum_richtig_herum() {
		$this->assertSame( '', BuchBuchungen::range_error( '', '2026-01-01' ) );
		$this->assertSame( '', BuchBuchungen::range_error( '2026-01-01', '2026-01-01' ) );
		$this->assertStringContainsString( 'Startdatum', BuchBuchungen::range_error( '2026-02-01', '2026-01-01' ) );
	}

	public function test_where_baut_nur_platzhalter() {
		$f = BuchBuchungen::clean_filter( array( 'von' => '2026-01-01', 'bis' => '2026-01-31', 'typ' => 'ausgabe', 'kategorie' => 'Miete', 'q' => "a'b" ) );
		list( $sql, $params ) = BuchBuchungen::journal_where( $f );

		$this->assertSame( 7, substr_count( $sql, '%s' ) );
		$this->assertCount( 7, $params );
		$this->assertStringNotContainsString( "a'b", $sql );
		$this->assertSame( "%a'b%", $params[4] );

		list( $sql2, $p2 ) = BuchBuchungen::rechnungen_where( BuchBuchungen::clean_filter( array( 'status' => 'storniert', 'bis' => '2026-05-01' ) ) );
		$this->assertStringContainsString( 'storniert = 1', $sql2 );
		$this->assertSame( array( '2026-05-01 23:59:59' ), $p2 );

		list( $sql3, $p3 ) = BuchBuchungen::kasse_where( BuchBuchungen::clean_filter( array() ) );
		$this->assertSame( '1=1', $sql3 );
		$this->assertSame( array(), $p3 );
	}

	/* ---------- Summen ---------- */

	public function test_journalsummen_ohne_storniertes() {
		$s = BuchBuchungen::sum_journal(
			array(
				array( 'typ' => 'einnahme', 'storniert' => 0, 'anzahl' => 2, 'summe' => '100.10' ),
				array( 'typ' => 'einnahme', 'storniert' => 1, 'anzahl' => 1, 'summe' => '40.00' ),
				array( 'typ' => 'storno', 'storniert' => 0, 'anzahl' => 1, 'summe' => '40.00' ),
				array( 'typ' => 'ausgabe', 'storniert' => 0, 'anzahl' => 3, 'summe' => '30.20' ),
			)
		);

		$this->assertSame( 100.1, $s['einnahmen'] );
		$this->assertSame( 30.2, $s['ausgaben'] );
		$this->assertSame( 69.9, $s['saldo'] );
		$this->assertSame( 40.0, $s['storniert'] );
		$this->assertSame( 40.0, $s['gegenbuchungen'] );
		$this->assertSame( 7, $s['anzahl'] );
	}

	public function test_summen_leer_und_ohne_rundungsfehler() {
		$s = BuchBuchungen::sum_journal( array() );
		$this->assertSame( 0.0, $s['saldo'] );

		$s = BuchBuchungen::sum_journal(
			array(
				array( 'typ' => 'einnahme', 'storniert' => 0, 'anzahl' => 1, 'summe' => '0.30' ),
				array( 'typ' => 'ausgabe', 'storniert' => 0, 'anzahl' => 1, 'summe' => '0.10' ),
			)
		);
		$this->assertSame( 0.2, $s['saldo'] );
	}

	/* ---------- Storno ---------- */

	public function test_storno_braucht_grund() {
		$this->assertNotSame( '', BuchBuchungen::storno_error( '' ) );
		$this->assertNotSame( '', BuchBuchungen::storno_error( "  \n  " ) );
		$this->assertNotSame( '', BuchBuchungen::storno_error( 'ja' ) );
		$this->assertNotSame( '', BuchBuchungen::storno_error( null ) );
		$this->assertNotSame( '', BuchBuchungen::storno_error( array( 'Kunde hat storniert' ) ) );
		$this->assertSame( '', BuchBuchungen::storno_error( 'Kunde hat storniert' ) );
	}

	public function test_stornogrund_wird_bereinigt() {
		$this->assertSame( 'a b c', BuchBuchungen::clean_grund( "  a\n b\t c " ) );
		$this->assertSame( 200, strlen( BuchBuchungen::clean_grund( str_repeat( 'x', 500 ) ) ) );
	}

	/* ---------- Kasse ---------- */

	private function kasse( array $over, $saldo = 100.0 ) {
		return BuchBuchungen::kasse_check(
			array_merge(
				array( 'datum' => '2026-03-01', 'beschreibung' => 'Porto', 'art' => 'ausgabe', 'betrag' => '12,50' ),
				$over
			),
			$saldo
		);
	}

	public function test_kasse_gueltiger_eintrag() {
		$c = $this->kasse( array() );

		$this->assertTrue( $c['ok'] );
		$this->assertSame( 12.5, $c['data']['ausgabe'] );
		$this->assertSame( 0, $c['data']['einnahme'] );
		$this->assertSame( 87.5, $c['saldo_neu'] );

		$e = $this->kasse( array( 'art' => 'einnahme', 'betrag' => 5 ) );
		$this->assertSame( 5.0, $e['data']['einnahme'] );
		$this->assertSame( 105.0, $e['saldo_neu'] );
	}

	public function test_kasse_pflichtangaben() {
		$this->assertFalse( $this->kasse( array( 'datum' => '2026-13-01' ) )['ok'] );
		$this->assertFalse( $this->kasse( array( 'beschreibung' => '  ' ) )['ok'] );
		$this->assertFalse( $this->kasse( array( 'art' => 'beides' ) )['ok'] );
		$this->assertFalse( $this->kasse( array( 'betrag' => '0' ) )['ok'] );
		$this->assertFalse( $this->kasse( array( 'betrag' => '-5' ) )['ok'] );
		$this->assertFalse( $this->kasse( array( 'betrag' => 'abc' ) )['ok'] );
		$this->assertFalse( $this->kasse( array( 'betrag' => '99999999' ) )['ok'] );
	}

	public function test_kasse_negativer_bestand_nur_mit_bestaetigung() {
		$c = $this->kasse( array( 'betrag' => '150' ) );
		$this->assertFalse( $c['ok'] );
		$this->assertTrue( $c['confirm'] );
		$this->assertSame( -50.0, $c['saldo_neu'] );
		$this->assertStringContainsString( '-50,00', $c['message'] );

		$ok = $this->kasse( array( 'betrag' => '150', 'bestaetigt' => true ) );
		$this->assertTrue( $ok['ok'] );

		$null = $this->kasse( array( 'betrag' => '100' ) );
		$this->assertTrue( $null['ok'], 'Genau null ist erlaubt.' );
	}

	public function test_kasse_cent_genau() {
		$c = $this->kasse( array( 'betrag' => '0,10' ), 0.3 );
		$this->assertTrue( $c['ok'] );
		$this->assertSame( 0.2, $c['saldo_neu'] );
	}

	public function test_kasse_storno_sperren() {
		$this->assertNotSame( '', BuchBuchungen::kasse_storno_block( null ) );
		$this->assertNotSame( '', BuchBuchungen::kasse_storno_block( (object) array( 'storniert' => 1, 'storno_von_id' => null ) ) );
		$this->assertNotSame( '', BuchBuchungen::kasse_storno_block( (object) array( 'storniert' => 0, 'storno_von_id' => 7 ) ) );
		$this->assertSame( '', BuchBuchungen::kasse_storno_block( (object) array( 'storniert' => 0, 'storno_von_id' => null ) ) );
	}

	public function test_kassenstand_nach_storno() {
		$this->assertSame( 50.0, BuchBuchungen::saldo_after_storno( 100, 50, 0 ) );
		$this->assertSame( 130.0, BuchBuchungen::saldo_after_storno( 100, 0, 30 ) );
		$this->assertSame( '', BuchBuchungen::negative_cash( 0 ) );
		$this->assertNotSame( '', BuchBuchungen::negative_cash( -0.01 ) );
	}

	/* ---------- Dauerbuchungen ---------- */

	public function test_faelligkeit_wie_execute_due() {
		$this->assertTrue( BuchBuchungen::dauer_faellig( true, '2026-03-01', '', '2026-03-01' ) );
		$this->assertTrue( BuchBuchungen::dauer_faellig( true, '2026-02-01', '2026-03-01', '2026-03-01' ) );
		$this->assertFalse( BuchBuchungen::dauer_faellig( true, '2026-03-02', '', '2026-03-01' ) );
		$this->assertFalse( BuchBuchungen::dauer_faellig( false, '2026-01-01', '', '2026-03-01' ) );
		$this->assertFalse( BuchBuchungen::dauer_faellig( true, '2026-01-01', '2026-02-01', '2026-03-01' ), 'Nach dem Ende nicht mehr.' );
		$this->assertFalse( BuchBuchungen::dauer_faellig( true, '', '', '2026-03-01' ) );
	}

	public function test_naechstes_datum_je_intervall() {
		$this->assertSame( '2026-03-08', BuchBuchungen::next_date( '2026-03-01', 'woechentlich' ) );
		$this->assertSame( '2026-04-01', BuchBuchungen::next_date( '2026-03-01', 'monatlich' ) );
		$this->assertSame( '2026-06-01', BuchBuchungen::next_date( '2026-03-01', 'quartalsweise' ) );
		$this->assertSame( '2026-09-01', BuchBuchungen::next_date( '2026-03-01', 'halbjaehrlich' ) );
		$this->assertSame( '2027-03-01', BuchBuchungen::next_date( '2026-03-01', 'jaehrlich' ) );
		$this->assertSame( '2026-04-01', BuchBuchungen::next_date( '2026-03-01', 'unbekannt' ) );
	}

	public function test_vorschau_eines_laufs() {
		$v = BuchBuchungen::dauer_vorschau(
			array( 'betrag' => '-99.5', 'intervall' => 'monatlich', 'naechste_ausfuehrung' => '2026-03-01', 'ende_datum' => '' ),
			'2026-03-15'
		);

		$this->assertSame( 99.5, $v['betrag'] );
		$this->assertSame( '2026-03-15', $v['beleg_datum'] );
		$this->assertSame( '2026-04-01', $v['naechste'] );
		$this->assertFalse( $v['endet'] );
		$this->assertSame( 0, $v['rueckstand'] );
	}

	public function test_vorschau_rueckstand_und_ende() {
		$v = BuchBuchungen::dauer_vorschau(
			array( 'betrag' => 10, 'intervall' => 'monatlich', 'naechste_ausfuehrung' => '2026-01-01', 'ende_datum' => '2026-02-01' ),
			'2026-04-10'
		);

		$this->assertSame( '2026-02-01', $v['naechste'] );
		$this->assertFalse( $v['endet'], 'Genau am Ende ist noch erlaubt.' );
		$this->assertSame( 3, $v['rueckstand'] );

		$w = BuchBuchungen::dauer_vorschau(
			array( 'betrag' => 10, 'intervall' => 'monatlich', 'naechste_ausfuehrung' => '2026-02-01', 'ende_datum' => '2026-02-15' ),
			'2026-02-10'
		);
		$this->assertTrue( $w['endet'] );
	}

	private function dauer( array $over, $neu = true ) {
		return BuchBuchungen::dauer_check(
			array_merge(
				array( 'bezeichnung' => 'Miete', 'betrag' => '450,00', 'kategorie' => 'Miete', 'intervall' => 'monatlich', 'start_datum' => '2026-03-01', 'ende_datum' => '' ),
				$over
			),
			array( 'Miete', 'Versicherung' ),
			array( 'monatlich', 'jaehrlich' ),
			$neu
		);
	}

	public function test_dauerbuchung_pruefung() {
		$ok = $this->dauer( array() );
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 450.0, $ok['data']['betrag'] );
		$this->assertNull( $ok['data']['ende_datum'] );

		$this->assertFalse( $this->dauer( array( 'bezeichnung' => '' ) )['ok'] );
		$this->assertFalse( $this->dauer( array( 'betrag' => '0' ) )['ok'] );
		$this->assertFalse( $this->dauer( array( 'kategorie' => 'Erfunden' ) )['ok'] );
		$this->assertFalse( $this->dauer( array( 'intervall' => 'taeglich' ) )['ok'] );
		$this->assertFalse( $this->dauer( array( 'start_datum' => '' ) )['ok'] );
		$this->assertFalse( $this->dauer( array( 'ende_datum' => '2026-02-01' ) )['ok'] );
		$this->assertFalse( $this->dauer( array( 'ende_datum' => 'morgen' ) )['ok'] );
		$this->assertTrue( $this->dauer( array( 'start_datum' => '' ), false )['ok'], 'Beim Ändern braucht es keinen Start.' );
	}

	/* ---------- Zahlen, CSV ---------- */

	public function test_betrag_mit_komma() {
		$this->assertSame( 12.5, BuchBuchungen::clean_amount( '12,5' ) );
		$this->assertSame( 12.35, BuchBuchungen::clean_amount( ' 12.349 ' ) );
		$this->assertNull( BuchBuchungen::clean_amount( 'zwölf' ) );
		$this->assertNull( BuchBuchungen::clean_amount( null ) );
		$this->assertNull( BuchBuchungen::clean_amount( 'INF' ) );
	}

	public function test_csv_schuetzt_formeln() {
		$this->assertSame( "'=1+1", BuchBuchungen::csv_safe( '=1+1' ) );
		$this->assertSame( "'@x", BuchBuchungen::csv_safe( '@x' ) );
		$this->assertSame( 'normal', BuchBuchungen::csv_safe( 'normal' ) );

		$rows = BuchBuchungen::journal_csv_rows(
			array( array( 'buchungsnummer' => 'B-1', 'buchungsdatum' => '2026-01-02', 'typ' => 'ausgabe', 'betrag' => '12.5', 'kategorie' => 'Büro', 'beschreibung' => '=cmd', 'storniert' => 1 ) )
		);
		$this->assertCount( 2, $rows );
		$this->assertSame( '12,50', $rows[1][3] );
		$this->assertSame( "'=cmd", $rows[1][5] );
		$this->assertSame( 'storniert', $rows[1][6] );
	}

	/* ---------- Quelltext-Wächter ---------- */

	public function test_alle_routen_am_buchhaltungsrecht() {
		$src = $this->src( 'includes/Erp/Pages/BuchBuchungen.php' );

		$this->assertStringContainsString( "\$buch = \$api->needs( array( 'finanzen.buchhaltung' ) )", $src );

		preg_match_all( "/self::route\( \\\$ns, '([^']+)', '(GET|POST)', '(\w+)', (\\\$\w+) \)/", $src, $m, PREG_SET_ORDER );
		$this->assertCount( 14, $m );
		foreach ( $m as $r ) {
			$this->assertSame( '$buch', $r[4], $r[1] . ' muss am Buchhaltungsrecht hängen.' );
			$this->assertStringStartsWith( '/erp/buch/', $r[1] );
		}
		$this->assertStringNotContainsString( '__return_true', $src );
	}

	public function test_schreibende_routen_sind_post() {
		$src = $this->src( 'includes/Erp/Pages/BuchBuchungen.php' );

		foreach ( array( 'rechnungen/storno', 'kasse/eintrag', 'kasse/storno', 'dauer/speichern', 'dauer/deaktivieren', 'dauer/ausfuehren' ) as $route ) {
			$this->assertMatchesRegularExpression( "#'/erp/buch/" . preg_quote( $route, '#' ) . "', 'POST'#", $src, $route );
		}
		foreach ( array( 'rechnungen', 'rechnungen/fehlend', 'kasse', 'kasse/tagesabschluss', 'journal', 'journal/export', 'dauer' ) as $route ) {
			$this->assertMatchesRegularExpression( "#'/erp/buch/" . preg_quote( $route, '#' ) . "', 'GET'#", $src, $route );
		}
	}

	public function test_nie_loeschen() {
		$src = $this->src( 'includes/Erp/Pages/BuchBuchungen.php' );

		$this->assertDoesNotMatchRegularExpression( '/\$wpdb->delete|DELETE\s+FROM|TRUNCATE/i', $src );
		$this->assertStringContainsString( 'InvoiceGenerator::stornieren(', $src );
		$this->assertStringContainsString( 'KassenbuchRepository::create_storno(', $src );
		$this->assertStringContainsString( 'KassenbuchRepository::create(', $src );
		$this->assertStringContainsString( 'DauerbuchungRepository::deactivate(', $src );
	}

	public function test_oberflaeche_meldet_vier_reiter() {
		$js = $this->src( 'assets/erp/pages/buch_buchungen.js' );

		foreach ( array( 'rechnungen' => 20, 'kasse' => 30, 'journal' => 40, 'dauer' => 50 ) as $key => $order ) {
			$this->assertMatchesRegularExpression( "/UI\.buchTabs\['" . $key . "'\] = \{\s+label: '[^']+',\s+order: " . $order . ',/', $js, $key );
		}
	}

	public function test_oberflaeche_ist_es5_und_ohne_innerhtml() {
		$js = $this->src( 'assets/erp/pages/buch_buchungen.js' );

		$this->assertStringNotContainsString( 'innerHTML', $js );
		$this->assertStringNotContainsString( 'insertAdjacentHTML', $js );
		$this->assertStringNotContainsString( '=>', $js );
		$this->assertDoesNotMatchRegularExpression( '/\b(let|const)\s/', $js );
		$this->assertStringNotContainsString( '`', $js );
	}
}
