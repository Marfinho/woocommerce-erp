<?php
/**
 * OCR-Texterkennung für Belege (optional, benötigt Tesseract).
 *
 * Extrahiert automatisch Betrag, Datum und Händlername aus
 * hochgeladenen Beleg-Fotos und gibt Vorschläge zurück.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

/**
 * Optische Zeichenerkennung für Belegfotos.
 */
class BelegOcr {

	/**
	 * Prüft, ob Tesseract auf dem Server verfügbar ist.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$path = self::tesseract_path();
		return ! empty( $path );
	}

	/**
	 * Pfad zum Tesseract-Binary finden.
	 *
	 * @return string Leerer String, wenn nicht gefunden.
	 */
	protected static function tesseract_path() {
		static $path = null;
		if ( null !== $path ) {
			return $path;
		}

		// Bekannte Pfade durchprobieren.
		$candidates = array(
			'/usr/bin/tesseract',
			'/usr/local/bin/tesseract',
			'/opt/homebrew/bin/tesseract',
		);

		foreach ( $candidates as $bin ) {
			if ( file_exists( $bin ) && is_executable( $bin ) ) {
				$path = $bin;
				return $path;
			}
		}

		// which / command -v Fallback.
		$out = array();
		@exec( 'which tesseract 2>/dev/null', $out ); // phpcs:ignore
		if ( ! empty( $out[0] ) && is_executable( trim( $out[0] ) ) ) {
			$path = trim( $out[0] );
			return $path;
		}

		$path = '';
		return $path;
	}

	/**
	 * OCR auf ein Bild anwenden und strukturierte Daten extrahieren.
	 *
	 * @param string $image_path Absoluter Pfad zum Bild.
	 * @return array {
	 *     'raw_text'  => string,  // Volltext.
	 *     'betrag'    => string,  // Erkannter Betrag oder ''.
	 *     'datum'     => string,  // Erkanntes Datum (Y-m-d) oder ''.
	 *     'haendler'  => string,  // Erkannter Händlername oder ''.
	 *     'success'   => bool,
	 *     'error'     => string,
	 * }
	 */
	public static function scan( $image_path ) {
		$result = array(
			'raw_text' => '',
			'betrag'   => '',
			'datum'    => '',
			'haendler' => '',
			'success'  => false,
			'error'    => '',
		);

		if ( ! file_exists( $image_path ) ) {
			$result['error'] = __( 'Datei nicht gefunden.', 'lindenweg16' );
			return $result;
		}

		$tesseract = self::tesseract_path();
		if ( ! $tesseract ) {
			$result['error'] = __( 'Tesseract OCR ist nicht installiert. Bitte den Server-Administrator kontaktieren (apt install tesseract-ocr tesseract-ocr-deu).', 'lindenweg16' );
			return $result;
		}

		// Temporäre Datei für den Output.
		$tmp_base = wp_tempnam( 'lw16_ocr' );
		$tmp_out  = $tmp_base . '.txt';

		// Tesseract mit deutscher Sprache ausführen.
		$lang = self::has_language( 'deu' ) ? 'deu' : 'eng';
		$cmd  = sprintf(
			'%s %s %s -l %s 2>/dev/null',
			escapeshellcmd( $tesseract ),
			escapeshellarg( $image_path ),
			escapeshellarg( $tmp_base ),
			escapeshellarg( $lang )
		);

		@exec( $cmd, $output, $exit_code ); // phpcs:ignore

		if ( file_exists( $tmp_out ) ) {
			$text = file_get_contents( $tmp_out ); // phpcs:ignore
			@unlink( $tmp_out ); // phpcs:ignore
			@unlink( $tmp_base ); // phpcs:ignore
		} else {
			@unlink( $tmp_base ); // phpcs:ignore
			$result['error'] = __( 'OCR-Verarbeitung fehlgeschlagen.', 'lindenweg16' );
			return $result;
		}

		if ( empty( trim( $text ) ) ) {
			$result['error'] = __( 'Kein Text erkannt. Bitte ein schärferes Foto verwenden.', 'lindenweg16' );
			return $result;
		}

		$result['raw_text'] = $text;
		$result['success']  = true;

		// Strukturierte Daten extrahieren.
		$result['betrag']   = self::extract_betrag( $text );
		$result['datum']    = self::extract_datum( $text );
		$result['haendler'] = self::extract_haendler( $text );

		return $result;
	}

