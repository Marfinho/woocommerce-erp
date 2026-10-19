<?php
/**
 * Gleicht ein Postfach per IMAP mit der Datenbank ab.
 *
 * Ablauf je Postfach: anmelden, Ordner auflisten, je Ordner neue Mails holen,
 * dann Flags (gelesen, Stern) und gelöschte Mails abgleichen.
 *
 * Neue Mails kommen zuerst: Beim ersten Abgleich eines Ordners wird von den
 * neuesten rückwärts geladen (so ist der Posteingang sofort brauchbar) und
 * ältere Mails innerhalb der Aufbewahrungsfrist laufen in den folgenden
 * Durchgängen nach (`backfill_uid`).
 *
 * Jeder Durchgang ist durch Zeit und Stückzahl begrenzt, damit er in ein
 * Hintergrundjob-Fenster passt; `more` in der Antwort sagt, dass noch etwas
 * offen ist.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Lock;
use Lindenweg16\Core\Settings;

/**
 * IMAP-Abgleich.
 */
class SyncService {

	/**
	 * Mails je Ordner und Durchgang.
	 */
	const BATCH = 40;

	/**
	 * Höchstdauer eines Durchgangs in Sekunden.
	 */
	const BUDGET = 20;

	/**
	 * Größere Mails werden nur mit Kopfzeilen geholt (Bytes). Beim Lesen
	 * liegen Rohtext, Teile und entpackte Anhänge gleichzeitig im Speicher –
	 * ein Vielfaches der Mailgröße, und WordPress läuft oft mit 128–256 MB.
	 */
	const MAX_SIZE = 12582912;

	/**
	 * Wann der Durchgang aufhören muss (Unix-Zeit mit Bruchteilen).
	 *
	 * @var float
	 */
	protected $deadline = 0.0;

	/**
	 * Hintergrundjob: alle aktiven Postfächer.
	 */
	public static function run_all() {
		if ( ! Settings::get( 'mail_sync_aktiv', 1 ) ) {
			return;
		}

		$service = new self();
		$start   = microtime( true );

		foreach ( MailRepository::all_accounts() as $account ) {
			if ( empty( $account['active'] ) ) {
				continue;
			}

			// Die Gesamtdauer ist begrenzt; was nicht mehr passt, kommt im nächsten Lauf.
			$left = self::BUDGET * 2 - ( microtime( true ) - $start );
			if ( $left < 3 ) {
				break;
			}

			$service->sync_account( (int) $account['id'], array( 'budget' => min( self::BUDGET, $left ) ) );
		}
	}

	/**
	 * Rolle eines Ordners: aus den Sonderflags des Servers, sonst aus dem Namen.
	 *
	 * @param string   $name  Ordnername (UTF-8).
	 * @param string[] $flags Flags des Servers, klein geschrieben.
	 * @return string inbox, sent, drafts, trash, spam, archive oder other.
	 */
	public static function role_for( $name, array $flags ) {
		if ( 'inbox' === strtolower( $name ) ) {
			return 'inbox';
		}

		$by_flag = array(
			'\\sent'    => 'sent',
			'\\drafts'  => 'drafts',
			'\\trash'   => 'trash',
			'\\junk'    => 'spam',
			'\\archive' => 'archive',
			'\\all'     => 'archive',
		);
		foreach ( $by_flag as $flag => $role ) {
			if ( in_array( $flag, $flags, true ) ) {
				return $role;
			}
		}

		// Letzter Teil des Pfades, z. B. "INBOX.Sent" → "sent".
		$leaf = strtolower( (string) preg_replace( '#^.*[./\\\\]#u', '', $name ) );

		$by_name = array(
			'sent'               => 'sent',
			'sent items'         => 'sent',
			'sent messages'      => 'sent',
			'gesendet'           => 'sent',
			'gesendete elemente' => 'sent',
			'gesendete objekte'  => 'sent',
			'drafts'             => 'drafts',
			'entwürfe'           => 'drafts',
			'entwurf'            => 'drafts',
			'trash'              => 'trash',
			'deleted items'      => 'trash',
			'deleted messages'   => 'trash',
			'papierkorb'         => 'trash',
			'gelöscht'           => 'trash',
			'gelöschte elemente' => 'trash',
			'spam'               => 'spam',
			'junk'               => 'spam',
			'junk e-mail'        => 'spam',
			'archive'            => 'archive',
			'archiv'             => 'archive',
		);

		return isset( $by_name[ $leaf ] ) ? $by_name[ $leaf ] : 'other';
	}

