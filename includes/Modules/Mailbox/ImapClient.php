<?php
/**
 * Schlanker IMAP-Client auf Socket-Basis.
 *
 * Bewusst ohne die PHP-IMAP-Erweiterung (seit PHP 8.4 nicht mehr im Kern,
 * bei vielen Hostern nicht aktiv) und ohne Fremdbibliothek: Das Plugin
 * kommt ohne Build-Schritt aus. Der Client kann genau das, was die Mailbox
 * braucht – anmelden, Ordner listen, Nachrichten nach UID holen, Flags
 * setzen, verschieben, ablegen (APPEND).
 *
 * Verbindung standardmäßig per TLS (Port 993, wie bei IONOS). Frei von
 * WordPress; Tests füttern den Client über `from_stream()`.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

/**
 * IMAP-Client.
 */
class ImapClient {

	/**
	 * Marke für eingebettete Literale in gelesenen Antwortzeilen.
	 */
	const LIT = "\x01";

	/**
	 * Verbindungs-Stream.
	 *
	 * @var resource|null
	 */
	protected $stream = null;

	/**
	 * Zähler für Befehlsmarken (A1, A2 …).
	 *
	 * @var int
	 */
	protected $tag = 0;

	/**
	 * Fähigkeiten laut Server (großgeschrieben).
	 *
	 * @var string[]
	 */
	protected $caps = array();

	/**
	 * Verbindungsdaten.
	 *
	 * @var array
	 */
	protected $config = array();

	/**
	 * Den gewählten Ordner merken.
	 *
	 * @var string
	 */
	protected $selected = '';

	/**
	 * Neuer Client.
	 *
	 * @param string $host    Server.
	 * @param int    $port    Port (993 für TLS).
	 * @param string $secure  ssl (TLS von Anfang an), tls (STARTTLS) oder none (nur für Tests/lokal).
	 * @param int    $timeout Sekunden.
	 * @param array  $options Zusätze: verify_peer (bool).
	 */
	public function __construct( $host, $port = 993, $secure = 'ssl', $timeout = 20, array $options = array() ) {
		$this->config = array(
			'host'    => (string) $host,
			'port'    => (int) $port,
			'secure'  => (string) $secure,
			'timeout' => max( 3, (int) $timeout ),
			'options' => $options,
		);
	}

	/**
	 * Client auf einem fertigen Stream (für Tests).
	 *
	 * @param resource $stream Stream, der schon verbunden ist.
	 * @return static
	 */
	public static function from_stream( $stream ) {
		$client         = new static( 'test' );
		$client->stream = $stream;
		return $client;
	}

	/**
	 * Verbinden und die Begrüßung lesen.
	 *
	 * @throws \RuntimeException Wenn die Verbindung scheitert.
	 */
	public function connect() {
		if ( null === $this->stream ) {
			$c       = $this->config;
			$verify  = ! isset( $c['options']['verify_peer'] ) || $c['options']['verify_peer'];
			$context = stream_context_create(
				array(
					'ssl' => array(
						'verify_peer'       => $verify,
						'verify_peer_name'  => $verify,
						'peer_name'         => $c['host'],
						'SNI_enabled'       => true,
						'allow_self_signed' => ! $verify,
					),
				)
			);

			$scheme = 'ssl' === $c['secure'] ? 'ssl' : 'tcp';
			$errno  = 0;
			$errstr = '';
			$stream = @stream_socket_client( // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$scheme . '://' . $c['host'] . ':' . $c['port'],
				$errno,
				$errstr,
				$c['timeout'],
				STREAM_CLIENT_CONNECT,
				$context
			);

			if ( ! $stream ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: 1: Server, 2: Port, 3: Fehlertext */
						__( 'Keine Verbindung zu %1$s:%2$d (%3$s).', 'lindenweg16' ),
						$c['host'],
						$c['port'],
						'' !== $errstr ? $errstr : 'Fehler ' . $errno
					)
				);
			}

