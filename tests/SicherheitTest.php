<?php
/**
 * Regressionstests aus dem Penetrationstest vom 27.11.2026.
 *
 * 1. Rechteprüfung als permission_callback: WordPress übergibt die Anfrage
 *    als erstes Argument. `LiveViewer::can_open( $user_id = 0 )` machte aus
 *    `(int) $request` die 1 – den ersten Administrator –, und /erp/live
 *    antwortete jedem anonymen Besucher.
 * 2. Claude-Zugang: SqlGuard sperrte nur Abfragen, die `user_pass` beim
 *    Namen nennen. `SELECT * FROM wp_users`, `meta_key LIKE 'sess%'` und
 *    `lw16_settings` lieferten Passwort-Hashes, Sitzungen und die
 *    DHL-Zugangsdaten. Der Redactor maskiert jetzt die Antwort.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Erp\Access;
use Lindenweg16\Mcp\Redactor;

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Testersatz: angemeldete Person.
	 *
	 * @return int
	 */
	function get_current_user_id() {
		return (int) ( $GLOBALS['lw16_test_current_user'] ?? 0 );
	}
}

class SicherheitTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['lw16_test_current_user'] );
	}

	public function test_ein_objekt_ist_nie_benutzer_eins() {
		$GLOBALS['lw16_test_current_user'] = 0;

		$request = new stdClass();
		$this->assertSame( 0, Access::user_id( $request ), 'Die Anfrage ist kein Benutzer – angemeldet ist niemand.' );
		$this->assertSame( 0, Access::user_id( array( 1 ) ) );
		$this->assertSame( 0, Access::user_id( '1abc' ) );
		$this->assertSame( 0, Access::user_id( true ), 'true ist nicht Benutzer 1.' );
		$this->assertSame( 0, Access::user_id( 0 ) );

		$this->assertSame( 7, Access::user_id( 7 ) );
		$this->assertSame( 7, Access::user_id( '7' ) );

		$GLOBALS['lw16_test_current_user'] = 5;
		$this->assertSame( 5, Access::user_id( $request ), 'Ohne Zahl gilt die angemeldete Person.' );
		$this->assertSame( 5, Access::user_id( -3 ) );
	}

	public function test_permission_callbacks_lesen_die_anfrage_nicht_als_benutzer() {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/Erp/LiveViewer.php' );

		$this->assertStringContainsString( 'Access::user_id( $user_id )', $src );
		$this->assertStringNotContainsString( 'get_userdata( (int) $user_id )', $src );
	}

	public function test_passwort_hash_und_geheime_spalten_werden_maskiert() {
		$rows = Redactor::rows(
			array(
				array(
					'ID'                  => '1',
					'user_login'          => 'admin',
					'user_pass'           => '$wp$2y$10$abcdefghijklmnopqrstuv',
					'user_activation_key' => '',
					'user_email'          => 'admin@example.com',
				),
			)
		);

		$this->assertSame( Redactor::MASK, $rows[0]['user_pass'] );
		$this->assertSame( '', $rows[0]['user_activation_key'], 'Leer bleibt leer.' );
		$this->assertSame( 'admin', $rows[0]['user_login'] );
		$this->assertSame( 'admin@example.com', $rows[0]['user_email'] );
	}

	public function test_hash_wird_auch_unter_anderem_namen_erkannt() {
		$rows = Redactor::rows( array( array( 'x' => '$P$Babcdefghijklmnopqrstuvwxyz' ), array( 'x' => '$2y$10$abc' ) ) );

		$this->assertSame( Redactor::MASK, $rows[0]['x'] );
		$this->assertSame( Redactor::MASK, $rows[1]['x'] );
	}

	public function test_geheime_meta_und_optionen_nach_ihrem_namen() {
		$rows = Redactor::rows(
			array(
				array( 'meta_key' => 'session_tokens', 'meta_value' => 'a:1:{s:3:"abc";a:0:{}}' ),
				array( 'option_name' => 'irgendein_api_key', 'option_value' => 'k-123' ),
				array( 'option_name' => 'blogname', 'option_value' => 'Lindenweg16' ),
			)
		);

		$this->assertSame( Redactor::MASK, $rows[0]['meta_value'] );
		$this->assertSame( Redactor::MASK, $rows[1]['option_value'] );
		$this->assertSame( 'Lindenweg16', $rows[2]['option_value'] );
	}

	public function test_serialisierte_einstellungen_werden_einzeln_bereinigt() {
		$settings = serialize(
			array(
				'firma_name'                  => 'Lindenweg16',
				'dhl_versand_live_password'   => 'geheim',
				'dhl_versand_live_api_secret' => 'auch-geheim',
				'dhl_versand_live_api_key'    => 'schluessel',
				'smtp'                        => array( 'host' => 'mail.example', 'passwort' => 'xyz' ),
			)
		);

		$rows  = Redactor::rows( array( array( 'option_value' => $settings ) ) );
		$clean = $rows[0]['option_value'];

		$this->assertIsArray( $clean );
		$this->assertSame( 'Lindenweg16', $clean['firma_name'] );
		$this->assertSame( Redactor::MASK, $clean['dhl_versand_live_password'] );
		$this->assertSame( Redactor::MASK, $clean['dhl_versand_live_api_secret'] );
		$this->assertSame( Redactor::MASK, $clean['dhl_versand_live_api_key'] );
		$this->assertSame( Redactor::MASK, $clean['smtp']['passwort'] );
		$this->assertSame( 'mail.example', $clean['smtp']['host'] );
	}

	public function test_serialisierte_objekte_werden_nicht_erzeugt() {
		$evil = 'O:8:"stdClass":1:{s:8:"password";s:3:"abc";}';
		$out  = Redactor::scrub( $evil );

		$this->assertIsArray( $out, 'Objekte kommen höchstens als __PHP_Incomplete_Class-Daten zurück, nie als echte Klasse.' );
		$this->assertSame( Redactor::MASK, $out['password'] );
		$this->assertStringNotContainsString( 'abc', json_encode( $out ) );
	}

	public function test_rest_antworten_und_json() {
		$data = Redactor::scrub(
			array(
				'name'     => 'Webhook',
				'secret'   => 's3cr3t',
				'settings' => array( 'api_key' => array( 'value' => 'pk_live' ), 'title' => 'PayPal' ),
				'json'     => '{"token":"t-1","ok":true}',
				'count'    => 3,
			)
		);

		$this->assertSame( 'Webhook', $data['name'] );
		$this->assertSame( Redactor::MASK, $data['secret'] );
		$this->assertSame( Redactor::MASK, $data['settings']['api_key'] );
		$this->assertSame( 'PayPal', $data['settings']['title'] );
		$this->assertSame( Redactor::MASK, $data['json']['token'] );
		$this->assertTrue( $data['json']['ok'] );
		$this->assertSame( 3, $data['count'] );
	}

	public function test_harmlose_daten_bleiben_unveraendert() {
		$in = array(
			array( 'id' => '5', 'title' => 'Haken', 'status' => 'completed', 'passiv' => '1', 'total' => '19.90' ),
		);

		$this->assertSame( $in, Redactor::rows( $in ) );
	}
}
