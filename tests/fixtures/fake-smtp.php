<?php
/**
 * Minimaler SMTP-Server für Tests (eine Verbindung, ohne TLS).
 *
 * Aufruf: php tests/fixtures/fake-smtp.php <ausgabedatei> <benutzer> <passwort>
 * Schreibt "PORT <n>" auf die Standardausgabe, sobald er lauscht, und die
 * empfangene Nachricht in die Ausgabedatei (Umschlag als Kopfzeilen davor).
 */

$out  = $argv[1];
$user = $argv[2];
$pass = $argv[3];

$srv = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
$name = stream_socket_get_name( $srv, false );
echo 'PORT ' . substr( strrchr( $name, ':' ), 1 ) . "\n";
fflush( STDOUT );

$conn = stream_socket_accept( $srv, 20 );
if ( ! $conn ) {
	exit( 1 );
}

$say = static function ( $s ) use ( $conn ) {
	fwrite( $conn, $s . "\r\n" );
};

$say( '220 fake.example ESMTP' );
$from = '';
$rcpt = array();
$authed = false;

while ( ( $line = fgets( $conn ) ) !== false ) {
	$line = rtrim( $line, "\r\n" );
	$cmd  = strtoupper( strtok( $line, ' ' ) );

	switch ( $cmd ) {
		case 'EHLO':
		case 'HELO':
			$say( '250-fake.example' );
			$say( '250-8BITMIME' );
			$say( '250 AUTH PLAIN LOGIN' );
			break;
		case 'AUTH':
			$parts = explode( ' ', $line );
			if ( 'PLAIN' === strtoupper( $parts[1] ) ) {
				$cred = isset( $parts[2] ) ? $parts[2] : '';
				if ( '' === $cred ) {
					$say( '334 ' );
					$cred = rtrim( fgets( $conn ), "\r\n" );
				}
				$bits = explode( "\0", base64_decode( $cred ) );
				$ok   = isset( $bits[2] ) && $bits[1] === $user && $bits[2] === $pass;
			} else {
				$say( '334 ' . base64_encode( 'Username:' ) );
				$u = base64_decode( rtrim( fgets( $conn ), "\r\n" ) );
				$say( '334 ' . base64_encode( 'Password:' ) );
				$p = base64_decode( rtrim( fgets( $conn ), "\r\n" ) );
				$ok = $u === $user && $p === $pass;
			}
			if ( $ok ) {
				$authed = true;
				$say( '235 2.7.0 Authentication successful' );
			} else {
				$say( '535 5.7.8 Authentication credentials invalid' );
			}
			break;
		case 'MAIL':
			if ( ! $authed ) {
				$say( '530 5.7.0 Authentication required' );
				break;
			}
			preg_match( '/<([^>]*)>/', $line, $m );
			$from = $m[1];
			$say( '250 OK' );
			break;
		case 'RCPT':
			preg_match( '/<([^>]*)>/', $line, $m );
			$rcpt[] = $m[1];
			$say( '250 OK' );
			break;
		case 'DATA':
			$say( '354 End data with <CR><LF>.<CR><LF>' );
			$data = '';
			while ( ( $l = fgets( $conn ) ) !== false ) {
				if ( "." === rtrim( $l, "\r\n" ) ) {
					break;
				}
				$data .= 0 === strpos( $l, '..' ) ? substr( $l, 1 ) : $l;
			}
			file_put_contents( $out, 'X-Envelope-From: ' . $from . "\r\nX-Envelope-To: " . implode( ',', $rcpt ) . "\r\n" . $data );
			$say( '250 OK queued' );
			break;
		case 'RSET':
			$say( '250 OK' );
			break;
		case 'NOOP':
			$say( '250 OK' );
			break;
		case 'QUIT':
			$say( '221 Bye' );
			break 2;
		default:
			$say( '502 Command not implemented' );
	}
}
