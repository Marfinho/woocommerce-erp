<?php
/**
 * Szenarien für das Mahnwesen, ergänzend zu ErpMahnwesenTest: Grenzfälle der
 * Stufenfolge, negativer Basiszins, Rundung bei Nebenforderungen, unbrauchbare
 * Bundesbank-Antworten und die Texte an den Kunden.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Tests\Szenarien;

use Lindenweg16\Erp\Pages\Mahnwesen;
use PHPUnit\Framework\TestCase;

/**
 * Mahnwesen.
 */
class ErpMahnwesenSzenarienTest extends TestCase {

	/**
	 * Einstellungen wie im Betrieb.
	 *
	 * @param array $over Abweichungen.
	 * @return array
	 */
	private function settings( array $over = array() ) {
		return array_merge( array( 'tage_erinnerung' => 0, 'tage_stufe2' => 14, 'tage_stufe3' => 14 ), $over );
	}

	/**
	 * Kontext für Texte.
	 *
	 * @param array $over Abweichungen.
	 * @return array
	 */
	private function ctx( array $over = array() ) {
		return array_merge(
			array(
				'company' => 'Lindenweg16',
				'person'  => 'Frau Muster',
				'shop'    => 'Lindenweg16',
				'iban'    => 'DE02120300000000202051',
				'items'   => array(
					array( 'number' => '2026-0042', 'date' => '2026-02-01', 'value' => 119.0, 'due' => '2026-02-15' ),
				),
				'fee'     => 5.0,
				'new_due' => '2026-03-10',
				'today'   => '2026-03-01',
			),
			$over
		);
	}

	public function test_altersklassen_an_den_uebrigen_grenzen() {
		$this->assertSame( 'nicht_faellig', Mahnwesen::bucket( -5 ) );
		$this->assertSame( '1_30', Mahnwesen::bucket( 1 ) );
		$this->assertSame( '31_60', Mahnwesen::bucket( 60 ) );
		$this->assertSame( 'ueber_60', Mahnwesen::bucket( 400 ) );
	}

	public function test_erinnerung_mit_karenz_am_letzten_karenztag_und_danach() {
		$s = $this->settings( array( 'tage_erinnerung' => 7 ) );

		$this->assertSame( array( 'level' => 1, 'due' => false, 'wait' => 1 ), Mahnwesen::next_level( 7, 0, '', '', $s, '2026-03-01' ) );
		$this->assertSame( array( 'level' => 1, 'due' => true, 'wait' => 0 ), Mahnwesen::next_level( 8, 0, '', '', $s, '2026-03-01' ) );
	}

	public function test_lange_nach_dem_termin_wartet_nie_negativ() {
		$this->assertSame( array( 'level' => 2, 'due' => true, 'wait' => 0 ), Mahnwesen::next_level( 40, 1, '2026-03-01', '2026-03-11', $this->settings(), '2026-04-30' ) );
	}

	public function test_ohne_frist_zaehlt_nur_der_abstand() {
		$next = Mahnwesen::next_level( 30, 2, '2026-03-01', '', $this->settings( array( 'tage_stufe3' => 10 ) ), '2026-03-11' );
		$this->assertSame( array( 'level' => 3, 'due' => true, 'wait' => 0 ), $next );
	}

	public function test_verzugszinsen_bei_negativem_basiszins() {
		$res = Mahnwesen::interest( 1000, -0.88, 365, false );
		$this->assertSame( 4.12, $res['rate'] );
		$this->assertSame( 41.2, $res['amount'] );

		$this->assertSame( 0.0, Mahnwesen::interest( 1000, -6, 30, false )['amount'], 'Negativer Gesamtzins ergibt keine Zinsen.' );
		$this->assertSame( 0.0, Mahnwesen::interest( 1000, 1.52, -3, true )['amount'], 'Negative Verzugstage ergeben keine Zinsen.' );
	}

