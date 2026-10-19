<?php
/**
 * ERP-Seite „Mailbox“: Postfach wählen, Ordner, Liste, Mail lesen, antworten.
 *
 * Alles Fachliche bleibt in `includes/Modules/Mailbox/` (Repository, Zugriff,
 * Bereinigung, Senden); diese Klasse ist die REST-Schicht der neuen
 * Oberfläche und prüft dieselben Dinge wie die alte Mailbox-Seite:
 * das ERP-Recht, das Menü-Recht der Mailbox und – bei jeder einzelnen
 * Anfrage – die Zugriffsstufe auf das betreffende Postfach (`MailboxAccess`).
 *
 * Fremde Mail-Inhalte gelten als nicht vertrauenswürdig: Klartext geht als
 * Text an die Oberfläche (dort nur über textContent), HTML nur als
 * bereinigtes Dokument für einen abgeschotteten iframe (`HtmlSanitizer`,
 * Sandbox ohne Skripte, externe Bilder erst nach ausdrücklichem Klick).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Mailbox\ComposeService;
use Lindenweg16\Modules\Mailbox\HtmlSanitizer;
use Lindenweg16\Modules\Mailbox\MailboxAccess;
use Lindenweg16\Modules\Mailbox\MailboxModule;
use Lindenweg16\Modules\Mailbox\MailboxView;
use Lindenweg16\Modules\Mailbox\MailRepository;
use Lindenweg16\Modules\Mailbox\OrderMailsBox;
use Lindenweg16\Modules\Mailbox\SyncService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen und Regeln der nativen Mailbox.
 */
class Postfach {

	const PER_PAGE = 30;

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$gate = self::gate( $api );
		$ns   = RestApi::NAMESPACE_V1;

