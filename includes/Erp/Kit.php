<?php
/**
 * Gemeinsame Helfer der ERP-Seiten Bank, Offene Posten, Steuer, Liquidität,
 * Angebote, Reklamationen, Inventur, Bestellungen an Lieferanten und Abwesenheit.
 *
 * Reine Regeln (Zahlen lesen, Datumsprüfung, Beträge, Nummernkreise) stehen
 * ohne Datenbank da und sind getestet (tests/ErpKitTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Kleine Werkzeugkiste.
 */
class Kit {

	/**
	 * Name der Option mit den Einstellungen der neuen Seiten.
	 */
	const OPTION = 'lw16_erp_kit_settings';

	/* ====================================================================
	 * Reine Regeln
	 * ================================================================== */

	/**
	 * Betrag lesen – deutsch („1.234,50“, „-12,5“) oder englisch („1234.50“).
	 *
	 * @param mixed $raw Eingabe.
	 * @return float|null Null, wenn es keine Zahl ist.
	 */
	public static function amount( $raw ) {
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return is_finite( (float) $raw ) ? (float) $raw : null;
		}

		$text = str_replace( array( ' ', "\xc2\xa0", '€', 'EUR' ), '', trim( (string) $raw ) );

		if ( '' === $text ) {
			return null;
		}

		// Nachgestelltes Minus („12,50-“) wie in manchen Bank-Exporten.
		if ( '-' === substr( $text, -1 ) ) {
			$text = '-' . substr( $text, 0, -1 );
		}

		$comma = strrpos( $text, ',' );
		$dot   = strrpos( $text, '.' );

		if ( false !== $comma && ( false === $dot || $comma > $dot ) ) {
			$text = str_replace( '.', '', $text );
			$text = str_replace( ',', '.', $text );
		} elseif ( false !== $dot && false !== $comma ) {
			$text = str_replace( ',', '', $text );
		} elseif ( false !== $dot && preg_match( '/^[+-]?\d{1,3}(\.\d{3})+$/', $text ) ) {
			$text = str_replace( '.', '', $text );
		}

		if ( ! preg_match( '/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $text ) ) {
			return null;
		}

		return (float) $text;
	}