	public function test_zahlung_deckt_nebenforderungen_auf_den_cent() {
		$this->assertTrue( Mahnwesen::covers_extras( 1014.996, 1000, 15 ), 'Rundung um einen halben Cent.' );
		$this->assertFalse( Mahnwesen::covers_extras( 1014.99, 1000, 15 ), 'Ein Cent fehlt.' );
	}

	public function test_basiszins_neuester_monat_auch_bei_unsortierten_zeilen() {
		$csv = "\xEF\xBB\xBF" . "KEY;FREQ;TIME_PERIOD;OBS_VALUE;OBS_STATUS\r\n"
			. "BBIN1.M.DE;M;2025-07;1,27;A\r\n"
			. "BBIN1.M.DE;M;2026-01;1,52;A\r\n"
			. "BBIN1.M.DE;M;2025-01;2,27;A\r\n";

		$this->assertSame( array( 'rate' => 1.52, 'period' => '2026-01' ), Mahnwesen::parse_base_rate( $csv ) );
	}

	public function test_basiszins_mit_vertauschten_spalten() {
		$this->assertSame( array( 'rate' => 3.62, 'period' => '2024-01' ), Mahnwesen::parse_base_rate( "OBS_VALUE;TIME_PERIOD\n3.62;2024-01\n" ) );
	}

	public function test_basiszins_unbrauchbare_antworten() {
		$this->assertNull( Mahnwesen::parse_base_rate( '' ) );
		$this->assertNull( Mahnwesen::parse_base_rate( "<html>Wartung</html>\n" ) );
		$this->assertNull( Mahnwesen::parse_base_rate( "TIME_PERIOD;OBS_VALUE\n2026-01;NaN\n2026;1,0\n" ) );
	}

	public function test_erinnerung_ohne_zinsen_und_pauschale_auch_wenn_uebergeben() {
		$text = Mahnwesen::text( 1, $this->ctx( array( 'fee' => 5.0, 'interest' => 3.0, 'flat' => 40.0 ) ) );

		$this->assertSame( 119.0, $text['total'] );
		$this->assertStringNotContainsString( 'Verzugszinsen', $text['body'] );
		$this->assertStringNotContainsString( 'Verzugspauschale', $text['body'] );
		$this->assertStringContainsString( '2026-0042 vom 01.02.2026 über 119,00 €, fällig seit 15.02.2026', $text['body'] );
	}

	public function test_mahnung_ueber_mehrere_rechnungen() {
		$text = Mahnwesen::text(
			2,
			$this->ctx(
				array(
					'items' => array(
						array( 'number' => 'A', 'date' => '2026-01-01', 'value' => 100.0, 'due' => '2026-01-15' ),
						array( 'number' => 'B', 'date' => '2026-01-05', 'value' => 50.5, 'due' => '2026-01-19' ),
					),
				)
			)
		);

		$this->assertSame( 155.5, $text['total'] );
		$this->assertStringContainsString( "– A vom 01.01.2026 über 100,00 €, fällig seit 15.01.2026\n– B vom 05.01.2026", $text['body'] );
		$this->assertStringContainsString( 'Offen insgesamt: 155,50 €', $text['body'] );
	}

	public function test_negative_gebuehr_wird_nicht_gutgeschrieben() {
		$text = Mahnwesen::text( 2, $this->ctx( array( 'fee' => -20.0 ) ) );
		$this->assertSame( 119.0, $text['total'] );
		$this->assertStringStartsWith( '1. Mahnung', $text['subject'] );
	}

	public function test_ohne_iban_kein_zahlhinweis_und_keine_haengenden_leerzeichen() {
		$text = Mahnwesen::text( 2, $this->ctx( array( 'person' => '  ', 'iban' => '' ) ) );

		$this->assertStringNotContainsString( 'IBAN', $text['body'] );
		$this->assertDoesNotMatchRegularExpression( "/ +\n/", $text['body'] );
	}

	public function test_stufenbezeichnungen() {
		$this->assertSame( array( 1, 2, 3 ), array_keys( Mahnwesen::level_labels() ) );
	}
}
