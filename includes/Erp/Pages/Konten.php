<?php
/**
 * ERP-Seite „Postfächer“: Postfächer anlegen, ändern, deaktivieren, löschen,
 * Verbindungstest, Freigaben und Mailbox-Einstellungen.
 *
 * Gleiche Prüfungen und Speicherung wie die alte Seite
 * (Modules/Mailbox/AccountsPage.php): nur Verwalter, Passwörter über MailCrypto
 * verschlüsselt in derselben Spalte, Protokoll über AuditLog, Zugriffsänderungen
 * über den Hook `lw16_mail_access_changed`.
 *
 * Zugangsdaten verlassen den Server nie: Antworten enthalten das Passwort
 * weder im Klartext noch verschlüsselt, nur den Zustand „gespeichert“. Ein
 * leeres Passwortfeld lässt den gespeicherten Wert unverändert.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Settings;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Mailbox\AccountsPage;
use Lindenweg16\Modules\Mailbox\ComposeService;
use Lindenweg16\Modules\Mailbox\MailboxAccess;
use Lindenweg16\Modules\Mailbox\MailCrypto;
use Lindenweg16\Modules\Mailbox\MailRepository;
use Lindenweg16\Modules\Mailbox\SmtpMailer;
use Lindenweg16\Modules\Mailbox\SyncService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen erp/konten/….
 */
class Konten {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$needs = $api->needs( array( 'verwaltung.mailbox_konten' ) );
		$gate  = static function () use ( $needs ) {
			// Wie die alte Seite: nur Verwalter der Mailbox.
			return $needs() && MailboxAccess::is_manager();
		};
		$ns = RestApi::NAMESPACE_V1;

