<?php
/**
 * Versand im Modul Betrieb.
 *
 * Die Versandaktionen lagen unter Lindenweg16 → Produktion, während Ware,
 * Picklisten und Fertigungsaufträge im Betrieb liegen. Wer gepackt hatte,
 * musste das Menü wechseln, um denselben Karton zu frankieren.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\ShippingLabel;
use Lindenweg16\Modules\Betrieb\BetriebModule;
use Lindenweg16\Modules\Betrieb\Admin\ShippingPage;

/**
 * Prüft Menüeintrag, Filterlogik und Erkennung des Versandplugins.
 */
class BetriebVersandTest extends TestCase {

	/**
	 * Die Seite gehört zum Modul – sonst weist das Routing sie ab.
	 */
	public function test_versand_ist_eine_seite_des_moduls() {
		$this->assertTrue( BetriebModule::is_page( ShippingPage::SLUG ) );

		$pages = BetriebModule::pages();
		$this->assertSame( 'lw16_capture', $pages[ ShippingPage::SLUG ][1], 'Packen ist Werkstattarbeit – die Seite darf nicht an der Produktionsleitung hängen.' );
	}

	/**
	 * Der Standardfilter zeigt, was raus muss.
	 */
	public function test_standard_zeigt_offene_bestellungen() {
		$this->assertSame( array( 'wc-processing', 'wc-on-hold' ), ShippingPage::statuses_for( 'offen' ) );
	}

	/**
	 * Ein unbekannter Filter aus der Adresszeile darf nicht in eine leere
	 * Abfrage laufen – die Seite sähe dann aus, als gäbe es nichts zu tun.
	 */
	public function test_unbekannter_filter_faellt_auf_offen_zurueck() {
		foreach ( array( '', 'unsinn', 'DROP' ) as $filter ) {
			$this->assertSame( ShippingPage::statuses_for( 'offen' ), ShippingPage::statuses_for( $filter ), $filter );
		}
	}

	/**
	 * „Alle" ist die Vereinigung, nicht ein dritter Status.
	 */
	public function test_alle_enthaelt_beide_seiten() {
		$alle = ShippingPage::statuses_for( 'alle' );

		foreach ( array_merge( ShippingPage::statuses_for( 'offen' ), ShippingPage::statuses_for( 'versendet' ) ) as $status ) {
			$this->assertContains( $status, $alle );
		}
	}

	/**
	 * Ist gar kein Versandplugin da, wird auch keins behauptet.
	 */
	public function test_ohne_plugin_kein_label_weg() {
		$this->assertSame( '', ShippingLabel::detect( array() ) );
		$this->assertSame( '', ShippingLabel::detect( array( 'dhl_for_woocommerce' => false ) ) );
	}

	/**
	 * Bei mehreren aktiven Plugins gewinnt das, das wirklich DHL-Labels
	 * erzeugt – sonst schickt die Seite den Packer zu dem Plugin, das nur
	 * Sendungsnummern verwaltet.
	 */
	public function test_label_plugin_schlaegt_nummernverwaltung() {
		$kennung = ShippingLabel::detect(
			array(
				'shipment_tracking'    => true,
				'dhl_for_woocommerce'  => true,
			)
		);

		$this->assertSame( 'dhl_for_woocommerce', $kennung );
		$this->assertNotSame( '', ShippingLabel::label( $kennung ) );
	}

	/**
	 * Ein Name für eine unbekannte Kennung wäre eine Erfindung.
	 */
	public function test_unbekannte_kennung_hat_keinen_namen() {
		$this->assertSame( '', ShippingLabel::label( 'gibtsnicht' ) );
	}

	/**
	 * Die alte Produktionsseite darf die Versandaktionen nicht mehr
	 * doppelt anbieten – zwei Wege zur selben Packliste sind ein Weg zu
	 * viel. Der frühere Umzugshinweis samt Reiter "Druck-Queue" (nur ein
	 * Verweis auf Bedarf/Versand, ohne eigene Funktion) ist inzwischen
	 * selbst entfernt worden - der Umzug ist lange abgeschlossen, ein
	 * toter Reiter, der nur noch woanders hinzeigt, ist kein Mehrwert
	 * mehr.
	 */
	public function test_produktionsseite_bietet_den_versand_nicht_mehr_an() {
		$quelle = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Production/ProductionModule.php' );

		$this->assertStringNotContainsString( 'Versand-Aktionen je Bestellung', $quelle );
		$this->assertStringNotContainsString( 'Druck-Queue', $quelle );
		$this->assertStringNotContainsString( 'page=lw16-betrieb-versand', $quelle );
	}
}
