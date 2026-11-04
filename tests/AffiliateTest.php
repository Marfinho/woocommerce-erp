<?php
/**
 * Affiliates: reine Regeln (Code, Eingaben, Provision, Wartezeit, Summen, Auszahlung).
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Affiliate\Rules;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/Modules/Affiliate/Rules.php';

/**
 * Prüft die Regeln ohne Datenbank.
 */
class AffiliateTest extends TestCase {

	public function test_code_wird_gross_und_ohne_leerzeichen() {
		$this->assertSame( 'ANNA10', Rules::normalize_code( ' anna 10 ' ) );
		$this->assertSame( '', Rules::normalize_code( null ) );
	}

	public function test_gueltige_und_ungueltige_codes() {
		$this->assertTrue( Rules::is_valid_code( 'ANNA10' ) );
		$this->assertTrue( Rules::is_valid_code( 'ANNA-RIDES_10' ) );
		$this->assertFalse( Rules::is_valid_code( 'AB' ) );
		$this->assertFalse( Rules::is_valid_code( '-ANNA' ) );
		$this->assertFalse( Rules::is_valid_code( 'MÜLLER10' ) );
		$this->assertFalse( Rules::is_valid_code( str_repeat( 'A', 33 ) ) );
	}

	public function test_zahlen_mit_komma() {
		$this->assertSame( 7.5, Rules::number( '7,5' ) );
		$this->assertSame( 10.0, Rules::number( 10 ) );
		$this->assertNull( Rules::number( 'zehn' ) );
		$this->assertNull( Rules::number( null ) );
	}

	public function test_neuer_partner_wird_bereinigt() {
		$r = Rules::clean_partner(
			array(
				'name'      => ' Anna ',
				'code'      => 'anna10',
				'gesamt'    => '20',
				'rabatt'    => '7,5',
				'aktiv'     => true,
			),
			true
		);

		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( 'Anna', $r['row']['name'] );
		$this->assertSame( 'ANNA10', $r['row']['code'] );
		$this->assertSame( 20.0, $r['row']['gesamt'] );
		$this->assertSame( 7.5, $r['row']['rabatt'] );
		$this->assertSame( 12.5, $r['row']['provision'] );
		$this->assertTrue( $r['row']['teilen'] );
		$this->assertTrue( $r['row']['aktiv'] );
	}

	public function test_fehler_werden_gesammelt() {
		$r = Rules::clean_partner(
			array(
				'name'      => '',
				'email'     => 'kein-mail',
				'code'      => 'x',
				'gesamt'    => '150',
				'rabatt'    => '10',
			),
			true
		);

		$this->assertCount( 4, $r['errors'] );
	}

	public function test_code_wird_beim_aendern_nicht_gelesen() {
		$r = Rules::clean_partner(
			array(
				'name'      => 'Anna',
				'code'      => 'NEUERCODE',
				'gesamt'    => 20,
				'rabatt'    => 10,
			),
			false
		);

		$this->assertSame( array(), $r['errors'] );
		$this->assertArrayNotHasKey( 'code', $r['row'] );
		$this->assertFalse( $r['row']['aktiv'] );
	}

	public function test_aufteilung_summe_bleibt_der_gesamtanteil() {
		foreach ( array( array( 10, 10 ), array( 5, 15 ), array( 0, 20 ), array( 20, 0 ) ) as $pair ) {
			$r = Rules::split( 20, $pair[0] );
			$this->assertSame( array(), $r['errors'] );
			$this->assertSame( 20.0, $r['rabatt'] + $r['provision'] );
			$this->assertSame( (float) $pair[1], $r['provision'] );
		}

		$this->assertSame( 12.5, Rules::split( '20', '7,5' )['provision'] );
	}

	public function test_aufteilung_ablehnen() {
		// Mehr Rabatt als Gesamtanteil, negativ, keine Zahl, ungültiger Gesamtanteil.
		$this->assertNotEmpty( Rules::split( 20, 25 )['errors'] );
		$this->assertNotEmpty( Rules::split( 20, -1 )['errors'] );
		$this->assertNotEmpty( Rules::split( 20, 'viel' )['errors'] );
		$this->assertNotEmpty( Rules::split( 120, 10 )['errors'] );
		$this->assertNotEmpty( Rules::split( null, 0 )['errors'] );
	}

	public function test_provisionssatz_aus_dem_tatsaechlichen_rabatt() {
		// Gesamtanteil 20: Hat der Kunde 10 % bekommen, bleiben 10; bei 5 sind es 15.
		$this->assertSame( 10.0, Rules::rate_from_discount( 20, 10 ) );
		$this->assertSame( 15.0, Rules::rate_from_discount( 20, 5 ) );
		// Nie negativ, auch wenn der Rabatt über dem Gesamtanteil steht.
		$this->assertSame( 0.0, Rules::rate_from_discount( 20, 25 ) );
	}

