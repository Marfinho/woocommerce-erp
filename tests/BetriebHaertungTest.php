<?php
/**
 * Härtung: Buchung ohne Doppelabzug, Systemprotokoll, Drosselung, Shop-Prüfungen.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Core\EventLog;
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
	 * Die drei Stufen; die alten Namen bleiben gültig.
	 */
	public function test_stufen_des_ereignisprotokolls() {
		$this->assertSame( array( 'normal', 'attention', 'critical' ), array_keys( Log::levels() ) );
		$this->assertSame( 'critical', Log::normalize_level( 'error' ) );
		$this->assertSame( 'attention', Log::normalize_level( 'warning' ) );
		$this->assertSame( 'normal', Log::normalize_level( 'irgendwas' ) );
	}

	/**
	 * Kritisches wird am längsten aufbewahrt, Gewöhnliches am kürzesten.
	 */
	public function test_aufbewahrung_nach_stufe() {
		$this->assertLessThan( Log::keep_days( 'attention' ), Log::keep_days( 'normal' ) );
		$this->assertLessThan( Log::keep_days( 'critical' ), Log::keep_days( 'attention' ) );
	}

	/**
	 * Zusatzangaben bleiben einfache, kurze Werte.
	 */
	public function test_protokolleintrag_bereinigt_kontext() {
		$entry = Log::entry( 'warning', 'dhl', 'x', array( 'id' => 7, 'obj' => new stdClass(), 'lang' => str_repeat( 'a', 500 ) ), 100 );

		$this->assertSame( 'attention', $entry['level'] );
		$this->assertSame( '7', $entry['context']['id'] );
		$this->assertSame( '[object]', $entry['context']['obj'] );
		$this->assertSame( 200, strlen( $entry['context']['lang'] ) );
	}

	/**
	 * Stornierungen und Löschungen im Änderungsprotokoll verdienen Aufmerksamkeit, alles andere ist normal.
	 */
	public function test_aenderungen_werden_eingestuft() {
		$this->assertSame( 'normal', EventLog::classify_audit( array( 'aktion' => 'betrieb_create', 'objekt_typ' => 'stock_movements', 'objekt_id' => 5 ) )['level'] );
		$this->assertSame( 'attention', EventLog::classify_audit( array( 'aktion' => 'betrieb_void', 'objekt_typ' => 'stock_movements', 'objekt_id' => 5 ) )['level'] );
		$this->assertSame( 'lager', EventLog::classify_audit( array( 'aktion' => 'betrieb_create', 'objekt_typ' => 'stock_movements' ) )['area'] );
		$this->assertSame( 'artikel', EventLog::area_of( 'articles' ) );
		$this->assertSame( 'system', EventLog::area_of( '' ) );
		$this->assertSame( 'daten', EventLog::area_of( 'sonstiges' ) );
	}

	/**
	 * Stornos, Erstattungen und Fehlschläge einer Bestellung fallen auf.
	 */
	public function test_bestellstatus_mit_aufmerksamkeit() {
		$this->assertTrue( EventLog::status_needs_attention( 'cancelled' ) );
		$this->assertTrue( EventLog::status_needs_attention( 'refunded' ) );
		$this->assertTrue( EventLog::status_needs_attention( 'failed' ) );
		$this->assertFalse( EventLog::status_needs_attention( 'processing' ) );
		$this->assertFalse( EventLog::status_needs_attention( 'completed' ) );
	}

	/**
	 * Fünf Fehlanmeldungen in kurzer Zeit sind kritisch.
	 */
	public function test_fehlanmeldungen() {
		$this->assertFalse( EventLog::is_brute_force( 4 ) );
		$this->assertTrue( EventLog::is_brute_force( 5 ) );
	}

	/**
	 * Nur schwere PHP-Fehler in unserem Ordner zählen als kritisch.
	 */
	public function test_php_fehler_im_plugin() {
		$dir = '/var/www/wp-content/plugins/lindenweg16/';

		$this->assertTrue( EventLog::is_plugin_fatal( array( 'type' => E_ERROR, 'file' => $dir . 'includes/Core/Log.php' ), $dir ) );
		$this->assertFalse( EventLog::is_plugin_fatal( array( 'type' => E_WARNING, 'file' => $dir . 'x.php' ), $dir ) );
		$this->assertFalse( EventLog::is_plugin_fatal( array( 'type' => E_ERROR, 'file' => '/var/www/wp-content/plugins/anderes/x.php' ), $dir ) );
		$this->assertFalse( EventLog::is_plugin_fatal( array( 'type' => E_ERROR, 'file' => $dir . 'x.php' ), '' ) );
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

	/**
	 * Klassische Seiten leiten nur dann ins ERP, wenn es dort eine gibt und die Person sie öffnen darf.
	 */
	public function test_klassische_seite_leitet_nur_wenn_erp_sie_bietet() {
		require_once dirname( __DIR__ ) . '/includes/Erp/Shell.php';

		$native = array( 'kind' => 'native', 'alt' => 'lw16-betrieb-versand', 'id' => 'versand' );

		$this->assertTrue( \Lindenweg16\Erp\Shell::classic_redirects( $native, true ) );
		$this->assertFalse( \Lindenweg16\Erp\Shell::classic_redirects( $native, false ), 'Ohne Recht keine Umleitung auf eine leere Seite.' );
		$this->assertFalse( \Lindenweg16\Erp\Shell::classic_redirects( array( 'kind' => 'legacy', 'alt' => '', 'id' => 'kasse' ), true ), 'Eingebettete Seiten haben im ERP keine eigene Ansicht.' );
		$this->assertFalse( \Lindenweg16\Erp\Shell::classic_redirects( null, true ) );
	}
}
