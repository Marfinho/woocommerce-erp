<?php
/**
 * Prüft die reinen Hilfen beim Schreiben und Beantworten.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Mailbox\ComposeService;
use Lindenweg16\Modules\Mailbox\SyncService;
use PHPUnit\Framework\TestCase;

/**
 * Mail schreiben.
 */
class MailboxComposeTest extends TestCase {

	/**
	 * Präfixe werden nicht verdoppelt, auch nicht über das deutsche "AW:".
	 */
	public function test_betreff_praefix() {
		$this->assertSame( 'Re: Frage', ComposeService::prefix_subject( 'Frage', 'Re' ) );
		$this->assertSame( 'Re: Frage', ComposeService::prefix_subject( 'Re: Frage', 'Re' ) );
		$this->assertSame( 'AW: Frage', ComposeService::prefix_subject( 'AW: Frage', 'Re' ) );
		$this->assertSame( 'Fwd: Frage', ComposeService::prefix_subject( 'Frage', 'Fwd' ) );
		$this->assertSame( 'WG: Frage', ComposeService::prefix_subject( 'WG: Frage', 'Fwd' ) );
	}

	/**
	 * Die Überschrift der Mail trägt kein "Re:" – es ist ja die Überschrift,
	 * nicht der Betreff.
	 */
	public function test_ueberschrift() {
		$this->assertSame( 'Frage zur Bestellung', ComposeService::heading( 'Re: AW: Frage zur Bestellung' ) );
		$this->assertSame( 'Hallo', ComposeService::heading( 'WG: Hallo' ) );
		$this->assertSame( 'Re:', ComposeService::heading( 'Re:' ) );
	}

	/**
	 * Empfängerfeld: Komma, Semikolon, Namen, doppelte und ungültige Adressen.
	 */
	public function test_empfaenger() {
		$r = ComposeService::parse_recipients( '"Müller, Käthe" <kaethe@example.de>; hans@example.de, KAETHE@example.de, kaputt@, Katrin' );

		$this->assertCount( 2, $r['valid'] );
		$this->assertSame( 'Müller, Käthe', $r['valid'][0]['name'] );
		$this->assertSame( 'hans@example.de', $r['valid'][1]['email'] );
		$this->assertContains( 'kaputt@', $r['invalid'] );
		$this->assertContains( 'Katrin', $r['invalid'] );

		$leer = ComposeService::parse_recipients( '  ' );
		$this->assertSame( array(), $leer['valid'] );
		$this->assertSame( array(), $leer['invalid'] );
	}

	/**
	 * Antwort geht an den Absender, Allen-antworten zusätzlich an die anderen
	 * Empfänger – ohne die eigene Adresse.
	 */
	public function test_antwort_empfaenger() {
		$msg = array(
			'from_name'  => 'Kundin',
			'from_email' => 'kundin@example.de',
			'to_json'    => json_encode( array( array( 'name' => 'Sven', 'email' => 'sven@example.de' ), array( 'name' => '', 'email' => 'info@example.de' ) ) ),
			'cc_json'    => json_encode( array( array( 'name' => 'Chef', 'email' => 'chef@example.de' ) ) ),
		);

		$one = ComposeService::reply_recipients( $msg, false, 'sven@example.de' );
		$this->assertSame( '"Kundin" <kundin@example.de>', $one['to'] );
		$this->assertSame( '', $one['cc'] );

		$all = ComposeService::reply_recipients( $msg, true, 'sven@example.de' );
		$this->assertSame( '"Kundin" <kundin@example.de>', $all['to'] );
		$this->assertStringContainsString( 'info@example.de', $all['cc'] );
		$this->assertStringContainsString( 'chef@example.de', $all['cc'] );
		$this->assertStringNotContainsString( 'sven@example.de', $all['cc'] );

		// Reply-To hat Vorrang.
		$rt = ComposeService::reply_recipients( $msg, false, 'sven@example.de', 'antwort@example.de' );
		$this->assertSame( '"Kundin" <antwort@example.de>', $rt['to'] );

		// Antwort auf die eigene gesendete Mail: an die damaligen Empfänger.
		$own = array_merge( $msg, array( 'from_email' => 'sven@example.de', 'from_name' => 'Sven' ) );
		$self = ComposeService::reply_recipients( $own, false, 'sven@example.de' );
		$this->assertStringContainsString( 'info@example.de', $self['to'] );
		$this->assertStringNotContainsString( 'sven@example.de', $self['to'] );
	}

	/**
	 * Zitat: jede Zeile mit "> ", leere Zeilen als ">", Zitate im Zitat wachsen.
	 */
	public function test_zitat() {
		$q = ComposeService::quote(
			array(
				'from_name'  => 'Kundin',
				'from_email' => 'kundin@example.de',
				'body_text'  => "Hallo\n\n> alt\nEnde",
			),
			'19.10.2026 um 10:15'
		);

		$this->assertSame(
			"Am 19.10.2026 um 10:15 schrieb Kundin <kundin@example.de>:\n> Hallo\n>\n>> alt\n> Ende",
			$q
		);
	}

