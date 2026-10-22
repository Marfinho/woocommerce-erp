<?php
/**
 * Belege im ERP: reine Regeln (Doppelbeleg, OCR-Felder, Status, Dateityp)
 * und Quelltext-Wächter für Rechte und Storno-Grund.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\BuchBelege;
use PHPUnit\Framework\TestCase;

class ErpBuchBelegeTest extends TestCase {

	/**
	 * Quelltext der Seitenklasse.
	 *
	 * @return string
	 */
	protected function source() {
		return (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Pages/BuchBelege.php' );
	}

	/* ---- Betrag und Eingaben ---- */

	public function test_betrag_deutsch_und_englisch() {
		$this->assertSame( '12.50', BuchBelege::normalize_amount( '12,50' ) );
		$this->assertSame( '1234.56', BuchBelege::normalize_amount( '1.234,56' ) );
		$this->assertSame( '1234.56', BuchBelege::normalize_amount( '1,234.56' ) );
		$this->assertSame( '12.50', BuchBelege::normalize_amount( '€ 12.5' ) );
		$this->assertSame( '1234.00', BuchBelege::normalize_amount( '1.234' ) );
		$this->assertSame( '7.00', BuchBelege::normalize_amount( 7 ) );
		$this->assertSame( '', BuchBelege::normalize_amount( 'abc' ) );
		$this->assertSame( '', BuchBelege::normalize_amount( '' ) );
	}

	public function test_eingaben_pruefen() {
		$ok = BuchBelege::validate_input(
			array( 'betrag' => '12,50', 'datum' => '2026-03-01', 'haendler' => '  Bauhaus <b>AG</b> ', 'konto' => '4930', 'zahlungsart' => 'bar', 'mwst_satz' => '19' ),
			'2026-10-22'
		);
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( '12.50', $ok['clean']['betrag'] );
		$this->assertSame( 'Bauhaus AG', $ok['clean']['haendler'] );
		$this->assertSame( '4930', $ok['clean']['konto'] );

		$schlecht = BuchBelege::validate_input( array( 'betrag' => '0', 'datum' => '2026-02-30', 'zahlungsart' => 'bitcoin', 'mwst_satz' => '5' ), '2026-10-22' );
		$this->assertFalse( $schlecht['ok'] );
		$this->assertArrayHasKey( 'betrag', $schlecht['errors'] );
		$this->assertArrayHasKey( 'datum', $schlecht['errors'] );
		$this->assertArrayHasKey( 'zahlungsart', $schlecht['errors'] );
		$this->assertArrayHasKey( 'mwst_satz', $schlecht['errors'] );
		foreach ( $schlecht['errors'] as $text ) {
			$this->assertDoesNotMatchRegularExpression( '/\b(error|code\s*\d+)\b/i', $text );
		}
	}

	public function test_zukunft_und_minus_werden_abgelehnt() {
		$z = BuchBelege::validate_input( array( 'betrag' => '5', 'datum' => '2026-12-01' ), '2026-10-22' );
		$this->assertStringContainsString( 'Zukunft', $z['errors']['datum'] );

		$m = BuchBelege::validate_input( array( 'betrag' => '-5', 'datum' => '2026-10-01' ), '2026-10-22' );
		$this->assertArrayHasKey( 'betrag', $m['errors'] );
	}

	public function test_unbekanntes_konto_wird_sonstiges() {
		$r = BuchBelege::validate_input( array( 'betrag' => '5', 'datum' => '2026-10-01', 'konto' => '9999' ), '2026-10-22' );
		$this->assertSame( \Lindenweg16\Modules\Accounting\Kontenrahmen::KONTO_SONSTIGES, $r['clean']['konto'] );
	}

	public function test_schalter() {
		$this->assertTrue( BuchBelege::flag( '1' ) );
		$this->assertTrue( BuchBelege::flag( true ) );
		$this->assertTrue( BuchBelege::flag( 'true' ) );
		$this->assertFalse( BuchBelege::flag( '0' ) );
		$this->assertFalse( BuchBelege::flag( 'false' ) );
		$this->assertFalse( BuchBelege::flag( null ) );
	}

	/* ---- Notiz mit Zusatzangaben ---- */

	public function test_notiz_rundreise() {
		$meta  = array( 'zahlungsart' => 'karte', 'externe_nr' => 'RE-2026-77', 'mwst_satz' => '19' );
		$notiz = BuchBelege::notiz_bauen( "Schrauben\nfür Regal", $meta );
		$this->assertSame( "Zahlungsart: Karte (EC/Kredit)\nBeleg-Nr. (extern): RE-2026-77\nMwSt: 19 %\n\nSchrauben\nfür Regal", $notiz );

		$z = BuchBelege::notiz_zerlegen( $notiz );
		$this->assertSame( 'karte', $z['zahlungsart'] );
		$this->assertSame( 'RE-2026-77', $z['externe_nr'] );
		$this->assertSame( '19', $z['mwst_satz'] );
		$this->assertSame( "Schrauben\nfür Regal", $z['frei'] );
	}

	public function test_notiz_ohne_zusatz_bleibt_unveraendert() {
		$this->assertSame( 'nur Text', BuchBelege::notiz_bauen( 'nur Text', array() ) );
		$z = BuchBelege::notiz_zerlegen( 'E-Rechnung 123 (zugferd)' );
		$this->assertSame( 'E-Rechnung 123 (zugferd)', $z['frei'] );
		$this->assertSame( '', $z['zahlungsart'] );
	}

	public function test_mwst_anteil() {
		$this->assertSame( 3.19, BuchBelege::mwst_anteil( '20.00', '19' ) );
		$this->assertNull( BuchBelege::mwst_anteil( '20.00', '' ) );
		$this->assertNull( BuchBelege::mwst_anteil( '0', '19' ) );
	}

	/* ---- Doppelbeleg ---- */

	public function test_haendler_aehnlichkeit() {
		$this->assertTrue( BuchBelege::haendler_aehnlich( 'Bauhaus AG', 'BAUHAUS' ) );
		$this->assertTrue( BuchBelege::haendler_aehnlich( 'Müller GmbH', 'Mueller' ) );
		$this->assertTrue( BuchBelege::haendler_aehnlich( 'Dm Drogerie Markt', 'dm drogerie' ) );
		$this->assertTrue( BuchBelege::haendler_aehnlich( '', 'Obi' ), 'ohne Namen gilt als möglich' );
		$this->assertFalse( BuchBelege::haendler_aehnlich( 'Bauhaus', 'Rewe' ) );
	}

	public function test_doppelbeleg_erkennung() {
		$vorhanden = array(
			array( 'id' => 1, 'betrag' => '12.50', 'datum' => '2026-03-01', 'haendler' => 'Bauhaus AG', 'storniert' => 0, 'quelle' => 'manuell' ),
			array( 'id' => 2, 'betrag' => '12.50', 'datum' => '2026-03-02', 'haendler' => 'Bauhaus AG', 'storniert' => 0, 'quelle' => 'manuell' ),
			array( 'id' => 3, 'betrag' => '12.51', 'datum' => '2026-03-01', 'haendler' => 'Bauhaus AG', 'storniert' => 0, 'quelle' => 'manuell' ),
			array( 'id' => 4, 'betrag' => '12.50', 'datum' => '2026-03-01', 'haendler' => 'Rewe', 'storniert' => 0, 'quelle' => 'manuell' ),
			array( 'id' => 5, 'betrag' => '12.50', 'datum' => '2026-03-01', 'haendler' => 'Bauhaus', 'storniert' => 1, 'quelle' => 'manuell' ),
			array( 'id' => 6, 'betrag' => '12.50', 'datum' => '2026-03-01', 'haendler' => 'Bauhaus', 'storniert' => 0, 'quelle' => 'storno' ),
		);

		$treffer = BuchBelege::doppelte_finden( array( 'betrag' => 12.5, 'datum' => '2026-03-01', 'haendler' => 'BAUHAUS' ), $vorhanden );
		$this->assertCount( 1, $treffer );
		$this->assertSame( 1, $treffer[0]['id'] );

		$this->assertSame( array(), BuchBelege::doppelte_finden( array( 'betrag' => 99, 'datum' => '2026-03-01', 'haendler' => 'Bauhaus' ), $vorhanden ) );
		$this->assertStringContainsString( 'doppelt', BuchBelege::doppelt_text( 1 ) );
		$this->assertStringContainsString( '2 Belege', BuchBelege::doppelt_text( 2 ) );
	}

	/* ---- OCR-Felder ---- */

	public function test_ocr_felder_aus_text() {
		$text = "Baumarkt Lindner GmbH\nHauptstr. 5\nRechnung Nr: RE-2026/0815\nDatum: 14.03.2026\nSchrauben 12,00 €\nSumme 1.234,56 €\ndarin MwSt 19 % 197,12 €\n";
		$r    = BuchBelege::ocr_felder( $text, '2026-10-22' );

		$this->assertSame( '1234.56', $r['felder']['betrag'] );
		$this->assertSame( '2026-03-14', $r['felder']['datum'] );
		$this->assertSame( 'Baumarkt Lindner GmbH', $r['felder']['haendler'] );
		$this->assertSame( 'RE-2026/0815', $r['felder']['externe_nr'] );
		$this->assertSame( '19', $r['felder']['mwst_satz'] );
		$this->assertContains( 'betrag', $r['gefunden'] );
	}

	public function test_ocr_rat_nicht() {
		$r = BuchBelege::ocr_felder( "xx\n..\n", '2026-10-22' );
		$this->assertSame( array(), $r['gefunden'] );
		$this->assertSame( '', $r['felder']['betrag'] );
		$this->assertSame( '', $r['felder']['datum'] );
	}

	public function test_ocr_datum_in_der_zukunft_wird_nicht_vorgeschlagen() {
		$r = BuchBelege::ocr_felder( "Laden\nSumme 5,00 €\n01.12.2026", '2026-10-22' );
		$this->assertSame( '', $r['felder']['datum'] );
		$this->assertSame( '5.00', $r['felder']['betrag'] );
	}

	public function test_mwst_satz_nur_wenn_eindeutig() {
		$this->assertSame( '7', BuchBelege::mwst_satz_finden( 'MwSt 7 % 0,45' ) );
		$this->assertSame( '', BuchBelege::mwst_satz_finden( "MwSt 7 % 0,45\nMwSt 19 % 3,00" ) );
		$this->assertSame( '', BuchBelege::mwst_satz_finden( 'Artikel 19 Stück' ) );
	}

	public function test_gebuehrenzeile_schlaegt_konto_vor() {
		$r = BuchBelege::ocr_felder( "PayPal\nGebühr 1,23 EUR\nGesamt 45,60 EUR\n", '2026-10-22' );
		$this->assertSame( '4970', $r['felder']['konto'] );
		$this->assertNotEmpty( $r['hinweise'] );
	}

	/* ---- Status-Übergänge ---- */

	public function test_status() {
		$this->assertSame( 'offen', BuchBelege::status( false, false ) );
		$this->assertSame( 'festgeschrieben', BuchBelege::status( false, true ) );
		$this->assertSame( 'storniert', BuchBelege::status( true, true ) );
		$this->assertSame( 'storniert', BuchBelege::status( true, false ) );
	}

	public function test_uebergaenge() {
		$this->assertSame( '', BuchBelege::uebergang_fehler( 'offen', 'manuell', 'aendern' ) );
		$this->assertSame( '', BuchBelege::uebergang_fehler( 'offen', 'manuell', 'festschreiben' ) );
		$this->assertSame( '', BuchBelege::uebergang_fehler( 'offen', 'manuell', 'stornieren' ) );

		// Nach dem Festschreiben: nichts ändern, nur Notiz, Storno erlaubt.
		$this->assertNotSame( '', BuchBelege::uebergang_fehler( 'festgeschrieben', 'manuell', 'aendern' ) );
		$this->assertNotSame( '', BuchBelege::uebergang_fehler( 'festgeschrieben', 'manuell', 'festschreiben' ) );
		$this->assertSame( '', BuchBelege::uebergang_fehler( 'festgeschrieben', 'manuell', 'notiz' ) );
		$this->assertSame( '', BuchBelege::uebergang_fehler( 'festgeschrieben', 'manuell', 'stornieren' ) );

		// Storniert ist endgültig; Gegenbelege lassen sich nicht stornieren.
		foreach ( array( 'aendern', 'festschreiben', 'stornieren', 'notiz' ) as $aktion ) {
			$this->assertNotSame( '', BuchBelege::uebergang_fehler( 'storniert', 'manuell', $aktion ), $aktion );
		}
		$this->assertNotSame( '', BuchBelege::uebergang_fehler( 'festgeschrieben', 'storno', 'stornieren' ) );
	}

	public function test_aenderbare_felder_je_status() {
		$offen = BuchBelege::aenderbare_felder( 'offen' );
		foreach ( array( 'betrag', 'datum', 'haendler', 'datei' ) as $gesperrt ) {
			$this->assertNotContains( $gesperrt, $offen, 'steht in der Hash-Kette' );
		}
		$this->assertContains( 'konto', $offen );
		$this->assertSame( array( 'notiz' ), BuchBelege::aenderbare_felder( 'festgeschrieben' ) );
		$this->assertSame( array(), BuchBelege::aenderbare_felder( 'storniert' ) );
	}

	public function test_storno_grund_ist_pflicht() {
		$this->assertNotSame( '', BuchBelege::storno_grund_error( '' ) );
		$this->assertNotSame( '', BuchBelege::storno_grund_error( "   \n " ) );
		$this->assertNotSame( '', BuchBelege::storno_grund_error( 'ok' ) );
		$this->assertNotSame( '', BuchBelege::storno_grund_error( null ) );
		$this->assertSame( '', BuchBelege::storno_grund_error( 'Falscher Betrag erfasst' ) );
		$this->assertSame( 'a b', BuchBelege::clean_grund( "  a \n b " ) );
		$this->assertSame( 500, mb_strlen( BuchBelege::clean_grund( str_repeat( 'x', 900 ) ) ) );
	}

	/* ---- Dateityp ---- */

	public function test_dateityp_nach_inhalt() {
		$this->assertSame( 'jpeg', BuchBelege::dateityp( "\xFF\xD8\xFF\xE0" . str_repeat( "\0", 12 ) )['art'] );
		$this->assertSame( 'png', BuchBelege::dateityp( "\x89PNG\r\n\x1A\n" . str_repeat( "\0", 8 ) )['art'] );
		$this->assertSame( 'pdf', BuchBelege::dateityp( '%PDF-1.7' . str_repeat( ' ', 8 ) )['art'] );
		$this->assertSame( 'webp', BuchBelege::dateityp( 'RIFF' . "\0\0\0\0" . 'WEBPVP8 ' )['art'] );
		$this->assertSame( 'xml', BuchBelege::dateityp( '<?xml version="1.0"?>' )['art'] );
		$this->assertSame( 'xml', BuchBelege::dateityp( "\xEF\xBB\xBF<rsm:CrossIndustryInvoice " )['art'] );
		$this->assertSame( 'heic', BuchBelege::dateityp( "\0\0\0\x18ftypheic\0\0\0\0" )['art'] );
		$this->assertNull( BuchBelege::dateityp( 'MZ' . str_repeat( "\0", 14 ) ) );
		$this->assertNull( BuchBelege::dateityp( '<?php echo 1; ?>' ) );
		$this->assertNull( BuchBelege::dateityp( '' ) );
	}

	public function test_dateityp_je_zweck() {
		$jpeg = BuchBelege::dateityp( "\xFF\xD8\xFF\xE0" );
		$pdf  = BuchBelege::dateityp( '%PDF-1.4' );
		$xml  = BuchBelege::dateityp( '<?xml ' );
		$heic = BuchBelege::dateityp( "\0\0\0\x18ftypheic" );

		$this->assertSame( '', BuchBelege::dateityp_fehler( $jpeg, 'beleg' ) );
		$this->assertSame( '', BuchBelege::dateityp_fehler( $pdf, 'beleg' ) );
		$this->assertSame( '', BuchBelege::dateityp_fehler( $jpeg, 'ocr' ) );
		$this->assertNotSame( '', BuchBelege::dateityp_fehler( $xml, 'beleg' ), 'XML ist kein Beleg-Foto' );
		$this->assertNotSame( '', BuchBelege::dateityp_fehler( $xml, 'ocr' ) );
		$this->assertSame( '', BuchBelege::dateityp_fehler( $xml, 'erechnung' ) );
		$this->assertSame( '', BuchBelege::dateityp_fehler( $pdf, 'erechnung' ) );
		$this->assertNotSame( '', BuchBelege::dateityp_fehler( $jpeg, 'erechnung' ) );
		$this->assertStringContainsString( 'HEIC', BuchBelege::dateityp_fehler( $heic, 'beleg' ) );
		$this->assertNotSame( '', BuchBelege::dateityp_fehler( null, 'beleg' ) );
	}

	public function test_dateiname_wie_klassisch() {
		$this->assertSame( 'beleg-20261022-AbC123xyz.jpg', BuchBelege::dateiname( '20261022', 'AbC123xyz', 'JPG' ) );
		$name = BuchBelege::dateiname( '2026-10-22', '../../etc', 'p/hp' );
		$this->assertDoesNotMatchRegularExpression( '#[/\\\\]#', $name );
	}

	/* ---- Filter ---- */

	public function test_filter_bereinigen() {
		$f = BuchBelege::filter_bereinigen(
			array( 'status' => 'offen; DROP', 'von' => '2026-05-01', 'bis' => '2026-01-01', 'konto' => '4930 OR 1=1', 'quelle' => 'erechnung', 'seite' => '-3', 'suche' => '  Bau   haus ' )
		);
		$this->assertSame( '', $f['status'] );
		$this->assertSame( '2026-01-01', $f['von'] );
		$this->assertSame( '2026-05-01', $f['bis'] );
		$this->assertSame( '', $f['konto'] );
		$this->assertSame( 'erechnung', $f['quelle'] );
		$this->assertSame( 1, $f['seite'] );
		$this->assertSame( 'Bau haus', $f['suche'] );

		$this->assertSame( 'storniert', BuchBelege::filter_bereinigen( array( 'status' => 'storniert' ) )['status'] );
		$this->assertSame( '4930', BuchBelege::filter_bereinigen( array( 'kategorie' => '4930' ) )['konto'] );
	}

	public function test_status_sql_ist_fest() {
		$this->assertSame( 'festgeschrieben = 0 AND storniert = 0', BuchBelege::status_sql( 'offen' ) );
		$this->assertSame( 'festgeschrieben = 1 AND storniert = 0', BuchBelege::status_sql( 'festgeschrieben' ) );
		$this->assertSame( 'storniert = 1', BuchBelege::status_sql( 'storniert' ) );
		$this->assertSame( '1=1', BuchBelege::status_sql( "x'; DROP TABLE" ) );
	}

	/* ---- Quelltext-Wächter ---- */

	public function test_jede_route_haengt_am_recht_der_buchhaltung() {
		$src = $this->source();

		$this->assertSame( 1, substr_count( $src, 'register_rest_route(' ), 'alle Routen laufen über route()' );
		$this->assertStringContainsString( "\$perm = \$api->needs( array( 'finanzen.buchhaltung' ) );", $src );
		$this->assertStringContainsString( "'permission_callback' => \$perm", $src );
		$this->assertStringNotContainsString( '__return_true', $src );

		// Jede angemeldete Route benutzt $perm, nie ein eigenes Recht.
		preg_match_all( '/self::route\( ([^;]+);/', $src, $m );
		$this->assertGreaterThanOrEqual( 12, count( $m[1] ) );
		foreach ( $m[1] as $aufruf ) {
			$this->assertStringEndsWith( '$perm )', $aufruf );
		}
	}

	public function test_schreibende_routen_sind_post() {
		$src = $this->source();
		foreach ( array( 'anlegen', 'aendern', 'festschreiben', 'festschreiben_alle', 'stornieren', 'ocr', 'erechnung_hochladen', 'erechnung_uebernehmen' ) as $name ) {
			$this->assertMatchesRegularExpression( "/'POST', '" . $name . "'/", $src, $name );
		}
		$this->assertMatchesRegularExpression( "/'GET', 'datei'/", $src );
	}

	public function test_storno_verlangt_grund_vor_dem_stornieren() {
		$src = $this->source();
		$a   = strpos( $src, 'storno_grund_error( $request->get_param' );
		$b   = strpos( $src, 'BelegRepository::stornieren(' );

		$this->assertNotFalse( $a );
		$this->assertNotFalse( $b );
		$this->assertLessThan( $b, $a, 'Grund wird geprüft, bevor storniert wird' );
		$this->assertStringContainsString( 'clean_grund( $request->get_param', $src );
	}

	public function test_geschuetzte_ablage_und_pfadpruefung() {
		$src = $this->source();
		$this->assertStringContainsString( 'Installer::belege_dir()', $src );
		$this->assertStringContainsString( 'realpath(', $src );
		$this->assertStringContainsString( 'DIRECTORY_SEPARATOR', $src );
		$this->assertStringContainsString( 'basename(', $src );
		$this->assertStringContainsString( 'X-Content-Type-Options: nosniff', $src );
		$this->assertStringContainsString( "hash_file( 'sha256'", $src );
		$this->assertStringContainsString( 'is_uploaded_file(', $src );
	}

	public function test_gobd_regeln_bleiben_im_repository() {
		$src = $this->source();

		// Keine eigenen Schreibzugriffe auf die Belegtabelle: Nummer, Hash-Kette, Festschreiben, Storno liegen im Repository.
		$this->assertStringNotContainsString( '$wpdb->insert', $src );
		$this->assertStringNotContainsString( '$wpdb->update', $src );
		$this->assertStringNotContainsString( '$wpdb->delete', $src );
		$this->assertStringNotContainsString( 'DELETE FROM', $src );
		$this->assertStringContainsString( 'BelegRepository::create(', $src );
		$this->assertStringContainsString( 'BelegRepository::festschreiben(', $src );
		$this->assertStringContainsString( 'BelegRepository::update(', $src );
		$this->assertStringContainsString( 'AuditLog::record(', $src );
		$this->assertStringContainsString( "Lock::acquire( 'beleg_nummer' )", $src );
	}

	public function test_oberflaeche_schnittstelle_fuer_marketing() {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/erp/pages/buch_belege.js' );

		$this->assertStringContainsString( 'UI.belegCapture = function', $js );
		$this->assertStringContainsString( "UI.buchTabs['belege']", $js );
		$this->assertStringContainsString( 'order: 10', $js );
		$this->assertStringContainsString( 'onSaved', $js );
		$this->assertStringContainsString( 'getUserMedia', $js );
		$this->assertStringContainsString( 'capture', $js );
		$this->assertStringNotContainsString( 'innerHTML', $js );
	}
}
