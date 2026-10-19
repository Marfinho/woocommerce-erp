<?php
/**
 * Prüft das Lesen von Roh-Mails.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Mailbox\MimeParser;
use PHPUnit\Framework\TestCase;

/**
 * MIME-Parser.
 */
class MailboxMimeParserTest extends TestCase {

	/**
	 * Eine einfache Textmail mit allen Kopfzeilen.
	 */
	public function test_einfache_textmail() {
		$raw = "From: Katrin Muster <katrin@example.de>\r\n"
			. "To: Sven <sven@example.de>, info@example.de\r\n"
			. "Cc: \"Meier, Hans\" <hans@example.de>\r\n"
			. "Subject: Frage zur Bestellung\r\n"
			. "Date: Mon, 19 Oct 2026 10:15:00 +0200\r\n"
			. "Message-ID: <abc123@example.de>\r\n"
			. "In-Reply-To: <vorher@example.de>\r\n"
			. "References: <erste@example.de> <vorher@example.de>\r\n"
			. "Content-Type: text/plain; charset=utf-8\r\n"
			. "\r\n"
			. "Hallo,\r\n\r\nwann kommt meine Bestellung?\r\n";

		$m = MimeParser::parse( $raw );

		$this->assertSame( 'Frage zur Bestellung', $m['subject'] );
		$this->assertSame( 'Katrin Muster', $m['from']['name'] );
		$this->assertSame( 'katrin@example.de', $m['from']['email'] );
		$this->assertCount( 2, $m['to'] );
		$this->assertSame( 'info@example.de', $m['to'][1]['email'] );
		$this->assertSame( 'Meier, Hans', $m['cc'][0]['name'] );
		$this->assertSame( 'hans@example.de', $m['cc'][0]['email'] );
		$this->assertSame( 'abc123@example.de', $m['message_id'] );
		$this->assertSame( 'vorher@example.de', $m['in_reply_to'] );
		$this->assertSame( array( 'erste@example.de', 'vorher@example.de' ), $m['references'] );
		$this->assertSame( gmmktime( 8, 15, 0, 10, 19, 2026 ), $m['date'] );
		$this->assertStringContainsString( 'wann kommt meine Bestellung?', $m['text'] );
		$this->assertSame( '', $m['html'] );
		$this->assertSame( array(), $m['attachments'] );
	}

	/**
	 * Umlaute in Betreff und Namen (RFC 2047), B und Q.
	 */
	public function test_kodierte_kopfzeilen() {
		$this->assertSame( 'Grüße aus Haste', MimeParser::decode_words( '=?UTF-8?B?R3LDvMOfZSBhdXMgSGFzdGU=?=' ) );
		$this->assertSame( 'Müller', MimeParser::decode_words( '=?ISO-8859-1?Q?M=FCller?=' ) );
		// Zwei kodierte Wörter hintereinander: der Leerraum dazwischen entfällt.
		$this->assertSame( 'Grüße', MimeParser::decode_words( '=?UTF-8?Q?Gr=C3=BC?= =?UTF-8?Q?=C3=9Fe?=' ) );
		// Gemischt mit normalem Text.
		$this->assertSame( 'Re: Größe', MimeParser::decode_words( 'Re: =?UTF-8?Q?Gr=C3=B6=C3=9Fe?=' ) );

		$a = MimeParser::parse_addresses( '=?UTF-8?Q?J=C3=BCrgen_B=C3=A4cker?= <j@example.de>' );
		$this->assertSame( 'Jürgen Bäcker', $a[0]['name'] );
	}

	/**
	 * Adressen mit Komma im Namen, Kommentar und ohne Namen.
	 */
	public function test_adressen() {
		$a = MimeParser::parse_addresses( '"Schmidt, Anna" <anna@example.de>, bob@example.de (Bob), <carl@example.de>' );

		$this->assertCount( 3, $a );
		$this->assertSame( 'Schmidt, Anna', $a[0]['name'] );
		$this->assertSame( 'anna@example.de', $a[0]['email'] );
		$this->assertSame( 'bob@example.de', $a[1]['email'] );
		$this->assertSame( 'carl@example.de', $a[2]['email'] );
		$this->assertSame( array(), MimeParser::parse_addresses( 'undisclosed-recipients:;' ) );
		$this->assertSame( array(), MimeParser::parse_addresses( '' ) );
	}

	/**
	 * Mehrteilig: Text, HTML und ein Anhang.
	 */
	public function test_multipart_mit_anhang() {
		$pdf = "%PDF-1.4 test\x00\x01\x02";
		$raw = "From: a@example.de\r\nTo: b@example.de\r\nSubject: Rechnung\r\n"
			. "Content-Type: multipart/mixed; boundary=\"MIX\"\r\n\r\n"
			. "--MIX\r\n"
			. "Content-Type: multipart/alternative; boundary=\"ALT\"\r\n\r\n"
			. "--ALT\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
			. "Gr=C3=BC=C3=9Fe aus Haste\r\n"
			. "--ALT\r\nContent-Type: text/html; charset=utf-8\r\n\r\n"
			. "<p>Gr&uuml;&szlig;e <b>aus</b> Haste</p>\r\n"
			. "--ALT--\r\n"
			. "--MIX\r\n"
			. "Content-Type: application/pdf; name=\"Rechnung 2026.pdf\"\r\n"
			. "Content-Disposition: attachment; filename=\"Rechnung 2026.pdf\"\r\n"
			. "Content-Transfer-Encoding: base64\r\n\r\n"
			. chunk_split( base64_encode( $pdf ) )
			. "--MIX--\r\n";

		$m = MimeParser::parse( $raw );

		$this->assertSame( 'Grüße aus Haste', trim( $m['text'] ) );
		$this->assertStringContainsString( '<b>aus</b>', $m['html'] );
		$this->assertCount( 1, $m['attachments'] );
		$this->assertSame( 'Rechnung 2026.pdf', $m['attachments'][0]['filename'] );
		$this->assertSame( 'application/pdf', $m['attachments'][0]['mime'] );
		$this->assertSame( $pdf, $m['attachments'][0]['content'] );
		$this->assertFalse( $m['attachments'][0]['inline'] );
		$this->assertSame( 'Grüße aus Haste', $m['snippet'] );
	}

