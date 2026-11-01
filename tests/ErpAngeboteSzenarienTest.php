<?php
/**
 * Szenarien für Angebote, ergänzend zu ErpAngeboteTest: vollständige
 * Statusmatrix, Begrenzung der Rabatte, geleerte Formularfelder und die
 * einzelnen Prüfungen je Position.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Tests\Szenarien;

use Lindenweg16\Erp\Pages\Angebote;
use PHPUnit\Framework\TestCase;

/**
 * Angebote.
 */
class ErpAngeboteSzenarienTest extends TestCase {

	/**
	 * Gültige Eingabe, so wie das Formular sie schickt (Felder als Text).
	 *
	 * @param array $over Abweichungen.
	 * @return array
	 */
	private function input( array $over = array() ) {
		return array_merge(
			array(
				'recipient'   => 'Erika Mustermann',
				'quote_date'  => '2026-03-01',
				'valid_until' => '',
				'items'       => array(
					array( 'title' => 'Regalhalter', 'qty' => '2', 'unit_price_gross' => '12,50', 'discount_pct' => '0' ),
				),
			),
			$over
		);
	}

	public function test_entwurf_laeuft_ab_ohne_datum_nie() {
		$this->assertSame( 'abgelaufen', Angebote::shown_status( 'entwurf', '2026-03-31', '2026-04-01' ) );
		$this->assertSame( 'versendet', Angebote::shown_status( 'versendet', '', '2030-01-01' ), 'Ohne Datum kein Ablauf.' );
		$this->assertSame( 'abgelehnt', Angebote::shown_status( 'abgelehnt', '2026-03-31', '2027-01-01' ) );
	}

	public function test_statuswechsel_vollstaendig() {
		$allowed = array(
			array( 'entwurf', 'versendet' ),
			array( 'entwurf', 'abgelehnt' ),
			array( 'versendet', 'angenommen' ),
			array( 'versendet', 'abgelehnt' ),
			array( 'versendet', 'entwurf' ),
			array( 'abgelehnt', 'entwurf' ),
		);
		foreach ( $allowed as $pair ) {
			$this->assertTrue( Angebote::can_switch( $pair[0], $pair[1] ), implode( ' → ', $pair ) );
		}

		$denied = array(
			array( 'entwurf', 'angenommen' ),
			array( 'angenommen', 'abgelehnt' ),
			array( 'abgelehnt', 'angenommen' ),
			array( 'versendet', 'versendet' ),
			array( 'abgelaufen', 'entwurf' ),
			array( 'unbekannt', 'entwurf' ),
		);
		foreach ( $denied as $pair ) {
			$this->assertFalse( Angebote::can_switch( $pair[0], $pair[1] ), implode( ' → ', $pair ) );
		}
	}

	public function test_rabatte_werden_auf_0_bis_100_prozent_begrenzt() {
		$t = Angebote::totals(
			array(
				array( 'qty' => 1, 'unit_price_gross' => 50, 'discount_pct' => 150 ),
				array( 'qty' => 1, 'unit_price_gross' => 50, 'discount_pct' => -20 ),
			),
			-10,
			-5,
			false
		);

		$this->assertSame( array( 0.0, 50.0 ), $t['lines'] );
		$this->assertSame( 0.0, $t['discount'] );
		$this->assertSame( 0.0, $t['shipping'], 'Negativer Versand zählt nicht.' );
		$this->assertSame( 50.0, $t['total'] );
	}

	public function test_leeres_angebot_kostet_nichts() {
		$t = Angebote::totals( array(), 0, 0, false );
		$this->assertSame( 0.0, $t['total'] );
		$this->assertSame( 0.0, $t['vat'] );
	}

	public function test_deutsche_datumsangaben_im_formular() {
		$res = Angebote::validate( $this->input( array( 'quote_date' => '01.03.2026', 'valid_until' => '15.4.26' ) ), '2026-03-15' );
		$this->assertSame( array(), $res['errors'] );
		$this->assertSame( '2026-03-01', $res['data']['quote_date'] );
		$this->assertSame( '2026-04-15', $res['data']['valid_until'] );
	}

