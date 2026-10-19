<?php
/**
 * Mails schreiben, beantworten und versenden – im Layout der Automatik-Mails.
 *
 * Der Mitarbeiter schreibt Text; das ERP packt ihn über
 * `MailTemplate::wrap_html()` in dasselbe Layout wie die automatischen Mails
 * (Logo, Farben, Footer – das Block-Theme übernimmt den Website-Look über den
 * Filter `lindenweg16_mail_html`). Die Signatur des Postfachs kommt darunter.
 *
 * Versendet wird über den SMTP-Server des Postfachs (IONOS: smtp.ionos.de).
 * IONOS legt gesendete Mails nicht selbst ab – deshalb schreibt die Mailbox
 * eine Kopie in den Gesendet-Ordner des Postfachs, damit sie auch im
 * Webmail und im Handy-Programm erscheint.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Core\Settings;

/**
 * Mail schreiben und senden.
 */
class ComposeService {

	/**
	 * Größte Summe aller Anhänge in Megabyte (Standard).
	 */
	const MAX_ATTACHMENT_MB = 20;

	/* --------------------------------------------------------------------
	 * Reine Hilfen (prüfbar ohne WordPress)
	 * ------------------------------------------------------------------ */

	/**
	 * Eingabe von Formularen bereinigen, ohne sie zu verfälschen.
	 *
	 * `sanitize_text_field()` und `sanitize_textarea_field()` entfernen alles,
	 * was wie ein HTML-Tag aussieht – damit verschwindet aus
	 * `Käthe <kaethe@example.de>` die Adresse und aus einem zitierten
	 * „Am … schrieb Name <adresse>:" ebenfalls. Mail-Text ist Klartext und
	 * wird bei jeder Ausgabe escaped; hier fallen nur Steuerzeichen und
	 * ungültige Zeichenfolgen weg.
	 *
	 * @param string $text      Eingabe (bereits ohne Slashes).
	 * @param bool   $multiline Zeilenumbrüche behalten, sonst zu Leerzeichen.
	 * @return string
	 */
	public static function clean_text( $text, $multiline = false ) {
		$text = MimeParser::to_utf8( (string) $text, 'utf-8' );
		$text = MimeParser::normalize_newlines( $text );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		if ( ! $multiline ) {
			$text = (string) preg_replace( '/\s*\n\s*/', ' ', $text );
		}

		return trim( $text );
	}

	/**
	 * Betreff mit Präfix, ohne ein bestehendes zu verdoppeln.
	 *
	 * @param string $subject Betreff der Ursprungsmail.
	 * @param string $prefix  "Re" oder "Fwd".
	 * @return string
	 */
	public static function prefix_subject( $subject, $prefix ) {
		$subject = trim( (string) $subject );
		$aliases = 'Re' === $prefix ? 'Re|AW|Antw' : 'Fwd|WG|Fw';

		if ( preg_match( '/^(' . $aliases . ')\s*:/iu', $subject ) ) {
			return $subject;
		}

		return $prefix . ': ' . $subject;
	}

	/**
	 * Betreff ohne Antwort-/Weiterleitungs-Präfixe – als Überschrift der Mail.
	 *
	 * @param string $subject Betreff.
	 * @return string
	 */
	public static function heading( $subject ) {
		$heading = trim( (string) preg_replace( '/^(\s*(Re|AW|Antw|Fwd|WG|Fw)\s*:\s*)+/iu', '', (string) $subject ) );

		return '' !== $heading ? $heading : trim( (string) $subject );
	}

	/**
	 * Adressfeld ("Name <a@b>, c@d") prüfen und zerlegen.
	 *
	 * @param string $input Eingabe.
	 * @return array {valid: Liste aus {name, email}, invalid: string[]}
	 */
	public static function parse_recipients( $input ) {
		$valid   = array();
		$invalid = array();
		$seen    = array();

		// Semikolon wie Komma behandeln – so schreiben es viele aus Outlook.
		foreach ( MimeParser::parse_addresses( str_replace( ';', ',', (string) $input ) ) as $a ) {
			$key = strtolower( $a['email'] );

			if ( ! filter_var( $a['email'], FILTER_VALIDATE_EMAIL ) ) {
				$invalid[] = $a['email'];
				continue;
			}

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$valid[]      = $a;
		}

		// Eintrag ohne @ ("Katrin") wird von parse_addresses verworfen – das melden wir.
		foreach ( preg_split( '/[,;]/', (string) $input ) as $part ) {
			$part = trim( $part );
			if ( '' !== $part && false === strpos( $part, '@' ) ) {
				$invalid[] = $part;
			}
		}

		return array(
			'valid'   => $valid,
			'invalid' => $invalid,
		);
	}