	/**
	 * Verlaufsschlüssel: Mails desselben Gesprächs bekommen denselben.
	 *
	 * @param string      $message_id   Eigene Message-ID.
	 * @param string      $in_reply_to  Message-ID, auf die geantwortet wird.
	 * @param string[]    $references   Message-IDs des Verlaufs.
	 * @param string|null $parent_key   Schlüssel der bereits bekannten Elternmail.
	 * @return string
	 */
	public static function thread_key( $message_id, $in_reply_to, array $references, $parent_key = null ) {
		if ( null !== $parent_key && '' !== $parent_key ) {
			return $parent_key;
		}

		if ( $references ) {
			return (string) $references[0];
		}

		if ( '' !== (string) $in_reply_to ) {
			return (string) $in_reply_to;
		}

		return (string) $message_id;
	}

	/**
	 * Ein Postfach abgleichen.
	 *
	 * @param int   $account_id Postfach.
	 * @param array $opts {
	 *     @type float  $budget  Sekunden (Standard BUDGET).
	 *     @type int    $batch   Mails je Ordner (Standard BATCH).
	 *     @type string $only    Nur Ordner mit dieser Rolle (z. B. sent).
	 * }
	 * @return array {new, removed, more, error, skipped}
	 */
	public function sync_account( $account_id, array $opts = array() ) {
		$result  = array(
			'new'     => 0,
			'removed' => 0,
			'more'    => false,
			'error'   => '',
			'skipped' => false,
		);
		$account = MailRepository::get_account( $account_id );

		if ( ! $account ) {
			$result['error'] = __( 'Postfach nicht gefunden.', 'lindenweg16' );
			return $result;
		}

		$lock = 'mailsync_' . (int) $account_id;
		if ( ! Lock::acquire( $lock, 0 ) ) {
			$result['skipped'] = true;
			return $result;
		}

		$this->deadline = microtime( true ) + ( isset( $opts['budget'] ) ? (float) $opts['budget'] : self::BUDGET );
		$batch          = isset( $opts['batch'] ) ? max( 1, (int) $opts['batch'] ) : self::BATCH;
		$client         = null;

		try {
			$client = self::connect( $account );

			foreach ( $this->prepare_folders( $account, $client, isset( $opts['only'] ) ? $opts['only'] : '' ) as $folder ) {
				if ( $this->out_of_time() ) {
					$result['more'] = true;
					break;
				}

				$r                 = $this->sync_folder( $account, $folder, $client, $batch );
				$result['new']    += $r['new'];
				$result['removed'] += $r['removed'];
				$result['more']    = $result['more'] || $r['more'];
			}

			MailRepository::set_sync_result( $account_id, null );
		} catch ( \RuntimeException $e ) {
			$result['error'] = $e->getMessage();
			MailRepository::set_sync_result( $account_id, $e->getMessage() );
		}

		if ( $client ) {
			$client->logout();
		}

		Lock::release( $lock );

		return $result;
	}

	/**
	 * Verbindung zum IMAP-Server des Postfachs.
	 *
	 * @param array $account Postfach.
	 * @return ImapClient Angemeldet.
	 * @throws \RuntimeException Bei Verbindungs- oder Anmeldefehlern.
	 */
	public static function connect( array $account ) {
		return self::connect_with( $account, self::password( $account ) );
	}

	/**
	 * Verbindung mit ausdrücklich übergebenem Passwort (Verbindungstest vor dem Speichern).
	 *
	 * @param array  $account  Postfach (imap_host, imap_port, imap_secure, username).
	 * @param string $password Passwort im Klartext.
	 * @return ImapClient Angemeldet.
	 * @throws \RuntimeException Bei Verbindungs- oder Anmeldefehlern.
	 */
	public static function connect_with( array $account, $password ) {
		/**
		 * Zusätze für die IMAP-Verbindung (z. B. verify_peer = false für Tests).
		 *
		 * @param array $options Optionen für ImapClient.
		 * @param array $account Postfach.
		 */
		$options = apply_filters( 'lw16_mail_imap_options', array(), $account );

		$client = new ImapClient( $account['imap_host'], (int) $account['imap_port'], $account['imap_secure'], 20, (array) $options );
		$client->connect();
		$client->login( $account['username'], $password );

		return $client;
	}

	/**
	 * Das Passwort eines Postfachs im Klartext.
	 *
	 * @param array $account Postfach.
	 * @return string
	 * @throws \RuntimeException Wenn es nicht lesbar ist.
	 */
	public static function password( array $account ) {
		$password = MailCrypto::decrypt( isset( $account['password_enc'] ) ? $account['password_enc'] : '' );

		if ( null === $password ) {
			throw new \RuntimeException( __( 'Das gespeicherte Passwort ist nicht lesbar. Bitte das Passwort des Postfachs neu eintragen.', 'lindenweg16' ) );
		}

		return $password;
	}

