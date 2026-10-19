<?php
/**
 * Datenzugriff der Mailbox.
 *
 * Alle Abfragen laufen über `$wpdb` mit prepared statements.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Installer;

/**
 * Postfächer, Ordner, Nachrichten, Anhänge, Notizen.
 */
class MailRepository {

	/**
	 * Entwürfe liegen lokal unter diesem Ordnernamen.
	 */
	const DRAFTS = '__drafts';

	/**
	 * Tabellenname.
	 *
	 * @param string $name accounts, shares, folders, messages, attachments, notes.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'lw16_mail_' . $name;
	}

	/* --------------------------------------------------------------------
	 * Postfächer
	 * ------------------------------------------------------------------ */

	/**
	 * Ein Postfach.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get_account( $id ) {
		global $wpdb;
		$t   = self::table( 'accounts' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $row ? $row : null;
	}

	/**
	 * Alle Postfächer.
	 *
	 * @return array
	 */
	public static function all_accounts() {
		global $wpdb;
		$t = self::table( 'accounts' );

		return (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY kind ASC, label ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Postfächer, die ein Nutzer sehen darf – mit seiner Zugriffsstufe.
	 *
	 * @param int  $user_id    Nutzer.
	 * @param bool $is_manager Verwalter sehen alle.
	 * @return array Postfächer, je mit Schlüssel `level`.
	 */
	public static function accounts_for_user( $user_id, $is_manager ) {
		$out    = array();
		$shares = self::all_shares();

		foreach ( self::all_accounts() as $account ) {
			$level = MailboxAccess::level( $account, isset( $shares[ (int) $account['id'] ] ) ? $shares[ (int) $account['id'] ] : array(), (int) $user_id, $is_manager );
			if ( 'none' !== $level ) {
				$account['level'] = $level;
				$out[]            = $account;
			}
		}

		return $out;
	}

	/**
	 * Postfach anlegen oder ändern.
	 *
	 * @param array    $data Spalten (nur bekannte werden übernommen).
	 * @param int|null $id   ID beim Ändern.
	 * @return int ID, 0 bei Fehler.
	 */
	public static function save_account( array $data, $id = null ) {
		global $wpdb;

		$allowed = array(
			'label',
			'kind',
			'owner_user_id',
			'email',
			'from_name',
			'username',
			'password_enc',
			'imap_host',
			'imap_port',
			'imap_secure',
			'smtp_host',
			'smtp_port',
			'smtp_secure',
			'signature',
			'active',
			'last_sync',
			'last_error',
		);
		$row     = array_intersect_key( $data, array_flip( $allowed ) );

		if ( $id ) {
			$wpdb->update( self::table( 'accounts' ), $row, array( 'id' => (int) $id ) );
			return (int) $id;
		}

		$row['created_at'] = current_time( 'mysql' );
		$wpdb->insert( self::table( 'accounts' ), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Postfach samt aller Nachrichten, Anhänge und Dateien löschen.
	 *
	 * @param int $id ID.
	 */
	public static function delete_account( $id ) {
		global $wpdb;
		$id = (int) $id;

		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table( 'messages' ) . ' WHERE account_id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $ids as $mid ) {
			self::delete_message( (int) $mid );
		}

		foreach ( array( 'folders', 'shares' ) as $name ) {
			$wpdb->delete( self::table( $name ), array( 'account_id' => $id ) );
		}
		$wpdb->delete( self::table( 'accounts' ), array( 'id' => $id ) );

		$dir = self::attachment_dir( $id );
		if ( is_dir( $dir ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Ergebnis des letzten Abgleichs merken.
	 *
	 * @param int         $id    Postfach.
	 * @param string|null $error Fehlermeldung oder null bei Erfolg.
	 */
	public static function set_sync_result( $id, $error = null ) {
		global $wpdb;

		$wpdb->update(
			self::table( 'accounts' ),
			array(
				'last_sync'  => current_time( 'mysql' ),
				'last_error' => null === $error ? null : (string) $error,
			),
			array( 'id' => (int) $id )
		);
	}

	/* --------------------------------------------------------------------
	 * Mitnutzer
	 * ------------------------------------------------------------------ */

	/**
	 * Mitnutzer aller Postfächer in einer Abfrage (das Menü fragt bei jedem
	 * Seitenaufruf im Backend).
	 *
	 * @return array account_id => ( user_id => can_send )
	 */
	protected static function all_shares() {
		global $wpdb;
		$t    = self::table( 'shares' );
		$rows = $wpdb->get_results( "SELECT account_id, user_id, can_send FROM {$t}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();

		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['account_id'] ][ (int) $r['user_id'] ] = (bool) $r['can_send'];
		}

		return $out;
	}

	/**
	 * Mitnutzer eines Postfachs.
	 *
	 * @param int $account_id Postfach.
	 * @return array user_id => can_send (bool)
	 */
	public static function get_shares( $account_id ) {
		global $wpdb;
		$t    = self::table( 'shares' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, can_send FROM {$t} WHERE account_id = %d", (int) $account_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();

		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['user_id'] ] = (bool) $r['can_send'];
		}

		return $out;
	}

	/**
	 * Mitnutzer ersetzen.
	 *
	 * @param int   $account_id Postfach.
	 * @param array $shares     user_id => can_send.
	 */
	public static function set_shares( $account_id, array $shares ) {
		global $wpdb;

		$wpdb->delete( self::table( 'shares' ), array( 'account_id' => (int) $account_id ) );

		foreach ( $shares as $user_id => $can_send ) {
			$wpdb->insert(
				self::table( 'shares' ),
				array(
					'account_id' => (int) $account_id,
					'user_id'    => (int) $user_id,
					'can_send'   => $can_send ? 1 : 0,
				)
			);
		}
	}

	/**
	 * Hat der Nutzer noch irgendein Postfach (als Besitzer oder Mitnutzer)?
	 *
	 * @param int $user_id Nutzer.
	 * @return bool
	 */
	public static function user_has_any_account( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;

		$own = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'accounts' ) . ' WHERE owner_user_id = %d', $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $own > 0 ) {
			return true;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'shares' ) . ' WHERE user_id = %d', $user_id ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/* --------------------------------------------------------------------
	 * Ordner
	 * ------------------------------------------------------------------ */

	/**
	 * Ordner eines Postfachs.
	 *
	 * @param int $account_id Postfach.
	 * @return array Name => Zeile
	 */
	public static function get_folders( $account_id ) {
		global $wpdb;
		$t    = self::table( 'folders' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE account_id = %d", (int) $account_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();

		foreach ( (array) $rows as $r ) {
			$out[ $r['name'] ] = $r;
		}

		return $out;
	}

	/**
	 * Ordner anlegen oder Rolle und Trenner aktualisieren.
	 *
	 * @param int    $account_id Postfach.
	 * @param string $name       Name.
	 * @param string $role       inbox, sent, drafts, trash, spam, archive, other.
	 * @param string $delimiter  Trenner.
	 * @return array Die Zeile.
	 */
	public static function upsert_folder( $account_id, $name, $role, $delimiter ) {
		global $wpdb;
		$t   = self::table( 'folders' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE account_id = %d AND name = %s", (int) $account_id, $name ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( $row ) {
			if ( $row['role'] !== $role || $row['delimiter'] !== $delimiter ) {
				$wpdb->update(
					$t,
					array(
						'role'      => $role,
						'delimiter' => $delimiter,
					),
					array( 'id' => (int) $row['id'] )
				);
				$row['role']      = $role;
				$row['delimiter'] = $delimiter;
			}
			return $row;
		}

		$wpdb->insert(
			$t,
			array(
				'account_id' => (int) $account_id,
				'name'       => $name,
				'role'       => $role,
				'delimiter'  => $delimiter,
			)
		);

		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $wpdb->insert_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Abgleichstand eines Ordners speichern.
	 *
	 * @param int   $folder_id Ordner.
	 * @param array $state     uidvalidity, last_uid, backfill_uid, last_sync.
	 */
	public static function update_folder_state( $folder_id, array $state ) {
		global $wpdb;

		$row = array_intersect_key( $state, array_flip( array( 'uidvalidity', 'last_uid', 'backfill_uid', 'last_sync' ) ) );
		$wpdb->update( self::table( 'folders' ), $row, array( 'id' => (int) $folder_id ) );
	}

	/**
	 * Ordner, der in der Liste nicht mehr vorkommt, samt Nachrichten entfernen.
	 *
	 * @param int    $account_id Postfach.
	 * @param string $name       Ordner.
	 */
	public static function delete_folder( $account_id, $name ) {
		global $wpdb;

		self::delete_folder_messages( $account_id, $name );
		$wpdb->delete(
			self::table( 'folders' ),
			array(
				'account_id' => (int) $account_id,
				'name'       => $name,
			)
		);
	}

	/**
	 * Zähler je Ordner.
	 *
	 * @param int $account_id Postfach.
	 * @return array Ordner => {total, unread}
	 */
	public static function folder_counts( $account_id ) {
		global $wpdb;
		$t    = self::table( 'messages' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT folder, COUNT(*) AS total, SUM(is_read = 0) AS unread FROM {$t} WHERE account_id = %d GROUP BY folder", (int) $account_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();

		foreach ( (array) $rows as $r ) {
			$out[ $r['folder'] ] = array(
				'total'  => (int) $r['total'],
				'unread' => (int) $r['unread'],
			);
		}

		return $out;
	}

	/**
	 * Ungelesene Mails im Posteingang über mehrere Postfächer.
	 *
	 * @param int[] $account_ids Postfächer.
	 * @return int
	 */
	public static function unread_inbox_total( array $account_ids ) {
		global $wpdb;

		if ( ! $account_ids ) {
			return 0;
		}

		$ids = implode( ',', array_map( 'intval', $account_ids ) );
		$m   = self::table( 'messages' );
		$f   = self::table( 'folders' );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} m INNER JOIN {$f} f ON f.account_id = m.account_id AND f.name = m.folder WHERE m.account_id IN ({$ids}) AND f.role = 'inbox' AND m.is_read = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/* --------------------------------------------------------------------
	 * Nachrichten
	 * ------------------------------------------------------------------ */

	/**
	 * Eine Nachricht.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get_message( $id ) {
		global $wpdb;
		$t   = self::table( 'messages' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $row ? $row : null;
	}

	/**
	 * Nachricht anlegen.
	 *
	 * @param array $data Spalten.
	 * @return int ID, 0 wenn es sie (Postfach, Ordner, UID) schon gibt oder das Anlegen scheitert.
	 */
	public static function insert_message( array $data ) {
		global $wpdb;

		if ( ! empty( $data['uid'] ) ) {
			$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL
				$wpdb->prepare(
					'SELECT id FROM ' . self::table( 'messages' ) . ' WHERE account_id = %d AND folder = %s AND uid = %d',
					(int) $data['account_id'],
					$data['folder'],
					(int) $data['uid']
				)
			);
			if ( $existing ) {
				return 0;
			}
		}

		if ( empty( $data['created_at'] ) ) {
			$data['created_at'] = current_time( 'mysql' );
		}

		$ok = $wpdb->insert( self::table( 'messages' ), $data );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Felder einer Nachricht ändern.
	 *
	 * @param int   $id   Nachricht.
	 * @param array $data Spalten.
	 */
	public static function update_message( $id, array $data ) {
		global $wpdb;
		$wpdb->update( self::table( 'messages' ), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Nachricht samt Anhängen und Notizen löschen.
	 *
	 * @param int $id Nachricht.
	 */
	public static function delete_message( $id ) {
		global $wpdb;
		$id = (int) $id;

		foreach ( self::attachments( $id ) as $att ) {
			self::delete_attachment_file( $att['path'] );
		}

		$wpdb->delete( self::table( 'attachments' ), array( 'message_id' => $id ) );
		$wpdb->delete( self::table( 'notes' ), array( 'message_id' => $id ) );
		$wpdb->delete( self::table( 'messages' ), array( 'id' => $id ) );
	}

	/**
	 * Alle Nachrichten eines Ordners löschen (nur lokal).
	 *
	 * @param int    $account_id Postfach.
	 * @param string $folder     Ordner.
	 */
	public static function delete_folder_messages( $account_id, $folder ) {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT id FROM ' . self::table( 'messages' ) . ' WHERE account_id = %d AND folder = %s',
				(int) $account_id,
				$folder
			)
		);

		foreach ( $ids as $id ) {
			self::delete_message( (int) $id );
		}
	}

	/**
	 * Lokal bekannte Nachrichten eines Ordners ab einer UID.
	 *
	 * @param int    $account_id Postfach.
	 * @param string $folder     Ordner.
	 * @param int    $min_uid    Kleinste UID.
	 * @return array UID => {id, is_read, is_starred, is_answered}
	 */
	public static function local_uids( $account_id, $folder, $min_uid = 0 ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT id, uid, is_read, is_starred, is_answered FROM ' . self::table( 'messages' ) . ' WHERE account_id = %d AND folder = %s AND uid IS NOT NULL AND uid >= %d',
				(int) $account_id,
				$folder,
				(int) $min_uid
			),
			ARRAY_A
		);
		$out  = array();

		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['uid'] ] = $r;
		}

		return $out;
	}

	/**
	 * Niedrigste lokal bekannte UID eines Ordners.
	 *
	 * @param int    $account_id Postfach.
	 * @param string $folder     Ordner.
	 * @return int 0 wenn keine.
	 */
	public static function min_local_uid( $account_id, $folder ) {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT MIN(uid) FROM ' . self::table( 'messages' ) . ' WHERE account_id = %d AND folder = %s AND uid IS NOT NULL',
				(int) $account_id,
				$folder
			)
		);
	}

