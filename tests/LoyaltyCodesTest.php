<?php
/**
 * Prüft das Aussehen der Empfehlungscodes: Vorname plus Zahl.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Loyalty\LoyaltyCodes;
use PHPUnit\Framework\TestCase;

/**
 * Code-Tests.
 */
class LoyaltyCodesTest extends TestCase {

	public function test_namensteil_loest_umlaute_auf_und_schreibt_gross_an() {
		$this->assertSame( 'Anna', LoyaltyCodes::base( ' anna ' ) );
		$this->assertSame( 'Joerg', LoyaltyCodes::base( 'Jörg' ) );
		$this->assertSame( 'Aenne', LoyaltyCodes::base( 'ÄNNE' ) );
		$this->assertSame( 'Annalena', LoyaltyCodes::base( 'Anna-Lena' ) );
		$this->assertSame( 'Strasser', LoyaltyCodes::base( 'Straßer' ) );
	}

	public function test_ohne_brauchbaren_namen_heisst_der_code_freund() {
		$this->assertSame( 'Freund', LoyaltyCodes::base( '' ) );
		$this->assertSame( 'Freund', LoyaltyCodes::base( '123 !!' ) );
	}

	public function test_der_namensteil_ist_begrenzt() {
		$this->assertSame( 12, strlen( LoyaltyCodes::base( 'Maximilianusbartholomaeus' ) ) );
	}

	public function test_code_ist_vorname_plus_zweistellige_zahl() {
		$code = LoyaltyCodes::build( 'Sven', static function () {
			return false;
		}, static function ( $min, $max ) {
			return 47;
		} );

		$this->assertSame( 'Sven47', $code );
	}

	public function test_bei_belegung_wird_die_zahl_laenger() {
		$calls = 0;
		$code  = LoyaltyCodes::build(
			'Sven',
			static function ( $c ) {
				return 1 === preg_match( '/^Sven\d{2}$/', $c );
			},
			static function ( $min, $max ) use ( &$calls ) {
				$calls++;

				return $min > 99 ? 321 : 47;
			}
		);

		$this->assertSame( 'Sven321', $code );
	}

	public function test_zufallszahl_liegt_im_bereich_der_stellenzahl() {
		$seen = array();
		LoyaltyCodes::build( 'Eva', static function () {
			return false;
		}, static function ( $min, $max ) use ( &$seen ) {
			$seen = array( $min, $max );

			return $min;
		} );

		$this->assertSame( array( 10, 99 ), $seen );
	}

	public function test_altes_format_wird_erkannt() {
		$this->assertTrue( LoyaltyCodes::is_old_format( 'AB12CD34' ) );
		$this->assertFalse( LoyaltyCodes::is_old_format( 'Anna47' ) );
		$this->assertFalse( LoyaltyCodes::is_old_format( 'ab12cd34' ) );
	}
}
