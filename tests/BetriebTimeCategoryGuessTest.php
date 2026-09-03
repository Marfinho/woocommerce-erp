<?php
/**
 * Prüft den Kategorievorschlag der Stoppuhr.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\TimeCategoryGuess;
use Lindenweg16\Modules\Betrieb\TimeRepository;
use PHPUnit\Framework\TestCase;

/**
 * Vorschlags-Tests.
 */
class BetriebTimeCategoryGuessTest extends TestCase {

	/**
	 * Seiten werden ihrer Tätigkeit zugeordnet.
	 */
	public function test_seiten_werden_zugeordnet() {
		$this->assertSame( 'produktion', TimeCategoryGuess::category_for_page( 'lw16-betrieb-druckjournal' ) );
		$this->assertSame( 'konstruktion', TimeCategoryGuess::category_for_page( 'lw16-betrieb-artikel' ) );
		$this->assertSame( 'wartung', TimeCategoryGuess::category_for_page( 'lw16-betrieb-drucker' ) );
		$this->assertSame( 'verwaltung', TimeCategoryGuess::category_for_page( 'lw16-buchhaltung' ) );
	}

	/**
	 * Der längere Seitenname gewinnt.
	 *
	 * `lw16-betrieb-lagerplaetze` beginnt mit `lw16-betrieb-lager`. Ohne die
	 * richtige Reihenfolge landete die Fertigung unter Versand.
	 */
	public function test_der_laengere_seitenname_gewinnt() {
		$this->assertSame( 'versand', TimeCategoryGuess::category_for_page( 'lw16-betrieb-lager' ) );
		$this->assertSame( 'produktion', TimeCategoryGuess::category_for_page( 'lw16-betrieb-lagerplaetze' ) );
	}

	/**
	 * Eine unbekannte Seite ist „Sonstiges", nicht geraten.
	 */
	public function test_unbekannte_seite_ist_sonstiges() {
		$this->assertSame( 'sonstiges', TimeCategoryGuess::category_for_page( 'edit.php' ) );
		$this->assertSame( 'sonstiges', TimeCategoryGuess::category_for_page( '' ) );
	}

	/**
	 * Jede vorgeschlagene Kategorie gibt es auch in der Erfassung.
	 *
	 * Sonst käme ein Vorschlag, den das Auswahlfeld nicht anbietet – und die
	 * Prüfung im Repository setzte ihn stillschweigend auf „Sonstiges".
	 */
	public function test_jede_vorgeschlagene_kategorie_ist_erfassbar() {
		$this->assertTrue( TimeCategoryGuess::categories_are_known() );

		foreach ( TimeCategoryGuess::page_categories() as $page => $category ) {
			$this->assertContains( $category, TimeRepository::CATEGORIES, $page );
		}
	}

	/**
	 * Beobachtete Zeit summiert sich je Tätigkeit.
	 */
	public function test_zeit_summiert_sich_je_taetigkeit() {
		$seen = array();
		$seen = TimeCategoryGuess::accumulate( $seen, 'lw16-betrieb-druckjournal', 600 );
		$seen = TimeCategoryGuess::accumulate( $seen, 'lw16-betrieb-qualitaet', 300 );
		$seen = TimeCategoryGuess::accumulate( $seen, 'lw16-buchhaltung', 120 );

		$this->assertSame( 900, $seen['produktion'] );
		$this->assertSame( 120, $seen['verwaltung'] );
	}

	/**
	 * Eine offen gelassene Seite sammelt keine Stunden.
	 *
	 * Wer mittags eine Seite offen lässt und abends wiederkommt, war nicht
	 * sechs Stunden in der Produktion.
	 */
	public function test_offen_gelassene_seite_wird_gekappt() {
		$seen = TimeCategoryGuess::accumulate( array(), 'lw16-betrieb-druckjournal', 6 * 3600 );

		$this->assertSame( 900, $seen['produktion'] );
	}

	/**
	 * Null Sekunden ändern nichts.
	 */
	public function test_null_sekunden_aendern_nichts() {
		$this->assertSame( array(), TimeCategoryGuess::accumulate( array(), 'lw16-betrieb-druckjournal', 0 ) );
		$this->assertSame( array(), TimeCategoryGuess::accumulate( array(), 'lw16-betrieb-druckjournal', -50 ) );
	}