	/**
	 * Mailliste eines Ordners.
	 *
	 * @param int   $account_id Postfach.
	 * @param string $folder    Ordner.
	 * @param array $args {
	 *     @type int    $page     Seite (ab 1).
	 *     @type int    $per_page Je Seite.
	 *     @type string $search   Suchtext (Betreff, Absender, Text).
	 *     @type string $filter   all, unread, starred oder mine (mir zugewiesen).
	 *     @type int    $user_id  Für den Filter mine.
	 * }
	 * @return array {rows, total}
	 */
	public static function list_messages( $account_id, $folder, array $args = array() ) {
		global $wpdb;
		$args = array_merge(
			array(
				'page'     => 1,
				'per_page' => 30,
				'search'   => '',
				'filter'   => 'all',
				'user_id'  => 0,
			),
			$args
		);

		$t     = self::table( 'messages' );
		$where = array( 'account_id = %d', 'folder = %s' );
		$vals  = array( (int) $account_id, $folder );

		if ( 'unread' === $args['filter'] ) {
			$where[] = 'is_read = 0';
		} elseif ( 'starred' === $args['filter'] ) {
			$where[] = 'is_starred = 1';
		} elseif ( 'mine' === $args['filter'] ) {
			$where[] = 'assigned_user_id = %d';
			$vals[]  = (int) $args['user_id'];
		}

		if ( '' !== trim( (string) $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$where[] = '(subject LIKE %s OR from_name LIKE %s OR from_email LIKE %s OR to_json LIKE %s OR body_text LIKE %s)';
			array_push( $vals, $like, $like, $like, $like, $like );
		}

		$cond     = implode( ' AND ', $where );
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$cond}", $vals ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->prepare(
				"SELECT id, account_id, folder, uid, from_name, from_email, to_json, subject, sent_at, snippet, has_attachments, is_read, is_starred, is_answered, assigned_user_id FROM {$t} WHERE {$cond} ORDER BY sent_at DESC, id DESC LIMIT %d OFFSET %d",
				array_merge( $vals, array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Weitere Nachrichten desselben Verlaufs.
	 *
	 * @param int    $account_id Postfach.
	 * @param string $thread_key Verlaufsschlüssel.
	 * @param int    $exclude_id Diese Nachricht auslassen.
	 * @return array
	 */
	public static function thread_messages( $account_id, $thread_key, $exclude_id = 0 ) {
		global $wpdb;

		if ( '' === (string) $thread_key ) {
			return array();
		}

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT id, folder, from_name, from_email, subject, sent_at, snippet, is_read FROM ' . self::table( 'messages' ) . ' WHERE account_id = %d AND thread_key = %s AND id <> %d ORDER BY sent_at ASC, id ASC LIMIT 50',
				(int) $account_id,
				$thread_key,
				(int) $exclude_id
			),
			ARRAY_A
		);
	}

