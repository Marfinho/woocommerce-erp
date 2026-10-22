<?php
/**
 * Buchhaltung im ERP: AfA-Vorschau, Checklisten-Ampel, Zeiträume, Vorjahresvergleich
 * und Rechte der Routen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\BuchAbschluss;
use PHPUnit\Framework\TestCase;

class ErpBuchAbschlussTest extends TestCase {

	/**
	 * Anlage als Zeile.
	 *
	 * @param array $over Abweichungen.
	 * @return array
	 */
	private function anlage( array $over = array() ) {
		return array_merge(
			array(
				'id'                  => 1,
				'anlagennummer'       => 'AN-2025-0001',
				'bezeichnung'         => 'Drucker',
				'anschaffungsdatum'   => '2025-03-10',
				'anschaffungskosten'  => 900.0,
				'nutzungsdauer_jahre' => 3,
				'buchwert'            => 900.0,
				'ausgeschieden'       => 0,
			),
			$over
		);
	}

	/* ---------- AfA ---------- */

	public function test_afa_betrag_ist_jahresrate_aber_nie_mehr_als_buchwert() {
		$this->assertSame( 300.0, BuchAbschluss::afa_amount( 900, 3, 900 ) );
		$this->assertSame( 33.33, BuchAbschluss::afa_amount( 100, 3, 100 ) );
		$this->assertSame( 20.0, BuchAbschluss::afa_amount( 900, 3, 20 ) );
		$this->assertSame( 900.0, BuchAbschluss::afa_amount( 900, 0, 900 ) );
	}

	public function test_vorschau_bucht_im_afa_zeitraum() {
		$v = BuchAbschluss::afa_preview( array( $this->anlage() ), 2026, array() );

		$this->assertSame( 'buchen', $v['zeilen'][0]['status'] );
		$this->assertSame( 300.0, $v['zeilen'][0]['betrag'] );
		$this->assertSame( 600.0, $v['zeilen'][0]['buchwert_neu'] );
		$this->assertSame( 300.0, $v['summe'] );
		$this->assertSame( 1, $v['offen'] );
	}

	public function test_vorschau_ausserhalb_des_zeitraums() {
		$davor  = BuchAbschluss::afa_preview( array( $this->anlage() ), 2024, array() );
		$danach = BuchAbschluss::afa_preview( array( $this->anlage() ), 2028, array() );

		$this->assertSame( 'ausserhalb', $davor['zeilen'][0]['status'] );
		$this->assertSame( 'ausserhalb', $danach['zeilen'][0]['status'] );
		$this->assertSame( 0.0, $davor['summe'] );
		$this->assertStringContainsString( '2025 bis 2027', $danach['zeilen'][0]['hinweis'] );
	}

	public function test_vorschau_schon_gebucht_wird_nicht_nochmal_gebucht() {
		$v = BuchAbschluss::afa_preview( array( $this->anlage( array( 'buchwert' => 600.0 ) ) ), 2025, array( 1 ) );

		$this->assertSame( 'gebucht', $v['zeilen'][0]['status'] );
		$this->assertSame( 0, $v['offen'] );
		$this->assertSame( 1, $v['gebucht'] );
		$this->assertSame( 0.0, $v['summe'] );
	}

	public function test_vorschau_ausgeschieden_und_abgeschrieben() {
		$v = BuchAbschluss::afa_preview(
			array(
				$this->anlage( array( 'id' => 1, 'ausgeschieden' => 1 ) ),
				$this->anlage( array( 'id' => 2, 'buchwert' => 0.0 ) ),
			),
			2026,
			array()
		);

		$this->assertSame( 'ausgeschieden', $v['zeilen'][0]['status'] );
		$this->assertSame( 'abgeschrieben', $v['zeilen'][1]['status'] );
		$this->assertSame( 0, $v['offen'] );
	}

	public function test_vorschau_summiert_mehrere_anlagen() {
		$v = BuchAbschluss::afa_preview(
			array(
				$this->anlage( array( 'id' => 1 ) ),
				$this->anlage( array( 'id' => 2, 'anschaffungskosten' => 100.0, 'buchwert' => 100.0, 'nutzungsdauer_jahre' => 3 ) ),
			),
			2026,
			array()
		);

		$this->assertSame( 333.33, $v['summe'] );
		$this->assertSame( 2, $v['offen'] );
	}

	public function test_gebuchte_anlagen_werden_am_vermerk_erkannt() {
		$a    = array( $this->anlage( array( 'id' => 7, 'anlagennummer' => 'AN-2025-0007' ) ), $this->anlage( array( 'id' => 8, 'anlagennummer' => 'AN-2025-0008' ) ) );
		$note = array( BuchAbschluss::afa_note( 'AN-2025-0007', 2026 ), BuchAbschluss::afa_note( 'AN-2025-0008', 2025 ) );

		$this->assertSame( array( 7 ), BuchAbschluss::booked_ids( $a, $note, 2026 ) );
		$this->assertSame( 'Lineare AfA AN-2025-0007, Geschäftsjahr 2026', BuchAbschluss::afa_note( 'AN-2025-0007', 2026 ) );
	}

	public function test_afa_nur_bis_zum_laufenden_jahr() {
		$this->assertSame( '', BuchAbschluss::afa_year_error( 2026, 2026 ) );
		$this->assertSame( '', BuchAbschluss::afa_year_error( 2020, 2026 ) );
		$this->assertStringContainsString( 'zwischen 2020 und 2099', BuchAbschluss::afa_year_error( 2019, 2026 ) );
		$this->assertStringContainsString( 'noch nicht begonnen', BuchAbschluss::afa_year_error( 2027, 2026 ) );
	}

	/* ---------- Eingaben ---------- */

	public function test_betrag_deutsch_und_englisch_lesen() {
		$this->assertSame( 1234.5, BuchAbschluss::parse_money( '1.234,50' ) );
		$this->assertSame( 12.5, BuchAbschluss::parse_money( '12,5 €' ) );
		$this->assertSame( 12.5, BuchAbschluss::parse_money( '12.5' ) );
		$this->assertSame( 99.0, BuchAbschluss::parse_money( 99 ) );
		$this->assertNull( BuchAbschluss::parse_money( 'abc' ) );
		$this->assertNull( BuchAbschluss::parse_money( '' ) );
		$this->assertNull( BuchAbschluss::parse_money( array( 1 ) ) );
	}

	public function test_neue_anlage_wird_geprueft() {
		$ok = BuchAbschluss::clean_anlage(
			array( 'bezeichnung' => ' <b>3D-Drucker</b> ', 'anschaffungsdatum' => '2026-02-01', 'anschaffungskosten' => '1.299,00', 'nutzungsdauer_jahre' => '5', 'kategorie' => 'Maschinen' ),
			2026
		);

		$this->assertSame( '', $ok['error'] );
		$this->assertSame( '3D-Drucker', $ok['data']['bezeichnung'] );
		$this->assertSame( 1299.0, $ok['data']['anschaffungskosten'] );
		$this->assertSame( 5, $ok['data']['nutzungsdauer_jahre'] );

		$base = array( 'bezeichnung' => 'X', 'anschaffungsdatum' => '2026-02-01', 'anschaffungskosten' => '10', 'nutzungsdauer_jahre' => 3, 'kategorie' => 'Maschinen' );

		$this->assertStringContainsString( 'Bezeichnung', BuchAbschluss::clean_anlage( array_merge( $base, array( 'bezeichnung' => ' ' ) ), 2026 )['error'] );
		$this->assertStringContainsString( 'Anschaffungsdatum', BuchAbschluss::clean_anlage( array_merge( $base, array( 'anschaffungsdatum' => '31.02.2026' ) ), 2026 )['error'] );
		$this->assertStringContainsString( 'Anschaffungskosten', BuchAbschluss::clean_anlage( array_merge( $base, array( 'anschaffungskosten' => '0' ) ), 2026 )['error'] );
		$this->assertStringContainsString( 'Nutzungsdauer', BuchAbschluss::clean_anlage( array_merge( $base, array( 'nutzungsdauer_jahre' => 51 ) ), 2026 )['error'] );
		$this->assertStringContainsString( 'Anschaffungsdatum', BuchAbschluss::clean_anlage( array_merge( $base, array( 'anschaffungsdatum' => '2031-01-01' ) ), 2026 )['error'] );
		$this->assertSame( 'Sonstiges', BuchAbschluss::clean_anlage( array_merge( $base, array( 'kategorie' => 'Quatsch' ) ), 2026 )['data']['kategorie'] );
	}

	public function test_ausscheiden_braucht_grund_und_sinnvolles_datum() {
		$this->assertSame( '', BuchAbschluss::clean_ausscheiden( 'verkauft', '', '2025-01-01', '2026-05-05' )['error'] );
		$this->assertSame( '2026-05-05', BuchAbschluss::clean_ausscheiden( 'verkauft', '', '2025-01-01', '2026-05-05' )['datum'] );
		$this->assertStringContainsString( 'Grund', BuchAbschluss::clean_ausscheiden( ' ', '', '2025-01-01', '2026-05-05' )['error'] );
		$this->assertStringContainsString( 'Zukunft', BuchAbschluss::clean_ausscheiden( 'defekt', '2026-06-01', '2025-01-01', '2026-05-05' )['error'] );
		$this->assertStringContainsString( 'vor der Anschaffung', BuchAbschluss::clean_ausscheiden( 'defekt', '2024-06-01', '2025-01-01', '2026-05-05' )['error'] );
		$this->assertStringContainsString( 'gültiges Datum', BuchAbschluss::clean_ausscheiden( 'defekt', 'gestern', '2025-01-01', '2026-05-05' )['error'] );
	}

	/* ---------- Zeiträume ---------- */

	public function test_zeitraum_jahr_quartal_monat() {
		$j = BuchAbschluss::resolve_period( 'jahr', 2026, 1, 1 );
		$this->assertSame( array( '2026-01-01', '2026-12-31', '2026' ), array( $j['von'], $j['bis'], $j['label'] ) );

		$q = BuchAbschluss::resolve_period( 'quartal', 2026, 2, 1 );
		$this->assertSame( array( '2026-04-01', '2026-06-30' ), array( $q['von'], $q['bis'] ) );
		$this->assertSame( '2. Quartal 2026', $q['label'] );

		$q4 = BuchAbschluss::resolve_period( 'quartal', 2026, 4, 1 );
		$this->assertSame( array( '2026-10-01', '2026-12-31' ), array( $q4['von'], $q4['bis'] ) );

		$m = BuchAbschluss::resolve_period( 'monat', 2024, 1, 2 );
		$this->assertSame( array( '2024-02-01', '2024-02-29', 'Februar 2024' ), array( $m['von'], $m['bis'], $m['label'] ) );
		$this->assertSame( '2025-02-28', BuchAbschluss::resolve_period( 'monat', 2025, 1, 2 )['bis'] );
	}

	public function test_zeitraum_frei_und_fehler() {
		$f = BuchAbschluss::resolve_period( 'frei', 2026, 1, 1, '2026-03-05', '2026-04-10' );
		$this->assertSame( '', $f['error'] );
		$this->assertSame( '05.03.2026 bis 10.04.2026', $f['label'] );

		$this->assertStringContainsString( 'Startdatum', BuchAbschluss::resolve_period( 'frei', 2026, 1, 1, '2026-05-01', '2026-04-10' )['error'] );
		$this->assertStringContainsString( 'als Datum', BuchAbschluss::resolve_period( 'frei', 2026, 1, 1, '', '2026-04-10' )['error'] );
		$this->assertStringContainsString( 'Quartal', BuchAbschluss::resolve_period( 'quartal', 2026, 5, 1 )['error'] );
		$this->assertStringContainsString( 'Monat', BuchAbschluss::resolve_period( 'monat', 2026, 1, 13 )['error'] );
		$this->assertStringContainsString( 'Jahr', BuchAbschluss::resolve_period( 'jahr', 1800, 1, 1 )['error'] );
		// Unbekannter Modus fällt auf das Jahr zurück.
		$this->assertSame( 'jahr', BuchAbschluss::resolve_period( 'quatsch', 2026, 1, 1 )['modus'] );
	}

	public function test_vorjahreszeitraum() {
		$q  = BuchAbschluss::resolve_period( 'quartal', 2026, 1, 1 );
		$pq = BuchAbschluss::previous_period( $q, 1, 1, 2026 );
		$this->assertSame( array( '2025-01-01', '2025-03-31' ), array( $pq['von'], $pq['bis'] ) );

		// Februar 2025 (28 Tage) gegen Februar 2024 (29 Tage): volle Vergleichsmonate.
		$m  = BuchAbschluss::resolve_period( 'monat', 2025, 1, 2 );
		$pm = BuchAbschluss::previous_period( $m, 1, 2, 2025 );
		$this->assertSame( '2024-02-29', $pm['bis'] );

		$frei = BuchAbschluss::resolve_period( 'frei', 2026, 1, 1, '2024-02-29', '2026-03-31' );
		$pf   = BuchAbschluss::previous_period( $frei, 1, 1, 2026 );
		$this->assertSame( array( '2023-02-28', '2025-03-31' ), array( $pf['von'], $pf['bis'] ) );

		$kaputt = BuchAbschluss::resolve_period( 'monat', 2026, 1, 99 );
		$this->assertNull( BuchAbschluss::previous_period( $kaputt, 1, 99, 2026 ) );
	}

	/* ---------- Prozent und Vergleich ---------- */

	public function test_prozentabweichung() {
		$this->assertSame( 25.0, BuchAbschluss::percent_change( 125, 100 ) );
		$this->assertSame( -50.0, BuchAbschluss::percent_change( 50, 100 ) );
		$this->assertSame( 12.3, BuchAbschluss::percent_change( 112.34, 100 ) );
		$this->assertNull( BuchAbschluss::percent_change( 10, 0 ) );
		$this->assertSame( 0.0, BuchAbschluss::percent_change( 0, 0 ) );
		// Negativer Vorjahreswert: Verbesserung bleibt positiv.
		$this->assertSame( 150.0, BuchAbschluss::percent_change( 50, -100 ) );
	}

	public function test_vorjahresvergleich_eckwerte() {
		$c = BuchAbschluss::year_compare(
			array( 'einnahmen' => 1200.0, 'ausgaben_summe' => 700.0, 'gewinn' => 500.0 ),
			array( 'einnahmen' => 1000.0, 'ausgaben_summe' => 800.0, 'gewinn' => 200.0 )
		);

		$this->assertSame( 200.0, $c['einnahmen']['diff'] );
		$this->assertSame( 20.0, $c['einnahmen']['prozent'] );
		$this->assertSame( -100.0, $c['ausgaben']['diff'] );
		$this->assertSame( -12.5, $c['ausgaben']['prozent'] );
		$this->assertSame( 150.0, $c['gewinn']['prozent'] );

		$leer = BuchAbschluss::year_compare( array( 'einnahmen' => 10.0 ), array() );
		$this->assertNull( $leer['einnahmen']['prozent'] );
		$this->assertSame( 0.0, $leer['gewinn']['aktuell'] );
	}

	public function test_gruppen_je_kontengruppe_und_vergleich() {
		$konten = array(
			'4210' => array( 'brutto' => 100.0, 'abziehbar' => 100.0 ),
			'4240' => array( 'brutto' => 50.0, 'abziehbar' => 50.0 ),
			'4650' => array( 'brutto' => 100.0, 'abziehbar' => 70.0 ),
		);
		$g = BuchAbschluss::groups_from_accounts( $konten );

		$this->assertSame( 'Raumkosten', $g[0]['name'] );
		$this->assertSame( 150.0, $g[0]['summe'] );
		$this->assertCount( 2, $g[0]['konten'] );
		$this->assertSame( 'Werbe- und Reisekosten', $g[1]['name'] );
		$this->assertSame( 70.0, $g[1]['summe'] );
		$this->assertSame( 100.0, $g[1]['konten'][0]['brutto'] );

		$vorher = array(
			array( 'name' => 'Raumkosten', 'summe' => 100.0, 'konten' => array() ),
			array( 'name' => 'Versicherungen und Beiträge', 'summe' => 40.0, 'konten' => array() ),
		);
		$c = BuchAbschluss::groups_compare( $g, $vorher );

		$this->assertSame( 50.0, $c[0]['vergleich']['prozent'] );
		$this->assertNull( $c[1]['vergleich']['prozent'] );
		$this->assertSame( 'Versicherungen und Beiträge', $c[2]['name'] );
		$this->assertSame( 0.0, $c[2]['summe'] );
		$this->assertSame( -40.0, $c[2]['vergleich']['diff'] );
		$this->assertSame( $g, BuchAbschluss::groups_compare( $g, null ) );
	}

	public function test_konto_eines_belegs_wie_in_der_eur() {
		$this->assertSame( '4210', BuchAbschluss::beleg_konto( '4210', 'egal' ) );
		$this->assertSame( '4830', BuchAbschluss::beleg_konto( '', 'AfA' ) );
		$this->assertSame( \Lindenweg16\Modules\Accounting\Kontenrahmen::KONTO_SONSTIGES, BuchAbschluss::beleg_konto( '', 'unbekannt' ) );
	}

	/* ---------- Ampel der Checkliste ---------- */

	public function test_ampel_offene_belege() {
		$this->assertSame( 'gruen', BuchAbschluss::lamp_open_receipts( 0 )['status'] );
		$rot = BuchAbschluss::lamp_open_receipts( 3 );
		$this->assertSame( 'rot', $rot['status'] );
		$this->assertStringContainsString( '3 Belege sind', $rot['text'] );
		$this->assertStringContainsString( '1 Beleg ist', BuchAbschluss::lamp_open_receipts( 1 )['text'] );
	}

	public function test_ampel_dauerbuchungen_und_afa() {
		$this->assertSame( 'gruen', BuchAbschluss::lamp_recurring( 0 )['status'] );
		$this->assertSame( 'gelb', BuchAbschluss::lamp_recurring( 2 )['status'] );
		$this->assertSame( 'rot', BuchAbschluss::lamp_afa( 1, 0 )['status'] );
		$this->assertSame( 'rot', BuchAbschluss::lamp_afa( 1, 4 )['status'] );
		$this->assertSame( 'gruen', BuchAbschluss::lamp_afa( 0, 2 )['status'] );
		$this->assertStringContainsString( 'keine Anlage', BuchAbschluss::lamp_afa( 0, 0 )['text'] );
	}

	public function test_ampel_kassenbuch() {
		$this->assertSame( 'gruen', BuchAbschluss::lamp_cash( 0, 0, null )['status'] );
		$this->assertSame( 'rot', BuchAbschluss::lamp_cash( 5, -1.0, null )['status'] );
		$this->assertSame( 'gelb', BuchAbschluss::lamp_cash( 5, 100.0, null )['status'] );

		$ok = array( 'buchsaldo' => 100.0, 'gezaehlt' => 100.0, 'differenz' => 0.0 );
		$this->assertSame( 'gruen', BuchAbschluss::lamp_cash( 5, 100.0, $ok )['status'] );

		// Seit dem Kassensturz hat sich der Buchbestand geändert.
		$this->assertSame( 'gelb', BuchAbschluss::lamp_cash( 6, 120.0, $ok )['status'] );

		$diff = array( 'buchsaldo' => 100.0, 'gezaehlt' => 90.0, 'differenz' => -10.0 );
		$rot  = BuchAbschluss::lamp_cash( 5, 100.0, $diff );
		$this->assertSame( 'rot', $rot['status'] );
		$this->assertStringContainsString( '-10,00 €', $rot['text'] );
	}

	public function test_ampel_gobd() {
		$this->assertSame( 'rot', BuchAbschluss::lamp_gobd( null, false )['status'] );

		$ok = array( 'datum' => '2026-01-10 10:00:00', 'ergebnis' => 'ok', 'fehler' => 0, 'warnungen' => 0 );
		$this->assertSame( 'gruen', BuchAbschluss::lamp_gobd( $ok, false )['status'] );
		$this->assertSame( 'gelb', BuchAbschluss::lamp_gobd( $ok, true )['status'] );

		$warn = array( 'datum' => 'x', 'ergebnis' => 'warnung', 'fehler' => 0, 'warnungen' => 2 );
		$this->assertSame( 'gelb', BuchAbschluss::lamp_gobd( $warn, false )['status'] );

		$fehler = array( 'datum' => 'x', 'ergebnis' => 'fehler', 'fehler' => 1, 'warnungen' => 0 );
		$this->assertSame( 'rot', BuchAbschluss::lamp_gobd( $fehler, false )['status'] );
		$this->assertSame( 'rot', BuchAbschluss::lamp_gobd( $fehler, true )['status'] );
	}

	public function test_ampel_gebuehren() {
		$this->assertSame( 'grau', BuchAbschluss::lamp_fees( null, true )['status'] );
		$this->assertSame( 'gruen', BuchAbschluss::lamp_fees( 0, true )['status'] );
		$this->assertSame( 'gelb', BuchAbschluss::lamp_fees( 0, true, true )['status'] );

		$f = BuchAbschluss::lamp_fees( 2, true );
		$this->assertSame( 'gelb', $f['status'] );
		$this->assertStringContainsString( '2 Bestellungen', $f['text'] );
		$this->assertStringContainsString( 'ausgeschaltet', BuchAbschluss::lamp_fees( 2, false )['text'] );
	}

	public function test_export_abdeckung_und_ampel() {
		$ganz = array( array( 'von' => '2026-01-01', 'bis' => '2026-12-31' ) );
		$teil = array( array( 'von' => '2026-01-01', 'bis' => '2026-06-30' ) );
		$alt  = array( array( 'von' => '2025-01-01', 'bis' => '2025-12-31' ) );

		$this->assertSame( 'voll', BuchAbschluss::export_coverage( $ganz, 2026 ) );
		$this->assertSame( 'voll', BuchAbschluss::export_coverage( array( array( 'von' => '2025-01-01', 'bis' => '2027-12-31' ) ), 2026 ) );
		$this->assertSame( 'teil', BuchAbschluss::export_coverage( $teil, 2026 ) );
		$this->assertSame( 'kein', BuchAbschluss::export_coverage( $alt, 2026 ) );
		$this->assertSame( 'kein', BuchAbschluss::export_coverage( array(), 2026 ) );
		$this->assertSame( 'voll', BuchAbschluss::export_coverage( array_merge( $teil, $ganz ), 2026 ) );

		$this->assertSame( 'gruen', BuchAbschluss::lamp_export( 'voll' )['status'] );
		$this->assertSame( 'gelb', BuchAbschluss::lamp_export( 'teil' )['status'] );
		$this->assertSame( 'gelb', BuchAbschluss::lamp_export( 'kein' )['status'] );
	}

	public function test_gesamtampel_ist_der_schlechteste_wert() {
		$this->assertSame( 'gruen', BuchAbschluss::lamp_overall( array( 'gruen', 'gruen' ) ) );
		$this->assertSame( 'grau', BuchAbschluss::lamp_overall( array( 'gruen', 'grau' ) ) );
		$this->assertSame( 'gelb', BuchAbschluss::lamp_overall( array( 'gruen', 'gelb', 'grau' ) ) );
		$this->assertSame( 'rot', BuchAbschluss::lamp_overall( array( 'gelb', 'rot', 'gruen' ) ) );
		$this->assertSame( 'gruen', BuchAbschluss::lamp_overall( array() ) );
	}

	/* ---------- GoBD-Texte ---------- */

	public function test_gobd_punkte_verstaendlich() {
		$ok = BuchAbschluss::gobd_point( array( 'name' => 'Hash-Kette: Belege', 'status' => 'ok', 'details' => '12 Datensätze geprüft, Kette intakt' ) );
		$this->assertSame( 'gruen', $ok['status'] );
		$this->assertSame( 'Unveränderbarkeit: Belege', $ok['titel'] );
		$this->assertSame( '12 Datensätze geprüft, Kette intakt', $ok['technisch'] );

		$bad = BuchAbschluss::gobd_point( array( 'name' => 'Belegnummern-Lückenlosigkeit (2026)', 'status' => 'fehler', 'details' => 'Fehlende Nummern: 4' ) );
		$this->assertSame( 'rot', $bad['status'] );
		$this->assertStringContainsString( 'lückenlose Nummern', $bad['titel'] );
		$this->assertStringContainsString( 'nie vergeben', $bad['erklaerung'] );

		$skip = BuchAbschluss::gobd_point( array( 'name' => 'Hash-Kette: Kassenbuch', 'status' => 'uebersprungen', 'details' => '' ) );
		$this->assertSame( 'gelb', $skip['status'] );

		$late = BuchAbschluss::gobd_point( array( 'name' => 'Zeitgerechte Erfassung (Belege)', 'status' => 'warnung', 'details' => '3 Belege' ) );
		$this->assertSame( 'gelb', $late['status'] );
		$this->assertStringContainsString( '10 Tage', $late['erklaerung'] );

		$this->assertCount( 2, BuchAbschluss::gobd_points( array( array( 'name' => 'a', 'status' => 'ok' ), 'kaputt', array( 'name' => 'b', 'status' => 'fehler' ) ) ) );
	}

	/* ---------- Quelltext-Wächter ---------- */

	/**
	 * Quelltext der Seite.
	 *
	 * @return string
	 */
	private function source() {
		return (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Pages/BuchAbschluss.php' );
	}

	public function test_jede_route_hat_ein_recht_und_exporte_zusaetzlich_das_exportrecht() {
		$src = $this->source();

		$this->assertMatchesRegularExpression( "/\\\$buch\\s*=\\s*\\\$api->needs\\(\\s*array\\(\\s*'finanzen\\.buchhaltung'\\s*\\)\\s*\\)/", $src );
		$this->assertMatchesRegularExpression( "/\\\$export\\s*=\\s*\\\$api->needs\\(\\s*array\\(\\s*'finanzen\\.buchhaltung'\\s*,\\s*'finanzen\\.export'\\s*\\)\\s*\\)/", $src );

		preg_match_all( "/self::(get|post)\\(\\s*\\\$ns,\\s*'([^']+)',\\s*'([^']+)',\\s*(\\\$\\w+)\\s*\\)/", $src, $m, PREG_SET_ORDER );

		$this->assertGreaterThanOrEqual( 14, count( $m ), 'Alle Routen müssen über self::get/self::post angemeldet sein.' );

		foreach ( $m as $r ) {
			$this->assertStringStartsWith( '/erp/buchabschluss/', $r[2] );
			$this->assertContains( $r[4], array( '$buch', '$export' ), 'Route ' . $r[2] . ' ohne Rechteprüfung.' );

			if ( false !== strpos( $r[2], 'export' ) || false !== strpos( $r[2], 'csv' ) ) {
				$this->assertSame( '$export', $r[4], 'Route ' . $r[2] . ' liefert Exportdaten und braucht das Exportrecht.' );
			}
		}

		// Alle register_rest_route-Aufrufe laufen über die zwei Helfer mit permission_callback.
		$this->assertSame( 2, substr_count( $src, 'register_rest_route(' ) );
		$this->assertSame( 2, substr_count( $src, "'permission_callback' => \$perm" ) );
		$this->assertStringNotContainsString( '__return_true', $src );
	}

	public function test_schreibende_routen_sind_post_und_lesende_get() {
		$src = $this->source();

		foreach ( array( 'anlagen', 'afa-buchen', 'anlagen-ausscheiden', 'dauerbuchungen-ausfuehren', 'kassenbuch-bestaetigen', 'gebuehren-pruefen' ) as $route ) {
			$this->assertMatchesRegularExpression( "#self::post\\(\\s*\\\$ns,\\s*'/erp/buchabschluss/" . preg_quote( $route, '#' ) . "'#", $src );
		}

		foreach ( array( 'afa-vorschau', 'euer', 'euer-buchungen', 'euer-csv', 'export', 'abschluss' ) as $route ) {
			$this->assertMatchesRegularExpression( "#self::get\\(\\s*\\\$ns,\\s*'/erp/buchabschluss/" . preg_quote( $route, '#' ) . "'#", $src );
		}
	}

	public function test_gobd_regeln_bleiben_in_den_repositories() {
		$src = $this->source();

		// Belege entstehen nur über das Repository und werden festgeschrieben, nie direkt per SQL angelegt oder gelöscht.
		$this->assertStringContainsString( 'BelegRepository::create(', $src );
		$this->assertStringContainsString( 'BelegRepository::festschreiben(', $src );
		$this->assertStringContainsString( 'AnlagenRepository::create(', $src );
		$this->assertStringContainsString( 'AnlagenRepository::ausscheiden(', $src );
		$this->assertStringContainsString( 'GobdChecker::run(', $src );
		$this->assertStringContainsString( 'DauerbuchungRepository::execute_due(', $src );
		$this->assertStringContainsString( 'capture_order_fee(', $src );
		$this->assertStringContainsString( "'afa_gebucht'", $src );
		$this->assertDoesNotMatchRegularExpression( '/\$wpdb->(insert|delete)\(/', $src );
		$this->assertDoesNotMatchRegularExpression( '/DELETE\s+FROM/i', $src );
	}
}
