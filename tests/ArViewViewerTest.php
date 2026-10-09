<?php
/**
 * AR-Ansicht: Skript-Tag als ES-Modul.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\ArView\Viewer;
use PHPUnit\Framework\TestCase;

/**
 * Die Umschreibung darf nur den Tag mit src treffen.
 */
class ArViewViewerTest extends TestCase {

	public function test_fremde_handles_bleiben_unberuehrt() {
		$tag = "<script src='https://x.test/a.js'></script>";
		$this->assertSame( $tag, Viewer::to_module_tag( $tag, 'jquery' ) );
	}

	public function test_src_tag_wird_modul() {
		$out = Viewer::to_module_tag( "<script src='https://x.test/ar-viewer.js?ver=1' id='lw16-ar-viewer-js'></script>\n", 'lw16-ar-viewer' );

		$this->assertStringStartsWith( '<script type="module" src=', $out );
		$this->assertSame( 1, substr_count( $out, 'type=' ) );
	}

	public function test_vorhandenes_type_wird_ersetzt() {
		$out = Viewer::to_module_tag( "<script type='text/javascript' src='a.js'></script>", 'lw16-ar-viewer' );

		$this->assertStringNotContainsString( 'text/javascript', $out );
		$this->assertStringContainsString( 'type="module"', $out );
		$this->assertStringContainsString( "src='a.js'", $out );
	}

	public function test_inline_daten_davor_bleiben_erhalten() {
		$before = "<script id='x-js-before'>window.A = 1;</script>\n";
		$out    = Viewer::to_module_tag( $before . "<script src='a.js'></script>", 'lw16-ar-viewer' );

		$this->assertStringStartsWith( $before, $out, 'Die Datenzeile darf nicht verloren gehen.' );
		$this->assertSame( 1, substr_count( $out, 'type="module"' ) );
	}
}
