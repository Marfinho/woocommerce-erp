<?php
/**
 * Darstellung der Mailbox: Ordner, Liste, Lesefenster, Schreiben.
 *
 * Reine Ausgabe – alle schreibenden Aktionen laufen über die Handler in
 * `MailboxModule`. Jede Ausgabe wird escaped; fremde HTML-Mails erscheinen
 * ausschließlich in einem abgeschotteten iframe (siehe HtmlSanitizer).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;

/**
 * Seite „Mailbox".
 */
class MailboxView {

	const PER_PAGE = 30;

	/**
	 * Eingeloggter Nutzer.
	 *
	 * @var int
	 */
	protected $user_id = 0;

	/**
	 * Die Seite ausgeben.
	 */
	public function render() {
		$this->user_id = get_current_user_id();
		$is_manager    = MailboxAccess::is_manager();
		$accounts      = MailRepository::accounts_for_user( $this->user_id, $is_manager );

		echo '<div class="wrap lw16-wrap lw16-mb-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Mailbox', 'lindenweg16' ) . '</h1><hr class="wp-header-end">';
		$this->render_notice();

		if ( ! $accounts ) {
			echo '<div class="lw16-card"><p>' . esc_html__( 'Dir ist noch kein Postfach zugewiesen.', 'lindenweg16' ) . '</p>';
			if ( $is_manager ) {
				echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . AccountsPage::PAGE ) ) . '">' . esc_html__( 'Postfach einrichten', 'lindenweg16' ) . '</a></p>';
			} else {
				echo '<p>' . esc_html__( 'Bitte wende Dich an die Administration.', 'lindenweg16' ) . '</p>';
			}
			echo '</div></div>';
			return;
		}

		// Vorgabe: das eigene Postfach, sonst das erste.
		$default = $accounts[0];
		foreach ( $accounts as $a ) {
			if ( (int) $a['owner_user_id'] === $this->user_id ) {
				$default = $a;
				break;
			}
		}

		$by_id      = array_column( $accounts, null, 'id' );
		$account_id = isset( $_GET['account'] ) ? (int) $_GET['account'] : (int) $default['id']; // phpcs:ignore WordPress.Security.NonceVerification
		$account    = isset( $by_id[ $account_id ] ) ? $by_id[ $account_id ] : $default;
		$folders    = MailRepository::get_folders( (int) $account['id'] );
		$counts     = MailRepository::folder_counts( (int) $account['id'] );
		$can_send   = MailboxAccess::allows( $account['level'], 'send' );

		$inbox = '';
		foreach ( $folders as $f ) {
			if ( 'inbox' === $f['role'] ) {
				$inbox = $f['name'];
				break;
			}
		}
		if ( '' === $inbox && $folders ) {
			$inbox = (string) array_key_first( $folders );
		}

		$folder = isset( $_GET['folder'] ) ? (string) wp_unslash( $_GET['folder'] ) : $inbox; // Wird gegen die bekannten Ordner geprüft. // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $folder || ( MailRepository::DRAFTS !== $folder && ! isset( $folders[ $folder ] ) ) ) {
			$folder = $inbox;
		}

		$compose = isset( $_GET['compose'] ) ? sanitize_key( wp_unslash( $_GET['compose'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$msg_id  = isset( $_GET['msg'] ) ? (int) $_GET['msg'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$message = null;

		if ( $msg_id ) {
			$message = MailRepository::get_message( $msg_id );
			if ( ! $message || (int) $message['account_id'] !== (int) $account['id'] ) {
				$message = null;
			}
		}

		$has_pane = ( $compose && $can_send ) || $message;

		echo '<div class="lw16-mb' . ( $has_pane ? ' has-pane' : '' ) . '" data-account="' . (int) $account['id'] . '">';

		$this->render_sidebar( $accounts, $account, $folders, $counts, $folder, $can_send );

		echo '<section class="lw16-mb-list">';
		$this->render_list( $account, $folder, $message );
		echo '</section>';

		echo '<section class="lw16-mb-pane">';
		if ( $compose && $can_send ) {
			$this->render_compose( $account, $accounts, $compose );
		} elseif ( $message ) {
			$this->render_message( $account, $folder, $message, $folders );
		} else {
			echo '<div class="lw16-mb-empty"><span class="dashicons dashicons-email-alt"></span><p>' . esc_html__( 'Wähle links eine Mail aus.', 'lindenweg16' ) . '</p></div>';
		}
		echo '</section>';

		echo '</div></div>';
	}

	/* --------------------------------------------------------------------
	 * Bausteine
	 * ------------------------------------------------------------------ */

	/**
	 * Hinweis aus der letzten Aktion.
	 */
	protected function render_notice() {
		$key    = 'lw16_mail_notice_' . $this->user_id;
		$notice = get_transient( $key );

		if ( is_array( $notice ) ) {
			delete_transient( $key );
			$class = in_array( $notice['type'], array( 'success', 'error', 'warning' ), true ) ? $notice['type'] : 'info';
			echo '<div class="notice notice-' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['text'] ) . '</p></div>';
		}
	}

	/**
	 * Bezeichnung eines Ordners.
	 *
	 * @param string $name Name auf dem Server.
	 * @param string $role Rolle.
	 * @return string
	 */
	public static function folder_label( $name, $role ) {
		$labels = array(
			'inbox'   => __( 'Posteingang', 'lindenweg16' ),
			'sent'    => __( 'Gesendet', 'lindenweg16' ),
			'drafts'  => __( 'Entwürfe', 'lindenweg16' ),
			'trash'   => __( 'Papierkorb', 'lindenweg16' ),
			'spam'    => __( 'Spam', 'lindenweg16' ),
			'archive' => __( 'Archiv', 'lindenweg16' ),
		);

		if ( isset( $labels[ $role ] ) ) {
			return $labels[ $role ];
		}

		return (string) preg_replace( '#^.*[./\\\\]#u', '', $name );
	}

	/**
	 * Datum aus der Datenbank (UTC) für die Anzeige.
	 *
	 * @param string $mysql_utc Datum.
	 * @param bool   $long      Mit Uhrzeit und Jahr.
	 * @return string
	 */
	public static function fmt_date( $mysql_utc, $long = false ) {
		if ( empty( $mysql_utc ) ) {
			return '';
		}

		$ts = strtotime( $mysql_utc . ' UTC' );
		if ( ! $ts ) {
			return '';
		}

		if ( $long ) {
			return wp_date( 'd.m.Y H:i', $ts );
		}

		if ( wp_date( 'Y-m-d', $ts ) === wp_date( 'Y-m-d' ) ) {
			return wp_date( 'H:i', $ts );
		}

		return wp_date( wp_date( 'Y', $ts ) === wp_date( 'Y' ) ? 'd.m.' : 'd.m.Y', $ts );
	}

	/**
	 * Adressliste (JSON aus der Datenbank) als Text.
	 *
	 * @param string $json JSON.
	 * @return string
	 */
	public static function addr_text( $json ) {
		$out = array();

		foreach ( (array) json_decode( (string) $json, true ) as $a ) {
			if ( ! is_array( $a ) || empty( $a['email'] ) ) {
				continue;
			}
			$out[] = '' !== trim( (string) $a['name'] ) ? $a['name'] . ' <' . $a['email'] . '>' : $a['email'];
		}

		return implode( ', ', $out );
	}

	/**
	 * Linke Spalte: Postfächer, Ordner, Abrufen.
	 *
	 * @param array  $accounts Alle Postfächer des Nutzers.
	 * @param array  $account  Gewähltes Postfach.
	 * @param array  $folders  Ordner.
	 * @param array  $counts   Zähler.
	 * @param string $folder   Gewählter Ordner.
	 * @param bool   $can_send Ob schreiben erlaubt ist.
	 */
	protected function render_sidebar( array $accounts, array $account, array $folders, array $counts, $folder, $can_send ) {
		echo '<aside class="lw16-mb-side">';

		if ( count( $accounts ) > 1 ) {
			echo '<div class="lw16-mb-accounts">';
			foreach ( $accounts as $a ) {
				$unread = MailRepository::unread_inbox_total( array( (int) $a['id'] ) );
				echo '<a class="lw16-mb-account' . ( (int) $a['id'] === (int) $account['id'] ? ' is-active' : '' ) . '" href="' . esc_url( MailboxModule::url( array( 'account' => (int) $a['id'] ) ) ) . '">'
					. esc_html( $a['label'] )
					. ( $unread ? ' <span class="lw16-mb-count">' . (int) $unread . '</span>' : '' )
					. '</a>';
			}
			echo '</div>';
		} else {
			echo '<div class="lw16-mb-account-name"><strong>' . esc_html( $account['label'] ) . '</strong><br><span>' . esc_html( $account['email'] ) . '</span></div>';
		}

		if ( $can_send ) {
			echo '<a class="button button-primary lw16-mb-compose" href="' . esc_url( MailboxModule::url( array( 'account' => (int) $account['id'], 'compose' => 'new' ) ) ) . '">' . esc_html__( 'Neue Mail', 'lindenweg16' ) . '</a>';
		}

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
				continue; // Entwürfe führt die Mailbox lokal.
			}
			$rows[] = array(
				'name'  => $f['name'],
				'role'  => $f['role'],
				'label' => self::folder_label( $f['name'], $f['role'] ),
			);
		}
		$rows[] = array(
			'name'  => MailRepository::DRAFTS,
			'role'  => 'drafts',
			'label' => self::folder_label( '', 'drafts' ),
		);

		usort(
			$rows,
			static function ( $a, $b ) use ( $order ) {
				$ra = isset( $order[ $a['role'] ] ) ? $order[ $a['role'] ] : 3;
				$rb = isset( $order[ $b['role'] ] ) ? $order[ $b['role'] ] : 3;
				return $ra === $rb ? strcasecmp( $a['label'], $b['label'] ) : $ra - $rb;
			}
		);

		$icons = array(
			'inbox'   => 'dashicons-inbox',
			'drafts'  => 'dashicons-edit',
			'sent'    => 'dashicons-migrate',
			'trash'   => 'dashicons-trash',
			'spam'    => 'dashicons-shield',
			'archive' => 'dashicons-archive',
			'other'   => 'dashicons-category',
		);

		echo '<nav class="lw16-mb-folders">';
		foreach ( $rows as $r ) {
			$c      = isset( $counts[ $r['name'] ] ) ? $counts[ $r['name'] ] : array( 'total' => 0, 'unread' => 0 );
			$badge  = '';
			$unread = in_array( $r['role'], array( 'trash', 'spam', 'sent', 'drafts' ), true ) ? 0 : $c['unread'];
			if ( $unread ) {
				$badge = '<span class="lw16-mb-count">' . (int) $unread . '</span>';
			} elseif ( 'drafts' === $r['role'] && $c['total'] ) {
				$badge = '<span class="lw16-mb-count is-quiet">' . (int) $c['total'] . '</span>';
			}

			echo '<a class="lw16-mb-folder' . ( $r['name'] === $folder ? ' is-active' : '' ) . '" href="' . esc_url( MailboxModule::url( array( 'account' => (int) $account['id'], 'folder' => $r['name'] ) ) ) . '">'
				. '<span class="dashicons ' . esc_attr( isset( $icons[ $r['role'] ] ) ? $icons[ $r['role'] ] : $icons['other'] ) . '"></span>'
				. '<span class="lw16-mb-folder-name">' . esc_html( $r['label'] ) . '</span>' . $badge . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</nav>';

		echo '<div class="lw16-mb-sync">';
		if ( ! empty( $account['active'] ) ) {
			echo '<button type="button" class="button lw16-mb-sync-btn">' . esc_html__( 'Jetzt abrufen', 'lindenweg16' ) . '</button>';
		} else {
			echo '<p class="description">' . esc_html__( 'Postfach deaktiviert.', 'lindenweg16' ) . '</p>';
		}
		echo '<div class="lw16-mb-sync-status" aria-live="polite">';
		if ( ! empty( $account['last_error'] ) ) {
			echo '<span class="lw16-mb-error">' . esc_html( $account['last_error'] ) . '</span>';
		} elseif ( ! empty( $account['last_sync'] ) ) {
			echo esc_html( sprintf( /* translators: %s: Zeitangabe */ __( 'Zuletzt abgerufen: %s', 'lindenweg16' ), self::fmt_date( get_gmt_from_date( $account['last_sync'] ), true ) ) );
		} else {
			echo esc_html__( 'Noch nicht abgerufen.', 'lindenweg16' );
		}
		echo '</div></div>';

		echo '</aside>';
	}

	/**
	 * Mailliste.
	 *
	 * @param array      $account Postfach.
	 * @param string     $folder  Ordner.
	 * @param array|null $current Geöffnete Mail.
	 */
	protected function render_list( array $account, $folder, $current ) {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
		$filter = in_array( $filter, array( 'all', 'unread', 'starred', 'mine' ), true ) ? $filter : 'all';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification

		$base = array(
			'account' => (int) $account['id'],
			'folder'  => $folder,
		);

		echo '<form method="get" class="lw16-mb-search"><input type="hidden" name="page" value="' . esc_attr( MailboxModule::PAGE ) . '">'
			. '<input type="hidden" name="account" value="' . (int) $account['id'] . '"><input type="hidden" name="folder" value="' . esc_attr( $folder ) . '">'
			. '<input type="hidden" name="filter" value="' . esc_attr( $filter ) . '">'
			. '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Suchen …', 'lindenweg16' ) . '"></form>';

		echo '<div class="lw16-mb-filters">';
		foreach ( array(
			'all'     => __( 'Alle', 'lindenweg16' ),
			'unread'  => __( 'Ungelesen', 'lindenweg16' ),
			'starred' => __( 'Markiert', 'lindenweg16' ),
			'mine'    => __( 'Mir zugewiesen', 'lindenweg16' ),
		) as $key => $label ) {
			echo '<a class="' . ( $key === $filter ? 'is-active' : '' ) . '" href="' . esc_url( MailboxModule::url( array_merge( $base, array( 'filter' => $key, 's' => $search ) ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</div>';

		$list = MailRepository::list_messages(
			(int) $account['id'],
			$folder,
			array(
				'page'     => $paged,
				'per_page' => self::PER_PAGE,
				'search'   => $search,
				'filter'   => $filter,
				'user_id'  => $this->user_id,
			)
		);

		if ( ! $list['rows'] ) {
			echo '<p class="lw16-mb-none">' . esc_html( '' !== $search ? __( 'Nichts gefunden.', 'lindenweg16' ) : __( 'Keine Mails.', 'lindenweg16' ) ) . '</p>';
			return;
		}

		$is_out = MailRepository::DRAFTS === $folder;
		$folders = MailRepository::get_folders( (int) $account['id'] );
		$is_out  = $is_out || ( isset( $folders[ $folder ] ) && 'sent' === $folders[ $folder ]['role'] );

		echo '<ul class="lw16-mb-rows">';
		foreach ( $list['rows'] as $row ) {
			$active = $current && (int) $current['id'] === (int) $row['id'];

			if ( MailRepository::DRAFTS === $folder ) {
				$to  = (array) json_decode( (string) $row['to_json'], true );
				$who = isset( $to['raw'] ) && '' !== $to['raw'] ? $to['raw'] : __( '(kein Empfänger)', 'lindenweg16' );
				$url = MailboxModule::url( array( 'account' => (int) $account['id'], 'compose' => 'draft', 'ref' => (int) $row['id'] ) );
			} else {
				$who = $is_out ? self::addr_text( $row['to_json'] ) : ( '' !== trim( (string) $row['from_name'] ) ? $row['from_name'] : $row['from_email'] );
				$url = MailboxModule::url( array_merge( $base, array( 'msg' => (int) $row['id'], 'filter' => $filter, 's' => $search, 'paged' => $paged ) ) );
			}

			$star = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'lw16_mail_action',
						'id'      => (int) $row['id'],
						'do'      => $row['is_starred'] ? 'unstar' : 'star',
						'back'    => 'list',
					),
					admin_url( 'admin-post.php' )
				),
				'lw16_mail_' . (int) $row['id']
			);

			echo '<li class="lw16-mb-row' . ( $row['is_read'] ? '' : ' is-unread' ) . ( $active ? ' is-current' : '' ) . '">';
			if ( MailRepository::DRAFTS !== $folder && ! empty( $account['level'] ) ) {
				echo '<a class="lw16-mb-star' . ( $row['is_starred'] ? ' is-on' : '' ) . '" href="' . esc_url( $star ) . '" title="' . esc_attr__( 'Markieren', 'lindenweg16' ) . '"><span class="dashicons ' . ( $row['is_starred'] ? 'dashicons-star-filled' : 'dashicons-star-empty' ) . '"></span></a>';
			}
			echo '<a class="lw16-mb-row-link" href="' . esc_url( $url ) . '">'
				. '<span class="lw16-mb-row-top"><span class="lw16-mb-who">' . esc_html( $who ) . '</span><span class="lw16-mb-date">' . esc_html( self::fmt_date( $row['sent_at'] ) ) . '</span></span>'
				. '<span class="lw16-mb-subject">' . esc_html( '' !== $row['subject'] ? $row['subject'] : __( '(ohne Betreff)', 'lindenweg16' ) )
				. ( $row['has_attachments'] ? ' <span class="dashicons dashicons-paperclip" title="' . esc_attr__( 'Anhang', 'lindenweg16' ) . '"></span>' : '' )
				. ( $row['is_answered'] ? ' <span class="dashicons dashicons-undo" title="' . esc_attr__( 'Beantwortet', 'lindenweg16' ) . '"></span>' : '' )
				. '</span>'
				. '<span class="lw16-mb-snippet">' . esc_html( $row['snippet'] ) . '</span>'
				. '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>';

		$pages = (int) ceil( $list['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', MailboxModule::url( array_merge( $base, array( 'filter' => $filter, 's' => $search ) ) ) ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '‹',
						'next_text' => '›',
					)
				)
			) . '</div></div>';
		}
	}

	/**
	 * Lesefenster.
	 *
	 * @param array  $account Postfach.
	 * @param string $folder  Ordner.
	 * @param array  $m       Nachricht.
	 * @param array  $folders Ordner des Postfachs.
	 */
	protected function render_message( array $account, $folder, array $m, array $folders ) {
		$id       = (int) $m['id'];
		$can_send = MailboxAccess::allows( $account['level'], 'send' );
		$back     = MailboxModule::url( array( 'account' => (int) $account['id'], 'folder' => $folder ) );

		// Fremdzugriff protokollieren: Wer ein Postfach liest, das ihm nicht gehört.
		if ( 'personal' === $account['kind'] && (int) $account['owner_user_id'] !== $this->user_id ) {
			AuditLog::record( 'mail_gelesen', 'mail_message', $id, array( 'postfach' => $account['email'] ) );
		}

		if ( ! $m['is_read'] ) {
			MailboxModule::mark_read( $account, $m );
			$m['is_read'] = 1;
		}

		$act = static function ( $do, array $extra = array() ) use ( $id ) {
			return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'lw16_mail_action', 'id' => $id, 'do' => $do ), $extra ), admin_url( 'admin-post.php' ) ), 'lw16_mail_' . $id );
		};

		$attachments = MailRepository::attachments( $id );
		$subject     = '' !== $m['subject'] ? $m['subject'] : __( '(ohne Betreff)', 'lindenweg16' );

		echo '<article class="lw16-mb-message">';
		echo '<a class="lw16-mb-back" href="' . esc_url( $back ) . '">‹ ' . esc_html__( 'Zurück zur Liste', 'lindenweg16' ) . '</a>';
		echo '<h2>' . esc_html( $subject ) . '</h2>';

		echo '<div class="lw16-mb-toolbar">';
		if ( $can_send ) {
			foreach ( array(
				'reply'    => __( 'Antworten', 'lindenweg16' ),
				'replyall' => __( 'Allen antworten', 'lindenweg16' ),
				'forward'  => __( 'Weiterleiten', 'lindenweg16' ),
			) as $mode => $label ) {
				echo '<a class="button' . ( 'reply' === $mode ? ' button-primary' : '' ) . '" href="' . esc_url( MailboxModule::url( array( 'account' => (int) $account['id'], 'compose' => $mode, 'ref' => $id ) ) ) . '">' . esc_html( $label ) . '</a> ';
			}
		}
		echo '<a class="button" href="' . esc_url( $act( 'unread' ) ) . '">' . esc_html__( 'Ungelesen', 'lindenweg16' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $act( $m['is_starred'] ? 'unstar' : 'star' ) ) . '">' . esc_html( $m['is_starred'] ? __( 'Markierung entfernen', 'lindenweg16' ) : __( 'Markieren', 'lindenweg16' ) ) . '</a> ';

		if ( $can_send ) {
			$role = isset( $folders[ $folder ] ) ? $folders[ $folder ]['role'] : '';
			if ( 'spam' === $role ) {
				echo '<a class="button" href="' . esc_url( $act( 'notspam' ) ) . '">' . esc_html__( 'Kein Spam', 'lindenweg16' ) . '</a> ';
			} else {
				echo '<a class="button" href="' . esc_url( $act( 'spam' ) ) . '">' . esc_html__( 'Spam', 'lindenweg16' ) . '</a> ';
			}
			echo '<a class="button lw16-mb-delete" data-confirm="' . esc_attr( 'trash' === $role ? __( 'Endgültig löschen?', 'lindenweg16' ) : '' ) . '" href="' . esc_url( $act( 'trash' ) ) . '">' . esc_html( 'trash' === $role ? __( 'Endgültig löschen', 'lindenweg16' ) : __( 'Löschen', 'lindenweg16' ) ) . '</a> ';

			// Verschieben.
			echo '<form class="lw16-mb-move" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'lw16_mail_' . $id );
			echo '<input type="hidden" name="action" value="lw16_mail_action"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="do" value="move">';
			echo '<select name="dest"><option value="">' . esc_html__( 'Verschieben nach …', 'lindenweg16' ) . '</option>';
			foreach ( $folders as $f ) {
				if ( $f['name'] !== $folder && 'drafts' !== $f['role'] ) {
					echo '<option value="' . esc_attr( $f['name'] ) . '">' . esc_html( self::folder_label( $f['name'], $f['role'] ) ) . '</option>';
				}
			}
			echo '</select> <button class="button">' . esc_html__( 'OK', 'lindenweg16' ) . '</button></form>';
		}
		echo '</div>';

		// Kopf.
		echo '<dl class="lw16-mb-head">';
		echo '<dt>' . esc_html__( 'Von', 'lindenweg16' ) . '</dt><dd>' . esc_html( '' !== trim( (string) $m['from_name'] ) ? $m['from_name'] . ' <' . $m['from_email'] . '>' : $m['from_email'] ) . '</dd>';
		echo '<dt>' . esc_html__( 'An', 'lindenweg16' ) . '</dt><dd>' . esc_html( self::addr_text( $m['to_json'] ) ) . '</dd>';
		$cc = self::addr_text( $m['cc_json'] );
		if ( '' !== $cc ) {
			echo '<dt>' . esc_html__( 'Cc', 'lindenweg16' ) . '</dt><dd>' . esc_html( $cc ) . '</dd>';
		}
		echo '<dt>' . esc_html__( 'Datum', 'lindenweg16' ) . '</dt><dd>' . esc_html( self::fmt_date( $m['sent_at'], true ) ) . '</dd>';
		echo '</dl>';

		$this->render_body( $m, $attachments );

		if ( $attachments ) {
			echo '<div class="lw16-mb-attachments"><strong>' . esc_html__( 'Anhänge', 'lindenweg16' ) . '</strong><ul>';
			foreach ( $attachments as $a ) {
				if ( $a['inline'] && '' !== (string) $a['content_id'] ) {
					continue;
				}
				$url = wp_nonce_url( add_query_arg( array( 'action' => 'lw16_mail_attachment', 'id' => (int) $a['id'] ), admin_url( 'admin-post.php' ) ), 'lw16_mail_att_' . (int) $a['id'] );
				echo '<li><span class="dashicons dashicons-paperclip"></span> <a href="' . esc_url( $url ) . '">' . esc_html( $a['filename'] ) . '</a> <span class="description">(' . esc_html( size_format( (int) $a['size'] ) ) . ')</span></li>';
			}
			echo '</ul></div>';
		}

		$this->render_meta( $account, $m, $folder );

		echo '</article>';
	}

	/**
	 * Text der Mail: HTML im abgeschotteten Rahmen, sonst Klartext.
	 *
	 * @param array $m           Nachricht.
	 * @param array $attachments Anhänge (für eingebettete Bilder).
	 */
	protected function render_body( array $m, array $attachments ) {
		$html = (string) $m['body_html'];

		if ( '' === trim( $html ) ) {
			echo '<div class="lw16-mb-plain">' . HtmlSanitizer::text_to_html( (string) $m['body_text'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}

		$images = ! empty( $_GET['images'] ); // phpcs:ignore WordPress.Security.NonceVerification

		// Eingebettete Bilder (cid:) aus den gespeicherten Anhängen.
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

		$has_remote = (bool) preg_match( '#<img[^>]+src\s*=\s*["\']?https?://#i', $html ) || (bool) preg_match( '#url\(\s*["\']?https?://#i', $html );

		if ( $has_remote && ! $images ) {
			$load = add_query_arg( 'images', 1 );
			echo '<div class="lw16-mb-imgnote">' . esc_html__( 'Externe Bilder wurden blockiert, damit der Absender nicht sieht, dass Du die Mail gelesen hast.', 'lindenweg16' ) . ' <a href="' . esc_url( $load ) . '">' . esc_html__( 'Bilder laden', 'lindenweg16' ) . '</a></div>';
		}

		echo '<iframe class="lw16-mb-frame" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" srcdoc="' . esc_attr( HtmlSanitizer::document( $html, $images, $cid ) ) . '"></iframe>';
	}

	/**
	 * Zuweisung, Notizen, Verlauf, Kundendaten unter der Mail.
	 *
	 * @param array  $account Postfach.
	 * @param array  $m       Nachricht.
	 * @param string $folder  Ordner.
	 */
	protected function render_meta( array $account, array $m, $folder ) {
		$id = (int) $m['id'];

		echo '<div class="lw16-mb-meta">';

		// Zuweisung.
		$users = $this->users_with_access( $account );
		if ( count( $users ) > 1 || 'shared' === $account['kind'] ) {
			echo '<form class="lw16-mb-assign" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'lw16_mail_' . $id );
			echo '<input type="hidden" name="action" value="lw16_mail_action"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="do" value="assign">';
			echo '<label>' . esc_html__( 'Zugewiesen an', 'lindenweg16' ) . ' <select name="assign_to"><option value="0">' . esc_html__( '– niemand –', 'lindenweg16' ) . '</option>';
			foreach ( $users as $uid => $name ) {
				echo '<option value="' . (int) $uid . '"' . selected( (int) $m['assigned_user_id'], (int) $uid, false ) . '>' . esc_html( $name ) . '</option>';
			}
			echo '</select></label> <button class="button">' . esc_html__( 'Speichern', 'lindenweg16' ) . '</button></form>';
		}

		// Kunde und Bestellungen.
		$this->render_customer( $m );

		// Verlauf.
		$thread = MailRepository::thread_messages( (int) $account['id'], (string) $m['thread_key'], $id );
		if ( $thread ) {
			echo '<div class="lw16-mb-thread"><strong>' . esc_html__( 'Verlauf', 'lindenweg16' ) . '</strong><ul>';
			foreach ( $thread as $t ) {
				echo '<li><a href="' . esc_url( MailboxModule::url( array( 'account' => (int) $account['id'], 'folder' => $t['folder'], 'msg' => (int) $t['id'] ) ) ) . '">'
					. esc_html( self::fmt_date( $t['sent_at'], true ) . ' · ' . ( '' !== trim( (string) $t['from_name'] ) ? $t['from_name'] : $t['from_email'] ) ) . '</a>'
					. '<span class="description"> – ' . esc_html( wp_trim_words( (string) $t['snippet'], 12 ) ) . '</span></li>';
			}
			echo '</ul></div>';
		}

		// Notizen.
		echo '<div class="lw16-mb-notes"><strong>' . esc_html__( 'Interne Notizen', 'lindenweg16' ) . '</strong> <span class="description">' . esc_html__( '(nur im ERP sichtbar)', 'lindenweg16' ) . '</span>';
		foreach ( MailRepository::notes( $id ) as $n ) {
			$u = get_userdata( (int) $n['user_id'] );
			echo '<div class="lw16-mb-note"><span class="description">' . esc_html( ( $u ? $u->display_name : '#' . (int) $n['user_id'] ) . ' · ' . self::fmt_date( get_gmt_from_date( $n['created_at'] ), true ) ) . '</span><br>' . nl2br( esc_html( $n['note'] ) ) . '</div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lw16_mail_note_' . $id );
		echo '<input type="hidden" name="action" value="lw16_mail_note"><input type="hidden" name="id" value="' . $id . '">'
			. '<textarea name="note" rows="2" placeholder="' . esc_attr__( 'Notiz für das Team …', 'lindenweg16' ) . '"></textarea> <button class="button">' . esc_html__( 'Notiz speichern', 'lindenweg16' ) . '</button></form></div>';

		echo '</div>';
	}

	/**
	 * Nutzer, die das Postfach sehen dürfen.
	 *
	 * @param array $account Postfach.
	 * @return array user_id => Name
	 */
	protected function users_with_access( array $account ) {
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

		return $out;
	}

	/**
	 * Kunde und Bestellungen zur Absenderadresse.
	 *
	 * @param array $m Nachricht.
	 */
	protected function render_customer( array $m ) {
		$info = OrderMailsBox::customer_info( (string) $m['from_email'], (string) $m['subject'] );

		if ( ! $info ) {
			return;
		}

		echo '<div class="lw16-mb-customer"><strong>' . esc_html__( 'Im Shop', 'lindenweg16' ) . '</strong><ul>';
		foreach ( $info as $line ) {
			echo '<li>' . ( $line['url'] ? '<a href="' . esc_url( $line['url'] ) . '">' . esc_html( $line['text'] ) . '</a>' : esc_html( $line['text'] ) ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Formular zum Schreiben, Antworten, Weiterleiten, Entwurf.
	 *
	 * @param array      $account  Postfach.
	 * @param array      $accounts Alle Postfächer des Nutzers.
	 * @param string     $mode     new, reply, replyall, forward, draft.
	 */
	protected function render_compose( array $account, array $accounts, $mode ) {
		$ref_id = isset( $_GET['ref'] ) ? (int) $_GET['ref'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$ref    = $ref_id ? MailRepository::get_message( $ref_id ) : null;
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
					'to'          => isset( $to['raw'] ) ? $to['raw'] : '',
					'cc'          => isset( $cc['cc'] ) ? $cc['cc'] : '',
					'bcc'         => isset( $cc['bcc'] ) ? $cc['bcc'] : '',
					'subject'     => $ref['subject'],
					'body'        => $ref['body_text'],
					'reply_to_id' => (int) $ref['in_reply_to'],
					'draft_id'    => (int) $ref['id'],
				)
			);
		} elseif ( $ref && in_array( $mode, array( 'reply', 'replyall', 'forward' ), true ) ) {
			$date = self::fmt_date( $ref['sent_at'], true );

			if ( 'forward' === $mode ) {
				$vals['subject'] = ComposeService::prefix_subject( $ref['subject'], 'Fwd' );
				$vals['body']    = "\n\n---------- " . __( 'Weitergeleitete Nachricht', 'lindenweg16' ) . " ----------\n"
					. __( 'Von', 'lindenweg16' ) . ': ' . ( '' !== trim( (string) $ref['from_name'] ) ? $ref['from_name'] . ' <' . $ref['from_email'] . '>' : $ref['from_email'] ) . "\n"
					. __( 'Datum', 'lindenweg16' ) . ': ' . $date . "\n"
					. __( 'Betreff', 'lindenweg16' ) . ': ' . $ref['subject'] . "\n"
					. __( 'An', 'lindenweg16' ) . ': ' . self::addr_text( $ref['to_json'] ) . "\n\n"
					. $ref['body_text'];
			} else {
				$reply_to        = '';
				$vals['subject'] = ComposeService::prefix_subject( $ref['subject'], 'Re' );
				$rec             = ComposeService::reply_recipients( $ref, 'replyall' === $mode, $account['email'], $reply_to );
				$vals['to']      = $rec['to'];
				$vals['cc']      = $rec['cc'];
				$vals['body']    = "\n\n" . ComposeService::quote( $ref, $date );
				$vals['reply_to_id'] = (int) $ref['id'];
			}
		} else {
			// Neue Mail, ggf. aus dem Shop vorbelegt (Adresse, Betreff).
			foreach ( array( 'to', 'subject', 'body' ) as $key ) {
				if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					$vals[ $key ] = ComposeService::clean_text( wp_unslash( $_GET[ $key ] ), 'body' === $key ); // phpcs:ignore WordPress.Security.NonceVerification
				}
			}
		}

		$sender = array();
		foreach ( $accounts as $a ) {
			if ( MailboxAccess::allows( $a['level'], 'send' ) && ! empty( $a['active'] ) ) {
				$sender[] = $a;
			}
		}

		$user = wp_get_current_user();
		$sig  = ComposeService::render_signature( (string) $account['signature'], $user->display_name );

		echo '<form class="lw16-mb-compose-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lw16_mail_send' );
		echo '<input type="hidden" name="action" value="lw16_mail_send">'
			. '<input type="hidden" name="reply_to_id" value="' . (int) $vals['reply_to_id'] . '">'
			. '<input type="hidden" name="draft_id" value="' . (int) $vals['draft_id'] . '">';

		echo '<h2>' . esc_html( 'forward' === $mode ? __( 'Weiterleiten', 'lindenweg16' ) : ( in_array( $mode, array( 'reply', 'replyall' ), true ) ? __( 'Antworten', 'lindenweg16' ) : __( 'Neue Mail', 'lindenweg16' ) ) ) . '</h2>';

		echo '<table class="form-table lw16-mb-fields" role="presentation">';
		echo '<tr><th><label for="lw16-mb-from">' . esc_html__( 'Von', 'lindenweg16' ) . '</label></th><td>';
		if ( count( $sender ) > 1 ) {
			echo '<select name="account" id="lw16-mb-from">';
			foreach ( $sender as $a ) {
				echo '<option value="' . (int) $a['id'] . '"' . selected( (int) $a['id'], (int) $account['id'], false ) . '>' . esc_html( $a['label'] . ' <' . $a['email'] . '>' ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input type="hidden" name="account" value="' . (int) $account['id'] . '"><span id="lw16-mb-from">' . esc_html( ( '' !== $account['from_name'] ? $account['from_name'] . ' ' : '' ) . '<' . $account['email'] . '>' ) . '</span>';
		}
		echo '</td></tr>';

		echo '<tr><th><label for="lw16-mb-to">' . esc_html__( 'An', 'lindenweg16' ) . '</label></th><td><input type="text" class="large-text" name="to" id="lw16-mb-to" value="' . esc_attr( $vals['to'] ) . '" placeholder="name@example.de, …" autocomplete="off">'
			. ' <a href="#" class="lw16-mb-showcc">' . esc_html__( 'Cc/Bcc', 'lindenweg16' ) . '</a></td></tr>';
		$show_cc = '' !== $vals['cc'] || '' !== $vals['bcc'];
		echo '<tr class="lw16-mb-cc"' . ( $show_cc ? '' : ' hidden' ) . '><th><label for="lw16-mb-cc">' . esc_html__( 'Cc', 'lindenweg16' ) . '</label></th><td><input type="text" class="large-text" name="cc" id="lw16-mb-cc" value="' . esc_attr( $vals['cc'] ) . '"></td></tr>';
		echo '<tr class="lw16-mb-cc"' . ( $show_cc ? '' : ' hidden' ) . '><th><label for="lw16-mb-bcc">' . esc_html__( 'Bcc', 'lindenweg16' ) . '</label></th><td><input type="text" class="large-text" name="bcc" id="lw16-mb-bcc" value="' . esc_attr( $vals['bcc'] ) . '"></td></tr>';
		echo '<tr><th><label for="lw16-mb-subject">' . esc_html__( 'Betreff', 'lindenweg16' ) . '</label></th><td><input type="text" class="large-text" name="subject" id="lw16-mb-subject" value="' . esc_attr( $vals['subject'] ) . '"></td></tr>';
		echo '</table>';

		echo '<textarea name="body" id="lw16-mb-body" rows="16" class="large-text" placeholder="' . esc_attr__( 'Deine Nachricht …', 'lindenweg16' ) . '">' . esc_textarea( $vals['body'] ) . '</textarea>';

		echo '<p class="description lw16-mb-hint">' . esc_html__( 'Die Mail geht im Layout der Shop-Mails raus (Logo, Farben, Fußzeile). Ein Link allein in einer Zeile wird zum Button.', 'lindenweg16' ) . '</p>';
		if ( '' !== $sig ) {
			echo '<div class="lw16-mb-sig"><span class="description">' . esc_html__( 'Signatur (wird automatisch unter Deinen Text gesetzt):', 'lindenweg16' ) . '</span><pre>' . esc_html( $sig ) . '</pre></div>';
		}

		echo '<p><label>' . esc_html__( 'Anhänge', 'lindenweg16' ) . ' <input type="file" name="attachments[]" multiple></label></p>';

		echo '<p class="lw16-mb-actions"><button type="submit" class="button button-primary button-large lw16-mb-send">' . esc_html__( 'Senden', 'lindenweg16' ) . '</button> '
			. '<button type="submit" name="save_draft" value="1" class="button button-large">' . esc_html__( 'Als Entwurf speichern', 'lindenweg16' ) . '</button> '
			. '<button type="button" class="button button-large lw16-mb-preview-btn">' . esc_html__( 'Vorschau', 'lindenweg16' ) . '</button> ';
		if ( $vals['draft_id'] ) {
			$del = wp_nonce_url( add_query_arg( array( 'action' => 'lw16_mail_action', 'id' => (int) $vals['draft_id'], 'do' => 'trash', 'back' => 'list' ), admin_url( 'admin-post.php' ) ), 'lw16_mail_' . (int) $vals['draft_id'] );
			echo '<a class="button button-large" href="' . esc_url( $del ) . '">' . esc_html__( 'Entwurf verwerfen', 'lindenweg16' ) . '</a>';
		}
		echo '</p></form>';

		echo '<div class="lw16-mb-preview" hidden><div class="lw16-mb-preview-bar"><strong>' . esc_html__( 'Vorschau', 'lindenweg16' ) . '</strong> <button type="button" class="button lw16-mb-preview-close">' . esc_html__( 'Schließen', 'lindenweg16' ) . '</button></div>'
			. '<iframe class="lw16-mb-preview-frame" sandbox="allow-popups"></iframe></div>';
	}
}
