<?php
/**
 * Menü und Seite müssen dasselbe Recht verlangen.
 *
 * Der Fehler, den dieser Test findet, ist leise: Das Menü bietet eine Seite
 * mit `lw16_capture` an, die Seite selbst verlangt aber `lw16_manage_production`.
 * Der Werkstatt-Nutzer sieht den Menüpunkt, klickt und bekommt „Keine
 * Berechtigung". Nichts stürzt ab, kein Protokoll schlägt an – es
 * funktioniert nur für genau die Leute nicht, für die das Profil gedacht war.
 *
 * Umgekehrt ist es schlimmer: Verlangte eine Seite weniger als das Menü,
 * käme man über die Adresse hinein, ohne den Menüpunkt zu sehen. Deshalb
 * prüft der Test beide Richtungen.
 *
 * Gelesen wird der Quelltext, nicht ein nachgebautes Verhalten – sonst
 * prüfte der Test seine eigene Nachbildung.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Betrieb\AccessProfiles;
use Lindenweg16\Modules\Betrieb\BetriebModule;
use PHPUnit\Framework\TestCase;

/**
 * Rechte-Tests der Seiten.
 */
class BetriebSeitenrechteTest extends TestCase {

	/**
	 * Das Recht, das eine Renderfunktion verlangt.
	 *
	 * @param string $source Quelltext.
	 * @param string $method Methode.
	 * @return string|null
	 */
	protected function required_cap( $source, $method ) {
		if ( ! preg_match( '/public function ' . preg_quote( $method, '/' ) . '\(\)\s*\{(.*?)\n\t\}/s', $source, $body ) ) {
			return null;
		}

		if ( ! preg_match( "/require_cap\(\s*(?:'(\w+)'|AccessProfiles::(\w+))?\s*\)/", $body[1], $call ) ) {
			return null;
		}

		if ( ! empty( $call[1] ) ) {
			return $call[1];
		}

		if ( ! empty( $call[2] ) ) {
			$konstante = 'Lindenweg16\Modules\Betrieb\AccessProfiles::' . $call[2];

			return defined( $konstante ) ? constant( $konstante ) : null;
		}

		// Ohne Argument gilt die Vorgabe der Klasse. Fehlt der Parameter,
		// ist das Recht fest verdrahtet.
		if ( preg_match( "/protected function require_cap\(\s*\\\$cap = '(\w+)'/", $source, $default ) ) {
			return $default[1];
		}

		if ( preg_match( "/protected function require_cap\(\s*\)\s*\{\s*if \( ! Roles::current_user_can_betrieb\(\s*(?:'(\w+)'|AccessProfiles::(\w+))\s*\)/s", $source, $fest ) ) {
			if ( ! empty( $fest[1] ) ) {
				return $fest[1];
			}

			$konstante = 'Lindenweg16\Modules\Betrieb\AccessProfiles::' . $fest[2];

			return defined( $konstante ) ? constant( $konstante ) : null;
		}

		return null;
	}

	/**
	 * Alle Seiten mit ihrer Renderfunktion und deren Recht.
	 *
	 * @return array Slug => {menu, page, file}.
	 */
	protected function pages() {
		$out = array();

		foreach ( glob( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Admin/*.php' ) as $file ) {
			$source = file_get_contents( $file );

			preg_match_all(
				"/add_action\(\s*'lw16_betrieb_render_(\w+)',\s*array\(\s*\\\$this,\s*'(\w+)'\s*\)/",
				$source,
				$treffer,
				PREG_SET_ORDER
			);

			foreach ( $treffer as $eintrag ) {
				$slug = str_replace( '_', '-', $eintrag[1] );

				$out[ $slug ] = array(
					'page' => $this->required_cap( $source, $eintrag[2] ),
					'file' => basename( $file ),
				);
			}
		}

		return $out;
	}

	/**
	 * Der Test liest überhaupt etwas – sonst schweigt er sich grün.
	 */
	public function test_der_test_findet_die_seiten() {
		$seiten = $this->pages();

		$this->assertGreaterThanOrEqual( 10, count( $seiten ) );

		foreach ( $seiten as $slug => $daten ) {
			$this->assertNotNull( $daten['page'], $slug . ' (' . $daten['file'] . '): kein require_cap gefunden.' );
		}
	}

	/**
	 * Jede Seite verlangt genau das Recht, mit dem das Menü sie anbietet.
	 */
	public function test_menue_und_seite_verlangen_dasselbe_recht() {
		$menue = BetriebModule::pages();

		foreach ( $this->pages() as $slug => $daten ) {
			$this->assertArrayHasKey( $slug, $menue, $slug . ' steht in keinem Menü.' );

			$this->assertSame(
				$menue[ $slug ][1],
				$daten['page'],
				$slug . ' (' . $daten['file'] . '): Das Menü verlangt ' . $menue[ $slug ][1]
					. ', die Seite verlangt ' . $daten['page'] . '.'
			);
		}
	}

	/**
	 * Jedes im Menü verlangte Recht gibt es auch wirklich.
	 */
	public function test_die_verlangten_rechte_existieren() {
		$bekannt = array_keys( AccessProfiles::capabilities() );

		foreach ( BetriebModule::pages() as $slug => $eintrag ) {
			$this->assertContains( $eintrag[1], $bekannt, $slug );
		}
	}

	/**
	 * Die Werkstatt kommt auf jede Seite, die ihr das Menü anbietet.
	 *
	 * Das ist die Aussage in Klartext: Was das Profil verspricht, muss es
	 * auch können.
	 */
	public function test_die_werkstatt_kommt_auf_ihre_seiten() {
		$profile = AccessProfiles::profiles();
		$caps    = $profile['werkstatt']['caps'];
		$seiten  = $this->pages();
		$menue   = BetriebModule::pages();

		$offen = array();

		foreach ( $menue as $slug => $eintrag ) {
			if ( in_array( $eintrag[1], $caps, true ) ) {
				$offen[] = $slug;
			}
		}

		$this->assertNotEmpty( $offen, 'Die Werkstatt käme nirgends hin.' );

		foreach ( $offen as $slug ) {
			if ( ! isset( $seiten[ $slug ] ) ) {
				continue;
			}

			$this->assertContains(
				$seiten[ $slug ]['page'],
				$caps,
				$slug . ': Die Werkstatt sieht den Menüpunkt, wird aber abgewiesen.'
			);
		}
	}
}
