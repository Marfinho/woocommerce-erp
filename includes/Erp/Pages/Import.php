<?php
/**
 * ERP-Seite „Import & Doku“: CSV-Import, Übernahme aus der alten Lagerverwaltung,
 * Verfahrensdokumentation.
 *
 * Der Import läuft wie auf der alten Seite (Modules/Betrieb/Admin/ImportPage.php):
 * je Blatt eine CSV hochladen, Spalten zuordnen, Vorschau mit Fehlern, erst nach
 * Bestätigung schreiben. Die Prüfungen je Datensatz und das Schreiben (samt
 * Protokoll, das die Repositories selbst führen) übernimmt dieselbe Logik wie
 * die alte Seite; hier kommen nur Lesen der Datei, Zuordnung und Zahlen-/
 * Datumsformate dazu. Die Datei wird bei jedem Schritt neu hochgeladen (der
 * Browser behält sie), auf dem Server bleibt nichts liegen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Erp\ProductFile;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\Admin\ImportPage;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\LegacyMigration;
use Lindenweg16\Modules\Betrieb\VerfahrensDokuBetrieb;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen erp/import/….
 */
class Import {

	/** Größte erlaubte CSV in Byte. */
	const MAX_BYTES = 5242880;

	/** Mehr Datenzeilen je Datei nimmt der Import nicht an. */
	const MAX_ROWS = 5000;

	/** So viele Fehler gehen an die Oberfläche. */
	const MAX_ERRORS = 200;

	/** So viele Zeilen zeigt die Vorschau. */
	const PREVIEW_ROWS = 10;

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$needs = $api->needs( array( 'verwaltung.import' ) );
		$gate  = static function () use ( $needs ) {
			// Wie die alte Seite: zusätzlich das Betrieb-Recht „Einstellungen“.
			return $needs() && Roles::current_user_can_betrieb( 'lw16_manage_settings' );
		};
		$ns = RestApi::NAMESPACE_V1;

