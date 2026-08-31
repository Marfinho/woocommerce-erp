<?php
/**
 * Unit-Tests für den Kontenrahmen (Kostenart-Achse).
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Accounting\Kontenrahmen;

/**
 * @covers \Lindenweg16\Modules\Accounting\Kontenrahmen
 */
class KontenrahmenTest extends TestCase {

	public function test_werbekonto_existiert_und_ist_ein_aufwandskonto() {
		$konto = Kontenrahmen::get( Kontenrahmen::KONTO_WERBUNG );
		$this->assertNotNull( $konto );
		$this->assertSame( 'ausgabe', $konto['typ'] );
		$this->assertSame( 'Werbe- und Reisekosten', $konto['gruppe'] );
	}

	public function test_bewirtung_ist_nur_zu_siebzig_prozent_abziehbar() {
		$this->assertSame( 70.0, Kontenrahmen::abzugsfaehig( '4650' ) );
		$this->assertSame( 70.0, Kontenrahmen::abziehbarer_betrag( '4650', 100.0 ) );
	}

	public function test_normale_konten_sind_voll_abziehbar() {
		$this->assertSame( 100.0, Kontenrahmen::abzugsfaehig( Kontenrahmen::KONTO_WERBUNG ) );
		$this->assertSame( 250.0, Kontenrahmen::abziehbarer_betrag( Kontenrahmen::KONTO_WERBUNG, 250.0 ) );
	}

	public function test_unbekanntes_konto_wird_nicht_stillschweigend_gekuerzt() {
		// Lieber voll ansetzen als eine Kürzung erfinden, die niemand sieht.
		$this->assertSame( 100.0, Kontenrahmen::abzugsfaehig( '9999' ) );
		$this->assertFalse( Kontenrahmen::exists( '9999' ) );
	}

	public function test_erloeskonto_haengt_an_der_kleinunternehmer_regelung() {
		$this->assertSame( '8195', Kontenrahmen::erloes_konto( true ) );
		$this->assertSame( '8400', Kontenrahmen::erloes_konto( false ) );
	}

	public function test_alte_kategorien_finden_ihr_konto() {
		$this->assertSame( '4672', Kontenrahmen::konto_fuer_kategorie( 'Fahrtkosten' ) );
		$this->assertSame( '3200', Kontenrahmen::konto_fuer_kategorie( 'Wareneinkauf' ) );
		$this->assertSame( '4970', Kontenrahmen::konto_fuer_kategorie( 'Nebenkosten des Geldverkehrs' ) );
	}

	public function test_unbekannte_kategorie_liefert_kein_konto() {
		$this->assertSame( '', Kontenrahmen::konto_fuer_kategorie( 'Phantasieposten' ) );
	}

	public function test_label_traegt_die_kontonummer_voran() {
		$this->assertSame( '4600 Werbekosten', Kontenrahmen::label( '4600' ) );
	}

	public function test_gruppierung_liefert_nur_aufwandskonten() {
		$gruppen = Kontenrahmen::grouped( 'ausgabe' );
		$this->assertArrayHasKey( 'Werbe- und Reisekosten', $gruppen );
		$this->assertArrayNotHasKey( 'Erlöse', $gruppen );
	}

	public function test_marktbesuch_verteilt_sich_ueber_mehrere_konten_einer_kostenstelle() {
		// Der Kern der Trennung: eine Kostenstelle, vier Kostenarten.
		$standgebuehr = Kontenrahmen::gruppe( '4600' );
		$fahrt        = Kontenrahmen::gruppe( '4672' );
		$flyer        = Kontenrahmen::gruppe( '4980' );
		$bewirtung    = Kontenrahmen::gruppe( '4650' );

		$this->assertSame( 'Werbe- und Reisekosten', $standgebuehr );
		$this->assertSame( 'Werbe- und Reisekosten', $fahrt );
		$this->assertSame( 'Betriebsbedarf', $flyer );
		$this->assertSame( 'Werbe- und Reisekosten', $bewirtung );

		// Und nur die Bewirtung wird gekürzt.
		$this->assertSame( 100.0, Kontenrahmen::abziehbarer_betrag( '4980', 100.0 ) );
		$this->assertSame( 70.0, Kontenrahmen::abziehbarer_betrag( '4650', 100.0 ) );
	}
}
