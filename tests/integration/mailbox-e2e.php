<?php
/**
 * Ende-zu-Ende-Test der Mailbox in einer echten WordPress-Installation.
 *
 *   wp eval-file tests/integration/mailbox-e2e.php
 *
 * Voraussetzungen (siehe README.md): Dovecot auf 127.0.0.1:1993 mit den
 * Postfächern sven@/katrin@example.de, der Test-SMTP-Server auf Port 1587 und
 * das mu-plugin mu-mailbox-test-env.php.
 */

use Lindenweg16\Modules\Mailbox\ComposeService;
use Lindenweg16\Modules\Mailbox\ImapClient;
use Lindenweg16\Modules\Mailbox\MailboxAccess;
use Lindenweg16\Modules\Mailbox\MailCrypto;
use Lindenweg16\Modules\Mailbox\MailRepository as R;
use Lindenweg16\Modules\Mailbox\SyncService;

$fail = 0;
function ok( $cond, $label ) {
	global $fail;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

global $wpdb;
foreach ( array( 'attachments', 'notes', 'messages', 'folders', 'shares', 'accounts' ) as $t ) {
	$wpdb->query( 'TRUNCATE TABLE ' . R::table( $t ) ); // phpcs:ignore
}

// Dovecot-Postfächer leeren.
foreach ( array( 'sven@example.de' => 'svenpw', 'katrin@example.de' => 'katrinpw' ) as $u => $p ) {
	$c = new ImapClient( '127.0.0.1', 1993, 'ssl', 10, array( 'verify_peer' => false ) );
	$c->connect();
	$c->login( $u, $p );
	foreach ( $c->list_folders() as $f ) {
		$c->select( $f['name'] );
		foreach ( $c->uid_search( 'ALL' ) as $uid ) {
			$c->uid_store( $uid, array( '\\Deleted' ) );
		}
		$c->expunge();
	}
	$c->logout();
}

function mk_account( $label, $email, $pw, $owner, $kind = 'personal' ) {
	return R::save_account(
		array(
			'label'         => $label,
			'kind'          => $kind,
			'owner_user_id' => $owner,
			'email'         => $email,
			'from_name'     => $label . ' – Lindenweg16',
			'username'      => $email,
			'password_enc'  => MailCrypto::encrypt( $pw ),
			'imap_host'     => '127.0.0.1',
			'imap_port'     => 1993,
			'imap_secure'   => 'ssl',
			'smtp_host'     => 'smtp.example.invalid',
			'smtp_port'     => 587,
			'smtp_secure'   => 'tls',
			'signature'     => "{name}\nLindenweg16 · Haste",
			'active'        => 1,
		)
	);
}

$katrin_user = get_user_by( 'login', 'katrin' );
if ( ! $katrin_user ) {
	$uid         = wp_insert_user( array( 'user_login' => 'katrin', 'user_pass' => 'katrin', 'user_email' => 'katrin@example.de', 'role' => 'editor', 'display_name' => 'Katrin Muster' ) );
	$katrin_user = get_userdata( $uid );
}
wp_update_user( array( 'ID' => 1, 'display_name' => 'Sven Meendermann' ) );

echo "== Postfächer und Rechte\n";
$a = mk_account( 'Sven', 'sven@example.de', 'svenpw', 1 );
$b = mk_account( 'Katrin', 'katrin@example.de', 'katrinpw', (int) $katrin_user->ID );
ok( $a && $b, 'Postfächer angelegt' );
ok( null !== R::get_account( $a ) && '' !== R::get_account( $a )['password_enc'] && false === strpos( R::get_account( $a )['password_enc'], 'svenpw' ), 'Passwort liegt verschlüsselt in der Datenbank' );
do_action( 'lw16_mail_access_changed', (int) $katrin_user->ID );
$katrin_user = get_userdata( (int) $katrin_user->ID ); // frisch laden: Rechte stehen im Objekt.
ok( user_can( $katrin_user, MailboxAccess::CAP_USE ), 'Katrin bekommt das Menü-Recht durch ihr Postfach' );
ok( ! user_can( $katrin_user, LW16_CAPABILITY ), 'Katrin ist keine Verwalterin' );
$ka = R::accounts_for_user( (int) $katrin_user->ID, false );
ok( 1 === count( $ka ) && 'Katrin' === $ka[0]['label'] && 'send' === $ka[0]['level'], 'Katrin sieht nur ihr eigenes Postfach' );
ok( 2 === count( R::accounts_for_user( 1, true ) ), 'Verwalter sieht alle Postfächer' );

echo "== Senden Sven → Katrin (SMTP, CI-Layout, Kopie im Gesendet-Ordner)\n";
wp_set_current_user( 1 );
$acc = R::get_account( $a );
$res = ComposeService::send(
	$acc,
	1,
	array(
		'to'      => '"Katrin Muster" <katrin@example.de>',
		'subject' => 'Frage zur Bestellung #1234 – Größe',
		'body'    => "Hallo Katrin,\n\nkannst Du Dir die Bestellung ansehen?\nhttps://example.de/bestellung/1234\n\nDanke!",
	)
);
ok( ! empty( $res['message_id'] ), 'gesendet: ' . $res['message_id'] );
ok( ! $res['warnings'], 'keine Warnungen: ' . implode( '|', $res['warnings'] ) );
$folders = R::get_folders( $a );
ok( isset( $folders['Sent'] ) && 'sent' === $folders['Sent']['role'], 'Gesendet-Ordner erkannt' );
$sent = R::list_messages( $a, 'Sent' );
ok( 1 === $sent['total'], 'Kopie steht im Gesendet-Ordner (lokal)' );
ok( 'Frage zur Bestellung #1234 – Größe' === $sent['rows'][0]['subject'], 'Betreff mit Umlaut/Sonderzeichen' );
$full = R::get_message( (int) $sent['rows'][0]['id'] );
ok( false !== strpos( $full['body_html'], 'template_container' ) || false !== strpos( $full['body_html'], 'lw16-mail' ), 'HTML-Teil im CI-Layout (Theme)' );
ok( false !== strpos( $full['body_html'], 'Lindenweg16 · Haste' ) && false !== strpos( $full['body_html'], 'Sven Meendermann' ), 'Signatur mit Namen des Absenders' );
ok( false !== strpos( $full['body_html'], 'lw16-mail-button' ), 'Link allein in Zeile wird zum Button' );
ok( false !== strpos( $full['body_text'], 'Sven Meendermann' ), 'Klartext-Teil enthält Signatur' );
file_put_contents( '/tmp/lw16-e2e-mail.html', $full['body_html'] );

echo "== Empfangen bei Katrin\n";
$r = ( new SyncService() )->sync_account( $b );
ok( '' === $r['error'] && 1 === $r['new'], 'Abgleich holt 1 neue Mail: ' . json_encode( $r ) );
$inbox = R::list_messages( $b, 'INBOX' );
ok( 1 === $inbox['total'], 'Posteingang hat 1 Mail' );
$in = R::get_message( (int) $inbox['rows'][0]['id'] );
ok( 'sven@example.de' === $in['from_email'], 'Absender: ' . $in['from_name'] . ' <' . $in['from_email'] . '>' );
ok( 0 === (int) $in['is_read'], 'ungelesen' );
ok( R::unread_inbox_total( array( $b ) ) === 1, 'Zähler ungelesen = 1' );

echo "== Antworten (Verlauf)\n";
$bacc = R::get_account( $b );
$rec  = ComposeService::reply_recipients( $in, false, $bacc['email'] );
$res2 = ComposeService::send(
	$bacc,
	(int) $katrin_user->ID,
	array(
		'to'          => $rec['to'],
		'subject'     => ComposeService::prefix_subject( $in['subject'], 'Re' ),
		'body'        => "Klar, mache ich.\n\n" . ComposeService::quote( $in, '19.10.2026 um 10:15' ),
		'reply_to_id' => (int) $in['id'],
	)
);
ok( ! $res2['warnings'], 'Antwort gesendet' );
ok( 1 === (int) R::get_message( (int) $in['id'] )['is_answered'], 'Original als beantwortet markiert' );
$r = ( new SyncService() )->sync_account( $a );
ok( '' === $r['error'] && 1 === $r['new'], 'Sven holt die Antwort: ' . json_encode( $r ) );
$sinbox = R::list_messages( $a, 'INBOX' );
$reply  = R::get_message( (int) $sinbox['rows'][0]['id'] );
ok( 'Re: Frage zur Bestellung #1234 – Größe' === $reply['subject'], 'Betreff Re:' );
ok( $reply['in_reply_to'] === $full['message_id'], 'In-Reply-To zeigt auf die gesendete Mail' );
ok( $reply['thread_key'] === $full['thread_key'], 'gleicher Verlauf (thread_key)' );
ok( 1 === count( R::thread_messages( $a, $reply['thread_key'], (int) $reply['id'] ) ), 'Verlauf zeigt die erste Mail' );
file_put_contents( '/tmp/lw16-e2e-reply.html', $reply['body_html'] );
ok( false !== strpos( $reply['body_html'], 'blockquote' ), 'Zitat als Block im HTML' );
$sig_pos   = strpos( $reply['body_html'], 'lw16-mail-signature' );
$quote_pos = strpos( $reply['body_html'], 'lw16-mail-quote' );
ok( $sig_pos && $quote_pos && $sig_pos < $quote_pos, 'Reihenfolge: Text, Signatur, Zitat' );

echo "== Flags: Server → lokal und zurück\n";
$cl = SyncService::connect( $acc );
$cl->select( 'INBOX' );
$cl->uid_store( (int) $reply['uid'], array( '\\Seen', '\\Flagged' ) );
$cl->logout();
$r = ( new SyncService() )->sync_account( $a );
$reply = R::get_message( (int) $reply['id'] );
ok( 1 === (int) $reply['is_read'] && 1 === (int) $reply['is_starred'], 'Flags vom Server übernommen' );

echo "== Löschen auf dem Server\n";
$cl = SyncService::connect( $acc );
$cl->select( 'INBOX' );
$cl->uid_store( (int) $reply['uid'], array( '\\Deleted' ) );
$cl->expunge();
$cl->logout();
$r = ( new SyncService() )->sync_account( $a );
ok( 1 === $r['removed'] && null === R::get_message( (int) $reply['id'] ), 'Auf dem Server gelöschte Mail verschwindet lokal' );

echo "== Folgeabgleich ohne Neues\n";
$r = ( new SyncService() )->sync_account( $a );
ok( 0 === $r['new'] && '' === $r['error'], 'nichts Neues, kein Fehler' );

echo "== Passwort ändern → Fehlermeldung statt Absturz\n";
R::save_account( array( 'password_enc' => MailCrypto::encrypt( 'falsch' ) ), $a );
$r = ( new SyncService() )->sync_account( $a );
ok( '' !== $r['error'] && false !== stripos( R::get_account( $a )['last_error'], 'abgelehnt' ), 'Fehler gespeichert: ' . $r['error'] );
R::save_account( array( 'password_enc' => MailCrypto::encrypt( 'svenpw' ) ), $a );
$r = ( new SyncService() )->sync_account( $a );
ok( '' === $r['error'] && null === R::get_account( $a )['last_error'], 'wieder verbunden, Fehler gelöscht' );


echo "== Befehle an den Mailserver (Hintergrundjob run_server_op)\n";
$module = new \Lindenweg16\Modules\Mailbox\MailboxModule();
$put    = function ( $account, $subject ) {
	$cl = SyncService::connect( $account );
	$cl->append( 'INBOX', "From: x@kunde.example\r\nTo: a@b.de\r\nSubject: $subject\r\nMessage-ID: <" . uniqid() . "@kunde.example>\r\nDate: Mon, 19 Oct 2026 10:00:00 +0200\r\n\r\nText", array() );
	$cl->logout();
};
$put( $acc, 'Server-Befehl' );
( new SyncService() )->sync_account( $a );
$m = R::list_messages( $a, 'INBOX' )['rows'][0];
$server_flags = function ( $account, $folder, $uid ) {
	$cl = SyncService::connect( $account );
	$cl->select( $folder );
	$meta = $cl->uid_fetch_meta( (string) $uid );
	$cl->logout();
	return isset( $meta[ $uid ] ) ? $meta[ $uid ]['flags'] : null;
};
$module->run_server_op( $a, 'INBOX', (int) $m['uid'], 'seen', '' );
ok( in_array( '\\Seen', $server_flags( $acc, 'INBOX', (int) $m['uid'] ), true ), 'seen → \\Seen am Server' );
$module->run_server_op( $a, 'INBOX', (int) $m['uid'], 'unseen', '' );
ok( ! in_array( '\\Seen', $server_flags( $acc, 'INBOX', (int) $m['uid'] ), true ), 'unseen → \\Seen entfernt' );
$module->run_server_op( $a, 'INBOX', (int) $m['uid'], 'flag', '' );
ok( in_array( '\\Flagged', $server_flags( $acc, 'INBOX', (int) $m['uid'] ), true ), 'flag → \\Flagged' );
$module->run_server_op( $a, 'INBOX', (int) $m['uid'], 'move', 'Trash' );
ok( null === $server_flags( $acc, 'INBOX', (int) $m['uid'] ), 'move → aus dem Posteingang weg' );
( new SyncService() )->sync_account( $a );
$trash = R::list_messages( $a, 'Trash' );
ok( 1 === $trash['total'] && 'Server-Befehl' === $trash['rows'][0]['subject'], 'move → beim Abgleich im Papierkorb' );
$module->run_server_op( $a, 'Trash', (int) $trash['rows'][0]['uid'], 'delete', '' );
( new SyncService() )->sync_account( $a );
ok( 0 === R::list_messages( $a, 'Trash' )['total'], 'delete → endgültig weg' );

echo "== Hintergrundjob (Action Scheduler Hook)\n";
$put( $acc, 'Per Hintergrundjob' );
do_action( \Lindenweg16\Core\Scheduler::HOOK_MAIL_SYNC );
$subjects = array_column( R::list_messages( $a, 'INBOX' )['rows'], 'subject' );
ok( in_array( 'Per Hintergrundjob', $subjects, true ), 'Mail vom Hintergrundjob angekommen' );

echo "== Großes Postfach: erster Abgleich lädt neueste zuerst, Rest läuft nach\n";
$cl = SyncService::connect( $acc );
for ( $i = 1; $i <= 25; $i++ ) {
	$cl->append( 'Spam', "From: s$i@spam.example\r\nTo: a@b.de\r\nSubject: Spam $i\r\nMessage-ID: <spam$i@spam.example>\r\nDate: Mon, " . ( 5 + $i % 20 ) . " Oct 2026 10:00:00 +0200\r\n\r\nText $i", array() );
}
$cl->logout();
R::delete_folder( $a, 'Spam' );
$svc = new SyncService();
$r1  = $svc->sync_account( $a, array( 'batch' => 10 ) );
$spam1 = R::list_messages( $a, 'Spam', array( 'per_page' => 100 ) );
ok( 10 === $spam1['total'] && $r1['more'], 'Durchgang 1: 10 von 25, more=true (' . $spam1['total'] . ')' );
$uids1 = array_map( function ( $x ) { return (int) $x['uid']; }, $spam1['rows'] );
ok( min( $uids1 ) >= 16, 'zuerst die neuesten (UID ab ' . min( $uids1 ) . ')' );
$r2 = $svc->sync_account( $a, array( 'batch' => 10 ) );
$r3 = $svc->sync_account( $a, array( 'batch' => 10 ) );
$r4 = $svc->sync_account( $a, array( 'batch' => 10 ) );
$spam4 = R::list_messages( $a, 'Spam', array( 'per_page' => 100 ) );
ok( 25 === $spam4['total'] && ! $r4['more'], 'nach 3 weiteren Durchgängen alle 25, more=false (' . $spam4['total'] . ')' );
$f = R::get_folders( $a )['Spam'];
ok( 0 === (int) $f['backfill_uid'], 'Nachladen abgeschlossen (backfill_uid=0)' );
// Neue Mail zwischendurch
$cl = SyncService::connect( $acc );
$cl->append( 'Spam', "From: neu@spam.example\r\nTo: a@b.de\r\nSubject: Spam neu\r\nMessage-ID: <spamneu@spam.example>\r\nDate: Mon, 19 Oct 2026 10:00:00 +0200\r\n\r\nNeu", array() );
$cl->logout();
$svc->sync_account( $a, array( 'batch' => 10 ) );
ok( 26 === R::list_messages( $a, 'Spam', array( 'per_page' => 100 ) )['total'], 'neue Mail wird danach geholt' );

echo "== UIDVALIDITY ändert sich → lokaler Bestand wird verworfen\n";
$wpdb->update( R::table( 'folders' ), array( 'uidvalidity' => 12345 ), array( 'account_id' => $a, 'name' => 'Spam' ) );
$svc->sync_account( $a, array( 'batch' => 100 ) );
ok( 26 === R::list_messages( $a, 'Spam', array( 'per_page' => 100 ) )['total'], 'nach UIDVALIDITY-Wechsel neu geladen, keine Dubletten' );

echo $fail ? "\n$fail Fehler\n" : "\nAlles ok\n";
exit( $fail ? 1 : 0 );
