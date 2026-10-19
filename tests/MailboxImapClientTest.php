<?php
/**
 * Prüft den IMAP-Client gegen vorbereitete Server-Antworten.
 *
 * Der Server ist ein Socketpaar: Die Antworten stehen im Voraus bereit, der
 * Client liest sie der Reihe nach. Die Befehlsmarken sind fortlaufend (A1, A2
 * …), die Reihenfolge der Antworten ist deshalb festgelegt. Gegen einen echten
 * Server (Dovecot) läuft zusätzlich tests/integration/mailbox-dovecot.php.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Mailbox\ImapClient;
use PHPUnit\Framework\TestCase;

/**
 * IMAP-Client.
 */
class MailboxImapClientTest extends TestCase {

	/**
	 * Offene Gegenstellen.
	 *
	 * @var array
	 */
	protected $servers = array();

	/**
	 * Client und Gegenstelle.
	 *
	 * @param string $script Antworten des Servers (CRLF-getrennt).
	 * @return array {ImapClient, resource Gegenstelle}
	 */
	protected function client( $script ) {
		$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
		fwrite( $pair[1], $script );

		// Die Gegenstelle muss offen bleiben, sonst scheitert jedes Schreiben des Clients.
		$this->servers[] = $pair[1];

		return array( ImapClient::from_stream( $pair[0] ), $pair[1] );
	}

	/**
	 * Was der Client gesendet hat.
	 *
	 * @param resource $server Gegenstelle.
	 * @return string
	 */
	protected function sent( $server ) {
		stream_set_blocking( $server, false );
		return (string) stream_get_contents( $server );
	}

	/**
	 * Anmelden, Fähigkeiten lesen.
	 */
	public function test_login_und_capability() {
		list( $c, $s ) = $this->client(
			"* OK IMAP ready\r\n"
			. "A1 OK Logged in\r\n"
			. "* CAPABILITY IMAP4rev1 UIDPLUS MOVE IDLE\r\nA2 OK done\r\n"
		);

		$c->connect();
		$c->login( 'sven@example.de', 'geheim' );

		$this->assertTrue( $c->has( 'move' ) );
		$this->assertTrue( $c->has( 'UIDPLUS' ) );
		$this->assertFalse( $c->has( 'CONDSTORE' ) );
		$this->assertStringContainsString( "A1 LOGIN \"sven@example.de\" \"geheim\"\r\n", $this->sent( $s ) );
	}

	/**
	 * Sonderzeichen im Passwort: Anführungszeichen und Backslash werden maskiert,
	 * Umlaute gehen als Literal.
	 */
	public function test_passwort_mit_sonderzeichen() {
		list( $c, $s ) = $this->client(
			"* OK ready\r\nA1 OK\r\n* CAPABILITY IMAP4rev1\r\nA2 OK\r\n"
			. "* OK ready\r\n"
		);

		$c->connect();
		$c->login( 'u', 'a"b\\c' );
		$this->assertStringContainsString( 'A1 LOGIN "u" "a\\"b\\\\c"' . "\r\n", $this->sent( $s ) );

		list( $c2, $s2 ) = $this->client( "* OK ready\r\n+ go\r\nA1 OK\r\n* CAPABILITY IMAP4rev1\r\nA2 OK\r\n" );
		$c2->connect();
		$c2->login( 'u', 'pässwort' );
		$sent = $this->sent( $s2 );
		$this->assertStringContainsString( 'A1 LOGIN "u" {9}' . "\r\n", $sent );
		$this->assertStringContainsString( "pässwort\r\n", $sent );
	}

	/**
	 * Falsche Zugangsdaten werfen eine lesbare Ausnahme.
	 */
	public function test_login_abgelehnt() {
		list( $c ) = $this->client( "* OK ready\r\nA1 NO [AUTHENTICATIONFAILED] Authentication failed.\r\n" );
		$c->connect();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Authentication failed' );
		$c->login( 'u', 'falsch' );
	}

