<?php
/**
 * Modul Mailbox: eine Mailbox je Mitarbeiter, Versand im CI.
 *
 * - Postfächer (persönlich oder geteilt, z. B. info@) bei IONOS oder einem
 *   anderen IMAP/SMTP-Anbieter; Zugangsdaten verschlüsselt.
 * - Eingang per IMAP-Abgleich im Hintergrund (alle fünf Minuten) und auf Knopfdruck.
 * - Senden über den SMTP-Server des Postfachs im Layout der Automatik-Mails,
 *   Kopie im Gesendet-Ordner.
 * - Rechte: Besitzer, Mitnutzer (nur lesen oder auch senden), Verwalter.
 *
 * Diese Klasse hängt die Hooks ein und enthält die Formular-Handler; die
 * Darstellung steckt in `MailboxView`, die Postfach-Verwaltung in `AccountsPage`.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;
use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Scheduler;

/**
 * Hooks und Handler der Mailbox.
 */
class MailboxModule {

	const PAGE = 'lw16-mailbox';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( Scheduler::HOOK_MAIL_SYNC, array( SyncService::class, 'run_all' ) );
		add_action( Scheduler::HOOK_MAIL_SERVER_OP, array( $this, 'run_server_op' ), 10, 5 );

		// Eine Rechte-Änderung am Postfach (Besitzer, Mitnutzer) hält das Menü-Recht aktuell.
		add_action( 'lw16_mail_access_changed', array( $this, 'refresh_user_caps' ) );