	public function test_nur_leere_zeilen_ergibt_mindestens_eine_position() {
		$res = Angebote::validate(
			$this->input( array( 'items' => array( array( 'title' => '', 'qty' => '', 'unit_price_gross' => '' ), array() ) ) ),
			'2026-03-15'
		);

		$this->assertSame( array(), $res['items'] );
		$this->assertCount( 1, $res['errors'] );
		$this->assertStringContainsString( 'mindestens eine Position', $res['errors'][0] );
	}

	public function test_fehler_je_position_mit_positionsnummer() {
		$res = Angebote::validate(
			$this->input(
				array(
					'items' => array(
						array( 'title' => 'Ok', 'qty' => '1', 'unit_price_gross' => '1' ),
						array( 'title' => '', 'qty' => '0', 'unit_price_gross' => '-1', 'discount_pct' => '120' ),
					),
				)
			),
			'2026-03-15'
		);

		$this->assertCount( 4, $res['errors'] );
		foreach ( $res['errors'] as $e ) {
			$this->assertStringContainsString( 'Position 2', $e );
		}
	}

	public function test_mengen_grenzen_und_unlesbare_zahlen() {
		$big = Angebote::validate( $this->input( array( 'items' => array( array( 'title' => 'X', 'qty' => '100001', 'unit_price_gross' => '1' ) ) ) ), '2026-03-15' );
		$this->assertCount( 1, $big['errors'] );

		$nan = Angebote::validate( $this->input( array( 'items' => array( array( 'title' => 'X', 'qty' => 'zwei', 'unit_price_gross' => 'gratis' ) ) ) ), '2026-03-15' );
		$this->assertCount( 2, $nan['errors'] );
		$this->assertSame( 0, $nan['items'][0]['qty'] );
	}

	public function test_gesamtrabatt_und_versand_pruefen() {
		$res = Angebote::validate( $this->input( array( 'discount_pct' => '101', 'shipping_gross' => '-4,90' ) ), '2026-03-15' );
		$this->assertCount( 2, $res['errors'] );

		$ok = Angebote::validate( $this->input( array( 'discount_pct' => '2,5', 'shipping_gross' => '4,90' ) ), '2026-03-15' );
		$this->assertSame( 2.5, $ok['data']['discount_pct'] );
		$this->assertSame( 4.9, $ok['data']['shipping_gross'] );
	}

	public function test_geleerte_felder_fuer_rabatt_und_versand_bedeuten_null() {
		// Das Formular schickt den Inhalt der Felder – ein geleertes Feld kommt als ''.
		$res = Angebote::validate(
			$this->input(
				array(
					'discount_pct'   => '',
					'shipping_gross' => '',
					'items'          => array( array( 'title' => 'Halter', 'qty' => '1', 'unit_price_gross' => '5', 'discount_pct' => '' ) ),
				)
			),
			'2026-03-15'
		);

		$this->assertSame( array(), $res['errors'] );
		$this->assertSame( 0.0, (float) $res['data']['discount_pct'] );
		$this->assertSame( 0.0, (float) $res['data']['shipping_gross'] );
		$this->assertSame( 0.0, (float) $res['items'][0]['discount_pct'] );
	}

	public function test_kontakt_und_farbe_ohne_sonderzeichen() {
		$res = Angebote::validate(
			$this->input( array( 'contact_id' => '7', 'items' => array( array( 'title' => 'Halter', 'color' => 'Anthrazit <b>', 'qty' => 1, 'unit_price_gross' => 1 ) ) ) ),
			'2026-03-15'
		);

		$this->assertSame( 'anthrazitb', $res['items'][0]['color'] );
		$this->assertSame( 7, $res['data']['contact_id'] );
		$this->assertNull( $res['items'][0]['article_id'], 'Freie Position ohne Artikel.' );
	}

	public function test_mailtext_ohne_empfaengernamen() {
		$mail = Angebote::mail_text( array( 'recipient' => '', 'number' => 'AN-2026-0003', 'valid_until' => '2026-04-30' ), 'Lindenweg16' );

		$this->assertSame( 'Ihr Angebot AN-2026-0003 von Lindenweg16', $mail['subject'] );
		$this->assertStringContainsString( 'Guten Tag zusammen,', $mail['body'] );
	}
}
