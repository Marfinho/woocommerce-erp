<?php
/**
 * Macht fremde HTML-Mails für die Anzeige im Backend unschädlich.
 *
 * Mehrere Schichten, damit ein Fehler in einer nicht reicht:
 *
 * 1. Die Mail wird nie in die Seite eingefügt, sondern in einem
 *    `<iframe sandbox>` angezeigt – ohne Skripte, ohne Zugriff auf
 *    WordPress (kein allow-same-origin).
 * 2. Eine Content-Security-Policy im Dokument verbietet alles außer
 *    Inline-Stilen und eingebetteten Bildern. Fremde Bilder (Tracking-
 *    Pixel!) werden erst nach „Bilder laden" zugelassen.
 * 3. Diese Klasse entfernt zusätzlich Skripte, Rahmen, Formulare,
 *    Ereignis-Attribute und gefährliche Adressen.
 *
 * Reine Funktionen, ohne WordPress – deshalb prüfbar.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Mailbox;

defined( 'ABSPATH' ) || exit;

/**
 * HTML-Bereinigung.
 */
class HtmlSanitizer {

	/**
	 * Elemente, die samt Inhalt entfernt werden.
	 *
	 * @var string[]
	 */
	const REMOVE = array( 'script', 'iframe', 'frame', 'frameset', 'object', 'applet', 'title', 'noscript', 'audio', 'video', 'svg', 'math', 'template' );

	/**
	 * Elemente, die wegfallen, deren Inhalt aber bleibt. Das HTML-4-Lesen von
	 * libxml kennt `embed`, `input` & Co. nicht als leere Elemente und hängt
	 * den folgenden Text daran – ihn mitzulöschen verschluckte Inhalt.
	 *
	 * @var string[]
	 */
	const UNWRAP = array( 'form', 'embed', 'input', 'button', 'select', 'textarea', 'source', 'base', 'link', 'meta' );

	/**
	 * Gefährliche Adressen und Inline-Stile bereinigen und das Ergebnis als
	 * vollständiges, sicheres Dokument liefern.
	 *
	 * @param string $html          HTML der Mail.
	 * @param bool   $remote_images Fremde Bilder zulassen.
	 * @param array  $cid_images    content-id => data:-URI (eingebettete Bilder).
	 * @return string
	 */
	public static function document( $html, $remote_images = false, array $cid_images = array() ) {
		$clean = self::clean( $html, $cid_images );

		$img = $remote_images ? "data: https: http:" : 'data:';
		$csp = "default-src 'none'; img-src {$img}; style-src 'unsafe-inline'; font-src data:";

		return '<!DOCTYPE html><html><head><meta charset="utf-8">'
			. '<meta http-equiv="Content-Security-Policy" content="' . htmlspecialchars( $csp, ENT_QUOTES ) . '">'
			. '<base target="_blank">'
			. '<style>html{color:#1d2327;font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
			. 'body{margin:12px;word-wrap:break-word;overflow-wrap:anywhere}img{max-width:100%;height:auto}'
			. 'blockquote{margin:8px 0 8px 4px;padding-left:12px;border-left:3px solid #c3c4c7;color:#50575e}'
			. 'table{max-width:100%}a{color:#2271b1}</style></head><body>'
			. $clean
			. '</body></html>';
	}

