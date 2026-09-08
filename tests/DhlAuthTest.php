<?php
/**
 * Anmeldung an der DHL und die Auskunft im Fehlerfall.
 *
 * Ein Label, das nicht entsteht, ist ärgerlich. Ein Label, das nicht
 * entsteht und dabei „keinen Grund" nennt, kostet einen Nachmittag: Man
 * sucht in der Adresse, im Gewicht, in der Abrechnungsnummer – während
 * die DHL längst gesagt hat, dass sie den Zugang nicht kennt.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\Dhl\DhlAuth;
use Lindenweg16\Modules\Betrieb\Dhl\DhlClient;
use Lindenweg16\Modules\Betrieb\Dhl\DhlResponse;

/**
 * Prüft Token-Auswertung und Fehlerauskunft.
 */
class DhlAuthTest extends TestCase {

	/**
	 * Aus der Antwort wird das Token gelesen.
	 */
	public function test_token_wird_gelesen() {
		$ergebnis = DhlAuth::parse(
			array(
				'access_token' => 'abc123',
				'token_type'   => 'Bearer',
				'expires_in'   => 1800,
			),
			200
		);

		$this->assertSame( 'abc123', $ergebnis['token'] );
		$this->assertSame( array(), $ergebnis['messages'] );
	}

	/**
	 * Aufgehoben wird kürzer, als die DHL sagt – ein Token, das während
	 * des Frankierens abläuft, ist keins.
	 */
	public function test_token_wird_vor_ablauf_verworfen() {
		$this->assertSame( 1800 - DhlAuth::PUFFER, DhlAuth::ttl( 1800 ) );
		$this->assertLessThan( 1800, DhlAuth::parse( array( 'access_token' => 'x', 'expires_in' => 1800 ) )['ttl'] );
	}

	/**
	 * Ohne Angabe gilt die halbe Stunde aus der Beschreibung der DHL.
	 */
	public function test_ohne_angabe_gilt_die_halbe_stunde() {
		$this->assertSame( 1800 - DhlAuth::PUFFER, DhlAuth::ttl( 0 ) );
		$this->assertSame( 1800 - DhlAuth::PUFFER, DhlAuth::ttl( null ) );
	}

	/**
	 * Eine unsinnig kurze Angabe führt nicht zu einer negativen Haltezeit.
	 */
	public function test_sehr_kurze_gueltigkeit_wird_nicht_negativ() {
		$this->assertSame( 0, DhlAuth::ttl( 30 ) );
	}

	/**
	 * Ein abgelehnter Zugang nennt den Grund, den die DHL genannt hat.
	 */
	public function test_abgelehnter_zugang_nennt_den_grund() {
		$ergebnis = DhlAuth::parse(
			array(
				'error'             => 'invalid_client',
				'error_description' => 'Client authentication failed',
			),
			401
		);

		$this->assertSame( '', $ergebnis['token'] );
		$this->assertContains( 'Client authentication failed', $ergebnis['messages'] );
	}

	/**
	 * Und dazu, wo man nachsehen muss. Eine nackte 401 sagt dem, der am
	 * Packtisch steht, nichts.
	 */
	public function test_die_401_wird_uebersetzt() {
		$ergebnis = DhlAuth::parse( array(), 401 );

		$this->assertNotEmpty( $ergebnis['messages'] );
		$this->assertStringContainsString( 'API-Secret', implode( ' ', $ergebnis['messages'] ) );
	}

	/**
	 * Ein leerer Rumpf ohne Status bleibt nicht stumm.
	 */
	public function test_leere_antwort_bleibt_nicht_stumm() {
		$this->assertNotEmpty( DhlAuth::parse( array(), 0 )['messages'] );
	}

	/* ----------------------------------------------------------------
	 * Fehlerauskunft der Versandschnittstelle
	 * -------------------------------------------------------------- */

	/**
	 * Der Grund steht bei einem abgewiesenen Zugang flach im Rumpf – ohne
	 * `items`, ohne `status`. Genau dort hat das Plugin nicht gesucht und
	 * deshalb „kein Grund genannt" gemeldet.
	 */
	public function test_flacher_fehlerrumpf_wird_gelesen() {
		$ergebnis = DhlResponse::parse(
			array(
				'title'  => 'Unauthorized',
				'detail' => 'Invalid credentials',
			),
			401
		);

		$this->assertFalse( $ergebnis['ok'] );
		$this->assertContains( 'Invalid credentials', $ergebnis['messages'] );
		$this->assertContains( 'Unauthorized', $ergebnis['messages'] );
	}

	/**
	 * Auch eine Liste unter `errors` wird ausgelesen.
	 */
	public function test_fehlerliste_wird_gelesen() {
		$meldungen = DhlResponse::flat_messages(
			array(
				'errors' => array(
					array( 'detail' => 'Gewicht fehlt' ),
					'Adresse unvollständig',
				),
			)
		);

		$this->assertContains( 'Gewicht fehlt', $meldungen );
		$this->assertContains( 'Adresse unvollständig', $meldungen );
	}

