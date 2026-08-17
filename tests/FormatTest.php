<?php
/**
 * Unit-Tests für die zentrale Zahlenformatierung.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Core\Format;

/**
 * @covers \Lindenweg16\Core\Format
 */
class FormatTest extends TestCase {

	public function test_qty_strips_trailing_zero_decimals() {
		$this->assertSame( '5', Format::qty( 5 ) );
		$this->assertSame( '5,5', Format::qty( 5.5 ) );
		$this->assertSame( '5,25', Format::qty( 5.25 ) );
	}

	public function test_qty_keeps_thousands_separator() {
		$this->assertSame( '1.000', Format::qty( 1000 ) );
		$this->assertSame( '2.500,5', Format::qty( 2500.5 ) );
	}

	/**
	 * Der frühere Code trimmte blind Nullen von rechts. Sobald das
	 * Dezimalkomma wegfällt, würde das Ganzzahlen verstümmeln – deshalb
	 * darf nur getrimmt werden, wenn überhaupt ein Komma vorhanden ist.
	 */
	public function test_qty_never_truncates_integer_part() {
		$this->assertSame( '100', Format::qty( 100 ) );
		$this->assertSame( '10', Format::qty( 10 ) );
		$this->assertSame( '0', Format::qty( 0 ) );
		$this->assertSame( '20.000', Format::qty( 20000 ) );
	}

	public function test_qty_handles_negative_values() {
		$this->assertSame( '-3,5', Format::qty( -3.5 ) );
		$this->assertSame( '-40', Format::qty( -40 ) );
	}

	public function test_amount_and_euro_keep_two_decimals() {
		$this->assertSame( '1.234,50', Format::amount( 1234.5 ) );
		$this->assertSame( '1.234,50 €', Format::euro( 1234.5 ) );
		$this->assertSame( '0,00 €', Format::euro( 0 ) );
	}
}
