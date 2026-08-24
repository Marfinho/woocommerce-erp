<?php
/**
 * Lieferschein für Händlerbestellungen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\DeliveryNote;
use PHPUnit\Framework\TestCase;

/**
 * Eine halbe Adresse auf einem Lieferschein liest sich wie ein Fehler –
 * und ist einer.
 */
class BetriebDeliveryNoteTest extends TestCase {

	/**
	 * Vollständiger Kontakt.
	 *
	 * @return array
	 */
	protected function kontakt() {
		return array(
			'company'        => 'Holzhandel Meier',
			'contact_person' => 'A. Meier',
			'street'         => 'Lindenweg 16',
			'zip'            => '26123',
			'city'           => 'Oldenburg',
		);
	}

	/**
	 * Die Anschrift steht in der Reihenfolge, in der man sie liest.
	 */
	public function test_anschrift_in_lesereihenfolge() {
		$this->assertSame(
			array( 'Holzhandel Meier', 'A. Meier', 'Lindenweg 16', '26123 Oldenburg' ),
			DeliveryNote::address_lines( $this->kontakt() )
		);
	}

	/**
	 * Leere Felder fallen weg statt eine Leerzeile zu hinterlassen.
	 */
	public function test_leere_felder_fallen_weg() {
		$kontakt = $this->kontakt();
		$kontakt['contact_person'] = '';

		$this->assertSame(
			array( 'Holzhandel Meier', 'Lindenweg 16', '26123 Oldenburg' ),
			DeliveryNote::address_lines( $kontakt )
		);
	}

	/**
	 * Fehlt die PLZ, steht der Ort trotzdem ohne führendes Leerzeichen da.
	 */
	public function test_ort_ohne_plz_bleibt_sauber() {
		$kontakt = $this->kontakt();
		$kontakt['zip'] = '';

		$zeilen = DeliveryNote::address_lines( $kontakt );

		$this->assertSame( 'Oldenburg', end( $zeilen ) );
	}

	/**
	 * Ein Kontakt ohne jede Adresse ergibt keine leeren Zeilen.
	 */
	public function test_ohne_adresse_keine_leerzeilen() {
		$this->assertSame(
			array( 'Holzhandel Meier' ),
			DeliveryNote::address_lines( array( 'company' => 'Holzhandel Meier' ) )
		);
	}

	/**
	 * Vollständigkeit wird erkannt.
	 */
	public function test_vollstaendige_anschrift() {
		$this->assertFalse( DeliveryNote::address_incomplete( $this->kontakt() ) );
	}

	/**
	 * Jedes fehlende Adressfeld macht die Anschrift unvollständig.
	 */
	public function test_jedes_fehlende_feld_faellt_auf() {
		foreach ( array( 'street', 'zip', 'city' ) as $feld ) {
			$kontakt = $this->kontakt();
			$kontakt[ $feld ] = '   ';

			$this->assertTrue(
				DeliveryNote::address_incomplete( $kontakt ),
				'Fehlendes Feld nicht erkannt: ' . $feld
			);
		}
	}

	/**
	 * Mengen ohne überflüssige Nachkommastellen.
	 */
	public function test_menge_wird_knapp_gesetzt() {
		$this->assertSame( '3', DeliveryNote::fmt( 3.0 ) );
		$this->assertSame( '2,50', DeliveryNote::fmt( 2.5 ) );
	}
}
