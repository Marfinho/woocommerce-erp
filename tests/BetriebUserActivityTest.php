<?php
/**
 * Prüft die Lesbarkeit des Protokolls.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\UserActivity;
use PHPUnit\Framework\TestCase;

/**
 * Protokoll-Tests.
 */
class BetriebUserActivityTest extends TestCase {

	/**
	 * Eine Protokollzeile.
	 *
	 * @param array $over Abweichungen.
	 * @return array
	 */
	protected function zeile( array $over = array() ) {
		return array_merge(
			array(
				'aktion'      => 'betrieb_update',
				'objekt_typ'  => 'article_revisions',
				'objekt_id'   => 12,
				'user_id'     => 7,
				'grund'       => '',
				'erstellt_am' => '2026-08-20 09:15:00',
			),
			$over
		);
	}

	/**
	 * Aus Tabellenname und Aktion wird ein Satz.
	 */
	public function test_aus_der_zeile_wird_ein_satz() {
		$this->assertSame( 'Revision #12 geändert', UserActivity::describe( $this->zeile() ) );
		$this->assertSame(
			'Lagerbuchung #3 angelegt',
			UserActivity::describe( $this->zeile( array( 'aktion' => 'betrieb_create', 'objekt_typ' => 'stock_movements', 'objekt_id' => 3 ) ) )
		);
	}

	/**
	 * Ohne Datensatznummer bleibt der Satz trotzdem lesbar.
	 */
	public function test_ohne_nummer_bleibt_der_satz_lesbar() {
		$this->assertSame(
			'Artikel geändert',
			UserActivity::describe( $this->zeile( array( 'objekt_typ' => 'articles', 'objekt_id' => 0 ) ) )
		);
	}

	/**
	 * Ein unbekannter Tabellenname wird durchgereicht statt verschluckt –
	 * lieber technisch als gar nicht.
	 */
	public function test_unbekannte_tabelle_wird_durchgereicht() {
		$this->assertStringContainsString(
			'etwas_neues',
			UserActivity::describe( $this->zeile( array( 'objekt_typ' => 'etwas_neues' ) ) )
		);
	}

	/**
	 * Fremde Protokollzeilen zählen nicht mit: Das Protokoll enthält auch
	 * Vorgänge anderer Module.
	 */
	public function test_fremde_zeilen_zaehlen_nicht_mit() {
		$summe = UserActivity::summarize(
			array(
				$this->zeile(),
				$this->zeile( array( 'aktion' => 'beleg_erfasst' ) ),
			)
		);

		$this->assertSame( 1, $summe['total'] );
	}

	/**
	 * Die Zusammenfassung zählt nach Art und merkt sich den Zeitraum.
	 */
	public function test_zusammenfassung_zaehlt_und_datiert() {
		$summe = UserActivity::summarize(
			array(
				$this->zeile( array( 'aktion' => 'betrieb_create', 'erstellt_am' => '2026-08-01 08:00:00' ) ),
				$this->zeile( array( 'aktion' => 'betrieb_update', 'erstellt_am' => '2026-08-20 09:15:00' ) ),
				$this->zeile( array( 'aktion' => 'betrieb_void', 'erstellt_am' => '2026-08-10 12:00:00' ) ),
			)
		);

		$this->assertSame( 3, $summe['total'] );
		$this->assertSame( 1, $summe['create'] );
		$this->assertSame( 1, $summe['update'] );
		$this->assertSame( 1, $summe['void'] );
		$this->assertSame( '2026-08-01 08:00:00', $summe['first'] );
		$this->assertSame( '2026-08-20 09:15:00', $summe['last'] );
	}

	/**
	 * Die Bereiche stehen nach Häufigkeit.
	 */
	public function test_bereiche_stehen_nach_haeufigkeit() {
		$summe = UserActivity::summarize(
			array(
				$this->zeile( array( 'objekt_typ' => 'stock_movements' ) ),
				$this->zeile( array( 'objekt_typ' => 'stock_movements' ) ),
				$this->zeile( array( 'objekt_typ' => 'time_entries' ) ),
			)
		);

		$this->assertSame( array( 'stock_movements', 'time_entries' ), array_keys( $summe['areas'] ) );
		$this->assertSame( 'Lagerbuchung (2), Zeiteintrag (1)', UserActivity::areas_label( $summe['areas'] ) );
	}

	/**
	 * Ohne Vorgänge bleibt alles bei null, ohne erfundene Daten.
	 */
	public function test_ohne_vorgaenge_bleibt_es_leer() {
		$summe = UserActivity::summarize( array() );

		$this->assertSame( 0, $summe['total'] );
		$this->assertSame( '', $summe['last'] );
		$this->assertSame( array(), $summe['areas'] );
	}

	/**
	 * Jede Tabelle, in die tatsächlich protokolliert wird, hat auch eine
	 * Beschriftung. Sonst stünde im Protokoll ein Tabellenname, den nur
	 * jemand mit Datenbankzugriff versteht.
	 */
	public function test_jede_protokollierte_tabelle_ist_beschriftet() {
		$verzeichnis = dirname( __DIR__ ) . '/includes';
		$bekannt     = array_keys( UserActivity::object_labels() );
		$fehlend     = array();

		$dateien = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $verzeichnis ) );

		foreach ( $dateien as $datei ) {
			if ( 'php' !== $datei->getExtension() ) {
				continue;
			}

			$quelle = file_get_contents( $datei->getPathname() );

			if ( preg_match_all( "/BetriebAudit::record\(\s*'[a-z]+',\s*'([a-z_]+)'/", $quelle, $treffer ) ) {
				foreach ( $treffer[1] as $tabelle ) {
					if ( ! in_array( $tabelle, $bekannt, true ) ) {
						$fehlend[ $tabelle ] = $tabelle;
					}
				}
			}
		}

		$this->assertSame(
			array(),
			array_values( $fehlend ),
			"Diese protokollierten Tabellen haben keine Beschriftung:\n  " . implode( "\n  ", $fehlend )
		);
	}
}
