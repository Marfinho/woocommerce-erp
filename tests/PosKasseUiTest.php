<?php
/**
 * Bedienung der Marktkasse: Kacheln statt Klappfeld, freier Betrag.
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
 * Liest die Kassenseite und prüft ihre Bausteine.
 */
class PosKasseUiTest extends TestCase {

	/**
	 * Quelltext der Kasse.
	 *
	 * @return string
	 */
	protected function quelle() {
		return file_get_contents( dirname( __DIR__ ) . '/includes/Modules/POS/POSModule.php' );
	}

	/**
	 * Die Produktauswahl darf kein Klappfeld mehr sein.
	 */
	public function test_kein_klappfeld_mehr_fuer_die_produktauswahl() {
		$this->assertStringNotContainsString( '<select name="produkt_id"', $this->quelle() );
	}

	/**
	 * Desktop und mobile Kasse zeigen beide dieselbe Kachelliste.
	 */
	public function test_beide_ansichten_zeigen_kacheln() {
		$quelle = $this->quelle();

		$this->assertSame(
			2,
			substr_count( $quelle, '$this->render_product_picker(' ),
			'Kachelliste fehlt in einer der beiden Ansichten.'
		);
		$this->assertSame(
			2,
			substr_count( $quelle, '$this->picker_script(' ),
			'Ohne das Skript ist die Kachel nur ein Bild – die Auswahl käme nie im Formular an.'
		);
	}

	/**
	 * Die Kachel füllt Name und Preis über feste IDs. Fehlt eine davon
	 * im Formular, bleibt der Preis leer und der Verkauf wird mit 0 €
	 * gebucht.
	 */
	public function test_die_vom_skript_erwarteten_felder_stehen_im_formular() {
		$quelle = $this->quelle();

		foreach ( array( 'lw16-pos', 'm-pos' ) as $prefix ) {
			foreach ( array( 'name', 'preis' ) as $feld ) {
				$this->assertStringContainsString(
					'id="' . $prefix . '-' . $feld . '"',
					$quelle,
					$prefix . '-' . $feld . ' fehlt im Formular.'
				);
			}
		}
	}

	/**
	 * Ein Betrag ohne Produkt muss sich buchen lassen – in beiden
	 * Ansichten.
	 */
	public function test_freier_betrag_ist_in_beiden_ansichten_buchbar() {
		$quelle = $this->quelle();

		$this->assertSame(
			2,
			substr_count( $quelle, "esc_html_e( 'Freier Betrag', 'lindenweg16' )" ),
			'Der freie Betrag fehlt in einer der beiden Ansichten.'
		);
		$this->assertSame(
			2,
			substr_count( $quelle, '<input type="hidden" name="produkt_id" value="">' ),
			'Ohne leeres produkt_id würde der freie Betrag auf das zuletzt gewählte Produkt gebucht.'
		);
	}

	/**
	 * Jedes Geldfeld der Kasse läuft durch parse_amount – sonst wird aus
	 * „12,50" ein Betrag von 12 €.
	 */
	public function test_geldfelder_werden_geparst() {
		$quelle = $this->quelle();

		foreach ( array( 'einzelpreis', 'kassenbestand_anfang', 'kassenbestand_ende' ) as $feld ) {
			$this->assertStringContainsString(
				"self::parse_amount( wp_unslash( \$_POST['" . $feld . "']",
				$quelle,
				$feld . ' wird ungeprüft in eine Zahl gecastet.'
			);
		}

		$this->assertStringNotContainsString( "(float) ( \$_POST['einzelpreis']", $quelle );
	}

	/**
	 * Die Kachel braucht Bild und Preis aus der Auswahlliste.
	 */
	public function test_auswahlliste_liefert_bild_und_preis() {
		$quelle = $this->quelle();

		$this->assertStringContainsString( "'bild'  => self::product_image_url(", $quelle );
		$this->assertStringContainsString( 'ArticleRepository::selectable(', $quelle );
	}
}