			stream_set_timeout( $stream, $c['timeout'] );
			$this->stream = $stream;
		}

		$greeting = $this->read_line();

		if ( 0 !== strpos( $greeting, '* OK' ) && 0 !== strpos( $greeting, '* PREAUTH' ) ) {
			$this->close();
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: Antwort des Servers */
					__( 'Der Server hat die Verbindung abgelehnt: %s', 'lindenweg16' ),
					trim( $greeting )
				)
			);
		}

		if ( 'tls' === $this->config['secure'] ) {
			$this->command( array( 'STARTTLS' ) );
			$crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
			if ( ! @stream_socket_enable_crypto( $this->stream, true, $crypto ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$this->close();
				throw new \RuntimeException( __( 'Die TLS-Verschlüsselung (STARTTLS) konnte nicht aufgebaut werden.', 'lindenweg16' ) );
			}
		}
	}

	/**
	 * Anmelden.
	 *
	 * @param string $user     Benutzername.
	 * @param string $password Passwort.
	 * @throws \RuntimeException Bei falschen Zugangsdaten.
	 */
	public function login( $user, $password ) {
		try {
			$this->command( array( 'LOGIN', $this->arg( $user ), $this->arg( $password ) ) );
		} catch ( \RuntimeException $e ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: Antwort des Servers */
					__( 'Anmeldung am Postfach abgelehnt: %s', 'lindenweg16' ),
					$e->getMessage()
				),
				$e->getCode(),
				$e
			);
		}

		$this->capability();
	}

	/**
	 * Fähigkeiten des Servers.
	 *
	 * @return string[] Großgeschrieben.
	 */
	public function capability() {
		$res        = $this->command( array( 'CAPABILITY' ) );
		$this->caps = array();

		foreach ( $res['untagged'] as $u ) {
			if ( 0 === stripos( $u['text'], '* CAPABILITY ' ) ) {
				$this->caps = array_merge( $this->caps, preg_split( '/\s+/', strtoupper( trim( substr( $u['text'], 13 ) ) ) ) );
			}
		}

		return $this->caps;
	}

	/**
	 * Kann der Server das?
	 *
	 * @param string $cap Fähigkeit, z. B. MOVE oder UIDPLUS.
	 * @return bool
	 */
	public function has( $cap ) {
		return in_array( strtoupper( $cap ), $this->caps, true );
	}

	/**
	 * Alle Ordner.
	 *
	 * @return array Liste aus {name (UTF-8), delimiter, flags (klein geschrieben)}.
	 */
	public function list_folders() {
		$res = $this->command( array( 'LIST', '""', '"*"' ) );
		$out = array();

		foreach ( $res['untagged'] as $u ) {
			if ( ! preg_match( '/^\* LIST \(([^)]*)\) (NIL|"(?:[^"\\\\]|\\\\.)*") (.+)$/s', $u['text'], $m ) ) {
				continue;
			}

			$name = $this->resolve_string( trim( $m[3] ), $u['literals'] );
			$flag = '' === trim( $m[1] ) ? array() : preg_split( '/\s+/', strtolower( trim( $m[1] ) ) );

			if ( in_array( '\\noselect', $flag, true ) || in_array( '\\nonexistent', $flag, true ) ) {
				continue;
			}

			$out[] = array(
				'name'      => self::utf7_decode( $name ),
				'delimiter' => 'NIL' === $m[2] ? '' : stripcslashes( substr( $m[2], 1, -1 ) ),
				'flags'     => $flag,
			);
		}

		return $out;
	}

	/**
	 * Ordner öffnen.
	 *
	 * @param string $folder Name (UTF-8).
	 * @return array {exists, uidvalidity, uidnext}
	 */
	public function select( $folder ) {
		$res  = $this->command( array( 'SELECT', $this->quote( self::utf7_encode( $folder ) ) ) );
		$info = array(
			'exists'      => 0,
			'uidvalidity' => 0,
			'uidnext'     => 0,
		);

		foreach ( $res['untagged'] as $u ) {
			if ( preg_match( '/^\* (\d+) EXISTS/i', $u['text'], $m ) ) {
				$info['exists'] = (int) $m[1];
			} elseif ( preg_match( '/\[UIDVALIDITY (\d+)\]/i', $u['text'], $m ) ) {
				$info['uidvalidity'] = (int) $m[1];
			} elseif ( preg_match( '/\[UIDNEXT (\d+)\]/i', $u['text'], $m ) ) {
				$info['uidnext'] = (int) $m[1];
			}
		}

		$this->selected = $folder;

		return $info;
	}

	/**
	 * Nachrichten suchen.
	 *
	 * @param string $criteria IMAP-Suchausdruck, z. B. "ALL" oder "SINCE 1-Oct-2026".
	 * @return int[] UIDs, aufsteigend.
	 */
	public function uid_search( $criteria = 'ALL' ) {
		$res  = $this->command( array( 'UID', 'SEARCH', $criteria ) );
		$uids = array();

		foreach ( $res['untagged'] as $u ) {
			if ( 0 === stripos( $u['text'], '* SEARCH' ) ) {
				foreach ( preg_split( '/\s+/', trim( substr( $u['text'], 8 ) ) ) as $n ) {
					if ( ctype_digit( $n ) ) {
						$uids[] = (int) $n;
					}
				}
			}
		}

		sort( $uids );

		return $uids;
	}

	/**
	 * Flags und Größe für einen UID-Bereich.
	 *
	 * @param string $set UID-Menge, z. B. "5:*" oder "1,2,3".
	 * @return array UID => {flags, size}
	 */
	public function uid_fetch_meta( $set ) {
		$res = $this->command( array( 'UID', 'FETCH', $set, '(UID FLAGS RFC822.SIZE)' ) );
		$out = array();

		foreach ( $res['untagged'] as $u ) {
			$meta = $this->parse_fetch( $u );
			if ( null !== $meta && isset( $meta['uid'] ) ) {
				$out[ $meta['uid'] ] = array(
					'flags' => $meta['flags'],
					'size'  => $meta['size'],
				);
			}
		}

		return $out;
	}

	/**
	 * Eine Nachricht vollständig holen, ohne sie als gelesen zu markieren.
	 *
	 * @param int  $uid        UID.
	 * @param bool $header_only Nur die Kopfzeilen.
	 * @return array|null {uid, flags, raw} oder null, wenn es die Nachricht nicht (mehr) gibt.
	 */
	public function uid_fetch_message( $uid, $header_only = false ) {
		$part = $header_only ? 'BODY.PEEK[HEADER]' : 'BODY.PEEK[]';
		$res  = $this->command( array( 'UID', 'FETCH', (string) (int) $uid, '(UID FLAGS ' . $part . ')' ) );

		foreach ( $res['untagged'] as $u ) {
			$meta = $this->parse_fetch( $u );
			if ( null !== $meta && isset( $meta['uid'] ) && (int) $uid === $meta['uid'] ) {
				return array(
					'uid'   => $meta['uid'],
					'flags' => $meta['flags'],
					'raw'   => (string) $meta['body'],
				);
			}
		}

		return null;
	}

	/**
	 * Flags setzen oder entfernen.
	 *
	 * @param int      $uid    UID.
	 * @param string[] $flags  z. B. array( '\Seen' ).
	 * @param bool     $add    true = setzen, false = entfernen.
	 */
	public function uid_store( $uid, array $flags, $add = true ) {
		$this->command(
			array(
				'UID',
				'STORE',
				(string) (int) $uid,
				( $add ? '+' : '-' ) . 'FLAGS.SILENT',
				'(' . implode( ' ', $flags ) . ')',
			)
		);
	}

	/**
	 * Nachricht in einen anderen Ordner verschieben.
	 *
	 * @param int    $uid  UID.
	 * @param string $dest Zielordner (UTF-8).
	 */
	public function uid_move( $uid, $dest ) {
		$dest = $this->quote( self::utf7_encode( $dest ) );

		if ( $this->has( 'MOVE' ) ) {
			$this->command( array( 'UID', 'MOVE', (string) (int) $uid, $dest ) );
			return;
		}

		$this->command( array( 'UID', 'COPY', (string) (int) $uid, $dest ) );
		$this->uid_store( $uid, array( '\\Deleted' ) );
		$this->expunge( $uid );
	}

	/**
	 * Als gelöscht markierte Nachrichten endgültig entfernen.
	 *
	 * @param int $uid Nur diese UID (braucht UIDPLUS); 0 = alle markierten.
	 */
	public function expunge( $uid = 0 ) {
		if ( $uid && $this->has( 'UIDPLUS' ) ) {
			$this->command( array( 'UID', 'EXPUNGE', (string) (int) $uid ) );
			return;
		}

		$this->command( array( 'EXPUNGE' ) );
	}

	/**
	 * Nachricht in einem Ordner ablegen (Kopie im Gesendet-Ordner).
	 *
	 * @param string   $folder Ordner (UTF-8).
	 * @param string   $raw    Vollständige Nachricht.
	 * @param string[] $flags  z. B. array( '\Seen' ).
	 * @return int UID der abgelegten Nachricht, 0 wenn der Server sie nicht nennt.
	 */
	public function append( $folder, $raw, array $flags = array() ) {
		$raw   = str_replace( array( "\r\n", "\r" ), "\n", (string) $raw );
		$raw   = str_replace( "\n", "\r\n", $raw );
		$parts = array( 'APPEND', $this->quote( self::utf7_encode( $folder ) ) );

		if ( $flags ) {
			$parts[] = '(' . implode( ' ', $flags ) . ')';
		}

		$parts[] = array( 'literal' => $raw );
		$res     = $this->command( $parts );

		if ( preg_match( '/\[APPENDUID \d+ (\d+)\]/i', $res['text'], $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	/**
	 * Ordner anlegen.
	 *
	 * @param string $folder Name (UTF-8).
	 */
	public function create_folder( $folder ) {
		$this->command( array( 'CREATE', $this->quote( self::utf7_encode( $folder ) ) ) );
	}

	/**
	 * Verbindung halten.
	 */
	public function noop() {
		$this->command( array( 'NOOP' ) );
	}

	/**
	 * Abmelden und schließen.
	 */
	public function logout() {
		try {
			if ( $this->stream ) {
				$this->command( array( 'LOGOUT' ) );
			}
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Der Server schließt oft schon vorher.
		}

		$this->close();
	}

	/**
	 * Stream schließen.
	 */
	public function close() {
		if ( is_resource( $this->stream ) ) {
			@fclose( $this->stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		$this->stream = null;
	}

	/* --------------------------------------------------------------------
	 * Protokoll
	 * ------------------------------------------------------------------ */

	/**
	 * Einen Befehl senden und die Antwort lesen.
	 *
	 * @param array $parts Teile: Strings (roh) oder array('literal' => Bytes).
	 * @return array {status, text, untagged}
	 * @throws \RuntimeException Bei NO/BAD oder Verbindungsabbruch.
	 */
	protected function command( array $parts ) {
		$tag  = 'A' . ( ++$this->tag );
		$line = $tag;

		foreach ( $parts as $part ) {
			if ( is_array( $part ) ) {
				$bytes = (string) $part['literal'];
				$this->write( $line . ' {' . strlen( $bytes ) . "}\r\n" );
				$this->wait_continuation();
				$this->write( $bytes );
				$line = '';
				continue;
			}
			$line .= ' ' . $part;
		}

		$this->write( $line . "\r\n" );

		return $this->read_response( $tag );
	}

	/**
	 * Argument für einen Befehl: Anführungszeichen, bei Sonderzeichen als Literal.
	 *
	 * @param string $value Wert.
	 * @return string|array
	 */
	protected function arg( $value ) {
		$value = (string) $value;

		if ( preg_match( '/[^\x20-\x7E]/', $value ) ) {
			return array( 'literal' => $value );
		}

		return $this->quote( $value );
	}

	/**
	 * Als IMAP-String in Anführungszeichen.
	 *
	 * @param string $value Wert.
	 * @return string
	 */
	protected function quote( $value ) {
		return '"' . addcslashes( (string) $value, "\"\\" ) . '"';
	}

	/**
	 * Auf das "+" des Servers warten (Literal darf gesendet werden).
	 *
	 * @throws \RuntimeException Wenn der Server ablehnt.
	 */
	protected function wait_continuation() {
		$line = $this->read_line();

		if ( 0 !== strpos( $line, '+' ) ) {
			throw new \RuntimeException( trim( $line ) );
		}
	}

	/**
	 * Antwort bis zur Zeile mit der eigenen Marke lesen.
	 *
	 * @param string $tag Marke.
	 * @return array {status, text, untagged}
	 * @throws \RuntimeException Bei NO/BAD.
	 */
	protected function read_response( $tag ) {
		$untagged = array();

		while ( true ) {
			$line = $this->read_line();

			if ( 0 === strpos( $line, '* ' ) ) {
				$untagged[] = $this->read_untagged( $line );
				continue;
			}

			if ( 0 === strpos( $line, $tag . ' ' ) ) {
				if ( ! preg_match( '/^' . preg_quote( $tag, '/' ) . ' (OK|NO|BAD)\s*(.*)$/is', rtrim( $line ), $m ) ) {
					throw new \RuntimeException( trim( $line ) );
				}

				$status = strtoupper( $m[1] );
				if ( 'OK' !== $status ) {
					throw new \RuntimeException( trim( $m[2] ) !== '' ? trim( $m[2] ) : $status );
				}

				return array(
					'status'   => $status,
					'text'     => $m[2],
					'untagged' => $untagged,
				);
			}

			// Alles andere (Fortsetzung, fremde Marke) übergehen.
		}
	}

	/**
	 * Eine ungetaggte Antwort samt eingebetteter Literale lesen.
	 *
	 * @param string $line Erste Zeile.
	 * @return array {text, literals}
	 */
	protected function read_untagged( $line ) {
		$text     = '';
		$literals = array();

		while ( true ) {
			if ( preg_match( '/\{(\d+)\}\r?\n$/', $line, $m ) ) {
				$text      .= substr( $line, 0, -strlen( $m[0] ) ) . self::LIT . count( $literals ) . self::LIT;
				$literals[] = $this->read_bytes( (int) $m[1] );
				$line       = $this->read_line();
				continue;
			}

			$text .= rtrim( $line, "\r\n" );
			break;
		}

		return array(
			'text'     => $text,
			'literals' => $literals,
		);
	}

	/**
	 * Eine FETCH-Antwort auswerten.
	 *
	 * @param array $u Ungetaggte Antwort.
	 * @return array|null {uid, flags, size, body}
	 */
	protected function parse_fetch( array $u ) {
		if ( ! preg_match( '/^\* \d+ FETCH \(/i', $u['text'] ) ) {
			return null;
		}

		$text = $u['text'];
		$out  = array(
			'flags' => array(),
			'size'  => 0,
			'body'  => '',
		);

		if ( preg_match( '/\bUID (\d+)/i', $this->strip_literals( $text ), $m ) ) {
			$out['uid'] = (int) $m[1];
		}

		if ( preg_match( '/\bFLAGS \(([^)]*)\)/i', $this->strip_literals( $text ), $m ) ) {
			$out['flags'] = '' === trim( $m[1] ) ? array() : preg_split( '/\s+/', trim( $m[1] ) );
		}

		if ( preg_match( '/RFC822\.SIZE (\d+)/i', $this->strip_literals( $text ), $m ) ) {
			$out['size'] = (int) $m[1];
		}

		if ( preg_match( '/BODY\[[^\]]*\](?:<\d+>)? ' . preg_quote( self::LIT, '/' ) . '(\d+)' . preg_quote( self::LIT, '/' ) . '/', $text, $m ) ) {
			$out['body'] = isset( $u['literals'][ (int) $m[1] ] ) ? $u['literals'][ (int) $m[1] ] : '';
		}

		return $out;
	}

	/**
	 * Literal-Marken aus einem Text entfernen, damit Mail-Inhalt nie wie ein
	 * Protokollteil gelesen wird.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected function strip_literals( $text ) {
		return (string) preg_replace( '/' . self::LIT . '\d+' . self::LIT . '/', ' ', $text );
	}

	/**
	 * Einen Wert aus einer Antwort lesen: in Anführungszeichen, Atom oder Literal.
	 *
	 * @param string $token    Wert wie gelesen.
	 * @param array  $literals Literale der Antwort.
	 * @return string
	 */
	protected function resolve_string( $token, array $literals ) {
		if ( preg_match( '/^' . self::LIT . '(\d+)' . self::LIT . '$/', $token, $m ) ) {
			return isset( $literals[ (int) $m[1] ] ) ? $literals[ (int) $m[1] ] : '';
		}

		if ( strlen( $token ) >= 2 && '"' === $token[0] && '"' === substr( $token, -1 ) ) {
			return stripcslashes( substr( $token, 1, -1 ) );
		}

		return $token;
	}

	/**
	 * Eine Zeile lesen (beliebig lang).
	 *
	 * @return string Mit Zeilenende.
	 * @throws \RuntimeException Bei Abbruch oder Zeitüberschreitung.
	 */
	protected function read_line() {
		$this->require_stream();
		$line = '';

		while ( true ) {
			$chunk = fgets( $this->stream, 65536 );

			if ( false === $chunk ) {
				$meta = stream_get_meta_data( $this->stream );
				$this->close();
				throw new \RuntimeException(
					! empty( $meta['timed_out'] )
						? __( 'Zeitüberschreitung: Der Mailserver antwortet nicht.', 'lindenweg16' )
						: __( 'Der Mailserver hat die Verbindung beendet.', 'lindenweg16' )
				);
			}

			$line .= $chunk;

			if ( "\n" === substr( $chunk, -1 ) ) {
				return $line;
			}
		}
	}

	/**
	 * Genau so viele Bytes lesen.
	 *
	 * @param int $length Anzahl.
	 * @return string
	 * @throws \RuntimeException Bei Abbruch.
	 */
	protected function read_bytes( $length ) {
		$this->require_stream();
		$data = '';

		while ( strlen( $data ) < $length ) {
			$chunk = fread( $this->stream, min( 65536, $length - strlen( $data ) ) );

			if ( false === $chunk || '' === $chunk ) {
				$meta = stream_get_meta_data( $this->stream );
				$this->close();
				throw new \RuntimeException(
					! empty( $meta['timed_out'] )
						? __( 'Zeitüberschreitung: Der Mailserver antwortet nicht.', 'lindenweg16' )
						: __( 'Der Mailserver hat die Verbindung beendet.', 'lindenweg16' )
				);
			}

			$data .= $chunk;
		}

		return $data;
	}

	/**
	 * Schreiben.
	 *
	 * @param string $data Bytes.
	 * @throws \RuntimeException Bei Abbruch.
	 */
	protected function write( $data ) {
		$this->require_stream();
		$len = strlen( $data );
		$off = 0;

		while ( $off < $len ) {
			$n = @fwrite( $this->stream, substr( $data, $off, 65536 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

			if ( false === $n || 0 === $n ) {
				$this->close();
				throw new \RuntimeException( __( 'Der Mailserver hat die Verbindung beendet.', 'lindenweg16' ) );
			}

			$off += $n;
		}
	}

	/**
	 * Es muss eine Verbindung bestehen.
	 *
	 * @throws \RuntimeException Wenn nicht.
	 */
	protected function require_stream() {
		if ( ! is_resource( $this->stream ) ) {
			throw new \RuntimeException( __( 'Keine Verbindung zum Mailserver.', 'lindenweg16' ) );
		}
	}

	/* --------------------------------------------------------------------
	 * Ordnernamen (modifiziertes UTF-7, RFC 3501 §5.1.3)
	 * ------------------------------------------------------------------ */

	/**
	 * Ordnername nach IMAP-UTF-7.
	 *
	 * @param string $name UTF-8.
	 * @return string
	 */
	public static function utf7_encode( $name ) {
		$name = (string) $name;
		$out  = '';
		$buf  = '';

		$flush = static function () use ( &$out, &$buf ) {
			if ( '' !== $buf ) {
				$utf16 = function_exists( 'mb_convert_encoding' )
					? mb_convert_encoding( $buf, 'UTF-16BE', 'UTF-8' )
					: iconv( 'UTF-8', 'UTF-16BE', $buf );
				$out  .= '&' . rtrim( strtr( base64_encode( $utf16 ), '/', ',' ), '=' ) . '-'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				$buf   = '';
			}
		};

		foreach ( preg_split( '//u', $name, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$o = ord( $char[0] );
			if ( 1 === strlen( $char ) && $o >= 0x20 && $o <= 0x7E ) {
				$flush();
				$out .= '&' === $char ? '&-' : $char;
			} else {
				$buf .= $char;
			}
		}
		$flush();

		return $out;
	}

	/**
	 * Ordnername aus IMAP-UTF-7.
	 *
	 * @param string $name Wie vom Server geliefert.
	 * @return string UTF-8.
	 */
	public static function utf7_decode( $name ) {
		return (string) preg_replace_callback(
			'/&([^-]*)-/',
			static function ( $m ) {
				if ( '' === $m[1] ) {
					return '&';
				}

				$bin = base64_decode( strtr( $m[1], ',', '/' ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				if ( false === $bin ) {
					return $m[0];
				}

				return function_exists( 'mb_convert_encoding' )
					? mb_convert_encoding( $bin, 'UTF-8', 'UTF-16BE' )
					: iconv( 'UTF-16BE', 'UTF-8', $bin );
			},
			(string) $name
		);
	}

	/**
	 * Datum im IMAP-Format ("1-Oct-2026").
	 *
	 * @param int $timestamp Unix-Zeit.
	 * @return string
	 */
	public static function date( $timestamp ) {
		return gmdate( 'j-M-Y', (int) $timestamp );
	}
}