	/**
	 * Datum lesen: JJJJ-MM-TT, TT.MM.JJJJ oder TT.MM.JJ.
	 *
	 * @param mixed $raw Eingabe.
	 * @return string JJJJ-MM-TT oder leer.
	 */
	public static function date( $raw ) {
		$raw = trim( (string) $raw );

		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m ) ) {
			list( , $y, $mo, $d ) = $m;
		} elseif ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/', $raw, $m ) ) {
			$d  = $m[1];
			$mo = $m[2];
			$y  = 2 === strlen( $m[3] ) ? '20' . $m[3] : $m[3];
		} else {
			return '';
		}

		return checkdate( (int) $mo, (int) $d, (int) $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : '';
	}

	/**
	 * Datum deutsch.
	 *
	 * @param string $ymd JJJJ-MM-TT.
	 * @return string TT.MM.JJJJ oder leer.
	 */
	public static function dmy( $ymd ) {
		$ymd = substr( (string) $ymd, 0, 10 );

		return preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ? $m[3] . '.' . $m[2] . '.' . $m[1] : '';
	}

	/**
	 * Betrag deutsch mit Euro.
	 *
	 * @param float $n Betrag.
	 * @return string
	 */
	public static function eur( $n ) {
		return number_format( (float) $n, 2, ',', '.' ) . ' €';
	}

	/**
	 * Tage zwischen zwei Daten (b − a).
	 *
	 * @param string $a JJJJ-MM-TT.
	 * @param string $b JJJJ-MM-TT.
	 * @return int
	 */
	public static function days_between( $a, $b ) {
		// Ohne gültiges Datum 0 – strtotime(' 12:00:00 UTC') wäre sonst „heute“.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $a ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $b ) ) {
			return 0;
		}

		$ta = strtotime( substr( (string) $a, 0, 10 ) . ' 12:00:00 UTC' );
		$tb = strtotime( substr( (string) $b, 0, 10 ) . ' 12:00:00 UTC' );

		return $ta && $tb ? (int) round( ( $tb - $ta ) / 86400 ) : 0;
	}

	/**
	 * Datum plus Tage.
	 *
	 * @param string $ymd  JJJJ-MM-TT.
	 * @param int    $days Tage.
	 * @return string
	 */
	public static function add_days( $ymd, $days ) {
		return gmdate( 'Y-m-d', strtotime( substr( (string) $ymd, 0, 10 ) . ' 12:00:00 UTC' ) + (int) $days * 86400 );
	}

	/**
	 * Nächster Termin einer wiederkehrenden Buchung.
	 *
	 * Monate werden gezählt, der Tag auf das Monatsende begrenzt: Ab dem 31.01.
	 * folgt monatlich der 28.02. (nicht der 03.03.), danach wieder der 31.03.,
	 * wenn der Ankertag 31 ist.
	 *
	 * @param string $date       JJJJ-MM-TT.
	 * @param string $intervall  woechentlich, monatlich, quartalsweise, halbjaehrlich, jaehrlich (sonst monatlich).
	 * @param int    $anchor_day Gewünschter Tag im Monat (0 = Tag von $date).
	 * @return string JJJJ-MM-TT
	 */
	public static function next_interval_date( $date, $intervall, $anchor_day = 0 ) {
		$date = substr( (string) $date, 0, 10 );

		if ( 'woechentlich' === $intervall ) {
			return self::add_days( $date, 7 );
		}

		$months = array( 'quartalsweise' => 3, 'halbjaehrlich' => 6, 'jaehrlich' => 12 );
		$add    = isset( $months[ $intervall ] ) ? $months[ $intervall ] : 1;
		$y      = (int) substr( $date, 0, 4 );
		$m      = (int) substr( $date, 5, 2 ) + $add;
		$day    = (int) $anchor_day > 0 ? min( 31, (int) $anchor_day ) : (int) substr( $date, 8, 2 );

		$y += intdiv( $m - 1, 12 );
		$m  = ( $m - 1 ) % 12 + 1;

		return sprintf( '%04d-%02d-%02d', $y, $m, min( $day, (int) gmdate( 't', gmmktime( 12, 0, 0, $m, 1, $y ) ) ) );
	}

	/**
	 * Nächste Nummer eines Nummernkreises: PREFIX-JJJJ-0001.
	 *
	 * @param string   $prefix   Kürzel (AN, BE, RK, IV).
	 * @param int      $year     Jahr.
	 * @param string[] $existing Schon vergebene Nummern dieses Jahres.
	 * @return string
	 */
	public static function next_number( $prefix, $year, array $existing ) {
		$max  = 0;
		$head = $prefix . '-' . (int) $year . '-';

		foreach ( $existing as $number ) {
			if ( 0 === strpos( (string) $number, $head ) ) {
				$max = max( $max, (int) substr( (string) $number, strlen( $head ) ) );
			}
		}

		return $head . str_pad( (string) ( $max + 1 ), 4, '0', STR_PAD_LEFT );
	}

	/**
	 * Text kürzen (für PDF-Spalten und DATEV-Felder).
	 *
	 * @param string $text Text.
	 * @param int    $max  Zeichen.
	 * @return string
	 */
	public static function cut( $text, $max ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $max ) {
			return rtrim( mb_substr( $text, 0, max( 1, $max - 1 ), 'UTF-8' ) ) . '…';
		}

		return $text;
	}

	/**
	 * Vorgaben der Einstellungen je Gruppe.
	 *
	 * @return array
	 */
	public static function setting_defaults() {
		return array(
			'datev' => array(
				'berater'         => '',
				'mandant'         => '',
				'sachkonten'      => 4,
				'wj_beginn'       => '01-01',
				'bank_konto'      => '1200',
				'kasse_konto'     => '1000',
				'diktat'          => 'LW',
			),
			'mahn'  => array(
				'tage_erinnerung' => 0,
				'tage_stufe2'     => 14,
				'tage_stufe3'     => 14,
				'gebuehr_stufe2'  => 5.0,
				'gebuehr_stufe3'  => 10.0,
				'frist_tage'      => 10,
				'shop_mahnen'     => 1,
				'shop_tage'       => 7,
				'zinsen'          => 0,
				'basiszins'       => 1.52,
				'basiszins_auto'  => 1,
				'basiszins_stand' => '',
				'basiszins_abruf' => '',
				'pauschale'       => 0,
				'konto'           => '',
			),
			'liqui' => array(
				'saldo'           => 0.0,
				'saldo_datum'     => '',
				'shop_wochen'     => 8,
				'shop_einrechnen' => 1,
				'puffer'          => 1000.0,
			),
			'digest' => array(
				'aktiv'           => 1,
				'an'              => '',
			),
			'bank'  => array(
				'auto_zuordnen'   => 1,
				'auto_schwelle'   => 100,
			),
			'urlaub' => array(
				'tage_standard'   => 24,
				'bundesland'      => '',
				'uebertrag'       => 1,
				'verfall'         => '03-31',
				'stunden_tag'     => 8.0,
			),
		);
	}

	/**
	 * Werte einer Gruppe auf die bekannten Schlüssel und Typen bringen.
	 *
	 * @param string $group Gruppe.
	 * @param array  $in    Eingaben.
	 * @param array  $base  Bisherige Werte.
	 * @return array
	 */
	public static function clean_settings( $group, array $in, array $base = array() ) {
		$defaults = self::setting_defaults();
		$out      = array();

		if ( ! isset( $defaults[ $group ] ) ) {
			return $out;
		}

		foreach ( $defaults[ $group ] as $key => $default ) {
			$value = array_key_exists( $key, $in ) ? $in[ $key ] : ( array_key_exists( $key, $base ) ? $base[ $key ] : $default );

			if ( is_int( $default ) ) {
				$value = (int) $value;
			} elseif ( is_float( $default ) ) {
				$num   = self::amount( $value );
				$value = null === $num ? (float) $default : $num;
			} else {
				$value = trim( preg_replace( '/[^\p{L}\p{N} ._\-\/@+]/u', '', (string) $value ) );
			}

			$out[ $key ] = $value;
		}

		return $out;
	}

	/* ====================================================================
	 * Mit WordPress
	 * ================================================================== */

	/**
	 * Einstellungen einer Gruppe.
	 *
	 * @param string $group Gruppe.
	 * @return array
	 */
	public static function settings( $group ) {
		$all = get_option( self::OPTION, array() );

		return self::clean_settings( $group, array(), is_array( $all ) && isset( $all[ $group ] ) ? (array) $all[ $group ] : array() );
	}

	/**
	 * Einstellungen einer Gruppe speichern.
	 *
	 * @param string $group Gruppe.
	 * @param array  $in    Eingaben.
	 * @return array Gespeicherte Werte.
	 */
	public static function save_settings( $group, array $in ) {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();

		$all[ $group ] = self::clean_settings( $group, $in, isset( $all[ $group ] ) ? (array) $all[ $group ] : array() );
		update_option( self::OPTION, $all, false );

		return $all[ $group ];
	}

	/**
	 * Fehlerantwort mit lesbarer Meldung.
	 *
	 * @param string $message Meldung.
	 * @param int    $status  HTTP-Status.
	 * @return \WP_REST_Response
	 */
	public static function fail( $message, $status = 422 ) {
		return new \WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}

	/**
	 * Eingaben aus dem Anfragekörper (JSON oder Formular), verschachtelt erlaubt.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return array
	 */
	public static function input( \WP_REST_Request $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		return is_array( $params ) ? $params : array();
	}

	/**
	 * Nächste freie Nummer aus einer Tabelle.
	 *
	 * @param string $table  Tabelle.
	 * @param string $column Spalte.
	 * @param string $prefix Kürzel.
	 * @return string
	 */
	public static function number_from( $table, $column, $prefix ) {
		global $wpdb;

		$year = (int) gmdate( 'Y' );
		$like = $wpdb->esc_like( $prefix . '-' . $year . '-' ) . '%';
		$have = $wpdb->get_col( $wpdb->prepare( "SELECT {$column} FROM {$table} WHERE {$column} LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::next_number( $prefix, $year, (array) $have );
	}

	/**
	 * Tabellenname mit Präfix.
	 *
	 * @param string $name Kurzname.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'lw16_' . $name;
	}

	/**
	 * Datei als Antwort, die die Oberfläche speichert (Base64, damit auch PDF durchkommt).
	 *
	 * @param string $filename Dateiname.
	 * @param string $mime     Typ.
	 * @param string $content  Inhalt.
	 * @return \WP_REST_Response
	 */
	public static function file_response( $filename, $mime, $content ) {
		return new \WP_REST_Response(
			array(
				'filename' => sanitize_file_name( $filename ),
				'mime'     => $mime,
				'b64'      => base64_encode( (string) $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			)
		);
	}

	/**
	 * Mail mit einem Anhang (PDF) verschicken.
	 *
	 * @param string $to       Empfänger.
	 * @param string $subject  Betreff.
	 * @param string $body     Text.
	 * @param string $filename Name des Anhangs (leer = ohne).
	 * @param string $content  Inhalt des Anhangs.
	 * @return bool
	 */
	public static function mail( $to, $subject, $body, $filename = '', $content = '' ) {
		$files = array();
		$dir   = '';

		if ( '' !== $filename ) {
			$dir = trailingslashit( get_temp_dir() ) . 'lw16-' . wp_generate_password( 12, false );
			wp_mkdir_p( $dir );
			$path = $dir . '/' . sanitize_file_name( $filename );
			file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$files[] = $path;
		}

		$from = (string) \Lindenweg16\Core\Settings::get( 'firma_email', get_bloginfo( 'admin_email' ) );
		$ok   = wp_mail( $to, $subject, $body, is_email( $from ) ? array( 'Reply-To: ' . $from ) : array(), $files );

		foreach ( $files as $path ) {
			wp_delete_file( $path );
		}
		if ( '' !== $dir ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}

		return (bool) $ok;
	}

	/**
	 * Firmenkopf für Briefe und PDFs.
	 *
	 * @return array {name, strasse, ort, email, telefon, iban, bic, steuernummer, kleinunternehmer}
	 */
	public static function company() {
		$s = function ( $key, $default = '' ) {
			return (string) \Lindenweg16\Core\Settings::get( $key, $default );
		};

		return array(
			'name'             => $s( 'firma_name', get_bloginfo( 'name' ) ),
			'strasse'          => $s( 'firma_strasse' ),
			'ort'              => $s( 'firma_plz_ort' ),
			'email'            => $s( 'firma_email', get_bloginfo( 'admin_email' ) ),
			'telefon'          => $s( 'firma_telefon' ),
			'iban'             => $s( 'firma_iban' ),
			'bic'              => $s( 'firma_bic' ),
			'steuernummer'     => $s( 'firma_steuernummer' ),
			'kleinunternehmer' => 1 === (int) \Lindenweg16\Core\Settings::get( 'kleinunternehmer', 1 ),
		);
	}

	/**
	 * Briefkopf in ein PDF schreiben (Absenderzeile, Empfänger, Titel, Nummer, Datum).
	 *
	 * @param \Lindenweg16\Core\Pdf $pdf       PDF.
	 * @param array                 $recipient Zeilen der Empfängeradresse.
	 * @param string                $title     Titel (z. B. „Angebot“).
	 * @param array                 $facts     Beschriftung => Wert (rechts oben).
	 */
	public static function letter_head( $pdf, array $recipient, $title, array $facts ) {
		$c      = self::company();
		$sender = implode( ' · ', array_filter( array( $c['name'], $c['strasse'], $c['ort'] ) ) );

		$pdf->text( $c['name'], 16, true );
		$pdf->text( $sender, 8 );
		$pdf->space( 18 );

		foreach ( $recipient as $line ) {
			if ( '' !== trim( (string) $line ) ) {
				$pdf->text( (string) $line, 11 );
			}
		}

		$pdf->space( 16 );
		$pdf->text( $title, 18, true );
		$pdf->space( 4 );

		foreach ( $facts as $label => $value ) {
			if ( '' !== (string) $value ) {
				$pdf->row( (string) $label, (string) $value, 10 );
			}
		}

		$pdf->space( 10 );
	}

	/**
	 * Fußzeile mit Bank- und Steuerdaten.
	 *
	 * @param \Lindenweg16\Core\Pdf $pdf PDF.
	 */
	public static function letter_foot( $pdf ) {
		$c = self::company();

		$pdf->space( 18 );
		$pdf->hr( 0.3 );
		$pdf->space( 4 );
		$pdf->text( implode( ' · ', array_filter( array( $c['name'], $c['email'], $c['telefon'] ) ) ), 8 );
		$bank = array_filter( array( $c['iban'] ? 'IBAN ' . $c['iban'] : '', $c['bic'] ? 'BIC ' . $c['bic'] : '', $c['steuernummer'] ? __( 'Steuernummer', 'lindenweg16' ) . ' ' . $c['steuernummer'] : '' ) );
		if ( $bank ) {
			$pdf->text( implode( ' · ', $bank ), 8 );
		}
		if ( $c['kleinunternehmer'] ) {
			$pdf->text( __( 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.', 'lindenweg16' ), 8 );
		}
	}
}