	public function test_codevorschlag_aus_dem_namen() {
		$this->assertSame( 'ANNA', Rules::suggest_code( 'Anna Müller' ) );
		$this->assertSame( 'MUELLER', Rules::suggest_code( 'Müller Rides' ) );
		$this->assertSame( '', Rules::suggest_code( 'Al' ) );
	}

	public function test_provision_und_bemessungsgrundlage() {
		$this->assertSame( 10.0, Rules::commission( 100, 10 ) );
		$this->assertSame( 8.33, Rules::commission( 83.33, 10 ) );
		$this->assertSame( 0.0, Rules::commission( -5, 10 ) );

		$this->assertSame( 90.0, Rules::base( 100, 10, 0 ) );
		$this->assertSame( 45.0, Rules::base( 100, 10, 45 ) );
		$this->assertSame( 0.0, Rules::base( 100, 10, 200 ) );
	}

	public function test_erstatteter_warenwert_ohne_steuer_und_versand() {
		// Erstattet: 100 Ware netto + 19 Steuer, negativ gespeichert.
		$this->assertSame( 100.0, Rules::refund_goods_net( -119, -19, 0 ) );
		// Dazu 5 Versand netto (Steuer 0,95 darauf steckt in der Steuer).
		$this->assertSame( 100.0, Rules::refund_goods_net( -124.95, -19.95, -5 ) );
	}

	public function test_wartezeit() {
		$now = 1000 * 86400;

		$this->assertTrue( Rules::is_payable( $now - 31 * 86400, $now, 30 ) );
		$this->assertTrue( Rules::is_payable( $now - 30 * 86400, $now, 30 ) );
		$this->assertFalse( Rules::is_payable( $now - 29 * 86400, $now, 30 ) );
		$this->assertTrue( Rules::is_payable( $now, $now, 0 ) );
	}

	public function test_summen_je_partner() {
		$now      = 1000 * 86400;
		$partners = array(
			1 => array( 'code' => 'ANNA10', 'provision' => 10.0 ),
			2 => array( 'code' => 'BEN', 'provision' => 20.0 ),
		);
		$sales    = array(
			// Anna: alt (auszahlbar), 100 € Basis zu 10 % = 10 €.
			array( 'code' => 'anna10', 'ts' => $now - 100 * 86400, 'base' => 100, 'total' => 119 ),
			// Anna: frisch (in Wartezeit), 50 € Basis; Satz von damals 5 % = 2,50 €.
			array( 'code' => 'anna10', 'ts' => $now - 2 * 86400, 'base' => 50, 'total' => 59.5, 'rate' => 5.0 ),
			// Ben: alt, 200 € zu 20 % = 40 €.
			array( 'code' => 'ben', 'ts' => $now - 60 * 86400, 'base' => 200, 'total' => 238 ),
			// Fremder Code zählt nicht.
			array( 'code' => 'anderer', 'ts' => $now, 'base' => 999, 'total' => 999 ),
		);
		$payouts  = array(
			array( 'partner' => 1, 'betrag' => 4.0 ),
		);

		$r = Rules::summarize( $partners, $sales, $payouts, $now, 30, 30 );

		// Anna, Zeitraum 30 Tage: nur die frische Bestellung.
		$this->assertSame( 1, $r[1]['anzahl'] );
		$this->assertSame( 59.5, $r[1]['umsatz'] );
		$this->assertSame( 2.5, $r[1]['provision'] );
		// Anna, insgesamt: 12,50 verdient, 10 auszahlbar, 2,50 wartend, 4 ausgezahlt.
		$this->assertSame( 12.5, $r[1]['gesamt'] );
		$this->assertSame( 10.0, $r[1]['auszahlbar'] );
		$this->assertSame( 2.5, $r[1]['wartend'] );
		$this->assertSame( 6.0, $r[1]['guthaben'] );

		// Ben: nichts im Zeitraum, aber 40 € auszahlbar.
		$this->assertSame( 0, $r[2]['anzahl'] );
		$this->assertSame( 40.0, $r[2]['guthaben'] );

		// Alles: Ben zählt mit.
		$all = Rules::summarize( $partners, $sales, $payouts, $now, 30, 0 );
		$this->assertSame( 1, $all[2]['anzahl'] );
		$this->assertSame( 40.0, $all[2]['provision'] );
	}

