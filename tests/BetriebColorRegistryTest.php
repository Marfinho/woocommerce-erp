<?php
/**
 * Farbsystem: Normalisierung und Auflösung.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\ColorRegistry;

/**
 * Gespeichert wird immer der Slug, nie der Anzeigename.
 */
class BetriebColorRegistryTest extends TestCase {

	/**
	 * Anzeigename und Slug führen auf denselben Wert.
	 */
	public function test_anzeigename_und_slug_werden_gleich() {
		$this->assertSame( 'anthrazit', ColorRegistry::normalize( 'Anthrazit' ) );
		$this->assertSame( 'anthrazit', ColorRegistry::normalize( 'anthrazit' ) );
		$this->assertSame( 'anthrazit', ColorRegistry::normalize( '  ANTHRAZIT  ' ) );
	}

	/**
	 * Umlaute werden umgeschrieben, damit „Grün" nicht neben „gruen" als
	 * zweite Farbe steht.
	 */
	public function test_umlaute_werden_umgeschrieben() {
		$this->assertSame( 'gruen', ColorRegistry::normalize( 'Grün' ) );
		$this->assertSame( 'gruen', ColorRegistry::normalize( 'gruen' ) );
		$this->assertSame( 'weiss', ColorRegistry::normalize( 'weiß' ) );
	}

	/**
	 * Mehrteilige Namen werden zu einem Slug verbunden.
	 */
	public function test_mehrteilige_namen() {
		$this->assertSame( 'matt-schwarz', ColorRegistry::normalize( 'Matt Schwarz' ) );
		$this->assertSame( 'matt-schwarz', ColorRegistry::normalize( 'matt-schwarz' ) );
		$this->assertSame( 'ral-7016', ColorRegistry::normalize( 'RAL 7016' ) );
	}

	/**
	 * Leere Eingaben bleiben leer.
	 */
	public function test_leere_eingabe() {
		$this->assertSame( '', ColorRegistry::normalize( '' ) );
		$this->assertSame( '', ColorRegistry::normalize( '   ' ) );
		$this->assertSame( '', ColorRegistry::normalize( '---' ) );
	}

	/**
	 * Der erste belegte Kandidat gewinnt: die Variante schlägt das
	 * Produktattribut.
	 */
	public function test_variante_schlaegt_produktattribut() {
		$result = ColorRegistry::resolve(
			array(
				'variante' => 'Grün',
				'produkt'  => 'anthrazit',
			),
			'anthrazit'
		);

		$this->assertSame( 'gruen', $result['color'] );
		$this->assertSame( 'variante', $result['resolved_from'] );
		$this->assertFalse( $result['is_default'] );
	}

	/**
	 * Fehlt die Variante, greift das Produktattribut.
	 */
	public function test_produktattribut_als_zweite_quelle() {
		$result = ColorRegistry::resolve(
			array(
				'variante' => '',
				'produkt'  => 'Anthrazit',
			),
			'schwarz'
		);

		$this->assertSame( 'anthrazit', $result['color'] );
		$this->assertSame( 'produkt', $result['resolved_from'] );
	}

	/**
	 * Ohne jede Angabe greift die Standardfarbe – das ist der Normalfall,
	 * solange nur eine Farbe produziert wird.
	 */
	public function test_standardfarbe_bei_einfarbigem_sortiment() {
		$result = ColorRegistry::resolve( array( 'variante' => '', 'produkt' => '' ), 'anthrazit' );

		$this->assertSame( 'anthrazit', $result['color'] );
		$this->assertSame( 'standard', $result['resolved_from'] );
		$this->assertTrue( $result['is_default'] );
	}

	/**
	 * Ohne Standardfarbe wird als ungeklärt gebucht, nicht verworfen: Ein
	 * fehlender Bestand fällt später auf, eine verschwundene Buchung nicht.
	 */
	public function test_ohne_standardfarbe_ungeklaert() {
		$result = ColorRegistry::resolve( array( 'variante' => '' ), '' );

		$this->assertSame( ColorRegistry::UNRESOLVED, $result['color'] );
		$this->assertFalse( $result['is_default'] );
	}

	/**
	 * Auch ein leeres Kandidatenfeld führt zur Standardfarbe.
	 */
	public function test_gar_keine_kandidaten() {
		$result = ColorRegistry::resolve( array(), 'anthrazit' );

		$this->assertSame( 'anthrazit', $result['color'] );
		$this->assertTrue( $result['is_default'] );
	}
}
