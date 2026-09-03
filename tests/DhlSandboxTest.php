<?php
/**
 * Die Testumgebung der DHL.
 *
 * Für die Sandbox legt man keinen Benutzer an – es gibt im
 * Geschäftskundenportal keinen zum Anlegen. Die DHL stellt einen festen
 * Demo-Zugang. Wer das nicht weiß, trägt seine echten Daten ein, lässt
 * den Sandbox-Haken stehen und bekommt eine Ablehnung, die wie ein
 * Tippfehler im Passwort aussieht.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\Dhl\DhlSandbox;
use Lindenweg16\Modules\Betrieb\Dhl\DhlProduct;

/**
 * Prüft die Weiche zwischen Demo-Zugang und eigenem Zugang.
 */
class DhlSandboxTest extends TestCase {

	/**
	 * Eigene Angaben, wie sie aus den Einstellungen kämen.
	 *
	 * @return array
	 */
	protected function eigene() {
		return array(
			'user'      => 'meine-kennung',
			'password'  => 'mein-passwort',
			'ekp'       => '1234567890',
			'teilnahme' => '01',
			'profil'    => 'MEIN_PROFIL',
		);
	}

	/**
	 * Im Echtbetrieb gilt, was der Betreiber eingetragen hat – da darf
	 * nichts überschrieben werden.
	 */
	public function test_echtbetrieb_nimmt_die_eigenen_angaben() {
		$this->assertSame( $this->eigene(), DhlSandbox::credentials( false, $this->eigene() ) );
	}

	/**
	 * In der Sandbox gilt der Demo-Zugang – auch dann, wenn eigene
	 * Angaben dastehen. Genau die funktionieren dort nämlich nicht.
	 */
	public function test_sandbox_nimmt_den_demo_zugang() {
		$zugang = DhlSandbox::credentials( true, $this->eigene() );

		$this->assertSame( DhlSandbox::USER, $zugang['user'] );
		$this->assertSame( DhlSandbox::PASSWORD, $zugang['password'] );
		$this->assertNotSame( 'meine-kennung', $zugang['user'] );
	}

	/**
	 * Auch gebucht wird in der Sandbox auf die Testkundennummer. Die
	 * echte EKP ergäbe dort eine Abrechnungsnummer, die es nicht gibt.
	 */
	public function test_sandbox_bucht_auf_die_testkundennummer() {
		$zugang = DhlSandbox::credentials( true, $this->eigene() );

		$this->assertSame( DhlSandbox::EKP, $zugang['ekp'] );
		$this->assertSame( DhlSandbox::TEILNAHME, $zugang['teilnahme'] );
	}

	/**
	 * Aus dem Demo-Zugang entsteht die Abrechnungsnummer, die die DHL in
	 * ihren eigenen Beispielen nennt. Weicht sie ab, stimmt eine der
	 * Konstanten nicht.
	 */
	public function test_demo_abrechnungsnummer_stimmt_mit_dem_beispiel_der_dhl() {
		$zugang = DhlSandbox::credentials( true, array() );

		$this->assertSame(
			'33333333330102',
			DhlProduct::billing_number( $zugang['ekp'], 'V01PAK', $zugang['teilnahme'] )
		);
	}

	/**
	 * In der Sandbox wird nur nach den Daten der eigenen Anwendung
	 * gefragt. Nach einem Benutzer zu fragen, den es nirgends zu holen
	 * gibt, schickt den Betreiber auf die Suche.
	 */
	public function test_sandbox_verlangt_nur_die_anwendungsdaten() {
		$noetig = DhlSandbox::required_settings( true );

		$this->assertContains( 'dhl_versand_api_key', $noetig );
		$this->assertContains( 'dhl_versand_api_secret', $noetig );
		$this->assertNotContains( 'dhl_versand_user', $noetig );
		$this->assertNotContains( 'dhl_versand_password', $noetig );
		$this->assertNotContains( 'dhl_versand_ekp', $noetig );
	}

	/**
	 * Im Echtbetrieb gehört alles dazu.
	 */
	public function test_echtbetrieb_verlangt_alles() {
		$noetig = DhlSandbox::required_settings( false );

		foreach ( array( 'dhl_versand_api_key', 'dhl_versand_api_secret', 'dhl_versand_user', 'dhl_versand_password', 'dhl_versand_ekp' ) as $key ) {
			$this->assertContains( $key, $noetig );
		}
	}

	/**
	 * Anmeldung, Kopfzeilen und Sendungsauftrag gehen alle drei über
	 * dieselbe Weiche. Bliebe eine davon aussen vor, meldete sich das
	 * Plugin als Demo an und buchte auf die echte Kundennummer – oder
	 * umgekehrt.
	 */
	public function test_alle_drei_stellen_benutzen_die_weiche() {
		$basis = dirname( __DIR__ ) . '/includes/Modules/Betrieb/Dhl/';

		foreach ( array( 'DhlAuth.php', 'DhlClient.php', 'DhlShipping.php' ) as $datei ) {
			$this->assertStringContainsString(
				'DhlSandbox::',
				file_get_contents( $basis . $datei ),
				$datei . ' entscheidet nicht über die Weiche.'
			);
		}
	}
}
