<?php
/**
 * Der Link, den der QR-Code der AR-Ansicht trägt.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Configurator\ArLink;
use PHPUnit\Framework\TestCase;

/**
 * Prüft, dass der Link nur auf die eigene Seite zeigt und die Konfiguration trägt.
 */
class KonfiguratorArTest extends TestCase {

	const HOME = 'https://lindenweg16.de/';

	/**
	 * Seite, Konfiguration und AR-Parameter stehen im Link.
	 */
	public function test_link_traegt_code_und_ar() {
		$link = ArLink::build( 'https://lindenweg16.de/konfigurator/', 'v1.5.weiss.7@300.5', self::HOME );

		$this->assertSame( 'https://lindenweg16.de/konfigurator/?haken=v1.5.weiss.7%40300.5&ar=1', $link );
	}

	/**
	 * Eine alte Konfiguration und ein alter AR-Parameter in der Adresse werden ersetzt.
	 */
	public function test_alte_parameter_werden_ersetzt() {
		$link = ArLink::build( 'https://lindenweg16.de/konfigurator/?haken=v1.9.rot&ar=1&utm_source=flyer#oben', 'v1.5.weiss', self::HOME );

		$this->assertSame( 'https://lindenweg16.de/konfigurator/?utm_source=flyer&haken=v1.5.weiss&ar=1', $link );
	}

	/**
	 * Eine fremde Adresse ergibt keinen Link.
	 */
	public function test_fremde_adresse_wird_abgelehnt() {
		$this->assertSame( '', ArLink::build( 'https://boese.example/konfigurator/', 'v1.5.weiss', self::HOME ) );
		$this->assertSame( '', ArLink::build( 'https://lindenweg16.de.boese.example/', 'v1.5.weiss', self::HOME ) );
		$this->assertSame( '', ArLink::build( 'javascript:alert(1)', 'v1.5.weiss', self::HOME ) );
		$this->assertSame( '', ArLink::build( 'ftp://lindenweg16.de/', 'v1.5.weiss', self::HOME ) );
	}

	/**
	 * Ein ungültiger Konfigurationscode ergibt keinen Link.
	 */
	public function test_ungueltiger_code_wird_abgelehnt() {
		$this->assertSame( '', ArLink::build( 'https://lindenweg16.de/konfigurator/', 'quatsch', self::HOME ) );
		$this->assertSame( '', ArLink::build( 'https://lindenweg16.de/konfigurator/', '', self::HOME ) );
	}
}
