<?php
/**
 * Zerlegt eine Roh-E-Mail (RFC 5322 / MIME) in Kopfzeilen, Text, HTML und Anhänge.
 *
 * Bewusst ohne PHP-IMAP- oder Mailparse-Erweiterung: Beide fehlen auf vielen
 * Webhostings (die IMAP-Erweiterung ist seit PHP 8.4 nicht mehr im Kern). Die
 * Klasse ist frei von WordPress und Datenbank und deshalb direkt prüfbar.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

/**
 * MIME-Parser.
 */
class MimeParser {

	/**
	 * Tiefste Verschachtelung, die gelesen wird – Schutz gegen präparierte Mails.
	 */
	const MAX_DEPTH = 12;

	/**
	 * Eine Mail lesen.
	 *
	 * @param string $raw Vollständige Nachricht.
	 * @return array {
	 *     @type array  $headers     Kopfzeilen, Name klein geschrieben => Wert (roh, entfaltet).
	 *     @type string $subject     Betreff, UTF-8.
	 *     @type array  $from        {name, email}.
	 *     @type array  $to          Liste aus {name, email}.
	 *     @type array  $cc          Liste aus {name, email}.
	 *     @type array  $reply_to    Liste aus {name, email}.
	 *     @type int    $date        Unix-Zeit, 0 wenn unbekannt.
	 *     @type string $message_id  Ohne spitze Klammern.
	 *     @type string $in_reply_to Ohne spitze Klammern.
	 *     @type array  $references  Message-IDs ohne spitze Klammern.
	 *     @type string $text        Klartext (aus dem HTML abgeleitet, wenn es keinen gibt).
	 *     @type string $html        HTML-Teil, leer wenn keiner da ist.
	 *     @type array  $attachments Liste aus {filename, mime, content, content_id, inline, size}.
	 *     @type string $snippet     Kurzvorschau.
	 * }
	 */
	public static function parse( $raw ) {
		$raw = (string) $raw;

		list( $head, $body ) = self::split_head_body( $raw );
		$headers             = self::parse_headers( $head );

		$result = array(
			'headers'     => $headers,
			'subject'     => self::decode_words( isset( $headers['subject'] ) ? $headers['subject'] : '' ),
			'from'        => array(
				'name'  => '',
				'email' => '',
			),
			'to'          => self::parse_addresses( isset( $headers['to'] ) ? $headers['to'] : '' ),
			'cc'          => self::parse_addresses( isset( $headers['cc'] ) ? $headers['cc'] : '' ),
			'reply_to'    => self::parse_addresses( isset( $headers['reply-to'] ) ? $headers['reply-to'] : '' ),
			'date'        => 0,
			'message_id'  => '',
			'in_reply_to' => '',
			'references'  => array(),
			'text'        => '',
			'html'        => '',
			'attachments' => array(),
			'snippet'     => '',
		);

		$from = self::parse_addresses( isset( $headers['from'] ) ? $headers['from'] : '' );
		if ( $from ) {
			$result['from'] = $from[0];
		}

		if ( ! empty( $headers['date'] ) ) {
			$ts                = strtotime( $headers['date'] );
			$result['date']    = false === $ts ? 0 : (int) $ts;
		}

		if ( ! empty( $headers['message-id'] ) ) {
			$ids                  = self::extract_ids( $headers['message-id'] );
			$result['message_id'] = $ids ? $ids[0] : trim( $headers['message-id'], " \t<>" );
		}

		if ( ! empty( $headers['in-reply-to'] ) ) {
			$ids                    = self::extract_ids( $headers['in-reply-to'] );
			$result['in_reply_to'] = $ids ? $ids[0] : '';
		}

		if ( ! empty( $headers['references'] ) ) {
			$result['references'] = self::extract_ids( $headers['references'] );
		}

		self::parse_part( $headers, $body, $result, 0 );

		$result['text'] = self::normalize_newlines( $result['text'] );
		if ( '' === trim( $result['text'] ) && '' !== trim( $result['html'] ) ) {
			$result['text'] = self::html_to_text( $result['html'] );
		}

		$result['snippet'] = self::snippet( $result['text'] );

		return $result;
	}

