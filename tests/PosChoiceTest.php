<?php
/**
 * Auswahlwert der Kasse.
 *
 * Die Kasse bucht seit der Zusammenlegung in zwei mögliche Lager. Welches,
 * entscheidet der Wert aus dem Auswahlfeld – eine Verwechslung hier bucht
 * still ins falsche Lager.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\POS\POSModule;

/**
 * Prüft das Zerlegen des Auswahlwerts.
 */
class PosChoiceTest extends TestCase {

	/**
	 * Ein Betriebsartikel wird als solcher erkannt.
	 */
	public function test_betriebsartikel_wird_erkannt() {
		$result = POSModule::parse_choice( 'a:12' );

		$this->assertSame( 12, $result['article_id'] );
		$this->assertNull( $result['produkt_id'] );
	}

	/**
	 * Ein Produkt der alten Verwaltung wird als solches erkannt.
	 */
	public function test_altes_produkt_wird_erkannt() {
		$result = POSModule::parse_choice( 'p:7' );

		$this->assertNull( $result['article_id'] );
		$this->assertSame( 7, $result['produkt_id'] );
	}

	/**
	 * Eine nackte Zahl kommt aus einem Formular von vor dem Update und
	 * meint ein altes Produkt. Sie darf nicht als Betriebsartikel gelten –
	 * sonst bucht ein offener Tab auf einen fremden Artikel.
	 */
	public function test_nackte_zahl_bleibt_altes_produkt() {
		$result = POSModule::parse_choice( '7' );

		$this->assertNull( $result['article_id'] );
		$this->assertSame( 7, $result['produkt_id'] );
	}

	/**
	 * Leere und unsinnige Werte ergeben einen manuellen Artikel, keine
	 * Buchung auf die ID 0.
	 */
	public function test_leeres_ergibt_keine_zuordnung() {
		foreach ( array( '', '   ', 'a:', 'p:', 'a:0', 'p:0', 'abc' ) as $raw ) {
			$result = POSModule::parse_choice( $raw );

			$this->assertNull( $result['article_id'], $raw );
			$this->assertNull( $result['produkt_id'], $raw );
		}
	}

	/**
	 * Leerraum am Rand stört nicht.
	 */
	public function test_leerraum_wird_uebergangen() {
		$result = POSModule::parse_choice( '  a:5  ' );

		$this->assertSame( 5, $result['article_id'] );
	}
}
