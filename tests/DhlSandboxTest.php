<?php
/**
 * Auf wen die Testumgebung der DHL bucht.
 *
 * Benutzer und Passwort der Sandbox stehen als Vorgabe in den
 * Einstellungen – für die Sandbox legt man keinen Benutzer an, die DHL
 * stellt einen festen Demo-Zugang. Die Kundennummer aber steht dort
 * nicht und gehört auch nicht hin: Die Sandbox bucht auf eine
 * Testkundennummer der DHL. Schickte man die echte mit, entstünde eine
 * Abrechnungsnummer, die es in der Testumgebung nicht gibt – und die
 * Ablehnung sähe nach einem Fehler in der Adresse aus.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\Dhl\DhlSandbox;
use Lindenweg16\Modules\Betrieb\Dhl\DhlProduct;

/**
 * Prüft die Weiche zwischen Testkundennummer und eigener Kundennummer.
 */
class DhlSandboxTest extends TestCase {

	/**
	 * Eigene Angaben, wie sie aus den Einstellungen kämen.
	 *
	 * @return array
	 */
	protected function eigene() {
		return array(
			'ekp'       => '1234567890',
			'teilnahme' => '01',
			'profil'    => 'MEIN_PROFIL',
		);
	}

	/**
	 * Im Echtbetrieb gilt, was der Betreiber eingetragen hat – da darf
	 * nichts überschrieben werden.
	 */
	public function test_echtbetrieb_bucht_auf_die_eigene_kundennummer() {
		$this->assertSame( $this->eigene(), DhlSandbox::billing( false, $this->eigene() ) );
	}

	/**
	 * In der Sandbox gilt die Testkundennummer – auch dann, wenn eine
	 * eigene dasteht. Genau die gibt es dort nämlich nicht.
	 */
	public function test_sandbox_bucht_auf_die_testkundennummer() {
		$zugang = DhlSandbox::billing( true, $this->eigene() );

		$this->assertSame( DhlSandbox::EKP, $zugang['ekp'] );
		$this->assertSame( DhlSandbox::TEILNAHME, $zugang['teilnahme'] );
		$this->assertSame( DhlSandbox::PROFIL, $zugang['profil'] );
		$this->assertNotSame( '1234567890', $zugang['ekp'] );
	}

	/**
	 * Aus den Demo-Angaben entsteht genau die Abrechnungsnummer, die die
	 * DHL in ihren eigenen Beispielen nennt. Weicht sie ab, stimmt eine
	 * der Konstanten nicht.
	 */
	public function test_demo_abrechnungsnummer_stimmt_mit_dem_beispiel_der_dhl() {
		$zugang = DhlSandbox::billing( true, array() );

		$this->assertSame(
			'33333333330102',
			DhlProduct::billing_number( $zugang['ekp'], 'V01PAK', $zugang['teilnahme'] )
		);
	}

	/**
	 * Nach der Kundennummer wird nur im Echtbetrieb gefragt.
	 */
	public function test_nach_der_kundennummer_wird_nur_im_echtbetrieb_gefragt() {
		$this->assertFalse( DhlSandbox::needs_own_ekp( true ) );
		$this->assertTrue( DhlSandbox::needs_own_ekp( false ) );
	}

	/**
	 * Der Demo-Zugang der DHL steht als Vorgabe in den Einstellungen –
	 * sonst suchte man einen Benutzer, den es nirgends zu holen gibt.
	 */
	public function test_der_demo_zugang_ist_vorbelegt() {
		$quelle = file_get_contents( dirname( __DIR__ ) . '/includes/Core/Settings.php' );

		$this->assertStringContainsString( DhlSandbox::USER, $quelle );
		$this->assertStringContainsString( DhlSandbox::PASSWORD, $quelle );
	}

	/**
	 * Beide Stellen, die über die Umgebung entscheiden, benutzen die
	 * Weiche: die Vollständigkeitsprüfung und der Sendungsauftrag. Bliebe
	 * eine aussen vor, verlangte das Plugin in der Sandbox eine
	 * Kundennummer, die es nicht gibt – oder buchte mit ihr.
	 */
	public function test_beide_stellen_benutzen_die_weiche() {
		$basis = dirname( __DIR__ ) . '/includes/Modules/Betrieb/Dhl/';

		foreach ( array( 'DhlClient.php', 'DhlShipping.php' ) as $datei ) {
			$this->assertStringContainsString(
				'DhlSandbox::',
				file_get_contents( $basis . $datei ),
				$datei . ' entscheidet nicht über die Weiche.'
			);
		}
	}

	/**
	 * Zugangsdaten wählt der Client je Umgebung getrennt – Sandbox und
	 * Echtbetrieb haben eigene Anwendungen bei der DHL.
	 */
	public function test_zugangsdaten_haengen_an_der_umgebung() {
		$quelle = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Dhl/DhlClient.php' );

		$this->assertStringContainsString( 'credential_key', $quelle );
	}
}
