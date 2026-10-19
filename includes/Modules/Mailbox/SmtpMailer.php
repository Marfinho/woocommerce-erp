<?php
/**
 * Versand über den SMTP-Server eines Postfachs.
 *
 * Nutzt PHPMailer, das WordPress ohnehin mitbringt – für MIME-Aufbau,
 * Kodierung und SMTP-Dialog. Anders als `wp_mail()` wird dabei nicht der
 * globale Absender der Website benutzt, sondern das Postfach des
 * Mitarbeiters (Anmeldung, Absender, Antwortadresse) – und die fertige
 * Nachricht kommt zurück, damit sie als Kopie im Gesendet-Ordner landen kann.
 *
 * Frei von WordPress-Funktionen (außer dem Nachladen von PHPMailer), damit
 * der Versand gegen einen Testserver prüfbar ist.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

/**
 * SMTP-Versand.
 */
class SmtpMailer {

	/**
	 * PHPMailer aus WordPress nachladen, falls nicht schon da.
	 *
	 * @throws \RuntimeException Wenn PHPMailer nicht verfügbar ist.
	 */
	public static function load() {
		if ( class_exists( '\PHPMailer\PHPMailer\PHPMailer' ) ) {
			return;
		}

		if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
			$dir = ABSPATH . WPINC . '/PHPMailer/';
			if ( is_readable( $dir . 'PHPMailer.php' ) ) {
				require_once $dir . 'PHPMailer.php';
				require_once $dir . 'SMTP.php';
				require_once $dir . 'Exception.php';
			}
		}

