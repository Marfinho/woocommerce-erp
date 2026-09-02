<?php
/**
 * Der Block und der PHP-Code dahinter müssen zusammenpassen.
 *
 * Ein Block hat seine Einstellungen an zwei Stellen: in der block.json und
 * im Render-Rückruf, der sie liest. Gehen die auseinander, fehlt im
 * Frontend still eine Einstellung, die im Editor einstellbar war – ohne
 * Fehlermeldung, ohne Hinweis. Genau das prüft dieser Test.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Events\EventsBlock;
use Lindenweg16\Modules\Events\EventsWidget;

/**
 * @covers \Lindenweg16\Modules\Events\EventsBlock
 */
class EventsBlockTest extends TestCase {

	/**
	 * Wurzel des Plugins.
	 *
	 * @return string
	 */
	protected function root() {
		return dirname( __DIR__ );
	}

	/**
	 * Inhalt der block.json.
	 *
	 * @return array
	 */
	protected function block_json() {
		$pfad = $this->root() . '/assets/blocks/events/block.json';
		$this->assertFileExists( $pfad, 'Ohne block.json gibt es keinen Block.' );

		$daten = json_decode( (string) file_get_contents( $pfad ), true );
		$this->assertIsArray( $daten, 'block.json ist kein gültiges JSON.' );

		return $daten;
	}

	public function test_block_json_ist_gueltig_und_vollstaendig() {
		$block = $this->block_json();

		foreach ( array( 'name', 'title', 'category', 'attributes', 'editorScript' ) as $feld ) {
			$this->assertArrayHasKey( $feld, $block, 'In block.json fehlt: ' . $feld );
		}
	}

	public function test_blockname_steht_nur_einmal_fest() {
		$this->assertSame( EventsBlock::NAME, $this->block_json()['name'] );
	}

	public function test_editor_skript_verweist_auf_das_angemeldete_handle() {
		// Ohne Build-Schritt wird das Skript von Hand angemeldet. Stimmt das
		// Handle nicht, laedt der Editor nichts - und der Block fehlt
		// wortlos in der Blockauswahl.
		$this->assertSame( EventsBlock::HANDLE, $this->block_json()['editorScript'] );
	}

	public function test_jede_einstellung_wird_auch_ausgewertet() {
		$block  = $this->block_json();
		$quelle = (string) file_get_contents( $this->root() . '/includes/Modules/Events/EventsBlock.php' );

		$unbenutzt = array();
		foreach ( array_keys( $block['attributes'] ) as $name ) {
			if ( false === strpos( $quelle, "'" . $name . "'" ) ) {
				$unbenutzt[] = $name;
			}
		}

		$this->assertSame(
			array(),
			$unbenutzt,
			"Diese Einstellungen sind im Editor sichtbar, werden beim Rendern aber nicht gelesen:\n  "
			. implode( "\n  ", $unbenutzt )
		);
	}

	public function test_jede_einstellung_ist_im_editor_bedienbar() {
		$block  = $this->block_json();
		$skript = (string) file_get_contents( $this->root() . '/assets/js/events-block.js' );

		$ohne = array();
		foreach ( array_keys( $block['attributes'] ) as $name ) {
			if ( false === strpos( $skript, 'attr.' . $name ) ) {
				$ohne[] = $name;
			}
		}

		$this->assertSame(
			array(),
			$ohne,
			"Diese Einstellungen stehen in block.json, sind im Editor aber nirgends einstellbar:\n  "
			. implode( "\n  ", $ohne )
		);
	}

	public function test_voreinstellung_der_wartezeit_stimmt_mit_php_ueberein() {
		$block = $this->block_json();

		$this->assertSame(
			EventsWidget::INTERVALL_STANDARD,
			$block['attributes']['intervall']['default'],
			'Der Block startet mit einer anderen Wartezeit als der Rest des Plugins.'
		);
	}

	public function test_verwiesene_dateien_existieren() {
		$block = $this->block_json();
		$basis = $this->root() . '/assets/blocks/events/';

		foreach ( $block as $wert ) {
			if ( ! is_string( $wert ) || 0 !== strpos( $wert, 'file:' ) ) {
				continue;
			}

			$pfad = $basis . substr( $wert, 5 );
			$this->assertFileExists(
				$pfad,
				'block.json verweist auf eine Datei, die es nicht gibt: ' . $wert
			);
		}
	}

	public function test_block_speichert_kein_html() {
		// Ein Block, der sein HTML in den Beitrag schreibt, traegt die
		// Teilnehmerzahlen von gestern mit sich herum.
		$skript = (string) file_get_contents( $this->root() . '/assets/js/events-block.js' );

		$this->assertMatchesRegularExpression(
			'/save:\s*function\s*\([^)]*\)\s*\{\s*return null;/',
			$skript,
			'Der Block muss dynamisch bleiben – save() gibt null zurück.'
		);
	}
}