	public function test_auszahlung_pruefen() {
		$ok = Rules::check_payout( '10,50', 20 );
		$this->assertSame( array(), $ok['errors'] );
		$this->assertSame( 10.5, $ok['betrag'] );

		$this->assertNotEmpty( Rules::check_payout( '25', 20 )['errors'] );
		$this->assertNotEmpty( Rules::check_payout( '', 20 )['errors'] );
		$this->assertNotEmpty( Rules::check_payout( '0', 20 )['errors'] );

		// Ein Minusbetrag ist keine Korrektur mehr, sondern wird abgelehnt (Stornieren ist der Weg).
		$this->assertNotEmpty( Rules::check_payout( '-5', 20 )['errors'] );
	}

	public function test_stornierte_auszahlung_und_eigenkauf_in_den_summen() {
		$now      = 1000 * 86400;
		$partners = array( 1 => array( 'code' => 'ANNA10', 'provision' => 10.0 ) );
		$sales    = array(
			array( 'code' => 'anna10', 'ts' => $now - 60 * 86400, 'base' => 100, 'total' => 119 ),
			// Eigenkauf: zählt weder für Umsatz noch für Provision.
			array( 'code' => 'anna10', 'ts' => $now - 60 * 86400, 'base' => 500, 'total' => 595, 'eigen' => true ),
		);
		$payouts  = array(
			array( 'partner' => 1, 'betrag' => 6.0 ),
			array( 'partner' => 1, 'betrag' => 4.0, 'storniert' => true ),
		);

		$r = Rules::summarize( $partners, $sales, $payouts, $now, 30, 0 );

		$this->assertSame( 1, $r[1]['anzahl'] );
		$this->assertSame( 1, $r[1]['eigen'] );
		$this->assertSame( 10.0, $r[1]['auszahlbar'] );
		$this->assertSame( 6.0, $r[1]['ausgezahlt'] );
		$this->assertSame( 4.0, $r[1]['guthaben'] );
	}

	public function test_eigenkauf_erkennen() {
		$own = array( 'Anna@Example.com' );

		// Gleiche Adresse, egal wie geschrieben.
		$this->assertTrue( Rules::is_self_purchase( $own, 0, array( ' anna@example.COM ' ), 0 ) );
		// Gleiches Konto, andere Adresse.
		$this->assertTrue( Rules::is_self_purchase( $own, 7, array( 'andere@example.com' ), 7 ) );
		// Fremde Adresse, fremdes Konto, Gast.
		$this->assertFalse( Rules::is_self_purchase( $own, 7, array( 'ben@example.com' ), 8 ) );
		$this->assertFalse( Rules::is_self_purchase( $own, 7, array( '' ), 0 ) );
		// Ohne bekannte Adresse und ohne Konto passt nichts (Gast mit leerer Adresse ist kein Treffer).
		$this->assertFalse( Rules::is_self_purchase( array(), 0, array( '' ), 0 ) );
	}

	public function test_auszahlungsanforderung() {
		$ok = Rules::check_request( 35.5, 20, false, ' paypal@anna.de ' );
		$this->assertSame( array(), $ok['errors'] );
		$this->assertSame( 35.5, $ok['betrag'] );
		$this->assertSame( 'paypal@anna.de', $ok['ziel'] );

		// Unter dem Mindestbetrag, ohne Zahlungsangabe, mit offener Anforderung.
		$this->assertCount( 1, Rules::check_request( 15, 20, false, 'x' )['errors'] );
		$this->assertCount( 1, Rules::check_request( 50, 20, false, '  ' )['errors'] );
		$this->assertCount( 1, Rules::check_request( 50, 20, true, 'x' )['errors'] );
		$this->assertCount( 3, Rules::check_request( 0, 20, true, '' )['errors'] );

		// Mindestbetrag 0 heißt: mindestens ein Cent, nie 0 €.
		$this->assertNotEmpty( Rules::check_request( 0, 0, false, 'x' )['errors'] );
		$this->assertSame( array(), Rules::check_request( 20, 20, false, 'x' )['errors'] );
	}

	public function test_konto_ist_pflicht() {
		// Weder vorhandenes Konto noch Anlegen: Fehler.
		$this->assertCount( 1, Rules::konto_errors( 0, false ) );
		// Vorhandenes Konto oder anzulegendes Konto: in Ordnung.
		$this->assertSame( array(), Rules::konto_errors( 12, false ) );
		$this->assertSame( array(), Rules::konto_errors( 0, true ) );
	}

	public function test_zeitraum() {
		$this->assertSame( 90, Rules::clean_days( null ) );
		$this->assertSame( 90, Rules::clean_days( '7' ) );
		$this->assertSame( 30, Rules::clean_days( '30' ) );
		$this->assertSame( 0, Rules::clean_days( '0' ) );
	}
}
