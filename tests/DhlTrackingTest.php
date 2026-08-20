<?php
/**
 * Sendungsverfolgung: Nummernerkennung und Fortschrittsstufe.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Production\DhlTracking;

/**
 * Prüft die datenbankfreien Teile der Sendungsverfolgung.
 */
class DhlTrackingTest extends TestCase {

	/**
	 * Eine schlichte Zeichenkette kommt unverändert zurück.
	 */
	public function test_zeichenkette_wird_uebernommen() {
		$this->assertSame( '00340434161094042557', DhlTracking::flatten_tracking_meta( '00340434161094042557' ) );
		$this->assertSame( '123', DhlTracking::flatten_tracking_meta( '  123  ' ) );
	}

	/**
	 * Versandplugins legen Nummern oft in Listen ab – auch verschachtelt.
	 * Ein einfaches reset() liefert dort ein Array statt einer Nummer.
	 */
	public function test_verschachtelte_listen_werden_aufgeloest() {
		$this->assertSame(
			'00340434161094042557',
			DhlTracking::flatten_tracking_meta( array( array( 'tracking_number' => '00340434161094042557' ) ) )
		);

		$this->assertSame(
			'ABC123',
			DhlTracking::flatten_tracking_meta( array( 'tracking_id' => 'ABC123' ) )
		);

		$this->assertSame(
			'XYZ',
			DhlTracking::flatten_tracking_meta( array( array( array( 'XYZ' ) ) ) )
		);
	}

	/**
	 * Benannte Felder haben Vorrang vor der bloßen Reihenfolge.
	 */
	public function test_benannte_felder_haben_vorrang() {
		$this->assertSame(
			'RICHTIG',
			DhlTracking::flatten_tracking_meta(
				array(
					'provider'        => 'DHL',
					'tracking_number' => 'RICHTIG',
				)
			)
		);
	}

	/**
	 * Leeres bleibt leer.
	 */
	public function test_leeres_bleibt_leer() {
		$this->assertSame( '', DhlTracking::flatten_tracking_meta( array() ) );
		$this->assertSame( '', DhlTracking::flatten_tracking_meta( '' ) );
		$this->assertSame( '', DhlTracking::flatten_tracking_meta( null ) );
	}

	/* ================================================================
	 * Fortschritt aus dem Bestellstatus
	 * ============================================================== */

	/**
	 * Bezahlte Bestellungen bekommen die Stufe „Bestellt", abgeschlossene
	 * die Stufe „Versendet".
	 */
	public function test_offene_bestellungen_zeigen_fortschritt() {
		$this->assertSame( 0, DhlTracking::step_for_order_status( 'processing' ) );
		$this->assertSame( 0, DhlTracking::step_for_order_status( 'wc-processing' ) );
		$this->assertSame( 0, DhlTracking::step_for_order_status( 'on-hold' ) );
		$this->assertSame( 1, DhlTracking::step_for_order_status( 'completed' ) );
	}

	/**
	 * Abgebrochene Vorgänge bekommen gar keine Anzeige – ein
	 * Fortschrittsbalken über einer stornierten Bestellung wäre Hohn.
	 */
	public function test_abgebrochene_bestellungen_zeigen_nichts() {
		foreach ( array( 'cancelled', 'refunded', 'failed', 'pending', 'checkout-draft', 'unbekannt' ) as $status ) {
			$this->assertNull( DhlTracking::step_for_order_status( $status ), $status );
		}
	}
}
