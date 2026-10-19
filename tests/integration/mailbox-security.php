<?php
/**
 * Zugriffs-Test der Mailbox in einer echten WordPress-Installation.
 *
 *   wp eval-file tests/integration/mailbox-security.php
 *
 * Läuft nach mailbox-e2e.php (braucht dessen Postfächer und Mails). Spielt
 * Angriffe auf die Handler nach: Katrin (Besitzerin ihres eigenen Postfachs)
 * versucht, Mails in Svens Postfach zu lesen, zu ändern und zu senden; eine
 * Mitnutzerin ohne Senderecht versucht zu senden.
 */

use Lindenweg16\Modules\Mailbox\MailboxModule;
use Lindenweg16\Modules\Mailbox\MailRepository as R;

$fail = 0;
function ok( $cond, $label ) {
	global $fail;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

// wp_die und die JSON-Antworten werden zu Ausnahmen, damit der Test weiterläuft.
$die = function () {
	return function ( $message = '', $title = '', $args = array() ) {
		$code = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 500;
		throw new \RuntimeException( 'DIE:' . $code . ':' . wp_strip_all_tags( (string) ( is_string( $message ) ? $message : '' ) ) );
	};
};
add_filter( 'wp_doing_ajax', '__return_true' ); // wp_send_json_* soll wp_die statt exit aufrufen.
add_filter( 'wp_die_handler', $die );
add_filter( 'wp_die_ajax_handler', $die );
add_filter(
	'wp_redirect',
	function ( $location ) {
		throw new \RuntimeException( 'REDIRECT:' . $location );
	},
	PHP_INT_MIN
);

function attempt( callable $fn ) {
	ob_start();
	try {
		$fn();
		$out = 'NOTHING';
	} catch ( \RuntimeException $e ) {
		$out = $e->getMessage();
	}
	$buf = ob_get_clean();
	return $out . ( '' !== $buf ? ' [' . trim( $buf ) . ']' : '' );
}

function as_user( $login, array $post = array(), array $get = array(), $nonce_action = '' ) {
	$user = get_user_by( 'login', $login );
	wp_set_current_user( $user->ID );
	$_POST    = $post;
	$_GET     = $get;
	$_REQUEST = array_merge( $get, $post );
	if ( '' !== $nonce_action ) {
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = $_GET['_wpnonce'] = wp_create_nonce( $nonce_action );
		$_REQUEST['nonce']    = $_POST['nonce'] = wp_create_nonce( $nonce_action );
	}
}

global $wpdb;
$sven   = R::all_accounts()[1]; // Sven (nach Bezeichnung sortiert: Katrin, Sven).
$katrin = R::all_accounts()[0];
ok( 'Sven' === $sven['label'] && 'Katrin' === $katrin['label'], 'Testdaten aus mailbox-e2e.php vorhanden' );
$m = R::list_messages( (int) $sven['id'], 'INBOX' )['rows'][0];
$mid = (int) $m['id'];
$module = new MailboxModule();

echo "== Katrin gegen Svens Postfach\n";
foreach ( array( 'read', 'unread', 'star', 'trash', 'delete', 'spam', 'assign' ) as $do ) {
	as_user( 'katrin', array(), array( 'id' => $mid, 'do' => $do ), 'lw16_mail_' . $mid );
	$r = attempt( array( $module, 'handle_action' ) );
	ok( 0 === strpos( $r, 'DIE:403' ), "Aktion $do wird abgewiesen ($r)" );
}
ok( null !== R::get_message( $mid ), 'Mail ist unverändert da' );

as_user( 'katrin', array( 'id' => $mid, 'note' => 'Hallo' ), array(), 'lw16_mail_note_' . $mid );
ok( 0 === strpos( attempt( array( $module, 'handle_note' ) ), 'DIE:403' ), 'Notiz an fremder Mail wird abgewiesen' );
ok( array() === R::notes( $mid ), 'keine Notiz gespeichert' );

as_user( 'katrin', array( 'account' => $sven['id'], 'to' => 'x@example.de', 'subject' => 's', 'body' => 'b' ), array(), 'lw16_mail_send' );
ok( 0 === strpos( attempt( array( $module, 'handle_send' ) ), 'DIE:403' ), 'Senden über Svens Postfach wird abgewiesen' );

as_user( 'katrin', array( 'account' => $sven['id'] ), array(), 'lw16_mail' );
ok( false !== strpos( attempt( array( $module, 'ajax_sync' ) ), 'Keine Berechtigung' ), 'Abgleich von Svens Postfach wird abgewiesen' );
as_user( 'katrin', array( 'account' => $sven['id'], 'subject' => 'x', 'body' => 'y' ), array(), 'lw16_mail' );
ok( false !== strpos( attempt( array( $module, 'ajax_preview' ) ), 'Keine Berechtigung' ), 'Vorschau über Svens Postfach wird abgewiesen' );

// Anhang einer Mail aus Svens Postfach.
$att = $wpdb->get_row( 'SELECT * FROM ' . R::table( 'attachments' ) . ' LIMIT 1', ARRAY_A );
if ( $att ) {
	as_user( 'katrin', array(), array( 'id' => $att['id'] ), 'lw16_mail_att_' . $att['id'] );
	$owner = R::get_message( (int) $att['message_id'] )['account_id'];
	if ( (int) $owner === (int) $sven['id'] ) {
		ok( 0 === strpos( attempt( array( $module, 'handle_attachment' ) ), 'DIE:403' ), 'Anhang aus Svens Postfach wird abgewiesen' );
	}
}

echo "== Ohne Nonce / falsche Nonce\n";
as_user( 'admin', array(), array( 'id' => $mid, 'do' => 'star' ) );
$_REQUEST['_wpnonce'] = $_GET['_wpnonce'] = 'falsch';
ok( 0 === strpos( attempt( array( $module, 'handle_action' ) ), 'DIE:403' ), 'falsche Nonce wird abgewiesen' );

echo "== Fremde Nonce: Nonce für Mail A gilt nicht für Mail B\n";
$other = R::list_messages( (int) $sven['id'], 'INBOX' )['rows'];
if ( count( $other ) > 1 ) {
	as_user( 'admin', array(), array( 'id' => (int) $other[1]['id'], 'do' => 'star' ), 'lw16_mail_' . (int) $other[0]['id'] );
	ok( 0 === strpos( attempt( array( $module, 'handle_action' ) ), 'DIE:403' ), 'Nonce einer anderen Mail wird abgewiesen' );
}

echo "== Nur-Lesen-Mitnutzerin\n";
$shared = R::save_account(
	array(
		'label' => 'Info', 'kind' => 'shared', 'owner_user_id' => null, 'email' => 'info@example.de', 'from_name' => 'Info',
		'username' => 'info@example.de', 'password_enc' => $sven['password_enc'], 'imap_host' => '127.0.0.1', 'imap_port' => 1993,
		'imap_secure' => 'ssl', 'smtp_host' => 'x', 'smtp_port' => 587, 'smtp_secure' => 'tls', 'signature' => '', 'active' => 1,
	)
);
$katrin_id = get_user_by( 'login', 'katrin' )->ID;
R::set_shares( $shared, array( (int) $katrin_id => false ) ); // nur lesen
$sid = R::insert_message(
	array(
		'account_id' => $shared, 'folder' => 'INBOX', 'uid' => 1, 'message_id' => 'shared1@x', 'thread_key' => 'shared1@x',
		'from_name' => 'Kunde', 'from_email' => 'kunde@kunde.example', 'to_json' => '[]', 'cc_json' => '[]', 'subject' => 'Geteilt',
		'sent_at' => gmdate( 'Y-m-d H:i:s' ), 'snippet' => '', 'body_text' => 'x', 'is_read' => 0,
	)
);
as_user( 'katrin', array(), array( 'id' => $sid, 'do' => 'star' ), 'lw16_mail_' . $sid );
$r = attempt( array( $module, 'handle_action' ) );
ok( 0 === strpos( $r, 'REDIRECT:' ), 'Markieren in geteiltem Postfach erlaubt' );
ok( 1 === (int) R::get_message( $sid )['is_starred'], 'Markierung gesetzt' );

as_user( 'katrin', array(), array( 'id' => $sid, 'do' => 'trash' ), 'lw16_mail_' . $sid );
ok( 0 === strpos( attempt( array( $module, 'handle_action' ) ), 'DIE:403' ), 'Löschen ohne Senderecht abgewiesen' );
as_user( 'katrin', array( 'account' => $shared, 'to' => 'x@example.de', 'subject' => 's', 'body' => 'b' ), array(), 'lw16_mail_send' );
ok( 0 === strpos( attempt( array( $module, 'handle_send' ) ), 'DIE:403' ), 'Senden ohne Senderecht abgewiesen' );

echo "== Zuweisung nur an Berechtigte\n";
$admin_id = get_user_by( 'login', 'admin' )->ID;
as_user( 'katrin', array( 'assign_to' => $admin_id ), array( 'id' => $sid, 'do' => 'assign' ), 'lw16_mail_' . $sid );
attempt( array( $module, 'handle_action' ) );
// admin ist Verwalter und darf das Postfach sehen → Zuweisung erlaubt.
ok( (int) R::get_message( $sid )['assigned_user_id'] === (int) $admin_id, 'Zuweisung an Verwalter erlaubt' );
$sub = wp_insert_user( array( 'user_login' => 'sub', 'user_pass' => 'x', 'user_email' => 'sub@example.de', 'role' => 'editor' ) );
as_user( 'katrin', array( 'assign_to' => $sub ), array( 'id' => $sid, 'do' => 'assign' ), 'lw16_mail_' . $sid );
attempt( array( $module, 'handle_action' ) );
ok( (int) R::get_message( $sid )['assigned_user_id'] === 0, 'Zuweisung an Nutzer ohne Zugriff wird verworfen' );

echo "== Menü-Recht und Seitenzugriff\n";
as_user( 'sub' );
ok( ! \Lindenweg16\Modules\Mailbox\MailboxAccess::can_open(), 'Nutzer ohne Postfach sieht die Mailbox nicht' );
$_GET = array();
ok( 0 === strpos( attempt( array( $module, 'render_page' ) ), 'DIE:' ), 'Seite ohne Recht: abgewiesen' );
as_user( 'katrin' );
ok( ! \Lindenweg16\Modules\Mailbox\MailboxAccess::is_manager(), 'Katrin ist keine Verwalterin' );
$acc_page = new \Lindenweg16\Modules\Mailbox\AccountsPage();
ok( 0 === strpos( attempt( array( $acc_page, 'render_page' ) ), 'DIE:' ), 'Postfach-Verwaltung für Katrin gesperrt' );
as_user( 'katrin', array( 'label' => 'X' ), array(), 'lw16_mail_account_save' );
ok( 0 === strpos( attempt( array( $acc_page, 'handle_save' ) ), 'DIE:403' ), 'Postfach anlegen für Katrin gesperrt' );
as_user( 'katrin', array(), array( 'id' => $sven['id'] ), 'lw16_mail_account_delete_' . $sven['id'] );
ok( 0 === strpos( attempt( array( $acc_page, 'handle_delete' ) ), 'DIE:403' ), 'Postfach löschen für Katrin gesperrt' );
ok( null !== R::get_account( (int) $sven['id'] ), 'Svens Postfach ist noch da' );

// Aufräumen.
R::delete_account( $shared );
wp_delete_user( $sub );

echo $fail ? "\n$fail Fehler\n" : "\nAlles ok\n";
exit( $fail ? 1 : 0 );
