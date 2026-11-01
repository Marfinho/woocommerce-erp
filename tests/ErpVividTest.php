<?php
/**
 * Vivid Business API: Schlüssel, Umwandlung, Abrufabschnitt, Fehlertexte.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Vivid;
use PHPUnit\Framework\TestCase;

class ErpVividTest extends TestCase {

	private function tx( array $over = array() ) {
		return array_merge(
			array(
				'id'                     => 'tx_01HZY',
				'group_id'               => 'g1',
				'account_id'             => 'acc_1',
				'account_type'           => 'current',
				'type'                   => 'card_payment',
				'state'                  => 'completed',
				'amount'                 => '-12.50',
				'currency'               => 'EUR',
				'original_amount'        => null,
				'original_currency'      => null,
				'show_at'                => '2026-10-30T22:15:00Z',
				'created_at'             => '2026-10-30T22:15:03.120Z',
				'updated_at'             => '2026-10-31T08:00:00Z',
				'merchant'               => array( 'name' => 'BAMBULAB EU', 'category_code' => '5999', 'city' => 'Shenzhen', 'country' => 'CN' ),
				'card'                   => array( 'id' => 'c1', 'last_4' => '4242' ),
				'network_transaction_id' => null,
			),
			$over
		);
	}

	public function test_schluessel_erkennen() {
		$this->assertTrue( Vivid::looks_like_key( 'vivid_sk_v1_AbCdEf0123456789xyz' ) );
		$this->assertFalse( Vivid::looks_like_key( 'Bearer vivid_sk_v1_AbCdEf0123456789xyz' ) );
		$this->assertFalse( Vivid::looks_like_key( 'vivid_sk_v1_kurz' ) );
	}

	public function test_kartenzahlung_wird_zeile() {
		$r = Vivid::to_row( $this->tx() );

		$this->assertSame( 'tx_01HZY', $r['external_id'] );
		$this->assertSame( sha1( 'vivid|tx_01HZY' ), $r['fingerprint'] );
		$this->assertSame( '2026-10-30', $r['booking_date'] );
		$this->assertSame( -12.5, $r['amount'] );
		$this->assertSame( 'BAMBULAB EU', $r['counterparty'] );
		$this->assertSame( 'Kartenzahlung · Shenzhen CN · •••• 4242', $r['purpose'] );
		$this->assertSame( 'completed', $r['ext_state'] );
		$this->assertSame( '4242', $r['card_last4'] );
		$this->assertSame( '2026-10-31 08:00:00', $r['ext_updated_at'] );
	}

	public function test_ueberweisung_ohne_haendler_und_unbekannte_werte() {
		$r = Vivid::to_row( $this->tx( array( 'type' => 'transfer', 'merchant' => null, 'card' => null, 'amount' => '149.90', 'state' => 'neu_erfunden' ) ) );
		$this->assertSame( '', $r['counterparty'] );
		$this->assertSame( 'Überweisung', $r['purpose'] );
		$this->assertSame( 'other', $r['ext_state'], 'Unbekannter Zustand gilt als other' );

		$this->assertSame( 'Umsatz · 15.00 USD', Vivid::to_row( $this->tx( array( 'type' => 'xyz', 'merchant' => null, 'card' => null, 'original_amount' => '15.00', 'original_currency' => 'USD' ) ) )['purpose'] );
		$this->assertNull( Vivid::to_row( array( 'id' => '', 'amount' => '1.00' ) ) );
		$this->assertNull( Vivid::to_row( $this->tx( array( 'amount' => 'abc' ) ) ) );
	}

	public function test_abrufabschnitt() {
		$now = strtotime( '2026-11-01 12:00:00 UTC' );
		$this->assertSame( '', Vivid::updated_from( '', $now ), 'Erster Abruf: alles' );
		$this->assertSame( '2026-10-18T12:00:00Z', Vivid::updated_from( '2026-11-01 12:00:00', $now ), '14 Tage zurück' );
		$this->assertSame( '', Vivid::updated_from( '2026-06-01 12:00:00', $now ), 'Lange nicht abgerufen: alles' );
	}

	public function test_konto() {
		$a = Vivid::account_row(
			array(
				'id'              => 'acc_1',
				'name'            => '',
				'currency'        => 'EUR',
				'state'           => 'active',
				'balance'         => array( 'available' => '1234.56', 'pending' => null ),
				'account_details' => array( array( 'iban' => 'DE02 1203 0000 0000 2020 51', 'bic' => 'BYLADEM1001', 'holder_name' => 'X', 'bank_name' => 'Y', 'type' => 'local' ) ),
				'created_at'      => null,
			)
		);
		$this->assertSame( 'Vivid-Konto', $a['name'] );
		$this->assertSame( 'DE02120300000000202051', $a['iban'] );
		$this->assertSame( 1234.56, $a['balance_available'] );
		$this->assertNull( $a['balance_pending'] );
		$this->assertNull( Vivid::account_row( array( 'id' => 'a', 'name' => 'n', 'currency' => 'EUR', 'state' => 'active', 'balance' => null, 'account_details' => array() ) )['balance_available'], 'Saldo nicht sichtbar' );
	}

	public function test_fehlertexte() {
		$this->assertStringContainsString( '180 Tage', Vivid::error_text( 401, 'key_expired' ) );
		$this->assertStringContainsString( 'IP-Adresse', Vivid::error_text( 401, 'address_not_allowed' ) );
		$this->assertStringContainsString( 'nicht erreichbar', Vivid::error_text( 503, 'internal_error' ) );
		$this->assertStringContainsString( '418', Vivid::error_text( 418, 'teapot' ) );
	}
}
