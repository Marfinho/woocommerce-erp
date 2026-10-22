<?php
/**
 * Marketing im ERP: reine Regeln des Schreibens (Kampagne, Kilometer, Anteil, Zuordnung).
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Marketing;
use PHPUnit\Framework\TestCase;

class ErpMarketingTest extends TestCase {

	private function in( array $over = array() ) {
		return array_merge( array( 'name' => 'Weihnachtsmarkt', 'typ' => 'event' ), $over );
	}

	public function test_zahlen_deutsch_und_englisch() {
		$this->assertSame( 12.5, Marketing::parse_number( '12,5' ) );
		$this->assertSame( 12.5, Marketing::parse_number( '12.5' ) );
		$this->assertSame( 1250.0, Marketing::parse_number( '1.250' ) );
		$this->assertSame( 1234.5, Marketing::parse_number( '1.234,5' ) );
		$this->assertSame( 1234.5, Marketing::parse_number( '1,234.5' ) );
		$this->assertSame( 250.0, Marketing::parse_number( '250 €' ) );
		$this->assertSame( 7.0, Marketing::parse_number( 7 ) );
		$this->assertNull( Marketing::parse_number( '' ) );
		$this->assertNull( Marketing::parse_number( 'abc' ) );
		$this->assertNull( Marketing::parse_number( array( 1 ) ) );
	}

	public function test_datum_wird_wirklich_geprueft() {
		$this->assertTrue( Marketing::is_date( '2026-02-28' ) );
		$this->assertFalse( Marketing::is_date( '2026-02-30' ) );
		$this->assertFalse( Marketing::is_date( '28.02.2026' ) );
		$this->assertFalse( Marketing::is_date( '' ) );
	}

	public function test_minimale_kampagne_bekommt_vorgaben() {
		$r = Marketing::validate_campaign( $this->in(), '2026-10-19' );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( '2026-10-19', $r['data']['start_datum'] );
		$this->assertNull( $r['data']['ende_datum'] );
		$this->assertNull( $r['data']['event_datum'] );
		$this->assertSame( 0.0, $r['data']['budget_geplant'] );
		$this->assertSame( 'geplant', $r['data']['status'] );
	}

	public function test_typ_leer_wird_event_wie_frueher() {
		$r = Marketing::validate_campaign( $this->in( array( 'typ' => '' ) ), '2026-10-19' );
		$this->assertSame( 'event', $r['data']['typ'] );
	}

	public function test_name_ist_pflicht() {
		$r = Marketing::validate_campaign( $this->in( array( 'name' => '   ' ) ), '2026-10-19' );
		$this->assertCount( 1, $r['errors'] );
		$this->assertStringContainsString( 'Namen', $r['errors'][0] );
	}

	public function test_unbekannte_art_und_status_werden_abgelehnt() {
		$r = Marketing::validate_campaign( $this->in( array( 'typ' => 'zirkus', 'status' => 'vorbei' ) ), '2026-10-19' );
		$this->assertCount( 2, $r['errors'] );
	}

	public function test_budget_deutsch_gerundet_und_nicht_negativ() {
		$r = Marketing::validate_campaign( $this->in( array( 'budget_geplant' => '1.250,555' ) ), '2026-10-19' );
		$this->assertSame( 1250.56, $r['data']['budget_geplant'] );
		$r = Marketing::validate_campaign( $this->in( array( 'budget_geplant' => '-5' ) ), '2026-10-19' );
		$this->assertSame( 0.0, $r['data']['budget_geplant'] );
		$r = Marketing::validate_campaign( $this->in( array( 'budget_geplant' => 'viel' ) ), '2026-10-19' );
		$this->assertStringContainsString( 'Budget', $r['errors'][0] );
		$r = Marketing::validate_campaign( $this->in( array( 'budget_geplant' => '99999999' ) ), '2026-10-19' );
		$this->assertStringContainsString( 'hoch', $r['errors'][0] );
	}

	public function test_kaputtes_datum_und_ende_vor_beginn() {
		$r = Marketing::validate_campaign( $this->in( array( 'start_datum' => '31.12.2026' ) ), '2026-10-19' );
		$this->assertStringContainsString( 'Beginn', $r['errors'][0] );

		$r = Marketing::validate_campaign( $this->in( array( 'start_datum' => '2026-12-01', 'ende_datum' => '2026-11-30' ) ), '2026-10-19' );
		$this->assertStringContainsString( 'Ende liegt vor dem Beginn', $r['errors'][0] );

		$r = Marketing::validate_campaign( $this->in( array( 'start_datum' => '2026-12-01', 'ende_datum' => '2026-12-01', 'event_datum' => '2026-12-01' ) ), '2026-10-19' );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( '2026-12-01', $r['data']['event_datum'] );
	}

	public function test_kilometer() {
		$this->assertSame( 42.5, Marketing::validate_km( '42,5' )['km'] );
		$this->assertSame( '', Marketing::validate_km( '0' )['error'] );
		$this->assertSame( 0.0, Marketing::validate_km( '0' )['km'] );
		$this->assertNotSame( '', Marketing::validate_km( '' )['error'] );
		$this->assertNotSame( '', Marketing::validate_km( 'weit' )['error'] );
		$this->assertStringContainsString( 'negativ', Marketing::validate_km( '-3' )['error'] );
		$this->assertNotSame( '', Marketing::validate_km( '500000' )['error'] );
	}

	public function test_anteil() {
		$this->assertSame( 100.0, Marketing::validate_share( null )['anteil'] );
		$this->assertSame( 100.0, Marketing::validate_share( '' )['anteil'] );
		$this->assertSame( 33.3, Marketing::validate_share( '33,3' )['anteil'] );
		$this->assertNotSame( '', Marketing::validate_share( '0' )['error'] );
		$this->assertNotSame( '', Marketing::validate_share( '101' )['error'] );
		$this->assertNotSame( '', Marketing::validate_share( 'x' )['error'] );
	}

	public function test_stornierte_belege_sind_gesperrt() {
		$this->assertSame( '', Marketing::beleg_blocker( (object) array( 'storniert' => 0, 'quelle' => 'upload' ) ) );
		$this->assertNotSame( '', Marketing::beleg_blocker( (object) array( 'storniert' => 1, 'quelle' => 'upload' ) ) );
		$this->assertNotSame( '', Marketing::beleg_blocker( (object) array( 'storniert' => 0, 'quelle' => 'storno' ) ) );
		$this->assertNotSame( '', Marketing::beleg_blocker( null ) );
	}

	public function test_kilometerbeleg_nur_ueber_kilometer_loesbar() {
		$this->assertNotSame( '', Marketing::loesen_blocker( 5, 5 ) );
		$this->assertSame( '', Marketing::loesen_blocker( 5, 6 ) );
		$this->assertSame( '', Marketing::loesen_blocker( 5, 0 ) );
	}

	public function test_kilometerdatum() {
		$this->assertSame( '2026-05-01', Marketing::km_datum( array( 'event_datum' => '2026-05-01', 'start_datum' => '2026-04-01' ), '2026-10-19' ) );
		$this->assertSame( '2026-04-01', Marketing::km_datum( array( 'event_datum' => null, 'start_datum' => '2026-04-01' ), '2026-10-19' ) );
		$this->assertSame( '2026-10-19', Marketing::km_datum( array(), '2026-10-19' ) );
	}

	/* ---------- Status-Ablauf, Hinweise, Duplizieren, Vorlagen, Erklärungen ---------- */

	public function test_statusablauf() {
		$this->assertSame( '', Marketing::status_transition_error( 'geplant', 'aktiv' ) );
		$this->assertSame( '', Marketing::status_transition_error( 'aktiv', 'abgeschlossen' ) );
		$this->assertSame( '', Marketing::status_transition_error( 'geplant', 'abgeschlossen' ) );
		$this->assertSame( '', Marketing::status_transition_error( 'abgeschlossen', 'aktiv' ) );
		$this->assertNotSame( '', Marketing::status_transition_error( 'aktiv', 'geplant' ) );
		$this->assertNotSame( '', Marketing::status_transition_error( 'abgeschlossen', 'geplant' ) );
		$this->assertStringContainsString( 'schon', Marketing::status_transition_error( 'aktiv', 'aktiv' ) );
		$this->assertStringContainsString( 'gibt es nicht', Marketing::status_transition_error( 'aktiv', 'vorbei' ) );
	}

	public function test_naechster_schritt() {
		$this->assertSame( 'aktiv', Marketing::next_status( 'geplant' )['value'] );
		$this->assertSame( 'abgeschlossen', Marketing::next_status( 'aktiv' )['value'] );
		$this->assertSame( 'aktiv', Marketing::next_status( 'abgeschlossen' )['value'] );
		$this->assertNull( Marketing::next_status( 'x' ) );
	}

	private function hints( array $over ) {
		return Marketing::status_hints( array_merge( array(
			'status' => 'aktiv', 'typ' => 'sonstiges', 'start_datum' => '2026-10-01', 'ende_datum' => '', 'event_datum' => '',
			'today' => '2026-10-22', 'cost_count' => 1, 'budget_ampel' => 'im_rahmen', 'km_booked' => false,
			'has_attribution' => true, 'market_days' => 0, 'revenue' => 10.0,
		), $over ) );
	}

	public function test_hinweise_ruhige_kampagne_hat_keine() {
		$this->assertSame( array(), $this->hints( array() ) );
	}

	public function test_hinweis_fehlende_belege_nach_ende_ist_warnung() {
		$h = $this->hints( array( 'cost_count' => 0, 'event_datum' => '2026-10-10', 'typ' => 'event' ) );
		$this->assertSame( 'warn', $h[0]['level'] );
		$this->assertStringContainsString( 'kein Beleg', $h[0]['text'] );
		// Fahrt fehlt bei Events nach dem Termin, aber nur als Hinweis.
		$this->assertContains( 'info', array_column( $h, 'level' ) );
		// Läuft noch: nur Info.
		$this->assertSame( array( 'info' ), array_column( $this->hints( array( 'cost_count' => 0 ) ), 'level' ) );
	}

	public function test_hinweis_budget_ueberschritten_und_knapp() {
		$this->assertSame( 'warn', $this->hints( array( 'budget_ampel' => 'ueberzogen' ) )[0]['level'] );
		$this->assertSame( 'info', $this->hints( array( 'budget_ampel' => 'knapp' ) )[0]['level'] );
	}

	public function test_hinweis_beginn_erreicht_und_vorbei() {
		$h     = $this->hints( array( 'status' => 'geplant', 'start_datum' => '2026-10-22', 'budget_ampel' => 'kein_budget' ) );
		$texts = implode( ' ', array_column( $h, 'text' ) );
		$this->assertStringContainsString( 'aktiv', $texts );
		$this->assertStringContainsString( 'Budget', $texts );

		$h = $this->hints( array( 'ende_datum' => '2026-10-15' ) );
		$this->assertStringContainsString( 'vorbei', $h[0]['text'] );
		$this->assertSame( array(), $this->hints( array( 'status' => 'abgeschlossen', 'ende_datum' => '2026-10-15' ) ) );
	}

	public function test_hinweis_kein_umsatz_zuordenbar() {
		$h = $this->hints( array( 'has_attribution' => false, 'revenue' => 0.0 ) );
		$this->assertStringContainsString( 'kein Umsatz zuordenbar', $h[0]['text'] );
		$this->assertSame( array(), $this->hints( array( 'has_attribution' => false, 'revenue' => 0.0, 'market_days' => 1 ) ) );
		$this->assertSame( array(), $this->hints( array( 'status' => 'geplant', 'has_attribution' => false, 'revenue' => 0.0, 'start_datum' => '2026-11-01' ) ) );
	}

	public function test_kopie_uebernimmt_rahmen_aber_keine_zuordnungsmerkmale() {
		$f = Marketing::duplicate_fields( array(
			'name' => 'Weihnachtsmarkt', 'typ' => 'event', 'kanal' => 'Markt', 'budget_geplant' => '120.50', 'notiz' => 'Stand B',
			'utm_campaign' => 'wm25', 'gutschein_code' => 'WM25', 'event_datum' => '2025-12-06', 'status' => 'abgeschlossen', 'gefahrene_km' => 40,
		), '2026-10-22' );
		$this->assertSame( 'Kopie von Weihnachtsmarkt', $f['name'] );
		$this->assertSame( 'event', $f['typ'] );
		$this->assertSame( 120.5, $f['budget_geplant'] );
		$this->assertSame( 'Stand B', $f['notiz'] );
		$this->assertSame( '', $f['utm_campaign'] );
		$this->assertSame( '', $f['gutschein_code'] );
		$this->assertNull( $f['event_datum'] );
		$this->assertNull( $f['ende_datum'] );
		$this->assertSame( '2026-10-22', $f['start_datum'] );
		$this->assertSame( 'geplant', $f['status'] );
		$this->assertArrayNotHasKey( 'gefahrene_km', $f );
		$this->assertArrayNotHasKey( 'km_beleg_id', $f );
		$this->assertSame( 'Neuer Markt', Marketing::duplicate_fields( array( 'name' => 'x' ), '2026-10-22', ' Neuer Markt ' )['name'] );
	}

	public function test_vorlagen_sind_gueltig() {
		$keys = array();
		foreach ( Marketing::templates() as $t ) {
			$keys[] = $t['key'];
			$this->assertContains( $t['fields']['typ'], \Lindenweg16\Modules\Marketing\KampagnenRepository::TYPEN );
			$this->assertNotSame( '', $t['label'] );
		}
		$this->assertSame( count( $keys ), count( array_unique( $keys ) ) );
	}

	public function test_erklaerungen_nennen_herkunft_und_grenzen() {
		$e = Marketing::explain( array( 'nachlauf' => 30, 'von' => '2026-04-01', 'bis' => '2026-05-12', 'utm' => '', 'code' => '', 'km_booked' => false, 'market_days' => 0 ) );
		foreach ( array( 'kosten', 'fahrt', 'markt', 'shop', 'rabatt', 'einsatz', 'umsatz', 'ergebnis', 'roas', 'cpo', 'budget', 'offen' ) as $k ) {
			$this->assertArrayHasKey( $k, $e );
			$this->assertNotSame( '', $e[ $k ] );
		}
		$this->assertStringContainsString( 'bewusst nichts geschätzt', $e['shop'] );
		$this->assertStringContainsString( 'Kassenabschluss', $e['markt'] );

		$e = Marketing::explain( array( 'nachlauf' => 14, 'von' => '2026-04-01', 'bis' => '2026-05-12', 'utm' => 'fruehling', 'code' => 'FR26', 'km_rate' => 0.3, 'km_booked' => true, 'market_days' => 2 ) );
		$this->assertStringContainsString( 'Kampagnenkennung', $e['shop'] );
		$this->assertStringContainsString( 'Gutscheincode', $e['shop'] );
		$this->assertStringContainsString( '14 Tagen', $e['shop'] );
		$this->assertStringContainsString( '0,30 €', $e['fahrt'] );
		$this->assertStringContainsString( 'abgeschlossenen Kassentage', $e['markt'] );
	}

	public function test_einnahmekonto_zaehlt_nicht_als_kosten() {
		$this->assertTrue( Marketing::konto_zaehlt_als_kosten( '4600' ) );
		$this->assertTrue( Marketing::konto_zaehlt_als_kosten( '' ) );
		$this->assertFalse( Marketing::konto_zaehlt_als_kosten( \Lindenweg16\Modules\Accounting\Kontenrahmen::erloes_konto( true ) ) );
	}

	public function test_ampel_beschriftung() {
		foreach ( array( 'im_rahmen', 'knapp', 'ueberzogen', 'kein_budget' ) as $a ) {
			$this->assertNotSame( $a, Marketing::ampel_label( $a ) );
		}
	}
}
