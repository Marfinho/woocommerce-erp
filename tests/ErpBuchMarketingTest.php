<?php
/**
 * Marketing-Kosten in der Buchhaltung: reine Regeln (Summen nach Kanal und Monat, Ampel,
 * Zuordnungsvorschlag, CSV) und Quelltext-Wächter für die Rechte der Routen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\BuchMarketing;
use Lindenweg16\Erp\Pages\Marketing;
use PHPUnit\Framework\TestCase;

class ErpBuchMarketingTest extends TestCase {

	private function row( array $over = array() ) {
		return array_merge( array(
			'kanal'        => 'Wochenmarkt',
			'budget'       => 100.0,
			'kosten'       => 50.0,
			'rabatt'       => 0.0,
			'einsatz'      => 50.0,
			'umsatz'       => 200.0,
			'bestellungen' => 0,
		), $over );
	}

	public function test_summen_nach_kanal_fassen_schreibweisen_zusammen() {
		$rows = array(
			$this->row( array( 'kanal' => 'Google Ads', 'kosten' => 80.0, 'einsatz' => 80.0, 'umsatz' => 160.0 ) ),
			$this->row( array( 'kanal' => 'google ads ', 'kosten' => 20.0, 'einsatz' => 20.0, 'umsatz' => 40.0 ) ),
			$this->row( array( 'kanal' => 'Wochenmarkt', 'kosten' => 30.0, 'einsatz' => 30.0, 'umsatz' => 0.0 ) ),
			$this->row( array( 'kanal' => '', 'kosten' => 5.0, 'einsatz' => 5.0, 'umsatz' => 0.0 ) ),
		);
		$out = BuchMarketing::summarize_channels( $rows );

		$this->assertCount( 3, $out );
		$this->assertSame( 'Google Ads', $out[0]['channel'] );
		$this->assertSame( 2, $out[0]['count'] );
		$this->assertSame( 100.0, $out[0]['kosten'] );
		$this->assertSame( 200.0, $out[0]['umsatz'] );
		$this->assertSame( 2.0, $out[0]['roas'] );
		$this->assertSame( 100.0, $out[0]['ergebnis'] );
		$this->assertSame( 'Wochenmarkt', $out[1]['channel'] );
		$this->assertSame( 0.0, $out[1]['umsatz'] );
		$this->assertSame( 0.0, $out[1]['roas'] );
		$this->assertSame( 'Ohne Kanal', $out[2]['channel'] );
	}

	public function test_kanal_ohne_einsatz_hat_keinen_roas() {
		$out = BuchMarketing::summarize_channels( array( $this->row( array( 'kosten' => 0.0, 'einsatz' => 0.0, 'umsatz' => 40.0 ) ) ) );
		$this->assertNull( $out[0]['roas'] );
		$this->assertSame( 40.0, $out[0]['ergebnis'] );
	}

	public function test_summen_und_ampel_gesamt() {
		$t = BuchMarketing::totals( array(
			$this->row( array( 'budget' => 100.0, 'kosten' => 60.0, 'rabatt' => 10.0, 'einsatz' => 70.0, 'umsatz' => 140.0, 'bestellungen' => 2 ) ),
			$this->row( array( 'budget' => 100.0, 'kosten' => 30.0, 'rabatt' => 0.0, 'einsatz' => 30.0, 'umsatz' => 60.0, 'bestellungen' => 1 ) ),
		) );
		$this->assertSame( 2, $t['count'] );
		$this->assertSame( 200.0, $t['budget'] );
		$this->assertSame( 90.0, $t['kosten'] );
		$this->assertSame( 100.0, $t['einsatz'] );
		$this->assertSame( 100.0, $t['ergebnis'] );
		$this->assertSame( 2.0, $t['roas'] );
		$this->assertSame( 3, $t['bestellungen'] );
		$this->assertSame( 'im_rahmen', $t['ampel'] );
	}

	public function test_ampel_stufen_aus_der_gemeinsamen_rechnung() {
		$ampel = function ( $budget, $kosten ) {
			return BuchMarketing::totals( array( $this->row( array( 'budget' => $budget, 'kosten' => $kosten ) ) ) )['ampel'];
		};
		$this->assertSame( 'im_rahmen', $ampel( 100.0, 79.0 ) );
		$this->assertSame( 'knapp', $ampel( 100.0, 80.0 ) );
		$this->assertSame( 'knapp', $ampel( 100.0, 100.0 ) );
		$this->assertSame( 'ueberzogen', $ampel( 100.0, 100.5 ) );
		$this->assertSame( 'kein_budget', $ampel( 0.0, 20.0 ) );
	}

	public function test_leere_summe() {
		$t = BuchMarketing::totals( array() );
		$this->assertSame( 0, $t['count'] );
		$this->assertNull( $t['roas'] );
		$this->assertSame( 'kein_budget', $t['ampel'] );
	}

	public function test_monate_durchgehend_und_aufsteigend() {
		$out = BuchMarketing::summarize_months( array(
			array( 'datum' => '2026-11-03', 'betrag' => 10.0 ),
			array( 'datum' => '2026-08-20', 'betrag' => 25.5 ),
			array( 'datum' => '2026-08-02', 'betrag' => 4.5 ),
			array( 'datum' => '2027-01-15', 'betrag' => 7.0 ),
			array( 'datum' => 'kaputt', 'betrag' => 99.0 ),
		) );
		$monate = array_column( $out, 'month' );
		$this->assertSame( array( '2026-08', '2026-09', '2026-10', '2026-11', '2026-12', '2027-01' ), $monate );
		$this->assertSame( 30.0, $out[0]['sum'] );
		$this->assertSame( 0.0, $out[1]['sum'] );
		$this->assertSame( 10.0, $out[3]['sum'] );
		$this->assertSame( 7.0, $out[5]['sum'] );
		$this->assertSame( array(), BuchMarketing::summarize_months( array() ) );
	}

	public function test_dezember_laeuft_ins_naechste_jahr() {
		$out = BuchMarketing::summarize_months( array(
			array( 'datum' => '2026-12-31', 'betrag' => 1.0 ),
			array( 'datum' => '2027-02-01', 'betrag' => 2.0 ),
		) );
		$this->assertSame( array( '2026-12', '2027-01', '2027-02' ), array_column( $out, 'month' ) );
	}

	public function test_marketing_erkennung_nach_konto_und_kategorie() {
		$this->assertTrue( BuchMarketing::looks_like_marketing( '4600', '' ) );
		$this->assertTrue( BuchMarketing::looks_like_marketing( '', 'Werbung' ) );
		$this->assertTrue( BuchMarketing::looks_like_marketing( '', 'Online-Marketing' ) );
		$this->assertTrue( BuchMarketing::looks_like_marketing( '4610', 'Sonstiges' ) );
		$this->assertFalse( BuchMarketing::looks_like_marketing( '4930', 'Büromaterial' ) );
		$this->assertFalse( BuchMarketing::looks_like_marketing( '4672', 'Fahrtkosten' ) );
		$this->assertFalse( BuchMarketing::looks_like_marketing( '', '' ) );
	}

	public function test_zeitraum_vorgabe_und_fehler() {
		$r = BuchMarketing::normalize_range( '', '', '2026-10-22' );
		$this->assertSame( '', $r['error'] );
		$this->assertSame( '2026-01-01', $r['von'] );
		$this->assertSame( '2026-12-31', $r['bis'] );

		$this->assertNotSame( '', BuchMarketing::normalize_range( '2026-02-30', '2026-03-01', '2026-10-22' )['error'] );
		$this->assertStringContainsString( 'vor dem Beginn', BuchMarketing::normalize_range( '2026-05-01', '2026-04-01', '2026-10-22' )['error'] );
		$this->assertSame( '', BuchMarketing::normalize_range( '2026-05-01', '2026-05-01', '2026-10-22' )['error'] );
	}

	public function test_kampagne_im_zeitraum_nach_veranstaltungstag_sonst_beginn() {
		$k = array( 'event_datum' => '2026-12-06', 'start_datum' => '2026-11-01' );
		$this->assertTrue( BuchMarketing::in_range( $k, '2026-12-01', '2026-12-31' ) );
		$this->assertFalse( BuchMarketing::in_range( $k, '2026-11-01', '2026-11-30' ) );
		$this->assertTrue( BuchMarketing::in_range( array( 'event_datum' => null, 'start_datum' => '2026-11-01' ), '2026-11-01', '2026-11-30' ) );
		$this->assertFalse( BuchMarketing::in_range( array(), '2026-01-01', '2026-12-31' ) );
	}

	private function campaigns() {
		return array(
			array( 'id' => 1, 'name' => 'Weihnachtsmarkt Altstadt', 'kanal' => 'Wochenmarkt', 'status' => 'abgeschlossen', 'start_datum' => '2025-11-20', 'ende_datum' => null, 'event_datum' => '2025-12-06' ),
			array( 'id' => 2, 'name' => 'Frühlingsmarkt', 'kanal' => 'Markt', 'status' => 'aktiv', 'start_datum' => '2026-03-01', 'ende_datum' => null, 'event_datum' => '2026-04-12' ),
			array( 'id' => 3, 'name' => 'Google Herbst', 'kanal' => 'Google Ads', 'status' => 'aktiv', 'start_datum' => '2026-09-01', 'ende_datum' => '2026-10-31', 'event_datum' => null ),
		);
	}

	public function test_vorschlag_nach_datum() {
		$s = BuchMarketing::suggest_campaigns( array( 'datum' => '2026-04-10', 'haendler' => 'Stadt', 'notiz' => '' ), $this->campaigns() );
		$this->assertSame( 2, $s[0]['id'] );
		$this->assertNotSame( '', $s[0]['reason'] );
	}

	public function test_vorschlag_nach_name_und_kanal_im_text() {
		$s = BuchMarketing::suggest_campaigns( array( 'datum' => '2026-10-15', 'haendler' => 'Google Ireland', 'notiz' => 'Google Ads Oktober' ), $this->campaigns() );
		$this->assertSame( 3, $s[0]['id'] );
		$this->assertStringContainsString( 'Kanal', $s[0]['reason'] );
	}

	public function test_kein_vorschlag_ohne_treffer() {
		$s = BuchMarketing::suggest_campaigns( array( 'datum' => '2024-01-15', 'haendler' => 'Baumarkt', 'notiz' => '' ), $this->campaigns() );
		$this->assertSame( array(), $s );
		$this->assertSame( array(), BuchMarketing::suggest_campaigns( array(), $this->campaigns() ) );
	}

	public function test_laufende_kampagne_vor_abgeschlossener() {
		$k = array(
			array( 'id' => 10, 'name' => 'Alt', 'kanal' => '', 'status' => 'abgeschlossen', 'start_datum' => '2026-05-01', 'ende_datum' => '2026-06-01', 'event_datum' => null ),
			array( 'id' => 11, 'name' => 'Neu', 'kanal' => '', 'status' => 'aktiv', 'start_datum' => '2026-05-01', 'ende_datum' => '2026-06-01', 'event_datum' => null ),
		);
		$s = BuchMarketing::suggest_campaigns( array( 'datum' => '2026-05-15', 'haendler' => '', 'notiz' => '' ), $k );
		$this->assertSame( array( 11, 10 ), array_column( $s, 'id' ) );
	}

	public function test_rechnung_kurz_nach_dem_ende_passt_noch() {
		$s = BuchMarketing::suggest_campaigns( array( 'datum' => '2026-12-15', 'haendler' => '', 'notiz' => '' ), array( $this->campaigns()[2] ) );
		$this->assertSame( 3, $s[0]['id'] );
	}

	public function test_csv_zelle_zahlen_deutsch_und_text_geschuetzt() {
		$this->assertSame( '12,50', BuchMarketing::csv_cell( 12.5 ) );
		$this->assertSame( '-3,00', BuchMarketing::csv_cell( -3.0 ) );
		$this->assertSame( '4', BuchMarketing::csv_cell( 4 ) );
		$this->assertSame( '', BuchMarketing::csv_cell( null ) );
		$this->assertSame( '"a;b"', BuchMarketing::csv_cell( 'a;b' ) );
		$this->assertSame( '"sagt ""hallo"""', BuchMarketing::csv_cell( 'sagt "hallo"' ) );
		$this->assertSame( "'=SUMME(A1)", BuchMarketing::csv_cell( '=SUMME(A1)' ) );
		$this->assertSame( "'@x", BuchMarketing::csv_cell( '@x' ) );
		$this->assertSame( 'zwei Zeilen', BuchMarketing::csv_cell( "zwei\nZeilen" ) );
	}

	public function test_csv_aufbau_mit_summenzeile() {
		$rows = array( array(
			'kst' => 'K-2026-001', 'name' => 'Markt; Nord', 'kanal' => 'Markt', 'art' => 'Event / Markt', 'status' => 'aktiv',
			'datum' => '2026-04-12', 'budget' => 100.0, 'kosten' => 50.0, 'rabatt' => 0.0, 'einsatz' => 50.0, 'umsatz' => 120.0,
			'ergebnis' => 70.0, 'roas' => 2.4, 'bestellungen' => 0, 'ampel_text' => 'im Rahmen',
		) );
		$csv   = BuchMarketing::csv( BuchMarketing::csv_header(), BuchMarketing::csv_rows( $rows, BuchMarketing::totals( array( $this->row() ) ) ) );
		$lines = explode( "\r\n", trim( $csv ) );
		$this->assertCount( 3, $lines );
		$this->assertSame( 15, count( BuchMarketing::csv_header() ) );
		$this->assertStringStartsWith( 'K-2026-001;"Markt; Nord";Markt;', $lines[1] );
		$this->assertStringContainsString( ';2,40;0;im Rahmen', $lines[1] );
		$this->assertStringStartsWith( ';Summe;', $lines[2] );
	}

	/* ---------- Quelltext-Wächter: Routen am Recht ---------- */

	private function source( $rel ) {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_waechter_lesende_routen_brauchen_buchhaltungsrecht() {
		$src = $this->source( 'includes/Erp/Pages/BuchMarketing.php' );
		$this->assertMatchesRegularExpression( "/\\\$read\\s*=\\s*\\\$api->needs\\(\\s*array\\(\\s*'finanzen\\.buchhaltung'\\s*\\)\\s*\\)/", $src );
		foreach ( array( "'/erp/buch/marketing'", "'/erp/buch/marketing/export'" ) as $route ) {
			$this->assertMatchesRegularExpression( '/register_rest_route\(\s*\$ns,\s*' . preg_quote( $route, '/' ) . ",\\s*array\\(\\s*'methods'\\s*=>\\s*'GET',[^;]*?'permission_callback'\\s*=>\\s*\\\$read,/s", $src, $route );
		}
	}

	public function test_waechter_zuordnen_braucht_beide_rechte_und_ist_post() {
		$src = $this->source( 'includes/Erp/Pages/BuchMarketing.php' );
		$this->assertMatchesRegularExpression( "/\\\$write\\s*=\\s*\\\$api->needs\\(\\s*array\\(\\s*'finanzen\\.buchhaltung',\\s*'verkauf\\.marketing'\\s*\\)\\s*\\)/", $src );
		$this->assertMatchesRegularExpression( "/'\\/erp\\/buch\\/marketing\\/zuordnen',\\s*array\\(\\s*'methods'\\s*=>\\s*'POST',[^;]*?'permission_callback'\\s*=>\\s*\\\$write,/s", $src );
	}

	public function test_waechter_keine_offene_route() {
		$src = $this->source( 'includes/Erp/Pages/BuchMarketing.php' );
		$this->assertStringNotContainsString( '__return_true', $src );
		$this->assertSame(
			preg_match_all( '/register_rest_route\(/', $src ),
			preg_match_all( "/'permission_callback'\\s*=>\\s*\\\$(read|write)\\b/", $src ),
			'Jede Route braucht ein permission_callback'
		);

		$src = $this->source( 'includes/Erp/Pages/Marketing.php' );
		$this->assertStringNotContainsString( '__return_true', $src );
		$this->assertSame( 1, preg_match_all( '/register_rest_route\(/', $src ), 'alle Routen laufen über die eine Hilfsfunktion' );
		$this->assertMatchesRegularExpression( "/'permission_callback'\\s*=>\\s*\\\$perm,/", $src );
	}

	public function test_waechter_marketing_routen_am_marketing_recht() {
		$src = $this->source( 'includes/Erp/Pages/Marketing.php' );
		$this->assertMatchesRegularExpression( "/\\\$perm\\s*=\\s*\\\$api->needs\\(\\s*array\\(\\s*'verkauf\\.marketing'\\s*\\)\\s*\\)/", $src );
		foreach ( array( 'auswertung', 'status', 'duplizieren', 'zuordnen', 'loesen', 'km', 'beenden' ) as $teil ) {
			$this->assertStringContainsString( "/erp/marketing/(?P<id>\\d+)/{$teil}'", $src, $teil );
		}
		// Schreibende Routen dürfen nicht über GET erreichbar sein.
		foreach ( array( 'status', 'duplizieren', 'zuordnen', 'loesen', 'km', 'beenden' ) as $teil ) {
			$this->assertMatchesRegularExpression( "/\\/{$teil}',\\s*'POST'/", $src, $teil );
		}
		$this->assertMatchesRegularExpression( "/\\/auswertung',\\s*'GET'/", $src );
	}

	public function test_waechter_assign_to_wird_gemeinsam_genutzt() {
		$src = $this->source( 'includes/Erp/Pages/BuchMarketing.php' );
		$this->assertStringContainsString( 'Marketing::assign_to(', $src );
		$this->assertStringContainsString( 'KampagnenReport::report(', $src );
		$this->assertTrue( method_exists( Marketing::class, 'assign_to' ) );
	}

	public function test_waechter_js_ohne_innerhtml() {
		foreach ( array( 'assets/erp/pages/buch_marketing.js', 'assets/erp/pages/zmarketing.js' ) as $rel ) {
			$this->assertDoesNotMatchRegularExpression( '/innerHTML|insertAdjacentHTML|document\.write/', $this->source( $rel ), $rel );
		}
	}
}
