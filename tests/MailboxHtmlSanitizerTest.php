<?php
/**
 * Prüft die Bereinigung fremder HTML-Mails.
 *
 * Hier geht es um die eine Frage: Kann eine eingehende Mail im Backend Code
 * ausführen oder den Leser verfolgen? Jeder Fall unten ist ein bekannter
 * Angriffsweg.
 *
 * @package Lindenweg16
 */

use Lindenweg16\Modules\Mailbox\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * HTML-Bereinigung.
 */
class MailboxHtmlSanitizerTest extends TestCase {

	/**
	 * Skripte, Rahmen und Formulare verschwinden samt Inhalt.
	 */
	public function test_aktive_inhalte_entfernt() {
		$html = '<p>Hallo</p><script>alert(1)</script><iframe src="https://evil.example"></iframe>'
			. '<object data="x.swf"></object><embed src="x"><form action="https://evil.example"><input name="pw"></form>'
			. '<svg onload="alert(1)"><script>1</script></svg><p>Ende</p>';

		$out = HtmlSanitizer::clean( $html );

		foreach ( array( 'script', 'alert', 'iframe', 'object', 'embed', '<form', '<input', '<svg' ) as $bad ) {
			$this->assertStringNotContainsString( $bad, $out, $bad );
		}
		$this->assertStringContainsString( 'Hallo', $out );
		$this->assertStringContainsString( 'Ende', $out );
	}

	/**
	 * Ereignis-Attribute fliegen raus, harmlose bleiben.
	 */
	public function test_ereignisattribute() {
		$out = HtmlSanitizer::clean( '<p onclick="steal()" class="x" ONMOUSEOVER="a()">T</p><img src="https://a.example/p.png" onerror="x()">' );

		$this->assertStringNotContainsString( 'onclick', $out );
		$this->assertStringNotContainsString( 'onmouseover', strtolower( $out ) );
		$this->assertStringNotContainsString( 'onerror', $out );
		$this->assertStringContainsString( 'class="x"', $out );
	}

	/**
	 * javascript:-Adressen in allen Tarnungen.
	 */
	public function test_javascript_adressen() {
		foreach ( array(
			'javascript:alert(1)',
			'JaVaScRiPt:alert(1)',
			" \tjavascript:alert(1)",
			"java\tscript:alert(1)",
			'java&#x09;script:alert(1)',
			'&#106;avascript:alert(1)',
			'vbscript:msgbox(1)',
			'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
		) as $url ) {
			$out = HtmlSanitizer::clean( '<a href="' . $url . '">klick</a>' );
			$this->assertStringNotContainsString( 'href=', $out, $url );
		}

		$out = HtmlSanitizer::clean( '<a href="https://example.de/x?a=1&amp;b=2">ok</a><a href="mailto:a@b.de">m</a>' );
		$this->assertStringContainsString( 'https://example.de/x?a=1&amp;b=2', $out );
		$this->assertStringContainsString( 'mailto:a@b.de', $out );
	}

	/**
	 * Links öffnen im neuen Fenster und ohne Referrer.
	 */
	public function test_links_noopener() {
		$out = HtmlSanitizer::clean( '<a href="https://example.de">x</a>' );

		$this->assertStringContainsString( 'noopener', $out );
		$this->assertStringContainsString( 'target="_blank"', $out );
	}

	/**
	 * Eingebettete Bilder (cid:) werden durch die gespeicherten Daten ersetzt;
	 * unbekannte cid-Adressen verschwinden.
	 */
	public function test_cid_bilder() {
		$out = HtmlSanitizer::clean(
			'<img src="cid:logo@x"><img src="cid:fehlt@x">',
			array( 'logo@x' => 'data:image/png;base64,AAAA' )
		);

		$this->assertStringContainsString( 'data:image/png;base64,AAAA', $out );
		$this->assertStringNotContainsString( 'cid:', $out );
	}

	/**
	 * CSS: keine Skript-Adressen, kein @import, keine fremden Bild-Adressen.
	 */
	public function test_css() {
		$css = HtmlSanitizer::clean_css( "@import url(https://evil.example/a.css); a { background: url(https://track.example/p.gif); width: expression(alert(1)); b: javascript:x }" );

		$this->assertStringNotContainsString( '@import', $css );
		$this->assertStringNotContainsString( 'track.example', $css );
		$this->assertStringNotContainsString( 'expression(', $css );
		$this->assertStringNotContainsString( 'javascript:', $css );

		$out = HtmlSanitizer::clean( '<div style="background:url(https://track.example/p.gif);color:red">x</div>' );
		$this->assertStringNotContainsString( 'track.example', $out );
		$this->assertStringContainsString( 'color:red', $out );
	}

	/**
	 * Stile aus dem <head> bleiben erhalten, ein </style> im CSS bricht nicht aus.
	 */
	public function test_stile_aus_head() {
		$out = HtmlSanitizer::clean( '<html><head><style>p{color:blue}</style></head><body><p>x</p></body></html>' );
		$this->assertStringContainsString( 'p{color:blue}', $out );
		$this->assertStringContainsString( '<p>x</p>', $out );
	}

	/**
	 * Das Dokument erlaubt ohne Zustimmung keine fremden Bilder und keine Skripte.
	 */
	public function test_dokument_csp() {
		$doc = html_entity_decode( HtmlSanitizer::document( '<p>x</p>' ), ENT_QUOTES );
		$this->assertStringContainsString( "default-src 'none'", $doc );
		$this->assertStringContainsString( 'img-src data:;', $doc );
		$this->assertStringNotContainsString( 'https:', $doc );

		$doc = html_entity_decode( HtmlSanitizer::document( '<p>x</p>', true ), ENT_QUOTES );
		$this->assertStringContainsString( 'img-src data: https: http:', $doc );
		$this->assertStringNotContainsString( 'script-src', $doc );
	}

	/**
	 * Klartext: escaped, Links anklickbar, Zitate als Block.
	 */
	public function test_text_to_html() {
		$out = HtmlSanitizer::text_to_html( "Hallo <b>Welt</b>\nSiehe https://example.de/a?x=1&y=2.\n> zitiert\n> noch eins\nEnde" );

		$this->assertStringContainsString( '&lt;b&gt;Welt&lt;/b&gt;', $out );
		$this->assertStringContainsString( '<a href="https://example.de/a?x=1&amp;y=2"', $out );
		$this->assertStringContainsString( '</a>.', $out, 'Satzzeichen bleibt außerhalb des Links' );
		$this->assertStringContainsString( '<blockquote class="lw16-mb-quote">zitiert' . "\n" . 'noch eins</blockquote>', $out );

		$evil = HtmlSanitizer::text_to_html( 'https://x.de/"><script>alert(1)</script>' );
		$this->assertStringNotContainsString( '<script>', $evil );
	}
}