	/**
	 * Kopf und Rumpf trennen.
	 *
	 * @param string $raw Nachricht.
	 * @return array {Kopf, Rumpf}
	 */
	public static function split_head_body( $raw ) {
		if ( preg_match( '/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE ) ) {
			return array(
				substr( $raw, 0, $m[0][1] ),
				substr( $raw, $m[0][1] + strlen( $m[0][0] ) ),
			);
		}

		// Nur Kopfzeilen, kein Rumpf.
		return array( $raw, '' );
	}

	/**
	 * Kopfzeilen lesen: entfalten, Namen klein schreiben. Kommt eine Zeile
	 * mehrfach vor (Received …), zählt die erste.
	 *
	 * @param string $head Kopfblock.
	 * @return array
	 */
	public static function parse_headers( $head ) {
		$head    = preg_replace( '/\r?\n[ \t]+/', ' ', (string) $head );
		$headers = array();

		foreach ( preg_split( '/\r?\n/', $head ) as $line ) {
			$pos = strpos( $line, ':' );
			if ( false === $pos || 0 === $pos ) {
				continue;
			}

			$name = strtolower( trim( substr( $line, 0, $pos ) ) );
			if ( ! isset( $headers[ $name ] ) ) {
				$headers[ $name ] = trim( substr( $line, $pos + 1 ) );
			}
		}

		return $headers;
	}

	/**
	 * Einen Teil (rekursiv) lesen.
	 *
	 * @param array $headers Kopfzeilen des Teils.
	 * @param string $body   Rumpf, noch kodiert.
	 * @param array $result  Ergebnis (wird ergänzt).
	 * @param int   $depth   Tiefe.
	 */
	protected static function parse_part( array $headers, $body, array &$result, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}

		$ctype  = isset( $headers['content-type'] ) ? $headers['content-type'] : 'text/plain; charset=us-ascii';
		$parsed = self::parse_header_params( $ctype );
		$type   = $parsed['value'];
		$params = $parsed['params'];
		$disp   = isset( $headers['content-disposition'] ) ? self::parse_header_params( $headers['content-disposition'] ) : array(
			'value'  => '',
			'params' => array(),
		);

		if ( 0 === strpos( $type, 'multipart/' ) && ! empty( $params['boundary'] ) ) {
			foreach ( self::split_multipart( $body, $params['boundary'] ) as $part ) {
				list( $h, $b ) = self::split_head_body( $part );
				self::parse_part( self::parse_headers( $h ), $b, $result, $depth + 1 );
			}
			return;
		}

		$encoding = strtolower( trim( isset( $headers['content-transfer-encoding'] ) ? $headers['content-transfer-encoding'] : '7bit' ) );
		$content  = self::decode_body( $body, $encoding );

		$filename = '';
		if ( ! empty( $disp['params']['filename'] ) ) {
			$filename = $disp['params']['filename'];
		} elseif ( ! empty( $params['name'] ) ) {
			$filename = $params['name'];
		}
		$filename = self::decode_words( $filename );

		$is_attachment = 'attachment' === $disp['value'] || '' !== $filename;
		$is_text       = in_array( $type, array( 'text/plain', 'text/html' ), true );

		if ( $is_text && ! $is_attachment ) {
			$charset = isset( $params['charset'] ) ? $params['charset'] : '';
			$content = self::to_utf8( $content, $charset );

			if ( 'text/html' === $type ) {
				$result['html'] .= ( '' === $result['html'] ? '' : "\n" ) . $content;
			} else {
				$result['text'] .= ( '' === $result['text'] ? '' : "\n\n" ) . $content;
			}
			return;
		}

		// Alles andere ist ein Anhang (auch eingebettete Mails und Bilder).
		$content_id = '';
		if ( ! empty( $headers['content-id'] ) ) {
			$ids        = self::extract_ids( $headers['content-id'] );
			$content_id = $ids ? $ids[0] : trim( $headers['content-id'], " \t<>" );
		}

		if ( '' === $filename ) {
			$filename = self::fallback_filename( $type, count( $result['attachments'] ) + 1 );
		}

		$result['attachments'][] = array(
			'filename'   => self::clean_filename( $filename ),
			'mime'       => '' !== $type ? $type : 'application/octet-stream',
			'content'    => $content,
			'content_id' => $content_id,
			'inline'     => 'inline' === $disp['value'] || ( '' !== $content_id && 'attachment' !== $disp['value'] ),
			'size'       => strlen( $content ),
		);
	}

