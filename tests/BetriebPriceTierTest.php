<?php
/**
 * Prüfung der Standard-Rabattstaffel.
 *
 * Eine unstimmige Staffel fällt nicht bei der Eingabe auf, sondern beim
 * Händler an der Kasse. Deshalb wird hier vor dem Festschreiben geprüft.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\PriceTierRepository;

/**
 * Prüft die Eingabelogik der Staffel.
 */
class BetriebPriceTierTest extends TestCase {

	/**
	 * Eine übliche Staffel geht durch und kommt sortiert zurück.
	 */
	public function test_gueltige_staffel_wird_sortiert_uebernommen() {
		$result = PriceTierRepository::validate(
			array(
				array( 'tier_name' => 'Stufe 3', 'min_order_value' => '800', 'discount_pct' => '50' ),
				array( 'tier_name' => 'Stufe 1', 'min_order_value' => '150', 'discount_pct' => '40' ),
				array( 'tier_name' => 'Stufe 2', 'min_order_value' => '400', 'discount_pct' => '45' ),
			)
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertCount( 3, $result['tiers'] );
		$this->assertSame( 'Stufe 1', $result['tiers'][0]['tier_name'] );
		$this->assertSame( 'Stufe 3', $result['tiers'][2]['tier_name'] );
	}

	/**
	 * Leere Reservezeilen des Formulars sind kein Fehler.
	 */
	public function test_leere_zeilen_werden_uebergangen() {
		$result = PriceTierRepository::validate(
			array(
				array( 'tier_name' => 'Stufe 1', 'min_order_value' => '150', 'discount_pct' => '40' ),
				array( 'tier_name' => '', 'min_order_value' => '', 'discount_pct' => '' ),
				array( 'tier_name' => '', 'min_order_value' => '0', 'discount_pct' => '0' ),
			)
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertCount( 1, $result['tiers'] );
	}

	/**
	 * Komma statt Punkt darf nicht zu einem stillen Nullrabatt führen.
	 */
	public function test_komma_wird_als_dezimaltrenner_verstanden() {
		$result = PriceTierRepository::validate(
			array( array( 'tier_name' => 'A', 'min_order_value' => '149,50', 'discount_pct' => '12,5' ) )
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertEqualsWithDelta( 149.5, $result['tiers'][0]['min_order_value'], 0.0001 );
		$this->assertEqualsWithDelta( 12.5, $result['tiers'][0]['discount_pct'], 0.0001 );
	}

	/**
	 * Ein Rabatt von 100 Prozent oder mehr wäre Verschenken.
	 */
	public function test_rabatt_ab_hundert_prozent_wird_abgewiesen() {
		$result = PriceTierRepository::validate(
			array( array( 'tier_name' => 'A', 'min_order_value' => '100', 'discount_pct' => '100' ) )
		);

		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Negative Werte werden abgewiesen.
	 */
	public function test_negative_werte_werden_abgewiesen() {
		$this->assertNotEmpty(
			PriceTierRepository::validate(
				array( array( 'tier_name' => 'A', 'min_order_value' => '100', 'discount_pct' => '-5' ) )
			)['errors']
		);

		$this->assertNotEmpty(
			PriceTierRepository::validate(
				array( array( 'tier_name' => 'A', 'min_order_value' => '-100', 'discount_pct' => '5' ) )
			)['errors']
		);
	}

	/**
	 * Zwei Stufen ab demselben Bestellwert wären nicht entscheidbar.
	 */
	public function test_doppelter_bestellwert_wird_abgewiesen() {
		$result = PriceTierRepository::validate(
			array(
				array( 'tier_name' => 'A', 'min_order_value' => '150', 'discount_pct' => '40' ),
				array( 'tier_name' => 'B', 'min_order_value' => '150', 'discount_pct' => '45' ),
			)
		);

		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Mehr Umsatz muss mehr Rabatt bedeuten – sonst wäre die größere
	 * Bestellung für den Händler die schlechtere.
	 */
	public function test_fallender_rabatt_wird_abgewiesen() {
		$result = PriceTierRepository::validate(
			array(
				array( 'tier_name' => 'A', 'min_order_value' => '150', 'discount_pct' => '45' ),
				array( 'tier_name' => 'B', 'min_order_value' => '400', 'discount_pct' => '40' ),
			)
		);

		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Gleichbleibender Rabatt über zwei Stufen ist erlaubt – ungewöhnlich,
	 * aber nicht widersprüchlich.
	 */
	public function test_gleichbleibender_rabatt_ist_erlaubt() {
		$result = PriceTierRepository::validate(
			array(
				array( 'tier_name' => 'A', 'min_order_value' => '150', 'discount_pct' => '40' ),
				array( 'tier_name' => 'B', 'min_order_value' => '400', 'discount_pct' => '40' ),
			)
		);

		$this->assertSame( array(), $result['errors'] );
	}

	/**
	 * Eine ganz leere Eingabe löscht die Staffel nicht versehentlich.
	 */
	public function test_leere_eingabe_wird_abgewiesen() {
		$result = PriceTierRepository::validate( array() );

		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( array(), $result['tiers'] );
	}

	/**
	 * Ohne Bezeichnung wird eine vergeben, statt die Zeile zu verwerfen.
	 */
	public function test_fehlende_bezeichnung_wird_ergaenzt() {
		$result = PriceTierRepository::validate(
			array( array( 'tier_name' => '', 'min_order_value' => '150', 'discount_pct' => '40' ) )
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertNotSame( '', $result['tiers'][0]['tier_name'] );
	}
}
