<?php
/**
 * Testkauf-Befunde: Lieferzeit bei Nachbestellung, Gutschein nur mit Wert.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\ForecastHooks;
use Lindenweg16\Modules\Betrieb\Promise;
use Lindenweg16\Modules\Gutschein\WertPflicht;

/**
 * Reine Regeln hinter Lagertext, Warenkorb-Lieferzeit und Gutschein-Sperre.
 */
class ShopVerfuegbarkeitTest extends TestCase {

	/**
	 * Nachbestellbare Ware heißt „Wird für dich gefertigt“ und nennt immer eine Lieferzeit.
	 */
	public function test_nachbestellbar_nennt_immer_lieferzeit() {
		$this->assertSame( 'Wird für dich gefertigt – Versand in ca. 7 Tagen', Promise::shop_text( 0, null, 3, true ) );
		$this->assertSame( Promise::made_to_order_text( Promise::days_for_weeks( 1.0 ) ), Promise::shop_text( 0, array( 'weeks' => 1.0, 'date_to' => 'x' ), 3, true ) );
	}

	/**
	 * Was am Lager ist, bleibt beim gewohnten Text.
	 */
	public function test_lagerware_bleibt_beim_alten_text() {
		$this->assertSame( 'Nur noch 1 auf Lager', Promise::shop_text( 1, null, 3, true ) );
		$this->assertSame( 'Auf Lager', Promise::shop_text( 9, null, 3, true ) );
		$this->assertSame( 'Zurzeit nicht auf Lager', Promise::shop_text( 0, null ) );
	}

	/**
	 * Tage aus Wochen: späte Zusage, ohne Schätzung die Vorgabe.
	 */
	public function test_tage_aus_wochen() {
		$this->assertSame( Promise::DEFAULT_MADE_TO_ORDER_DAYS, Promise::days_for_weeks( null ) );
		$this->assertSame( 12, Promise::days_for_weeks( 1.0 ) );
		$this->assertSame( 2, Promise::days_for_weeks( 0.0 ) );
	}

	/**
	 * Warenkorb: Lieferzeit erst, wenn die Menge über den Bestand geht.
	 */
	public function test_warenkorb_lieferzeit() {
		$this->assertSame( '', ForecastHooks::item_delivery_text( 1, 1, 7 ) );
		$this->assertSame( 'Wird für dich gefertigt – Versand in ca. 7 Tagen', ForecastHooks::item_delivery_text( 2, 0, 7 ) );
		$this->assertSame( '1 sofort, der Rest: Wird für dich gefertigt – Versand in ca. 7 Tagen', ForecastHooks::item_delivery_text( 5, 1, 7 ) );
	}

	/**
	 * Ein Gutscheinwert muss eine positive Zahl sein.
	 */
	public function test_gutscheinwert() {
		$this->assertTrue( WertPflicht::wert_gewaehlt( '25' ) );
		$this->assertTrue( WertPflicht::wert_gewaehlt( 50 ) );
		$this->assertFalse( WertPflicht::wert_gewaehlt( '' ) );
		$this->assertFalse( WertPflicht::wert_gewaehlt( null ) );
		$this->assertFalse( WertPflicht::wert_gewaehlt( '0' ) );
		$this->assertFalse( WertPflicht::wert_gewaehlt( 'abc' ) );
		$this->assertFalse( WertPflicht::wert_gewaehlt( array( 25 ) ) );
	}
}
