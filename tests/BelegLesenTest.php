<?php
/**
 * Belege auslesen: PDF-Textschicht und die Felder daraus.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Accounting\BelegOcr;
use Lindenweg16\Modules\Accounting\PdfText;
use PHPUnit\Framework\TestCase;

/**
 * Der Beleg im Test ist ein echtes PDF, erzeugt von einem anderen
 * Programm als dem Leser hier. Eine selbstgebaute Datei würde nur
 * beweisen, dass zwei eigene Bausteine zueinander passen.
 */
class BelegLesenTest extends TestCase {

	/**
	 * Pfad zum Beleg.
	 *
	 * @return string
	 */
	protected function pdf() {
		return __DIR__ . '/fixtures-beleg.pdf';
	}

	/**
	 * Der Text kommt vollständig und lesbar heraus – samt Umlauten.
	 *
	 * Eingebettete Teilschriften nummerieren ihre Zeichen neu; ohne die
	 * mitgelieferte Zeichentabelle liest man Buchstabensalat.
	 */
	public function test_pdf_text_ist_lesbar() {
		$text = PdfText::from_file( $this->pdf() );

		$this->assertStringContainsString( 'Schrauben Müller GmbH', $text );
		$this->assertStringContainsString( 'Gesamtbetrag', $text );
		$this->assertStringContainsString( '56,50', $text );
		$this->assertStringContainsString( 'Gewindeeinsätze', $text );
	}

	/**
	 * Aus dem Beleg kommen Betrag, Datum und Händler richtig heraus.
	 */
	public function test_felder_aus_dem_beleg() {
		$text = PdfText::from_file( $this->pdf() );

		$this->assertSame( '56.50', BelegOcr::extract_betrag( $text ) );
		$this->assertSame( '2026-08-14', BelegOcr::extract_datum( $text ) );
		$this->assertSame( 'Schrauben Müller GmbH', BelegOcr::extract_haendler( $text ) );
	}

	/**
	 * Der ganze Weg über scan() liefert dasselbe und nennt die Quelle.
	 */
	public function test_scan_nutzt_die_textschicht() {
		$result = BelegOcr::scan( $this->pdf() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'pdf', $result['quelle'] );
		$this->assertSame( '56.50', $result['betrag'] );
	}

	/**
	 * Etwas, das kein PDF ist, wird nicht als eines gelesen.
	 */
	public function test_kein_pdf_bleibt_leer() {
		$this->assertSame( '', PdfText::from_string( 'Guten Tag, das ist kein PDF.' ) );
		$this->assertFalse( PdfText::looks_like_pdf( 'GIF89a' ) );
		$this->assertTrue( PdfText::looks_like_pdf( '%PDF-1.7' ) );
	}

	/**
	 * Der Gesamtbetrag gewinnt gegen die Positionen.
	 *
	 * Nur den ersten Treffer zu nehmen, holte hier die 12,00 – keine
	 * Position ist aber je größer als die Summe.
	 */
	public function test_summe_schlaegt_positionen() {
		$text = "Artikel A 12,00 EUR\nArtikel B 12,00 EUR\nSumme 24,00 EUR";

		$this->assertSame( '24.00', BelegOcr::extract_betrag( $text ) );
	}

	/**
	 * Tausenderpunkt: 1.234,56 sind nicht 1,23.
	 */
	public function test_tausenderpunkt() {
		$this->assertSame( 1234.56, BelegOcr::to_amount( '1.234,56' ) );
		$this->assertSame( 1234.56, BelegOcr::to_amount( '1,234.56' ) );
		$this->assertSame( 56.5, BelegOcr::to_amount( '56,50' ) );
		$this->assertSame( 56.5, BelegOcr::to_amount( '56.50' ) );
	}

	/**
	 * Ein Betrag mit Tausenderpunkt wird auch gefunden, nicht nur richtig
	 * umgerechnet.
	 */
	public function test_grosser_betrag_wird_gefunden() {
		$this->assertSame( '1234.56', BelegOcr::extract_betrag( 'Rechnungsbetrag: 1.234,56 EUR' ) );
	}

	/**
	 * Ohne brauchbaren Text wird nichts geraten.
	 */
	public function test_ohne_text_wird_nichts_geraten() {
		$this->assertFalse( BelegOcr::is_useful( '' ) );
		$this->assertFalse( BelegOcr::is_useful( '12 34 56' ) );
		$this->assertTrue( BelegOcr::is_useful( 'Schrauben Müller GmbH, Rechnung über 56,50 EUR' ) );
	}

	/**
	 * Ein Datum in der Zukunft oder ferner Vergangenheit ist keins.
	 */
	public function test_unsinniges_datum_wird_verworfen() {
		$this->assertSame( '', BelegOcr::extract_datum( 'Bestellnummer 31.13.2026' ) );
		$this->assertSame( '', BelegOcr::extract_datum( 'Kein Datum weit und breit' ) );
	}

	/**
	 * Eine Zeichentabelle wird richtig ausgewertet.
	 */
	public function test_zeichentabelle() {
		$cmap = "begincmap\n1 beginbfchar\n<0036> <0053>\nendbfchar\n"
			. "1 beginbfrange\n<0044> <0046> <0061>\nendbfrange\nendcmap";

		$karte = PdfText::parse_cmap( $cmap );

		$this->assertSame( 'S', $karte[ 0x36 ] );
		$this->assertSame( 'a', $karte[ 0x44 ] );
		$this->assertSame( 'b', $karte[ 0x45 ] );
		$this->assertSame( 'c', $karte[ 0x46 ] );
	}

	/**
	 * Ein unsinnig großer Bereich wird übersprungen statt Speicher zu
	 * fressen.
	 */
	public function test_unsinniger_bereich_wird_uebersprungen() {
		$cmap  = "1 beginbfrange\n<0000> <FFFFFF> <0041>\nendbfrange";
		$karte = PdfText::parse_cmap( $cmap );

		$this->assertLessThan( 70000, count( $karte ) );
	}
}