	/**
	 * Zitat der Ursprungsmail für die Antwort ("> …").
	 *
	 * @param array  $message  Nachricht aus der Datenbank.
	 * @param string $date_fmt Fertig formatiertes Datum.
	 * @return string
	 */
	public static function quote( array $message, $date_fmt ) {
		$who = '' !== trim( (string) $message['from_name'] ) ? $message['from_name'] . ' <' . $message['from_email'] . '>' : $message['from_email'];

		$lines = explode( "\n", MimeParser::normalize_newlines( (string) $message['body_text'] ) );
		$lines = array_slice( $lines, 0, 200 );
		$quote = array();

		foreach ( $lines as $line ) {
			$line    = rtrim( $line );
			$quote[] = '' === $line ? '>' : ( 0 === strpos( $line, '>' ) ? '>' . $line : '> ' . $line );
		}

		return sprintf(
			/* translators: 1: Datum, 2: Absender */
			__( 'Am %1$s schrieb %2$s:', 'lindenweg16' ),
			$date_fmt,
			$who
		) . "\n" . implode( "\n", $quote );
	}

	/**
	 * Empfänger einer Antwort.
	 *
	 * @param array  $message   Ursprungsmail.
	 * @param bool   $all       Allen antworten.
	 * @param string $own_email Eigene Adresse (wird nicht angeschrieben).
	 * @param string $reply_to  Reply-To der Ursprungsmail, falls vorhanden.
	 * @return array {to: string, cc: string}
	 */
	public static function reply_recipients( array $message, $all, $own_email, $reply_to = '' ) {
		$own = strtolower( (string) $own_email );
		$to  = array();
		$cc  = array();

		$main = '' !== $reply_to ? $reply_to : $message['from_email'];
		if ( strtolower( $main ) === $own ) {
			// Auf die eigene Mail antworten: an die damaligen Empfänger.
			foreach ( (array) json_decode( (string) $message['to_json'], true ) as $a ) {
				$to[ strtolower( $a['email'] ) ] = $a;
			}
		} else {
			$to[ strtolower( $main ) ] = array(
				'name'  => $message['from_name'],
				'email' => $main,
			);
		}

		if ( $all ) {
			foreach ( array_merge( (array) json_decode( (string) $message['to_json'], true ), (array) json_decode( (string) $message['cc_json'], true ) ) as $a ) {
				$key = strtolower( $a['email'] );
				if ( $key !== $own && ! isset( $to[ $key ] ) ) {
					$cc[ $key ] = $a;
				}
			}
		}

		unset( $to[ $own ] );

		return array(
			'to' => self::format_list( $to ),
			'cc' => self::format_list( $cc ),
		);
	}

	/**
	 * Adressliste als Text ("Name <a@b>, c@d").
	 *
	 * @param array $list Liste aus {name, email}.
	 * @return string
	 */
	public static function format_list( array $list ) {
		$out = array();

		foreach ( $list as $a ) {
			$name  = trim( (string) $a['name'] );
			$out[] = '' === $name ? $a['email'] : '"' . addcslashes( $name, '"\\' ) . '" <' . $a['email'] . '>';
		}

		return implode( ', ', $out );
	}

	/**
	 * Signatur mit eingesetztem Namen des Absenders.
	 *
	 * @param string $signature Vorlage aus dem Postfach.
	 * @param string $name      Anzeigename des angemeldeten Mitarbeiters.
	 * @return string
	 */
	public static function render_signature( $signature, $name ) {
		return trim( str_replace( '{name}', (string) $name, (string) $signature ) );
	}

