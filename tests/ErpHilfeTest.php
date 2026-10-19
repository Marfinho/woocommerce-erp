<?php
/**
 * Anleitungen und AR-Ansicht in der neuen Oberfläche: reine Regeln.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\ArAnsicht;
use Lindenweg16\Erp\Pages\Hilfe;
use PHPUnit\Framework\TestCase;

/**
 * Tests für Hilfe und ArAnsicht.
 */
class ErpHilfeTest extends TestCase {

	public function test_manual_url_only_for_known_parts() {
		$manuals = array( 'start' => array( 'Einstieg', 'einstieg', 'x' ) );

		$this->assertSame( 'https://x.de/a.html#einstieg', Hilfe::part_url( $manuals, 'start', 'https://x.de/a.html' ) );
		$this->assertSame( '', Hilfe::part_url( $manuals, '../../etc/passwd', 'https://x.de/a.html' ) );
	}

	public function test_ar_range_falls_back_to_30_days() {
		$this->assertSame( 90, ArAnsicht::clean_days( '90' ) );
		$this->assertSame( 0, ArAnsicht::clean_days( 0 ) );
		$this->assertSame( 30, ArAnsicht::clean_days( 7 ) );
		$this->assertSame( 30, ArAnsicht::clean_days( 'alle' ) );
		$this->assertSame( 30, ArAnsicht::clean_days( null ) );
	}

	public function test_unsent_switch_means_off() {
		$out = ArAnsicht::settings_from_input( array( 'page_id' => '5', 'lock_days' => '9999' ) );

		$this->assertSame( 0, $out['enabled'] );
		$this->assertSame( 5, $out['page_id'] );
		$this->assertSame( 365, $out['lock_days'] );
	}

	public function test_sent_switch_means_on_and_empty_texts_get_defaults() {
		$out = ArAnsicht::settings_from_input( array( 'enabled' => 1, 'title' => '   ' ) );

		$this->assertSame( 1, $out['enabled'] );
		$this->assertNotSame( '', $out['title'] );
	}
}
