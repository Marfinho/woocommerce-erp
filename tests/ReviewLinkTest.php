<?php
/**
 * Bewertungslink für Gastkäufer.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Retention\ReviewLink;

/**
 * Signatur, Zerlegung und Anzeigename.
 */
class ReviewLinkTest extends TestCase {

	/**
	 * Die Signatur hängt an Bestellung, E-Mail (ohne Groß/Klein) und Schlüssel.
	 */
	public function test_signatur() {
		$a = ReviewLink::signatur( 42, 'Kunde@Example.de', 'geheim' );
		$this->assertSame( 20, strlen( $a ) );
		$this->assertSame( $a, ReviewLink::signatur( 42, ' kunde@example.de ', 'geheim' ) );
		$this->assertNotSame( $a, ReviewLink::signatur( 43, 'kunde@example.de', 'geheim' ) );
		$this->assertNotSame( $a, ReviewLink::signatur( 42, 'andere@example.de', 'geheim' ) );
		$this->assertNotSame( $a, ReviewLink::signatur( 42, 'kunde@example.de', 'anders' ) );
	}

	/**
	 * Nur „Zahl.20 Hexzeichen“ wird angenommen.
	 */
	public function test_zerlegen() {
		$this->assertSame( array( 'order_id' => 1234, 'sig' => 'abcdef0123456789abcd' ), ReviewLink::zerlegen( '1234.abcdef0123456789abcd' ) );
		$this->assertNull( ReviewLink::zerlegen( '1234.ABCDEF0123456789ABCD' ) );
		$this->assertNull( ReviewLink::zerlegen( '1234.abc' ) );
		$this->assertNull( ReviewLink::zerlegen( 'x.abcdef0123456789abcd' ) );
		$this->assertNull( ReviewLink::zerlegen( array( '1' ) ) );
	}

	/**
	 * Öffentlich sichtbar nur Vorname und Initial.
	 */
	public function test_anzeigename() {
		$this->assertSame( 'Sven M.', ReviewLink::anzeigename( 'Sven', 'Meendermann' ) );
		$this->assertSame( 'Sven', ReviewLink::anzeigename( 'Sven', '' ) );
		$this->assertSame( 'Ö.', ReviewLink::anzeigename( '', 'Özdemir' ) );
	}
}