		if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer' ) ) {
			throw new \RuntimeException( __( 'PHPMailer ist nicht verfügbar.', 'lindenweg16' ) );
		}
	}

	/**
	 * Einen konfigurierten PHPMailer für das Postfach.
	 *
	 * @param array $account {smtp_host, smtp_port, smtp_secure ssl|tls|none, username, password, email, from_name, verify_peer?}.
	 * @return \PHPMailer\PHPMailer\PHPMailer
	 */
	protected static function mailer( array $account ) {
		self::load();

		$m = new \PHPMailer\PHPMailer\PHPMailer( true );
		$m->isSMTP();
		$m->Host       = (string) $account['smtp_host'];
		$m->Port       = (int) $account['smtp_port'];
		$m->SMTPAuth   = true;
		$m->Username   = (string) $account['username'];
		$m->Password   = (string) $account['password'];
		$m->CharSet    = 'UTF-8';
		$m->Encoding   = \PHPMailer\PHPMailer\PHPMailer::ENCODING_QUOTED_PRINTABLE;
		$m->Timeout    = 25;
		$m->XMailer    = ' '; // Kein Hinweis auf PHPMailer im Kopf.
		$m->SMTPDebug  = 0;
		$m->SMTPAutoTLS = false;

		$secure = isset( $account['smtp_secure'] ) ? $account['smtp_secure'] : 'tls';
		if ( 'ssl' === $secure ) {
			$m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
		} elseif ( 'tls' === $secure ) {
			$m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
		} else {
			$m->SMTPSecure = '';
		}

		if ( isset( $account['verify_peer'] ) && ! $account['verify_peer'] ) {
			$m->SMTPOptions = array(
				'ssl' => array(
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'allow_self_signed' => true,
				),
			);
		}

		return $m;
	}

	/**
	 * Verbindung und Anmeldung prüfen, ohne etwas zu senden.
	 *
	 * @param array $account Wie bei mailer().
	 * @throws \RuntimeException Mit lesbarer Meldung bei Fehlern.
	 */
	public static function test( array $account ) {
		$m = self::mailer( $account );

		try {
			if ( ! $m->smtpConnect() ) {
				throw new \RuntimeException( self::explain( $m->ErrorInfo ) );
			}
			$m->smtpClose();
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			throw new \RuntimeException( self::explain( $e->getMessage() ), 0, $e );
		}
	}

	/**
	 * Eine Nachricht senden.
	 *
	 * @param array $account Wie bei mailer().
	 * @param array $msg {
	 *     @type array  $to          Liste aus {email, name}.
	 *     @type array  $cc          dito.
	 *     @type array  $bcc         dito.
	 *     @type array  $reply_to    dito.
	 *     @type string $subject     Betreff.
	 *     @type string $html        HTML-Teil.
	 *     @type string $text        Klartext-Teil.
	 *     @type array  $attachments Liste aus {path, name, type}.
	 *     @type string $in_reply_to Message-ID, auf die geantwortet wird.
	 *     @type array  $references  Message-IDs des Verlaufs.
	 * }
	 * @return array {message_id (ohne spitze Klammern), raw (vollständige Nachricht)}
	 * @throws \RuntimeException Mit lesbarer Meldung bei Fehlern.
	 */
	public static function send( array $account, array $msg ) {
		$m = self::mailer( $account );

		try {
			$m->setFrom( (string) $account['email'], isset( $account['from_name'] ) ? (string) $account['from_name'] : '', false );
			$m->Sender = (string) $account['email'];

			foreach ( array(
				'addAddress' => 'to',
				'addCC'      => 'cc',
				'addBCC'     => 'bcc',
				'addReplyTo' => 'reply_to',
			) as $method => $key ) {
				foreach ( isset( $msg[ $key ] ) ? (array) $msg[ $key ] : array() as $a ) {
					$m->$method( (string) $a['email'], isset( $a['name'] ) ? (string) $a['name'] : '' );
				}
			}

			$m->Subject = (string) $msg['subject'];

			if ( ! empty( $msg['html'] ) ) {
				$m->isHTML( true );
				$m->Body    = (string) $msg['html'];
				$m->AltBody = isset( $msg['text'] ) ? (string) $msg['text'] : '';
			} else {
				$m->isHTML( false );
				$m->Body = isset( $msg['text'] ) ? (string) $msg['text'] : '';
			}

			foreach ( isset( $msg['attachments'] ) ? (array) $msg['attachments'] : array() as $att ) {
				$m->addAttachment(
					(string) $att['path'],
					isset( $att['name'] ) ? (string) $att['name'] : '',
					\PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64,
					isset( $att['type'] ) ? (string) $att['type'] : ''
				);
			}

			$domain       = substr( strrchr( (string) $account['email'], '@' ), 1 );
			$id           = bin2hex( random_bytes( 12 ) ) . '@' . ( '' !== $domain ? $domain : 'localhost' );
			$m->MessageID = '<' . $id . '>';

			if ( ! empty( $msg['in_reply_to'] ) ) {
				$m->addCustomHeader( 'In-Reply-To', '<' . trim( (string) $msg['in_reply_to'], '<> ' ) . '>' );
			}
			if ( ! empty( $msg['references'] ) ) {
				$refs = array();
				foreach ( (array) $msg['references'] as $r ) {
					$refs[] = '<' . trim( (string) $r, '<> ' ) . '>';
				}
				$m->addCustomHeader( 'References', implode( ' ', $refs ) );
			}

			if ( ! $m->preSend() ) {
				throw new \RuntimeException( self::explain( $m->ErrorInfo ) );
			}

			$raw = $m->getSentMIMEMessage();

			if ( ! $m->postSend() ) {
				throw new \RuntimeException( self::explain( $m->ErrorInfo ) );
			}
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			throw new \RuntimeException( self::explain( $e->getMessage() ), 0, $e );
		}

		return array(
			'message_id' => $id,
			'raw'        => $raw,
		);
	}

	/**
	 * PHPMailer-Meldungen verständlich machen.
	 *
	 * @param string $message Meldung.
	 * @return string
	 */
	public static function explain( $message ) {
		$message = trim( (string) $message );

		if ( preg_match( '/authenticate|535|credentials/i', $message ) ) {
			return __( 'Anmeldung am SMTP-Server abgelehnt – Benutzername oder Passwort stimmen nicht.', 'lindenweg16' ) . ' (' . $message . ')';
		}

		if ( preg_match( '/connect/i', $message ) ) {
			return __( 'Keine Verbindung zum SMTP-Server – Server, Port und Verschlüsselung prüfen.', 'lindenweg16' ) . ' (' . $message . ')';
		}

		return '' !== $message ? $message : __( 'Unbekannter Fehler beim Senden.', 'lindenweg16' );
	}
}