	/**
	 * Prüfen, ob eine Tesseract-Sprache installiert ist.
	 *
	 * @param string $lang Sprachcode (z. B. 'deu').
	 * @return bool
	 */
	protected static function has_language( $lang ) {
		$tesseract = self::tesseract_path();
		if ( ! $tesseract ) {
			return false;
		}
		$out = array();
		@exec( escapeshellcmd( $tesseract ) . ' --list-langs 2>/dev/null', $out ); // phpcs:ignore
		foreach ( $out as $line ) {
			if ( trim( $line ) === $lang ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Betrag aus OCR-Text extrahieren.
	 *
	 * Sucht nach typischen Muster wie „12,50 €", „EUR 12.50", „SUMME: 12,50",
	 * „GESAMT 12,50", „TOTAL 12.50" usw.
	 *
	 * @param string $text OCR-Volltext.
	 * @return string Betrag oder ''.
	 */
	protected static function extract_betrag( $text ) {
		$patterns = array(
			// „SUMME", „GESAMT", „TOTAL", „BETRAG", „ZU ZAHLEN" gefolgt von Betrag.
			'/(?:SUMME|GESAMT|TOTAL|BETRAG|ZU\s*ZAHLEN|ENDBETRAG)\s*[:\s]*(\d{1,6}[.,]\d{2})\s*(?:€|EUR)?/iu',
			// Betrag mit € Zeichen.
			'/(\d{1,6}[.,]\d{2})\s*€/u',
			// EUR vor/nach Betrag.
			'/(?:EUR)\s*(\d{1,6}[.,]\d{2})/iu',
			// Letzter Betrag im Text (oft der Gesamtbetrag).
			'/(\d{1,6}[.,]\d{2})\s*$/m',
		);

		$candidates = array();
		foreach ( $patterns as $priority => $pattern ) {
			if ( preg_match_all( $pattern, $text, $matches ) ) {
				foreach ( $matches[1] as $match ) {
					$betrag = str_replace( ',', '.', $match );
					$betrag = (float) $betrag;
					if ( $betrag > 0 && $betrag < 999999 ) {
						$candidates[] = array(
							'betrag'   => $betrag,
							'priority' => $priority,
						);
					}
				}
			}
		}

		if ( empty( $candidates ) ) {
			return '';
		}

		// Höchste Priorität (niedrigster Index) bevorzugen.
		usort( $candidates, function ( $a, $b ) {
			return $a['priority'] - $b['priority'];
		} );

		return number_format( $candidates[0]['betrag'], 2, '.', '' );
	}

	/**
	 * Datum aus OCR-Text extrahieren.
	 *
	 * @param string $text OCR-Volltext.
	 * @return string Datum (Y-m-d) oder ''.
	 */
	protected static function extract_datum( $text ) {
		$patterns = array(
			// DD.MM.YYYY oder DD.MM.YY.
			'/(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})/',
			// YYYY-MM-DD (ISO).
			'/(\d{4})-(\d{2})-(\d{2})/',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $m ) {
					if ( strlen( $m[3] ) === 4 && (int) $m[3] > 2000 ) {
						// DD.MM.YYYY.
						$d = (int) $m[1];
						$mo = (int) $m[2];
						$y = (int) $m[3];
					} elseif ( strlen( $m[1] ) === 4 && (int) $m[1] > 2000 ) {
						// YYYY-MM-DD.
						$y = (int) $m[1];
						$mo = (int) $m[2];
						$d = (int) $m[3];
					} elseif ( strlen( $m[3] ) === 2 ) {
						// DD.MM.YY.
						$d = (int) $m[1];
						$mo = (int) $m[2];
						$y = 2000 + (int) $m[3];
					} else {
						continue;
					}

					if ( checkdate( $mo, $d, $y ) && $y >= 2020 && $y <= 2099 ) {
						return sprintf( '%d-%02d-%02d', $y, $mo, $d );
					}
				}
			}
		}

		return '';
	}

	/**
	 * Händlernamen aus OCR-Text extrahieren.
	 *
	 * Heuristik: Erste nicht-leere Zeile, die kein reiner Zahlenwert,
	 * kein Datum und kein bekanntes Schlüsselwort ist.
	 *
	 * @param string $text OCR-Volltext.
	 * @return string Händlername oder ''.
	 */
	protected static function extract_haendler( $text ) {
		$lines = explode( "\n", $text );
		$skip_words = array(
			'RECHNUNG', 'QUITTUNG', 'KASSENBON', 'BELEG', 'SUMME',
			'TOTAL', 'GESAMT', 'DATUM', 'NR', 'MWST', 'UST',
			'NETTO', 'BRUTTO', 'EUR', 'STEUER', 'BETRAG',
		);

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( mb_strlen( $line ) < 3 || mb_strlen( $line ) > 60 ) {
				continue;
			}
			// Reine Zahlenzeile überspringen.
			if ( preg_match( '/^[\d.,€\s%\-\/]+$/', $line ) ) {
				continue;
			}
			// Schlüsselwort-Zeile überspringen.
			$upper = mb_strtoupper( $line );
			$is_keyword = false;
			foreach ( $skip_words as $kw ) {
				if ( strpos( $upper, $kw ) === 0 ) {
					$is_keyword = true;
					break;
				}
			}
			if ( $is_keyword ) {
				continue;
			}
			// Zeile mit Datum überspringen.
			if ( preg_match( '/^\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}/', $line ) ) {
				continue;
			}

			return $line;
		}

		return '';
	}
}