	/**
	 * Ordner vom Server lesen, in der Datenbank nachführen und in die
	 * Abgleichsreihenfolge bringen (Posteingang zuerst).
	 *
	 * @param array      $account Postfach.
	 * @param ImapClient $client  Angemeldeter Client.
	 * @param string     $only    Nur diese Rolle, leer = alle.
	 * @return array Zeilen aus mail_folders, ohne Entwürfe.
	 */
	protected function prepare_folders( array $account, ImapClient $client, $only = '' ) {
		$account_id = (int) $account['id'];
		$seen       = array();
		$rows       = array();

		foreach ( $client->list_folders() as $f ) {
			$role   = self::role_for( $f['name'], $f['flags'] );
			$row    = MailRepository::upsert_folder( $account_id, $f['name'], $role, $f['delimiter'] );
			$seen[] = $f['name'];

			// Entwürfe führt die Mailbox lokal; der Serverordner bleibt unberührt.
			if ( 'drafts' === $role || ( '' !== $only && $only !== $role ) ) {
				continue;
			}

			$rows[] = $row;
		}

		// Ordner, die es auf dem Server nicht mehr gibt, samt Mails entfernen.
		if ( '' === $only && $seen ) {
			foreach ( MailRepository::get_folders( $account_id ) as $name => $row ) {
				if ( ! in_array( $name, $seen, true ) ) {
					MailRepository::delete_folder( $account_id, $name );
				}
			}
		}

		$order = array(
			'inbox'   => 0,
			'sent'    => 1,
			'spam'    => 3,
			'trash'   => 4,
			'archive' => 5,
		);
		usort(
			$rows,
			static function ( $a, $b ) use ( $order ) {
				$ra = isset( $order[ $a['role'] ] ) ? $order[ $a['role'] ] : 2;
				$rb = isset( $order[ $b['role'] ] ) ? $order[ $b['role'] ] : 2;
				return $ra === $rb ? strcmp( $a['name'], $b['name'] ) : $ra - $rb;
			}
		);

		return $rows;
	}

