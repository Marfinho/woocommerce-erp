<?php
/**
 * Sperre des wp-admin für alle außer dem Webmaster.
 *
 * Wer ausgesperrt wird, merkt es erst, wenn er arbeiten will. Deshalb steht
 * hier fest: Der Webmaster ist nie betroffen, die Einstellung ist aus, bis man
 * sie einschaltet, und nur der Webmaster ändert sie.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Shell;
use Lindenweg16\Erp\Workflow;
use PHPUnit\Framework\TestCase;

/**
 * Tests für die wp-admin-Sperre.
 */
class ErpWpadminLockTest extends TestCase {

	public function test_aus_ist_nichts_gesperrt() {
		$this->assertFalse( Shell::wpadmin_blocked( false, false, 'edit.php', false, false ) );
	}

	public function test_der_webmaster_ist_nie_betroffen() {
		foreach ( array( 'index.php', 'options-general.php', 'plugins.php', 'admin.php' ) as $page ) {
			$this->assertFalse( Shell::wpadmin_blocked( true, true, $page, false, false ), $page );
		}
	}

	public function test_alle_anderen_sind_ausgesperrt() {
		foreach ( array( 'index.php', 'edit.php', 'plugins.php', 'options-general.php', 'users.php', 'admin.php' ) as $page ) {
			$this->assertTrue( Shell::wpadmin_blocked( true, false, $page, false, false ), $page );
		}
	}

	public function test_formulare_ajax_upload_und_profil_bleiben_offen() {
		foreach ( array( 'admin-post.php', 'admin-ajax.php', 'async-upload.php', 'profile.php' ) as $page ) {
			$this->assertFalse( Shell::wpadmin_blocked( true, false, $page, false, false ), $page );
		}
	}

	public function test_eingebettete_shop_seiten_nur_mit_recht_und_im_rahmen() {
		$this->assertFalse( Shell::wpadmin_blocked( true, false, 'edit.php', true, true ), 'Im Rahmen mit Recht ist es offen.' );
		$this->assertTrue( Shell::wpadmin_blocked( true, false, 'edit.php', true, false ), 'Ohne Recht bleibt es zu.' );
		$this->assertTrue( Shell::wpadmin_blocked( true, false, 'edit.php', false, true ), 'Außerhalb des Rahmens bleibt es zu.' );
	}

	public function test_die_einstellung_ist_standardmaessig_aus() {
		$this->assertSame( 0, Workflow::defaults()['lock_wpadmin'] );
		$this->assertSame( 1, Workflow::sanitize_settings( array( 'lock_wpadmin' => '1' ) )['lock_wpadmin'] );
		$this->assertSame( 0, Workflow::sanitize_settings( array() )['lock_wpadmin'] );
	}

	public function test_nur_der_webmaster_aendert_die_sperre() {
		$php = file_get_contents( dirname( __DIR__ ) . '/includes/Erp/RestApi.php' );

		$this->assertMatchesRegularExpression( "/manage_options.*?unset\\( \\\$in\\['lock_wpadmin'\\] \\)/s", $php );
	}

	public function test_sperre_laeuft_frueh_und_ohne_anmeldung_gar_nicht() {
		$php = file_get_contents( dirname( __DIR__ ) . '/includes/Erp/Shell.php' );

		$this->assertStringContainsString( "add_action( 'admin_init', array( \$this, 'lock_wpadmin' ), 1 )", $php );
		$this->assertStringContainsString( '! is_user_logged_in()', $php );
	}
}
