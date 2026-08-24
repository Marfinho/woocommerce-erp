<?php
/**
 * Textschicht eines PDF auslesen.
 *
 * Die meisten Belege von Lieferanten sind am Rechner erzeugt: Sie
 * enthalten den Text bereits, nur eingebettet. Ihn zu lesen ist genauer
 * als jede Zeichenerkennung – es wird nichts geraten, sondern gelesen.
 *
 * Erst wenn hier nichts herauskommt (ein abfotografierter Kassenbon,
 * ein eingescanntes Blatt), lohnt sich der Umweg über Tesseract.
 *
 * Bewusst kein Fremdpaket: Gebraucht wird ein kleiner Ausschnitt des
 * Formats – entpacken, Textoperatoren einsammeln, Zeichen entschlüsseln.
 * Was hier nicht gelingt, faellt sauber auf die Zeichenerkennung zurück.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

/**
 * Liest Textoperatoren aus den Inhaltsströmen eines PDF.
 */
class PdfText {

	/**
	 * Sieht diese Datei nach einem PDF aus?
	 *
	 * Nach dem Inhalt, nicht nach der Endung: Eine Datei heißt schnell
	 * einmal anders, als sie ist.
	 *
	 * @param string $head Erste Bytes der Datei.
	 * @return bool
	 */
	public static function looks_like_pdf( $head ) {
		return 0 === strpos( (string) $head, '%PDF-' );
	}

	/**
	 * Text aus einem PDF im Speicher lesen.
	 *
	 * @param string $binary Dateiinhalt.
	 * @return string Leer, wenn keine Textschicht gefunden wurde.
	 */
	public static function from_string( $binary ) {
		$binary = (string) $binary;

		if ( ! self::looks_like_pdf( substr( $binary, 0, 8 ) ) ) {
			return '';
		}

		$maps = self::font_maps( $binary );
		$text = '';

		foreach ( self::streams( $binary ) as $stream ) {
			$text .= self::operators_to_text( $stream, $maps ) . "\n";
		}

		return self::tidy( $text );
	}

	/**
	 * Text aus einer Datei lesen.
	 *
	 * @param string $path Pfad.
	 * @return string
	 */
	public static function from_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$binary = file_get_contents( $path );