	/**
	 * Den eigenen Text vom angehängten Zitat trennen.
	 *
	 * Antworten und Weiterleitungen beginnen im Eingabefeld mit dem Zitat
	 * ("Am … schrieb …:" bzw. "---- Weitergeleitete Nachricht ----"). Die
	 * Signatur gehört zwischen den eigenen Text und das Zitat – so erwartet
	 * man es in jedem Mailprogramm.
	 *
	 * @param string $body Inhalt des Eingabefelds.
	 * @return array {main, quote}
	 */
	public static function split_quote( $body ) {
		$body = MimeParser::normalize_newlines( (string) $body );

		if ( preg_match( '/^(Am .{3,200} schrieb .{1,200}:|-{3,} ?Weitergeleitete Nachricht ?-{3,})\s*$/mu', $body, $m, PREG_OFFSET_CAPTURE ) ) {
			return array(
				'main'  => trim( substr( $body, 0, $m[0][1] ) ),
				'quote' => trim( substr( $body, $m[0][1] ) ),
			);
		}

		return array(
			'main'  => trim( $body ),
			'quote' => '',
		);
	}

	/* --------------------------------------------------------------------
	 * Mit WordPress
	 * ------------------------------------------------------------------ */

	/**
	 * Fertiges HTML der Mail im CI-Layout.
	 *
	 * @param string $body      Text des Mitarbeiters (ohne Zitat).
	 * @param string $subject   Betreff.
	 * @param string $signature Signatur (Klartext).
	 * @param string $quote     Zitat der Ursprungsmail (Klartext), darf leer sein.
	 * @return string
	 */
	public static function build_html( $body, $subject, $signature, $quote = '' ) {
		return MailTemplate::wrap_html(
			$body,
			self::heading( $subject ),
			array(
				'template'  => 'mailbox',
				'personal'  => true,
				'signature' => $signature,
				'quote'     => $quote,
			)
		);
	}

