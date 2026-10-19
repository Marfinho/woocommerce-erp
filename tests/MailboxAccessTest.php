<?php
/**
 * Prüft die Zugriffsstufen der Mailbox.
 *
 * Ein Fehler hier hieße: Jemand liest fremde Mails. Die Regeln stehen als
 * reine Funktion in MailboxAccess::level().
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Mailbox\MailboxAccess;
use PHPUnit\Framework\TestCase;

/**
 * Zugriffsstufen.
 */
class MailboxAccessTest extends TestCase {

	/**
	 * Ein persönliches Postfach gehört seinem Besitzer – und nur ihm.
	 */
	public function test_besitzer_und_fremde() {
		$account = array( 'owner_user_id' => 7 );

		$this->assertSame( 'send', MailboxAccess::level( $account, array(), 7, false ) );
		$this->assertSame( 'none', MailboxAccess::level( $account, array(), 8, false ) );
		$this->assertSame( 'none', MailboxAccess::level( $account, array(), 0, false ) );
	}

	/**
	 * Mitnutzer: mit oder ohne Senderecht.
	 */
	public function test_mitnutzer() {
		$account = array( 'owner_user_id' => null );
		$shares  = array(
			3 => true,
			4 => false,
		);

		$this->assertSame( 'send', MailboxAccess::level( $account, $shares, 3, false ) );
		$this->assertSame( 'read', MailboxAccess::level( $account, $shares, 4, false ) );
		$this->assertSame( 'none', MailboxAccess::level( $account, $shares, 5, false ) );
	}

	/**
	 * Verwalter sehen alles; ein Nutzer 0 (nicht angemeldet) nichts.
	 */
	public function test_verwalter() {
		$this->assertSame( 'admin', MailboxAccess::level( array( 'owner_user_id' => 7 ), array(), 1, true ) );
		$this->assertSame( 'none', MailboxAccess::level( array( 'owner_user_id' => 7 ), array(), 0, false ) );
	}

	/**
	 * Ein fehlender Besitzer (geteiltes Postfach) gibt dem Nutzer 0 keinen Zugriff.
	 */
	public function test_kein_besitzer_ist_nicht_nutzer_null() {
		$this->assertSame( 'none', MailboxAccess::level( array( 'owner_user_id' => null ), array(), 0, false ) );
		$this->assertSame( 'none', MailboxAccess::level( array(), array(), 0, false ) );
	}

	/**
	 * Die Stufen bauen aufeinander auf; "none" erfüllt nie etwas.
	 */
	public function test_stufen() {
		$this->assertTrue( MailboxAccess::allows( 'send', 'read' ) );
		$this->assertTrue( MailboxAccess::allows( 'admin', 'send' ) );
		$this->assertTrue( MailboxAccess::allows( 'read', 'read' ) );
		$this->assertFalse( MailboxAccess::allows( 'read', 'send' ) );
		$this->assertFalse( MailboxAccess::allows( 'none', 'read' ) );
		$this->assertFalse( MailboxAccess::allows( 'none', 'none' ), 'Auch "keine Stufe nötig" gibt keinen Zugriff.' );
		$this->assertFalse( MailboxAccess::allows( 'bogus', 'read' ) );
		$this->assertFalse( MailboxAccess::allows( 'send', 'bogus' ) );
	}
}