	/**
	 * Mehrteilige Nachricht in ihre Teile schneiden.
	 *
	 * @param string $body     Rumpf.
	 * @param string $boundary Trennmarke ohne die führenden Striche.
	 * @return string[]
	 */
	public static function split_multipart( $body, $boundary ) {
		$segments = explode( '--' . $boundary, $body );
		array_shift( $segments ); // Text vor der ersten Marke.

		$parts = array();
		foreach ( $segments as $segment ) {
			if ( 0 === strpos( $segment, '--' ) ) {
				break; // Schlussmarke.
			}

			// Rest der Markenzeile (Leerzeichen, Zeilenumbruch) abschneiden.
			$nl = strpos( $segment, "\n" );
			if ( false === $nl ) {
				continue;
			}
			$segment = substr( $segment, $nl + 1 );

			// Der Zeilenumbruch vor der nächsten Marke gehört zur Marke.
			$segment = preg_replace( '/\r?\n$/', '', $segment, 1 );

			$parts[] = $segment;
		}

		return $parts;
	}

	/**
	 * Header mit Parametern zerlegen ("text/plain; charset=utf-8").
	 *
	 * Versteht Anführungszeichen und RFC 2231 (filename*=UTF-8''…, auch
	 * mehrteilig als filename*0*, filename*1*).
	 *
	 * @param string $value Kopfzeile.
	 * @return array {value, params}
	 */
	public static function parse_header_params( $value ) {
		$value = (string) $value;
		$out   = array(
			'value'  => '',
			'params' => array(),
		);

		$pos = self::find_unquoted( $value, ';' );
		if ( false === $pos ) {
			$out['value'] = strtolower( trim( $value ) );
			return $out;
		}

		$out['value'] = strtolower( trim( substr( $value, 0, $pos ) ) );
		$rest         = substr( $value, $pos + 1 );

		$simple   = array();
		$extended = array(); // Name => [Index => [Wert, kodiert?]].

		while ( '' !== trim( $rest ) ) {
			$pos  = self::find_unquoted( $rest, ';' );
			$pair = false === $pos ? $rest : substr( $rest, 0, $pos );
			$rest = false === $pos ? '' : substr( $rest, $pos + 1 );

			$eq = strpos( $pair, '=' );
			if ( false === $eq ) {
				continue;
			}

			$name = strtolower( trim( substr( $pair, 0, $eq ) ) );
			$val  = trim( substr( $pair, $eq + 1 ) );

			if ( strlen( $val ) >= 2 && '"' === $val[0] && '"' === substr( $val, -1 ) ) {
				$val = stripcslashes( substr( $val, 1, -1 ) );
			}

			// "name*=" (kodiert), "name*0=" / "name*0*=" (mehrteilig, ggf. kodiert).
			if ( preg_match( '/^([^*]+)\*(?:(\d+)(\*)?)?$/', $name, $m ) ) {
				$has_index                   = isset( $m[2] ) && '' !== $m[2];
				$index                       = $has_index ? (int) $m[2] : 0;
				$extended[ $m[1] ][ $index ] = array( $val, ! $has_index || ! empty( $m[3] ) );
				continue;
			}

			$simple[ $name ] = $val;
		}

		foreach ( $extended as $name => $chunks ) {
			ksort( $chunks );
			$joined  = '';
			$charset = '';
			foreach ( $chunks as $i => $chunk ) {
				$text = $chunk[0];
				if ( $chunk[1] ) {
					if ( 0 === $i && preg_match( "/^([^']*)'[^']*'(.*)$/s", $text, $m ) ) {
						$charset = $m[1];
						$text    = $m[2];
					}
					$text = rawurldecode( $text );
				}
				$joined .= $text;
			}
			$simple[ $name ] = '' !== $charset ? self::to_utf8( $joined, $charset ) : $joined;
		}

		$out['params'] = $simple;

		return $out;
	}

	/**
	 * Position des ersten Zeichens außerhalb von Anführungszeichen.
	 *
	 * @param string $text Text.
	 * @param string $char Gesuchtes Zeichen.
	 * @return int|false
	 */
	protected static function find_unquoted( $text, $char ) {
		$quoted = false;
		$len    = strlen( $text );

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $text[ $i ];
			if ( '\\' === $c && $quoted ) {
				$i++;
				continue;
			}
			if ( '"' === $c ) {
				$quoted = ! $quoted;
				continue;
			}
			if ( ! $quoted && $c === $char ) {
				return $i;
			}
		}

