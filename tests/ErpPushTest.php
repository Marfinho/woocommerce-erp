<?php
/**
 * Web-Push: reine Teile (Endpunkt, Signatur, Token) und Quelltext-Wächter.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Push;
use PHPUnit\Framework\TestCase;

class ErpPushTest extends TestCase {

	public function test_b64url_hat_keine_auffuellung() {
		$this->assertSame( 'AQID', Push::b64url( "\x01\x02\x03" ) );
		$this->assertSame( '-_8', Push::b64url( "\xfb\xff" ) );
	}

	public function test_endpunkt_nur_https_ohne_zugangsdaten() {
		$this->assertTrue( Push::valid_endpoint( 'https://fcm.googleapis.com/fcm/send/abc' ) );
		$this->assertTrue( Push::valid_endpoint( 'https://web.push.apple.com/QWxs' ) );
		$this->assertFalse( Push::valid_endpoint( 'http://fcm.googleapis.com/x' ) );
		$this->assertFalse( Push::valid_endpoint( 'https://user:pw@fcm.googleapis.com/x' ) );
		$this->assertFalse( Push::valid_endpoint( 'https://localhost/x' ) );
		$this->assertFalse( Push::valid_endpoint( 'https://example.com:8443/x' ) );
		$this->assertFalse( Push::valid_endpoint( '' ) );
		$this->assertFalse( Push::valid_endpoint( null ) );
		$this->assertFalse( Push::valid_endpoint( 'https://a.example.com/' . str_repeat( 'x', 1001 ) ) );
	}

	public function test_geraete_kennung_ist_stabil() {
		$a = Push::device_id( 'https://a.example.com/1' );
		$this->assertSame( 32, strlen( $a ) );
		$this->assertSame( $a, Push::device_id( 'https://a.example.com/1' ) );
		$this->assertNotSame( $a, Push::device_id( 'https://a.example.com/2' ) );
	}

	public function test_jwt_signatur_ist_gueltig() {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			$this->markTestSkipped( 'OpenSSL fehlt.' );
		}
		$res = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
		if ( ! $res ) {
			$this->markTestSkipped( 'OpenSSL kann keine EC-Schlüssel erzeugen.' );
		}
		$pem = '';
		openssl_pkey_export( $res, $pem );
		$pub = openssl_pkey_get_details( $res )['key'];

		$jwt = Push::build_jwt(
			'https://fcm.googleapis.com',
			'mailto:a@example.com',
			2000000000,
			static function ( $input ) use ( $pem ) {
				$sig = '';
				openssl_sign( $input, $sig, $pem, OPENSSL_ALGO_SHA256 );
				return $sig;
			}
		);
		$parts = explode( '.', $jwt );
		$this->assertCount( 3, $parts );
		$this->assertStringNotContainsString( '=', $jwt );

		$payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
		$this->assertSame( 'https://fcm.googleapis.com', $payload['aud'] );
		$this->assertSame( 2000000000, $payload['exp'] );

		$raw = base64_decode( strtr( $parts[2], '-_', '+/' ) );
		$this->assertSame( 64, strlen( $raw ) );
		// Zurück nach DER und gegen den öffentlichen Schlüssel prüfen.
		$int = static function ( $b ) {
			$b = ltrim( $b, "\x00" );
			if ( '' === $b || ord( $b[0] ) > 127 ) {
				$b = "\x00" . $b;
			}
			return "\x02" . chr( strlen( $b ) ) . $b;
		};
		$body = $int( substr( $raw, 0, 32 ) ) . $int( substr( $raw, 32 ) );
		$der  = "\x30" . chr( strlen( $body ) ) . $body;
		$this->assertSame( 1, openssl_verify( $parts[0] . '.' . $parts[1], $der, $pub, OPENSSL_ALGO_SHA256 ) );
	}

	public function test_kaputte_signatur_ergibt_kein_token() {
		$this->assertSame( '', Push::build_jwt( 'https://x.example.com', 'mailto:a@b.de', 1, static function () { return 'kaputt'; } ) );
		$this->assertSame( '', Push::der_to_raw( '' ) );
	}

	public function test_meldung_wird_gekuerzt_und_bereinigt() {
		$m = Push::message( '<b>Neu</b>', str_repeat( 'x', 500 ), '#/seite/lager', 'la ger!' );
		$this->assertSame( 'Neu', $m['title'] );
		$this->assertSame( 200, strlen( $m['body'] ) );
		$this->assertSame( 'lager', $m['tag'] );
	}

	public function test_meldungsarten_sind_vollstaendig() {
		$cats  = Push::categories();
		$areas = \Lindenweg16\Erp\Navigation::areas();
		$this->assertCount( 18, $cats );
		$an = array();
		foreach ( $cats as $key => $def ) {
			$this->assertMatchesRegularExpression( '/^[a-z_]+$/', $key );
			$this->assertTrue( \Lindenweg16\Erp\Permissions::exists( $def[1] ), $key . ': unbekanntes Recht ' . $def[1] );
			$this->assertArrayHasKey( $def[2], $areas, $key );
			if ( $def[3] ) {
				$an[] = $key;
			}
		}
		// Nur die beiden alten Arten sind von selbst an; alles Neue wählt jede Person selbst.
		$this->assertSame( array( 'bestellung', 'lager' ), $an );
	}

	public function test_morgens_nur_neues_melden() {
		$this->assertSame( array( 'b' => 'B' ), Push::newly( array( 'a' => 'A', 'b' => 'B' ), array( 'a' ) ) );
		$this->assertSame( array( '7' => 'X' ), Push::newly( array( '7' => 'X' ), array() ) );
		$this->assertSame( array(), Push::newly( array( '7' => 'X' ), array( 7 ) ) );
	}

	public function test_quelltext_waechter() {
		$push = file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Push.php' );
		$page = file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Pages/Benachrichtigung.php' );
		$sw   = file_get_contents( dirname( __DIR__ ) . '/assets/erp/sw.js' );
		// Weckrufe nur über die sichere Variante (blockt interne Adressen).
		$this->assertStringContainsString( 'wp_safe_remote_post', $push );
		$this->assertStringNotContainsString( 'wp_remote_post(', $push );
		// Nur der Abruf des Service Workers ist offen, alles andere braucht eine Anmeldung.
		$this->assertSame( 1, substr_count( $page, "'__return_true'" ) );
		$this->assertStringContainsString( '__PUSH_URL__', $sw );
		$this->assertStringContainsString( 'showNotification', $sw );
		// Ein Fehler beim Benachrichtigen darf Kasse, Bridge oder Postfach nicht abbrechen.
		$this->assertStringContainsString( 'catch ( \\Throwable', $push );
		// Die neuen Auslöser feuern an ihrer Quelle.
		$this->assertStringContainsString( "do_action( 'lw16_waitlist_signup'", file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Retention/Waitlist.php' ) );
		$this->assertStringContainsString( "do_action( 'lw16_support_ticket_created'", file_get_contents( dirname( __DIR__ ) . '/includes/Modules/Support/SupportModule.php' ) );
	}
}
