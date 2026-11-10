<?php
/**
 * Warteliste: Bestätigungslink (Double-Opt-in) und Schutz des offenen Formulars.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Retention\Waitlist;
use PHPUnit\Framework\TestCase;

/**
 * Tests der Warteliste.
 */
class WaitlistTest extends TestCase {

	private function read( $rel ) {
		return file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_signatur_bindet_anmeldung_und_adresse() {
		$sig = Waitlist::signatur( 7, 'Kunde@Example.com ', 'geheim' );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{24}$/', $sig );
		$this->assertSame( $sig, Waitlist::signatur( 7, 'kunde@example.com', 'geheim' ), 'Schreibweise der Adresse ist egal.' );
		$this->assertNotSame( $sig, Waitlist::signatur( 8, 'kunde@example.com', 'geheim' ), 'Andere Anmeldung.' );
		$this->assertNotSame( $sig, Waitlist::signatur( 7, 'fremd@example.com', 'geheim' ), 'Andere Adresse.' );
		$this->assertNotSame( $sig, Waitlist::signatur( 7, 'kunde@example.com', 'anders' ), 'Anderer Schlüssel.' );
	}

	public function test_link_wird_nur_in_fester_form_angenommen() {
		$sig = Waitlist::signatur( 12, 'a@b.de', 'k' );

		$this->assertSame( array( 'id' => 12, 'sig' => $sig ), Waitlist::zerlegen( '12.' . $sig ) );
		$this->assertNull( Waitlist::zerlegen( '0.' . $sig ) );
		$this->assertNull( Waitlist::zerlegen( '12.' . substr( $sig, 0, 23 ) ) );
		$this->assertNull( Waitlist::zerlegen( '12.' . strtoupper( $sig ) ) );
		$this->assertNull( Waitlist::zerlegen( "12.{$sig}' OR 1=1" ) );
		$this->assertNull( Waitlist::zerlegen( '12' ) );
		$this->assertNull( Waitlist::zerlegen( array( '12.' . $sig ) ) );
	}

	public function test_nur_die_eigene_kontoadresse_gilt_sofort() {
		$this->assertTrue( Waitlist::braucht_bestaetigung( 'gast@example.com', '' ), 'Gast' );
		$this->assertTrue( Waitlist::braucht_bestaetigung( 'fremd@example.com', 'ich@example.com' ), 'Angemeldet, aber fremde Adresse' );
		$this->assertFalse( Waitlist::braucht_bestaetigung( ' Ich@Example.com', 'ich@example.com' ) );
	}

	public function test_rueckmeldung_nur_aus_fester_liste() {
		foreach ( array( 'ok', 'mail', 'bestaetigt', 'ungueltig', 'zu_oft' ) as $status ) {
			$this->assertNotSame( '', Waitlist::meldung( $status ) );
		}
		$this->assertSame( '', Waitlist::meldung( '<script>' ) );
		$this->assertSame( '', Waitlist::meldung( null ) );
	}

	public function test_benachrichtigt_wird_nur_wer_bestaetigt_hat() {
		$src = $this->read( 'includes/Modules/Retention/Waitlist.php' );

		// Beide Wege (Betriebsartikel und alte Lagerverwaltung) lesen nur bestätigte Anmeldungen.
		$this->assertSame( 2, substr_count( $src, 'benachrichtigt = 0 AND bestaetigt = 1' ) );

		// Drossel vor jedem Schreibzugriff und vor dem Mailversand.
		preg_match( '/public function handle_signup\(.*?\n\t}/s', $src, $m );
		$this->assertNotEmpty( $m );
		$this->assertLessThan( strpos( $m[0], '$wpdb->' ), strpos( $m[0], "Throttle::hit( 'warteliste'" ) );
		$this->assertLessThan( strpos( $m[0], 'MailTemplate::send' ), strpos( $m[0], 'self::MAX_OFFEN' ) );

		// Der Link wird zeitsicher verglichen.
		$this->assertStringContainsString( 'hash_equals( self::signatur(', $src );
	}

	public function test_schema_und_mailvorlage() {
		$installer = $this->read( 'includes/Core/Installer.php' );

		preg_match( '/CREATE TABLE \{\$p\}warteliste \((.*?)\)\s*\{\$charset_collate\}/s', $installer, $m );
		$this->assertNotEmpty( $m );
		$this->assertStringContainsString( 'bestaetigt TINYINT(1) NOT NULL DEFAULT 1', $m[1], 'Bestehende Anmeldungen gelten als bestätigt.' );

		$mail = $this->read( 'includes/Core/MailTemplate.php' );
		$this->assertStringContainsString( "'warteliste_bestaetigen'", $mail );
		$this->assertStringContainsString( '{bestaetigungslink}', $mail );
	}
}
