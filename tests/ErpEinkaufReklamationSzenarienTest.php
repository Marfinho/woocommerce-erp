<?php
/**
 * Szenarien für Einkauf und Reklamation, ergänzend zu
 * ErpLieferantenbestellungenTest, ErpProcurementTest, ErpMaterialTest und
 * ErpReklamationenTest: Teilstorno, Zeitumstellung, genau passende Rolle,
 * Kommazahlen im Wareneingang, gelöschte Artikel und Kundentexte.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Tests\Szenarien;

use Lindenweg16\Erp\Material;
use Lindenweg16\Erp\Pages\Lieferantenbestellungen;
use Lindenweg16\Erp\Pages\Reklamationen;
use Lindenweg16\Erp\Procurement;
use PHPUnit\Framework\TestCase;

/**
 * Einkauf und Reklamation.
 */
class ErpEinkaufReklamationSzenarienTest extends TestCase {

	/**
	 * Einkauf.
	 *
	 * @param int    $id     Kennung.
	 * @param string $status Status.
	 * @return array
	 */
	private function p( $id, $status ) {
		return array( 'id' => $id, 'status' => $status, 'supplier' => 'Würth', 'qty' => 1, 'unit_price_net' => 0, 'shipping_net' => 0 );
	}

	public function test_teilstorno_ist_keine_teillieferung() {
		$this->assertSame( 'versendet', Lieferantenbestellungen::shown_status( 'versendet', array( $this->p( 1, 'storniert' ), $this->p( 2, 'bestellt' ) ) ) );
	}

	public function test_bestellmail_ohne_wunschtermin() {
		$mail = Lieferantenbestellungen::mail_text( array( 'number' => 'BE-2026-0005', 'wanted_by' => '' ), 'Lindenweg16' );
		$this->assertSame( 'Bestellung BE-2026-0005 – Lindenweg16', $mail['subject'] );
		$this->assertStringNotContainsString( 'benötigen', $mail['body'] );
	}

	public function test_verspaetung_ueber_zeitumstellung_und_mit_deutschem_datum() {
		$this->assertSame( 1, Procurement::late_days( '2026-03-28', '2026-03-29' ) );
		$this->assertSame( 1, Procurement::late_days( '2026-10-24', '2026-10-25' ) );
		$this->assertSame( 0, Procurement::late_days( '10.03.2026', '2026-03-20' ), 'Unbekanntes Format: keine Verspätung.' );
	}

	public function test_genau_passende_rolle_reicht() {
		$lots = array(
			array( 'id' => 1, 'code' => 'A', 'remaining_kg' => 0.2, 'received_on' => '2026-01-01' ),
			array( 'id' => 2, 'code' => 'B', 'remaining_kg' => 1.0, 'received_on' => '2026-01-15' ),
		);
		$this->assertSame( 'A', Material::pick_lot( $lots, 0.2 )['code'] );
	}

	public function test_ampel_bald_bis_zur_doppelten_meldegrenze() {
		$this->assertSame( 'bald', Material::level( 28, 14, 1 ) );
		$this->assertSame( 'ok', Material::level( 29, 14, 1 ) );
	}

	public function test_wareneingang_mit_kommazahlen_ohne_rundungsfehler() {
		$this->assertSame( '', Reklamationen::receive_error( 0.3, 0.1, 0.2, 0 ) );
		$this->assertNotSame( '', Reklamationen::receive_error( 0.3, 0.1, 0.2, 0.01 ) );
	}

	public function test_geloeschter_artikel_erscheint_mit_kennung() {
		$stats = Reklamationen::stats( array( array( 'reason' => 'bruch', 'article_id' => 99, 'qty' => 1 ) ), array() );
		$this->assertSame( array( array( '#99', 1 ) ), $stats['top_articles'] );
	}

	public function test_hoechstens_fuenf_top_artikel() {
		$cases = array();
		for ( $i = 1; $i <= 8; $i++ ) {
			$cases[] = array( 'reason' => 'defekt', 'article_id' => $i, 'qty' => 1 );
		}
		$this->assertCount( 5, Reklamationen::stats( $cases, array() )['top_articles'] );
	}

	public function test_kundenmail_ohne_namen() {
		$anon = Reklamationen::customer_mail( 'loesung', array( 'number' => 'RK-1', 'customer' => '' ), 'L', 'ersatz' );
		$this->assertStringStartsWith( 'Hallo,', $anon['body'] );
		$this->assertSame( 'Ihre Reklamation RK-1 – unsere Lösung', $anon['subject'] );
	}

	public function test_jede_loesung_hat_einen_kundentext() {
		foreach ( array_keys( Reklamationen::resolutions() ) as $res ) {
			$mail = Reklamationen::customer_mail( 'loesung', array( 'number' => 'RK-1', 'customer' => 'A' ), 'L', $res );
			$this->assertDoesNotMatchRegularExpression( '/RK-1: \n/', $mail['body'], 'Lösung: ' . $res );
		}
	}

	public function test_erstattung_ohne_betrag() {
		$mail = Reklamationen::customer_mail( 'loesung', array( 'number' => 'RK-1', 'customer' => 'A' ), 'L', 'gutschrift' );
		$this->assertStringContainsString( 'Wir erstatten Ihnen den Betrag.', $mail['body'] );
	}
}
