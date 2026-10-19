<?php
/**
 * Postfächer einrichten: Zugangsdaten, Signatur, Zugriff, Verbindungstest.
 *
 * Nur für Verwalter (`manage_lw16` oder `lw16_mail_manage`). Die Vorgaben
 * sind für IONOS gesetzt: IMAP imap.ionos.de:993 (SSL), SMTP smtp.ionos.de:587
 * (STARTTLS). Als Benutzername dient bei IONOS die vollständige
 * E-Mail-Adresse.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Settings;

/**
 * Verwaltungsseite der Postfächer.
 */
class AccountsPage {

	const PAGE = 'lw16-mailbox-konten';

	/**
	 * Vorgaben für IONOS.
	 *
	 * @return array
	 */
	public static function ionos_defaults() {
		return array(
			'imap_host'   => 'imap.ionos.de',
			'imap_port'   => 993,
			'imap_secure' => 'ssl',
			'smtp_host'   => 'smtp.ionos.de',
			'smtp_port'   => 587,
			'smtp_secure' => 'tls',
		);
	}

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'admin_post_lw16_mail_account_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_lw16_mail_account_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_lw16_mail_settings_save', array( $this, 'handle_settings' ) );
		add_action( 'wp_ajax_lw16_mail_account_test', array( $this, 'ajax_test' ) );
	}

	/**
	 * Verwalter-Prüfung aller Handler.
	 *
	 * @param string $nonce_action Nonce.
	 * @param bool   $ajax         AJAX-Antwort.
	 */
	protected function guard( $nonce_action, $ajax = false ) {
		if ( ! current_user_can( LW16_CAPABILITY ) && ! current_user_can( MailboxAccess::CAP_MANAGE ) ) {
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
	 * Adresse der Seite.
	 *
	 * @param array $args Parameter.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/* --------------------------------------------------------------------
	 * Seite
	 * ------------------------------------------------------------------ */

	/**
	 * Die Seite.
	 */
	public function render_page() {
		if ( ! MailboxAccess::is_manager() ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}

		echo '<div class="wrap lw16-wrap lw16-mb-admin"><h1 class="wp-heading-inline">' . esc_html__( 'Postfächer', 'lindenweg16' ) . '</h1>';

		$edit = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( '' === $edit ) {
			echo ' <a class="page-title-action" href="' . esc_url( self::url( array( 'edit' => 'new' ) ) ) . '">' . esc_html__( 'Postfach hinzufügen', 'lindenweg16' ) . '</a>';
		}
		echo '<hr class="wp-header-end">';

		$key    = 'lw16_mail_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			echo '<div class="notice notice-' . esc_attr( in_array( $notice['type'], array( 'success', 'error', 'warning' ), true ) ? $notice['type'] : 'info' ) . ' is-dismissible"><p>' . esc_html( $notice['text'] ) . '</p></div>';
		}

		if ( ! MailCrypto::available() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Auf diesem Server fehlt libsodium – ohne sie können keine Passwörter gespeichert werden.', 'lindenweg16' ) . '</p></div>';
		}

		if ( '' !== $edit ) {
			$this->render_form( 'new' === $edit ? null : MailRepository::get_account( (int) $edit ) );
		} else {
			$this->render_list();
			$this->render_settings();
		}

		echo '</div>';
	}

	/**
	 * Liste der Postfächer.
	 */
	protected function render_list() {
		$accounts = MailRepository::all_accounts();

		if ( ! $accounts ) {
			echo '<div class="lw16-card"><p>' . esc_html__( 'Noch kein Postfach eingerichtet. Lege für jeden Mitarbeiter ein Postfach an – bei IONOS reicht dafür die E-Mail-Adresse und das Passwort des Postfachs.', 'lindenweg16' ) . '</p>'
				. '<p><a class="button button-primary" href="' . esc_url( self::url( array( 'edit' => 'new' ) ) ) . '">' . esc_html__( 'Erstes Postfach hinzufügen', 'lindenweg16' ) . '</a></p></div>';
			return;
		}

		echo '<table class="widefat striped lw16-mb-accounts-table"><thead><tr>'
			. '<th>' . esc_html__( 'Postfach', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Typ', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Zugriff', 'lindenweg16' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $accounts as $a ) {
			$names = array();
			if ( ! empty( $a['owner_user_id'] ) ) {
				$u = get_userdata( (int) $a['owner_user_id'] );
				if ( $u ) {
					$names[] = $u->display_name;
				}
			}
			foreach ( MailRepository::get_shares( (int) $a['id'] ) as $uid => $can_send ) {
				$u = get_userdata( $uid );
				if ( $u ) {
					$names[] = $u->display_name . ( $can_send ? '' : ' (' . __( 'nur lesen', 'lindenweg16' ) . ')' );
				}
			}

			if ( null === MailCrypto::decrypt( (string) $a['password_enc'] ) ) {
				$status = '<span class="lw16-mb-error">' . esc_html__( 'Passwort nicht lesbar – bitte neu eintragen', 'lindenweg16' ) . '</span>';
			} elseif ( ! $a['active'] ) {
				$status = esc_html__( 'Deaktiviert', 'lindenweg16' );
			} elseif ( ! empty( $a['last_error'] ) ) {
				$status = '<span class="lw16-mb-error">' . esc_html( $a['last_error'] ) . '</span>';
			} elseif ( ! empty( $a['last_sync'] ) ) {
				$status = esc_html( sprintf( /* translators: %s: Zeitangabe */ __( 'OK, zuletzt %s', 'lindenweg16' ), MailboxView::fmt_date( get_gmt_from_date( $a['last_sync'] ), true ) ) );
			} else {
				$status = esc_html__( 'Noch nicht abgerufen', 'lindenweg16' );
			}

			$delete = wp_nonce_url( add_query_arg( array( 'action' => 'lw16_mail_account_delete', 'id' => (int) $a['id'] ), admin_url( 'admin-post.php' ) ), 'lw16_mail_account_delete_' . (int) $a['id'] );

			echo '<tr><td><strong>' . esc_html( $a['label'] ) . '</strong><br><span class="description">' . esc_html( $a['email'] ) . '</span></td>'
				. '<td>' . esc_html( 'shared' === $a['kind'] ? __( 'Geteilt', 'lindenweg16' ) : __( 'Persönlich', 'lindenweg16' ) ) . '</td>'
				. '<td>' . esc_html( $names ? implode( ', ', $names ) : '–' ) . '</td>'
				. '<td>' . $status . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<td><a class="button" href="' . esc_url( self::url( array( 'edit' => (int) $a['id'] ) ) ) . '">' . esc_html__( 'Bearbeiten', 'lindenweg16' ) . '</a> '
				. '<a class="button lw16-mb-confirm" data-confirm="' . esc_attr__( 'Postfach samt aller lokal gespeicherten Mails löschen? Auf dem Mailserver bleibt alles erhalten.', 'lindenweg16' ) . '" href="' . esc_url( $delete ) . '">' . esc_html__( 'Löschen', 'lindenweg16' ) . '</a></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Allgemeine Einstellungen der Mailbox.
	 */
	protected function render_settings() {
		echo '<h2>' . esc_html__( 'Einstellungen', 'lindenweg16' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-card">';
		wp_nonce_field( 'lw16_mail_settings_save' );
		echo '<input type="hidden" name="action" value="lw16_mail_settings_save"><table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Automatisch abrufen', 'lindenweg16' ) . '</th><td><label><input type="checkbox" name="mail_sync_aktiv" value="1"' . checked( (int) Settings::get( 'mail_sync_aktiv', 1 ), 1, false ) . '> ' . esc_html__( 'Alle fünf Minuten im Hintergrund', 'lindenweg16' ) . '</label></td></tr>';
		echo '<tr><th><label for="mail_lookback_tage">' . esc_html__( 'Wie weit zurück laden', 'lindenweg16' ) . '</label></th><td><input type="number" min="1" max="3650" id="mail_lookback_tage" name="mail_lookback_tage" value="' . (int) Settings::get( 'mail_lookback_tage', 90 ) . '" class="small-text"> ' . esc_html__( 'Tage (beim ersten Abruf eines Ordners; ältere Mails bleiben auf dem Server)', 'lindenweg16' ) . '</td></tr>';
		echo '<tr><th><label for="mail_max_anhang_mb">' . esc_html__( 'Anhänge je Mail', 'lindenweg16' ) . '</label></th><td><input type="number" min="1" max="100" id="mail_max_anhang_mb" name="mail_max_anhang_mb" value="' . (int) Settings::get( 'mail_max_anhang_mb', 20 ) . '" class="small-text"> ' . esc_html__( 'MB höchstens (IONOS erlaubt insgesamt 50 MB je Mail)', 'lindenweg16' ) . '</td></tr>';
		echo '</table><p><button class="button button-primary">' . esc_html__( 'Speichern', 'lindenweg16' ) . '</button></p></form>';

		echo '<div class="lw16-card"><h3>' . esc_html__( 'Hinweise', 'lindenweg16' ) . '</h3><ul class="ul-disc">'
			. '<li>' . esc_html__( 'Die Passwörter werden verschlüsselt gespeichert. Der Schlüssel stammt aus den WordPress-Salts in der wp-config.php – ändern sich die Salts, müssen die Passwörter neu eingetragen werden. Wer das vermeiden will, setzt in der wp-config.php eine feste Konstante LW16_MAIL_KEY.', 'lindenweg16' ) . '</li>'
			. '<li>' . esc_html__( 'Für die Zustellung sollte die Domain bei IONOS SPF (und möglichst DKIM) eingerichtet haben; versendet wird über den IONOS-Server, nicht über den Webserver des Shops.', 'lindenweg16' ) . '</li>'
			. '<li>' . esc_html__( 'Entwürfe liegen nur im ERP. Gesendete Mails werden zusätzlich im Gesendet-Ordner des Postfachs abgelegt und erscheinen so auch im IONOS-Webmail und am Handy.', 'lindenweg16' ) . '</li>'
			. '</ul></div>';
	}

	/**
	 * Formular zum Anlegen und Ändern.
	 *
	 * @param array|null $account Postfach oder null für neu.
	 */
	protected function render_form( $account ) {
		$is_new = null === $account;

		if ( ! $is_new && ! $account ) {
			echo '<p>' . esc_html__( 'Postfach nicht gefunden.', 'lindenweg16' ) . '</p>';
			return;
		}

		$d = array_merge(
			array(
				'id'            => 0,
				'label'         => '',
				'kind'          => 'personal',
				'owner_user_id' => 0,
				'email'         => '',
				'from_name'     => '',
				'username'      => '',
				'signature'     => "{name}\n" . (string) Settings::get( 'firma_name', '' ),
				'active'        => 1,
			),
			self::ionos_defaults(),
			$is_new ? array() : $account
		);

		$shares = $is_new ? array() : MailRepository::get_shares( (int) $d['id'] );
		$users  = get_users(
			array(
				'role__not_in' => array( 'customer', 'subscriber' ),
				'orderby'      => 'display_name',
			)
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-mb-account-form" data-account="' . (int) $d['id'] . '">';
		wp_nonce_field( 'lw16_mail_account_save' );
		echo '<input type="hidden" name="action" value="lw16_mail_account_save"><input type="hidden" name="id" value="' . (int) $d['id'] . '">';

		echo '<h2>' . esc_html( $is_new ? __( 'Neues Postfach', 'lindenweg16' ) : $d['label'] ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		$this->row( __( 'Bezeichnung', 'lindenweg16' ), '<input type="text" name="label" class="regular-text" value="' . esc_attr( $d['label'] ) . '" required placeholder="' . esc_attr__( 'z. B. Katrin oder Info', 'lindenweg16' ) . '">' );

		$kind  = '<select name="kind" id="lw16-mb-kind"><option value="personal"' . selected( $d['kind'], 'personal', false ) . '>' . esc_html__( 'Persönlich (gehört einem Mitarbeiter)', 'lindenweg16' ) . '</option>'
			. '<option value="shared"' . selected( $d['kind'], 'shared', false ) . '>' . esc_html__( 'Geteilt (z. B. info@, support@)', 'lindenweg16' ) . '</option></select>';
		$this->row( __( 'Typ', 'lindenweg16' ), $kind );

		$owner = '<select name="owner_user_id"><option value="0">' . esc_html__( '– niemand –', 'lindenweg16' ) . '</option>';
		foreach ( $users as $u ) {
			$owner .= '<option value="' . (int) $u->ID . '"' . selected( (int) $d['owner_user_id'], (int) $u->ID, false ) . '>' . esc_html( $u->display_name ) . '</option>';
		}
		$owner .= '</select>';
		$this->row( __( 'Besitzer', 'lindenweg16' ), $owner, __( 'Nur bei persönlichen Postfächern: Dieser Mitarbeiter darf lesen und senden.', 'lindenweg16' ) );

		$this->row( __( 'E-Mail-Adresse', 'lindenweg16' ), '<input type="email" name="email" class="regular-text" value="' . esc_attr( $d['email'] ) . '" required>', __( 'Absenderadresse. Bei IONOS muss sie zum Postfach gehören.', 'lindenweg16' ) );
		$this->row( __( 'Absendername', 'lindenweg16' ), '<input type="text" name="from_name" class="regular-text" value="' . esc_attr( $d['from_name'] ) . '" placeholder="' . esc_attr__( 'z. B. Katrin – Lindenweg16', 'lindenweg16' ) . '">' );
		$this->row( __( 'Benutzername', 'lindenweg16' ), '<input type="text" name="username" class="regular-text" value="' . esc_attr( $d['username'] ) . '" autocomplete="off">', __( 'Bei IONOS die vollständige E-Mail-Adresse. Leer lassen, um die E-Mail-Adresse zu nehmen.', 'lindenweg16' ) );
		$this->row(
			__( 'Passwort', 'lindenweg16' ),
			'<input type="password" name="password" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( $is_new ? '' : __( '(unverändert)', 'lindenweg16' ) ) . '"' . ( $is_new ? ' required' : '' ) . '>',
			__( 'Das Passwort des Postfachs aus dem IONOS-Kundenbereich. Es wird verschlüsselt gespeichert.', 'lindenweg16' )
		);

		$secure_imap = '<select name="imap_secure"><option value="ssl"' . selected( $d['imap_secure'], 'ssl', false ) . '>SSL/TLS</option><option value="tls"' . selected( $d['imap_secure'], 'tls', false ) . '>STARTTLS</option></select>';
		$this->row( __( 'Eingang (IMAP)', 'lindenweg16' ), '<input type="text" name="imap_host" value="' . esc_attr( $d['imap_host'] ) . '" class="regular-text"> Port <input type="number" name="imap_port" value="' . (int) $d['imap_port'] . '" class="small-text"> ' . $secure_imap );

		$secure_smtp = '<select name="smtp_secure"><option value="tls"' . selected( $d['smtp_secure'], 'tls', false ) . '>STARTTLS</option><option value="ssl"' . selected( $d['smtp_secure'], 'ssl', false ) . '>SSL/TLS</option></select>';
		$this->row( __( 'Ausgang (SMTP)', 'lindenweg16' ), '<input type="text" name="smtp_host" value="' . esc_attr( $d['smtp_host'] ) . '" class="regular-text"> Port <input type="number" name="smtp_port" value="' . (int) $d['smtp_port'] . '" class="small-text"> ' . $secure_smtp, __( 'IONOS: smtp.ionos.de, Port 587 mit STARTTLS (oder 465 mit SSL/TLS).', 'lindenweg16' ) );

		$this->row( __( 'Signatur', 'lindenweg16' ), '<textarea name="signature" rows="4" class="large-text">' . esc_textarea( (string) $d['signature'] ) . '</textarea>', __( '{name} wird durch den Namen des Mitarbeiters ersetzt, der die Mail schreibt.', 'lindenweg16' ) );

		$access = '<fieldset class="lw16-mb-shares">';
		foreach ( $users as $u ) {
			$on      = isset( $shares[ (int) $u->ID ] );
			$send    = $on ? $shares[ (int) $u->ID ] : true;
			$access .= '<div><label><input type="checkbox" name="share[' . (int) $u->ID . ']" value="1"' . checked( $on, true, false ) . '> ' . esc_html( $u->display_name ) . '</label> '
				. '<label class="lw16-mb-sendbox"><input type="checkbox" name="send[' . (int) $u->ID . ']" value="1"' . checked( $send, true, false ) . '> ' . esc_html__( 'darf senden', 'lindenweg16' ) . '</label></div>';
		}
		$access .= '</fieldset>';
		$this->row( __( 'Weitere Mitarbeiter mit Zugriff', 'lindenweg16' ), $access, __( 'Ohne „darf senden" können sie die Mails nur lesen, markieren und notieren. Verwalter sehen immer alle Postfächer.', 'lindenweg16' ) );

		$this->row( __( 'Aktiv', 'lindenweg16' ), '<label><input type="checkbox" name="active" value="1"' . checked( (int) $d['active'], 1, false ) . '> ' . esc_html__( 'Postfach abrufen und nutzen', 'lindenweg16' ) . '</label>' );

		echo '</table>';

		echo '<p class="lw16-mb-test-row"><button type="button" class="button lw16-mb-test-btn">' . esc_html__( 'Verbindung testen', 'lindenweg16' ) . '</button> <span class="lw16-mb-test-result" aria-live="polite"></span></p>';
		echo '<p><button type="submit" class="button button-primary button-large">' . esc_html__( 'Speichern', 'lindenweg16' ) . '</button> <a class="button button-large" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Abbrechen', 'lindenweg16' ) . '</a></p>';
		echo '</form>';
	}

	/**
	 * Eine Formularzeile.
	 *
	 * @param string $label Beschriftung.
	 * @param string $field Fertiges HTML des Feldes (bereits escaped).
	 * @param string $help  Hilfetext.
	 */
	protected function row( $label, $field, $help = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $field // phpcs:ignore WordPress.Security.EscapeOutput
			. ( '' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>';
	}

	/* --------------------------------------------------------------------
	 * Handler
	 * ------------------------------------------------------------------ */

	/**
	 * Einstellungen speichern.
	 */
	public function handle_settings() {
		$this->guard( 'lw16_mail_settings_save' );

		Settings::update(
			array(
				'mail_sync_aktiv'    => isset( $_POST['mail_sync_aktiv'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'mail_lookback_tage' => isset( $_POST['mail_lookback_tage'] ) ? max( 1, min( 3650, (int) $_POST['mail_lookback_tage'] ) ) : 90, // phpcs:ignore WordPress.Security.NonceVerification
				'mail_max_anhang_mb' => isset( $_POST['mail_max_anhang_mb'] ) ? max( 1, min( 100, (int) $_POST['mail_max_anhang_mb'] ) ) : 20, // phpcs:ignore WordPress.Security.NonceVerification
			)
		);

		MailboxModule::notice( 'success', __( 'Einstellungen gespeichert.', 'lindenweg16' ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Postfach speichern.
	 */
	public function handle_save() {
		$this->guard( 'lw16_mail_account_save' );

		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$old    = $id ? MailRepository::get_account( $id ) : null;
		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$errors = array();

		$secure_ok = array( 'ssl', 'tls' );
		$email     = isset( $post['email'] ) ? sanitize_email( $post['email'] ) : '';
		$kind      = isset( $post['kind'] ) && 'shared' === $post['kind'] ? 'shared' : 'personal';
		$owner     = 'personal' === $kind && isset( $post['owner_user_id'] ) ? (int) $post['owner_user_id'] : 0;

		$data = array(
			'label'         => isset( $post['label'] ) ? sanitize_text_field( $post['label'] ) : '',
			'kind'          => $kind,
			'owner_user_id' => $owner ? $owner : null,
			'email'         => $email,
			'from_name'     => isset( $post['from_name'] ) ? sanitize_text_field( $post['from_name'] ) : '',
			'username'      => isset( $post['username'] ) && '' !== trim( $post['username'] ) ? sanitize_text_field( $post['username'] ) : $email,
			'imap_host'     => isset( $post['imap_host'] ) ? $this->host( $post['imap_host'] ) : '',
			'imap_port'     => isset( $post['imap_port'] ) ? (int) $post['imap_port'] : 993,
			'imap_secure'   => isset( $post['imap_secure'] ) && in_array( $post['imap_secure'], $secure_ok, true ) ? $post['imap_secure'] : 'ssl',
			'smtp_host'     => isset( $post['smtp_host'] ) ? $this->host( $post['smtp_host'] ) : '',
			'smtp_port'     => isset( $post['smtp_port'] ) ? (int) $post['smtp_port'] : 587,
			'smtp_secure'   => isset( $post['smtp_secure'] ) && in_array( $post['smtp_secure'], $secure_ok, true ) ? $post['smtp_secure'] : 'tls',
			'signature'     => isset( $post['signature'] ) ? ComposeService::clean_text( $post['signature'], true ) : '',
			'active'        => isset( $post['active'] ) ? 1 : 0,
		);

		if ( '' === $data['label'] ) {
			$errors[] = __( 'Bitte eine Bezeichnung angeben.', 'lindenweg16' );
		}
		if ( ! is_email( $email ) ) {
			$errors[] = __( 'Bitte eine gültige E-Mail-Adresse angeben.', 'lindenweg16' );
		}
		if ( '' === $data['imap_host'] || '' === $data['smtp_host'] ) {
			$errors[] = __( 'Bitte IMAP- und SMTP-Server angeben.', 'lindenweg16' );
		}
		foreach ( array( 'imap_port', 'smtp_port' ) as $port ) {
			if ( $data[ $port ] < 1 || $data[ $port ] > 65535 ) {
				$errors[] = __( 'Ungültiger Port.', 'lindenweg16' );
				break;
			}
		}

		$password = isset( $post['password'] ) ? (string) $post['password'] : '';
		if ( '' !== $password ) {
			$enc = MailCrypto::encrypt( $password );
			if ( '' === $enc ) {
				$errors[] = __( 'Das Passwort konnte nicht verschlüsselt werden (libsodium fehlt).', 'lindenweg16' );
			} else {
				$data['password_enc'] = $enc;
			}
		} elseif ( ! $old || '' === (string) $old['password_enc'] ) {
			$errors[] = __( 'Bitte das Passwort des Postfachs angeben.', 'lindenweg16' );
		}

		if ( $errors ) {
			MailboxModule::notice( 'error', implode( ' ', $errors ) );
			wp_safe_redirect( self::url( array( 'edit' => $id ? $id : 'new' ) ) );
			exit;
		}

		// Neue Zugangsdaten: alte Fehlermeldung verwerfen, damit sie nicht weiter angezeigt wird.
		if ( '' !== $password ) {
			$data['last_error'] = null;
		}

		$before = $old ? $this->involved_users( $id ) : array();
		$new_id = MailRepository::save_account( $data, $id ? $id : null );

		$shares = array();
		if ( isset( $post['share'] ) && is_array( $post['share'] ) ) {
			foreach ( $post['share'] as $uid => $on ) {
				$uid = (int) $uid;
				if ( $uid && get_userdata( $uid ) && $uid !== (int) $owner ) {
					$shares[ $uid ] = isset( $post['send'][ $uid ] );
				}
			}
		}
		MailRepository::set_shares( $new_id, $shares );

		foreach ( array_unique( array_merge( $before, $this->involved_users( $new_id ) ) ) as $uid ) {
			do_action( 'lw16_mail_access_changed', (int) $uid );
		}

		AuditLog::record( $id ? 'mail_postfach_geaendert' : 'mail_postfach_angelegt', 'mail_account', $new_id, array( 'email' => $email ) );

		MailboxModule::notice( 'success', __( 'Postfach gespeichert.', 'lindenweg16' ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Postfach löschen (nur lokal; auf dem Mailserver bleibt alles).
	 */
	public function handle_delete() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$this->guard( 'lw16_mail_account_delete_' . $id );

		$account = MailRepository::get_account( $id );
		if ( $account ) {
			$users = $this->involved_users( $id );
			MailRepository::delete_account( $id );

			foreach ( $users as $uid ) {
				do_action( 'lw16_mail_access_changed', (int) $uid );
			}

			AuditLog::record( 'mail_postfach_geloescht', 'mail_account', $id, array( 'email' => $account['email'] ) );
			MailboxModule::notice( 'success', __( 'Postfach gelöscht.', 'lindenweg16' ) );
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Besitzer und Mitnutzer eines Postfachs.
	 *
	 * @param int $id Postfach.
	 * @return int[]
	 */
	protected function involved_users( $id ) {
		$account = MailRepository::get_account( (int) $id );
		$ids     = array_keys( MailRepository::get_shares( (int) $id ) );

		if ( $account && ! empty( $account['owner_user_id'] ) ) {
			$ids[] = (int) $account['owner_user_id'];
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Servername säubern.
	 *
	 * @param string $host Eingabe.
	 * @return string
	 */
	protected function host( $host ) {
		return (string) preg_replace( '/[^a-z0-9.\-]/i', '', trim( (string) $host ) );
	}

	/**
	 * Verbindungstest (AJAX): IMAP-Anmeldung und SMTP-Anmeldung.
	 */
	public function ajax_test() {
		$this->guard( 'lw16_mail', true );

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$id   = isset( $post['id'] ) ? (int) $post['id'] : 0;
		$old  = $id ? MailRepository::get_account( $id ) : null;

		$password = isset( $post['password'] ) ? (string) $post['password'] : '';
		if ( '' === $password && $old ) {
			$password = (string) MailCrypto::decrypt( (string) $old['password_enc'] );
		}

		$email    = isset( $post['email'] ) ? sanitize_email( $post['email'] ) : '';
		$username = isset( $post['username'] ) && '' !== trim( $post['username'] ) ? sanitize_text_field( $post['username'] ) : $email;

		$account = array(
			'imap_host'   => $this->host( isset( $post['imap_host'] ) ? $post['imap_host'] : '' ),
			'imap_port'   => isset( $post['imap_port'] ) ? (int) $post['imap_port'] : 993,
			'imap_secure' => isset( $post['imap_secure'] ) && 'tls' === $post['imap_secure'] ? 'tls' : 'ssl',
			'smtp_host'   => $this->host( isset( $post['smtp_host'] ) ? $post['smtp_host'] : '' ),
			'smtp_port'   => isset( $post['smtp_port'] ) ? (int) $post['smtp_port'] : 587,
			'smtp_secure' => isset( $post['smtp_secure'] ) && 'ssl' === $post['smtp_secure'] ? 'ssl' : 'tls',
			'username'    => $username,
			'password'    => $password,
			'email'       => $email,
			'from_name'   => '',
		);

		if ( '' === $password ) {
			wp_send_json_error( array( 'message' => __( 'Bitte das Passwort eintragen.', 'lindenweg16' ) ) );
		}

		$result = array(
			'imap' => array( 'ok' => false, 'message' => '' ),
			'smtp' => array( 'ok' => false, 'message' => '' ),
		);

		try {
			$client = SyncService::connect_with( $account, $password );
			$count  = count( $client->list_folders() );
			$client->logout();
			$result['imap'] = array(
				'ok'      => true,
				/* translators: %d: Anzahl Ordner */
				'message' => sprintf( __( 'Eingang OK (%d Ordner gefunden).', 'lindenweg16' ), $count ),
			);
		} catch ( \RuntimeException $e ) {
			$result['imap']['message'] = __( 'Eingang:', 'lindenweg16' ) . ' ' . $e->getMessage();
		}

		try {
			SmtpMailer::test( (array) apply_filters( 'lw16_mail_smtp_account', $account ) );
			$result['smtp'] = array(
				'ok'      => true,
				'message' => __( 'Ausgang OK (Anmeldung am SMTP-Server erfolgreich).', 'lindenweg16' ),
			);
		} catch ( \RuntimeException $e ) {
			$result['smtp']['message'] = __( 'Ausgang:', 'lindenweg16' ) . ' ' . $e->getMessage();
		}

		wp_send_json_success( $result );
	}
}
