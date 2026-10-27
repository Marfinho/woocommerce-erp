<?php
/**
 * Härtung: Buchung ohne Doppelabzug, Systemprotokoll, Drosselung, Shop-Prüfungen.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Core\Log;
use Lindenweg16\Core\Throttle;
use Lindenweg16\Modules\Betrieb\Consistency;
use Lindenweg16\Modules\Betrieb\StockLedger;

/**
 * Was bei einem Abbruch, einem Fehler oder einem Ansturm nicht kaputtgehen darf.
 */
class BetriebHaertungTest extends TestCase {

	/**
	 * Eine noch nicht gebuchte Bestellung wird ganz gebucht.
	 */
	public function test_ungebuchte_bestellung_wird_ganz_gebucht() {
		$offen = StockLedger::unbooked(
			array(),
			array(
				array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 2 ),
				array( 'article_id' => 2, 'color' => 'kupfer', 'qty' => 1 ),
			)
		);

		$this->assertSame( array( 2.0, 1.0 ), $offen );
	}

	/**
	 * Nach einem Abbruch wird nur nachgebucht, was fehlt - nie doppelt.
	 */
	public function test_wiederaufnahme_bucht_nur_den_rest() {
		$gebucht = array(
			array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => -2, 'movement_type' => 'verkauf' ),
		);

		$offen = StockLedger::unbooked(
			$gebucht,
			array(
				array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 2 ),
				array( 'article_id' => 2, 'color' => 'kupfer', 'qty' => 1 ),
			)
		);

		$this->assertSame( array( 0.0, 1.0 ), $offen );
	}

	/**
	 * Zwei Positionen desselben Artikels teilen sich das bereits Gebuchte.
	 */
	public function test_gleicher_artikel_in_zwei_positionen() {
		$gebucht = array(
			array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => -3, 'movement_type' => 'verkauf' ),
		);

		$offen = StockLedger::unbooked(
			$gebucht,
			array(
				array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 2 ),
				array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 2 ),
			)
		);

		$this->assertSame( array( 0.0, 1.0 ), $offen );
	}

	/**
	 * Andere Bewegungsarten und Zugänge zählen nicht als gebuchter Verkauf.
	 */
	public function test_nur_verkaeufe_zaehlen_als_gebucht() {
		$offen = StockLedger::unbooked(
			array(
				array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => -2, 'movement_type' => 'muster' ),
				array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 5, 'movement_type' => 'verkauf' ),
			),
			array( array( 'article_id' => 1, 'color' => 'anthrazit', 'qty' => 2 ) )
		);

		$this->assertSame( array( 2.0 ), $offen );
	}

	/**
	 * Das Protokoll behält die neuesten Einträge und kürzt Wucherndes.
	 */
	public function test_protokoll_kuerzt() {
		$entries = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$entries = Log::push( $entries, Log::entry( 'error', 'lager', 'Fehler ' . $i, array(), $i ), 3 );
		}

		$this->assertCount( 3, $entries );
		$this->assertSame( 'Fehler 5', $entries[0]['message'] );
		$this->assertSame( 'Fehler 3', $entries[2]['message'] );
	}

	/**
	 * Zusatzangaben bleiben einfache, kurze Werte.
	 */
	public function test_protokolleintrag_bereinigt_kontext() {
		$entry = Log::entry( 'warning', 'dhl', 'x', array( 'id' => 7, 'obj' => new stdClass(), 'lang' => str_repeat( 'a', 500 ) ), 100 );

		$this->assertSame( 'warning', $entry['level'] );
		$this->assertSame( '7', $entry['context']['id'] );
		$this->assertSame( '[object]', $entry['context']['obj'] );
		$this->assertSame( 200, strlen( $entry['context']['lang'] ) );
	}

	/**
	 * Drosselung: bis zur Grenze durch, danach nicht mehr.
	 */
	public function test_drosselung() {
		$this->assertTrue( Throttle::allowed( 1, 120 ) );
		$this->assertTrue( Throttle::allowed( 120, 120 ) );
		$this->assertFalse( Throttle::allowed( 121, 120 ) );
	}

	/**
	 * Abweichungen zwischen ERP und Shop werden gemeldet.
	 */
	public function test_abweichung_im_shop_wird_gemeldet() {
		$this->assertSame( array(), Consistency::check_shop_stock( array() ) );

		$befunde = Consistency::check_shop_stock(
			array(
				array( 'article_id' => 1, 'product_id' => 10, 'name' => 'Haken', 'expected' => 4, 'shop' => 9 ),
				array( 'article_id' => 2, 'product_id' => 11, 'name' => 'Stange', 'expected' => 1, 'shop' => null ),
			)
		);

		$this->assertCount( 1, $befunde );
		$this->assertSame( 'warnung', $befunde[0]['level'] );
		$this->assertStringContainsString( 'Haken (ERP 4, Shop 9)', $befunde[0]['text'] );
		$this->assertStringContainsString( 'ohne Lagerverwaltung', $befunde[0]['text'] );
	}

	/**
	 * Der Abgleichsjob: ausgeschaltet still, sonst nach drei Stunden Alarm.
	 */
	public function test_abgleichsjob_ueberwachung() {
		$jetzt = 1000000;

		$this->assertSame( array(), Consistency::check_shop_stock_job( 0, $jetzt, false ) );
		$this->assertSame( array(), Consistency::check_shop_stock_job( $jetzt - 3600, $jetzt, true ) );
		$this->assertCount( 1, Consistency::check_shop_stock_job( $jetzt - 4 * 3600, $jetzt, true ) );
		$this->assertStringContainsString( 'noch nie', Consistency::check_shop_stock_job( 0, $jetzt, true )[0]['text'] );
	}

	/**
	 * Summen aus der Tabelle haben dieselbe Form wie die aus den Bewegungen.
	 */
	public function test_summen_aus_zeilen_wie_aus_bewegungen() {
		$aus_bewegungen = StockLedger::balances(
			array(
				array( 'article_id' => 1, 'color' => 'anthrazit', 'location' => 'lager', 'qty' => 10 ),
				array( 'article_id' => 1, 'color' => 'anthrazit', 'location' => 'lager', 'qty' => -3 ),
				array( 'article_id' => 1, 'color' => 'anthrazit', 'location' => 'kommission', 'qty' => 2 ),
				array( 'article_id' => 2, 'color' => '', 'location' => 'lager', 'qty' => 4 ),
			)
		);

		$aus_zeilen = StockLedger::from_rows(
			array(
				array( 'article_id' => 1, 'color' => 'anthrazit', 'location' => 'lager', 'qty' => '7.000' ),
				array( 'article_id' => 1, 'color' => 'anthrazit', 'location' => 'kommission', 'qty' => '2.000' ),
				array( 'article_id' => 2, 'color' => '', 'location' => 'lager', 'qty' => '4.000' ),
			)
		);

		$this->assertEquals( $aus_bewegungen, $aus_zeilen );
		$this->assertSame( array(), Consistency::check_balance_drift( $aus_bewegungen, $aus_zeilen ) );
	}

	/**
	 * Eine abweichende Summe ist ein Fehler, der auf das Bewegungskonto verweist.
	 */
	public function test_abweichende_summe_wird_gemeldet() {
		$erwartet = array( 1 => array( 'anthrazit' => array( 'lager' => 7.0 ) ) );
		$ist      = array( 1 => array( 'anthrazit' => array( 'lager' => 9.0 ) ), 2 => array( 'kupfer' => array( 'lager' => 1.0 ) ) );

		$befunde = Consistency::check_balance_drift( $erwartet, $ist );

		$this->assertCount( 1, $befunde );
		$this->assertSame( 'fehler', $befunde[0]['level'] );
		$this->assertStringContainsString( '2 Bestandszeilen', $befunde[0]['text'] );
	}

	/**
	 * Nur Versions-Tags dürfen in eine Download-Adresse.
	 */
	public function test_nur_versionstags_sind_gueltig() {
		$this->assertTrue( \Lindenweg16\Core\Updater::is_tag( '0.44.0' ) );
		$this->assertTrue( \Lindenweg16\Core\Updater::is_tag( 'v1.2.3' ) );
		$this->assertFalse( \Lindenweg16\Core\Updater::is_tag( '../../evil' ) );
		$this->assertFalse( \Lindenweg16\Core\Updater::is_tag( 'main' ) );
		$this->assertFalse( \Lindenweg16\Core\Updater::is_tag( '1.2' ) );
	}

	/**
	 * Sicherungsnamen: nur eigene Dateien, kein Ausbrechen aus dem Ordner.
	 */
	public function test_sicherungsnamen() {
		$name = \Lindenweg16\Core\Rollback::file_name( '0.44.0', 86400 );

		$this->assertSame( 'lindenweg16-0.44.0-19700102-000000.zip', $name );
		$this->assertTrue( \Lindenweg16\Core\Rollback::is_valid_name( $name ) );
		$this->assertFalse( \Lindenweg16\Core\Rollback::is_valid_name( '../wp-config.php' ) );
		$this->assertFalse( \Lindenweg16\Core\Rollback::is_valid_name( 'lindenweg16-1-20200101-000000.zip/../x' ) );
		$this->assertTrue( \Lindenweg16\Core\Rollback::skip( 'vendor/phpunit/x.php' ) );
		$this->assertFalse( \Lindenweg16\Core\Rollback::skip( 'includes/Core/Log.php' ) );
	}
}