	/**
	 * Einen Ordner abgleichen.
	 *
	 * @param array      $account Postfach.
	 * @param array      $folder  Zeile aus mail_folders.
	 * @param ImapClient $client  Angemeldeter Client.
	 * @param int        $batch   Mails je Durchgang.
	 * @return array {new, removed, more}
	 */
	protected function sync_folder( array $account, array $folder, ImapClient $client, $batch ) {
		$account_id = (int) $account['id'];
		$name       = $folder['name'];
		$out        = array(
			'new'     => 0,
			'removed' => 0,
			'more'    => false,
		);

		$info = $client->select( $name );

		// Leerer Ordner: Es gibt nichts zu holen, und lokal Vorhandenes ist auf dem Server
		// gelöscht worden. Manche Server verweigern UID-Bereiche mit "*" in einem leeren Ordner.
		if ( 0 === (int) $info['exists'] ) {
			$out['removed'] = count( MailRepository::local_uids( $account_id, $name ) );
			MailRepository::delete_folder_messages( $account_id, $name );
			MailRepository::update_folder_state(
				$folder['id'],
				array(
					'uidvalidity'  => (int) $info['uidvalidity'],
					'last_uid'     => max( (int) $folder['last_uid'], (int) $info['uidnext'] - 1 ),
					'backfill_uid' => 0,
					'last_sync'    => current_time( 'mysql' ),
				)
			);

			return $out;
		}

		$validity = (int) $folder['uidvalidity'];
		$last_uid = (int) $folder['last_uid'];
		$backfill = (int) $folder['backfill_uid'];
		$fresh    = empty( $folder['last_sync'] );

		// Der Server hat die UIDs neu vergeben: lokaler Bestand und Stand sind wertlos.
		if ( $validity && $validity !== (int) $info['uidvalidity'] ) {
			MailRepository::delete_folder_messages( $account_id, $name );
			$last_uid = 0;
			$backfill = 0;
			$fresh    = true;
		}

		$since = 'SINCE ' . ImapClient::date( time() - max( 1, (int) Settings::get( 'mail_lookback_tage', 90 ) ) * DAY_IN_SECONDS );

		if ( $fresh ) {
			// Erster Abgleich: neueste zuerst.
			$all   = $client->uid_search( $since );
			$todo  = array_reverse( $all );
			$done  = $this->fetch_and_store( $client, $account, $name, array_slice( $todo, 0, $batch ) );
			$left  = $done ? array_filter(
				$all,
				static function ( $u ) use ( $done ) {
					return $u < min( $done );
				}
			) : $all;

			$top = max( (int) $info['uidnext'] - 1, $all ? max( $all ) : 0 );

			$last_uid = $top;
			$backfill = $left ? ( $done ? min( $done ) : $top + 1 ) : 0;
			$out['new'] += count( $done );
			$out['more'] = (bool) $left;

			MailRepository::update_folder_state(
				$folder['id'],
				array(
					'uidvalidity'  => (int) $info['uidvalidity'],
					'last_uid'     => $last_uid,
					'backfill_uid' => $backfill,
					'last_sync'    => current_time( 'mysql' ),
				)
			);
		} else {
			// Neue Mails (aufsteigend).
			$new = array_values(
				array_filter(
					$client->uid_search( 'UID ' . ( $last_uid + 1 ) . ':*' ),
					static function ( $u ) use ( $last_uid ) {
						return $u > $last_uid;
					}
				)
			);

			$done = $this->fetch_and_store( $client, $account, $name, array_slice( $new, 0, $batch ) );
			if ( $done ) {
				$last_uid = max( $done );
			}
			$out['new']  += count( $done );
			$out['more']  = count( $new ) > count( $done );

			// Ältere Mails nachladen, solange Zeit und Platz im Durchgang ist.
			if ( $backfill > 0 && ! $this->out_of_time() && ! $out['more'] ) {
				$old  = array_reverse(
					array_values(
						array_filter(
							$client->uid_search( $since ),
							static function ( $u ) use ( $backfill ) {
								return $u < $backfill;
							}
						)
					)
				);
				$done = $this->fetch_and_store( $client, $account, $name, array_slice( $old, 0, $batch ) );
				if ( $done ) {
					$backfill = count( $old ) > count( $done ) ? min( $done ) : 0;
				} elseif ( ! $old ) {
					$backfill = 0;
				}
				$out['new'] += count( $done );
				$out['more'] = $out['more'] || ( $backfill > 0 );
			}

			MailRepository::update_folder_state(
				$folder['id'],
				array(
					'uidvalidity'  => (int) $info['uidvalidity'],
					'last_uid'     => $last_uid,
					'backfill_uid' => $backfill,
					'last_sync'    => current_time( 'mysql' ),
				)
			);
		}

		$out['removed'] = $this->refresh_flags( $account_id, $name, $client );

		return $out;
	}

	/**
	 * Mails holen und speichern.
	 *
	 * @param ImapClient $client  Client.
	 * @param array      $account Postfach.
	 * @param string     $folder  Ordner.
	 * @param int[]      $uids    UIDs in der gewünschten Reihenfolge.
	 * @return int[] Bearbeitete UIDs – ein Anfang der Liste, wenn die Zeit knapp wurde.
	 */
	protected function fetch_and_store( ImapClient $client, array $account, $folder, array $uids ) {
		if ( ! $uids ) {
			return array();
		}

		$meta      = $client->uid_fetch_meta( implode( ',', $uids ) );
		$processed = array();

		foreach ( $uids as $uid ) {
			if ( $processed && $this->out_of_time() ) {
				break;
			}

			if ( isset( $meta[ $uid ] ) ) {
				$msg = $client->uid_fetch_message( $uid, $meta[ $uid ]['size'] > self::MAX_SIZE );

				// Zwischen Suche und Abruf gelöscht: nichts zu speichern.
				if ( $msg ) {
					$this->store( $account, $folder, $uid, $msg['flags'], $msg['raw'], $meta[ $uid ]['size'] > self::MAX_SIZE );
				}
			}

			$processed[] = $uid;
		}

		return $processed;
	}

