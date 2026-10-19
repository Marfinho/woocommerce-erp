<?php
/**
 * Regeln der nativen Support-Seite.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Support;
use PHPUnit\Framework\TestCase;

/**
 * Tests für Support.
 */
class ErpSupportTest extends TestCase {

	public function test_only_known_statuses_can_be_set() {
		$this->assertSame( 'geloest', Support::status_for( 'geloest' ) );
		$this->assertSame( 'in_bearbeitung', Support::status_for( 'in_bearbeitung' ) );
		$this->assertSame( '', Support::status_for( 'offen' ) );
		$this->assertSame( '', Support::status_for( 'gutschrift' ) );
	}

	public function test_credit_accepts_comma_and_rounds_to_cents() {
		$this->assertSame( 12.5, Support::credit_amount( '12,50' ) );
		$this->assertSame( 3.33, Support::credit_amount( '3.333' ) );
		$this->assertSame( 20.0, Support::credit_amount( 20 ) );
	}

	public function test_credit_refuses_zero_negative_and_text() {
		$this->assertSame( 0.0, Support::credit_amount( '0' ) );
		$this->assertSame( 0.0, Support::credit_amount( '-5' ) );
		$this->assertSame( 0.0, Support::credit_amount( 'viel' ) );
		$this->assertSame( 0.0, Support::credit_amount( '' ) );
		$this->assertSame( 0.0, Support::credit_amount( '0,001' ) );
	}

	public function test_status_labels_are_german() {
		$this->assertSame( 'Gelöst', Support::status_label( 'geloest' ) );
		$this->assertSame( 'Offen', Support::status_label( 'offen' ) );
	}
}
