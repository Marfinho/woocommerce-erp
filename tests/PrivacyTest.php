<?php
/**
 * Auskunft und Löschung nach DSGVO.
 *
 * Der Wert dieser Schnittstelle steht und fällt mit ihrer Vollständigkeit:
 * Eine Auskunft, die einen Datenbestand verschweigt, ist schlimmer als keine,
 * weil sie Vollständigkeit behauptet. Deshalb prüft der wichtigste Test hier
 * nicht die Ausgabe, sondern ob überhaupt jeder Bestand angemeldet ist.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Core\Privacy;

/**
 * Prüft Vollständigkeit und Zuordnung der Datenbestände.
 */
class PrivacyTest extends TestCase {

	/**
	 * Jeder Bestand ist vollständig beschrieben.
	 */
	public function test_jeder_bestand_ist_vollstaendig_beschrieben() {
		foreach ( Privacy::sources() as $key => $source ) {
			$this->assertNotEmpty( $source['label'], $key . ' ohne Beschriftung.' );
			$this->assertNotEmpty( $source['table'], $key . ' ohne Tabelle.' );
			$this->assertNotEmpty( $source['fields'], $key . ' ohne Felder – eine leere Auskunft nuetzt niemandem.' );
			$this->assertContains( $source['by'], array( 'user', 'email', 'referrer' ), $key . ' mit unbekannter Zuordnung.' );

			foreach ( $source['fields'] as $field => $label ) {
				$this->assertNotEmpty( $label, $key . '.' . $field . ' ohne Beschriftung.' );
			}
		}
	}

	/**
	 * Die Zuordnungsart bestimmt die Spalte.
	 */
	public function test_zuordnung_bestimmt_die_spalte() {
		$this->assertSame( 'user_id', Privacy::column_for( 'user' ) );
		$this->assertSame( 'email', Privacy::column_for( 'email' ) );
		$this->assertSame( 'referrer_user_id', Privacy::column_for( 'referrer' ) );
	}

	/**
	 * Eine unbekannte Zuordnung fällt auf die Benutzerspalte zurück, statt
	 * eine leere Spalte in eine Abfrage zu setzen.
	 */
	public function test_unbekannte_zuordnung_faellt_zurueck() {
		$this->assertSame( 'user_id', Privacy::column_for( 'irgendwas' ) );
	}

	/**
	 * Der eigentliche Test: Jede Tabelle des Plugins, die eine E-Mail oder
	 * eine Benutzerkennung führt, ist entweder angemeldet oder steht mit
	 * Begründung auf der Ausnahmeliste. Ohne diesen Abgleich fällt eine neue
	 * Tabelle mit personenbezogenen Daten niemandem auf.
	 */
	public function test_keine_tabelle_mit_personenbezug_wird_uebersehen() {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/Core/Installer.php' );

		preg_match_all( '/CREATE TABLE \{\$p\}([a-z_]+) \((.*?)\) \{\$charset_collate\}/s', $source, $matches, PREG_SET_ORDER );

		$angemeldet = array();
		foreach ( Privacy::sources() as $entry ) {
			$angemeldet[] = str_replace( 'lw16_', '', $entry['table'] );
		}

		// Bewusste Ausnahmen mit Grund.
		$ausnahmen = array(
			// Aufbewahrungspflicht: Buchhaltung und Belege duerfen nicht
			// auf Zuruf geloescht werden.
			'belege', 'buchungen', 'rechnungen', 'kassenbuch', 'anlagen',
			'dauerbuchungen', 'erechnung_eingang', 'gobd_pruefung',
			// Protokolle: Wer was geaendert hat, ist Nachweis, nicht Kundendatum.
			'audit_log',
			// Ereignisprotokoll des Betriebs: Mitarbeitende, keine Kundendaten; Frist je Stufe (90 Tage bis 3 Jahre).
			'event_log',
			// Betriebsinterne Erfassung durch Mitarbeitende, keine Kundendaten.
			'kasse_tage', 'kasse_positionen', 'lagerbewegung', 'produktion_log',
			'druckauftrag', 'pickliste', 'pickliste_pos',
		);

		$this->assertGreaterThan( 20, count( $matches ), 'Der Installer wurde nicht gelesen – der Test pruefte nichts.' );

		$uebersehen  = array();
		$mit_bezug   = 0;

		foreach ( $matches as $match ) {
			$name    = $match[1];
			$columns = $match[2];

			$hat_bezug = preg_match( '/^\s*(email|user_id|referrer_user_id)\s/mi', $columns );

			if ( ! $hat_bezug ) {
				continue;
			}

			$mit_bezug++;

			if ( in_array( $name, $angemeldet, true ) || in_array( $name, $ausnahmen, true ) ) {
				continue;
			}

			$uebersehen[] = $name;
		}

		$this->assertGreaterThanOrEqual(
			count( Privacy::sources() ),
			$mit_bezug,
			'Es wurden weniger Tabellen mit Personenbezug erkannt als angemeldet sind – das Muster greift nicht mehr.'
		);

		$this->assertSame(
			array(),
			$uebersehen,
			"Diese Tabellen fuehren personenbezogene Daten, sind aber weder fuer Auskunft und Loeschung angemeldet noch als Ausnahme begruendet:\n  "
			. implode( "\n  ", $uebersehen )
		);
	}
}