		return false === $binary ? '' : self::from_string( $binary );
	}

	/**
	 * Indirekte Objekte einer Datei einsammeln.
	 *
	 * @param string $binary Dateiinhalt.
	 * @return array Objekt-Nummer => Rohinhalt.
	 */
	public static function objects( $binary ) {
		$out = array();

		if ( ! preg_match_all( '/(\d+)\s+\d+\s+obj\b/', (string) $binary, $treffer, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		foreach ( $treffer[0] as $i => $fund ) {
			$start = $fund[1] + strlen( $fund[0] );
			$ende  = strpos( $binary, 'endobj', $start );

			if ( false === $ende ) {
				continue;
			}

			$out[ (int) $treffer[1][ $i ][0] ] = substr( $binary, $start, $ende - $start );
		}

		return $out;
	}

	/**
	 * Zeichentabellen je Schriftname aufbauen.
	 *
	 * Eingebettete Teilschriften nummerieren ihre Zeichen neu: Aus einem
	 * „S" wird der Code 0x36. Nur die mitgelieferte ToUnicode-Tabelle
	 * führt zurück – ohne sie liest man Buchstabensalat und schriebe ihn
	 * in die Buchhaltung.
	 *
	 * @param string $binary Dateiinhalt.
	 * @return array Schriftname (z. B. F4) => Code => Zeichen.
	 */
	public static function font_maps( $binary ) {
		$objects = self::objects( $binary );
		$maps    = array();

		// Welche Objektnummer steckt hinter welchem Schriftnamen?
		$namen = array();

		if ( preg_match_all( '/\/Font\s*<<(.*?)>>/s', (string) $binary, $bloecke ) ) {
			foreach ( $bloecke[1] as $block ) {
				if ( preg_match_all( '/\/([A-Za-z0-9#]+)\s+(\d+)\s+\d+\s+R/', $block, $paare, PREG_SET_ORDER ) ) {
					foreach ( $paare as $paar ) {
						$namen[ $paar[1] ] = (int) $paar[2];
					}
				}
			}
		}

		foreach ( $namen as $name => $objekt ) {
			if ( ! isset( $objects[ $objekt ] ) ) {
				continue;
			}

			if ( ! preg_match( '/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $objects[ $objekt ], $verweis ) ) {
				continue;
			}

			$cmap = self::stream_of( $binary, (int) $verweis[1] );

			if ( '' === $cmap ) {
				continue;
			}

			$karte = self::parse_cmap( $cmap );

			if ( $karte ) {
				$maps[ $name ] = $karte;
			}
		}

		return $maps;
	}

	/**
	 * Entpackter Strom eines bestimmten Objekts.
	 *
	 * @param string $binary Dateiinhalt.
	 * @param int    $nummer Objektnummer.
	 * @return string
	 */
	public static function stream_of( $binary, $nummer ) {
		if ( ! preg_match( '/(?:^|[^0-9])' . (int) $nummer . '\s+\d+\s+obj\b/', (string) $binary, $treffer, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}

		$start = $treffer[0][1];
		$ende  = strpos( $binary, 'endobj', $start );
		$block = false === $ende ? substr( $binary, $start ) : substr( $binary, $start, $ende - $start );

		$streams = self::streams( $block );

		return $streams ? $streams[0] : '';
	}

	/**
	 * Eine ToUnicode-Tabelle auswerten.
	 *
	 * @param string $cmap Inhalt der Tabelle.
	 * @return array Code => Zeichen.
	 */
	public static function parse_cmap( $cmap ) {
		$karte = array();

		// Einzelzuordnungen: <0036> <0053>
		if ( preg_match_all( '/beginbfchar(.*?)endbfchar/s', $cmap, $bloecke ) ) {
			foreach ( $bloecke[1] as $block ) {
				if ( preg_match_all( '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $paare, PREG_SET_ORDER ) ) {
					foreach ( $paare as $paar ) {
						$karte[ hexdec( $paar[1] ) ] = self::utf16_to_utf8( $paar[2] );
					}
				}
			}
		}

		// Bereiche: <0024> <0027> <0041>  – fortlaufend ab dem Zielzeichen.
		if ( preg_match_all( '/beginbfrange(.*?)endbfrange/s', $cmap, $bloecke ) ) {
			foreach ( $bloecke[1] as $block ) {
				if ( preg_match_all( '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $bereiche, PREG_SET_ORDER ) ) {
					foreach ( $bereiche as $bereich ) {
						$von  = hexdec( $bereich[1] );
						$bis  = hexdec( $bereich[2] );
						$ziel = hexdec( $bereich[3] );

						// Ein unsinnig grosser Bereich waere ein Lesefehler.
						if ( $bis < $von || $bis - $von > 65535 ) {
							continue;
						}

						for ( $i = $von; $i <= $bis; $i++ ) {
							$karte[ $i ] = self::codepoint_to_utf8( $ziel + ( $i - $von ) );
						}
					}
				}
			}
		}

		return $karte;
	}

	/**
	 * Eine UTF-16BE-Hexfolge zu UTF-8 machen.
	 *
	 * @param string $hex Hexfolge.
	 * @return string
	 */
	protected static function utf16_to_utf8( $hex ) {
		$out = '';

		for ( $i = 0; $i + 3 < strlen( $hex ) + 1; $i += 4 ) {
			$stueck = substr( $hex, $i, 4 );

			if ( strlen( $stueck ) < 4 ) {
				break;
			}

			$out .= self::codepoint_to_utf8( hexdec( $stueck ) );
		}

		return $out;
	}

	/**
	 * Einen Codepunkt zu UTF-8 machen.
	 *
	 * @param int $code Codepunkt.
	 * @return string
	 */
	protected static function codepoint_to_utf8( $code ) {
		$code = (int) $code;

		if ( $code <= 0 ) {
			return '';
		}

		return function_exists( 'mb_chr' ) ? (string) mb_chr( $code, 'UTF-8' ) : html_entity_decode( '&#' . $code . ';', ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Entpackte Inhaltsströme eines PDF.
	 *
	 * Nur FlateDecode – das ist praktisch alles, was Rechnungsdrucker
	 * erzeugen. Ein Strom, der sich nicht entpacken lässt, wird
	 * übersprungen statt die ganze Datei aufzugeben.
	 *
	 * @param string $binary Dateiinhalt.
	 * @return array
	 */
	public static function streams( $binary ) {
		$out    = array();
		$offset = 0;

		while ( true ) {
			$start = strpos( $binary, 'stream', $offset );

			if ( false === $start ) {
				break;
			}

			$data_start = $start + 6;

			// Nach dem Schlüsselwort steht CRLF oder LF.
			if ( "\r" === substr( $binary, $data_start, 1 ) ) {
				$data_start++;
			}
			if ( "\n" === substr( $binary, $data_start, 1 ) ) {
				$data_start++;
			}

			$end = strpos( $binary, 'endstream', $data_start );

			if ( false === $end ) {
				break;
			}

			$raw    = substr( $binary, $data_start, $end - $data_start );
			$offset = $end + 9;

			if ( '' === trim( $raw ) ) {
				continue;
			}

			$plain = @gzuncompress( $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === $plain ) {
				$plain = @gzinflate( $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			// Unkomprimierte Ströme kommen vor, etwa bei kleinen Belegen.
			if ( false === $plain ) {
				$plain = false !== strpos( $raw, 'Tj' ) || false !== strpos( $raw, 'TJ' ) ? $raw : '';
			}

			if ( '' !== $plain ) {
				$out[] = $plain;
			}
		}

		return $out;
	}

	/**
	 * Textoperatoren eines Inhaltsstroms zu Text machen.
	 *
	 * Gelesen werden Tj und ' und " (eine Zeichenkette) sowie TJ (ein
	 * Feld aus Zeichenketten und Abständen). Ein großer Abstand trennt
	 * Wörter – daran hängt, ob „12,50EUR" oder „12,50 EUR" herauskommt.
	 *
	 * @param string $stream Entpackter Strom.
	 * @return string
	 */
	public static function operators_to_text( $stream, array $maps = array() ) {
		$out = '';

		// Zeilenumbrüche im Text: Td, TD, T* und ' beginnen eine neue Zeile.
		$stream = preg_replace( '/(T\*|TD|Td)/', "\n$1", (string) $stream );

		if ( ! preg_match_all( '/\((?:\\\\.|[^\\\\()])*\)|\[(?:[^\[\]\\\\]|\\\\.)*\]|<[0-9A-Fa-f\s]+>/s', (string) $stream, $treffer, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}

		foreach ( $treffer[0] as $fund ) {
			$stueck = $fund[0];
			$nach   = substr( $stream, $fund[1] + strlen( $stueck ), 4 );

			// Nur Zeichenketten, die auch ausgegeben werden.
			if ( ! preg_match( '/^\s*(TJ|Tj|\'|")/', $nach ) ) {
				continue;
			}

			// Welche Schrift gilt hier? Der letzte Tf-Befehl davor
			// entscheidet, welche Zeichentabelle greift.
			$karte = self::map_at( $stream, $fund[1], $maps );

			if ( '[' === $stueck[0] ) {
				$out .= self::array_to_text( $stueck, $karte );
			} elseif ( '<' === $stueck[0] ) {
				$out .= self::decode( self::hex_to_text( $stueck ), $karte, true );
			} else {
				$out .= self::decode( self::literal_to_text( $stueck ), $karte, false );
			}

			$out .= "\n";
		}

		return $out;
	}

	/**
	 * Ein TJ-Feld: Zeichenketten mit Abständen dazwischen.
	 *
	 * @param string $chunk Feld einschließlich Klammern.
	 * @return string
	 */
	protected static function array_to_text( $chunk, array $karte = array() ) {
		$out = '';

		preg_match_all( '/\((?:\\\\.|[^\\\\()])*\)|<[0-9A-Fa-f\s]+>|-?\d+(?:\.\d+)?/s', $chunk, $teile );

		foreach ( $teile[0] as $teil ) {
			if ( '(' === $teil[0] ) {
				$out .= self::decode( self::literal_to_text( $teil ), $karte, false );
			} elseif ( '<' === $teil[0] ) {
				$out .= self::decode( self::hex_to_text( $teil ), $karte, true );
			} elseif ( abs( (float) $teil ) > 100 ) {
				// Ein großer negativer Vorschub ist ein Wortabstand.
				$out .= ' ';
			}
		}

		return $out;
	}

	/**
	 * Welche Zeichentabelle gilt an dieser Stelle im Strom?
	 *
	 * @param string $stream Strom.
	 * @param int    $pos    Position der Zeichenkette.
	 * @param array  $maps   Tabellen je Schriftname.
	 * @return array Leere Tabelle heißt: unverändert übernehmen.
	 */
	protected static function map_at( $stream, $pos, array $maps ) {
		if ( ! $maps ) {
			return array();
		}

		$davor = substr( $stream, 0, $pos );

		if ( ! preg_match_all( '/\/([A-Za-z0-9#]+)\s+[\d.]+\s+Tf/', $davor, $treffer ) ) {
			// Keine Schriftwahl davor: Gibt es nur eine Tabelle, ist sie
			// gemeint. Sonst lieber nichts uebersetzen als falsch.
			return 1 === count( $maps ) ? reset( $maps ) : array();
		}

		$name = end( $treffer[1] );

		return isset( $maps[ $name ] ) ? $maps[ $name ] : array();
	}

	/**
	 * Zeichencodes über die Tabelle in Text übersetzen.
	 *
	 * @param string $roh      Rohe Zeichen.
	 * @param array  $karte    Code => Zeichen.
	 * @param bool   $zwei_byte Codes sind zwei Byte breit.
	 * @return string
	 */
	protected static function decode( $roh, array $karte, $zwei_byte ) {
		if ( '' === $roh ) {
			return '';
		}

		// Ohne Tabelle bleibt nur die Vermutung: Ein Nullbyte an jeder
		// zweiten Stelle heißt Zwei-Byte-Codes, die sich meist auf Latin-1
		// verkürzen lassen.
		if ( ! $karte ) {
			if ( $zwei_byte && 0 === strlen( $roh ) % 2 && substr_count( $roh, "\0" ) >= strlen( $roh ) / 2 - 1 ) {
				return str_replace( "\0", '', $roh );
			}

			return $roh;
		}

		$out    = '';
		$breite = $zwei_byte ? 2 : 1;

		for ( $i = 0; $i < strlen( $roh ); $i += $breite ) {
			$stueck = substr( $roh, $i, $breite );
			$code   = 1 === $breite ? ord( $stueck ) : ( ( ord( $stueck[0] ) << 8 ) + ( isset( $stueck[1] ) ? ord( $stueck[1] ) : 0 ) );

			if ( isset( $karte[ $code ] ) ) {
				$out .= $karte[ $code ];
				continue;
			}

			// Nicht in der Tabelle: Ein druckbares Zeichen darf bleiben,
			// alles andere waere Salat.
			$out .= 1 === $breite && $code >= 32 && $code < 127 ? $stueck : '';
		}

		return $out;
	}

	/**
	 * Eine Zeichenkette in Klammern entschlüsseln.
	 *
	 * @param string $chunk Zeichenkette einschließlich Klammern.
	 * @return string
	 */
	protected static function literal_to_text( $chunk ) {
		$roh = substr( $chunk, 1, -1 );
		$out = '';

		for ( $i = 0; $i < strlen( $roh ); $i++ ) {
			$c = $roh[ $i ];

			if ( '\\' !== $c ) {
				$out .= $c;
				continue;
			}

			$next = isset( $roh[ $i + 1 ] ) ? $roh[ $i + 1 ] : '';

			// Oktal: drei Ziffern, häufig für Umlaute.
			if ( ctype_digit( $next ) ) {
				$oktal = '';

				while ( strlen( $oktal ) < 3 && isset( $roh[ $i + 1 ] ) && ctype_digit( $roh[ $i + 1 ] ) ) {
					$oktal .= $roh[ ++$i ];
				}

				$out .= chr( octdec( $oktal ) );
				continue;
			}

			$i++;

			$map = array(
				'n' => "\n",
				'r' => "\r",
				't' => "\t",
				'b' => "\x08",
				'f' => "\x0C",
			);

			$out .= isset( $map[ $next ] ) ? $map[ $next ] : $next;
		}

		return $out;
	}

	/**
	 * Eine Hex-Zeichenkette entschlüsseln.
	 *
	 * @param string $chunk Zeichenkette einschließlich spitzer Klammern.
	 * @return string
	 */
	protected static function hex_to_text( $chunk ) {
		$hex = preg_replace( '/[^0-9A-Fa-f]/', '', substr( $chunk, 1, -1 ) );

		if ( '' === $hex ) {
			return '';
		}

		// Ungerade Länge: Das PDF-Format ergänzt eine 0.
		if ( strlen( $hex ) % 2 ) {
			$hex .= '0';
		}

		$bytes = hex2bin( $hex );

		if ( false === $bytes ) {
			return '';
		}

		// Roh zurückgeben: Ob hier Ein- oder Zwei-Byte-Codes stehen,
		// entscheidet erst die Zeichentabelle. Würde hier schon jedes
		// Nullbyte entfernt, wären die Codes vor der Übersetzung zerstört –
		// genau daran ist der erste Versuch gescheitert.
		return $bytes;
	}

	/**
	 * Ausgabe glätten: Zeichensatz, Leerzeichen, Leerzeilen.
	 *
	 * @param string $text Roher Text.
	 * @return string
	 */
	public static function tidy( $text ) {
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}

		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		$zeilen = array();

		foreach ( explode( "\n", $text ) as $zeile ) {
			$zeile = trim( $zeile );

			if ( '' !== $zeile ) {
				$zeilen[] = $zeile;
			}
		}

		return implode( "\n", $zeilen );
	}
}
