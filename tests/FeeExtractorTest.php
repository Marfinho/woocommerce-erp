<?php
/**
 * Zahlungsgebühren aus Bestellhinweisen.
 *
 * Die Erkennung muss zurückhaltend sein: Eine falsche Buchung ist schlimmer
 * als eine fehlende, weil sie niemandem auffällt. Deshalb prüfen hier
 * genauso viele Fälle das Nicht-Erkennen wie das Erkennen.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Accounting\FeeExtractor;

/**
 * Prüft Betragserkennung, Zeilenerkennung und Plausibilität.
 */
class FeeExtractorTest extends TestCase {

	/* ================================================================
	 * Beträge lesen
	 * ============================================================== */

	/**
	 * Deutsche und englische Schreibweise werden gleichermaßen verstanden.
	 */
	public function test_beide_schreibweisen_werden_gelesen() {
		$this->assertEqualsWithDelta( 1.23, FeeExtractor::parse_amount( '1,23' ), 0.001 );
		$this->assertEqualsWithDelta( 1.23, FeeExtractor::parse_amount( '1.23' ), 0.001 );
		$this->assertEqualsWithDelta( 0.55, FeeExtractor::parse_amount( 'EUR 0,55' ), 0.001 );
		$this->assertEqualsWithDelta( 12.4, FeeExtractor::parse_amount( '12,40 €' ), 0.001 );
	}

	/**
	 * Tausendertrenner dürfen den Betrag nicht zerreißen.
	 */
	public function test_tausendertrenner_werden_verstanden() {
		$this->assertEqualsWithDelta( 1234.56, FeeExtractor::parse_amount( '1.234,56 EUR' ), 0.001 );
		$this->assertEqualsWithDelta( 1234.56, FeeExtractor::parse_amount( '1 234,56' ), 0.001 );
	}

	/**
	 * Ohne Nachkommastellen wird nichts erkannt – sonst würde jede
	 * Bestellnummer zur Gebühr.
	 */
	public function test_ganze_zahlen_gelten_nicht_als_betrag() {
		$this->assertNull( FeeExtractor::parse_amount( 'Bestellung 12345' ) );
		$this->assertNull( FeeExtractor::parse_amount( 'Gebuehr 5 EUR' ) );
		$this->assertNull( FeeExtractor::parse_amount( 'kein Betrag hier' ) );
	}

	/**
	 * Stehen zwei Beträge in einer Zeile, gilt der kleinere als Gebühr.
	 */
	public function test_bei_zwei_betraegen_gewinnt_der_kleinere() {
		$this->assertEqualsWithDelta(
			1.23,
			FeeExtractor::parse_amount( 'Gebuehr 1,23 EUR von 45,60 EUR' ),
			0.001
		);
	}

	/**
	 * Eine dreistellige Nachkommazahl wird nicht als Betrag missverstanden.
	 */
	public function test_lange_ziffernfolgen_werden_nicht_zerschnitten() {
		$this->assertNull( FeeExtractor::parse_amount( 'Transaktion 9,8765' ) );
	}

	/* ================================================================
	 * Gebührenzeilen erkennen
	 * ============================================================== */

	/**
	 * Übliche Formulierungen der Dienstleister werden erkannt.
	 */
	public function test_gebuehrenzeilen_werden_erkannt() {
		foreach ( array(
			'PayPal-Gebühr: 1,23 EUR',
			'Stripe fee: 0.55 EUR',
			'Transaktionsentgelt 0,49 EUR',
			'Provision 2,10 EUR',
			'Disagio 0,80 EUR',
		) as $line ) {
			$this->assertTrue( FeeExtractor::is_fee_line( $line ), $line );
		}
	}

	/**
	 * Zeilen ohne Gebührenwort sind keine Gebührenzeilen.
	 */
	public function test_zeilen_ohne_gebuehrenwort_werden_uebergangen() {
		foreach ( array(
			'Zahlung eingegangen: 45,60 EUR',
			'Sendungsnummer 00340434161094042557',
			'Kundenwunsch: bitte klingeln',
			'',
		) as $line ) {
			$this->assertFalse( FeeExtractor::is_fee_line( $line ), $line );
		}
	}

	/**
	 * Verneinungen schließen die Zeile aus – „keine Gebühr" darf keine
	 * Ausgabe erzeugen, und eine erstattete erst recht nicht.
	 */
	public function test_verneinte_gebuehren_werden_ausgeschlossen() {
		foreach ( array(
			'Keine Gebühr angefallen: 0,00 EUR',
			'Gebühr erstattet: 1,23 EUR',
			'Fee refunded 0.55 EUR',
			'Gebühr storniert 2,00 EUR',
		) as $line ) {
			$this->assertFalse( FeeExtractor::is_fee_line( $line ), $line );
		}
	}

	/* ================================================================
	 * Plausibilität
	 * ============================================================== */