	/**
	 * Jeder Fehlschlag bekommt einen Hinweis zum HTTP-Status dazu – sonst
	 * bleibt „kein Grund genannt" stehen.
	 */
	public function test_jeder_fehlschlag_nennt_etwas() {
		foreach ( array( 0, 400, 401, 403, 429, 500, 503 ) as $code ) {
			$this->assertNotSame( '', DhlResponse::http_hint( $code ), 'HTTP ' . $code );

			$ergebnis = DhlResponse::parse( array(), $code );

			$this->assertFalse( $ergebnis['ok'] );
			$this->assertNotEmpty( $ergebnis['messages'], 'HTTP ' . $code . ' bleibt stumm.' );
		}
	}

	/**
	 * Der Hinweis zur 401 zeigt auf die Zugangsdaten und auf die
	 * Verwechslung von Sandbox und Echtbetrieb – die beiden Ursachen,
	 * die es fast immer sind.
	 */
	public function test_401_zeigt_auf_zugang_und_umgebung() {
		$hinweis = DhlResponse::http_hint( 401 );

		$this->assertStringContainsString( 'API-Secret', $hinweis );
		$this->assertStringContainsString( 'Sandbox', $hinweis );
	}

	/**
	 * Ein erfolgreicher Aufruf bekommt keinen Hinweis angehängt.
	 */
	public function test_erfolg_bekommt_keinen_hinweis() {
		$this->assertSame( '', DhlResponse::http_hint( 200 ) );
		$this->assertSame( '', DhlResponse::http_hint( 207 ) );
	}

	/**
	 * Der Zugang gilt erst als eingerichtet, wenn auch das Secret da ist.
	 * Ein API-Key allein wird von der DHL abgelehnt – das war der Fehler.
	 *
	 * Sandbox und Live haben seit der Trennung der Zugangsdaten getrennte
	 * Einstellungsschlüssel (dhl_versand_sandbox_api_secret /
	 * _live_api_secret), zusammengesetzt über credential_key(). Der Test
	 * prüft deshalb, dass 'api_secret' dort als Feld verlangt wird, statt
	 * einen der beiden festen Schlüssel wörtlich zu suchen.
	 */
	public function test_das_secret_gehoert_zu_den_zugangsdaten() {
		$quelle = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Dhl/DhlClient.php' );

		$this->assertStringContainsString( "credential_key( 'api_secret' )", $quelle );
	}

	/**
	 * Sandbox und Echtbetrieb legen ihr Token getrennt ab. Unter einem
	 * Schlüssel liefe das Umschalten mit dem alten Token weiter – und die
	 * Ablehnung sähe aus wie ein falsches Passwort.
	 */
	public function test_sandbox_und_echtbetrieb_teilen_sich_kein_token() {
		$quelle = file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Betrieb/Dhl/DhlAuth.php' );

		$this->assertStringContainsString( "'_sandbox'", $quelle );
		$this->assertStringContainsString( "'_live'", $quelle );
	}

	/* ----------------------------------------------------------------
	 * Getrennte Zugangsdaten für Sandbox und Live
	 * -------------------------------------------------------------- */

	/**
	 * Ohne Angabe (Vorgabe: Sandbox-Modus) liefert credential_key() die
	 * Sandbox-Einstellungsschlüssel – nie dieselben wie im Live-Betrieb.
	 * Das ist der eigentliche Fehler gewesen: ein Live-Login gegen die
	 * Sandbox-Umgebung endet mit „invalid_grant".
	 */
	public function test_credential_key_unterscheidet_sandbox_und_live() {
		$this->assertSame( 'dhl_versand_sandbox_api_key', DhlClient::credential_key( 'api_key' ) );
		$this->assertSame( 'dhl_versand_sandbox_api_secret', DhlClient::credential_key( 'api_secret' ) );
		$this->assertSame( 'dhl_versand_sandbox_user', DhlClient::credential_key( 'user' ) );
		$this->assertSame( 'dhl_versand_sandbox_password', DhlClient::credential_key( 'password' ) );
	}

	/**
	 * Mit deaktiviertem Sandbox-Haken liefert credential_key() die
	 * Live-Schlüssel – nie die Sandbox-Werte, auch nicht versehentlich.
	 */
	public function test_credential_key_im_livebetrieb() {
		$GLOBALS['lw16_test_options']['lw16_settings'] = array( 'dhl_versand_sandbox' => 0 );

		try {
			$this->assertSame( 'dhl_versand_live_api_key', DhlClient::credential_key( 'api_key' ) );
			$this->assertSame( 'dhl_versand_live_api_secret', DhlClient::credential_key( 'api_secret' ) );
			$this->assertSame( 'dhl_versand_live_user', DhlClient::credential_key( 'user' ) );
			$this->assertSame( 'dhl_versand_live_password', DhlClient::credential_key( 'password' ) );
		} finally {
			unset( $GLOBALS['lw16_test_options']['lw16_settings'] );
		}
	}
}
