<?php
/**
 * Prüft den Umgang mit gelöschten Shop-Bestellungen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\OrderSource;
use PHPUnit\Framework\TestCase;

/**
 * Quellen-Tests.
 */
class BetriebOrderSourceTest extends TestCase {

	/**
	 * Eine Bestellzeile.
	 *
	 * @param array $over Abweichungen.
	 * @return array
	 */
	protected function zeile( array $over = array() ) {
		return array_merge(
			array(
				'id'                 => 1,
				'wc_order_id'        => 4711,
				'manual_order_value' => null,
				'manual_order_date'  => null,
				'order_status'       => 'bestaetigt',
				'source_deleted_at'  => null,
			),
			$over
		);
	}

	/**
	 * Nur Shop-Bestellungen können verwaisen.
	 *
	 * Eine von Hand erfasste Bestellung hat keine Quelle, die verschwinden
	 * könnte – sie darf nie als verwaist gelten, sonst räumt das Aufräumen
	 * den halben Bestand ab.
	 */
	public function test_nur_shop_bestellungen_koennen_verwaisen() {
		$manuell = $this->zeile( array( 'wc_order_id' => null, 'manual_order_value' => 42.0 ) );

		$this->assertFalse( OrderSource::from_shop( $manuell ) );
		$this->assertFalse( OrderSource::is_orphaned( $manuell, false ) );
		$this->assertTrue( OrderSource::counts( $manuell, false ) );
	}

	/**
	 * Solange die Bestellung ladbar ist, ist nichts verwaist.
	 *
	 * Der Papierkorb zählt deshalb nicht: Eine Bestellung im Papierkorb lädt
	 * weiterhin und kann zurückgeholt werden.
	 */
	public function test_ladbare_bestellung_ist_nicht_verwaist() {
		$this->assertFalse( OrderSource::is_orphaned( $this->zeile(), true ) );
		$this->assertTrue( OrderSource::is_orphaned( $this->zeile(), false ) );
	}

	/**
	 * Eine Zeile ohne eigene Zahlen zählt nicht mehr mit.
	 *
	 * Sonst ginge sie als Bestellung über 0,00 € ohne Datum in die Zahl der
	 * Bestellungen und den Ø-Warenkorb ein – in der Umsatzsumme fiele das
	 * nicht auf, in den beiden anderen schon.
	 */
	public function test_zeile_ohne_zahlen_zaehlt_nicht_mehr() {
		$this->assertFalse( OrderSource::counts( $this->zeile(), false ) );

		$mit = $this->zeile( array( 'manual_order_value' => 89.9, 'manual_order_date' => '2026-08-15' ) );
		$this->assertTrue( OrderSource::counts( $mit, false ) );
	}

	/**
	 * Halbe Angaben sind keine Angaben.
	 *
	 * Ein Wert ohne Datum landet in keinem Zeitraum, ein Datum ohne Wert
	 * zählt als Bestellung über null Euro.
	 */
	public function test_halbe_angaben_zaehlen_nicht() {
		$this->assertFalse( OrderSource::has_snapshot( $this->zeile( array( 'manual_order_value' => 89.9 ) ) ) );
		$this->assertFalse( OrderSource::has_snapshot( $this->zeile( array( 'manual_order_date' => '2026-08-15' ) ) ) );
	}

	/**
	 * Beim Löschen werden Wert und Datum festgehalten.
	 */
	public function test_beim_loeschen_werden_wert_und_datum_festgehalten() {
		$daten = OrderSource::snapshot( $this->zeile(), 89.9, '2026-08-15', '2026-08-31 10:00:00' );

		$this->assertSame( 89.9, $daten['manual_order_value'] );
		$this->assertSame( '2026-08-15', $daten['manual_order_date'] );
		$this->assertSame( '2026-08-31 10:00:00', $daten['source_deleted_at'] );
	}

	/**
	 * Eigene Angaben werden nicht überschrieben.
	 *
	 * Wer den Wert von Hand korrigiert hat, hat sich dabei etwas gedacht –
	 * der Shop-Wert darf ihn beim Löschen nicht überschreiben.
	 */
	public function test_eigene_angaben_bleiben_stehen() {
		$zeile = $this->zeile( array( 'manual_order_value' => 55.0, 'manual_order_date' => '2026-01-02' ) );
		$daten = OrderSource::snapshot( $zeile, 89.9, '2026-08-15', '2026-08-31 10:00:00' );

		$this->assertArrayNotHasKey( 'manual_order_value', $daten );
		$this->assertArrayNotHasKey( 'manual_order_date', $daten );
		$this->assertSame( '2026-08-31 10:00:00', $daten['source_deleted_at'] );
	}

	/**
	 * Ohne ladbare Bestellung wird nichts erfunden.
	 *
	 * Ist die Bestellung schon weg, wenn der Hook feuert, bleibt nur der
	 * Vermerk – ein geschätzter Wert wäre schlimmer als keiner.
	 */
	public function test_ohne_ladbare_bestellung_wird_nichts_erfunden() {
		$daten = OrderSource::snapshot( $this->zeile(), null, '', '2026-08-31 10:00:00' );

		$this->assertSame( array( 'source_deleted_at' => '2026-08-31 10:00:00' ), $daten );
	}

	/**
	 * Ein unbrauchbares Datum wird nicht übernommen.
	 */
	public function test_unbrauchbares_datum_wird_nicht_uebernommen() {
		$daten = OrderSource::snapshot( $this->zeile(), 10.0, 'gestern', '2026-08-31 10:00:00' );

		$this->assertArrayNotHasKey( 'manual_order_date', $daten );
		$this->assertSame( 10.0, $daten['manual_order_value'] );
	}

	/**
	 * Die Erklärung unterscheidet die beiden Fälle.
	 */
	public function test_die_erklaerung_unterscheidet_die_faelle() {
		$mit  = $this->zeile( array( 'manual_order_value' => 89.9, 'manual_order_date' => '2026-08-15' ) );
		$ohne = $this->zeile();

		$this->assertNotSame( OrderSource::explain( $mit ), OrderSource::explain( $ohne ) );
		$this->assertStringContainsString( 'festgehalten', OrderSource::explain( $mit ) );
		$this->assertStringContainsString( 'keine Zahlen', OrderSource::explain( $ohne ) );
	}
}