		// Auskunft und Löschanfragen nach DSGVO (läuft über die WordPress-Werkzeuge).
		( new MailPrivacy() )->register();

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'lw16_render_page_lw16_mailbox', array( $this, 'render_page' ) );

		add_action( 'admin_post_lw16_mail_send', array( $this, 'handle_send' ) );
		add_action( 'admin_post_lw16_mail_action', array( $this, 'handle_action' ) );
		add_action( 'admin_post_lw16_mail_note', array( $this, 'handle_note' ) );
		add_action( 'admin_post_lw16_mail_attachment', array( $this, 'handle_attachment' ) );
		add_action( 'wp_ajax_lw16_mail_sync', array( $this, 'ajax_sync' ) );
		add_action( 'wp_ajax_lw16_mail_preview', array( $this, 'ajax_preview' ) );

		( new AccountsPage() )->register();
		( new OrderMailsBox() )->register();
	}

	/* --------------------------------------------------------------------
	 * Menü und Assets
	 * ------------------------------------------------------------------ */

	/**
	 * Eigenes Hauptmenü „Mailbox“ – unabhängig von den fünf Gruppen, weil
	 * Mitarbeiter ohne Betriebsrechte nur dieses eine Menü sehen sollen.
	 */
	public function add_menu() {
		if ( ! MailboxAccess::can_open() ) {
			return;
		}

		$bubble = '';
		$unread = $this->unread_total();
		if ( $unread > 0 ) {
			$bubble = ' <span class="awaiting-mod">' . (int) $unread . '</span>';
		}

		add_menu_page(
			__( 'Mailbox', 'lindenweg16' ),
			__( 'Mailbox', 'lindenweg16' ) . $bubble,
			MailboxAccess::menu_cap(),
			self::PAGE,
			array( $this, 'render_page' ),
			'dashicons-email-alt',
			55
		);

		if ( MailboxAccess::is_manager() ) {
			add_submenu_page(
				self::PAGE,
				__( 'Postfächer', 'lindenweg16' ),
				__( 'Postfächer', 'lindenweg16' ),
				MailboxAccess::menu_cap(),
				AccountsPage::PAGE,
				array( new AccountsPage(), 'render_page' )
			);
		}
	}

	/**
	 * Ungelesene Mails im Posteingang aller Postfächer des Nutzers.
	 *
	 * @return int
	 */
	protected function unread_total() {
		$ids = array();

		foreach ( MailRepository::accounts_for_user( get_current_user_id(), MailboxAccess::is_manager() ) as $a ) {
			if ( ! empty( $a['active'] ) ) {
				$ids[] = (int) $a['id'];
			}
		}

		return MailRepository::unread_inbox_total( $ids );
	}

	/**
	 * Stile und Skript nur auf den Mailbox-Seiten.
	 *
	 * @param string $hook Aktueller Admin-Hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'lw16-mailbox' ) ) {
			return;
		}

		wp_enqueue_style( 'lw16-mailbox', LW16_PLUGIN_URL . 'assets/css/mailbox.css', array(), Assets::version( 'assets/css/mailbox.css' ) );
		wp_enqueue_script( 'lw16-mailbox', LW16_PLUGIN_URL . 'assets/js/mailbox.js', array(), Assets::version( 'assets/js/mailbox.js' ), true );
		wp_localize_script(
			'lw16-mailbox',
			'LW16Mailbox',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'lw16_mail' ),
				'syncMs'   => 120000,
				'i18n'     => array(
					'syncing'  => __( 'Rufe Mails ab …', 'lindenweg16' ),
					'synced'   => __( 'Aktuell.', 'lindenweg16' ),
					'newMails' => __( 'neue Mails – Seite neu laden', 'lindenweg16' ),
					'error'    => __( 'Fehler beim Abrufen:', 'lindenweg16' ),
					'sending'  => __( 'Sende …', 'lindenweg16' ),
					'confirm'  => __( 'Wirklich löschen?', 'lindenweg16' ),
					'preview'  => __( 'Vorschau wird erstellt …', 'lindenweg16' ),
				),
			)
		);
	}

	/* --------------------------------------------------------------------
	 * Seite
	 * ------------------------------------------------------------------ */

	/**
	 * Die Mailbox-Seite.
	 */
	public function render_page() {
		if ( ! MailboxAccess::can_open() ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}

		( new MailboxView() )->render();
	}

	/* --------------------------------------------------------------------
	 * Zugriff
	 * ------------------------------------------------------------------ */

	/**
	 * Grundprüfung jedes Handlers: Recht, Nonce.
	 *
	 * @param string $nonce_action Name der Nonce.
	 * @param bool   $ajax         Ob es ein AJAX-Aufruf ist.
	 */
	protected function guard( $nonce_action, $ajax = false ) {
		if ( ! current_user_can( MailboxAccess::CAP_USE ) && ! current_user_can( MailboxAccess::CAP_MANAGE ) && ! current_user_can( LW16_CAPABILITY ) ) {
			if ( $ajax ) {
				wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'lindenweg16' ) ), 403 );
			}
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ), '', array( 'response' => 403 ) );
		}

		if ( $ajax ) {
			check_ajax_referer( $nonce_action, 'nonce' );
		} else {
			check_admin_referer( $nonce_action );
		}
	}

	/**
	 * Postfach laden und prüfen, ob der Nutzer die nötige Stufe hat.
	 *
	 * @param int    $account_id Postfach.
	 * @param string $needed     read oder send.
	 * @param bool   $ajax       AJAX-Antwort statt wp_die.
	 * @return array Das Postfach.
	 */
	protected function account_for( $account_id, $needed, $ajax = false ) {
		$account = MailRepository::get_account( (int) $account_id );
		$level   = $account ? MailboxAccess::level( $account, MailRepository::get_shares( (int) $account['id'] ), get_current_user_id(), MailboxAccess::is_manager() ) : 'none';

		if ( ! $account || ! MailboxAccess::allows( $level, $needed ) ) {
			if ( $ajax ) {
				wp_send_json_error( array( 'message' => __( 'Keine Berechtigung für dieses Postfach.', 'lindenweg16' ) ), 403 );
			}
			wp_die( esc_html__( 'Keine Berechtigung für dieses Postfach.', 'lindenweg16' ), '', array( 'response' => 403 ) );
		}

		return $account;
	}

	/**
	 * Einen Hinweis für die nächste Seite merken.
	 *
	 * @param string $type success, error oder warning.
	 * @param string $text Text.
	 */
	public static function notice( $type, $text ) {
		set_transient( 'lw16_mail_notice_' . get_current_user_id(), array( 'type' => $type, 'text' => $text ), 120 );
	}

	/**
	 * Adresse der Mailbox mit Parametern.
	 *
	 * @param array $args Parameter.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		// add_query_arg() kodiert die Werte nicht selbst: Ohne das bräche ein "#" oder "&"
		// in Ordnernamen, Suchtexten und Betreffzeilen die Adresse ab.
		$args = array_map(
			static function ( $value ) {
				return rawurlencode( (string) $value );
			},
			array_merge( array( 'page' => self::PAGE ), $args )
		);

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Menü-Recht (lw16_mail_use) nach einer Änderung bei den Postfächern anpassen.
	 *
	 * @param int $user_id Nutzer.
	 */
	public function refresh_user_caps( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}

		if ( MailRepository::user_has_any_account( (int) $user_id ) ) {
			$user->add_cap( MailboxAccess::CAP_USE );
		} else {
			$user->remove_cap( MailboxAccess::CAP_USE );
		}
	}

	/* --------------------------------------------------------------------
	 * Senden und Entwürfe
	 * ------------------------------------------------------------------ */

	/**
	 * Mail senden oder Entwurf speichern (Formular der Mailbox).
	 */
	public function handle_send() {
		$this->guard( 'lw16_mail_send' );

		$account = $this->account_for( isset( $_POST['account'] ) ? (int) $_POST['account'] : 0, 'send' ); // phpcs:ignore WordPress.Security.NonceVerification

		$data = array(
			'to'          => isset( $_POST['to'] ) ? ComposeService::clean_text( wp_unslash( $_POST['to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'cc'          => isset( $_POST['cc'] ) ? ComposeService::clean_text( wp_unslash( $_POST['cc'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'bcc'         => isset( $_POST['bcc'] ) ? ComposeService::clean_text( wp_unslash( $_POST['bcc'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'subject'     => isset( $_POST['subject'] ) ? ComposeService::clean_text( wp_unslash( $_POST['subject'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'body'        => isset( $_POST['body'] ) ? ComposeService::clean_text( wp_unslash( $_POST['body'] ), true ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'reply_to_id' => isset( $_POST['reply_to_id'] ) ? (int) $_POST['reply_to_id'] : 0, // phpcs:ignore WordPress.Security.NonceVerification
			'draft_id'    => isset( $_POST['draft_id'] ) ? (int) $_POST['draft_id'] : 0, // phpcs:ignore WordPress.Security.NonceVerification
		);

		// Das Zitat gehört zu einer Mail dieses Postfachs – sonst ließe sich über die ID fremder Inhalt anhängen.
		if ( $data['reply_to_id'] ) {
			$ref = MailRepository::get_message( $data['reply_to_id'] );
			if ( ! $ref || (int) $ref['account_id'] !== (int) $account['id'] ) {
				$data['reply_to_id'] = 0;
			}
		}
		if ( $data['draft_id'] ) {
			$draft = MailRepository::get_message( $data['draft_id'] );
			if ( ! $draft || (int) $draft['account_id'] !== (int) $account['id'] || MailRepository::DRAFTS !== $draft['folder'] ) {
				$data['draft_id'] = 0;
			}
		}

		$back = array(
			'account' => (int) $account['id'],
			'compose' => 'new',
		);

		if ( isset( $_POST['save_draft'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$id = ComposeService::save_draft( (int) $account['id'], get_current_user_id(), $data, $data['draft_id'] );
			self::notice( 'success', __( 'Entwurf gespeichert.', 'lindenweg16' ) );
			wp_safe_redirect( self::url( array_merge( $back, array( 'compose' => 'draft', 'ref' => $id ) ) ) );
			exit;
		}

		$data['attachments'] = $this->collect_uploads();

		try {
			$result = ComposeService::send( $account, get_current_user_id(), $data );
		} catch ( \RuntimeException $e ) {
			// Nichts geht verloren: Der Text bleibt als Entwurf erhalten.
			$id = ComposeService::save_draft( (int) $account['id'], get_current_user_id(), $data, $data['draft_id'] );
			self::notice(
				'error',
				$e->getMessage() . ( $data['attachments'] ? ' ' . __( 'Anhänge bitte erneut anhängen.', 'lindenweg16' ) : '' )
			);
			wp_safe_redirect( self::url( array_merge( $back, array( 'compose' => 'draft', 'ref' => $id ) ) ) );
			exit;
		}

		self::notice( $result['warnings'] ? 'warning' : 'success', $result['warnings'] ? implode( ' ', $result['warnings'] ) : __( 'Mail gesendet.', 'lindenweg16' ) );

		$sent = '';
		foreach ( MailRepository::get_folders( (int) $account['id'] ) as $f ) {
			if ( 'sent' === $f['role'] ) {
				$sent = $f['name'];
				break;
			}
		}

		wp_safe_redirect( self::url( array( 'account' => (int) $account['id'], 'folder' => $sent ) ) );
		exit;
	}

	/**
	 * Hochgeladene Anhänge aus $_FILES einsammeln.
	 *
	 * @return array Liste aus {path, name, type}.
	 */
	protected function collect_uploads() {
		$out = array();

		if ( empty( $_FILES['attachments']['name'] ) || ! is_array( $_FILES['attachments']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $out;
		}

		foreach ( $_FILES['attachments']['name'] as $i => $name ) { // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			$tmp   = isset( $_FILES['attachments']['tmp_name'][ $i ] ) ? $_FILES['attachments']['tmp_name'][ $i ] : ''; // phpcs:ignore
			$error = isset( $_FILES['attachments']['error'][ $i ] ) ? (int) $_FILES['attachments']['error'][ $i ] : UPLOAD_ERR_NO_FILE; // phpcs:ignore

			if ( UPLOAD_ERR_OK !== $error || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
				continue;
			}

			$out[] = array(
				'path' => $tmp,
				'name' => MimeParser::clean_filename( wp_basename( wp_unslash( $name ) ) ),
				'type' => '',
			);
		}

		return $out;
	}

	/**
	 * Vorschau der Mail im CI-Layout (AJAX).
	 */
	public function ajax_preview() {
		$this->guard( 'lw16_mail', true );

		$account = $this->account_for( isset( $_POST['account'] ) ? (int) $_POST['account'] : 0, 'send', true ); // phpcs:ignore WordPress.Security.NonceVerification

		$subject = isset( $_POST['subject'] ) ? ComposeService::clean_text( wp_unslash( $_POST['subject'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$body    = isset( $_POST['body'] ) ? ComposeService::clean_text( wp_unslash( $_POST['body'] ), true ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$user    = wp_get_current_user();
		$parts   = ComposeService::split_quote( $body );
		$sig     = ComposeService::render_signature( (string) $account['signature'], $user->display_name );

		wp_send_json_success(
			array(
				'html' => ComposeService::build_html( $parts['main'], '' !== $subject ? $subject : __( '(ohne Betreff)', 'lindenweg16' ), $sig, $parts['quote'] ),
			)
		);
	}

	/* --------------------------------------------------------------------
	 * Aktionen an einer Mail
	 * ------------------------------------------------------------------ */

	/**
	 * Aktion an einer Nachricht: gelesen, Stern, verschieben, löschen, zuweisen.
	 */
	public function handle_action() {
		$id = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$this->guard( 'lw16_mail_' . $id );

		$msg = MailRepository::get_message( $id );
		if ( ! $msg ) {
			wp_die( esc_html__( 'Nachricht nicht gefunden.', 'lindenweg16' ) );
		}

		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		// Lesen und Markieren darf, wer lesen darf; verschieben und löschen nur, wer auch senden darf.
		$needs   = in_array( $do, array( 'read', 'unread', 'star', 'unstar', 'assign' ), true ) ? 'read' : 'send';
		$account = $this->account_for( (int) $msg['account_id'], $needs );

		$folder   = $msg['folder'];
		$redirect = array(
			'account' => (int) $account['id'],
			'folder'  => $folder,
		);
		// Aus der Liste heraus (Stern): zurück zur Liste statt in die Mail.
		$keep_msg = ! ( isset( $_REQUEST['back'] ) && 'list' === $_REQUEST['back'] ); // phpcs:ignore WordPress.Security.NonceVerification

		switch ( $do ) {
			case 'read':
			case 'unread':
				$this->set_flag( $account, $msg, 'seen', 'read' === $do );
				break;

			case 'star':
			case 'unstar':
				$this->set_flag( $account, $msg, 'flag', 'star' === $do );
				break;

			case 'assign':
				$user = isset( $_POST['assign_to'] ) ? (int) $_POST['assign_to'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
				// Nur an jemanden, der das Postfach sehen darf.
				if ( $user && ! MailboxAccess::allows( MailboxAccess::level( $account, MailRepository::get_shares( (int) $account['id'] ), $user, (bool) user_can( $user, LW16_CAPABILITY ) || (bool) user_can( $user, MailboxAccess::CAP_MANAGE ) ), 'read' ) ) {
					$user = 0;
				}
				MailRepository::update_message( $id, array( 'assigned_user_id' => $user ? $user : null ) );
				AuditLog::record( 'mail_zugewiesen', 'mail_message', $id, array( 'an' => $user ) );
				break;

			case 'trash':
				$this->move_to_role( $account, $msg, 'trash' );
				$keep_msg = false;
				break;

			case 'spam':
				$this->move_to_role( $account, $msg, 'spam' );
				$keep_msg = false;
				break;

			case 'notspam':
				$this->move_to_role( $account, $msg, 'inbox' );
				$keep_msg = false;
				break;

			case 'move':
				$dest    = isset( $_POST['dest'] ) ? (string) wp_unslash( $_POST['dest'] ) : ''; // Wird gegen die bekannten Ordner geprüft. // phpcs:ignore WordPress.Security.NonceVerification
				$folders = MailRepository::get_folders( (int) $account['id'] );
				if ( isset( $folders[ $dest ] ) && $dest !== $folder ) {
					$this->move_message( $account, $msg, $dest );
				}
				$keep_msg = false;
				break;

			case 'delete':
				$this->delete_message( $account, $msg );
				$keep_msg = false;
				break;

			default:
				wp_die( esc_html__( 'Unbekannte Aktion.', 'lindenweg16' ) );
		}

		if ( $keep_msg ) {
			$redirect['msg'] = $id;
		}

		wp_safe_redirect( self::url( $redirect ) );
		exit;
	}

	/**
	 * Eine Markierung setzen (lokal sofort, am Server im Hintergrund).
	 *
	 * @param array  $account Postfach.
	 * @param array  $msg     Nachricht.
	 * @param string $flag    seen oder flag.
	 * @param bool   $on      Setzen oder entfernen.
	 */
	public function set_flag( array $account, array $msg, $flag, $on ) {
		$column = 'seen' === $flag ? 'is_read' : 'is_starred';
		MailRepository::update_message( (int) $msg['id'], array( $column => $on ? 1 : 0 ) );

		if ( ! empty( $msg['uid'] ) && MailRepository::DRAFTS !== $msg['folder'] ) {
			$op = 'seen' === $flag ? ( $on ? 'seen' : 'unseen' ) : ( $on ? 'flag' : 'unflag' );
			Scheduler::enqueue_async( Scheduler::HOOK_MAIL_SERVER_OP, array( (int) $account['id'], $msg['folder'], (int) $msg['uid'], $op, '' ) );
		}
	}

	/**
	 * Eine geöffnete Mail als gelesen markieren (lokal sofort, am Server im Hintergrund).
	 *
	 * @param array $account Postfach.
	 * @param array $msg     Nachricht.
	 */
	public static function mark_read( array $account, array $msg ) {
		( new self() )->set_flag( $account, $msg, 'seen', true );
	}

	/**
	 * Nachricht in den Ordner mit einer bestimmten Rolle verschieben.
	 *
	 * @param array  $account Postfach.
	 * @param array  $msg     Nachricht.
	 * @param string $role    trash, spam oder inbox.
	 */
	public function move_to_role( array $account, array $msg, $role ) {
		// Entwürfe gibt es nur lokal.
		if ( MailRepository::DRAFTS === $msg['folder'] ) {
			MailRepository::delete_message( (int) $msg['id'] );
			return;
		}

		$current = MailRepository::get_folders( (int) $account['id'] );

		// Aus dem Papierkorb heraus löscht „Löschen" endgültig.
		if ( 'trash' === $role && isset( $current[ $msg['folder'] ] ) && 'trash' === $current[ $msg['folder'] ]['role'] ) {
			$this->delete_message( $account, $msg );
			return;
		}

		foreach ( $current as $f ) {
			if ( $f['role'] === $role ) {
				$this->move_message( $account, $msg, $f['name'] );
				return;
			}
		}

		// Kein Papierkorb-Ordner vorhanden: endgültig löschen.
		if ( 'trash' === $role ) {
			$this->delete_message( $account, $msg );
		}
	}

	/**
	 * Nachricht verschieben: lokal entfernen, am Server im Hintergrund umlagern.
	 * Im Zielordner erscheint sie beim nächsten Abgleich.
	 *
	 * @param array  $account Postfach.
	 * @param array  $msg     Nachricht.
	 * @param string $dest    Zielordner.
	 */
	public function move_message( array $account, array $msg, $dest ) {
		if ( ! empty( $msg['uid'] ) ) {
			Scheduler::enqueue_async( Scheduler::HOOK_MAIL_SERVER_OP, array( (int) $account['id'], $msg['folder'], (int) $msg['uid'], 'move', $dest ) );
		}

		AuditLog::record( 'mail_verschoben', 'mail_message', (int) $msg['id'], array( 'nach' => $dest ) );
		MailRepository::delete_message( (int) $msg['id'] );
	}

	/**
	 * Nachricht endgültig löschen.
	 *
	 * @param array $account Postfach.
	 * @param array $msg     Nachricht.
	 */
	protected function delete_message( array $account, array $msg ) {
		if ( ! empty( $msg['uid'] ) ) {
			Scheduler::enqueue_async( Scheduler::HOOK_MAIL_SERVER_OP, array( (int) $account['id'], $msg['folder'], (int) $msg['uid'], 'delete', '' ) );
		}

		AuditLog::record( 'mail_geloescht', 'mail_message', (int) $msg['id'], array( 'betreff' => $msg['subject'] ) );
		MailRepository::delete_message( (int) $msg['id'] );
	}

	/**
	 * Hintergrundjob: einen Befehl an den Mailserver schicken.
	 *
	 * @param int    $account_id Postfach.
	 * @param string $folder     Ordner der Nachricht.
	 * @param int    $uid        UID.
	 * @param string $op         seen, unseen, flag, unflag, move, delete.
	 * @param string $dest       Zielordner bei move.
	 */
	public function run_server_op( $account_id, $folder, $uid, $op, $dest = '' ) {
		$account = MailRepository::get_account( (int) $account_id );
		if ( ! $account ) {
			return;
		}

		try {
			$client = SyncService::connect( $account );
			$client->select( $folder );

			switch ( $op ) {
				case 'seen':
					$client->uid_store( (int) $uid, array( '\\Seen' ), true );
					break;
				case 'unseen':
					$client->uid_store( (int) $uid, array( '\\Seen' ), false );
					break;
				case 'flag':
					$client->uid_store( (int) $uid, array( '\\Flagged' ), true );
					break;
				case 'unflag':
					$client->uid_store( (int) $uid, array( '\\Flagged' ), false );
					break;
				case 'move':
					$client->uid_move( (int) $uid, (string) $dest );
					break;
				case 'delete':
					$client->uid_store( (int) $uid, array( '\\Deleted' ), true );
					$client->expunge( (int) $uid );
					break;
			}

			$client->logout();
		} catch ( \RuntimeException $e ) {
			MailRepository::set_sync_result( (int) $account_id, $e->getMessage() );
		}
	}

	/* --------------------------------------------------------------------
	 * Notizen, Anhänge, Abgleich
	 * ------------------------------------------------------------------ */

	/**
	 * Interne Notiz zu einer Mail (nur im ERP sichtbar).
	 */
	public function handle_note() {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$this->guard( 'lw16_mail_note_' . $id );

		$msg = MailRepository::get_message( $id );
		if ( ! $msg ) {
			wp_die( esc_html__( 'Nachricht nicht gefunden.', 'lindenweg16' ) );
		}
		$account = $this->account_for( (int) $msg['account_id'], 'read' );

		$note = isset( $_POST['note'] ) ? ComposeService::clean_text( wp_unslash( $_POST['note'] ), true ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== trim( $note ) ) {
			MailRepository::add_note( $id, get_current_user_id(), $note );
		}

		wp_safe_redirect( self::url( array( 'account' => (int) $account['id'], 'folder' => $msg['folder'], 'msg' => $id ) ) );
		exit;
	}

	/**
	 * Anhang herunterladen. Immer als Download, nie im Browser geöffnet.
	 */
	public function handle_attachment() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$this->guard( 'lw16_mail_att_' . $id );

		$att = MailRepository::get_attachment( $id );
		$msg = $att ? MailRepository::get_message( (int) $att['message_id'] ) : null;
		if ( ! $att || ! $msg ) {
			wp_die( esc_html__( 'Anhang nicht gefunden.', 'lindenweg16' ) );
		}

		$account = $this->account_for( (int) $msg['account_id'], 'read' );
		$path    = MailRepository::attachment_path( $att['path'] );
		if ( null === $path ) {
			wp_die( esc_html__( 'Die Datei des Anhangs fehlt.', 'lindenweg16' ) );
		}

		if ( (int) $msg['account_id'] !== (int) $account['id'] ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}

		$name = MimeParser::clean_filename( $att['filename'] );

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Disposition: attachment; filename=\"" . str_replace( array( '"', '\\' ), '_', remove_accents( $name ) ) . "\"; filename*=UTF-8''" . rawurlencode( $name ) );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Postfach jetzt abrufen (AJAX).
	 */
	public function ajax_sync() {
		$this->guard( 'lw16_mail', true );

		$account = $this->account_for( isset( $_POST['account'] ) ? (int) $_POST['account'] : 0, 'read', true ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( empty( $account['active'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Das Postfach ist deaktiviert.', 'lindenweg16' ) ) );
		}

		$result = ( new SyncService() )->sync_account(
			(int) $account['id'],
			array(
				'batch'  => 60,
				'budget' => 20,
			)
		);

		if ( '' !== $result['error'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		wp_send_json_success(
			array(
				'new'     => (int) $result['new'],
				'removed' => (int) $result['removed'],
				'more'    => (bool) $result['more'],
				'skipped' => (bool) $result['skipped'],
				'unread'  => MailRepository::unread_inbox_total( array( (int) $account['id'] ) ),
			)
		);
	}
}
