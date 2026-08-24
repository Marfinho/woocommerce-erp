<?php
/**
 * Prüft die Rechteprofile.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\AccessProfiles;
use Lindenweg16\Modules\Betrieb\BetriebModule;
use PHPUnit\Framework\TestCase;

/**
 * Rechte-Tests.
 */
class BetriebAccessProfilesTest extends TestCase {

	/**
	 * Die Werkstatt darf erfassen – und sonst nichts.
	 *
	 * Das ist der Kern der Sache: Eine Aushilfe soll arbeiten und
	 * protokollieren, aber weder Stammdaten noch Einstellungen ändern und
	 * keine Kosten sehen.
	 */
	public function test_werkstatt_darf_nur_erfassen() {
		$caps = AccessProfiles::caps( 'werkstatt' );

		$this->assertSame( array( AccessProfiles::CAP_CAPTURE ), $caps );

		foreach ( array(
			AccessProfiles::CAP_PRODUCTION,
			AccessProfiles::CAP_REPORTS,
			AccessProfiles::CAP_COSTS,
			AccessProfiles::CAP_SETTINGS,
			AccessProfiles::CAP_USERS,
		) as $verboten ) {
			$this->assertFalse( AccessProfiles::allows( $caps, $verboten ), $verboten . ' darf die Werkstatt nicht haben.' );
		}
	}

	/**
	 * Fertigung pflegt Stammdaten, sieht aber kein Geld.
	 */
	public function test_fertigung_sieht_keine_kosten() {
		$caps = AccessProfiles::caps( 'fertigung' );

		$this->assertTrue( AccessProfiles::allows( $caps, AccessProfiles::CAP_PRODUCTION ) );
		$this->assertTrue( AccessProfiles::allows( $caps, AccessProfiles::CAP_REPORTS ) );
		$this->assertFalse( AccessProfiles::allows( $caps, AccessProfiles::CAP_COSTS ) );
	}

	/**
	 * Die Leitung sieht die Kalkulation, vergibt aber keine Rechte.
	 */
	public function test_leitung_vergibt_keine_rechte() {
		$caps = AccessProfiles::caps( 'leitung' );

		$this->assertTrue( AccessProfiles::allows( $caps, AccessProfiles::CAP_COSTS ) );
		$this->assertFalse( AccessProfiles::allows( $caps, AccessProfiles::CAP_SETTINGS ) );
		$this->assertFalse( AccessProfiles::allows( $caps, AccessProfiles::CAP_USERS ) );
	}

	/**
	 * Vollzugriff heisst wirklich alle Rechte – sonst wäre der Name falsch.
	 */
	public function test_vollzugriff_hat_alle_rechte() {
		$caps = AccessProfiles::caps( 'admin' );

		foreach ( array_keys( AccessProfiles::capabilities() ) as $cap ) {
			$this->assertTrue( AccessProfiles::allows( $caps, $cap ), $cap . ' fehlt im Vollzugriff.' );
		}
	}

	/**
	 * Kein Zugriff ist kein Zugriff.
	 */
	public function test_kein_zugriff_ist_leer() {
		$this->assertSame( array(), AccessProfiles::caps( 'kein' ) );
	}

	/**
	 * Die Profile bauen aufeinander auf: Jedes höhere enthält alles, was
	 * das darunter darf. Ohne diese Ordnung wäre eine Beförderung ein
	 * Rechteverlust.
	 */
	public function test_die_profile_bauen_aufeinander_auf() {
		$reihe = array( 'kein', 'werkstatt', 'fertigung', 'leitung', 'admin' );

		for ( $i = 1; $i < count( $reihe ); $i++ ) {
			$unten = AccessProfiles::caps( $reihe[ $i - 1 ] );
			$oben  = AccessProfiles::caps( $reihe[ $i ] );

			$this->assertSame(
				array(),
				array_diff( $unten, $oben ),
				$reihe[ $i ] . ' hat weniger als ' . $reihe[ $i - 1 ] . '.'
			);
		}
	}

	/**
	 * Aus vorhandenen Rechten wird das Profil wiedererkannt.
	 */
	public function test_profil_wird_wiedererkannt() {
		$this->assertSame( 'werkstatt', AccessProfiles::match( array( AccessProfiles::CAP_CAPTURE ) ) );
		$this->assertSame( 'kein', AccessProfiles::match( array() ) );
		$this->assertSame( 'leitung', AccessProfiles::match( array_reverse( AccessProfiles::caps( 'leitung' ) ) ) );
	}

	/**
	 * Passt keine Zusammenstellung genau, wird das gesagt statt geraten.
	 */
	public function test_unpassende_rechte_ergeben_kein_profil() {
		$this->assertSame(
			'',
			AccessProfiles::match( array( AccessProfiles::CAP_SETTINGS ) )
		);
	}

	/**
	 * Jedes Recht ist erklärt. Ein Recht ohne Erklärung wird falsch
	 * vergeben.
	 */
	public function test_jedes_recht_ist_erklaert() {
		$this->assertSame(
			array_keys( AccessProfiles::capabilities() ),
			array_keys( AccessProfiles::explanations() )
		);
	}

	/**
	 * Jede Seite hängt an einem Recht, das es auch gibt.
	 */
	public function test_jede_seite_haengt_an_einem_bekannten_recht() {
		$bekannt = array_keys( AccessProfiles::capabilities() );

		foreach ( BetriebModule::pages() as $slug => $page ) {
			$this->assertContains( $page[1], $bekannt, $slug . ' verlangt ein unbekanntes Recht.' );
		}
	}

	/**
	 * Die Werkstatt kommt an die Seiten, die sie zum Arbeiten braucht –
	 * und nicht an die anderen.
	 */
	public function test_werkstatt_sieht_die_arbeitsseiten() {
		$caps  = AccessProfiles::caps( 'werkstatt' );
		$pages = BetriebModule::pages();

		foreach ( array( 'lw16-betrieb-lagerplaetze', 'lw16-betrieb-druckjournal', 'lw16-betrieb-zeit', 'lw16-betrieb-lager' ) as $slug ) {
			$this->assertTrue( AccessProfiles::allows( $caps, $pages[ $slug ][1] ), $slug . ' bleibt der Werkstatt verschlossen.' );
		}

		foreach ( array( 'lw16-betrieb-einstellungen', 'lw16-betrieb-benutzer', 'lw16-betrieb-berichte', 'lw16-betrieb-artikel' ) as $slug ) {
			$this->assertFalse( AccessProfiles::allows( $caps, $pages[ $slug ][1] ), $slug . ' steht der Werkstatt offen.' );
		}
	}
}
