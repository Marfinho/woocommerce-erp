<?php
/**
 * Preisübergabe an das Shop-Produkt.
 *
 * Der Preis stand an zwei Stellen: am Betriebsartikel und im Shop, mit
 * einer eigenen Seite dazwischen, auf der man ihn „übernahm". Wer den
 * zweiten Schritt vergaß, verkaufte weiter zum alten Preis. Jetzt zieht
 * der Shop beim Speichern nach – aber nur dann, wenn es etwas nachzuziehen
 * gibt.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\PriceSync;

/**
 * Prüft, wann übertragen wird und wann nicht.
 */
class BetriebPriceSyncTest extends TestCase {

	/**
	 * Ein neuer Artikel mit Preis und Shop-Produkt geht sofort raus.
	 */
	public function test_neuer_artikel_wird_uebertragen() {
		$this->assertTrue(
			PriceSync::needs_push( null, array( 'price_gross' => 12.9, 'wc_product_id' => 7 ) )
		);
	}

	/**
	 * Ohne Shop-Produkt gibt es nichts zu übertragen.
	 */
	public function test_ohne_shop_produkt_keine_uebertragung() {
		$this->assertFalse( PriceSync::needs_push( null, array( 'price_gross' => 12.9, 'wc_product_id' => null ) ) );
		$this->assertFalse( PriceSync::needs_push( null, array( 'price_gross' => 12.9 ) ) );
	}

	/**
	 * Ein geänderter Preis geht raus.
	 */
	public function test_geaenderter_preis_geht_raus() {
		$this->assertTrue(
			PriceSync::needs_push(
				array( 'price_gross' => 12.9, 'wc_product_id' => 7 ),
				array( 'price_gross' => 14.5, 'wc_product_id' => 7 )
			)
		);
	}

	/**
	 * Wird nur eine Notiz geändert, bleibt das Shop-Produkt in Ruhe.
	 * Sonst gäbe es eine Produktänderung pro Tippfehler.
	 */
	public function test_unveraenderter_preis_laesst_den_shop_in_ruhe() {
		$this->assertFalse(
			PriceSync::needs_push(
				array( 'price_gross' => 12.9, 'wc_product_id' => 7 ),
				array( 'price_gross' => 12.9, 'wc_product_id' => 7 )
			)
		);
	}

	/**
	 * Die Datenbank liefert „12.9000", das Formular „12.90". Ein
	 * Zeichenkettenvergleich meldete hier einen Unterschied und schriebe
	 * das Produkt bei jedem Speichern neu.
	 */
	public function test_gleiche_zahl_in_anderer_schreibweise_ist_kein_unterschied() {
		$this->assertFalse( PriceSync::differs( '12.9000', 12.9 ) );
		$this->assertFalse( PriceSync::differs( 12.90, '12.90' ) );
	}

	/**
	 * Ein Cent ist ein Unterschied.
	 */
	public function test_ein_cent_ist_ein_unterschied() {
		$this->assertTrue( PriceSync::differs( 12.90, 12.91 ) );
	}

	/**
	 * Von „kein Preis" auf einen Preis ist ein Unterschied – und umgekehrt.
	 */
	public function test_leerer_preis_gegen_preis() {
		$this->assertTrue( PriceSync::differs( null, 12.9 ) );
		$this->assertTrue( PriceSync::differs( 12.9, null ) );
		$this->assertFalse( PriceSync::differs( null, null ) );
		$this->assertFalse( PriceSync::differs( '', null ) );
	}

	/**
	 * Wird der Artikel erst jetzt mit einem Shop-Produkt verknüpft, kennt
	 * dieses den Preis noch gar nicht – auch ohne Preisänderung.
	 */
	public function test_neue_verknuepfung_loest_uebertragung_aus() {
		$this->assertTrue(
			PriceSync::needs_push(
				array( 'price_gross' => 12.9, 'wc_product_id' => 0 ),
				array( 'price_gross' => 12.9, 'wc_product_id' => 7 )
			)
		);
	}

	/**
	 * Die Preisübernahme aus der Kalkulation schickt nur den Preis mit.
	 * Die Verknüpfung steht dann allein im Stand davor – wird sie dort
	 * nicht gelesen, bleibt ausgerechnet der eben gesetzte Preis liegen.
	 */
	public function test_teilaenderung_findet_die_verknuepfung_im_stand_davor() {
		$this->assertTrue(
			PriceSync::needs_push(
				array( 'price_gross' => 12.9, 'wc_product_id' => 7 ),
				array( 'price_gross' => 15.0 )
			)
		);

		$this->assertFalse(
			PriceSync::needs_push(
				array( 'price_gross' => 12.9, 'wc_product_id' => 0 ),
				array( 'price_gross' => 15.0 )
			),
			'Ohne Shop-Produkt gibt es auch bei geändertem Preis nichts zu übertragen.'
		);
	}

	/**
	 * Ein Teil-Update ohne Preisfeld – etwa nur der Status – rührt den
	 * Shop nicht an.
	 */
	public function test_update_ohne_preisfeld_rührt_den_shop_nicht_an() {
		$this->assertFalse(
			PriceSync::needs_push(
				array( 'price_gross' => 12.9, 'wc_product_id' => 7 ),
				array( 'status' => 'aktiv' )
			)
		);
	}

	/**
	 * Der Artikelstamm meldet jedes Speichern – sonst bekäme die
	 * Übergabe einen Preis aus dem Import oder der Kalkulation nie zu
	 * sehen.
	 */
	public function test_artikelstamm_meldet_jedes_speichern() {
		$quelle = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/ArticleRepository.php' );

		$this->assertSame(
			2,
			substr_count( $quelle, "do_action( 'lw16_betrieb_article_saved'" ),
			'Anlegen und Ändern müssen beide melden.'
		);
	}

	/**
	 * Die alte Preiskalkulationsseite ist weg – zwei Wahrheiten für
	 * denselben Preis waren der Fehler.
	 */
	public function test_die_alte_preisseite_gibt_es_nicht_mehr() {
		$this->assertFileDoesNotExist( dirname( __DIR__ ) . '/includes/Modules/Pricing/PricingModule.php' );
		$this->assertStringNotContainsString(
			'lw16-preiskalkulation',
			file_get_contents( dirname( __DIR__ ) . '/includes/Admin/Menu.php' )
		);
	}
}
