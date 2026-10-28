<?php
/**
 * Bedienung der Marktkasse in der ERP-Oberfläche: Kacheln statt Klappfeld, freier Betrag.
 *
 * Das Klappfeld war am Stand die falsche Bedienung – man greift nach dem
 * Teil, das der Kunde in der Hand hält, und erkennt es am Bild. Und nicht
 * jeder Verkauf hat ein Produkt: Restposten, Reparatur, Spende müssen
 * ohne Artikel in die Kasse gehen. Beides hält dieser Test fest.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

/**
 * Liest Seite und Fachlogik der Kasse und prüft ihre Bausteine.
 */
class PosKasseUiTest extends TestCase {

	/**
	 * Quelltext der Oberfläche.
	 *
	 * @return string
	 */
	protected function js() {
		return file_get_contents( dirname( __DIR__ ) . '/assets/erp/pages/kasse.js' );
	}

	/**
	 * Quelltext der Fachlogik.
	 *
	 * @return string
	 */
	protected function quelle() {
		return file_get_contents( dirname( __DIR__ ) . '/includes/Modules/POS/POSModule.php' );
	}

	public function test_kein_klappfeld_mehr_fuer_die_produktauswahl() {
		$this->assertStringNotContainsString( "h('select', { class: 'field', name: 'produkt_id'", $this->js() );
		$this->assertStringContainsString( 'ks-kachel', $this->js() );
	}

	/**
	 * Ein Betrag ohne Produkt muss sich buchen lassen, und zwar ohne das
	 * zuletzt gewählte Produkt mitzuschleppen.
	 */
	public function test_freier_betrag_schickt_leere_produktauswahl() {
		$js = $this->js();

		$this->assertStringContainsString( "value: '', name: fname.value.trim()", $js, 'Ein freier Betrag darf kein Produkt tragen.' );
		$this->assertStringContainsString( 'Freier Betrag', $js );
	}

	/**
	 * Mehrfach antippen: Jeder Tipp legt ein Stück auf den Bon, der Bon
	 * geht als Ganzes an den Server.
	 */
	public function test_kachel_mehrfach_antippen_und_bon() {
		$js = $this->js();

		$this->assertStringContainsString( 'line.menge += 1', $js );
		$this->assertStringContainsString( "post('bon'", $js );
	}

	/**
	 * Vollbild für das Tablet: Kopf und Menü weg, wo möglich echtes Vollbild.
	 */
	public function test_vollbild_fuer_das_tablet() {
		$js = $this->js();

		$this->assertStringContainsString( "classList.toggle('kiosk'", $js );
		$this->assertStringContainsString( 'requestFullscreen', $js );
		$this->assertStringContainsString( 'kiosk-exit', $js );
		$this->assertStringContainsString( '.erp.kiosk .ks-grid', file_get_contents( dirname( __DIR__ ) . '/assets/erp/pages/kasse.css' ) );
	}

	/**
	 * Ein Bon wird vor dem Buchen ganz geprüft.
	 */
	public function test_bon_wird_serverseitig_vorab_geprueft() {
		$php = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/POS/POSModule.php' );

		$this->assertStringContainsString( 'public static function book_bon', $php );
		$this->assertStringContainsString( 'count( $zeilen ) > 100', $php );
	}

	/**
	 * Jedes Geldfeld der Kasse läuft durch parse_amount – sonst wird aus
	 * „12,50" ein Betrag von 12 €.
	 */
	public function test_geldfelder_werden_geparst() {
		$quelle = $this->quelle();

		foreach ( array( 'einzelpreis', 'kassenbestand_anfang', 'kassenbestand_ende' ) as $feld ) {
			$this->assertMatchesRegularExpression(
				"/self::parse_amount\\( \\\$data\\['" . $feld . "'\\]/",
				$quelle,
				$feld . ' wird ungeprüft in eine Zahl gecastet.'
			);
		}

		$this->assertStringNotContainsString( "(float) ( \$data['einzelpreis']", $quelle );
	}

	/**
	 * Die Kachel braucht Bild und Preis aus der Auswahlliste.
	 */
	public function test_auswahlliste_liefert_bild_und_preis() {
		$quelle = $this->quelle();

		$this->assertStringContainsString( "'bild'  => self::article_image_url(", $quelle );
		$this->assertStringContainsString( 'ArticleRepository::selectable(', $quelle );
	}

	/**
	 * Die wp-admin-Kasse ist weg: keine Seite, keine admin-post-Handler.
	 */
	public function test_keine_zweite_kasse_im_wp_admin() {
		$quelle = $this->quelle();

		$this->assertStringNotContainsString( 'admin_post_', $quelle );
		$this->assertStringNotContainsString( 'lw16_render_page_lw16_kasse', $quelle );
		$this->assertStringNotContainsString( 'wp_nonce_field', $quelle );
	}
}