	/**
	 * Bereinigtes Body-HTML (inklusive der Stilangaben der Mail).
	 *
	 * @param string $html       Roh-HTML.
	 * @param array  $cid_images content-id => data:-URI.
	 * @return string
	 */
	public static function clean( $html, array $cid_images = array() ) {
		$html = (string) $html;

		if ( '' === trim( $html ) ) {
			return '';
		}

		if ( ! class_exists( '\DOMDocument' ) ) {
			return self::clean_fallback( $html, $cid_images );
		}

		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . self::strip_head( $html ), LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		// Stile der Mail vor dem Aufräumen merken (steckt oft im <head>).
		$styles = '';
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'style' ) ) as $style ) {
			$css = (string) $style->textContent;
			// Der Block lässt sich nicht aus dem <style> ausbrechen: Er wird als Text neu ausgegeben.
			$styles .= '<style>' . str_replace( '</', '<\\/', self::clean_css( $css ) ) . '</style>';
			$style->parentNode->removeChild( $style ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}

		foreach ( self::REMOVE as $tag ) {
			foreach ( iterator_to_array( $dom->getElementsByTagName( $tag ) ) as $node ) {
				if ( $node->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
					$node->parentNode->removeChild( $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				}
			}
		}

		foreach ( self::UNWRAP as $tag ) {
			foreach ( iterator_to_array( $dom->getElementsByTagName( $tag ) ) as $node ) {
				$parent = $node->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				if ( ! $parent ) {
					continue;
				}
				while ( $node->firstChild ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
					$parent->insertBefore( $node->firstChild, $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				}
				$parent->removeChild( $node );
			}
		}

		$xpath = new \DOMXPath( $dom );
		foreach ( iterator_to_array( $xpath->query( '//*' ) ) as $el ) {
			foreach ( iterator_to_array( $el->attributes ) as $attr ) {
				$name  = strtolower( $attr->name );
				$value = (string) $attr->value;

				if ( 0 === strpos( $name, 'on' ) ) {
					$el->removeAttribute( $attr->name );
					continue;
				}

				if ( in_array( $name, array( 'href', 'src', 'background', 'action', 'formaction', 'xlink:href', 'poster', 'data' ), true ) ) {
					$safe = self::safe_url( $value, $cid_images, 'src' === $name || 'background' === $name );
					if ( null === $safe ) {
						$el->removeAttribute( $attr->name );
					} else {
						$el->setAttribute( $attr->name, $safe );
					}
					continue;
				}

				if ( 'style' === $name ) {
					$el->setAttribute( 'style', self::clean_css( $value ) );
				}
			}

			if ( 'a' === strtolower( $el->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$el->setAttribute( 'rel', 'noopener noreferrer' );
				$el->setAttribute( 'target', '_blank' );
			}
		}

		$body  = $dom->getElementsByTagName( 'body' )->item( 0 );
		$inner = '';
		if ( $body ) {
			foreach ( $body->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$inner .= $dom->saveHTML( $child );
			}
		}

		return $styles . $inner;
	}

	/**
	 * Alles bis zum <body> und die schließenden Tags abtrennen, damit der
	 * Parser nicht mit doppelten html/body-Elementen kämpft.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	protected static function strip_head( $html ) {
		// Stile aus dem Kopf retten, bevor er wegfällt.
		$styles = '';
		if ( preg_match( '#<head[^>]*>(.*?)</head>#is', $html, $m ) ) {
			if ( preg_match_all( '#<style[^>]*>.*?</style>#is', $m[1], $s ) ) {
				$styles = implode( '', $s[0] );
			}
		}

		$html = preg_replace( '#^.*?<body[^>]*>#is', '', $html, 1, $count );
		if ( ! $count ) {
			$html = preg_replace( '#<!doctype[^>]*>|<html[^>]*>|<head[^>]*>.*?</head>#is', '', (string) $html );
		}
		$html = preg_replace( '#</body>|</html>#i', '', (string) $html );

		return $styles . $html;
	}

	/**
	 * Adresse prüfen. Erlaubt: http(s), mailto, tel, cid (wird ersetzt), eingebettete Bilder.
	 *
	 * @param string $url        Wert des Attributs.
	 * @param array  $cid_images content-id => data:-URI.
	 * @param bool   $is_image   Ob das Attribut ein Bild lädt.
	 * @return string|null Bereinigter Wert, null = Attribut entfernen.
	 */
	public static function safe_url( $url, array $cid_images, $is_image = false ) {
		// Steuerzeichen und Leerraum, mit denen sich "java\tscript:" tarnen lässt.
		$compact = strtolower( (string) preg_replace( '/[\x00-\x20\x7F]+/', '', html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

		if ( 0 === strpos( $compact, 'cid:' ) ) {
			$cid = substr( trim( html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 4 );
			$cid = trim( rawurldecode( $cid ), '<> ' );

			return isset( $cid_images[ $cid ] ) ? $cid_images[ $cid ] : null;
		}

		if ( preg_match( '#^(https?:|mailto:|tel:)#', $compact ) ) {
			return trim( (string) $url );
		}

		if ( $is_image && preg_match( '#^data:image/(png|jpe?g|gif|webp|bmp);base64,#', $compact ) ) {
			return trim( (string) $url );
		}

		// Relative Adressen und #Anker bleiben (führen im iframe ins Leere).
		if ( '' === $compact || '#' === $compact[0] ) {
			return (string) $url;
		}

		return null;
	}

	/**
	 * CSS entschärfen: Ausdrücke, Skript-Adressen, @import, Verhalten.
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	public static function clean_css( $css ) {
		$css = (string) $css;
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = preg_replace( '#@import[^;]*;?#i', '', (string) $css );
		$css = preg_replace( '#expression\s*\(|behaviou?r\s*:|-moz-binding\s*:|javascript\s*:#i', 'x-removed(', (string) $css );
		$css = preg_replace( '#url\s*\(\s*([\'"]?)\s*(?!data:image/)[^)]*\)#i', 'none', (string) $css );

		return (string) $css;
	}

	/**
	 * Notlösung ohne DOM-Erweiterung: alles Verdächtige wegnehmen, im Zweifel zu viel.
	 *
	 * @param string $html       HTML.
	 * @param array  $cid_images content-id => data:-URI.
	 * @return string
	 */
	protected static function clean_fallback( $html, array $cid_images ) {
		unset( $cid_images );

		return nl2br( htmlspecialchars( \Lindenweg16\Modules\Mailbox\MimeParser::html_to_text( $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Klartext für die Anzeige: Zeilenumbrüche, anklickbare Links, Zitate hervorgehoben.
	 *
	 * @param string $text Klartext.
	 * @return string HTML (escaped).
	 */
	public static function text_to_html( $text ) {
		$lines  = explode( "\n", MimeParser::normalize_newlines( (string) $text ) );
		$out    = '';
		$quote  = array();
		$flush  = static function () use ( &$out, &$quote ) {
			if ( $quote ) {
				$out  .= '<blockquote class="lw16-mb-quote">' . implode( "\n", $quote ) . '</blockquote>';
				$quote = array();
			}
		};

		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*>+ ?(.*)$/s', $line, $m ) ) {
				$quote[] = self::linkify( $m[1] );
				continue;
			}

			$flush();
			$out .= self::linkify( $line ) . "\n";
		}
		$flush();

		return rtrim( $out );
	}

	/**
	 * Eine Zeile escapen und Web-Adressen anklickbar machen.
	 *
	 * @param string $line Zeile.
	 * @return string
	 */
	public static function linkify( $line ) {
		$parts = preg_split( '#(https?://[^\s<>"\']+)#iu', (string) $line, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';

		foreach ( $parts as $i => $part ) {
			if ( 1 === $i % 2 ) {
				// Satzzeichen am Ende gehören nicht zur Adresse.
				$trail = '';
				if ( preg_match( '/[.,;:!?)\]]+$/', $part, $m ) ) {
					$trail = $m[0];
					$part  = substr( $part, 0, -strlen( $trail ) );
				}
				$e    = htmlspecialchars( $part, ENT_QUOTES, 'UTF-8' );
				$out .= '<a href="' . $e . '" target="_blank" rel="noopener noreferrer">' . $e . '</a>' . htmlspecialchars( $trail, ENT_QUOTES, 'UTF-8' );
			} else {
				$out .= htmlspecialchars( $part, ENT_QUOTES, 'UTF-8' );
			}
		}

		return $out;
	}
}