	/**
	 * Eigener Text und Zitat werden getrennt – die Signatur gehört dazwischen.
	 */
	public function test_zitat_trennen() {
		$body = "Danke, kommt morgen.\n\nAm 19.10.2026 um 10:15 schrieb Kundin <kundin@example.de>:\n> Wann kommt es?";
		$p    = ComposeService::split_quote( $body );

		$this->assertSame( 'Danke, kommt morgen.', $p['main'] );
		$this->assertStringStartsWith( 'Am 19.10.2026', $p['quote'] );
		$this->assertStringContainsString( '> Wann kommt es?', $p['quote'] );

		$fw = ComposeService::split_quote( "Zur Info\n\n---------- Weitergeleitete Nachricht ----------\nVon: x" );
		$this->assertSame( 'Zur Info', $fw['main'] );
		$this->assertStringStartsWith( '----------', $fw['quote'] );

		$none = ComposeService::split_quote( "Nur Text\nZeile 2" );
		$this->assertSame( "Nur Text\nZeile 2", $none['main'] );
		$this->assertSame( '', $none['quote'] );
	}

	/**
	 * Signatur: Platzhalter {name} wird durch den Absender ersetzt.
	 */
	public function test_signatur() {
		$this->assertSame( "Katrin Muster\nLindenweg16", ComposeService::render_signature( "{name}\nLindenweg16\n", 'Katrin Muster' ) );
		$this->assertSame( '', ComposeService::render_signature( '', 'X' ) );
	}

	/**
	 * Ordnerrollen erkennt der Client an den Flags des Servers, sonst am Namen
	 * (IONOS und andere deutsche Anbieter nutzen unterschiedliche Namen).
	 */
	public function test_ordnerrollen() {
		$this->assertSame( 'inbox', SyncService::role_for( 'INBOX', array() ) );
		$this->assertSame( 'sent', SyncService::role_for( 'Gesendet', array( '\\sent' ) ) );
		$this->assertSame( 'sent', SyncService::role_for( 'INBOX.Sent', array() ) );
		$this->assertSame( 'sent', SyncService::role_for( 'Gesendete Elemente', array() ) );
		$this->assertSame( 'drafts', SyncService::role_for( 'Entwürfe', array() ) );
		$this->assertSame( 'trash', SyncService::role_for( 'Papierkorb', array() ) );
		$this->assertSame( 'trash', SyncService::role_for( 'Deleted Items', array() ) );
		$this->assertSame( 'spam', SyncService::role_for( 'Spam', array() ) );
		$this->assertSame( 'spam', SyncService::role_for( 'Foo', array( '\\junk' ) ) );
		$this->assertSame( 'other', SyncService::role_for( 'Kunden/Anfragen', array() ) );
		// Ein Ordner, der nur zufällig "Sent" im Namen trägt, ist keiner.
		$this->assertSame( 'other', SyncService::role_for( 'Sentinel', array() ) );
	}

	/**
	 * Verlaufsschlüssel: Antworten landen beim Gesprächsanfang.
	 */
	public function test_verlaufsschluessel() {
		$this->assertSame( 'a', SyncService::thread_key( 'c', 'b', array( 'a', 'b' ), null ) );
		$this->assertSame( 'b', SyncService::thread_key( 'c', 'b', array(), null ) );
		$this->assertSame( 'c', SyncService::thread_key( 'c', '', array(), null ) );
		$this->assertSame( 'root', SyncService::thread_key( 'c', 'b', array( 'a' ), 'root' ) );
	}

	/**
	 * Formulareingaben behalten spitze Klammern: Eine Adresse "Name <a@b>" und
	 * ein Zitat mit Absenderadresse dürfen nicht wie HTML-Tags verschwinden.
	 */
	public function test_eingabe_bereinigen() {
		$this->assertSame( '"Käthe Müller" <kaethe@example.de>', ComposeService::clean_text( '"Käthe Müller" <kaethe@example.de>' ) );
		$this->assertSame( 'Re: <Angebot> & mehr', ComposeService::clean_text( "Re: <Angebot> & mehr\r\n" ) );
		$this->assertSame( "Zeile 1\nZeile 2\n> Am 1.1. schrieb X <x@y.de>:", ComposeService::clean_text( "Zeile 1\r\nZeile 2\n> Am 1.1. schrieb X <x@y.de>:", true ) );

		// Einzeilig: kein Zeilenumbruch, der einen Header einschleusen könnte.
		$this->assertSame( 'Betreff Bcc: evil@example.de', ComposeService::clean_text( "Betreff\r\nBcc: evil@example.de" ) );

		// Steuerzeichen und NUL fallen weg, Umlaute bleiben.
		$this->assertSame( 'abÄ', ComposeService::clean_text( "a\x00b\x07Ä" ) );

		// Ungültiges UTF-8 wird gelesen statt die Eingabe zu zerstören.
		$this->assertSame( 'Schön', ComposeService::clean_text( "Sch\xF6n" ) );
	}
}