		return false;
	}

	/**
	 * Transferkodierung auflösen.
	 *
	 * @param string $body     Kodierter Rumpf.
	 * @param string $encoding base64, quoted-printable, 7bit, 8bit, binary.
	 * @return string
	 */
	public static function decode_body( $body, $encoding ) {
		switch ( $encoding ) {
			case 'base64':
				$decoded = base64_decode( preg_replace( '/[^A-Za-z0-9+\/=]/', '', $body ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				return false === $decoded ? '' : $decoded;

			case 'quoted-printable':
				return quoted_printable_decode( $body );

			default:
				return $body;
		}
	}

	/**
	 * Nach UTF-8 umwandeln. Unbekannte oder kaputte Angaben fallen auf
	 * Windows-1252 zurück – das ist in der Praxis die häufigste Ursache für
	 * „Müll“ in alten Mails.
	 *
	 * @param string $text    Text.
	 * @param string $charset Zeichensatz laut Mail.
	 * @return string
	 */
	public static function to_utf8( $text, $charset = '' ) {
		$text    = (string) $text;
		$charset = strtolower( trim( (string) $charset, " \t\"'" ) );

		if ( '' === $text ) {
			return '';
		}

		if ( '' === $charset || 'us-ascii' === $charset || 'utf-8' === $charset || 'utf8' === $charset ) {
			if ( self::is_utf8( $text ) ) {
				return $text;
			}
			$charset = 'windows-1252';
		}

		if ( 'iso-8859-1' === $charset || 'latin1' === $charset ) {
			// Windows-1252 ist eine Obermenge, die Mailprogramme in der Praxis so lesen.
			$charset = 'windows-1252';
		}

		if ( function_exists( 'mb_convert_encoding' ) ) {
			$converted = @mb_convert_encoding( $text, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_string( $converted ) && self::is_utf8( $converted ) ) {
				return $converted;
			}
		}

		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( $charset, 'UTF-8//IGNORE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_string( $converted ) ) {
				return $converted;
			}
		}

		return self::is_utf8( $text ) ? $text : self::latin1_to_utf8( $text );
	}

	/**
	 * Ob der Text gültiges UTF-8 ist.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	protected static function is_utf8( $text ) {
		return 1 === preg_match( '//u', $text );
	}

	/**
	 * RFC-2047-Kopfzeilen entschlüsseln ("=?UTF-8?B?…?=").
	 *
	 * @param string $value Kopfzeile.
	 * @return string UTF-8.
	 */
	public static function decode_words( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		if ( false === strpos( $value, '=?' ) ) {
			return self::to_utf8( $value );
		}

		// Zwischen zwei kodierten Wörtern ist Leerraum nicht Teil des Textes.
		$value = preg_replace( '/(\?=)\s+(?==\?)/', '$1', $value );

		return (string) preg_replace_callback(
			'/=\?([^?\s]+)\?([bBqQ])\?([^?]*)\?=/',
			static function ( $m ) {
				$charset = preg_replace( '/\*.*$/', '', $m[1] ); // RFC 2231: Sprachangabe.
				if ( 'b' === strtolower( $m[2] ) ) {
					$text = base64_decode( $m[3], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					$text = false === $text ? '' : $text;
				} else {
					$text = quoted_printable_decode( str_replace( '_', ' ', $m[3] ) );
				}
				return self::to_utf8( $text, $charset );
			},
			$value
		);
	}

	/**
	 * Adressliste lesen.
	 *
	 * @param string $header Wert von From/To/Cc.
	 * @return array Liste aus {name, email}.
	 */
	public static function parse_addresses( $header ) {
		$header = (string) $header;
		$out    = array();

		foreach ( self::split_addresses( $header ) as $item ) {
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}

			$name  = '';
			$email = '';

			if ( preg_match( '/^(.*?)<([^<>]*)>\s*$/s', $item, $m ) ) {
				$name  = trim( $m[1] );
				$email = trim( $m[2] );
			} else {
				$email = trim( preg_replace( '/\([^)]*\)/', '', $item ) );
			}

			if ( strlen( $name ) >= 2 && '"' === $name[0] && '"' === substr( $name, -1 ) ) {
				$name = stripcslashes( substr( $name, 1, -1 ) );
			}

			// Adresse mit Route ("<@a,@b:x@y>") und Anführungszeichen bereinigen.
			$email = trim( $email, " \t\"'" );
			$email = (string) preg_replace( '/^@[^:]*:/', '', $email );

			if ( false === strpos( $email, '@' ) ) {
				continue;
			}

			$out[] = array(
				'name'  => self::decode_words( $name ),
				'email' => $email,
			);
		}

		return $out;
	}

	/**
	 * Adressliste an den Kommas außerhalb von Anführungszeichen,
	 * Klammern und spitzen Klammern trennen. Gruppen ("Name: a, b;")
	 * werden aufgelöst.
	 *
	 * @param string $header Kopfzeile.
	 * @return string[]
	 */
	protected static function split_addresses( $header ) {
		$items   = array();
		$current = '';
		$quoted  = false;
		$angle   = 0;
		$comment = 0;
		$len     = strlen( $header );

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $header[ $i ];

			if ( '\\' === $c && ( $quoted || $comment ) && $i + 1 < $len ) {
				$current .= $c . $header[ ++$i ];
				continue;
			}

			if ( '"' === $c && 0 === $comment ) {
				$quoted = ! $quoted;
			} elseif ( ! $quoted ) {
				if ( '(' === $c ) {
					$comment++;
				} elseif ( ')' === $c && $comment > 0 ) {
					$comment--;
				} elseif ( 0 === $comment ) {
					if ( '<' === $c ) {
						$angle++;
					} elseif ( '>' === $c && $angle > 0 ) {
						$angle--;
					} elseif ( ( ',' === $c || ';' === $c ) && 0 === $angle ) {
						$items[] = $current;
						$current = '';
						continue;
					} elseif ( ':' === $c && 0 === $angle && false === strpos( $current, '@' ) ) {
						// Gruppenname ("Empfänger: a@b, c@d;") verwerfen.
						$current = '';
						continue;
					}
				}
			}

			$current .= $c;
		}

		$items[] = $current;

		return $items;
	}

	/**
	 * Alle <…>-Kennungen aus einer Zeile (Message-ID, References, Content-ID).
	 *
	 * @param string $value Kopfzeile.
	 * @return string[] Ohne spitze Klammern.
	 */
	public static function extract_ids( $value ) {
		if ( preg_match_all( '/<([^<>\s]+)>/', (string) $value, $m ) ) {
			return $m[1];
		}
		return array();
	}

	/**
	 * Zeilenumbrüche vereinheitlichen.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize_newlines( $text ) {
		return str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
	}

	/**
	 * HTML in lesbaren Klartext umwandeln.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html_to_text( $html ) {
		$html = (string) $html;
		$html = preg_replace( '#<(head|style|script|title)[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", (string) $html );
		$html = preg_replace( '#</(p|div|tr|li|h[1-6]|blockquote|table)>#i', "\n", (string) $html );
		$html = preg_replace( '#<li[^>]*>#i', '- ', (string) $html );
		$text = html_entity_decode( strip_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/ *\n */", "\n", (string) $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Kurzvorschau für die Mailliste.
	 *
	 * @param string $text Klartext.
	 * @param int    $max  Länge.
	 * @return string
	 */
	public static function snippet( $text, $max = 200 ) {
		// Zitate ("> …") interessieren in der Vorschau nicht.
		$lines = array();
		foreach ( explode( "\n", self::normalize_newlines( $text ) ) as $line ) {
			if ( 0 === strpos( ltrim( $line ), '>' ) ) {
				continue;
			}
			$lines[] = $line;
		}

		$flat = trim( (string) preg_replace( '/\s+/u', ' ', implode( ' ', $lines ) ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $flat, 0, $max );
		}

		return substr( $flat, 0, $max );
	}

	/**
	 * Dateiname für Anhänge ohne Namen.
	 *
	 * @param string $type  MIME-Typ.
	 * @param int    $index Laufende Nummer.
	 * @return string
	 */
	protected static function fallback_filename( $type, $index ) {
		$ext = array(
			'message/rfc822'  => 'eml',
			'image/png'       => 'png',
			'image/jpeg'      => 'jpg',
			'image/gif'       => 'gif',
			'application/pdf' => 'pdf',
			'text/calendar'   => 'ics',
		);

		return 'anhang-' . (int) $index . ( isset( $ext[ $type ] ) ? '.' . $ext[ $type ] : '' );
	}

	/**
	 * Dateinamen säubern: kein Pfad, keine Steuerzeichen.
	 *
	 * @param string $name Name aus der Mail.
	 * @return string
	 */
	public static function clean_filename( $name ) {
		$name = str_replace( array( '\\', '/' ), '_', (string) $name );
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $name );
		$name = trim( $name, " .\t" );

		if ( '' === $name ) {
			$name = 'anhang';
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 150 ) : substr( $name, 0, 150 );
	}

	/**
	 * Letzter Ausweg der Zeichensatzumwandlung: jedes Byte als Latin-1-Zeichen lesen.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected static function latin1_to_utf8( $text ) {
		$out = '';
		$len = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$b    = ord( $text[ $i ] );
			$out .= $b < 0x80 ? chr( $b ) : chr( 0xC0 | ( $b >> 6 ) ) . chr( 0x80 | ( $b & 0x3F ) );
		}
		return $out;
	}
}
