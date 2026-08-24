<?php
/**
 * Prüft den Auszug zur Analyse.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\AnalyseExport;
use PHPUnit\Framework\TestCase;

/**
 * Tests der Darstellung.
 */
class BetriebAnalyseExportTest extends TestCase {

	/**
	 * Ein kleiner, vollständiger Zustand.
	 *
	 * @param array $over Abweichungen.
	 * @return array
	 */
	protected function daten( array $over = array() ) {
		return array_merge(
			array(
				'stand'    => '2026-08-24 10:00',
				'von'      => '2026-08-01',
				'bis'      => '2026-08-24',
				'version'  => '0.10.0',
				'articles' => array(
					array( 'HH-001', 'Basisplatte', 'druck', 'aktiv', 'v1.0', 'PETG', '31.5', '0.420', '4.0', '2.10', '14.90', '9.90', '42', '30', '5.2' ),
				),
				'gaps'     => array( 'Die Buchhaltung ist nicht enthalten.' ),
			),
			$over
		);
	}

	/**
	 * Zahlen stehen mit Punkt und fester Stellenzahl da.
	 */
	public function test_zahlen_sind_eindeutig() {
		$this->assertSame( '1234.50', AnalyseExport::num( 1234.5, 2 ) );
		$this->assertSame( '0.0625', AnalyseExport::num( 0.0625, 4 ) );
		$this->assertSame( '42', AnalyseExport::num( 42, 0 ) );
	}

	/**
	 * Nicht erfasst ist nicht null.
	 */
	public function test_fehlender_wert_bleibt_leer() {
		$this->assertSame( '', AnalyseExport::num( null ) );
		$this->assertSame( '', AnalyseExport::num( '' ) );
		$this->assertSame( '0.00', AnalyseExport::num( 0 ) );
	}

	/**
	 * Ein senkrechter Strich im Text darf die Tabelle nicht zerreissen.
	 */
	public function test_zelle_zerreisst_die_tabelle_nicht() {
		$this->assertSame( 'a / b', AnalyseExport::cell( 'a | b' ) );
		$this->assertSame( 'zwei Zeilen', AnalyseExport::cell( "zwei\nZeilen" ) );
	}

	/**
	 * Eine Tabelle hat Kopf, Trennzeile und je Zeile eine Zeile.
	 */
	public function test_tabelle_hat_kopf_und_zeilen() {
		$text = AnalyseExport::table( array( 'A', 'B' ), array( array( 1, 2 ), array( 3, 4 ) ) );

		$this->assertStringContainsString( '| A | B |', $text );
		$this->assertStringContainsString( '| --- | --- |', $text );
		$this->assertStringContainsString( '| 1 | 2 |', $text );
		$this->assertStringContainsString( '| 3 | 4 |', $text );
	}

	/**
	 * Zu kurze oder zu lange Zeilen werden auf die Spaltenzahl gebracht –
	 * sonst verrutschen die Werte gegen die Köpfe.
	 */
	public function test_zeilen_passen_zur_spaltenzahl() {
		$text = AnalyseExport::table( array( 'A', 'B', 'C' ), array( array( 1 ), array( 1, 2, 3, 4 ) ) );

		$this->assertStringContainsString( '| 1 |  |  |', $text );
		$this->assertStringContainsString( '| 1 | 2 | 3 |', $text );
	}

	/**
	 * Ohne Daten steht das da, statt einer leeren Tabelle.
	 */
	public function test_leerer_abschnitt_sagt_es() {
		$this->assertStringContainsString( 'Keine Daten', AnalyseExport::table( array( 'A' ), array() ) );
	}

	/**
	 * Eine Kürzung wird genannt. Eine stillschweigend abgeschnittene Liste
	 * würde jede Auswertung in die Irre führen.
	 */
	public function test_kuerzung_wird_genannt() {
		$rows = array_fill( 0, AnalyseExport::MAX_ROWS + 5, array( 'x' ) );
		$text = AnalyseExport::table( array( 'A' ), $rows );

		$this->assertStringContainsString( '5', $text );
		$this->assertStringContainsString( 'Gekürzt', $text );
		$this->assertSame( AnalyseExport::MAX_ROWS, substr_count( $text, '| x |' ) );
	}

	/**
	 * Der Vorspann nennt Zeitraum, Stand und die Leseregeln.
	 */
	public function test_vorspann_erklaert_die_datei() {
		$text = AnalyseExport::render( $this->daten() );

		$this->assertStringContainsString( '2026-08-01', $text );
		$this->assertStringContainsString( '2026-08-24', $text );
		$this->assertStringContainsString( '0.10.0', $text );
		$this->assertStringContainsString( 'Dezimaltrennzeichen', $text );
		$this->assertStringContainsString( 'nicht erfasst', $text );
	}

	/**
	 * Alle Abschnitte sind da – auch die leeren.
	 */
	public function test_alle_abschnitte_stehen_drin() {
		$text = AnalyseExport::render( $this->daten() );

		foreach ( array(
			'Kalkulationsgrundlagen',
			'Artikel',
			'Stücklisten',
			'Kennzahlen',
			'Druckjournal',
			'Ausschussursachen',
			'Arbeitszeit',
			'Offene Aufträge',
			'Lagerbewegungen',
			'Verkauf',
			'Geschäftskunden',
			'Systemprüfung',
		) as $abschnitt ) {
			$this->assertStringContainsString( $abschnitt, $text );
		}
	}

	/**
	 * Die Daten selbst landen in der Datei.
	 */
	public function test_die_zeilen_stehen_in_der_datei() {
		$text = AnalyseExport::render( $this->daten() );

		$this->assertStringContainsString( '| HH-001 | Basisplatte | druck |', $text );
	}

	/**
	 * Die bekannten Lücken stehen am Ende. Ohne sie liest jemand die
	 * Zahlen für belastbarer, als sie sind.
	 */
	public function test_die_luecken_stehen_am_ende() {
		$text = AnalyseExport::render( $this->daten() );

		$this->assertStringContainsString( 'Was in diesen Daten fehlt', $text );
		$this->assertStringContainsString( 'Die Buchhaltung ist nicht enthalten.', $text );
		// Der Vorspann verweist auf den Abschnitt, deshalb das letzte Vorkommen.
		$this->assertGreaterThan(
			strpos( $text, 'Systemprüfung' ),
			strrpos( $text, 'Was in diesen Daten fehlt' )
		);
	}

	/**
	 * Die fest hinterlegten Lücken sind vollständig genug, um die groben
	 * Fehlschlüsse abzufangen.
	 */
	public function test_die_hinterlegten_luecken_nennen_das_wesentliche() {
		$text = implode( ' ', AnalyseExport::gaps() );

		foreach ( array( 'Buchhaltung', 'Kommissionsware', 'Reichweite', 'Arbeitszeit' ) as $stichwort ) {
			$this->assertStringContainsString( $stichwort, $text );
		}
	}

	/**
	 * Ohne Namen wird ausdrücklich gesagt, dass die Kunden nummeriert sind.
	 */
	public function test_ohne_namen_steht_der_hinweis_da() {
		$mit  = AnalyseExport::render( $this->daten( array( 'contacts_named' => true ) ) );
		$ohne = AnalyseExport::render( $this->daten( array( 'contacts_named' => false ) ) );

		$this->assertStringContainsString( 'durchnummeriert', $ohne );
		$this->assertStringNotContainsString( 'durchnummeriert', $mit );
	}
}
