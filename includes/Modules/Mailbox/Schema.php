<?php
/**
 * Tabellen der Mailbox.
 *
 * - mail_accounts: ein Postfach (persönlich oder geteilt) mit den
 *   Zugangsdaten für IMAP und SMTP; das Passwort liegt verschlüsselt.
 * - mail_shares: wer ein Postfach außer dem Besitzer nutzen darf.
 * - mail_folders: Ordner je Postfach samt Abgleichstand: UIDVALIDITY, höchste
 *   geholte UID (last_uid) und – solange ältere Mails noch nachgeladen werden –
 *   die niedrigste geholte UID (backfill_uid, 0 = nichts mehr offen).
 * - mail_messages: der lokale Spiegel der Nachrichten. Entwürfe liegen hier
 *   mit folder = '__drafts' und ohne UID.
 * - mail_attachments: Anhänge; die Dateien liegen im geschützten Verzeichnis.
 * - mail_notes: interne Notizen zu einer Nachricht (nur im ERP sichtbar).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

/**
 * Liefert die CREATE-TABLE-Anweisungen für den dbDelta-Durchlauf.
 */
class Schema {

	/**
	 * Tabellendefinitionen.
	 *
	 * @param string $p               Präfix inklusive lw16_.
	 * @param string $charset_collate Zeichensatz der Installation.
	 * @return array
	 */
	public static function tables( $p, $charset_collate ) {
		$t = array();

		$t[] = "CREATE TABLE {$p}mail_accounts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			label VARCHAR(120) NOT NULL,
			kind VARCHAR(10) NOT NULL DEFAULT 'personal',
			owner_user_id BIGINT UNSIGNED NULL,
			email VARCHAR(190) NOT NULL,
			from_name VARCHAR(190) NOT NULL DEFAULT '',
			username VARCHAR(190) NOT NULL,
			password_enc TEXT NULL,
			imap_host VARCHAR(190) NOT NULL,
			imap_port SMALLINT UNSIGNED NOT NULL DEFAULT 993,
			imap_secure VARCHAR(10) NOT NULL DEFAULT 'ssl',
			smtp_host VARCHAR(190) NOT NULL,
			smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
			smtp_secure VARCHAR(10) NOT NULL DEFAULT 'tls',
			signature TEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			last_sync DATETIME NULL,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY owner_user_id (owner_user_id),
			KEY email (email)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}mail_shares (
			account_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			can_send TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (account_id, user_id),
			KEY user_id (user_id)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}mail_folders (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			role VARCHAR(10) NOT NULL DEFAULT 'other',
			delimiter VARCHAR(4) NOT NULL DEFAULT '/',
			uidvalidity BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,
			backfill_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_sync DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY account_name (account_id, name),
			KEY account_role (account_id, role)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}mail_messages (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			folder VARCHAR(190) NOT NULL,
			uid BIGINT UNSIGNED NULL,
			message_id VARCHAR(255) NULL,
			in_reply_to VARCHAR(255) NULL,
			thread_key VARCHAR(255) NULL,
			from_name VARCHAR(190) NOT NULL DEFAULT '',
			from_email VARCHAR(190) NOT NULL DEFAULT '',
			to_json TEXT NULL,
			cc_json TEXT NULL,
			subject VARCHAR(500) NOT NULL DEFAULT '',
			sent_at DATETIME NULL,
			snippet VARCHAR(255) NOT NULL DEFAULT '',
			body_text LONGTEXT NULL,
			body_html LONGTEXT NULL,
			has_attachments TINYINT(1) NOT NULL DEFAULT 0,
			is_read TINYINT(1) NOT NULL DEFAULT 0,
			is_starred TINYINT(1) NOT NULL DEFAULT 0,
			is_answered TINYINT(1) NOT NULL DEFAULT 0,
			size INT UNSIGNED NOT NULL DEFAULT 0,
			assigned_user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY account_folder_uid (account_id, folder, uid),
			KEY account_folder_sent (account_id, folder, sent_at),
			KEY thread_key (thread_key(191)),
			KEY message_id (message_id(191)),
			KEY from_email (from_email),
			KEY assigned_user_id (assigned_user_id)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}mail_attachments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			message_id BIGINT UNSIGNED NOT NULL,
			filename VARCHAR(190) NOT NULL,
			mime VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
			size INT UNSIGNED NOT NULL DEFAULT 0,
			content_id VARCHAR(190) NULL,
			inline TINYINT(1) NOT NULL DEFAULT 0,
			path VARCHAR(255) NOT NULL,
			PRIMARY KEY  (id),
			KEY message_id (message_id)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}mail_notes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			message_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			note TEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY message_id (message_id)
		) {$charset_collate};";

		return $t;
	}
}