	/**
	 * Eine Nachricht anhand ihrer Message-ID finden.
	 *
	 * @param int    $account_id Postfach.
	 * @param string $message_id Message-ID ohne spitze Klammern.
	 * @return array|null
	 */
	public static function find_by_message_id( $account_id, $message_id ) {
		global $wpdb;

		if ( '' === (string) $message_id ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT * FROM ' . self::table( 'messages' ) . ' WHERE account_id = %d AND message_id = %s LIMIT 1',
				(int) $account_id,
				$message_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Nachrichten einer E-Mail-Adresse (Absender oder Empfänger) in den
	 * angegebenen Postfächern – für den Mailverlauf bei Kunden und Bestellungen.
	 *
	 * @param string $email       Adresse.
	 * @param int[]  $account_ids Nur diese Postfächer.
	 * @param int    $limit       Anzahl.
	 * @return array
	 */
	public static function messages_for_email( $email, array $account_ids, $limit = 20 ) {
		global $wpdb;

		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || ! $account_ids ) {
			return array();
		}

		$ids  = implode( ',', array_map( 'intval', $account_ids ) );
		$like = '%' . $wpdb->esc_like( $email ) . '%';

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT id, account_id, folder, from_name, from_email, subject, sent_at, is_read FROM ' . self::table( 'messages' ) . " WHERE account_id IN ({$ids}) AND folder <> %s AND (from_email = %s OR to_json LIKE %s) ORDER BY sent_at DESC LIMIT %d",
				self::DRAFTS,
				$email,
				$like,
				(int) $limit
			),
			ARRAY_A
		);
	}

	/* --------------------------------------------------------------------
	 * Anhänge
	 * ------------------------------------------------------------------ */

	/**
	 * Verzeichnis der Anhänge eines Postfachs.
	 *
	 * @param int $account_id Postfach.
	 * @return string
	 */
	public static function attachment_dir( $account_id ) {
		return trailingslashit( Installer::mail_dir() ) . (int) $account_id;
	}

	/**
	 * Anhang auf die Platte legen und in der Datenbank vermerken.
	 *
	 * Der Dateiname auf der Platte ist zufällig – der echte Name steht nur in
	 * der Datenbank. So lässt sich weder ein Pfad einschleusen noch eine
	 * ausführbare Datei erraten.
	 *
	 * @param int   $account_id Postfach.
	 * @param int   $message_id Nachricht.
	 * @param array $att        {filename, mime, content, content_id, inline}.
	 * @return int ID, 0 bei Fehler.
	 */
	public static function add_attachment( $account_id, $message_id, array $att ) {
		global $wpdb;

		$dir = self::attachment_dir( $account_id );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$rel  = (int) $account_id . '/' . bin2hex( random_bytes( 16 ) ) . '.bin';
		$path = trailingslashit( Installer::mail_dir() ) . $rel;

		if ( false === file_put_contents( $path, $att['content'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return 0;
		}

		$ok = $wpdb->insert(
			self::table( 'attachments' ),
			array(
				'message_id' => (int) $message_id,
				'filename'   => $att['filename'],
				'mime'       => substr( (string) $att['mime'], 0, 120 ),
				'size'       => strlen( $att['content'] ),
				'content_id' => '' !== (string) $att['content_id'] ? $att['content_id'] : null,
				'inline'     => ! empty( $att['inline'] ) ? 1 : 0,
				'path'       => $rel,
			)
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Anhänge einer Nachricht.
	 *
	 * @param int $message_id Nachricht.
	 * @return array
	 */
	public static function attachments( $message_id ) {
		global $wpdb;

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->prepare( 'SELECT * FROM ' . self::table( 'attachments' ) . ' WHERE message_id = %d ORDER BY id ASC', (int) $message_id ),
			ARRAY_A
		);
	}

	/**
	 * Ein Anhang.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get_attachment( $id ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->prepare( 'SELECT * FROM ' . self::table( 'attachments' ) . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Absoluter Pfad eines Anhangs – nur, wenn er sicher im Mail-Verzeichnis liegt.
	 *
	 * @param string $rel Relativer Pfad aus der Datenbank.
	 * @return string|null
	 */
	public static function attachment_path( $rel ) {
		$base = realpath( Installer::mail_dir() );
		$full = realpath( trailingslashit( Installer::mail_dir() ) . $rel );

		if ( false === $base || false === $full || 0 !== strpos( $full, $base . DIRECTORY_SEPARATOR ) || ! is_file( $full ) ) {
			return null;
		}

		return $full;
	}

	/**
	 * Datei eines Anhangs löschen.
	 *
	 * @param string $rel Relativer Pfad.
	 */
	protected static function delete_attachment_file( $rel ) {
		$path = self::attachment_path( $rel );

		if ( null !== $path ) {
			@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		}
	}

	/* --------------------------------------------------------------------
	 * Notizen
	 * ------------------------------------------------------------------ */

	/**
	 * Notiz zu einer Nachricht anlegen.
	 *
	 * @param int    $message_id Nachricht.
	 * @param int    $user_id    Verfasser.
	 * @param string $note       Text.
	 * @return int
	 */
	public static function add_note( $message_id, $user_id, $note ) {
		global $wpdb;

		$wpdb->insert(
			self::table( 'notes' ),
			array(
				'message_id' => (int) $message_id,
				'user_id'    => (int) $user_id,
				'note'       => (string) $note,
				'created_at' => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Notizen zu einer Nachricht.
	 *
	 * @param int $message_id Nachricht.
	 * @return array
	 */
	public static function notes( $message_id ) {
		global $wpdb;

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->prepare( 'SELECT * FROM ' . self::table( 'notes' ) . ' WHERE message_id = %d ORDER BY id ASC', (int) $message_id ),
			ARRAY_A
		);
	}
}