	/**
	 * Eine klare Mehrheit ergibt einen Vorschlag.
	 */
	public function test_klare_mehrheit_ergibt_einen_vorschlag() {
		$vorschlag = TimeCategoryGuess::suggest(
			array( 'produktion' => 2400, 'verwaltung' => 300 )
		);

		$this->assertSame( 'produktion', $vorschlag['category'] );
		$this->assertTrue( $vorschlag['confident'] );
		$this->assertEqualsWithDelta( 2400 / 2700, $vorschlag['share'], 0.0001 );
	}

	/**
	 * Eine knappe Mehrheit ist keine Erkenntnis.
	 *
	 * Bei 55 zu 45 Prozent hat der Nutzer in beiden Bereichen gearbeitet.
	 * Das Feld bleibt dann leer, statt eine Zahl zur Aussage zu machen.
	 */
	public function test_knappe_mehrheit_ist_keine_erkenntnis() {
		$vorschlag = TimeCategoryGuess::suggest(
			array( 'produktion' => 1650, 'verwaltung' => 1350 )
		);

		$this->assertSame( 'produktion', $vorschlag['category'] );
		$this->assertFalse( $vorschlag['confident'] );
	}

	/**
	 * Bei Gleichstand wird nicht gelost.
	 */
	public function test_bei_gleichstand_kein_vorschlag() {
		$vorschlag = TimeCategoryGuess::suggest(
			array( 'produktion' => 1200, 'verwaltung' => 1200 )
		);

		$this->assertSame( '', $vorschlag['category'] );
		$this->assertFalse( $vorschlag['confident'] );
	}

	/**
	 * Zu wenig beobachtete Zeit ergibt keinen Vorschlag.
	 *
	 * Wer die Uhr startet und den Rechner zuklappt, hinterlässt eine
	 * einzige Seitenansicht. Daraus eine Tätigkeit abzuleiten wäre Zufall.
	 */
	public function test_zu_wenig_beobachtung_ergibt_keinen_vorschlag() {
		$vorschlag = TimeCategoryGuess::suggest( array( 'produktion' => 120 ) );

		$this->assertSame( '', $vorschlag['category'] );
		$this->assertFalse( $vorschlag['confident'] );
		$this->assertSame( 120, $vorschlag['seconds'] );
	}

	/**
	 * Ohne Beobachtung gibt es nichts vorzuschlagen.
	 */
	public function test_ohne_beobachtung_kein_vorschlag() {
		$vorschlag = TimeCategoryGuess::suggest( array() );

		$this->assertSame( '', $vorschlag['category'] );
		$this->assertSame( 0, $vorschlag['seconds'] );
	}

	/**
	 * Der Vorschlag wird begründet, nicht behauptet.
	 */
	public function test_der_vorschlag_wird_begruendet() {
		$text = TimeCategoryGuess::explain(
			array( 'verwaltung' => 720, 'produktion' => 2280 )
		);

		// Der grösste Anteil steht vorn.
		$this->assertStringStartsWith( '38 Min. Produktion', $text );
		$this->assertStringContainsString( '12 Min. Verwaltung', $text );
	}

	/**
	 * Die Begründung nennt nicht jede Kleinigkeit.
	 */
	public function test_die_begruendung_bleibt_kurz() {
		$text = TimeCategoryGuess::explain(
			array(
				'produktion'   => 2400,
				'verwaltung'   => 1200,
				'versand'      => 600,
				'konstruktion' => 300,
				'wartung'      => 60,
			),
			3
		);

		$this->assertSame( 2, substr_count( $text, ',' ), 'Drei Bereiche, zwei Kommas.' );
		$this->assertStringNotContainsString( 'Wartung', $text );
	}

	/**
	 * Ohne Beobachtung keine Begründung.
	 */
	public function test_ohne_beobachtung_keine_begruendung() {
		$this->assertSame( '', TimeCategoryGuess::explain( array() ) );
		$this->assertSame( '', TimeCategoryGuess::explain( array( 'produktion' => 0 ) ) );
	}
}