	/**
	 * Mail senden.
	 *
	 * @param array $account Postfach.
	 * @param int   $user_id Absendender Mitarbeiter.
	 * @param array $data {
	 *     @type string $to          Empfänger.
	 *     @type string $cc          Kopie.
	 *     @type string $bcc         Blindkopie.
	 *     @type string $subject     Betreff.
	 *     @type string $body        Text.
	 *     @type array  $attachments Liste aus {path, name, type}.
	 *     @type int    $reply_to_id Nachricht, auf die geantwortet wird.
	 *     @type int    $draft_id    Entwurf, der danach gelöscht wird.
	 * }
	 * @return array {message_id, warnings: string[]}
	 * @throws \RuntimeException Mit lesbarer Meldung.
	 */
	public static function send( array $account, $user_id, array $data ) {
		$to  = self::parse_recipients( isset( $data['to'] ) ? $data['to'] : '' );
		$cc  = self::parse_recipients( isset( $data['cc'] ) ? $data['cc'] : '' );
		$bcc = self::parse_recipients( isset( $data['bcc'] ) ? $data['bcc'] : '' );

		$invalid = array_merge( $to['invalid'], $cc['invalid'], $bcc['invalid'] );
		if ( $invalid ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: Liste ungültiger Adressen */
					__( 'Ungültige E-Mail-Adresse: %s', 'lindenweg16' ),
					implode( ', ', $invalid )
				)
			);
		}

		if ( ! $to['valid'] && ! $cc['valid'] && ! $bcc['valid'] ) {
			throw new \RuntimeException( __( 'Bitte mindestens einen Empfänger angeben.', 'lindenweg16' ) );
		}

		$subject = trim( (string) ( isset( $data['subject'] ) ? $data['subject'] : '' ) );
		if ( '' === $subject ) {
			$subject = __( '(ohne Betreff)', 'lindenweg16' );
		}
		$subject = str_replace( array( "\r", "\n" ), ' ', $subject );

		$body = trim( MimeParser::normalize_newlines( isset( $data['body'] ) ? (string) $data['body'] : '' ) );
		if ( '' === $body ) {
			throw new \RuntimeException( __( 'Die Nachricht ist leer.', 'lindenweg16' ) );
		}

		$attachments = isset( $data['attachments'] ) ? (array) $data['attachments'] : array();
		$total       = 0;
		foreach ( $attachments as $att ) {
			$total += (int) filesize( $att['path'] );
		}
		$max = max( 1, (int) Settings::get( 'mail_max_anhang_mb', self::MAX_ATTACHMENT_MB ) ) * 1048576;
		if ( $total > $max ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: Megabyte */
					__( 'Die Anhänge sind zu groß (höchstens %d MB insgesamt).', 'lindenweg16' ),
					(int) ( $max / 1048576 )
				)
			);
		}

		$user      = get_userdata( (int) $user_id );
		$signature = self::render_signature( isset( $account['signature'] ) ? $account['signature'] : '', $user ? $user->display_name : '' );

		$original   = ! empty( $data['reply_to_id'] ) ? MailRepository::get_message( (int) $data['reply_to_id'] ) : null;
		$in_reply   = '';
		$references = array();
		if ( $original && (int) $original['account_id'] === (int) $account['id'] && '' !== (string) $original['message_id'] ) {
			$in_reply = $original['message_id'];
			if ( '' !== (string) $original['thread_key'] && $original['thread_key'] !== $original['message_id'] ) {
				$references[] = $original['thread_key'];
			}
			$references[] = $original['message_id'];
		}

		$parts = self::split_quote( $body );
		$text  = $parts['main']
			. ( '' !== $signature ? "\n\n-- \n" . $signature : '' )
			. ( '' !== $parts['quote'] ? "\n\n" . $parts['quote'] : '' );

		$account['password'] = SyncService::password( $account );
		$account             = (array) apply_filters( 'lw16_mail_smtp_account', $account );

		$sent = SmtpMailer::send(
			$account,
			array(
				'to'          => $to['valid'],
				'cc'          => $cc['valid'],
				'bcc'         => $bcc['valid'],
				'subject'     => $subject,
				'html'        => self::build_html( $parts['main'], $subject, $signature, $parts['quote'] ),
				'text'        => $text,
				'attachments' => $attachments,
				'in_reply_to' => $in_reply,
				'references'  => $references,
			)
		);

		$warnings = array();

		// Kopie in den Gesendet-Ordner, damit sie auch im Webmail und am Handy erscheint.
		try {
			self::file_sent_copy( $account, $sent['raw'] );
		} catch ( \RuntimeException $e ) {
			$warnings[] = sprintf(
				/* translators: %s: Fehlertext */
				__( 'Die Mail wurde gesendet, die Kopie im Gesendet-Ordner konnte aber nicht abgelegt werden: %s', 'lindenweg16' ),
				$e->getMessage()
			);
		}

		if ( $original ) {
			MailRepository::update_message( (int) $original['id'], array( 'is_answered' => 1 ) );
			if ( ! empty( $original['uid'] ) && MailRepository::DRAFTS !== $original['folder'] ) {
				try {
					$client = SyncService::connect( $account );
					$client->select( $original['folder'] );
					$client->uid_store( (int) $original['uid'], array( '\\Answered' ) );
					$client->logout();
				} catch ( \RuntimeException $e ) {
					unset( $e ); // Nur die Markierung auf dem Server fehlt; der Abgleich holt sie nach.
				}
			}
		}

		if ( ! empty( $data['draft_id'] ) ) {
			self::delete_draft( (int) $data['draft_id'], (int) $account['id'] );
		}

		AuditLog::record(
			'mail_gesendet',
			'mail_account',
			(int) $account['id'],
			array(
				'von'        => $account['email'],
				'empfaenger' => count( $to['valid'] ) + count( $cc['valid'] ) + count( $bcc['valid'] ),
				'anhaenge'   => count( $attachments ),
			)
		);

		return array(
			'message_id' => $sent['message_id'],
			'warnings'   => $warnings,
		);
	}

	/**
	 * Gesendete Mail im Gesendet-Ordner des Postfachs ablegen und lokal erfassen.
	 *
	 * @param array  $account Postfach inkl. password.
	 * @param string $raw     Vollständige Nachricht.
	 * @throws \RuntimeException Wenn der Server die Kopie ablehnt (dann liegt sie nur lokal).
	 */
	protected static function file_sent_copy( array $account, $raw ) {
		$folders = MailRepository::get_folders( (int) $account['id'] );
		$name    = '';

		foreach ( $folders as $f ) {
			if ( 'sent' === $f['role'] ) {
				$name = $f['name'];
				break;
			}
		}

		$client = null;
		try {
			$client = SyncService::connect( $account );

			if ( '' === $name ) {
				// Noch kein Gesendet-Ordner bekannt (Postfach nie abgeglichen): Server fragen.
				foreach ( $client->list_folders() as $f ) {
					if ( 'sent' === SyncService::role_for( $f['name'], $f['flags'] ) ) {
						$name = $f['name'];
						MailRepository::upsert_folder( (int) $account['id'], $f['name'], 'sent', $f['delimiter'] );
						break;
					}
				}
			}

			if ( '' === $name ) {
				$name = 'Sent';
				$client->create_folder( $name );
				MailRepository::upsert_folder( (int) $account['id'], $name, 'sent', '/' );
			}

			$client->append( $name, $raw, array( '\\Seen' ) );
			$client->logout();
			$client = null;

			// Gleich einlesen, damit die Mail sofort im Gesendet-Ordner steht.
			( new SyncService() )->sync_account(
				(int) $account['id'],
				array(
					'only'   => 'sent',
					'batch'  => 5,
					'budget' => 10,
				)
			);
		} catch ( \RuntimeException $e ) {
			if ( $client ) {
				$client->logout();
			}

			// Der Server nimmt die Kopie nicht an – lokal bleibt sie sichtbar.
			if ( '' === $name ) {
				$name = 'Sent';
				MailRepository::upsert_folder( (int) $account['id'], $name, 'sent', '/' );
			}
			SyncService::store( $account, $name, null, array( '\\Seen' ), $raw );

			throw $e;
		}
	}

	/* --------------------------------------------------------------------
	 * Entwürfe (nur lokal)
	 * ------------------------------------------------------------------ */

	/**
	 * Entwurf speichern.
	 *
	 * @param int   $account_id Postfach.
	 * @param int   $user_id    Verfasser.
	 * @param array $data       to, cc, bcc, subject, body, reply_to_id.
	 * @param int   $draft_id   Bestehender Entwurf, 0 = neu.
	 * @return int ID des Entwurfs.
	 */
	public static function save_draft( $account_id, $user_id, array $data, $draft_id = 0 ) {
		$row = array(
			'account_id'       => (int) $account_id,
			'folder'           => MailRepository::DRAFTS,
			'uid'              => null,
			'message_id'       => null,
			'in_reply_to'      => ! empty( $data['reply_to_id'] ) ? (string) (int) $data['reply_to_id'] : null,
			'from_name'        => '',
			'from_email'       => '',
			'to_json'          => wp_json_encode( array( 'raw' => (string) ( isset( $data['to'] ) ? $data['to'] : '' ) ) ),
			'cc_json'          => wp_json_encode(
				array(
					'cc'  => (string) ( isset( $data['cc'] ) ? $data['cc'] : '' ),
					'bcc' => (string) ( isset( $data['bcc'] ) ? $data['bcc'] : '' ),
				)
			),
			'subject'          => (string) ( isset( $data['subject'] ) ? $data['subject'] : '' ),
			'sent_at'          => gmdate( 'Y-m-d H:i:s' ),
			'snippet'          => MimeParser::snippet( isset( $data['body'] ) ? (string) $data['body'] : '', 120 ),
			'body_text'        => (string) ( isset( $data['body'] ) ? $data['body'] : '' ),
			'is_read'          => 1,
			'assigned_user_id' => (int) $user_id,
		);

		if ( $draft_id ) {
			unset( $row['account_id'], $row['folder'] );
			MailRepository::update_message( (int) $draft_id, $row );
			return (int) $draft_id;
		}

		return MailRepository::insert_message( $row );
	}

	/**
	 * Entwurf löschen.
	 *
	 * @param int $draft_id   Entwurf.
	 * @param int $account_id Postfach (Sicherheitsabgleich).
	 */
	public static function delete_draft( $draft_id, $account_id ) {
		$draft = MailRepository::get_message( (int) $draft_id );

		if ( $draft && MailRepository::DRAFTS === $draft['folder'] && (int) $draft['account_id'] === (int) $account_id ) {
			MailRepository::delete_message( (int) $draft_id );
		}
	}
}
