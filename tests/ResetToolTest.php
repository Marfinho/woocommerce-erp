<?php
/**
 * Zurücksetzen vor dem Livegang.
 *
 * Geprüft wird der Teil, der ohne Datenbank auskommt – und dabei vor allem
 * das, was im Ernstfall teuer wäre: dass keine Stammdatentabelle in einem
 * löschbaren Bereich steht.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Core\ResetTool;

/**
 * Prüft Bereichsdefinition und Bestätigung.
 */
class ResetToolTest extends TestCase {

	/**
	 * Keine geschützte Tabelle darf in einem löschbaren Bereich auftauchen.
	 * Das ist die wichtigste Zusicherung dieser Datei.
	 */
	public function test_keine_stammdaten_in_loeschbaren_bereichen() {
		$protected = ResetTool::protected_tables();

		foreach ( ResetTool::scopes() as $key => $scope ) {
			foreach ( $scope['tables'] as $table ) {
				$this->assertNotContains(
					$table,
					$protected,
					sprintf( 'Bereich %s wuerde die geschuetzte Tabelle %s leeren.', $key, $table )
				);
			}
		}
	}

	/**
	 * Die zentralen Stammdaten stehen ausdrücklich unter Schutz.
	 */
	public function test_wichtige_stammdaten_sind_geschuetzt() {
		$protected = ResetTool::protected_tables();

		foreach ( array( 'articles', 'bom', 'components', 'printers', 'storage_bins', 'calc_settings', 'price_tiers' ) as $table ) {
			$this->assertContains( $table, $protected, $table . ' muss geschuetzt sein.' );
		}
	}

	/**
	 * Keine Tabelle steht in zwei Bereichen – sonst würde sie zweimal
	 * gezählt und der Vorschau wäre nicht zu trauen.
	 */
	public function test_keine_tabelle_in_zwei_bereichen() {
		$seen = array();

		foreach ( ResetTool::scopes() as $key => $scope ) {
			foreach ( $scope['tables'] as $table ) {
				$this->assertArrayNotHasKey(
					$table,
					$seen,
					sprintf( '%s steht in %s und in %s.', $table, $key, isset( $seen[ $table ] ) ? $seen[ $table ] : '?' )
				);
				$seen[ $table ] = $key;
			}
		}
	}

	/**
	 * Jeder Bereich hat Beschriftung und Erklärung – eine unbeschriftete
	 * Löschoption wäre grob fahrlässig.
	 */
	public function test_jeder_bereich_ist_beschriftet() {
		foreach ( ResetTool::scopes() as $key => $scope ) {
			$this->assertNotEmpty( $scope['label'], $key . ' ohne Beschriftung.' );
			$this->assertNotEmpty( $scope['hint'], $key . ' ohne Erklaerung.' );
		}
	}

	/**
	 * Die Vorauswahl enthält nur bekannte Bereiche und lässt die heiklen
	 * bewusst aus.
	 */
	public function test_vorauswahl_ist_zurueckhaltend() {
		$known    = array_keys( ResetTool::scopes() );
		$defaults = ResetTool::default_scopes();

		foreach ( $defaults as $key ) {
			$this->assertContains( $key, $known, $key . ' ist kein bekannter Bereich.' );
		}

		$this->assertNotContains( 'kontakte', $defaults, 'Kontakte sind oft schon echt.' );
		$this->assertNotContains( 'altes_lager', $defaults );
	}

	/* ================================================================
	 * Bestätigungssatz
	 * ============================================================== */

	/**
	 * Der genaue Wortlaut wird angenommen.
	 */
	public function test_richtiger_satz_wird_angenommen() {
		$this->assertTrue( ResetTool::phrase_matches( ResetTool::PHRASE ) );
	}

	/**
	 * Groß- und Kleinschreibung sowie Leerraum am Rand sind nachsichtig
	 * behandelt – wer den Satz tippt, meint ihn.
	 */
	public function test_schreibweise_und_leerraum_sind_nachsichtig() {
		$this->assertTrue( ResetTool::phrase_matches( '  alles zuruecksetzen  ' ) );
		$this->assertTrue( ResetTool::phrase_matches( 'Alles   Zuruecksetzen' ) );
	}

	/**
	 * Alles andere wird abgewiesen – gerade das, was man versehentlich
	 * tippen könnte.
	 */
	public function test_falscher_satz_wird_abgewiesen() {
		foreach ( array( '', 'ja', 'ALLES', 'ZURUECKSETZEN', 'alles zurücksetzen', 'ALLES ZURUCKSETZEN', 'ALLESZURUECKSETZEN' ) as $input ) {
			$this->assertFalse(
				ResetTool::phrase_matches( $input ),
				sprintf( '"%s" haette nicht genuegen duerfen.', $input )
			);
		}
	}
}
