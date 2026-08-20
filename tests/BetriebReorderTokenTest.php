<?php
/**
 * Token-Logik des Nachbestell-Links.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;
use Lindenweg16\Modules\Betrieb\ReorderToken;

/**
 * Gültig, abgelaufen, widerrufen, unbekannt – und nur der eigene Kontakt.
 */
class BetriebReorderTokenTest extends TestCase {

	/**
	 * Jetzt-Zeitstempel für die Tests.
	 */
	const NOW = 1800000000;

	/**
	 * Ein gültiges Token.
	 */
	public function test_gueltiges_token() {
		$row = array(
			'id'         => 1,
			'contact_id' => 5,
			'expires_at' => gmdate( 'Y-m-d H:i:s', self::NOW + 86400 ),
			'revoked_at' => null,
		);

		$result = ReorderToken::check( $row, self::NOW );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( '', $result['reason'] );
	}

	/**
	 * Ein abgelaufenes Token wird abgewiesen.
	 */
	public function test_abgelaufenes_token() {
		$row = array(
			'id'         => 1,
			'contact_id' => 5,
			'expires_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 1 ),
			'revoked_at' => null,
		);

		$result = ReorderToken::check( $row, self::NOW );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'abgelaufen', $result['reason'] );
	}

	/**
	 * Genau im Ablaufmoment gilt es noch.
	 */
	public function test_ablauf_ist_einschliesslich() {
		$row = array(
			'id'         => 1,
			'contact_id' => 5,
			'expires_at' => gmdate( 'Y-m-d H:i:s', self::NOW ),
			'revoked_at' => null,
		);

		$this->assertTrue( ReorderToken::check( $row, self::NOW )['valid'] );
	}

	/**
	 * Ein widerrufenes Token wird abgewiesen – auch wenn es noch läuft.
	 */
	public function test_widerrufenes_token() {
		$row = array(
			'id'         => 1,
			'contact_id' => 5,
			'expires_at' => gmdate( 'Y-m-d H:i:s', self::NOW + 86400 ),
			'revoked_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 3600 ),
		);

		$result = ReorderToken::check( $row, self::NOW );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'widerrufen', $result['reason'] );
	}

	/**
	 * Der Widerruf schlägt den Ablauf: Es bleibt beim Widerruf als Grund.
	 */
	public function test_widerruf_vor_ablauf() {
		$row = array(
			'id'         => 1,
			'expires_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 100 ),
			'revoked_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 200 ),
		);

		$this->assertSame( 'widerrufen', ReorderToken::check( $row, self::NOW )['reason'] );
	}

	/**
	 * Ein unbekanntes Token ergibt denselben neutralen Zustand – die Seite
	 * darf nicht verraten, ob es je gültig war.
	 */
	public function test_unbekanntes_token() {
		$result = ReorderToken::check( null, self::NOW );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'unbekannt', $result['reason'] );
	}

	/**
	 * Token ohne Ablaufdatum laufen nicht ab.
	 */
	public function test_token_ohne_ablauf() {
		$row = array( 'id' => 1, 'expires_at' => null, 'revoked_at' => null );

		$this->assertTrue( ReorderToken::check( $row, self::NOW )['valid'] );
	}

	/**
	 * Der Hash ist deterministisch und gibt das Token nicht preis.
	 */
	public function test_hash() {
		$token = str_repeat( 'a', 64 );

		$this->assertSame( ReorderToken::hash( $token ), ReorderToken::hash( $token ) );
		$this->assertSame( 64, strlen( ReorderToken::hash( $token ) ) );
		$this->assertNotSame( $token, ReorderToken::hash( $token ) );
		$this->assertNotSame( ReorderToken::hash( $token ), ReorderToken::hash( $token . 'b' ) );
	}

	/**
	 * Die URL enthält das Token, nicht den Hash – der Hash bleibt in der
	 * Datenbank.
	 */
	public function test_url_enthaelt_das_token() {
		$token = str_repeat( 'c', 64 );

		$GLOBALS['lw16_test_options']['permalink_structure'] = '/%postname%/';

		$this->assertStringContainsString( $token, ReorderToken::url( $token ) );
		$this->assertStringNotContainsString( ReorderToken::hash( $token ), ReorderToken::url( $token ) );
		$this->assertStringContainsString( '/nachbestellen/', ReorderToken::url( $token ) );

		unset( $GLOBALS['lw16_test_options']['permalink_structure'] );
	}

	/**
	 * Ohne sprechende Permalinks greift keine Rewrite-Regel. Die URL muss
	 * dann auf die Abfragevariablen ausweichen, sonst laeuft der Haendler
	 * auf eine 404 – der Fehler faellt erst beim Kunden auf.
	 */
	public function test_url_ohne_permalinks_nutzt_abfragevariablen() {
		$token = str_repeat( 'd', 64 );

		$GLOBALS['lw16_test_options']['permalink_structure'] = '';
		$url = ReorderToken::url( $token );

		$this->assertStringContainsString( 'lw16_reorder=1', $url );
		$this->assertStringContainsString( 'lw16_token=' . $token, $url );
		$this->assertStringNotContainsString( '/nachbestellen/', $url );
	}
}
