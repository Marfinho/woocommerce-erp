<?php
/**
 * Verkauf im ERP: reine Regeln der Kontakte-Akte, Shop-Liste, Loyalty und Marketing.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Verkauf;
use PHPUnit\Framework\TestCase;

class ErpVerkaufTest extends TestCase {

	private function contact( array $over = array() ) {
		return array_merge(
			array(
				'company'        => 'Spielwaren Müller',
				'city'           => 'Hamburg',
				'zip'            => '20095',
				'contact_person' => 'Anna Schmidt',
				'email'          => 'info@mueller.example',
				'phone'          => '040 123',
				'type'           => 'haendler',
				'tier'           => array( 'kind' => 'standard' ),
			),
			$over
		);
	}

	public function test_zahlen_mit_komma_und_punkt() {
		$this->assertSame( 12.5, Verkauf::number( '12,5' ) );
		$this->assertSame( 7.0, Verkauf::number( ' 7 ' ) );
		$this->assertNull( Verkauf::number( '' ) );
		$this->assertNull( Verkauf::number( 'zehn' ) );
	}

	public function test_datum_muss_es_geben() {
		$this->assertTrue( Verkauf::is_date( '2026-02-28' ) );
		$this->assertFalse( Verkauf::is_date( '2026-02-30' ) );
		$this->assertFalse( Verkauf::is_date( '28.02.2026' ) );
		$this->assertFalse( Verkauf::is_date( '' ) );
	}

	public function test_kontakt_ohne_namen_wird_abgelehnt() {
		$errors = Verkauf::contact_errors( array( 'company' => '   ' ) );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'Pflicht', $errors[0] );
	}

	public function test_kontakt_mit_falscher_mail_oder_datum() {
		$this->assertCount( 1, Verkauf::contact_errors( array( 'company' => 'X', 'email' => 'keine-mail' ) ) );
		$this->assertCount( 1, Verkauf::contact_errors( array( 'company' => 'X', 'follow_up_on' => '31.12.2026' ) ) );
		$this->assertSame( array(), Verkauf::contact_errors( array( 'company' => 'X', 'email' => '', 'follow_up_on' => '', 'type' => 'verein', 'status' => 'kunde' ) ) );
	}

	public function test_unbekannter_typ_und_status() {
		$this->assertCount( 2, Verkauf::contact_errors( array( 'company' => 'X', 'type' => 'alien', 'status' => 'tot' ) ) );
	}

	public function test_kondition_pruefen() {
		$this->assertSame( array(), Verkauf::term_errors( array( 'min_order_value' => '250,50', 'discount_pct' => '12,5', 'payment_terms_days' => '14', 'valid_from' => '2026-01-01' ) ) );
		$this->assertCount( 1, Verkauf::term_errors( array( 'discount_pct' => '100' ) ) );
		$this->assertCount( 1, Verkauf::term_errors( array( 'discount_pct' => '-1' ) ) );
		$this->assertCount( 1, Verkauf::term_errors( array( 'min_order_value' => '-5' ) ) );
		$this->assertCount( 1, Verkauf::term_errors( array( 'payment_terms_days' => '400' ) ) );
		$this->assertCount( 1, Verkauf::term_errors( array( 'discount_pct' => 'viel' ) ) );
		$this->assertCount( 1, Verkauf::term_errors( array( 'valid_from' => 'gestern' ) ) );
	}

	public function test_rabattstufe_standard_und_sonder() {
		$this->assertSame( 'standard', Verkauf::contact_tier( array() )['kind'] );

		$one = Verkauf::contact_tier( array( array( 'discount_pct' => '10.000' ) ) );
		$this->assertSame( 'sonder', $one['kind'] );
		$this->assertStringContainsString( '10 %', $one['label'] );

		$many = Verkauf::contact_tier( array( array( 'discount_pct' => '10' ), array( 'discount_pct' => '17.5' ) ) );
		$this->assertStringContainsString( '10–17,5 %', $many['label'] );
		$this->assertSame( 17.5, $many['max'] );
	}

	public function test_suche_findet_firma_ort_und_ignoriert_umlaute() {
		$rows = array( $this->contact(), $this->contact( array( 'company' => 'Bastelwelt', 'city' => 'Köln', 'type' => 'firma', 'email' => '' ) ) );

		$this->assertCount( 1, Verkauf::filter_contacts( $rows, 'MULLER', '', '' ) );
		$this->assertCount( 1, Verkauf::filter_contacts( $rows, 'koln', '', '' ) );
		$this->assertCount( 2, Verkauf::filter_contacts( $rows, '', '', '' ) );
		$this->assertCount( 0, Verkauf::filter_contacts( $rows, 'nichts', '', '' ) );
	}

	public function test_filter_nach_typ_und_rabattstufe() {
		$rows = array(
			$this->contact(),
			$this->contact( array( 'company' => 'Verein', 'type' => 'verein', 'tier' => array( 'kind' => 'sonder' ) ) ),
		);

		$this->assertSame( 'Verein', Verkauf::filter_contacts( $rows, '', 'verein', '' )[0]['company'] );
		$this->assertSame( 'Verein', Verkauf::filter_contacts( $rows, '', '', 'sonder' )[0]['company'] );
		$this->assertCount( 0, Verkauf::filter_contacts( $rows, '', 'verein', 'standard' ) );
	}

	public function test_kennzahlen_je_kontakt() {
		$stats = Verkauf::stats_by_contact(
			array(
				array( 'contact_id' => 5, 'status' => 'bestaetigt', 'invoice' => 'berechnet', 'value' => 100.0 ),
				array( 'contact_id' => 5, 'status' => 'bestaetigt', 'invoice' => 'bezahlt', 'value' => 50.0 ),
				array( 'contact_id' => 5, 'status' => 'entwurf', 'invoice' => 'offen', 'value' => 999.0 ),
				array( 'contact_id' => 5, 'status' => 'storniert', 'invoice' => 'storniert', 'value' => 70.0 ),
				array( 'contact_id' => 0, 'status' => 'bestaetigt', 'invoice' => 'offen', 'value' => 1.0 ),
			)
		);

		$this->assertSame( array( 5 ), array_keys( $stats ) );
		$this->assertSame( 150.0, $stats[5]['revenue'] );
		$this->assertSame( 4, $stats[5]['orders'] );
		$this->assertSame( 100.0, $stats[5]['open'] );
	}

	public function test_offener_posten_nur_bestaetigt_und_unbezahlt() {
		$this->assertTrue( Verkauf::is_open_invoice( 'bestaetigt', 'offen' ) );
		$this->assertTrue( Verkauf::is_open_invoice( 'bestaetigt', 'berechnet' ) );
		$this->assertFalse( Verkauf::is_open_invoice( 'bestaetigt', 'bezahlt' ) );
		$this->assertFalse( Verkauf::is_open_invoice( 'entwurf', 'offen' ) );
	}

	public function test_faelligkeit() {
		$this->assertSame( '2026-03-15', Verkauf::due_date( '2026-03-01', 14 ) );
		$this->assertSame( '2026-03-01', Verkauf::due_date( '2026-03-01 08:00:00', 0 ) );
		$this->assertSame( '', Verkauf::due_date( '', 14 ) );
		$this->assertSame( '', Verkauf::due_date( '0000-00-00', 14 ) );
	}

	public function test_kommissionsalter() {
		$this->assertSame( 'gruen', Verkauf::commission_age( 90 ) );
		$this->assertSame( 'gelb', Verkauf::commission_age( 91 ) );
		$this->assertSame( 'gelb', Verkauf::commission_age( 180 ) );
		$this->assertSame( 'rot', Verkauf::commission_age( 181 ) );
	}

	public function test_zahlstatus_der_shop_bestellung() {
		$this->assertSame( 'paid', Verkauf::pay_state( 'processing', true, 20.0, 0.0 )['key'] );
		$this->assertSame( 'paid', Verkauf::pay_state( 'wc-completed', false, 20.0, 0.0 )['key'] );
		$this->assertSame( 'open', Verkauf::pay_state( 'pending', false, 20.0, 0.0 )['key'] );
		$this->assertSame( 'open', Verkauf::pay_state( 'on-hold', false, 20.0, 0.0 )['key'] );
		$this->assertSame( 'failed', Verkauf::pay_state( 'failed', false, 20.0, 0.0 )['key'] );
		$this->assertSame( 'cancelled', Verkauf::pay_state( 'cancelled', false, 20.0, 0.0 )['key'] );
		$this->assertSame( 'refunded', Verkauf::pay_state( 'refunded', true, 20.0, 20.0 )['key'] );
		$this->assertSame( 'refunded', Verkauf::pay_state( 'completed', true, 20.0, 20.0 )['key'] );
		$this->assertSame( 'part_refunded', Verkauf::pay_state( 'completed', true, 20.0, 5.0 )['key'] );
		$this->assertSame( 'draft', Verkauf::pay_state( 'checkout-draft', false, 0.0, 0.0 )['key'] );
	}

	public function test_nullsumme_ist_nicht_erstattet() {
		$this->assertSame( 'paid', Verkauf::pay_state( 'completed', true, 0.0, 0.0 )['key'] );
	}

	public function test_statusfilter_nur_bekannte() {
		$known = array( 'wc-processing', 'wc-completed' );

		$this->assertSame( 'wc-processing', Verkauf::shop_status( 'processing', $known ) );
		$this->assertSame( 'wc-completed', Verkauf::shop_status( 'WC-completed', $known ) );
		$this->assertSame( '', Verkauf::shop_status( 'quatsch', $known ) );
		$this->assertSame( '', Verkauf::shop_status( '', $known ) );
	}

	public function test_nur_offene_bestellungen_warten_auf_den_versand() {
		$this->assertTrue( Verkauf::awaits_shipping( 'processing' ) );
		$this->assertTrue( Verkauf::awaits_shipping( 'wc-on-hold' ) );
		$this->assertFalse( Verkauf::awaits_shipping( 'completed' ) );
		$this->assertFalse( Verkauf::awaits_shipping( 'pending' ) );
	}

	public function test_pruefentscheidung_nur_aus_der_pruefung() {
		$approve = Verkauf::referral_decision( 'approve', 'review' );
		$this->assertTrue( $approve['ok'] );
		$this->assertSame( 'pending', $approve['to'] );
		$this->assertSame( 'referral_freigegeben', $approve['action'] );

		$reject = Verkauf::referral_decision( 'reject', 'review' );
		$this->assertSame( 'reversed', $reject['to'] );
		$this->assertSame( 'referral_abgelehnt', $reject['action'] );

		$this->assertFalse( Verkauf::referral_decision( 'approve', 'completed' )['ok'] );
		$this->assertFalse( Verkauf::referral_decision( 'maybe', 'review' )['ok'] );
	}

	public function test_kampagnenzeitraum() {
		$this->assertSame( '2026-05-01', Verkauf::campaign_period( array( 'event_datum' => '2026-05-01', 'start_datum' => '2026-04-01' ) ) );
		$this->assertSame( '2026-04-01 – 2026-04-30', Verkauf::campaign_period( array( 'start_datum' => '2026-04-01', 'ende_datum' => '2026-04-30' ) ) );
		$this->assertSame( '2026-04-01', Verkauf::campaign_period( array( 'start_datum' => '2026-04-01' ) ) );
	}

	public function test_bezeichnungen_fallen_auf_den_schluessel_zurueck() {
		$this->assertSame( 'bestätigt', Verkauf::order_status_label( 'bestaetigt' ) );
		$this->assertSame( 'unbekannt', Verkauf::order_status_label( 'unbekannt' ) );
		$this->assertSame( 'Rechnung offen', Verkauf::invoice_label( 'berechnet' ) );
		$this->assertSame( 'Sonstiges', Verkauf::campaign_type_label( 'sonstiges' ) );
		$this->assertStringContainsString( 'Punkte gesammelt', Verkauf::ledger_label( 'earned', 'pending' ) );
	}

	public function test_kommissionszeilen_bereinigen() {
		$rows = Verkauf::commission_rows(
			array(
				array( 'article_id' => 7, 'color' => 'weiss', 'qty' => ' 3 ' ),
				array( 'article_id' => 8, 'qty' => '' ),
				array( 'article_id' => 0, 'qty' => '2' ),
				'kein Array',
				array( 'article_id' => '9', 'color' => array( 'x' ), 'qty' => '1,5' ),
			)
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'article_id' => 7, 'color' => 'weiss', 'qty' => '3' ), $rows[0] );
		$this->assertSame( '', $rows[1]['color'] );
		$this->assertSame( '1,5', $rows[1]['qty'] );
		$this->assertSame( array(), Verkauf::commission_rows( 'x' ) );
	}

	public function test_kommission_buchen_verlangt_das_lagerrecht() {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Pages/Verkauf.php' );

		$this->assertStringContainsString( "Access::can( 'lager.buchen' )", $src );
		$this->assertStringContainsString( 'CommissionRepository::book_batch', $src );
	}
}
