<?php
/**
 * Offline-Erfassung: Gegenstücke in Server und Service Worker.
 *
 * Die Warteschlange schickt Buchungen mit Kennung nach; ohne Prüfung auf
 * dem Server würde eine Buchung doppelt gezählt, deren Antwort verloren ging.
 *
 * @package Lindenweg16
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests für die Offline-Teile.
 */
class ErpOfflineTest extends TestCase {

	protected function read( $relative ) {
		return file_get_contents( dirname( __DIR__ ) . '/' . $relative );
	}

	public function test_every_queued_post_has_a_duplicate_check_on_the_server() {
		$js   = $this->read( 'assets/erp/erp.js' );
		$rest = $this->read( 'includes/Modules/Betrieb/RestApi.php' );

		$this->assertSame( 1, preg_match_all( "/queue: true/", $js ) >= 2 ? 1 : 0, 'Mindestens Buchen und Picken werden vorgemerkt' );

		// Jede vorgemerkte Schnittstelle muss client_ref kennen.
		foreach ( array( 'lager/bewegung' => 'create_movement', 'picklisten/position' => 'pick_item' ) as $path => $method ) {
			$this->assertStringContainsString( "'$path'", $js );
			$this->assertMatchesRegularExpression( '/function ' . $method . '\(.*?already_done.*?remember_done/s', $rest, "$method prüft client_ref nicht" );
		}
	}

	public function test_duplicate_check_comes_after_the_permission_check() {
		$rest = $this->read( 'includes/Modules/Betrieb/RestApi.php' );

		$this->assertLessThan( strpos( $rest, 'already_done( $request ) ) {', strpos( $rest, 'function create_movement' ) ), strpos( $rest, "Access::can( 'lager.buchen' )" ), 'Erst das Recht prüfen, dann auf Duplikate' );
	}

	public function test_service_worker_has_version_placeholder_and_never_touches_the_api() {
		$sw = $this->read( 'assets/erp/sw.js' );

		$this->assertStringContainsString( '__VERSION__', $sw );
		$this->assertStringContainsString( "req.method !== 'GET'", $sw, 'nur GET anfassen' );
		$this->assertStringNotContainsString( 'wp-json', $sw, 'Schnittstelle gehört nie in den Zwischenspeicher' );
		$this->assertStringNotContainsString( 'wp-admin', $sw );
	}

	public function test_shell_serves_the_worker_with_scope_header() {
		$shell = $this->read( 'includes/Erp/Shell.php' );

		$this->assertStringContainsString( 'Service-Worker-Allowed', $shell );
		$this->assertStringContainsString( "str_replace( '__VERSION__'", $shell );
	}
}