	/**
	 * Ordnerliste mit Sonderordnern, Umlauten und nicht wählbaren Ordnern.
	 */
	public function test_ordner_listen() {
		list( $c ) = $this->client(
			"* OK ready\r\n"
			. "* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n"
			. "* LIST (\\HasNoChildren \\Sent) \"/\" \"Sent\"\r\n"
			. "* LIST (\\HasNoChildren \\Trash) \"/\" Trash\r\n"
			. "* LIST (\\HasChildren \\Noselect) \"/\" \"Sammlung\"\r\n"
			. "* LIST (\\HasNoChildren) \"/\" \"Entw&APw-rfe\"\r\n"
			. "* LIST (\\HasNoChildren) \"/\" {5}\r\nAb c \r\n"
			. "A1 OK List completed\r\n"
		);
		$c->connect();

		$folders = $c->list_folders();
		$names   = array_column( $folders, 'name' );

		$this->assertSame( array( 'INBOX', 'Sent', 'Trash', 'Entwürfe', 'Ab c ' ), $names );
		$this->assertContains( '\\sent', $folders[1]['flags'] );
		$this->assertSame( '/', $folders[0]['delimiter'] );
	}

	/**
	 * SELECT liefert UIDVALIDITY und UIDNEXT.
	 */
	public function test_select() {
		list( $c, $s ) = $this->client(
			"* OK ready\r\n"
			. "* 17 EXISTS\r\n* 0 RECENT\r\n* OK [UIDVALIDITY 1700000001] UIDs valid\r\n* OK [UIDNEXT 42] Predicted next UID\r\n"
			. "A1 OK [READ-WRITE] Select completed\r\n"
		);
		$c->connect();

		$info = $c->select( 'Entwürfe' );

		$this->assertSame( 17, $info['exists'] );
		$this->assertSame( 1700000001, $info['uidvalidity'] );
		$this->assertSame( 42, $info['uidnext'] );
		$this->assertStringContainsString( 'A1 SELECT "Entw&APw-rfe"', $this->sent( $s ) );
	}

	/**
	 * Suche und Meta-Abfrage.
	 */
	public function test_suche_und_meta() {
		list( $c ) = $this->client(
			"* OK ready\r\n"
			. "* SEARCH 9 3 4\r\nA1 OK done\r\n"
			. "* 1 FETCH (UID 3 FLAGS (\\Seen \\Answered) RFC822.SIZE 1234)\r\n"
			. "* 2 FETCH (RFC822.SIZE 99 UID 4 FLAGS ())\r\n"
			. "A2 OK done\r\n"
		);
		$c->connect();

		$this->assertSame( array( 3, 4, 9 ), $c->uid_search( 'ALL' ) );

		$meta = $c->uid_fetch_meta( '3:*' );
		$this->assertSame( array( '\\Seen', '\\Answered' ), $meta[3]['flags'] );
		$this->assertSame( 1234, $meta[3]['size'] );
		$this->assertSame( array(), $meta[4]['flags'] );
		$this->assertSame( 99, $meta[4]['size'] );
	}

	/**
	 * Nachricht holen: der Inhalt kommt als Literal, auch wenn er selbst
	 * wie ein Protokollteil aussieht.
	 */
	public function test_nachricht_holen() {
		$body = "Subject: Test\r\n\r\nA1 OK trick\r\n* 1 FETCH (UID 999)\r\nEnde\r\n";
		list( $c, $s ) = $this->client(
			"* OK ready\r\n"
			. "* 1 FETCH (UID 7 FLAGS (\\Seen) BODY[] {" . strlen( $body ) . "}\r\n" . $body . ")\r\n"
			. "A1 OK Fetch completed\r\n"
		);
		$c->connect();

		$msg = $c->uid_fetch_message( 7 );

		$this->assertSame( 7, $msg['uid'] );
		$this->assertSame( array( '\\Seen' ), $msg['flags'] );
		$this->assertSame( $body, $msg['raw'] );
		$this->assertStringContainsString( 'UID FETCH 7 (UID FLAGS BODY.PEEK[])', $this->sent( $s ) );
	}

