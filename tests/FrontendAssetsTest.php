<?php
/**
 * Frontend: Skripte, Markup und Dateien müssen zusammenpassen.
 *
 * Das JavaScript greift die Elemente über feste IDs ab. Fehlt eine davon
 * im PHP, wirft der erste Zugriff – und weil das schon beim Aufbau
 * passiert, hängt danach die ganze Seite: kein Druckjob, keine Zeit, kein
 * Scan. Genau das ist einmal passiert. Dieser Test hält es fest.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

/**
 * Liest beide Dateien und vergleicht sie.
 */
class FrontendAssetsTest extends TestCase {

	/**
	 * Pfad zum Skript.
	 *
	 * @return string
	 */
	protected function script() {
		return dirname( __DIR__ ) . '/assets/js/betrieb-mobile.js';
	}

	/**
	 * Pfad zur Seite.
	 *
	 * @return string
	 */
	protected function markup() {
		return dirname( __DIR__ ) . '/includes/Modules/Betrieb/MobileCapture.php';
	}

	/**
	 * Alle IDs, die das Skript per getElementById erwartet.
	 *
	 * @return array
	 */
	protected function expected_ids() {
		$js = file_get_contents( $this->script() );

		preg_match_all( "/getElementById\(\s*'([^']+)'/", $js, $treffer );

		// Zusammengesetzte IDs (lw16- plus Datenattribut) lassen sich
		// statisch nicht auflösen und bleiben draußen.
		return array_values(
			array_filter(
				array_unique( $treffer[1] ),
				static function ( $id ) {
					return '' !== $id && '-' !== substr( $id, -1 );
				}
			)
		);
	}

	/**
	 * Der Test muss überhaupt etwas zu prüfen finden.
	 */
	public function test_der_test_findet_ids() {
		$this->assertGreaterThan( 10, count( $this->expected_ids() ) );
	}

	/**
	 * Jede erwartete ID kommt im Markup vor.
	 */
	public function test_jede_id_existiert_im_markup() {
		$html   = file_get_contents( $this->markup() );
		$fehlen = array();

		foreach ( $this->expected_ids() as $id ) {
			if ( false === strpos( $html, '"' . $id . '"' ) && false === strpos( $html, "'" . $id . "'" ) ) {
				$fehlen[] = $id;
			}
		}

		$this->assertSame(
			array(),
			$fehlen,
			'Diese IDs erwartet das Skript, das Markup liefert sie nicht: ' . implode( ', ', $fehlen )
		);
	}

	/**
	 * Jeder Reiter hat ein Panel und jedes Panel einen Reiter.
	 */
	public function test_reiter_und_panels_passen_zusammen() {
		$html = file_get_contents( $this->markup() );

		preg_match_all( '/data-tab="([a-z]+)"/', $html, $tabs );
		preg_match_all( '/data-panel="([a-z]+)"/', $html, $panels );

		$tabs   = array_unique( $tabs[1] );
		$panels = array_unique( $panels[1] );

		sort( $tabs );
		sort( $panels );

		$this->assertNotEmpty( $tabs );
		$this->assertSame( $tabs, $panels );
	}

	/**
	 * Die vier Reiter, die das Handbuch beschreibt, sind auch da.
	 */
	public function test_alle_vier_reiter_sind_vorhanden() {
		$html = file_get_contents( $this->markup() );

		foreach ( array( 'druck', 'lager', 'picken', 'zeit' ) as $tab ) {
			$this->assertStringContainsString( 'data-tab="' . $tab . '"', $html );
		}
	}

	/**
	 * Jedes Skript wird von irgendeiner PHP-Datei eingebunden.
	 *
	 * Ein Skript, das niemand lädt, ist entweder tot oder eine vergessene
	 * Einbindung – beides will man wissen.
	 */
	public function test_jedes_skript_wird_eingebunden() {
		$root  = dirname( __DIR__ );
		$lose  = array();

		foreach ( glob( $root . '/assets/js/*.js' ) as $datei ) {
			$name    = basename( $datei );
			$treffer = shell_exec( 'grep -rl ' . escapeshellarg( $name ) . ' ' . escapeshellarg( $root . '/includes' ) . ' 2>/dev/null' );

			if ( ! trim( (string) $treffer ) ) {
				$lose[] = $name;
			}
		}

		$this->assertSame( array(), $lose, 'Nicht eingebundene Skripte: ' . implode( ', ', $lose ) );
	}

	/**
	 * Jede Stildatei, auf die PHP zeigt, liegt auch da.
	 */
	public function test_verwiesene_dateien_existieren() {
		$root = dirname( __DIR__ );
		$roh  = shell_exec(
			'grep -rhoE "assets/(js|css)/[a-z0-9.-]+\\.(js|css)" ' . escapeshellarg( $root . '/includes' ) . ' 2>/dev/null'
		);

		$pfade = array_unique( array_filter( array_map( 'trim', explode( "\n", (string) $roh ) ) ) );
		$fehlen = array();

		foreach ( $pfade as $pfad ) {
			if ( ! file_exists( $root . '/' . $pfad ) ) {
				$fehlen[] = $pfad;
			}
		}

		$this->assertNotEmpty( $pfade, 'Der Test findet keine Verweise – dann prüft er nichts.' );
		$this->assertSame( array(), $fehlen, 'Verwiesen, aber nicht vorhanden: ' . implode( ', ', $fehlen ) );
	}
}