	/**
	 * Eine Roh-Mail in die Datenbank schreiben.
	 *
	 * @param array    $account   Postfach.
	 * @param string   $folder    Ordner.
	 * @param int|null $uid       UID (null bei lokalen Kopien).
	 * @param string[] $flags     IMAP-Flags.
	 * @param string   $raw       Rohtext.
	 * @param bool     $truncated Nur Kopfzeilen vorhanden (zu groß).
	 * @return int ID der Nachricht, 0 wenn nicht gespeichert.
	 */
	public static function store( array $account, $folder, $uid, array $flags, $raw, $truncated = false ) {
		$m = MimeParser::parse( $raw );

		$parent     = MailRepository::find_by_message_id( (int) $account['id'], $m['in_reply_to'] );
		$parent_key = $parent ? ( '' !== (string) $parent['thread_key'] ? $parent['thread_key'] : $parent['message_id'] ) : null;
		$thread     = self::thread_key( $m['message_id'], $m['in_reply_to'], $m['references'], $parent_key );

		if ( '' === $thread ) {
			$thread = 'uid:' . $folder . ':' . (int) $uid;
		}

		$text = $m['text'];
		if ( $truncated ) {
			$text = __( '(Diese Nachricht ist sehr groß und wurde nicht geladen. Bitte im Mailprogramm oder Webmail öffnen.)', 'lindenweg16' );
		}

		$visible = array_filter(
			$m['attachments'],
			static function ( $a ) {
				return empty( $a['inline'] );
			}
		);

		$flags = array_map( 'strtolower', $flags );

		$id = MailRepository::insert_message(
			array(
				'account_id'      => (int) $account['id'],
				'folder'          => $folder,
				'uid'             => null === $uid ? null : (int) $uid,
				'message_id'      => self::cut( $m['message_id'], 255 ),
				'in_reply_to'     => self::cut( $m['in_reply_to'], 255 ),
				'thread_key'      => self::cut( $thread, 255 ),
				'from_name'       => self::cut( $m['from']['name'], 190 ),
				'from_email'      => self::cut( strtolower( $m['from']['email'] ), 190 ),
				'to_json'         => wp_json_encode( $m['to'] ),
				'cc_json'         => wp_json_encode( $m['cc'] ),
				'subject'         => self::cut( $m['subject'], 500 ),
				'sent_at'         => gmdate( 'Y-m-d H:i:s', $m['date'] ? $m['date'] : time() ),
				'snippet'         => self::cut( $m['snippet'], 255 ),
				'body_text'       => str_replace( "\0", '', $text ),
				'body_html'       => $truncated ? '' : str_replace( "\0", '', $m['html'] ),
				'has_attachments' => $visible ? 1 : 0,
				'is_read'         => in_array( '\\seen', $flags, true ) ? 1 : 0,
				'is_starred'      => in_array( '\\flagged', $flags, true ) ? 1 : 0,
				'is_answered'     => in_array( '\\answered', $flags, true ) ? 1 : 0,
				'size'            => strlen( $raw ),
			)
		);

		if ( $id && ! $truncated ) {
			foreach ( $m['attachments'] as $att ) {
				MailRepository::add_attachment( (int) $account['id'], $id, $att );
			}
		}

		return $id;
	}

	/**
	 * Text auf eine Länge kürzen (zeichenweise).
	 *
	 * @param string $text Text.
	 * @param int    $max  Zeichen.
	 * @return string
	 */
	protected static function cut( $text, $max ) {
		$text = str_replace( "\0", '', (string) $text );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * Gelesen/Stern/Beantwortet vom Server übernehmen und auf dem Server
	 * gelöschte Mails lokal entfernen.
	 *
	 * @param int        $account_id Postfach.
	 * @param string     $folder     Ordner.
	 * @param ImapClient $client     Client.
	 * @return int Entfernte Mails.
	 */
	protected function refresh_flags( $account_id, $folder, ImapClient $client ) {
		$min = MailRepository::min_local_uid( $account_id, $folder );
		if ( ! $min ) {
			return 0;
		}

		$server  = $client->uid_fetch_meta( $min . ':*' );
		$local   = MailRepository::local_uids( $account_id, $folder, $min );
		$removed = 0;

		foreach ( $local as $uid => $row ) {
			if ( ! isset( $server[ $uid ] ) ) {
				MailRepository::delete_message( (int) $row['id'] );
				$removed++;
				continue;
			}

			$flags = array_map( 'strtolower', $server[ $uid ]['flags'] );
			$want  = array(
				'is_read'     => in_array( '\\seen', $flags, true ) ? 1 : 0,
				'is_starred'  => in_array( '\\flagged', $flags, true ) ? 1 : 0,
				'is_answered' => in_array( '\\answered', $flags, true ) ? 1 : 0,
			);

			if ( (int) $row['is_read'] !== $want['is_read'] || (int) $row['is_starred'] !== $want['is_starred'] || (int) $row['is_answered'] !== $want['is_answered'] ) {
				MailRepository::update_message( (int) $row['id'], $want );
			}
		}

		return $removed;
	}

	/**
	 * Ist die Zeit des Durchgangs um?
	 *
	 * @return bool
	 */
	protected function out_of_time() {
		return microtime( true ) >= $this->deadline;
	}
}
