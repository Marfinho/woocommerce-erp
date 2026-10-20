<?php
/**
 * Regeln der nativen Postfach-Verwaltung: Eingaben, Freigaben, Zustände, Geheimhaltung.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Erp\Pages\Konten;
use PHPUnit\Framework\TestCase;

/**
 * Tests für Konten.
 */
class ErpKontenTest extends TestCase {

	protected function valid() {
		return array(
			'label'     => 'Info',
			'imap_host' => 'imap.ionos.de',
			'smtp_host' => 'smtp.ionos.de',
			'imap_port' => 993,
			'smtp_port' => 587,
		);
	}

	public function test_a_complete_account_has_no_problems() {
		$this->assertSame( array(), Konten::problems( $this->valid(), true, true ) );
	}

	public function test_each_missing_part_is_named() {
		$d          = $this->valid();
		$d['label'] = '';
		$d['imap_host'] = '';
		$d['smtp_port'] = 70000;

		$errors = Konten::problems( $d, false, false, false );
		$text   = implode( ' | ', $errors );

		$this->assertStringContainsString( 'bezeichnung', strtolower( $text ) );
		$this->assertStringContainsString( 'E-Mail-Adresse', $text );
		$this->assertStringContainsString( 'IMAP- und SMTP-Server', $text );
		$this->assertStringContainsString( 'Port', $text );
		$this->assertStringContainsString( 'Passwort', $text );
		$this->assertStringContainsString( 'libsodium', $text );
		$this->assertCount( 6, $errors );
	}

	public function test_ports_must_be_between_1_and_65535() {
		$this->assertTrue( Konten::valid_port( 993 ) );
		$this->assertTrue( Konten::valid_port( '587' ) );
		$this->assertTrue( Konten::valid_port( 65535 ) );
		$this->assertFalse( Konten::valid_port( 0 ) );
		$this->assertFalse( Konten::valid_port( 65536 ) );
		$this->assertFalse( Konten::valid_port( '' ) );
		$this->assertFalse( Konten::valid_port( 'abc' ) );
	}

	public function test_host_names_are_cleaned() {
		$this->assertSame( 'imap.ionos.de', Konten::clean_host( '  imap.ionos.de ' ) );
		$this->assertSame( 'mailhost.de', Konten::clean_host( 'mail host;.de' ) );
		$this->assertSame( 'imapa-b.de', Konten::clean_host( 'imap://a-b.de/' ) );
		$this->assertSame( '', Konten::clean_host( null ) );
	}

	public function test_secure_mode_falls_back_to_the_default() {
		$this->assertSame( 'tls', Konten::clean_secure( 'tls', 'ssl' ) );
		$this->assertSame( 'ssl', Konten::clean_secure( 'ssl', 'tls' ) );
		$this->assertSame( 'ssl', Konten::clean_secure( 'none', 'ssl' ) );
		$this->assertSame( 'tls', Konten::clean_secure( '', 'tls' ) );
	}

	public function test_password_state_never_depends_on_the_password_itself() {
		$this->assertSame( 'missing', Konten::password_state( '', true ) );
		$this->assertSame( 'saved', Konten::password_state( 'v1:xyz', true ) );
		$this->assertSame( 'unreadable', Konten::password_state( 'v1:xyz', false ) );
	}

	public function test_status_follows_the_old_page() {
		$this->assertSame( 'pw', Konten::status_code( true, 'unreadable', '', '1' ) );
		$this->assertSame( 'pw', Konten::status_code( false, 'missing', '', '' ) );
		$this->assertSame( 'off', Konten::status_code( false, 'saved', 'Fehler', '1' ) );
		$this->assertSame( 'error', Konten::status_code( true, 'saved', 'Anmeldung fehlgeschlagen', '1' ) );
		$this->assertSame( 'ok', Konten::status_code( true, 'saved', '', '1' ) );
		$this->assertSame( 'new', Konten::status_code( true, 'saved', '', '' ) );
	}

	public function test_shares_skip_owner_duplicates_unknown_and_invalid_users() {
		$exists = static function ( $uid ) {
			return in_array( $uid, array( 2, 3, 4, 9 ), true );
		};

		$shares = Konten::clean_shares(
			array(
				array( 'user_id' => 2, 'can_send' => true ),
				array( 'user_id' => '3', 'can_send' => false ),
				array( 'user_id' => 4 ),
				array( 'user_id' => 9, 'can_send' => true ),
				array( 'user_id' => 2, 'can_send' => false ),
				array( 'user_id' => 77, 'can_send' => true ),
				array( 'user_id' => 0, 'can_send' => true ),
				'kaputt',
			),
			9,
			$exists
		);

		$this->assertSame( array( 2 => true, 3 => false, 4 => false ), $shares );
		$this->assertSame( array(), Konten::clean_shares( 'x', 0, $exists ) );
	}

	public function test_settings_are_clamped() {
		$this->assertSame(
			array( 'mail_sync_aktiv' => 1, 'mail_lookback_tage' => 3650, 'mail_max_anhang_mb' => 1 ),
			Konten::clamp_settings( array( 'sync' => true, 'lookback_days' => 99999, 'max_attachment_mb' => -5 ) )
		);
		$this->assertSame(
			array( 'mail_sync_aktiv' => 0, 'mail_lookback_tage' => 90, 'mail_max_anhang_mb' => 20 ),
			Konten::clamp_settings( array() )
		);
		$this->assertSame( 100, Konten::clamp_settings( array( 'max_attachment_mb' => 500 ) )['mail_max_anhang_mb'] );
	}

	public function test_the_password_is_removed_from_server_messages() {
		$this->assertSame( 'Login mit *** abgelehnt', Konten::scrub( 'Login mit geheim123 abgelehnt', 'geheim123' ) );
		$this->assertSame( 'Fehler', Konten::scrub( 'Fehler', '' ) );
	}
}