		register_rest_route( $ns, '/erp/import', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $gate ) );
		register_rest_route( $ns, '/erp/import/lesen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'read' ), 'permission_callback' => $gate ) );
		register_rest_route( $ns, '/erp/import/pruefen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check' ), 'permission_callback' => $gate ) );
		register_rest_route( $ns, '/erp/import/ausfuehren', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'run' ), 'permission_callback' => $gate ) );
		register_rest_route( $ns, '/erp/import/uebernahme', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'legacy' ), 'permission_callback' => $gate ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'legacy_run' ), 'permission_callback' => $gate ),
		) );
		register_rest_route( $ns, '/erp/import/doku', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'doku' ), 'permission_callback' => $gate ) );
	}

	/* ====================================================================
	 * Reine Regeln (ohne WordPress und Datenbank, getestet)
	 * ================================================================== */

	/**
	 * Felder, die Zahlen sind, je Blatt-Feld. Alles andere ist Text.
	 *
	 * @return string[]
	 */
	public static function number_fields() {
		return array(
			'parts_per_plate', 'print_time_plate_h', 'weight_g', 'postprocessing_min', 'price_gross', 'dealer_price', 'load_rating_kg',
			'acquisition_cost', 'nozzle_mm',
			'parts_planned', 'parts_ok', 'print_time_h', 'plate_changes',
			'kg_received', 'price_per_kg',
			'hours',
		);
	}

	/**
	 * Felder, die Daten sind.
	 *
	 * @return string[]
	 */
	public static function date_fields() {
		return array( 'acquired_on', 'job_date', 'received_on', 'dried_on', 'entry_date', 'follow_up_on' );
	}

	/**
	 * Windows-1252 nach UTF-8 (Excel speichert „CSV“ meist so).
	 *
	 * @param string $bytes Rohdaten.
	 * @return string
	 */
	protected static function cp1252_to_utf8( $bytes ) {
		static $special = array(
			0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021,
			0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D,
			0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
			0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A, 0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
		);

		$out = '';
		$len = strlen( $bytes );

		for ( $i = 0; $i < $len; $i++ ) {
			$b = ord( $bytes[ $i ] );

			if ( $b < 0x80 ) {
				$out .= $bytes[ $i ];
				continue;
			}

			$cp   = isset( $special[ $b ] ) ? $special[ $b ] : $b;
			$out .= self::utf8_char( $cp );
		}

		return $out;
	}

	/**
	 * Ein Unicode-Zeichen als UTF-8.
	 *
	 * @param int $cp Codepunkt (bis U+FFFF).
	 * @return string
	 */
	protected static function utf8_char( $cp ) {
		if ( $cp < 0x80 ) {
			return chr( $cp );
		}
		if ( $cp < 0x800 ) {
			return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}

		return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}

	/**
	 * Dateiinhalt als UTF-8-Text: Byte-Order-Mark weg, Windows-Kodierung umwandeln.
	 *
	 * @param string $bytes Rohdaten.
	 * @return string
	 */
	public static function decode_text( $bytes ) {
		$bytes = (string) $bytes;

		if ( 0 === strncmp( $bytes, "\xEF\xBB\xBF", 3 ) ) {
			return substr( $bytes, 3 );
		}

		// UTF-16 mit Byte-Order-Mark (Excel „Unicode-Text“).
		if ( 0 === strncmp( $bytes, "\xFF\xFE", 2 ) || 0 === strncmp( $bytes, "\xFE\xFF", 2 ) ) {
			$little = 0 === strncmp( $bytes, "\xFF\xFE", 2 );
			$out    = '';
			$len    = strlen( $bytes ) - 1;

			for ( $i = 2; $i < $len; $i += 2 ) {
				$cp = $little ? ord( $bytes[ $i ] ) | ( ord( $bytes[ $i + 1 ] ) << 8 ) : ( ord( $bytes[ $i ] ) << 8 ) | ord( $bytes[ $i + 1 ] );
				// Zeichen außerhalb der Grundebene sind in Tabellen so selten, dass sie als „?“ erscheinen dürfen.
				$out .= $cp >= 0xD800 && $cp <= 0xDFFF ? '?' : self::utf8_char( $cp );
			}

			return $out;
		}

		if ( 1 === preg_match( '//u', $bytes ) ) {
			return $bytes;
		}

		return self::cp1252_to_utf8( $bytes );
	}

	/**
	 * Trennzeichen aus der ersten nicht leeren Zeile erraten (Semikolon, Komma, Tab).
	 *
	 * Anführungszeichen zählen nicht mit. Gleichstand und keine Treffer
	 * ergeben das Semikolon, wie bei deutschem Excel.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function detect_delimiter( $text ) {
		$counts = array( ';' => 0, ',' => 0, "\t" => 0 );
		$quoted = false;
		$seen   = false;
		$len    = strlen( $text );

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $text[ $i ];

			if ( '"' === $c ) {
				$quoted = ! $quoted;
				$seen   = true;
				continue;
			}
			if ( $quoted ) {
				continue;
			}
			if ( "\n" === $c || "\r" === $c ) {
				if ( $seen ) {
					break;
				}
				continue;
			}
			if ( isset( $counts[ $c ] ) ) {
				$counts[ $c ]++;
			}
			if ( ' ' !== $c ) {
				$seen = true;
			}
		}

		arsort( $counts );
		$best = key( $counts );

		return $counts[ $best ] > 0 && $counts[ $best ] > $counts[';'] ? $best : ';';
	}

	/**
	 * CSV-Text in Zeilen zerlegen.
	 *
	 * Versteht Anführungszeichen (auch mit Zeilenumbruch und "" darin), \n,
	 * \r\n und \r. Jede Zelle ist getrimmt. Eine Zeile ohne Inhalt bleibt als
	 * array('') erhalten, damit die Zeilennummern zur Datei passen.
	 *
	 * @param string      $text      UTF-8-Text.
	 * @param string|null $delimiter Trennzeichen; null = erkennen.
	 * @return array Liste von Zeilen (Liste von Zellen).
	 */
	public static function parse_csv( $text, $delimiter = null ) {
		$text = (string) $text;
		$d    = null === $delimiter ? self::detect_delimiter( $text ) : $delimiter;
		$rows = array();
		$row  = array();
		$cell = '';
		$in_q = false;
		$len  = strlen( $text );
		$open = false; // Es hat schon etwas in dieser Zeile begonnen.

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $text[ $i ];

			if ( $in_q ) {
				if ( '"' === $c ) {
					if ( $i + 1 < $len && '"' === $text[ $i + 1 ] ) {
						$cell .= '"';
						$i++;
					} else {
						$in_q = false;
					}
				} else {
					$cell .= $c;
				}
				continue;
			}

			if ( '"' === $c && '' === trim( $cell ) ) {
				$in_q = true;
				$cell = '';
				$open = true;
			} elseif ( $c === $d ) {
				$row[] = trim( $cell );
				$cell  = '';
				$open  = true;
			} elseif ( "\n" === $c || "\r" === $c ) {
				if ( "\r" === $c && $i + 1 < $len && "\n" === $text[ $i + 1 ] ) {
					$i++;
				}
				$row[]  = trim( $cell );
				$rows[] = $row;
				$row    = array();
				$cell   = '';
				$open   = false;
			} else {
				$cell .= $c;
				$open  = true;
			}
		}

		if ( $open || '' !== $cell || $row ) {
			$row[]  = trim( $cell );
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Zahl aus einer Zelle: „1,5“, „1.5“, „1.234,50“, „12,50 €“.
	 *
	 * @param string $raw Zelle.
	 * @return float|null Null, wenn es keine Zahl ist.
	 */
	public static function parse_number( $raw ) {
		$text = preg_replace( '/[\s\x{00A0}]+/u', '', (string) $raw );
		$text = preg_replace( '/(€|eur)$/iu', '', (string) $text );

		if ( '' === $text || null === $text ) {
			return null;
		}

		if ( preg_match( '/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $text ) ) {
			$text = str_replace( array( '.', ',' ), array( '', '.' ), $text );
		} elseif ( preg_match( '/^-?\d{1,3}(,\d{3})+\.\d+$/', $text ) ) {
			$text = str_replace( ',', '', $text );
		} elseif ( preg_match( '/^-?\d+([.,]\d+)?$/', $text ) ) {
			$text = str_replace( ',', '.', $text );
		} else {
			return null;
		}

		return (float) $text;
	}

	/**
	 * Datum aus einer Zelle als Y-m-d: „2025-12-31“, „31.12.2025“, „31.12.25“, „31/12/2025“.
	 *
	 * Eine Uhrzeit dahinter wird ignoriert. Zweistellige Jahre zählen ab 2000.
	 *
	 * @param string $raw Zelle.
	 * @return string|null Null, wenn es kein echtes Datum ist.
	 */
	public static function parse_date( $raw ) {
		$text = trim( (string) $raw );
		$text = (string) preg_replace( '/[ T]\d{1,2}:\d{2}(:\d{2})?$/', '', $text );

		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m ) ) {
			$y = (int) $m[1];
			$mo = (int) $m[2];
			$d = (int) $m[3];
		} elseif ( preg_match( '/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4}|\d{2})$/', $text, $m ) ) {
			$d  = (int) $m[1];
			$mo = (int) $m[2];
			$y  = (int) $m[3];
			if ( $y < 100 ) {
				$y += 2000;
			}
		} else {
			return null;
		}

		return checkdate( $mo, $d, $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : null;
	}

	/**
	 * Zahlen und Daten einer Zeile in die Schreibweise bringen, die die Repositories lesen.
	 *
	 * Die Repositories verstehen nur Y-m-d und Zahlen mit einfachem Komma;
	 * ein Excel-Datum „31.12.2025“ würde dort stillschweigend zu „heute“.
	 * Deshalb wird hier umgewandelt – und was sich nicht lesen lässt, als Fehler
	 * gemeldet statt geraten.
	 *
	 * @param array $record Feld => Zellwert.
	 * @param array $labels Feld => Beschriftung (für die Meldung).
	 * @return array {record, errors}
	 */
	public static function normalize_record( array $record, array $labels = array() ) {
		$errors  = array();
		$numbers = self::number_fields();
		$dates   = self::date_fields();

		foreach ( $record as $field => $value ) {
			$value = trim( (string) $value );
			$label = isset( $labels[ $field ] ) ? self::plain_label( $labels[ $field ] ) : $field;

			if ( '' === $value ) {
				continue;
			}

			if ( in_array( $field, $numbers, true ) ) {
				$n = self::parse_number( $value );
				if ( null === $n ) {
					/* translators: 1: Feld, 2: Wert. */
					$errors[] = sprintf( __( '%1$s: „%2$s“ ist keine Zahl.', 'lindenweg16' ), $label, self::clip( $value ) );
				} else {
					$record[ $field ] = rtrim( rtrim( number_format( $n, 6, '.', '' ), '0' ), '.' );
					if ( '' === $record[ $field ] || '-' === $record[ $field ] ) {
						$record[ $field ] = '0';
					}
				}
			} elseif ( in_array( $field, $dates, true ) ) {
				$iso = self::parse_date( $value );
				if ( null === $iso ) {
					/* translators: 1: Feld, 2: Wert. */
					$errors[] = sprintf( __( '%1$s: „%2$s“ ist kein Datum (erwartet TT.MM.JJJJ oder JJJJ-MM-TT).', 'lindenweg16' ), $label, self::clip( $value ) );
				} else {
					$record[ $field ] = $iso;
				}
			}
		}

		return array(
			'record' => $record,
			'errors' => $errors,
		);
	}

	/**
	 * Beschriftung ohne „(Pflicht)“.
	 *
	 * @param string $label Beschriftung.
	 * @return string
	 */
	public static function plain_label( $label ) {
		return trim( (string) preg_replace( '/\s*\(Pflicht\)\s*$/u', '', (string) $label ) );
	}

	/**
	 * Wert für Meldungen kürzen.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected static function clip( $text ) {
		$text = (string) $text;

		return strlen( $text ) > 40 ? substr( $text, 0, 40 ) . '…' : $text;
	}

	/**
	 * Vergleichsschlüssel für Spaltennamen: klein, ohne Umlaut-Unterschiede und Sonderzeichen.
	 *
	 * @param string $text Name.
	 * @return string
	 */
	public static function key_of( $text ) {
		$text = strtolower( self::plain_label( $text ) );
		$text = strtr( $text, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ) );

		return (string) preg_replace( '/[^a-z0-9]+/', '', $text );
	}

	/**
	 * Zuordnung vorschlagen: Spalten, deren Name der Beschriftung oder dem Feldnamen entspricht.
	 *
	 * Eine Spalte wird nur einmal vergeben.
	 *
	 * @param array $fields Feld => Beschriftung.
	 * @param array $header Kopfzeile der Datei.
	 * @return array Feld => Spaltenindex oder '' (nicht zugeordnet).
	 */
	public static function suggest_mapping( array $fields, array $header ) {
		$keys = array();
		foreach ( $header as $index => $name ) {
			$key = self::key_of( $name );
			if ( '' !== $key && ! isset( $keys[ $key ] ) ) {
				$keys[ $key ] = (int) $index;
			}
		}

		$taken = array();
		$map   = array();

		foreach ( $fields as $field => $label ) {
			$map[ $field ] = '';

			foreach ( array( self::key_of( $label ), self::key_of( $field ) ) as $key ) {
				if ( '' !== $key && isset( $keys[ $key ] ) && ! isset( $taken[ $keys[ $key ] ] ) ) {
					$map[ $field ]           = $keys[ $key ];
					$taken[ $keys[ $key ] ] = true;
					break;
				}
			}
		}

		return $map;
	}

	/**
	 * Zuordnung aus der Anfrage bereinigen: nur bekannte Felder, nur vorhandene Spalten.
	 *
	 * @param mixed $raw         Feld => Spalte (Zahl oder leer).
	 * @param array $fields      Feld => Beschriftung.
	 * @param int   $column_count Anzahl Spalten der Datei.
	 * @return array Feld => int
	 */
	public static function clean_mapping( $raw, array $fields, $column_count ) {
		$map = array();

		if ( ! is_array( $raw ) ) {
			return $map;
		}

		foreach ( $raw as $field => $column ) {
			if ( ! isset( $fields[ $field ] ) || '' === $column || null === $column || false === $column ) {
				continue;
			}
			if ( ! is_numeric( $column ) || (int) $column < 0 || (int) $column >= (int) $column_count ) {
				continue;
			}
			$map[ (string) $field ] = (int) $column;
		}

		return $map;
	}

	/**
	 * Pflichtfelder (mit „(Pflicht)“ in der Beschriftung), die keiner Spalte zugeordnet sind.
	 *
	 * @param array $fields Feld => Beschriftung.
	 * @param array $map    Zuordnung.
	 * @return string[] Beschriftungen.
	 */
	public static function missing_required( array $fields, array $map ) {
		$missing = array();

		foreach ( $fields as $field => $label ) {
			if ( false !== strpos( (string) $label, '(Pflicht)' ) && ! isset( $map[ $field ] ) ) {
				$missing[] = self::plain_label( $label );
			}
		}

		return $missing;
	}

	/**
	 * Eine Datenzeile über die Zuordnung in ein Feld-Array übersetzen.
	 *
	 * @param array $row Zellen.
	 * @param array $map Feld => Spaltenindex.
	 * @return array Feld => Wert
	 */
	public static function record_from_row( array $row, array $map ) {
		$record = array();

		foreach ( $map as $field => $column ) {
			$record[ $field ] = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
		}

		return $record;
	}

	/* ====================================================================
	 * Hilfen
	 * ================================================================== */

	/**
	 * Fehlerantwort.
	 *
	 * @param string $message Text.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}

	/**
	 * Größtes erlaubtes Dateigewicht.
	 *
	 * @return int
	 */
	protected static function max_bytes() {
		return min( self::MAX_BYTES, (int) wp_max_upload_size() );
	}

	/**
	 * Blatt aus der Anfrage.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array|WP_REST_Response {key, fields} oder Fehler.
	 */
	protected static function sheet_of( WP_REST_Request $request ) {
		$key    = sanitize_key( (string) $request->get_param( 'sheet' ) );
		$sheets = ImportPage::sheets();

		if ( ! isset( $sheets[ $key ] ) ) {
			return self::fail( __( 'Dieses Blatt kennt der Import nicht. Bitte eines aus der Liste wählen.', 'lindenweg16' ) );
		}

		return array(
			'key'    => $key,
			'fields' => $sheets[ $key ]['fields'],
		);
	}

	/**
	 * Hochgeladene CSV lesen.
	 *
	 * @param WP_REST_Request $request Anfrage (Feld „csv“).
	 * @return array|WP_REST_Response {rows, raw} oder Fehler.
	 */
	protected static function rows_of( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = isset( $files['csv'] ) ? $files['csv'] : null;

		// Übersteigt die Anfrage post_max_size, kommt gar kein Feld an.
		if ( ! $file || ( empty( $file['name'] ) && empty( $file['error'] ) ) ) {
			return self::fail( __( 'Es kam keine Datei an. Entweder wurde keine gewählt, oder sie ist größer, als der Server annimmt. Bitte eine CSV-Datei wählen.', 'lindenweg16' ) );
		}

		$error = ProductFile::upload_error( isset( $file['error'] ) ? (int) $file['error'] : 0, self::max_bytes() );

		if ( '' !== $error ) {
			return self::fail( $error );
		}

		$tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return self::fail( __( 'Die Datei kam nicht ordentlich an. Bitte noch einmal hochladen.', 'lindenweg16' ) );
		}

		$size = (int) filesize( $tmp );

		if ( $size > self::max_bytes() ) {
			return self::fail( sprintf(
				/* translators: %s: Größe. */
				__( 'Die Datei ist zu groß (höchstens %s). Bitte in mehrere Dateien teilen.', 'lindenweg16' ),
				ProductFile::bytes_text( self::max_bytes() )
			) );
		}

		$raw = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( '' === trim( $raw ) ) {
			return self::fail( __( 'Die Datei ist leer.', 'lindenweg16' ) );
		}

		$rows = self::parse_csv( self::decode_text( $raw ) );

		// Leere Zeilen am Ende fallen weg.
		while ( $rows && '' === implode( '', end( $rows ) ) ) {
			array_pop( $rows );
		}

		if ( count( $rows ) < 2 ) {
			return self::fail( __( 'In der Datei stehen keine lesbaren Zeilen. Die erste Zeile muss die Spaltenüberschriften enthalten, darunter die Daten. Bitte das Blatt in Excel als „CSV (Trennzeichen-getrennt)“ speichern.', 'lindenweg16' ) );
		}

		if ( count( $rows ) - 1 > self::MAX_ROWS ) {
			return self::fail( sprintf(
				/* translators: %d: Anzahl. */
				__( 'Die Datei hat mehr als %d Datenzeilen. Bitte in mehrere Dateien teilen und nacheinander importieren.', 'lindenweg16' ),
				self::MAX_ROWS
			) );
		}

		return array(
			'rows' => $rows,
			'hash' => sha1( $raw ),
		);
	}

	/* ====================================================================
	 * Routen
	 * ================================================================== */

	/**
	 * Blätter, Reihenfolge-Hinweis, Grenzen.
	 *
	 * @return WP_REST_Response
	 */
	public static function overview() {
		$sheets = array();

		foreach ( ImportPage::sheets() as $key => $sheet ) {
			$fields = array();
			foreach ( $sheet['fields'] as $field => $label ) {
				$fields[] = array(
					'key'      => $field,
					'label'    => self::plain_label( $label ),
					'required' => false !== strpos( (string) $label, '(Pflicht)' ),
				);
			}
			$sheets[] = array(
				'key'    => $key,
				'label'  => $sheet['label'],
				'fields' => $fields,
			);
		}

		return new WP_REST_Response( array(
			'sheets'    => $sheets,
			'max_bytes' => self::max_bytes(),
			'max_rows'  => self::MAX_ROWS,
		) );
	}

	/**
	 * Schritt 1: Datei lesen – Spalten, Beispielzeilen und Zuordnungsvorschlag.
	 *
	 * @param WP_REST_Request $request Anfrage (sheet, csv).
	 * @return WP_REST_Response
	 */
	public static function read( WP_REST_Request $request ) {
		$sheet = self::sheet_of( $request );
		if ( $sheet instanceof WP_REST_Response ) {
			return $sheet;
		}

		$file = self::rows_of( $request );
		if ( $file instanceof WP_REST_Response ) {
			return $file;
		}

		$rows   = $file['rows'];
		$header = $rows[0];

		return new WP_REST_Response( array(
			'ok'      => true,
			'columns' => array_map( 'strval', $header ),
			'count'   => count( $rows ) - 1,
			'sample'  => array_slice( $rows, 1, 3 ),
			'mapping' => self::suggest_mapping( $sheet['fields'], $header ),
		) );
	}

	/**
	 * Schritt 2: Vorschau (schreibt nichts).
	 *
	 * @param WP_REST_Request $request Anfrage (sheet, csv, map).
	 * @return WP_REST_Response
	 */
	public static function check( WP_REST_Request $request ) {
		return self::process( $request, false );
	}

	/**
	 * Schritt 3: Import ausführen.
	 *
	 * @param WP_REST_Request $request Anfrage (sheet, csv, map, confirm).
	 * @return WP_REST_Response
	 */
	public static function run( WP_REST_Request $request ) {
		if ( ! $request->get_param( 'confirm' ) ) {
			return self::fail( __( 'Der Import muss ausdrücklich bestätigt werden. Bitte erst die Vorschau ansehen.', 'lindenweg16' ) );
		}

		return self::process( $request, true );
	}

	/**
	 * Gemeinsamer Ablauf von Vorschau und Import.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @param bool            $commit  Wirklich schreiben.
	 * @return WP_REST_Response
	 */
	protected static function process( WP_REST_Request $request, $commit ) {
		$sheet = self::sheet_of( $request );
		if ( $sheet instanceof WP_REST_Response ) {
			return $sheet;
		}

		$file = self::rows_of( $request );
		if ( $file instanceof WP_REST_Response ) {
			return $file;
		}

		$rows   = $file['rows'];
		$fields = $sheet['fields'];
		$raw    = $request->get_param( 'map' );
		$raw    = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		$map    = self::clean_mapping( $raw, $fields, count( $rows[0] ) );

		if ( ! $map ) {
			return self::fail( __( 'Es ist noch keine Spalte zugeordnet. Bitte mindestens die Pflichtfelder einer Spalte der Datei zuordnen.', 'lindenweg16' ) );
		}

		$missing = self::missing_required( $fields, $map );
		if ( $missing ) {
			return self::fail( sprintf(
				/* translators: %s: Feldliste. */
				__( 'Diese Pflichtfelder haben noch keine Spalte: %s.', 'lindenweg16' ),
				implode( ', ', $missing )
			) );
		}

		// Doppelklick und zweimal Abschicken verhindern: dieselbe Datei mit derselben Zuordnung nicht binnen zwei Minuten erneut schreiben.
		$lock = 'lw16_erp_import_' . get_current_user_id();
		$sig  = sha1( $sheet['key'] . '|' . $file['hash'] . '|' . wp_json_encode( $map ) );

		if ( $commit ) {
			if ( get_transient( $lock ) === $sig ) {
				return self::fail( __( 'Diese Datei wurde gerade eben schon importiert. Ein zweiter Durchlauf würde alles doppelt anlegen. Falls es wirklich nötig ist, bitte zwei Minuten warten.', 'lindenweg16' ), 409 );
			}
			set_transient( $lock, $sig, 120 );
		}

		$runner   = new ImportRunner();
		$imported = 0;
		$updated  = 0;
		$errors   = array();
		$preview  = array();
		$total    = 0;
		$more     = 0;

		array_shift( $rows );

		foreach ( $rows as $index => $row ) {
			$record = self::record_from_row( $row, $map );

			// Leere Zeilen der Mappe überspringen.
			if ( '' === implode( '', $record ) ) {
				continue;
			}

			$total++;
			$line  = $index + 2; // Zeile 1 ist die Kopfzeile.
			$shown = $record;
			$norm  = self::normalize_record( $record, $fields );
			$error = '';
			$note  = '';

			if ( $norm['errors'] ) {
				$error = implode( ' ', $norm['errors'] );
			} else {
				$known = 'artikel' === $sheet['key'] && '' !== $norm['record']['sku'] && ArticleRepository::find_by_sku( strtoupper( $norm['record']['sku'] ) );
				$out   = $runner->record( $sheet['key'], $norm['record'], $commit );
				$error = (string) $out['error'];

				if ( '' === $error && $known ) {
					$updated++;
					$note = __( 'vorhandener Artikel wird aktualisiert', 'lindenweg16' );
				}
			}

			if ( '' === $error ) {
				$imported++;
			} elseif ( count( $errors ) < self::MAX_ERRORS ) {
				$errors[] = array( 'row' => $line, 'message' => $error );
			} else {
				$more++;
			}

			if ( count( $preview ) < self::PREVIEW_ROWS ) {
				$preview[] = array(
					'row'    => $line,
					'values' => $shown,
					'ok'     => '' === $error,
					'note'   => '' === $error ? $note : $error,
				);
			}
		}

		$labels = array();
		foreach ( array_keys( $map ) as $field ) {
			$labels[] = array( 'key' => $field, 'label' => self::plain_label( $fields[ $field ] ) );
		}

		if ( 0 === $total ) {
			if ( $commit ) {
				delete_transient( $lock );
			}

			return self::fail( __( 'Mit dieser Zuordnung bleibt keine Zeile mit Inhalt übrig. Bitte prüfen, ob die richtigen Spalten gewählt sind.', 'lindenweg16' ) );
		}

		return new WP_REST_Response( array(
			'ok'        => true,
			'committed' => (bool) $commit,
			'total'     => $total,
			'valid'     => $imported,
			'updated'   => $updated,
			'failed'    => $total - $imported,
			'errors'    => $errors,
			'more'      => $more,
			'columns'   => $labels,
			'preview'   => $preview,
			'message'   => $commit ? sprintf(
				/* translators: 1: übernommen, 2: übersprungen. */
				__( 'Import abgeschlossen: %1$d Datensätze übernommen, %2$d übersprungen.', 'lindenweg16' ),
				$imported,
				$total - $imported
			) : '',
		) );
	}

	/**
	 * Übernahme aus der alten Lagerverwaltung: Vorschau.
	 *
	 * @return WP_REST_Response
	 */
	public static function legacy() {
		$rows = array();
		$new  = 0;

		foreach ( LegacyMigration::preview() as $row ) {
			$rows[] = array(
				'name'   => (string) $row['name'],
				'typ'    => (string) $row['typ'],
				'stock'  => (float) $row['stock'],
				'target' => (string) $row['target'],
				'sku'    => (string) $row['sku'],
				'status' => (string) $row['status'],
			);
			if ( 'neu' === $row['status'] ) {
				$new++;
			}
		}

		return new WP_REST_Response( array( 'rows' => $rows, 'new' => $new ) );
	}

	/**
	 * Übernahme ausführen (schon übernommene Produkte werden erkannt und übersprungen).
	 *
	 * @return WP_REST_Response
	 */
	public static function legacy_run() {
		$result = LegacyMigration::run();

		return new WP_REST_Response( array(
			'ok'      => true,
			'created' => (int) $result['angelegt'],
			'skipped' => (int) $result['uebersprungen'],
			'booked'  => (int) $result['gebucht'],
			'message' => sprintf(
				/* translators: 1: angelegte Artikel, 2: übersprungene, 3: Bestandsbuchungen. */
				__( '%1$d Artikel angelegt, %2$d bereits vorhanden, %3$d Anfangsbestände gebucht.', 'lindenweg16' ),
				$result['angelegt'],
				$result['uebersprungen'],
				$result['gebucht']
			),
		) );
	}

	/**
	 * Verfahrensdokumentation als Text und Adresse des PDF.
	 *
	 * @return WP_REST_Response
	 */
	public static function doku() {
		$lines = array();

		foreach ( VerfahrensDokuBetrieb::lines() as $line ) {
			$lines[] = array( (string) $line[0], (string) $line[1] );
		}

		return new WP_REST_Response( array(
			'lines'   => $lines,
			'pdf_url' => wp_nonce_url( admin_url( 'admin-post.php?action=lw16_betrieb_verfahren' ), 'lw16_betrieb_verfahren' ),
		) );
	}
}

/**
 * Schreibt einzelne Datensätze mit genau der Logik der alten Importseite.
 *
 * Die Prüfungen je Blatt (ImportPage::import_*) bleiben an einer Stelle; diese
 * Hülle macht sie nur ohne Formular und Weiterleitung aufrufbar.
 */
class ImportRunner extends ImportPage {

	/**
	 * Einen Datensatz prüfen und – bei $commit – schreiben.
	 *
	 * @param string $sheet  Blatt.
	 * @param array  $record Werte.
	 * @param bool   $commit Schreiben.
	 * @return array {error}
	 */
	public function record( $sheet, array $record, $commit ) {
		return $this->import_record( $sheet, $record, $commit );
	}
}
