<?php
/**
 * Prüft den SMTP-Versand gegen einen kleinen Testserver (tests/fixtures/fake-smtp.php).
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Mailbox\MimeParser;
use Lindenweg16\Modules\Mailbox\SmtpMailer;
use PHPUnit\Framework\TestCase;

/**
 * SMTP-Versand.
 */
class MailboxSmtpMailerTest extends TestCase {

	/**
	 * Laufender Testserver.
	 *
	 * @var resource|null
	 */
	protected $proc = null;

	/**
	 * Ausgabedatei des Servers.
	 *
	 * @var string
	 */
	protected $out = '';

	/**
	 * Port des Servers.
	 *
	 * @var int
	 */
	protected $port = 0;

	protected function setUp(): void {
		if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer' ) ) {
			$this->markTestSkipped( 'PHPMailer fehlt (composer install).' );
		}

		$this->out = tempnam( sys_get_temp_dir(), 'lw16smtp' );
		unlink( $this->out );

		$this->proc = proc_open(
			array( PHP_BINARY, __DIR__ . '/fixtures/fake-smtp.php', $this->out, 'sven@example.de', 'pässwort' ),
			array( 1 => array( 'pipe', 'w' ) ),
			$pipes
		);

		$line = fgets( $pipes[1] );
		$this->assertMatchesRegularExpression( '/^PORT (\d+)/', (string) $line );
		$this->port = (int) substr( (string) $line, 5 );
	}

	protected function tearDown(): void {
		if ( is_resource( $this->proc ) ) {
			proc_terminate( $this->proc );
			proc_close( $this->proc );
		}
		if ( is_file( $this->out ) ) {
			unlink( $this->out );
		}
	}

	/**
	 * Zugangsdaten des Testpostfachs.
	 *
	 * @param array $over Abweichungen.
	 * @return array
	 */
	protected function account( array $over = array() ) {
		return array_merge(
			array(
				'smtp_host'   => '127.0.0.1',
				'smtp_port'   => $this->port,
				'smtp_secure' => 'none',
				'username'    => 'sven@example.de',
				'password'    => 'pässwort',
				'email'       => 'sven@example.de',
				'from_name'   => 'Sven Meendermann',
			),
			$over
		);
	}

	/**
	 * Verbindungstest meldet Erfolg bei richtigen Zugangsdaten.
	 */
	public function test_verbindungstest() {
		SmtpMailer::test( $this->account() );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Falsches Passwort: verständliche Meldung.
	 */
	public function test_falsches_passwort() {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Anmeldung am SMTP-Server abgelehnt' );
		SmtpMailer::test( $this->account( array( 'password' => 'falsch' ) ) );
	}

	/**
	 * Eine Mail mit Umlauten, Anhang und Antwortkopf: Umschlag, Kopfzeilen und
	 * Inhalt kommen so an, wie sie gesendet wurden – gelesen mit dem eigenen Parser.
	 */
	public function test_senden_und_zurueckparsen() {
		$att = tempnam( sys_get_temp_dir(), 'lw16att' );
		file_put_contents( $att, "Rechnung\x00\x01Inhalt" );

		$result = SmtpMailer::send(
			$this->account(),
			array(
				'to'          => array( array( 'email' => 'kundin@example.de', 'name' => 'Käthe Müller' ) ),
				'cc'          => array( array( 'email' => 'katrin@example.de', 'name' => '' ) ),
				'bcc'         => array( array( 'email' => 'chef@example.de', 'name' => '' ) ),
				'reply_to'    => array( array( 'email' => 'info@example.de', 'name' => 'Lindenweg16' ) ),
				'subject'     => 'Rückfrage zu Bestellung #1234',
				'html'        => '<p>Hallo Käthe, <b>danke</b>!</p>',
				'text'        => 'Hallo Käthe, danke!',
				'attachments' => array( array( 'path' => $att, 'name' => 'Rechnung März.pdf', 'type' => 'application/pdf' ) ),
				'in_reply_to' => 'orig@kundin.example',
				'references'  => array( 'erste@kundin.example', 'orig@kundin.example' ),
			)
		);
		unlink( $att );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{24}@example\.de$/', $result['message_id'] );

		// Kurz warten, bis der Server die Datei geschrieben hat.
		for ( $i = 0; $i < 50 && ! is_file( $this->out ); $i++ ) {
			usleep( 20000 );
		}
		$received = (string) file_get_contents( $this->out );

		$this->assertStringContainsString( 'X-Envelope-From: sven@example.de', $received );
		$this->assertStringContainsString( 'chef@example.de', $received, 'Bcc geht im Umschlag mit' );

		$parsed = MimeParser::parse( substr( $received, strpos( $received, "\r\n", strpos( $received, "\r\n" ) + 2 ) + 2 ) );

		$this->assertSame( 'Rückfrage zu Bestellung #1234', $parsed['subject'] );
		$this->assertSame( 'Sven Meendermann', $parsed['from']['name'] );
		$this->assertSame( 'sven@example.de', $parsed['from']['email'] );
		$this->assertSame( 'Käthe Müller', $parsed['to'][0]['name'] );
		$this->assertSame( 'katrin@example.de', $parsed['cc'][0]['email'] );
		$this->assertSame( 'info@example.de', $parsed['reply_to'][0]['email'] );
		$this->assertSame( $result['message_id'], $parsed['message_id'] );
		$this->assertSame( 'orig@kundin.example', $parsed['in_reply_to'] );
		$this->assertSame( array( 'erste@kundin.example', 'orig@kundin.example' ), $parsed['references'] );
		$this->assertStringContainsString( '<b>danke</b>', $parsed['html'] );
		$this->assertStringContainsString( 'Hallo Käthe, danke!', $parsed['text'] );
		$this->assertCount( 1, $parsed['attachments'] );
		$this->assertSame( 'Rechnung März.pdf', $parsed['attachments'][0]['filename'] );
		$this->assertSame( "Rechnung\x00\x01Inhalt", $parsed['attachments'][0]['content'] );

		// Die zurückgegebene Kopie ist dieselbe Nachricht (für den Gesendet-Ordner).
		$copy = MimeParser::parse( $result['raw'] );
		$this->assertSame( 'Rückfrage zu Bestellung #1234', $copy['subject'] );
		$this->assertSame( $result['message_id'], $copy['message_id'] );
	}
}