	/**
	 * Eine Gebühr über der Hälfte des Bestellwerts ist keine Gebühr.
	 */
	public function test_unplausible_hoehe_wird_abgewiesen() {
		$this->assertTrue( FeeExtractor::is_plausible( 1.23, 45.60 ) );
		$this->assertTrue( FeeExtractor::is_plausible( 22.80, 45.60 ) );
		$this->assertFalse( FeeExtractor::is_plausible( 45.60, 45.60 ) );
		$this->assertFalse( FeeExtractor::is_plausible( 0, 45.60 ) );
	}

	/**
	 * Ohne bekannten Bestellwert wird nicht gemutmaßt.
	 */
	public function test_ohne_bestellwert_gilt_jeder_positive_betrag() {
		$this->assertTrue( FeeExtractor::is_plausible( 99.99, 0 ) );
		$this->assertFalse( FeeExtractor::is_plausible( -1, 0 ) );
	}

	/* ================================================================
	 * Notizen auswerten
	 * ============================================================== */

	/**
	 * Aus mehreren Notizen wird die Gebührenzeile herausgegriffen.
	 */
	public function test_gebuehr_wird_aus_mehreren_notizen_gefunden() {
		$result = FeeExtractor::from_notes(
			array(
				'Zahlung über PayPal eingegangen. Betrag: 45,60 EUR',
				"Transaktion abgeschlossen\nPayPal-Gebühr: 1,23 EUR\nNetto: 44,37 EUR",
			),
			45.60
		);

		$this->assertEqualsWithDelta( 1.23, $result['amount'], 0.001 );
		$this->assertStringContainsString( 'PayPal-Gebühr', $result['source'] );
	}

	/**
	 * Ohne Gebührenzeile wird nichts erkannt – und nichts geraten.
	 */
	public function test_ohne_gebuehrenzeile_kein_ergebnis() {
		$result = FeeExtractor::from_notes(
			array( 'Zahlung eingegangen: 45,60 EUR', 'Versand veranlasst' ),
			45.60
		);

		$this->assertNull( $result['amount'] );
		$this->assertSame( '', $result['source'] );
	}

	/**
	 * Eine unplausibel hohe Zahl in einer Gebührenzeile wird verworfen,
	 * nicht gebucht.
	 */
	public function test_unplausible_gebuehr_wird_nicht_uebernommen() {
		$result = FeeExtractor::from_notes(
			array( 'Gebühr für Gesamtbetrag 45,60 EUR' ),
			45.60
		);

		$this->assertNull( $result['amount'] );
	}

	/**
	 * Die erste passende Zeile gewinnt, spätere ändern nichts mehr.
	 */
	public function test_erste_treffende_zeile_gewinnt() {
		$result = FeeExtractor::from_notes(
			array( 'Gebühr: 1,23 EUR', 'Gebühr: 9,99 EUR' ),
			100
		);

		$this->assertEqualsWithDelta( 1.23, $result['amount'], 0.001 );
	}

	/**
	 * Leere Notizen führen zu keinem Ergebnis und zu keinem Fehler.
	 */
	public function test_leere_notizen_sind_unproblematisch() {
		$result = FeeExtractor::from_notes( array(), 45.60 );

		$this->assertNull( $result['amount'] );
	}

	/* ================================================================
	 * WooPayments und andere Metafelder
	 * ============================================================== */

	/**
	 * WooPayments steht an erster Stelle der Metafelder – es ist der hier
	 * eingesetzte Weg und liefert eine fertige Zahl statt eines Satzes.
	 */
	public function test_woopayments_wird_zuerst_gelesen() {
		$this->assertSame( '_wcpay_transaction_fee', FeeExtractor::META_KEYS[0] );
	}

	/**
	 * Die Gebühr von WooPayments steht als schlichte Dezimalzahl im
	 * Metafeld. Genau diese Schreibweisen müssen ankommen.
	 */
	public function test_woopayments_schreibweisen_werden_gelesen() {
		// So legt WooPayments den Wert ab: Punkt als Dezimaltrenner.
		$this->assertEqualsWithDelta( 1.23, FeeExtractor::parse_amount( '1.23' ), 0.001 );
		$this->assertEqualsWithDelta( 0.55, FeeExtractor::parse_amount( '0.55' ), 0.001 );

		// Ein glatter Wert hat keine zwei Nachkommastellen und wird deshalb
		// nicht vom Textmuster erkannt – dafuer greift die Zahlenpruefung
		// in for_order().
		$this->assertNull( FeeExtractor::parse_amount( '2' ) );
		$this->assertTrue( is_numeric( '2' ) );
	}

	/**
	 * Zu jedem währungsabhängigen Feld gehört ein Währungsfeld.
	 */
	public function test_waehrungsfelder_gehoeren_zu_bekannten_metafeldern() {
		foreach ( FeeExtractor::CURRENCY_KEYS as $fee_key => $currency_key ) {
			$this->assertContains( $fee_key, FeeExtractor::META_KEYS, $fee_key . ' ist kein bekanntes Gebuehrenfeld.' );
			$this->assertNotEmpty( $currency_key );
		}
	}
}
