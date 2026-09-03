<?php
/**
 * Eingaben der Marktkasse: Geldbeträge, Kachelkürzel, Suchtext.
 *
 * Am Stand wird „12,50" getippt, nicht „12.50". Ein nacktes (float)
 * daraus wäre 12,00 € – der Fehler fällt erst beim Kassensturz auf, wenn
 * niemand mehr weiß, welche Position ihn verursacht hat.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\POS\POSModule;

/**
 * Prüft die Zeichenkettenlogik der Kasseneingaben.
 */
class PosAmountTest extends TestCase {

	/**
	 * Das Komma ist der Dezimaltrenner, den man hier tippt.
	 */
	public function test_komma_ist_dezimaltrenner() {
		$this->assertSame( 12.5, POSModule::parse_amount( '12,50' ) );
		$this->assertSame( 0.99, POSModule::parse_amount( '0,99' ) );
	}

	/**
	 * Der Punkt bleibt daneben gültig – vom Tablet mit englischem
	 * Tastaturlayout kommt er genauso.
	 */
	public function test_punkt_bleibt_gueltig() {
		$this->assertSame( 12.5, POSModule::parse_amount( '12.50' ) );
	}

	/**
	 * Währungszeichen und Leerraum stören nicht.
	 */
	public function test_waehrung_und_leerraum_werden_ignoriert() {
		$this->assertSame( 12.5, POSModule::parse_amount( ' 12,50 € ' ) );
	}

	/**
	 * Ein Tausenderpunkt ist kein Dezimaltrenner: „1.250" sind
	 * eintausendzweihundertfünfzig Euro, nicht 1,25 €.
	 */
	public function test_tausendertrenner_wird_erkannt() {
		$this->assertSame( 1250.0, POSModule::parse_amount( '1.250' ) );
		$this->assertSame( 1250.5, POSModule::parse_amount( '1.250,50' ) );
		$this->assertSame( 1250.5, POSModule::parse_amount( '1,250.50' ) );
	}

	/**
	 * Ohne Trenner bleibt die ganze Zahl stehen.
	 */
	public function test_ganze_zahl_bleibt_ganz() {
		$this->assertSame( 15.0, POSModule::parse_amount( '15' ) );
	}

	/**
	 * Leere und unsinnige Eingaben ergeben 0 – nie eine Zufallszahl.
	 */
	public function test_unsinn_ergibt_null() {
		foreach ( array( '', '   ', 'abc', '€', '-', ',' ) as $raw ) {
			$this->assertSame( 0.0, POSModule::parse_amount( $raw ), $raw );
		}
	}

	/**
	 * Ein negativer Betrag wäre in der Kasse eine Auszahlung. Die läuft
	 * über den Storno, nicht über ein Minuszeichen im Preisfeld.
	 */
	public function test_negatives_wird_nicht_gebucht() {
		$this->assertSame( 0.0, POSModule::parse_amount( '-5,00' ) );
	}

	/**
	 * Zahlen kommen unverändert durch.
	 */
	public function test_zahlen_bleiben_zahlen() {
		$this->assertSame( 7.25, POSModule::parse_amount( 7.25 ) );
		$this->assertSame( 3.0, POSModule::parse_amount( 3 ) );
	}

	/**
	 * Ein Array käme nur aus einem manipulierten Formular und darf keinen
	 * Fehler auslösen.
	 */
	public function test_kein_string_ergibt_null() {
		$this->assertSame( 0.0, POSModule::parse_amount( array( '5,00' ) ) );
		$this->assertSame( 0.0, POSModule::parse_amount( null ) );
	}

	/**
	 * Eine Kachel ohne Produktbild zeigt die Initialen – sonst wäre sie
	 * eine leere Fläche neben lauter anderen leeren Flächen.
	 */
	public function test_kuerzel_aus_den_ersten_beiden_woertern() {
		$this->assertSame( 'HH', POSModule::initials( 'Haster Haken' ) );
		$this->assertSame( 'HH', POSModule::initials( '  Haster   Haken  groß ' ) );
		$this->assertSame( 'S', POSModule::initials( 'Schiene' ) );
		$this->assertSame( '?', POSModule::initials( '' ) );
	}

	/**
	 * Der Filter im Browser vergleicht denselben normalisierten Text.
	 * Weicht die Aufbereitung ab, findet die Suche Kacheln nicht, die
	 * sichtbar danebenstehen.
	 */
	public function test_suchtext_ist_klein_und_ohne_doppelten_leerraum() {
		$this->assertSame( 'hh-01 – haster haken', POSModule::search_key( "HH-01 –  Haster\nHaken " ) );
	}
}
