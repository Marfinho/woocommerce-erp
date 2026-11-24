<?php
/**
 * Prüft, welche Gründe einer abgelehnten Stornierung angezeigt werden.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\Dhl\DhlResponse;
use PHPUnit\Framework\TestCase;

/**
 * Storno-Gründe-Tests.
 */
class BetriebDhlVoidTest extends TestCase {

	public function test_grund_aus_dem_status_der_sendung_wird_genannt() {
		$body = array(
			'status' => array( 'title' => 'Bad Request', 'statusCode' => 400, 'detail' => '0 von 1 Sendung erfolgreich storniert.' ),
			'items'  => array(
				array( 'shipmentNo' => '123', 'sstatus' => array( 'title' => 'Sendung bereits abgeschlossen', 'statusCode' => 400, 'detail' => 'Die Sendung wurde schon manifestiert.' ) ),
			),
		);

		$reasons = DhlResponse::void_reasons( $body );

		$this->assertContains( '0 von 1 Sendung erfolgreich storniert.', $reasons );
		$this->assertContains( 'Die Sendung wurde schon manifestiert.', $reasons );
	}

	public function test_meldung_der_sendung_zaehlt_auch_bei_erfolgsstatus() {
		$body = array(
			'status' => array( 'title' => 'ok', 'statusCode' => 200 ),
			'items'  => array( array( 'sstatus' => array( 'title' => 'Sendung nicht gefunden', 'statusCode' => 200 ) ) ),
		);

		$this->assertSame( array( 'Sendung nicht gefunden' ), DhlResponse::void_reasons( $body ) );
	}

	public function test_ein_blosses_ok_ist_keine_meldung() {
		$body = array( 'status' => array( 'title' => 'ok', 'statusCode' => 200 ), 'items' => array( array( 'sstatus' => array( 'title' => 'ok' ) ) ) );

		$this->assertSame( array(), DhlResponse::void_reasons( $body ) );
	}

	public function test_flacher_fehlerrumpf_ohne_sendungsstruktur() {
		$this->assertSame( array( 'Sendung nicht stornierbar' ), DhlResponse::void_reasons( array( 'detail' => 'Sendung nicht stornierbar' ) ) );
	}
}
