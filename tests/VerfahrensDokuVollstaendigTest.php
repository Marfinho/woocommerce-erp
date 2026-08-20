<?php
/**
 * Vollständigkeit der Verfahrensdokumentation.
 *
 * Die Dokumentation ist das, was einem Prüfer vorgelegt wird. Eine, die die
 * Hälfte des Systems verschweigt, ist schlechter als keine – und sie
 * verschweigt sie schleichend, weil beim Anlegen einer Tabelle niemand an
 * das Dokument denkt.
 *
 * Dieser Test liest beide Dateien als Text. Das ist bewusst grob: Er soll
 * ohne WordPress laufen und nur eine Frage beantworten – steht jede Tabelle
 * im Dokument?
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

/**
 * Vergleicht Schema und Dokumentation.
 */
class VerfahrensDokuVollstaendigTest extends TestCase {

	/**
	 * Wurzel des Plugins.
	 *
	 * @return string
	 */
	protected function root() {
		return dirname( __DIR__ );
	}

	/**
	 * Tabellennamen aus dem Schema des Moduls Betrieb.
	 *
	 * @return array
	 */
	protected function schema_tables() {
		$source = file_get_contents( $this->root() . '/includes/Modules/Betrieb/Schema.php' );

		preg_match_all( '/CREATE TABLE \{\$p\}([a-z_]+)/', $source, $matches );

		return array_unique( $matches[1] );
	}

	/**
	 * In der Dokumentation beschriebene Tabellen.
	 *
	 * @return array
	 */
	protected function documented_tables() {
		$source = file_get_contents( $this->root() . '/includes/Modules/Betrieb/VerfahrensDokuBetrieb.php' );

		preg_match_all( "/'([a-z_]+)' *=> *__\(/", $source, $matches );

		return array_unique( $matches[1] );
	}

	/**
	 * Tabellennamen aus dem Installer des Plugins.
	 *
	 * @return array
	 */
	protected function installer_tables() {
		$source = file_get_contents( $this->root() . '/includes/Core/Installer.php' );

		preg_match_all( '/CREATE TABLE \{\$p\}([a-z_]+)/', $source, $matches );

		return array_unique( $matches[1] );
	}

	/**
	 * Der Test selbst muss etwas finden – sonst prüft er nichts und wäre
	 * grün, während die Dokumentation leer ist.
	 */
	public function test_der_test_findet_ueberhaupt_etwas() {
		$this->assertGreaterThan( 20, count( $this->schema_tables() ), 'Schema nicht gelesen.' );
		$this->assertGreaterThan( 20, count( $this->documented_tables() ), 'Dokumentation nicht gelesen.' );
	}

	/**
	 * Jede Tabelle des Moduls ist im Dokument beschrieben.
	 */
	public function test_jede_tabelle_ist_beschrieben() {
		$fehlend = array_diff( $this->schema_tables(), $this->documented_tables() );

		$this->assertSame(
			array(),
			array_values( $fehlend ),
			"Diese Tabellen fehlen in der Verfahrensdokumentation:\n  " . implode( "\n  ", $fehlend )
		);
	}

	/**
	 * Umgekehrt beschreibt das Dokument keine Tabelle, die es nicht gibt –
	 * eine erfundene Zeile wäre für einen Prüfer schlimmer als eine fehlende.
	 */
	public function test_keine_erfundenen_tabellen() {
		$schema = $this->schema_tables();

		// Einige Tabellen legt der Installer des Plugins an, nicht das
		// Schema des Moduls. Sie im Dokument zu nennen ist richtig – nur
		// findet dieser Test sie an anderer Stelle.
		$fremd = array_unique(
			array_merge(
				array( 'belege', 'buchungen', 'rechnungen', 'kassenbuch', 'anlagen', 'dauerbuchungen' ),
				$this->installer_tables()
			)
		);

		foreach ( $this->documented_tables() as $table ) {
			if ( in_array( $table, $fremd, true ) ) {
				continue;
			}

			$this->assertContains(
				$table,
				$schema,
				sprintf( 'Die Dokumentation beschreibt %s, aber es gibt die Tabelle nicht.', $table )
			);
		}
	}
}