		register_rest_route(
			$ns,
			'/erp/postfach',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'overview' ),
				'permission_callback' => $gate,
			)
		);

		register_rest_route(
			$ns,
			'/erp/postfach/liste',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_mails' ),
				'permission_callback' => $gate,
			)
		);

		register_rest_route(
			$ns,
			'/erp/postfach/mail/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'read_mail' ),
				'permission_callback' => $gate,
			)
		);

		register_rest_route(
			$ns,
			'/erp/postfach/mail/(?P<id>\d+)/aktion',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'act' ),
				'permission_callback' => $gate,
			)
		);

		register_rest_route(
			$ns,
			'/erp/postfach/mail/(?P<id>\d+)/notiz',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'note' ),
				'permission_callback' => $gate,
			)
		);

		register_rest_route(
			$ns,
			'/erp/postfach/abrufen',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'sync' ),
				'permission_callback' => $gate,
			)
		);

		register_rest_route(
			$ns,
			'/erp/postfach/schreiben',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'compose_values' ),
				'permission_callback' => $gate,
			)
		);

		register_rest_route(
			$ns,
			'/erp/postfach/senden',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'send' ),
				'permission_callback' => $gate,
			)
		);
	}

	/**
	 * Zugang: ERP-Recht und Menü-Recht der Mailbox, wie bei der alten Seite.
	 *
	 * @param RestApi $api Schnittstelle.
	 * @return callable
	 */
	protected static function gate( RestApi $api ) {
		$needs = $api->needs( array( 'verwaltung.mailbox' ) );

		return function () use ( $needs ) {
			return $needs() && MailboxAccess::can_open();
		};
	}

	/* --------------------------------------------------------------------
	 * Reine Regeln (ohne Datenbank, getestet)
	 * ------------------------------------------------------------------ */

	/**
	 * Welche Stufe braucht eine Aktion an einer Mail?
	 *
	 * Lesen und Markieren darf, wer lesen darf; verschieben, Spam und
	 * Löschen nur, wer auch senden darf. Unbekanntes ergibt leer.
	 *
	 * @param string $do Aktion.
	 * @return string read, send oder leer.
	 */
	public static function action_level( $do ) {
		if ( in_array( $do, array( 'read', 'unread', 'star', 'unstar', 'assign' ), true ) ) {
			return 'read';
		}

		if ( in_array( $do, array( 'trash', 'spam', 'notspam', 'move' ), true ) ) {
			return 'send';
		}

		return '';
	}

	/**
	 * Filter der Liste auf die bekannten Werte begrenzen.
	 *
	 * @param string $filter Eingabe.
	 * @return string
	 */
	public static function clean_filter( $filter ) {
		return in_array( $filter, array( 'all', 'unread', 'starred', 'mine' ), true ) ? $filter : 'all';
	}

	/**
	 * Ordnerliste für die Anzeige: Reihenfolge, Bezeichnung, Zähler.
	 *
	 * Entwürfe führt die Mailbox lokal und nicht am Server; die Zeile dafür
	 * kommt immer dazu. Ungelesen-Zähler zeigen nur Ordner, in denen
	 * „ungelesen“ etwas bedeutet.
	 *
	 * @param array $folders Name => Zeile mit name und role.
	 * @param array $counts  Ordner => {total, unread}.
	 * @return array Liste aus {name, role, label, unread, total}.
	 */
	public static function folder_rows( array $folders, array $counts ) {
		$order = array(
			'inbox'   => 0,
			'drafts'  => 1,
			'sent'    => 2,
			'other'   => 3,
			'archive' => 4,
			'spam'    => 5,
			'trash'   => 6,
		);

		$rows = array();
		foreach ( $folders as $f ) {
			if ( 'drafts' === $f['role'] ) {
				continue;
			}
			$rows[] = array(
				'name' => $f['name'],
				'role' => $f['role'],
			);
		}
		$rows[] = array(
			'name' => MailRepository::DRAFTS,
			'role' => 'drafts',
		);

		foreach ( $rows as $i => $r ) {
			$c                   = isset( $counts[ $r['name'] ] ) ? $counts[ $r['name'] ] : array( 'total' => 0, 'unread' => 0 );
			$quiet               = in_array( $r['role'], array( 'trash', 'spam', 'sent', 'drafts' ), true );
			$rows[ $i ]['label'] = MailboxView::folder_label( 'drafts' === $r['role'] ? '' : $r['name'], $r['role'] );
			$rows[ $i ]['unread'] = $quiet ? 0 : (int) $c['unread'];
			$rows[ $i ]['total']  = (int) $c['total'];
		}

		usort(
			$rows,
			static function ( $a, $b ) use ( $order ) {
				$ra = isset( $order[ $a['role'] ] ) ? $order[ $a['role'] ] : 3;
				$rb = isset( $order[ $b['role'] ] ) ? $order[ $b['role'] ] : 3;

				return $ra === $rb ? strcasecmp( $a['label'], $b['label'] ) : $ra - $rb;
			}
		);

		return $rows;
	}

	/**
	 * Verweist das HTML der Mail auf Bilder von fremden Servern?
	 *
	 * Solche Bilder verraten dem Absender, dass die Mail gelesen wurde; sie
	 * werden erst nach Klick geladen.
	 *
	 * @param string $html HTML der Mail.
	 * @return bool
	 */
	public static function has_remote_content( $html ) {
		$html = (string) $html;

		return (bool) preg_match( '#<img[^>]+src\s*=\s*["\']?https?://#i', $html ) || (bool) preg_match( '#url\(\s*["\']?https?://#i', $html );
	}

	/**
	 * Zeitpunkt aus der Datenbank (UTC) als Unixzeit; 0, wenn leer oder ungültig.
	 *
	 * @param string $mysql_utc Datum.
	 * @return int
	 */
	public static function to_ts( $mysql_utc ) {
		if ( empty( $mysql_utc ) ) {
			return 0;
		}

		$ts = strtotime( $mysql_utc . ' UTC' );

		return $ts ? (int) $ts : 0;
	}

	/**
	 * Vorbelegung zum Antworten oder Weiterleiten.
	 *
	 * @param array  $ref       Ursprungsmail.
	 * @param string $mode      reply, replyall oder forward.
	 * @param string $own_email Eigene Adresse (wird bei „Allen antworten“ nicht angeschrieben).
	 * @param string $date      Fertig formatiertes Datum der Ursprungsmail.
	 * @return array {to, cc, subject, body, reply_to_id}
	 */
	public static function reply_values( array $ref, $mode, $own_email, $date ) {
		$vals = array(
			'to'          => '',
			'cc'          => '',
			'subject'     => '',
			'body'        => '',
			'reply_to_id' => 0,
		);

		if ( 'forward' === $mode ) {
			$who             = '' !== trim( (string) $ref['from_name'] ) ? $ref['from_name'] . ' <' . $ref['from_email'] . '>' : $ref['from_email'];
			$vals['subject'] = ComposeService::prefix_subject( $ref['subject'], 'Fwd' );
			$vals['body']    = "\n\n---------- " . __( 'Weitergeleitete Nachricht', 'lindenweg16' ) . " ----------\n"
				. __( 'Von', 'lindenweg16' ) . ': ' . $who . "\n"
				. __( 'Datum', 'lindenweg16' ) . ': ' . $date . "\n"
				. __( 'Betreff', 'lindenweg16' ) . ': ' . $ref['subject'] . "\n"
				. __( 'An', 'lindenweg16' ) . ': ' . MailboxView::addr_text( $ref['to_json'] ) . "\n\n"
				. $ref['body_text'];

			return $vals;
		}

		$rec                 = ComposeService::reply_recipients( $ref, 'replyall' === $mode, $own_email, '' );
		$vals['subject']     = ComposeService::prefix_subject( $ref['subject'], 'Re' );
		$vals['to']          = $rec['to'];
		$vals['cc']          = $rec['cc'];
		$vals['body']        = "\n\n" . ComposeService::quote( $ref, $date );
		$vals['reply_to_id'] = (int) $ref['id'];

		return $vals;
	}

	/**
	 * Klartext auf eine Höchstlänge kürzen (ganze Zeichen, nicht Bytes).
	 *
	 * @param string $text Text.
	 * @param int    $max  Höchstzahl Zeichen.
	 * @return string
	 */
	public static function clip( $text, $max ) {
		$text = (string) $text;

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $max ) {
			return mb_substr( $text, 0, $max, 'UTF-8' );
		}

		return $text;
	}

	/* --------------------------------------------------------------------
	 * Hilfen mit Datenbank
	 * ------------------------------------------------------------------ */

	/**
	 * Fehlerantwort mit lesbarem Text.
	 *
	 * @param string $message Text.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}

	/**
	 * Postfach laden und die Zugriffsstufe prüfen – bei jeder Anfrage.
	 *
	 * @param int    $account_id Postfach.
	 * @param string $needed     read oder send.
	 * @return array|WP_REST_Response Das Postfach (mit `level`) oder die Fehlerantwort.
	 */
	protected static function account_for( $account_id, $needed ) {
		$account = MailRepository::get_account( (int) $account_id );
		$level   = $account ? MailboxAccess::level( $account, MailRepository::get_shares( (int) $account['id'] ), get_current_user_id(), MailboxAccess::is_manager() ) : 'none';

		if ( ! $account || ! MailboxAccess::allows( $level, $needed ) ) {
			return self::fail(
				'send' === $needed ? __( 'Dafür darfst du in diesem Postfach nicht schreiben, verschieben oder löschen.', 'lindenweg16' ) : __( 'Auf dieses Postfach hast du keinen Zugriff.', 'lindenweg16' ),
				403
			);
		}

		$account['level'] = $level;

		return $account;
	}

	/**
	 * Nachricht laden, die zu einem Postfach des Nutzers gehört.
	 *
	 * @param int    $id     Nachricht.
	 * @param string $needed Stufe.
	 * @return array|WP_REST_Response {account, msg} oder Fehlerantwort.
	 */
	protected static function message_for( $id, $needed ) {
		$msg = MailRepository::get_message( (int) $id );

		if ( ! $msg ) {
			return self::fail( __( 'Diese Nachricht gibt es nicht mehr. Vielleicht wurde sie verschoben oder gelöscht.', 'lindenweg16' ), 404 );
		}

		$account = self::account_for( (int) $msg['account_id'], $needed );

		if ( $account instanceof WP_REST_Response ) {
			return $account;
		}

		return array(
			'account' => $account,
			'msg'     => $msg,
		);
	}

	/**
	 * Eintrag eines Postfachs für die Oberfläche – ohne Zugangsdaten.
	 *
	 * @param array $a Postfach.
	 * @return array
	 */
	protected static function account_out( array $a ) {
		return array(
			'id'         => (int) $a['id'],
			'label'      => (string) $a['label'],
			'email'      => (string) $a['email'],
			'kind'       => (string) $a['kind'],
			'level'      => (string) $a['level'],
			'can_send'   => MailboxAccess::allows( $a['level'], 'send' ),
			'active'     => ! empty( $a['active'] ),
			'unread'     => MailRepository::unread_inbox_total( array( (int) $a['id'] ) ),
			'last_sync'  => ! empty( $a['last_sync'] ) ? self::to_ts( get_gmt_from_date( $a['last_sync'] ) ) : 0,
			'last_error' => (string) $a['last_error'],
		);
	}

	/**
	 * Name oder Adresse des Absenders.
	 *
	 * @param array $row Zeile.
	 * @return string
	 */
	protected static function who( array $row ) {
		return '' !== trim( (string) $row['from_name'] ) ? (string) $row['from_name'] : (string) $row['from_email'];
	}

	/* --------------------------------------------------------------------
	 * Übersicht und Liste
	 * ------------------------------------------------------------------ */

	/**
	 * Postfächer der Person und Ordner des gewählten Postfachs.
	 *
	 * @param WP_REST_Request $request Anfrage (account).
	 * @return WP_REST_Response
	 */
	public static function overview( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$manager  = MailboxAccess::is_manager();
		$accounts = MailRepository::accounts_for_user( $user_id, $manager );

		if ( ! $accounts ) {
			return new WP_REST_Response(
				array(
					'accounts' => array(),
					'manager'  => $manager,
				)
			);
		}

		// Vorgabe: das eigene Postfach, sonst das erste.
		$default = $accounts[0];
		foreach ( $accounts as $a ) {
			if ( (int) $a['owner_user_id'] === $user_id ) {
				$default = $a;
				break;
			}
		}

		$by_id   = array_column( $accounts, null, 'id' );
		$wanted  = (int) $request->get_param( 'account' );
		$account = isset( $by_id[ $wanted ] ) ? $by_id[ $wanted ] : $default;

		$folders = MailRepository::get_folders( (int) $account['id'] );
		$inbox   = '';
		foreach ( $folders as $f ) {
			if ( 'inbox' === $f['role'] ) {
				$inbox = $f['name'];
				break;
			}
		}
		if ( '' === $inbox && $folders ) {
			$inbox = (string) array_key_first( $folders );
		}

		return new WP_REST_Response(
			array(
				'accounts' => array_map( array( __CLASS__, 'account_out' ), $accounts ),
				'account'  => (int) $account['id'],
				'folders'  => self::folder_rows( $folders, MailRepository::folder_counts( (int) $account['id'] ) ),
				'inbox'    => $inbox,
				'manager'  => $manager,
			)
		);
	}

	/**
	 * Mailliste eines Ordners.
	 *
	 * @param WP_REST_Request $request Anfrage (account, folder, s, filter, paged).
	 * @return WP_REST_Response
	 */
	public static function list_mails( WP_REST_Request $request ) {
		$account = self::account_for( (int) $request->get_param( 'account' ), 'read' );

		if ( $account instanceof WP_REST_Response ) {
			return $account;
		}

		$folders = MailRepository::get_folders( (int) $account['id'] );
		$folder  = (string) $request->get_param( 'folder' );

		// Nur bekannte Ordner (oder die lokalen Entwürfe).
		if ( MailRepository::DRAFTS !== $folder && ! isset( $folders[ $folder ] ) ) {
			return self::fail( __( 'Diesen Ordner gibt es in diesem Postfach nicht.', 'lindenweg16' ), 404 );
		}

		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$filter = self::clean_filter( sanitize_key( (string) $request->get_param( 'filter' ) ) );
		$paged  = max( 1, (int) $request->get_param( 'paged' ) );
		$list   = MailRepository::list_messages(
			(int) $account['id'],
			$folder,
			array(
				'page'     => $paged,
				'per_page' => self::PER_PAGE,
				'search'   => $search,
				'filter'   => $filter,
				'user_id'  => get_current_user_id(),
			)
		);

		$drafts = MailRepository::DRAFTS === $folder;
		$is_out = $drafts || ( isset( $folders[ $folder ] ) && 'sent' === $folders[ $folder ]['role'] );
		$rows   = array();

		foreach ( $list['rows'] as $row ) {
			if ( $drafts ) {
				$to  = (array) json_decode( (string) $row['to_json'], true );
				$who = isset( $to['raw'] ) && '' !== $to['raw'] ? (string) $to['raw'] : __( '(kein Empfänger)', 'lindenweg16' );
			} else {
				$who = $is_out ? MailboxView::addr_text( $row['to_json'] ) : self::who( $row );
			}

			$rows[] = array(
				'id'       => (int) $row['id'],
				'who'      => $who,
				'subject'  => '' !== $row['subject'] ? (string) $row['subject'] : __( '(ohne Betreff)', 'lindenweg16' ),
				'snippet'  => (string) $row['snippet'],
				'ts'       => self::to_ts( $row['sent_at'] ),
				'unread'   => ! $row['is_read'],
				'starred'  => (bool) $row['is_starred'],
				'attach'   => (bool) $row['has_attachments'],
				'answered' => (bool) $row['is_answered'],
				'draft'    => $drafts,
			);
		}

		return new WP_REST_Response(
			array(
				'rows'     => $rows,
				'total'    => (int) $list['total'],
				'pages'    => (int) max( 1, ceil( $list['total'] / self::PER_PAGE ) ),
				'paged'    => $paged,
				'filter'   => $filter,
				'search'   => $search,
				'counts'   => self::folder_rows( $folders, MailRepository::folder_counts( (int) $account['id'] ) ),
				'unread'   => MailRepository::unread_inbox_total( array( (int) $account['id'] ) ),
				'can_send' => MailboxAccess::allows( $account['level'], 'send' ),
			)
		);
	}

	/* --------------------------------------------------------------------
	 * Mail lesen
	 * ------------------------------------------------------------------ */

	/**
	 * Eine Mail zum Lesen. Markiert sie als gelesen und protokolliert den
	 * Zugriff auf fremde persönliche Postfächer – wie die alte Seite.
	 *
	 * @param WP_REST_Request $request Anfrage (id, images).
	 * @return WP_REST_Response
	 */
	public static function read_mail( WP_REST_Request $request ) {
		$found = self::message_for( (int) $request['id'], 'read' );

		if ( $found instanceof WP_REST_Response ) {
			return $found;
		}

		$account = $found['account'];
		$m       = $found['msg'];
		$id      = (int) $m['id'];
		$images  = ! empty( $request->get_param( 'images' ) );

		// Entwürfe öffnen sich zum Schreiben, nicht zum Lesen.
		if ( MailRepository::DRAFTS === $m['folder'] ) {
			return new WP_REST_Response(
				array(
					'draft'   => true,
					'id'      => $id,
					'account' => (int) $account['id'],
				)
			);
		}

		// Erneutes Laden desselben Inhalts (Bilder zulassen) ist kein neuer Zugriff.
		if ( ! $images && 'personal' === $account['kind'] && (int) $account['owner_user_id'] !== get_current_user_id() ) {
			AuditLog::record( 'mail_gelesen', 'mail_message', $id, array( 'postfach' => $account['email'] ) );
		}

		if ( ! $m['is_read'] ) {
			MailboxModule::mark_read( $account, $m );
			$m['is_read'] = 1;
		}

		$attachments = MailRepository::attachments( $id );
		$folders     = MailRepository::get_folders( (int) $account['id'] );
		$role        = isset( $folders[ $m['folder'] ] ) ? $folders[ $m['folder'] ]['role'] : '';

		return new WP_REST_Response(
			array(
				'id'          => $id,
				'account'     => (int) $account['id'],
				'folder'      => (string) $m['folder'],
				'role'        => $role,
				'subject'     => '' !== $m['subject'] ? (string) $m['subject'] : __( '(ohne Betreff)', 'lindenweg16' ),
				'from'        => '' !== trim( (string) $m['from_name'] ) ? $m['from_name'] . ' <' . $m['from_email'] . '>' : (string) $m['from_email'],
				'to'          => MailboxView::addr_text( $m['to_json'] ),
				'cc'          => MailboxView::addr_text( $m['cc_json'] ),
				'ts'          => self::to_ts( $m['sent_at'] ),
				'starred'     => (bool) $m['is_starred'],
				'body'        => self::body( $m, $attachments, $images ),
				'attachments' => self::attachments_out( $attachments ),
				'assigned'    => (int) $m['assigned_user_id'],
				'users'       => self::assign_users( $account ),
				'customer'    => OrderMailsBox::customer_info( (string) $m['from_email'], (string) $m['subject'] ),
				'thread'      => self::thread_out( $account, $m ),
				'notes'       => self::notes_out( $id ),
				'can_send'    => MailboxAccess::allows( $account['level'], 'send' ),
				'targets'     => self::move_targets( $folders, (string) $m['folder'] ),
			)
		);
	}

	/**
	 * Inhalt der Mail: Klartext oder bereinigtes HTML für den abgeschotteten Rahmen.
	 *
	 * @param array $m           Nachricht.
	 * @param array $attachments Anhänge (für eingebettete Bilder).
	 * @param bool  $images      Fremde Bilder zulassen.
	 * @return array {kind: text|html, text, frame, remote, images}
	 */
	protected static function body( array $m, array $attachments, $images ) {
		$html = (string) $m['body_html'];

		if ( '' === trim( $html ) ) {
			return array(
				'kind'   => 'text',
				'text'   => self::clip( (string) $m['body_text'], 200000 ),
				'remote' => false,
			);
		}

		// Eingebettete Bilder (cid:) aus den gespeicherten Anhängen, wie in der alten Ansicht.
		$cid = array();
		foreach ( $attachments as $a ) {
			if ( '' === (string) $a['content_id'] || 0 !== strpos( (string) $a['mime'], 'image/' ) || (int) $a['size'] > 2097152 ) {
				continue;
			}
			$path = MailRepository::attachment_path( $a['path'] );
			if ( $path && preg_match( '#^image/(png|jpe?g|gif|webp|bmp)$#', $a['mime'] ) ) {
				$cid[ $a['content_id'] ] = 'data:' . $a['mime'] . ';base64,' . base64_encode( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions
			}
		}

		return array(
			'kind'   => 'html',
			'frame'  => HtmlSanitizer::document( $html, $images, $cid ),
			'remote' => self::has_remote_content( $html ),
			'images' => $images,
		);
	}

	/**
	 * Anhänge mit Download-Adresse. Der Download selbst läuft über den
	 * bestehenden Handler der Mailbox (Nonce, Postfach-Prüfung, nur als Download).
	 *
	 * @param array $attachments Anhänge.
	 * @return array
	 */
	protected static function attachments_out( array $attachments ) {
		$out = array();

		foreach ( $attachments as $a ) {
			if ( $a['inline'] && '' !== (string) $a['content_id'] ) {
				continue;
			}

			$out[] = array(
				'id'   => (int) $a['id'],
				'name' => (string) $a['filename'],
				'size' => size_format( (int) $a['size'] ),
				'url'  => wp_nonce_url( add_query_arg( array( 'action' => 'lw16_mail_attachment', 'id' => (int) $a['id'] ), admin_url( 'admin-post.php' ) ), 'lw16_mail_att_' . (int) $a['id'] ),
			);
		}

		return $out;
	}

	/**
	 * Ordner, in die die Mail verschoben werden kann.
	 *
	 * @param array  $folders Ordner des Postfachs.
	 * @param string $current Aktueller Ordner.
	 * @return array Liste aus {name, label}.
	 */
	protected static function move_targets( array $folders, $current ) {
		$out = array();

		foreach ( $folders as $f ) {
			if ( $f['name'] !== $current && 'drafts' !== $f['role'] ) {
				$out[] = array(
					'name'  => (string) $f['name'],
					'label' => MailboxView::folder_label( $f['name'], $f['role'] ),
				);
			}
		}

		return $out;
	}

	/**
	 * Personen, die das Postfach sehen dürfen (für „Zugewiesen an“).
	 *
	 * @param array $account Postfach.
	 * @return array Liste aus {id, name}; leer, wenn die Zuweisung nichts bringt.
	 */
	protected static function assign_users( array $account ) {
		$ids = array_keys( MailRepository::get_shares( (int) $account['id'] ) );
		if ( ! empty( $account['owner_user_id'] ) ) {
			$ids[] = (int) $account['owner_user_id'];
		}
		$ids = array_unique( array_map( 'intval', $ids ) );

		$out = array();
		foreach ( $ids as $uid ) {
			$u = get_userdata( $uid );
			if ( $u ) {
				$out[ $uid ] = $u->display_name;
			}
		}
		asort( $out );

		if ( count( $out ) <= 1 && 'shared' !== $account['kind'] ) {
			return array();
		}

		$list = array();
		foreach ( $out as $uid => $name ) {
			$list[] = array(
				'id'   => (int) $uid,
				'name' => (string) $name,
			);
		}

		return $list;
	}

	/**
	 * Weitere Mails desselben Verlaufs.
	 *
	 * @param array $account Postfach.
	 * @param array $m       Nachricht.
	 * @return array
	 */
	protected static function thread_out( array $account, array $m ) {
		$out = array();

		foreach ( MailRepository::thread_messages( (int) $account['id'], (string) $m['thread_key'], (int) $m['id'] ) as $t ) {
			$out[] = array(
				'id'      => (int) $t['id'],
				'ts'      => self::to_ts( $t['sent_at'] ),
				'who'     => self::who( $t ),
				'snippet' => wp_trim_words( (string) $t['snippet'], 12 ),
			);
		}

		return $out;
	}

	/**
	 * Interne Notizen einer Mail.
	 *
	 * @param int $id Nachricht.
	 * @return array
	 */
	protected static function notes_out( $id ) {
		$out = array();

		foreach ( MailRepository::notes( (int) $id ) as $n ) {
			$u     = get_userdata( (int) $n['user_id'] );
			$out[] = array(
				'who'  => $u ? $u->display_name : __( 'Unbekannt', 'lindenweg16' ),
				'ts'   => self::to_ts( get_gmt_from_date( $n['created_at'] ) ),
				'text' => (string) $n['note'],
			);
		}

		return $out;
	}

	/* --------------------------------------------------------------------
	 * Aktionen
	 * ------------------------------------------------------------------ */

	/**
	 * Aktion an einer Mail: gelesen, ungelesen, Stern, zuweisen, Spam, Papierkorb, verschieben.
	 *
	 * Dieselben Stufen und dieselben Abläufe (inklusive Protokoll und
	 * Hintergrundauftrag an den Mailserver) wie in der alten Mailbox.
	 *
	 * @param WP_REST_Request $request Anfrage (id, do, assign_to, dest).
	 * @return WP_REST_Response
	 */
	public static function act( WP_REST_Request $request ) {
		$do    = sanitize_key( (string) $request->get_param( 'do' ) );
		$level = self::action_level( $do );

		if ( '' === $level ) {
			return self::fail( __( 'Diese Aktion kennt die Mailbox nicht.', 'lindenweg16' ) );
		}

		$found = self::message_for( (int) $request['id'], $level );

		if ( $found instanceof WP_REST_Response ) {
			return $found;
		}

		$account = $found['account'];
		$msg     = $found['msg'];
		$id      = (int) $msg['id'];
		$module  = new MailboxModule();
		$gone    = false;

		switch ( $do ) {
			case 'read':
			case 'unread':
				$module->set_flag( $account, $msg, 'seen', 'read' === $do );
				break;

			case 'star':
			case 'unstar':
				$module->set_flag( $account, $msg, 'flag', 'star' === $do );
				break;

			case 'assign':
				$user = (int) $request->get_param( 'assign_to' );
				// Nur an jemanden, der das Postfach sehen darf.
				if ( $user && ! MailboxAccess::allows( MailboxAccess::level( $account, MailRepository::get_shares( (int) $account['id'] ), $user, (bool) user_can( $user, LW16_CAPABILITY ) || (bool) user_can( $user, MailboxAccess::CAP_MANAGE ) ), 'read' ) ) {
					return self::fail( __( 'Diese Person hat auf das Postfach keinen Zugriff und kann die Mail deshalb nicht bekommen.', 'lindenweg16' ) );
				}
				MailRepository::update_message( $id, array( 'assigned_user_id' => $user ? $user : null ) );
				AuditLog::record( 'mail_zugewiesen', 'mail_message', $id, array( 'an' => $user ) );
				break;

			case 'trash':
				$module->move_to_role( $account, $msg, 'trash' );
				$gone = true;
				break;

			case 'spam':
				$module->move_to_role( $account, $msg, 'spam' );
				$gone = true;
				break;

			case 'notspam':
				$module->move_to_role( $account, $msg, 'inbox' );
				$gone = true;
				break;

			case 'move':
				$dest    = (string) $request->get_param( 'dest' ); // Wird gegen die bekannten Ordner geprüft.
				$folders = MailRepository::get_folders( (int) $account['id'] );
				if ( ! isset( $folders[ $dest ] ) || $dest === $msg['folder'] ) {
					return self::fail( __( 'Bitte einen anderen Ordner dieses Postfachs wählen.', 'lindenweg16' ) );
				}
				$module->move_message( $account, $msg, $dest );
				$gone = true;
				break;
		}

		$messages = array(
			'read'    => __( 'Als gelesen markiert.', 'lindenweg16' ),
			'unread'  => __( 'Als ungelesen markiert.', 'lindenweg16' ),
			'star'    => __( 'Markiert.', 'lindenweg16' ),
			'unstar'  => __( 'Markierung entfernt.', 'lindenweg16' ),
			'assign'  => __( 'Zuweisung gespeichert.', 'lindenweg16' ),
			'trash'   => __( 'Mail gelöscht.', 'lindenweg16' ),
			'spam'    => __( 'Als Spam verschoben.', 'lindenweg16' ),
			'notspam' => __( 'In den Posteingang zurückgelegt.', 'lindenweg16' ),
			'move'    => __( 'Mail verschoben. Im neuen Ordner erscheint sie beim nächsten Abruf.', 'lindenweg16' ),
		);

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => $messages[ $do ],
				'gone'    => $gone,
			)
		);
	}

	/**
	 * Interne Notiz zu einer Mail (nur im ERP sichtbar).
	 *
	 * @param WP_REST_Request $request Anfrage (id, note).
	 * @return WP_REST_Response
	 */
	public static function note( WP_REST_Request $request ) {
		$found = self::message_for( (int) $request['id'], 'read' );

		if ( $found instanceof WP_REST_Response ) {
			return $found;
		}

		$note = ComposeService::clean_text( (string) $request->get_param( 'note' ), true );

		if ( '' === trim( $note ) ) {
			return self::fail( __( 'Bitte zuerst die Notiz schreiben.', 'lindenweg16' ) );
		}

		MailRepository::add_note( (int) $found['msg']['id'], get_current_user_id(), $note );

		return new WP_REST_Response(
			array(
				'ok'    => true,
				'notes' => self::notes_out( (int) $found['msg']['id'] ),
			)
		);
	}

	/**
	 * Postfach jetzt abrufen.
	 *
	 * @param WP_REST_Request $request Anfrage (account).
	 * @return WP_REST_Response
	 */
	public static function sync( WP_REST_Request $request ) {
		$account = self::account_for( (int) $request->get_param( 'account' ), 'read' );

		if ( $account instanceof WP_REST_Response ) {
			return $account;
		}

		if ( empty( $account['active'] ) ) {
			return self::fail( __( 'Das Postfach ist deaktiviert und wird nicht abgerufen.', 'lindenweg16' ) );
		}

		$result = ( new SyncService() )->sync_account(
			(int) $account['id'],
			array(
				'batch'  => 60,
				'budget' => 20,
			)
		);

		if ( '' !== $result['error'] ) {
			return self::fail( $result['error'] );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'new'     => (int) $result['new'],
				'removed' => (int) $result['removed'],
				'more'    => (bool) $result['more'],
				'skipped' => (bool) $result['skipped'],
				'unread'  => MailRepository::unread_inbox_total( array( (int) $account['id'] ) ),
			)
		);
	}

	/* --------------------------------------------------------------------
	 * Schreiben
	 * ------------------------------------------------------------------ */

	/**
	 * Vorbelegung des Schreibfensters: neue Mail, Antwort, Weiterleitung, Entwurf.
	 *
	 * @param WP_REST_Request $request Anfrage (account, mode, ref).
	 * @return WP_REST_Response
	 */
	public static function compose_values( WP_REST_Request $request ) {
		$account = self::account_for( (int) $request->get_param( 'account' ), 'send' );

		if ( $account instanceof WP_REST_Response ) {
			return $account;
		}

		$mode   = sanitize_key( (string) $request->get_param( 'mode' ) );
		$ref_id = (int) $request->get_param( 'ref' );
		$ref    = $ref_id ? MailRepository::get_message( $ref_id ) : null;

		// Das Zitat gehört zu einer Mail dieses Postfachs – sonst ließe sich über die ID fremder Inhalt abrufen.
		if ( $ref && (int) $ref['account_id'] !== (int) $account['id'] ) {
			$ref = null;
		}

		$vals = array(
			'to'          => '',
			'cc'          => '',
			'bcc'         => '',
			'subject'     => '',
			'body'        => '',
			'reply_to_id' => 0,
			'draft_id'    => 0,
		);

		if ( 'draft' === $mode && $ref && MailRepository::DRAFTS === $ref['folder'] ) {
			$to   = (array) json_decode( (string) $ref['to_json'], true );
			$cc   = (array) json_decode( (string) $ref['cc_json'], true );
			$vals = array_merge(
				$vals,
				array(
					'to'          => isset( $to['raw'] ) ? (string) $to['raw'] : '',
					'cc'          => isset( $cc['cc'] ) ? (string) $cc['cc'] : '',
					'bcc'         => isset( $cc['bcc'] ) ? (string) $cc['bcc'] : '',
					'subject'     => (string) $ref['subject'],
					'body'        => (string) $ref['body_text'],
					'reply_to_id' => (int) $ref['in_reply_to'],
					'draft_id'    => (int) $ref['id'],
				)
			);
		} elseif ( $ref && in_array( $mode, array( 'reply', 'replyall', 'forward' ), true ) && MailRepository::DRAFTS !== $ref['folder'] ) {
			$vals = array_merge( $vals, self::reply_values( $ref, $mode, (string) $account['email'], MailboxView::fmt_date( $ref['sent_at'], true ) ) );
		}

		$senders = array();
		foreach ( MailRepository::accounts_for_user( get_current_user_id(), MailboxAccess::is_manager() ) as $a ) {
			if ( MailboxAccess::allows( $a['level'], 'send' ) && ! empty( $a['active'] ) ) {
				$senders[] = array(
					'id'    => (int) $a['id'],
					'label' => (string) $a['label'],
					'email' => (string) $a['email'],
				);
			}
		}

		$user = wp_get_current_user();

		return new WP_REST_Response(
			array(
				'values'    => $vals,
				'senders'   => $senders,
				'account'   => (int) $account['id'],
				'signature' => ComposeService::render_signature( (string) $account['signature'], $user->display_name ),
			)
		);
	}

	/**
	 * Mail senden oder Entwurf speichern.
	 *
	 * Wie `MailboxModule::handle_send`: Schlägt das Senden fehl, bleibt der
	 * Text als Entwurf erhalten. Anhänge gibt es hier nicht – die bleiben der
	 * klassischen Mailbox vorbehalten.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function send( WP_REST_Request $request ) {
		$account = self::account_for( (int) $request->get_param( 'account' ), 'send' );

		if ( $account instanceof WP_REST_Response ) {
			return $account;
		}

		$data = array(
			'to'          => ComposeService::clean_text( (string) $request->get_param( 'to' ) ),
			'cc'          => ComposeService::clean_text( (string) $request->get_param( 'cc' ) ),
			'bcc'         => ComposeService::clean_text( (string) $request->get_param( 'bcc' ) ),
			'subject'     => ComposeService::clean_text( (string) $request->get_param( 'subject' ) ),
			'body'        => ComposeService::clean_text( (string) $request->get_param( 'body' ), true ),
			'reply_to_id' => (int) $request->get_param( 'reply_to_id' ),
			'draft_id'    => (int) $request->get_param( 'draft_id' ),
		);

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

		if ( $request->get_param( 'save_draft' ) ) {
			$id = ComposeService::save_draft( (int) $account['id'], get_current_user_id(), $data, $data['draft_id'] );

			return new WP_REST_Response(
				array(
					'ok'       => true,
					'draft_id' => (int) $id,
					'message'  => __( 'Entwurf gespeichert.', 'lindenweg16' ),
				)
			);
		}

		$data['attachments'] = array();

		try {
			$result = ComposeService::send( $account, get_current_user_id(), $data );
		} catch ( \RuntimeException $e ) {
			// Nichts geht verloren: Der Text bleibt als Entwurf erhalten.
			$id = ComposeService::save_draft( (int) $account['id'], get_current_user_id(), $data, $data['draft_id'] );

			return new WP_REST_Response(
				array(
					'ok'       => false,
					'draft_id' => (int) $id,
					'message'  => $e->getMessage() . ' ' . __( 'Dein Text ist als Entwurf gespeichert.', 'lindenweg16' ),
				),
				422
			);
		}

		$sent = '';
		foreach ( MailRepository::get_folders( (int) $account['id'] ) as $f ) {
			if ( 'sent' === $f['role'] ) {
				$sent = $f['name'];
				break;
			}
		}

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'message'  => $result['warnings'] ? implode( ' ', $result['warnings'] ) : __( 'Mail gesendet.', 'lindenweg16' ),
				'warnings' => (bool) $result['warnings'],
				'sent'     => $sent,
			)
		);
	}
}
