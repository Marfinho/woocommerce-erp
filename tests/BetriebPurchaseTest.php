<?php
/**
 * Prüft die Nachbestellungen.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\Purchase;
use PHPUnit\Framework\TestCase;

/**
 * Bestell-Tests.
 */
class BetriebPurchaseTest extends TestCase {

	/**
	 * Eine Bestellung.
	 *
	 * @param array $over Abweichungen.
	 * @return array
	 */
	protected function bestellung( array $over = array() ) {
		return array_merge(
			array(
				'item_type'      => 'component',
				'item_id'        => 7,
				'status'         => 'bestellt',
				'qty'            => 100.0,
				'unit_price_net' => 0.12,
				'shipping_net'   => 4.90,
				'ordered_on'     => '2026-08-20',
				'expected_on'    => '2026-08-25',
			),
			$over
		);
	}

	/**
	 * Der erwartete Termin folgt aus Bestelldatum und Lieferzeit.
	 */
	public function test_liefertermin_folgt_aus_der_lieferzeit() {
		$this->assertSame( '2026-08-25', Purchase::expected_date( '2026-08-20', 5 ) );
		$this->assertSame( '2026-09-01', Purchase::expected_date( '2026-08-20', 12 ) );
		$this->assertSame( '2026-08-20', Purchase::expected_date( '2026-08-20', 0 ) );
	}

	/**
	 * Ohne Datum wird nichts erfunden.
	 */
	public function test_ohne_bestelldatum_kein_termin() {
		$this->assertSame( '', Purchase::expected_date( '', 5 ) );
	}

	/**
	 * Überfälligkeit zählt nur, solange etwas unterwegs ist.
	 */
	public function test_ueberfaelligkeit_zaehlt_nur_bei_offenen() {
		$offen = $this->bestellung();

		$this->assertSame( 3, Purchase::overdue_days( $offen, '2026-08-28' ) );
		$this->assertSame( -2, Purchase::overdue_days( $offen, '2026-08-23' ) );

		$geliefert = $this->bestellung( array( 'status' => 'geliefert' ) );
		$this->assertNull( Purchase::overdue_days( $geliefert, '2026-08-28' ) );
	}

	/**
	 * Ohne erwarteten Termin gibt es keine Überfälligkeit – geschätzt wird
	 * nicht.
	 */
	public function test_ohne_termin_keine_ueberfaelligkeit() {
		$this->assertNull(
			Purchase::overdue_days( $this->bestellung( array( 'expected_on' => null ) ), '2026-09-30' )
		);
	}

	/**
	 * Was unterwegs ist, zählt je Teil zusammen – Geliefertes nicht mehr.
	 */
	public function test_unterwegs_zaehlt_nur_offene_desselben_teils() {
		$liste = array(
			$this->bestellung( array( 'qty' => 100.0 ) ),
			$this->bestellung( array( 'qty' => 50.0 ) ),
			$this->bestellung( array( 'qty' => 999.0, 'status' => 'geliefert' ) ),
			$this->bestellung( array( 'qty' => 999.0, 'status' => 'storniert' ) ),
			$this->bestellung( array( 'qty' => 999.0, 'item_id' => 8 ) ),
			$this->bestellung( array( 'qty' => 999.0, 'item_type' => 'supply' ) ),
		);

		$this->assertSame( 150.0, Purchase::incoming( $liste, 'component', 7 ) );
		$this->assertSame( 999.0, Purchase::incoming( $liste, 'component', 8 ) );
		$this->assertSame( 0.0, Purchase::incoming( $liste, 'component', 99 ) );
	}

	/**
	 * Der Wert enthält den Versand – sonst rechnet sich eine Kleinbestellung
	 * künstlich günstig.
	 */
	public function test_der_wert_enthaelt_den_versand() {
		$this->assertEqualsWithDelta( 16.90, Purchase::total( $this->bestellung() ), 0.0001 );
	}