	/**
	 * Gibt es die Nachricht nicht mehr, kommt null.
	 */
	public function test_nachricht_verschwunden() {
		list( $c ) = $this->client( "* OK ready\r\nA1 OK Fetch completed\r\n" );
		$c->connect();

		$this->assertNull( $c->uid_fetch_message( 5 ) );
	}

	/**
	 * APPEND sendet die Nachricht als Literal und liefert die UID.
	 */
	public function test_append() {
		list( $c, $s ) = $this->client(
			"* OK ready\r\n+ Ready for literal data\r\nA1 OK [APPENDUID 1700000001 55] Append completed\r\n"
		);
		$c->connect();

		$uid  = $c->append( 'Sent', "Subject: Hallo\n\nText", array( '\\Seen' ) );
		$sent = $this->sent( $s );

		$this->assertSame( 55, $uid );
		$this->assertStringContainsString( 'A1 APPEND "Sent" (\\Seen) {', $sent );
		// Zeilenenden werden auf CRLF gebracht: 21 Zeichen + 2 zusätzliche CR.
		$this->assertStringContainsString( "{22}\r\nSubject: Hallo\r\n\r\nText\r\n", $sent );
	}

	/**
	 * Verschieben ohne MOVE-Erweiterung: kopieren, markieren, entfernen.
	 */
	public function test_verschieben_ohne_move() {
		list( $c, $s ) = $this->client(
			"* OK ready\r\nA1 OK\r\n* CAPABILITY IMAP4rev1\r\nA2 OK\r\n"
			. "A3 OK copied\r\nA4 OK stored\r\nA5 OK expunged\r\n"
		);
		$c->connect();
		$c->login( 'u', 'p' );
		$c->uid_move( 12, 'Trash' );

		$sent = $this->sent( $s );
		$this->assertStringContainsString( 'A3 UID COPY 12 "Trash"', $sent );
		$this->assertStringContainsString( 'A4 UID STORE 12 +FLAGS.SILENT (\\Deleted)', $sent );
		$this->assertStringContainsString( 'A5 EXPUNGE', $sent );
	}

	/**
	 * Verschieben mit MOVE und UIDPLUS.
	 */
	public function test_verschieben_mit_move() {
		list( $c, $s ) = $this->client(
			"* OK ready\r\nA1 OK\r\n* CAPABILITY IMAP4rev1 MOVE UIDPLUS\r\nA2 OK\r\nA3 OK moved\r\n"
		);
		$c->connect();
		$c->login( 'u', 'p' );
		$c->uid_move( 12, 'Trash' );

		$this->assertStringContainsString( 'A3 UID MOVE 12 "Trash"', $this->sent( $s ) );
	}

	/**
	 * Eine abgebrochene Verbindung wird gemeldet, nicht endlos gewartet.
	 */
	public function test_verbindungsabbruch() {
		list( $c, $s ) = $this->client( "* OK ready\r\n" );
		$c->connect();
		fclose( $s );

		$this->expectException( \RuntimeException::class );
		$c->uid_search( 'ALL' );
	}

	/**
	 * Ordnernamen: Hin und zurück, auch mit & und Sonderzeichen.
	 */
	public function test_ordnernamen_utf7() {
		foreach ( array( 'INBOX', 'Entwürfe', 'Rechnung & Co', 'Gelöscht/Größe', '日本語', 'a&b€c' ) as $name ) {
			$this->assertSame( $name, ImapClient::utf7_decode( ImapClient::utf7_encode( $name ) ), $name );
		}

		$this->assertSame( 'Entw&APw-rfe', ImapClient::utf7_encode( 'Entwürfe' ) );
		$this->assertSame( 'a&-b', ImapClient::utf7_encode( 'a&b' ) );
		$this->assertSame( 'Gesendet', ImapClient::utf7_decode( 'Gesendet' ) );
	}

	/**
	 * Datum im IMAP-Format.
	 */
	public function test_datum() {
		$this->assertSame( '19-Oct-2026', ImapClient::date( gmmktime( 12, 0, 0, 10, 19, 2026 ) ) );
	}
}