		register_rest_route( $ns, '/erp/konten', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $gate ) );
		register_rest_route( $ns, '/erp/konten/speichern', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save' ), 'permission_callback' => $gate ) );
		register_rest_route( $ns, '/erp/konten/(?P<id>\d+)/loeschen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'delete' ), 'permission_callback' => $gate ) );
		register_rest_route( $ns, '/erp/konten/einstellungen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_settings' ), 'permission_callback' => $gate ) );
		register_rest_route( $ns, '/erp/konten/test', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'test' ), 'permission_callback' => $gate ) );
	}

	/* ====================================================================
	 * Reine Regeln (ohne WordPress und Datenbank, getestet)
	 * ================================================================== */

	/**
	 * Servername säubern.
	 *
	 * @param mixed $host Eingabe.
	 * @return string
	 */
	public static function clean_host( $host ) {
		return (string) preg_replace( '/[^a-z0-9.\-]/i', '', trim( (string) $host ) );
	}

	/**
	 * Ist es ein gültiger Port?
	 *
	 * @param mixed $port Eingabe.
	 * @return bool
	 */
	public static function valid_port( $port ) {
		return is_numeric( $port ) && (int) $port >= 1 && (int) $port <= 65535;
	}

	/**
	 * Verschlüsselungsart: ssl oder tls.
	 *
	 * @param mixed  $value   Eingabe.
	 * @param string $default Vorgabe.
	 * @return string
	 */
	public static function clean_secure( $value, $default ) {
		return in_array( $value, array( 'ssl', 'tls' ), true ) ? $value : $default;
	}

	/**
	 * Ob ein Passwort gespeichert, nicht lesbar oder gar nicht da ist – ohne es zu zeigen.
	 *
	 * @param string $stored   Gespeicherter (verschlüsselter) Wert.
	 * @param bool   $readable Ob er sich entschlüsseln lässt.
	 * @return string saved, unreadable oder missing.
	 */
	public static function password_state( $stored, $readable ) {
		if ( '' === (string) $stored ) {
			return 'missing';
		}

		return $readable ? 'saved' : 'unreadable';
	}

	/**
	 * Zustand eines Postfachs für die Liste.
	 *
	 * @param bool   $active     Aktiv.
	 * @param string $password   Ergebnis von password_state().
	 * @param string $last_error Letzter Fehler.
	 * @param string $last_sync  Letzter Abruf (nicht leer = schon gelaufen).
	 * @return string pw, off, error, ok oder new.
	 */
	public static function status_code( $active, $password, $last_error, $last_sync ) {
		if ( 'saved' !== $password ) {
			return 'pw';
		}
		if ( ! $active ) {
			return 'off';
		}
		if ( '' !== (string) $last_error ) {
			return 'error';
		}

		return '' !== (string) $last_sync ? 'ok' : 'new';
	}

	/**
	 * Eingaben eines Postfachs prüfen.
	 *
	 * @param array $d              Bereinigte Werte (label, email, imap_host, smtp_host, imap_port, smtp_port).
	 * @param bool  $email_valid    Ob die Adresse gültig ist.
	 * @param bool  $password_known Ob ein neues Passwort kommt oder schon eines gespeichert ist.
	 * @param bool  $can_encrypt    Ob die Verschlüsselung möglich ist (libsodium).
	 * @return string[] Meldungen, leer wenn alles passt.
	 */
	public static function problems( array $d, $email_valid, $password_known, $can_encrypt = true ) {
		$errors = array();

		if ( '' === (string) $d['label'] ) {
			$errors[] = __( 'Bitte eine Bezeichnung angeben.', 'lindenweg16' );
		}
		if ( ! $email_valid ) {
			$errors[] = __( 'Bitte eine gültige E-Mail-Adresse angeben.', 'lindenweg16' );
		}
		if ( '' === (string) $d['imap_host'] || '' === (string) $d['smtp_host'] ) {
			$errors[] = __( 'Bitte IMAP- und SMTP-Server angeben.', 'lindenweg16' );
		}
		if ( ! self::valid_port( $d['imap_port'] ) || ! self::valid_port( $d['smtp_port'] ) ) {
			$errors[] = __( 'Ungültiger Port (erlaubt sind 1 bis 65535).', 'lindenweg16' );
		}
		if ( ! $password_known ) {
			$errors[] = __( 'Bitte das Passwort des Postfachs angeben.', 'lindenweg16' );
		}
		if ( ! $can_encrypt ) {
			$errors[] = __( 'Auf diesem Server fehlt libsodium – ohne sie können keine Passwörter gespeichert werden.', 'lindenweg16' );
		}

		return $errors;
	}

	/**
	 * Freigaben aus der Anfrage: user_id => darf senden.
	 *
	 * Der Besitzer, ungültige und doppelte Nutzer fallen weg.
	 *
	 * @param mixed    $raw    Liste von {user_id, can_send}.
	 * @param int      $owner  Besitzer (0 = keiner).
	 * @param callable $exists Prüft, ob es den Nutzer gibt (int => bool).
	 * @return array user_id => bool
	 */
	public static function clean_shares( $raw, $owner, $exists ) {
		$out = array();

		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( $raw as $entry ) {
			$uid = is_array( $entry ) && isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0;

			if ( $uid < 1 || $uid === (int) $owner || isset( $out[ $uid ] ) || ! $exists( $uid ) ) {
				continue;
			}

			$out[ $uid ] = ! empty( $entry['can_send'] );
		}

		return $out;
	}

	/**
	 * Mailbox-Einstellungen begrenzen.
	 *
	 * @param array $in Eingaben (sync, lookback_days, max_attachment_mb).
	 * @return array Schlüssel der Einstellungen => Wert.
	 */
	public static function clamp_settings( array $in ) {
		return array(
			'mail_sync_aktiv'    => ! empty( $in['sync'] ) ? 1 : 0,
			'mail_lookback_tage' => isset( $in['lookback_days'] ) ? max( 1, min( 3650, (int) $in['lookback_days'] ) ) : 90,
			'mail_max_anhang_mb' => isset( $in['max_attachment_mb'] ) ? max( 1, min( 100, (int) $in['max_attachment_mb'] ) ) : 20,
		);
	}

	/**
	 * Ein Geheimnis aus einem Text entfernen (Fehlermeldungen von Mailservern).
	 *
	 * @param string $text   Text.
	 * @param string $secret Geheimnis.
	 * @return string
	 */
	public static function scrub( $text, $secret ) {
		$text = (string) $text;

		return '' === (string) $secret ? $text : str_replace( (string) $secret, '***', $text );
	}

	/* ====================================================================
	 * Hilfen
	 * ================================================================== */

	/**
	 * Fehlerantwort.
	 *
	 * @param string $message Text.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}

	/**
	 * Eingaben der Anfrage (JSON oder Formular).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array
	 */
	protected static function input( WP_REST_Request $request ) {
		$json = $request->get_json_params();

		return is_array( $json ) ? $json : (array) $request->get_params();
	}

	/**
	 * Nutzer, die Zugriff bekommen können (wie die alte Seite).
	 *
	 * @return array Liste von {id, name}.
	 */
	protected static function users() {
		$out = array();

		foreach ( get_users( array( 'role__not_in' => array( 'customer', 'subscriber' ), 'orderby' => 'display_name' ) ) as $u ) {
			$out[] = array( 'id' => (int) $u->ID, 'name' => (string) $u->display_name );
		}

		return $out;
	}

	/**
	 * Besitzer und Mitnutzer eines Postfachs.
	 *
	 * @param int $id Postfach.
	 * @return int[]
	 */
	protected static function involved_users( $id ) {
		$account = MailRepository::get_account( (int) $id );
		$ids     = array_keys( MailRepository::get_shares( (int) $id ) );

		if ( $account && ! empty( $account['owner_user_id'] ) ) {
			$ids[] = (int) $account['owner_user_id'];
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Ein Postfach für die Oberfläche – ohne Zugangsdaten.
	 *
	 * @param array $a Postfach.
	 * @return array
	 */
	protected static function account_out( array $a ) {
		$password = self::password_state( (string) $a['password_enc'], null !== MailCrypto::decrypt( (string) $a['password_enc'] ) );
		$shares   = array();

		foreach ( MailRepository::get_shares( (int) $a['id'] ) as $uid => $can_send ) {
			$user = get_userdata( (int) $uid );
			if ( $user ) {
				$shares[] = array( 'user_id' => (int) $uid, 'name' => (string) $user->display_name, 'can_send' => (bool) $can_send );
			}
		}

		$owner      = ! empty( $a['owner_user_id'] ) ? get_userdata( (int) $a['owner_user_id'] ) : null;
		$last_error = (string) $a['last_error'];
		$last_sync  = ! empty( $a['last_sync'] ) ? Postfach::to_ts( get_gmt_from_date( $a['last_sync'] ) ) : 0;

		return array(
			'id'            => (int) $a['id'],
			'label'         => (string) $a['label'],
			'kind'          => 'shared' === $a['kind'] ? 'shared' : 'personal',
			'owner_user_id' => (int) $a['owner_user_id'],
			'owner_name'    => $owner ? (string) $owner->display_name : '',
			'email'         => (string) $a['email'],
			'from_name'     => (string) $a['from_name'],
			'username'      => (string) $a['username'],
			'imap_host'     => (string) $a['imap_host'],
			'imap_port'     => (int) $a['imap_port'],
			'imap_secure'   => self::clean_secure( $a['imap_secure'], 'ssl' ),
			'smtp_host'     => (string) $a['smtp_host'],
			'smtp_port'     => (int) $a['smtp_port'],
			'smtp_secure'   => self::clean_secure( $a['smtp_secure'], 'tls' ),
			'signature'     => (string) $a['signature'],
			'active'        => ! empty( $a['active'] ),
			'password'      => $password,
			'status'        => self::status_code( ! empty( $a['active'] ), $password, $last_error, $last_sync ? '1' : '' ),
			'last_error'    => $last_error,
			'last_sync'     => $last_sync,
			'shares'        => $shares,
		);
	}

	/* ====================================================================
	 * Routen
	 * ================================================================== */

	/**
	 * Alle Postfächer, Nutzer, Einstellungen, Vorgaben.
	 *
	 * @return WP_REST_Response
	 */
	public static function overview() {
		$accounts = array();

		foreach ( MailRepository::all_accounts() as $a ) {
			$accounts[] = self::account_out( $a );
		}

		return new WP_REST_Response( array(
			'accounts'  => $accounts,
			'users'     => self::users(),
			'crypto'    => MailCrypto::available(),
			'defaults'  => AccountsPage::ionos_defaults(),
			'signature' => "{name}\n" . (string) Settings::get( 'firma_name', '' ),
			'settings'  => array(
				'sync'              => (bool) (int) Settings::get( 'mail_sync_aktiv', 1 ),
				'lookback_days'     => (int) Settings::get( 'mail_lookback_tage', 90 ),
				'max_attachment_mb' => (int) Settings::get( 'mail_max_anhang_mb', 20 ),
			),
		) );
	}

	/**
	 * Postfach anlegen oder ändern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function save( WP_REST_Request $request ) {
		$in  = self::input( $request );
		$id  = isset( $in['id'] ) ? (int) $in['id'] : 0;
		$old = $id ? MailRepository::get_account( $id ) : null;

		if ( $id && ! $old ) {
			return self::fail( __( 'Dieses Postfach gibt es nicht mehr. Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		$email = isset( $in['email'] ) ? sanitize_email( (string) $in['email'] ) : '';
		$kind  = isset( $in['kind'] ) && 'shared' === $in['kind'] ? 'shared' : 'personal';
		$owner = 'personal' === $kind && isset( $in['owner_user_id'] ) ? (int) $in['owner_user_id'] : 0;

		$data = array(
			'label'         => isset( $in['label'] ) ? sanitize_text_field( (string) $in['label'] ) : '',
			'kind'          => $kind,
			'owner_user_id' => $owner ? $owner : null,
			'email'         => $email,
			'from_name'     => isset( $in['from_name'] ) ? sanitize_text_field( (string) $in['from_name'] ) : '',
			'username'      => isset( $in['username'] ) && '' !== trim( (string) $in['username'] ) ? sanitize_text_field( (string) $in['username'] ) : $email,
			'imap_host'     => isset( $in['imap_host'] ) ? self::clean_host( $in['imap_host'] ) : '',
			'imap_port'     => isset( $in['imap_port'] ) ? (int) $in['imap_port'] : 993,
			'imap_secure'   => self::clean_secure( isset( $in['imap_secure'] ) ? $in['imap_secure'] : '', 'ssl' ),
			'smtp_host'     => isset( $in['smtp_host'] ) ? self::clean_host( $in['smtp_host'] ) : '',
			'smtp_port'     => isset( $in['smtp_port'] ) ? (int) $in['smtp_port'] : 587,
			'smtp_secure'   => self::clean_secure( isset( $in['smtp_secure'] ) ? $in['smtp_secure'] : '', 'tls' ),
			'signature'     => isset( $in['signature'] ) ? ComposeService::clean_text( (string) $in['signature'], true ) : '',
			'active'        => ! empty( $in['active'] ) ? 1 : 0,
		);

		$password = isset( $in['password'] ) ? (string) $in['password'] : '';
		$known    = '' !== $password || ( $old && '' !== (string) $old['password_enc'] );
		$errors   = self::problems( $data, is_email( $email ), $known, '' === $password || MailCrypto::available() );

		$enc = '';
		if ( '' !== $password ) {
			$enc = MailCrypto::encrypt( $password );
			if ( '' === $enc && ! $errors ) {
				$errors[] = __( 'Das Passwort konnte nicht verschlüsselt werden (libsodium fehlt).', 'lindenweg16' );
			}
		}

		if ( $errors ) {
			return self::fail( implode( ' ', array_unique( $errors ) ) );
		}

		if ( '' !== $enc ) {
			$data['password_enc'] = $enc;
			// Neue Zugangsdaten: alte Fehlermeldung verwerfen, damit sie nicht weiter angezeigt wird.
			$data['last_error'] = null;
		}

		$before = $old ? self::involved_users( $id ) : array();
		$new_id = MailRepository::save_account( $data, $id ? $id : null );

		if ( ! $new_id ) {
			return self::fail( __( 'Das Postfach ließ sich nicht speichern. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		$shares = self::clean_shares(
			isset( $in['shares'] ) ? $in['shares'] : array(),
			$owner,
			static function ( $uid ) {
				return (bool) get_userdata( $uid );
			}
		);
		MailRepository::set_shares( $new_id, $shares );

		foreach ( array_unique( array_merge( $before, self::involved_users( $new_id ) ) ) as $uid ) {
			do_action( 'lw16_mail_access_changed', (int) $uid );
		}

		AuditLog::record( $id ? 'mail_postfach_geaendert' : 'mail_postfach_angelegt', 'mail_account', $new_id, array( 'email' => $email ) );

		return new WP_REST_Response( array(
			'ok'      => true,
			'id'      => (int) $new_id,
			'message' => __( 'Postfach gespeichert.', 'lindenweg16' ),
		) );
	}

	/**
	 * Postfach löschen (nur lokal; auf dem Mailserver bleibt alles).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function delete( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$account = MailRepository::get_account( $id );

		if ( ! $account ) {
			return self::fail( __( 'Dieses Postfach gibt es nicht mehr. Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		$users = self::involved_users( $id );
		MailRepository::delete_account( $id );

		foreach ( $users as $uid ) {
			do_action( 'lw16_mail_access_changed', (int) $uid );
		}

		AuditLog::record( 'mail_postfach_geloescht', 'mail_account', $id, array( 'email' => $account['email'] ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Postfach gelöscht. Auf dem Mailserver bleibt alles erhalten.', 'lindenweg16' ) ) );
	}

	/**
	 * Allgemeine Einstellungen der Mailbox speichern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function save_settings( WP_REST_Request $request ) {
		Settings::update( self::clamp_settings( self::input( $request ) ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Einstellungen gespeichert.', 'lindenweg16' ) ) );
	}

	/**
	 * Verbindungstest: IMAP-Anmeldung und SMTP-Anmeldung.
	 *
	 * Ohne Passwort im Formular wird das gespeicherte benutzt; es wird nie
	 * ausgegeben, und Fehlertexte werden davon bereinigt.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function test( WP_REST_Request $request ) {
		$in  = self::input( $request );
		$id  = isset( $in['id'] ) ? (int) $in['id'] : 0;
		$old = $id ? MailRepository::get_account( $id ) : null;

		$password = isset( $in['password'] ) ? (string) $in['password'] : '';
		if ( '' === $password && $old ) {
			$password = (string) MailCrypto::decrypt( (string) $old['password_enc'] );
		}

		if ( '' === $password ) {
			return self::fail( __( 'Bitte das Passwort eintragen. Für ein gespeichertes Postfach, dessen Passwort nicht mehr lesbar ist, muss es neu eingegeben werden.', 'lindenweg16' ) );
		}

		$email    = isset( $in['email'] ) ? sanitize_email( (string) $in['email'] ) : '';
		$username = isset( $in['username'] ) && '' !== trim( (string) $in['username'] ) ? sanitize_text_field( (string) $in['username'] ) : $email;

		$account = array(
			'imap_host'   => self::clean_host( isset( $in['imap_host'] ) ? $in['imap_host'] : '' ),
			'imap_port'   => isset( $in['imap_port'] ) ? (int) $in['imap_port'] : 993,
			'imap_secure' => 'tls' === ( isset( $in['imap_secure'] ) ? $in['imap_secure'] : '' ) ? 'tls' : 'ssl',
			'smtp_host'   => self::clean_host( isset( $in['smtp_host'] ) ? $in['smtp_host'] : '' ),
			'smtp_port'   => isset( $in['smtp_port'] ) ? (int) $in['smtp_port'] : 587,
			'smtp_secure' => 'ssl' === ( isset( $in['smtp_secure'] ) ? $in['smtp_secure'] : '' ) ? 'ssl' : 'tls',
			'username'    => $username,
			'password'    => $password,
			'email'       => $email,
			'from_name'   => '',
		);

		if ( '' === $account['imap_host'] || '' === $account['smtp_host'] ) {
			return self::fail( __( 'Bitte IMAP- und SMTP-Server eintragen.', 'lindenweg16' ) );
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
			$result['imap']['message'] = __( 'Eingang:', 'lindenweg16' ) . ' ' . self::scrub( $e->getMessage(), $password );
		}

		try {
			SmtpMailer::test( (array) apply_filters( 'lw16_mail_smtp_account', $account ) );
			$result['smtp'] = array(
				'ok'      => true,
				'message' => __( 'Ausgang OK (Anmeldung am SMTP-Server erfolgreich).', 'lindenweg16' ),
			);
		} catch ( \RuntimeException $e ) {
			$result['smtp']['message'] = __( 'Ausgang:', 'lindenweg16' ) . ' ' . self::scrub( $e->getMessage(), $password );
		}

		return new WP_REST_Response( array(
			'ok'   => $result['imap']['ok'] && $result['smtp']['ok'],
			'imap' => $result['imap'],
			'smtp' => $result['smtp'],
		) );
	}
}
