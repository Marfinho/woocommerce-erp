<?php
/**
 * Szenarien für Produktion und Vivid, ergänzend zu ErpVarianceTest,
 * ErpShiftOrderTest und ErpVividTest: unbrauchbare Zeitstempel, Grenzen der
 * Nachkalkulation, gemischte Schichtreihenfolge, Zeitzonen der API.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Tests\Szenarien;

use Lindenweg16\Erp\ShiftOrder;
use Lindenweg16\Erp\Variance;
use Lindenweg16\Erp\Vivid;
use PHPUnit\Framework\TestCase;

/**
 * Produktion und Vivid.
 */
class ErpProduktionVividSzenarienTest extends TestCase {

	/* -------------------------------------------------- Nachkalkulation */

	public function test_unbrauchbare_zeitstempel() {
		$this->assertNull( Variance::run_minutes( array() ) );
		$this->assertNull( Variance::run_minutes( array( 'print_at' => '0000-00-00 00:00:00', 'print_end_at' => '2026-03-01 09:00:00' ) ) );
		$this->assertNull( Variance::run_minutes( array( 'print_at' => '2026-03-01 09:00:00' ) ), 'Noch nicht fertig.' );
		$this->assertSame( 0.0, Variance::run_minutes( array( 'setup_at' => '2026-03-01 10:00:00', 'print_at' => '2026-03-01 09:00:00', 'print_end_at' => '2026-03-01 11:00:00' ) )['setup'], 'Rüsten nach Druckstart zählt nicht.' );
	}

	public function test_verteilen_ohne_artikel() {
		$this->assertSame( array(), Variance::allocate( 100, array() ) );
	}

	public function test_planzeitkorrektur_genau_an_der_toleranz() {
		$this->assertTrue( Variance::should_adjust( 0.9, 3 ), 'Genau 10 % schneller – ab 10 % gilt in beide Richtungen.' );
		$this->assertSame( 5.63, Variance::adjusted_hours( 4.5, 1.25 ) );
	}

	public function test_marge_genau_fuenf_punkte_weniger_und_besser_als_geplant() {
		$this->assertSame( 'sinkt', Variance::margin_state( 0.40, 0.35, 2.0 ) );
		$this->assertSame( '', Variance::margin_state( 0.40, 0.45, 2.0 ) );
	}

	/* ------------------------------------------------ Schichtreihenfolge */

	public function test_feierabend_mit_unsinn_und_leerzeichen() {
		$this->assertSame( 480, ShiftOrder::minutes_of( ' 8:00 ' ) );
		$this->assertSame( 1020, ShiftOrder::minutes_of( '25:00' ) );
		$this->assertSame( 1020, ShiftOrder::minutes_of( '17:60' ) );
		$this->assertSame( 1020, ShiftOrder::minutes_of( '' ) );
	}

	public function test_tageszeit_an_den_grenzen() {
		$end = 17 * 60;
		$this->assertSame( ShiftOrder::DAY, ShiftOrder::phase( 6 * 60, $end, 2 ), 'Ab sechs Uhr Tag.' );
		$this->assertSame( ShiftOrder::DAY, ShiftOrder::phase( 14 * 60 + 59, $end, 2 ) );
		$this->assertSame( ShiftOrder::EVENING, ShiftOrder::phase( 22 * 60, $end, 2 ) );
	}

	public function test_tagsueber_drei_gruppen_in_einer_liste() {
		$runs = array(
			array( 'id' => 1, 'est_minutes' => 600 ),
			array( 'id' => 2, 'est_minutes' => 200 ),
			array( 'id' => 3, 'est_minutes' => 60 ),
			array( 'id' => 4, 'est_minutes' => 150 ),
			array( 'id' => 5, 'est_minutes' => 300 ),
		);
		// 13:00, Feierabend 17:00 → 240 Minuten übrig; die Langen mit der längsten voran.
		$out = ShiftOrder::order( $runs, 13 * 60, 17 * 60 );

		$this->assertSame( array( 3, 4, 2, 1, 5 ), array_column( $out, 'id' ) );
		$this->assertSame( array( false, false, false, true, true ), array_column( $out, 'long' ) );
	}

