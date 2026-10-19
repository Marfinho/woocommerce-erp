<?php
/**
 * Integrationstest des IMAP-Clients gegen einen echten Server (Dovecot).
 *
 * Läuft nicht in der normalen Testsuite. Aufruf:
 *   php tests/integration/mailbox-imap-dovecot.php [host] [port-klartext] [port-tls]
 * Erwartet das Postfach test@example.de / "pässwort" ohne Zertifikatsprüfung
 * (siehe tests/integration/README.md).
 */

define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__, 2 );
require $root . '/includes/Modules/Mailbox/ImapClient.php';
require $root . '/includes/Modules/Mailbox/MimeParser.php';

if ( ! function_exists( '__' ) ) {
	function __( $t ) { return $t; } // phpcs:ignore
}

use Lindenweg16\Modules\Mailbox\ImapClient;
use Lindenweg16\Modules\Mailbox\MimeParser;

$host  = $argv[1] ?? '127.0.0.1';
$plain = (int) ( $argv[2] ?? 1143 );
$tls   = (int) ( $argv[3] ?? 1993 );
$fail  = 0;

function ok( $cond, $label ) {
	global $fail;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

foreach ( array( 'ssl' => $tls, 'tls' => $plain, 'none' => $plain ) as $secure => $port ) {
	echo "== Verbindung: $secure ($host:$port)\n";
	$c = new ImapClient( $host, $port, $secure, 10, array( 'verify_peer' => false ) );
	$c->connect();
	$c->login( 'test@example.de', 'pässwort' );
	ok( $c->has( 'IMAP4rev1' ), 'Anmeldung mit Umlaut-Passwort (Literal)' );

	$folders = $c->list_folders();
	$names   = array_column( $folders, 'name' );
	ok( in_array( 'INBOX', $names, true ) && in_array( 'Sent', $names, true ), 'Ordner: ' . implode( ', ', $names ) );

	if ( 'ssl' !== $secure ) {
		$c->logout();
		continue;
	}

	// Aufräumen: Inbox leeren.
	$info = $c->select( 'INBOX' );
	foreach ( $c->uid_search( 'ALL' ) as $u ) {
		$c->uid_store( $u, array( '\\Deleted' ) );
	}
	$c->expunge();

	$mail = "From: Katrin <katrin@example.de>\r\nTo: test@example.de\r\nSubject: =?UTF-8?Q?Gr=C3=BC=C3=9Fe?=\r\n"
		. "Message-ID: <m1@example.de>\r\nDate: Mon, 19 Oct 2026 10:00:00 +0200\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n"
		. "Hallo Welt\r\n" . str_repeat( "Zeile mit Inhalt äöü\r\n", 20000 );

	$uid = $c->append( 'INBOX', $mail );
	ok( $uid > 0, "APPEND liefert UID ($uid)" );

	$info = $c->select( 'INBOX' );
	ok( $info['exists'] === 1 && $info['uidvalidity'] > 0, 'SELECT: exists=1, uidvalidity=' . $info['uidvalidity'] );

	$uids = $c->uid_search( 'ALL' );
	ok( $uids === array( $uid ), 'UID SEARCH ALL' );
	ok( $c->uid_search( 'SINCE ' . ImapClient::date( strtotime( '2026-10-01' ) ) ) === array( $uid ), 'SEARCH SINCE' );
	ok( $c->uid_search( 'UID ' . ( $uid + 1 ) . ':*' ) === array( $uid ), 'UID n:* liefert die letzte Nachricht (bekanntes Verhalten)' );

	$meta = $c->uid_fetch_meta( '1:*' );
	ok( isset( $meta[ $uid ] ) && $meta[ $uid ]['size'] > 400000, 'Meta: Größe ' . ( $meta[ $uid ]['size'] ?? '?' ) );

	$msg = $c->uid_fetch_message( $uid );
	ok( strlen( $msg['raw'] ) === strlen( $mail ), 'Nachricht 400 KB als Literal vollständig (' . strlen( $msg['raw'] ) . ')' );
	$p = MimeParser::parse( $msg['raw'] );
	ok( 'Grüße' === $p['subject'], 'MIME: Betreff' );
	ok( ! in_array( '\\Seen', $c->uid_fetch_message( $uid )['flags'], true ), 'BODY.PEEK markiert nicht als gelesen' );

	$c->uid_store( $uid, array( '\\Seen', '\\Flagged' ) );
	$f = $c->uid_fetch_meta( (string) $uid )[ $uid ]['flags'];
	ok( in_array( '\\Seen', $f, true ) && in_array( '\\Flagged', $f, true ), 'Flags setzen: ' . implode( ' ', $f ) );
	$c->uid_store( $uid, array( '\\Flagged' ), false );
	ok( ! in_array( '\\Flagged', $c->uid_fetch_meta( (string) $uid )[ $uid ]['flags'], true ), 'Flag entfernen' );

	$c->uid_move( $uid, 'Trash' );
	ok( array() === $c->uid_search( 'ALL' ), 'Verschieben: Inbox leer' );
	$c->select( 'Trash' );
	ok( 1 === count( $c->uid_search( 'ALL' ) ), 'Verschieben: Papierkorb hat die Mail' );
	foreach ( $c->uid_search( 'ALL' ) as $u ) {
		$c->uid_store( $u, array( '\\Deleted' ) );
	}
	$c->expunge();

	try {
		$c->create_folder( 'Entwürfe & Co' );
	} catch ( \RuntimeException $e ) {
		// Beim zweiten Lauf gibt es ihn schon.
		ok( false !== strpos( $e->getMessage(), 'ALREADYEXISTS' ), 'Ordner existiert schon: ' . $e->getMessage() );
	}
	$names = array_column( $c->list_folders(), 'name' );
	ok( in_array( 'Entwürfe & Co', $names, true ), 'Ordner mit Umlaut und & anlegen und lesen' );
	$c->select( 'Entwürfe & Co' );

	$c->logout();
}

echo $fail ? "\n$fail Fehler\n" : "\nAlles ok\n";
exit( $fail ? 1 : 0 );