	/**
	 * Ohne Menge keine Bestellung.
	 */
	public function test_ohne_menge_keine_bestellung() {
		$result = Purchase::validate( array( 'item_type' => 'component', 'item_id' => 7, 'qty' => 0 ) );

		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Ohne Teil keine Bestellung.
	 */
	public function test_ohne_teil_keine_bestellung() {
		$result = Purchase::validate( array( 'item_type' => 'component', 'item_id' => 0, 'qty' => 5 ) );

		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Ein Liefertermin vor der Bestellung ist ein Tippfehler.
	 */
	public function test_liefertermin_vor_der_bestellung_wird_abgewiesen() {
		$result = Purchase::validate(
			array(
				'item_type'   => 'component',
				'item_id'     => 7,
				'qty'         => 10,
				'ordered_on'  => '2026-08-20',
				'expected_on' => '2026-08-01',
			)
		);

		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Eine gültige Eingabe ergibt eine offene Bestellung.
	 */
	public function test_gueltige_eingabe_ergibt_eine_offene_bestellung() {
		$result = Purchase::validate(
			array(
				'item_type'      => 'component',
				'item_id'        => 7,
				'qty'            => '100',
				'unit_price_net' => '0,12',
				'ordered_on'     => '2026-08-20',
				'expected_on'    => '2026-08-25',
			)
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'bestellt', $result['data']['status'] );
		$this->assertSame( 100.0, $result['data']['qty'] );

		// Komma als Dezimaltrennzeichen ist das, was jemand tippt.
		$this->assertSame( 0.12, $result['data']['unit_price_net'] );
		$this->assertTrue( Purchase::is_open( $result['data'] ) );
	}

	/**
	 * Ein unsinniges Datum fällt auf den Stichtag zurück, statt die
	 * Bestellung zu verwerfen.
	 */
	public function test_unsinniges_datum_faellt_auf_heute_zurueck() {
		$result = Purchase::validate(
			array( 'item_type' => 'component', 'item_id' => 7, 'qty' => 1, 'ordered_on' => 'gestern' ),
			'2026-08-24'
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( '2026-08-24', $result['data']['ordered_on'] );
	}

	/**
	 * Als Bestell-Link sind nur Webadressen erlaubt. Ein
	 * javascript:-Link im Backend wäre eine offene Tür.
	 */
	public function test_nur_webadressen_sind_bestell_links() {
		$this->assertSame( 'https://shop.example/artikel/1', Purchase::clean_url( 'https://shop.example/artikel/1' ) );
		$this->assertSame( 'http://shop.example', Purchase::clean_url( ' http://shop.example ' ) );

		$this->assertSame( '', Purchase::clean_url( 'javascript:alert(1)' ) );
		$this->assertSame( '', Purchase::clean_url( 'data:text/html,<script>' ) );
		$this->assertSame( '', Purchase::clean_url( 'shop.example' ) );
		$this->assertSame( '', Purchase::clean_url( '' ) );
	}

	/**
	 * Markup und Skript müssen dieselben Kennungen benutzen, sonst geht
	 * beim Klick auf „Bestellen" nur der Shop auf und das Formular nicht.
	 */
	public function test_markup_und_skript_passen_zusammen() {
		$seite  = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Admin/ArticlesPage.php' );
		$skript = file_get_contents( dirname( __DIR__ ) . '/assets/js/betrieb-bestellen.js' );

		foreach ( array( 'lw16-bestellung', 'lw16-bestellen', 'lw16-bestellung__titel', 'data-schliessen' ) as $kennung ) {
			$this->assertStringContainsString( $kennung, $seite, $kennung . ' fehlt im Markup.' );
			$this->assertStringContainsString( $kennung, $skript, $kennung . ' fehlt im Skript.' );
		}

		// Die Datenfelder, aus denen das Skript das Formular füllt.
		foreach ( array( 'data-typ', 'data-id', 'data-menge', 'data-preis', 'data-lieferant', 'data-lieferzeit', 'data-name' ) as $attribut ) {
			$this->assertStringContainsString( $attribut, $seite, $attribut . ' fehlt im Markup.' );
			$this->assertStringContainsString( $attribut, $skript, $attribut . ' fehlt im Skript.' );
		}

		// Und die Formularfelder, die es setzt.
		foreach ( array( 'item_type', 'item_id', 'qty', 'unit_price_net', 'supplier', 'ordered_on', 'expected_on', 'order_ref', 'note' ) as $feld ) {
			$this->assertStringContainsString( "'" . $feld . "'", $skript, $feld . ' wird vom Skript nicht gesetzt.' );
			$this->assertStringContainsString( $feld, $seite, $feld . ' fehlt im Formular.' );
		}
	}

	/**
	 * Jeder Zustand hat eine Beschriftung, jede Art auch.
	 */
	public function test_zustaende_und_arten_sind_beschriftet() {
		$this->assertSame( Purchase::STATES, array_keys( Purchase::state_labels() ) );
		$this->assertSame( Purchase::TYPES, array_keys( Purchase::type_labels() ) );
	}
}