	public function test_abends_gleich_lange_nach_kennung() {
		$runs = array(
			array( 'id' => 3, 'est_minutes' => 600 ),
			array( 'id' => 2, 'est_minutes' => 600 ),
			array( 'id' => 1, 'est_minutes' => 60 ),
		);
		$this->assertSame( array( 2, 3, 1 ), array_column( ShiftOrder::order( $runs, 16 * 60, 17 * 60 ), 'id' ) );
	}

	public function test_dauer_und_hinweis_sonderfaelle() {
		$this->assertSame( '', ShiftOrder::duration_text( 0 ) );
		$this->assertSame( '10 h 5 min', ShiftOrder::duration_text( 605 ) );
		$this->assertStringContainsString( 'ab 01:30', ShiftOrder::hint_text( 'night_later', 300, 25 * 60 + 30 ), 'Über Mitternacht.' );
		$this->assertSame( '45 min Druck', ShiftOrder::hint_text( '', 45, 0 ) );
		$this->assertSame( '', ShiftOrder::hint_text( '', 0, 0 ) );
	}

	/* --------------------------------------------------------- Vivid */

	public function test_api_schluessel_mit_leerraum_und_fremdem_praefix() {
		$this->assertTrue( Vivid::looks_like_key( "  vivid_sk_v12_ABC-def_0123456789xyz\n" ) );
		$this->assertFalse( Vivid::looks_like_key( 'sk_live_abcdefghijklmnop' ) );
		$this->assertFalse( Vivid::looks_like_key( 'vivid_sk_v1_abcdefghijklmnop mit leerzeichen' ) );
	}

	public function test_keine_antwort_gilt_als_nicht_erreichbar() {
		$this->assertStringContainsString( 'nicht erreichbar', Vivid::error_text( 0, 'timeout' ) );
	}

	public function test_zeitpunkt_nach_utc() {
		$this->assertSame( '2026-03-29 00:30:00', Vivid::mysql_time( '2026-03-29T02:30:00+02:00' ) );
		$this->assertSame( '2026-03-01 10:00:00', Vivid::mysql_time( '2026-03-01T10:00:00Z' ) );
		$this->assertSame( '', Vivid::mysql_time( 'irgendwann' ) );
	}

	/**
	 * Umsatz wie von der API.
	 *
	 * @param array $over Abweichungen.
	 * @return array
	 */
	private function api_tx( array $over = array() ) {
		return array_merge(
			array(
				'id'         => 'tx_123',
				'account_id' => 'acc_1',
				'amount'     => '-12.5',
				'currency'   => 'eur',
				'state'      => 'completed',
				'type'       => 'card_payment',
				'show_at'    => '2026-03-02T23:30:00+01:00',
				'created_at' => '2026-03-01T10:00:00Z',
				'merchant'   => null,
				'card'       => null,
			),
			$over
		);
	}

	public function test_buchungstag_aus_ortszeit_in_utc_und_waehrung_gross() {
		$row = Vivid::to_row( $this->api_tx() );
		$this->assertSame( '2026-03-02', $row['booking_date'] );
		$this->assertSame( 'EUR', $row['currency'] );
	}

	public function test_ohne_zeitpunkt_verworfen_sonst_created_at() {
		$this->assertNull( Vivid::to_row( $this->api_tx( array( 'show_at' => '', 'created_at' => '' ) ) ) );
		$this->assertSame( '2026-03-01', Vivid::to_row( $this->api_tx( array( 'show_at' => '' ) ) )['booking_date'] );
	}

	public function test_konto_nimmt_die_erste_iban_und_rundet_den_saldo() {
		$row = Vivid::account_row(
			array(
				'id'              => 'acc_1',
				'name'            => 'Geschäft',
				'currency'        => 'eur',
				'state'           => 'active',
				'account_details' => array( array( 'iban' => '', 'bic' => '' ), array( 'iban' => 'de89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX' ) ),
				'balance'         => array( 'available' => '1234.567', 'pending' => 'x' ),
			)
		);

		$this->assertSame( 'Geschäft', $row['name'] );
		$this->assertSame( 'DE89370400440532013000', $row['iban'] );
		$this->assertSame( 'COBADEFFXXX', $row['bic'] );
		$this->assertSame( 'EUR', $row['currency'] );
		$this->assertSame( 1234.57, $row['balance_available'] );
		$this->assertNull( $row['balance_pending'] );
	}
}
