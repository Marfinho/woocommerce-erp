<?php
/**
 * Artikel stilllegen: Der Status muss endlich wirken.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\ArticleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Ein gesperrter Artikel stand bisher weiter in jeder Auswahlliste –
 * der Status war ein Etikett ohne Wirkung.
 */
class BetriebArticleStateTest extends TestCase {

	/**
	 * Ein aktiver Artikel wird angeboten.
	 */
	public function test_aktiv_wird_angeboten() {
		$this->assertTrue( ArticleRepository::is_active( array( 'status' => 'aktiv' ) ) );
	}

	/**
	 * Ein Artikel in Entwicklung ist noch nicht ausgelaufen – er soll
	 * sich anlegen und bebuchen lassen.
	 */
	public function test_entwicklung_gilt_als_aktiv() {
		$this->assertTrue( ArticleRepository::is_active( array( 'status' => 'entwicklung' ) ) );
	}

	/**
	 * Gesperrt und ausgelaufen verschwinden aus den Auswahllisten.
	 */
	public function test_gesperrt_und_ausgelaufen_sind_inaktiv() {
		$this->assertFalse( ArticleRepository::is_active( array( 'status' => 'gesperrt' ) ) );
		$this->assertFalse( ArticleRepository::is_active( array( 'status' => 'ausgelaufen' ) ) );
	}

	/**
	 * Ein Artikel ohne Status gilt als aktiv – eine fehlende Angabe darf
	 * ihn nicht stillschweigend verschwinden lassen.
	 */
	public function test_ohne_status_gilt_als_aktiv() {
		$this->assertTrue( ArticleRepository::is_active( array() ) );
		$this->assertTrue( ArticleRepository::is_active( array( 'status' => '' ) ) );
	}

	/**
	 * Beide stillgelegten Zustände stehen in der Liste, und keiner der
	 * benutzbaren.
	 */
	public function test_liste_der_stillgelegten_zustaende() {
		$this->assertContains( 'gesperrt', ArticleRepository::INACTIVE_STATES );
		$this->assertContains( 'ausgelaufen', ArticleRepository::INACTIVE_STATES );
		$this->assertNotContains( 'aktiv', ArticleRepository::INACTIVE_STATES );
		$this->assertNotContains( 'entwicklung', ArticleRepository::INACTIVE_STATES );
	}

	/**
	 * Die Stellen, an denen man etwas Neues beginnt, fragen nach den
	 * angebotenen Artikeln – nicht nach allen.
	 *
	 * Diese vier Dateien kennen nur Auswahllisten: Kasse, Nachbestellung,
	 * Preisübergabe, Beschaffungsalarm. Taucht dort wieder ein all() auf,
	 * steht ein stillgelegter Artikel erneut zur Wahl.
	 */
	public function test_auswahllisten_fragen_nach_angebotenen_artikeln() {
		$root = dirname( __DIR__ ) . '/includes/Modules/';

		$dateien = array(
			'POS/POSModule.php',
			'Betrieb/ReorderPage.php',
			'Betrieb/PriceSync.php',
			'Betrieb/ProcurementAlert.php',
		);

		$fehler = array();

		foreach ( $dateien as $datei ) {
			$inhalt = file_get_contents( $root . $datei );

			$this->assertStringContainsString(
				'ArticleRepository::selectable(',
				$inhalt,
				$datei . ' fragt gar nicht nach Artikeln – der Test prüft dort nichts.'
			);

			if ( false !== strpos( $inhalt, 'ArticleRepository::all(' ) ) {
				$fehler[] = $datei;
			}
		}

		$this->assertSame( array(), $fehler, 'Auswahl über all() statt selectable(): ' . implode( ', ', $fehler ) );
	}

	/**
	 * Jeder stillgelegte Zustand ist auch ein bekannter Status – sonst
	 * ließe er sich gar nicht setzen.
	 */
	public function test_stillgelegte_zustaende_sind_bekannte_status() {
		$labels = ArticleRepository::status_labels();

		foreach ( ArticleRepository::INACTIVE_STATES as $status ) {
			$this->assertArrayHasKey( $status, $labels );
		}
	}
}