	/**
	 * Nur HTML: der Klartext wird abgeleitet.
	 */
	public function test_html_ohne_klartext() {
		$raw = "Subject: Newsletter\r\nContent-Type: text/html; charset=utf-8\r\n\r\n"
			. "<html><head><style>p{color:red}</style></head><body><h1>Hallo</h1><p>Zwei&nbsp;Worte<br>neue Zeile</p></body></html>";

		$m = MimeParser::parse( $raw );

		$this->assertStringContainsString( 'Hallo', $m['text'] );
		$this->assertStringContainsString( 'Zwei Worte', $m['text'] );
		$this->assertStringNotContainsString( 'color:red', $m['text'] );
		$this->assertStringContainsString( '<h1>', $m['html'] );
	}

	/**
	 * Eingebettetes Bild mit Content-ID.
	 */
	public function test_inline_bild() {
		$raw = "Content-Type: multipart/related; boundary=\"REL\"\r\n\r\n"
			. "--REL\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<img src=\"cid:logo@x\">\r\n"
			. "--REL\r\nContent-Type: image/png\r\nContent-ID: <logo@x>\r\nContent-Disposition: inline\r\nContent-Transfer-Encoding: base64\r\n\r\n"
			. base64_encode( 'PNGDATA' ) . "\r\n--REL--\r\n";

		$m = MimeParser::parse( $raw );

		$this->assertCount( 1, $m['attachments'] );
		$this->assertSame( 'logo@x', $m['attachments'][0]['content_id'] );
		$this->assertTrue( $m['attachments'][0]['inline'] );
		$this->assertSame( 'PNGDATA', $m['attachments'][0]['content'] );
		$this->assertSame( 'anhang-1.png', $m['attachments'][0]['filename'] );
	}

	/**
	 * Alte Mails in Latin-1 und Windows-1252 werden nach UTF-8 gebracht.
	 */
	public function test_zeichensaetze() {
		$latin = "Subject: x\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: 8bit\r\n\r\nGr\xFC\xDFe \x80";
		$m     = MimeParser::parse( $latin );
		$this->assertSame( "Grüße €", $m['text'] );

		// Ohne Angabe, aber kein UTF-8: Windows-1252.
		$kaputt = "Subject: x\r\n\r\nSch\xF6n";
		$this->assertSame( 'Schön', MimeParser::parse( $kaputt )['text'] );
	}

	/**
	 * Dateinamen nach RFC 2231 (filename*=UTF-8''…), auch mehrteilig.
	 */
	public function test_rfc2231_dateinamen() {
		$p = MimeParser::parse_header_params( "attachment; filename*=UTF-8''Gr%C3%BC%C3%9Fe.txt" );
		$this->assertSame( 'attachment', $p['value'] );
		$this->assertSame( 'Grüße.txt', $p['params']['filename'] );

		$p = MimeParser::parse_header_params( "attachment; filename*0*=UTF-8''Lan; filename*1*=ger%20Name.pdf" );
		$this->assertSame( 'Langer Name.pdf', $p['params']['filename'] );

		$p = MimeParser::parse_header_params( 'text/plain; charset="utf-8"; format=flowed' );
		$this->assertSame( 'utf-8', $p['params']['charset'] );
		$this->assertSame( 'flowed', $p['params']['format'] );
	}

	/**
	 * Dateinamen aus der Mail dürfen keinen Pfad enthalten.
	 */
	public function test_dateinamen_ohne_pfad() {
		$this->assertStringNotContainsString( '/', MimeParser::clean_filename( '../../etc/passwd' ) );
		$this->assertStringNotContainsString( '\\', MimeParser::clean_filename( 'C:\\Windows\\evil.exe' ) );
		$this->assertSame( 'anhang', MimeParser::clean_filename( "\x00\x01" ) );
	}

	/**
	 * Zitate stehen nicht in der Vorschau.
	 */
	public function test_snippet_ohne_zitat() {
		$s = MimeParser::snippet( "Danke!\n> alter Text\n> noch mehr\nBis bald" );
		$this->assertSame( 'Danke! Bis bald', $s );
	}

	/**
	 * Kaputte Mails dürfen den Parser nicht zum Absturz bringen.
	 */
	public function test_robust_gegen_muell() {
		$this->assertSame( '', MimeParser::parse( '' )['text'] );
		$m = MimeParser::parse( "Content-Type: multipart/mixed; boundary=\"X\"\r\n\r\n--X\r\nnoch kein Ende" );
		$this->assertIsArray( $m );

		// Tief verschachtelt.
		$raw = '';
		for ( $i = 0; $i < 30; $i++ ) {
			$raw .= "Content-Type: multipart/mixed; boundary=\"B$i\"\r\n\r\n--B$i\r\n";
		}
		$this->assertIsArray( MimeParser::parse( $raw . "Content-Type: text/plain\r\n\r\ntief" ) );
	}
}
