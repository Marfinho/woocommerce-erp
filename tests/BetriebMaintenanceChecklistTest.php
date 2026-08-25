<?php
/**
 * Prüft die Wartungscheckliste.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\MaintenanceChecklist;
use PHPUnit\Framework\TestCase;

/**
 * Checklisten-Tests.
 */
class BetriebMaintenanceChecklistTest extends TestCase {

	/**
	 * Jedes Intervall hat Punkte – eine leere Pflichtliste wäre eine
	 * Wartung, die man mit einem Klick als erledigt meldet.
	 */
	public function test_jedes_pflichtintervall_hat_punkte() {
		foreach ( array_keys( MaintenanceChecklist::periodic() ) as $interval ) {
			$this->assertNotEmpty(
				MaintenanceChecklist::items( $interval ),
				$interval . ' hat keine Punkte.'
			);
		}
	}

	/**
	 * Die Listen bauen aufeinander auf: Die Monatswartung enthält die
	 * Wochenpunkte, die Jahreswartung beides.
	 */
	public function test_die_listen_bauen_aufeinander_auf() {
		$woche = array_keys( MaintenanceChecklist::items( 'woechentlich' ) );
		$monat = array_keys( MaintenanceChecklist::items( 'monatlich' ) );
		$jahr  = array_keys( MaintenanceChecklist::items( 'jaehrlich' ) );

		$this->assertSame( array(), array_diff( $woche, $monat ) );
		$this->assertSame( array(), array_diff( $monat, $jahr ) );
		$this->assertGreaterThan( count( $woche ), count( $monat ) );
		$this->assertGreaterThan( count( $monat ), count( $jahr ) );
	}

	/**
	 * Die Kennungen sind über alle Listen eindeutig – sonst würde ein
	 * Häkchen zwei Punkte abhaken.
	 */
	public function test_die_kennungen_sind_eindeutig() {
		$alle = array();

		foreach ( array_keys( MaintenanceChecklist::periodic() ) as $interval ) {
			$alle = array_merge( $alle, array_keys( MaintenanceChecklist::own_items( $interval ) ) );
		}

		$this->assertSame( count( $alle ), count( array_unique( $alle ) ) );
	}

	/**
	 * Solange ein Punkt fehlt, ist die Wartung nicht erfasst.
	 */
	public function test_ein_fehlender_punkt_blockiert() {
		$items = array_keys( MaintenanceChecklist::items( 'woechentlich' ) );
		array_pop( $items );

		$check = MaintenanceChecklist::validate( 'woechentlich', $items );

		$this->assertFalse( $check['ok'] );
		$this->assertCount( 1, $check['missing'] );
	}

	/**
	 * Vollständig abgehakt geht durch.
	 */
	public function test_vollstaendig_geht_durch() {
		$check = MaintenanceChecklist::validate(
			'monatlich',
			array_keys( MaintenanceChecklist::items( 'monatlich' ) )
		);

		$this->assertTrue( $check['ok'] );
		$this->assertSame( $check['total'], $check['done'] );
	}

	/**
	 * Die Punkte des grösseren Intervalls reichen für das kleinere nicht
	 * andersherum: Wer monatlich wählt, muss auch die Wochenpunkte machen.
	 */
	public function test_wochenpunkte_reichen_fuer_die_monatswartung_nicht() {
		$check = MaintenanceChecklist::validate(
			'monatlich',
			array_keys( MaintenanceChecklist::items( 'woechentlich' ) )
		);

		$this->assertFalse( $check['ok'] );
	}

	/**
	 * Eine anlassbezogene Wartung hat keine Pflichtliste – wer einen
	 * gerissenen Riemen tauscht, fettet nicht zwangsläufig die Spindel.
	 */
	public function test_anlassbezogene_wartung_hat_keine_pflicht() {
		$this->assertFalse( MaintenanceChecklist::requires_all( 'verschleiss' ) );
		$this->assertTrue( MaintenanceChecklist::validate( 'verschleiss', array() )['ok'] );
	}

	/**
	 * Erfundene Häkchen zählen nicht: Ein Formular liesse sich sonst mit
	 * beliebigen Werten überlisten.
	 */
	public function test_fremde_haekchen_zaehlen_nicht() {
		$check = MaintenanceChecklist::validate( 'woechentlich', array( 'ausgedacht', 'noch_eins' ) );

		$this->assertFalse( $check['ok'] );
		$this->assertSame( 0, $check['done'] );

		$this->assertSame( array(), MaintenanceChecklist::sanitize( 'woechentlich', array( 'ausgedacht' ) ) );
	}

	/**
	 * Gespeichert wird nur, was zur Liste gehört.
	 */
	public function test_gespeichert_wird_nur_bekanntes() {
		$sauber = MaintenanceChecklist::sanitize(
			'woechentlich',
			array( 'platte', 'ausgedacht', 'duese' )
		);

		$this->assertSame( array( 'platte', 'duese' ), $sauber );
	}

	/**
	 * Das Protokoll zeigt später, was tatsächlich abgehakt wurde.
	 */
	public function test_protokolltext_nennt_die_punkte() {
		$text = MaintenanceChecklist::summary( 'woechentlich', array( 'platte' ) );

		$this->assertStringContainsString( 'Druckplatte', $text );
		$this->assertStringContainsString( 'Keine Punkte', MaintenanceChecklist::summary( 'woechentlich', array() ) );
	}

	/**
	 * Die Steuerung im Browser und das erzeugte Markup müssen dieselben
	 * Kennungen benutzen, sonst bleibt der Knopf für immer gesperrt.
	 */
	public function test_markup_und_skript_passen_zusammen() {
		$seite  = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Admin/PrintersPage.php' );
		$skript = file_get_contents( dirname( __DIR__ ) . '/assets/js/betrieb-wartung.js' );

		foreach ( array( 'lw16-wartung', 'lw16-wartung-intervall', 'lw16-wartung-senden', 'lw16-wartung-stand' ) as $id ) {
			$this->assertStringContainsString( $id, $seite, $id . ' fehlt im Markup.' );
			$this->assertStringContainsString( $id, $skript, $id . ' fehlt im Skript.' );
		}

		foreach ( array( 'data-intervall', 'data-pflicht', 'data-offen', 'data-fertig', 'data-frei' ) as $attribut ) {
			$this->assertStringContainsString( $attribut, $seite, $attribut . ' fehlt im Markup.' );
			$this->assertStringContainsString( $attribut, $skript, $attribut . ' fehlt im Skript.' );
		}
	}
}
