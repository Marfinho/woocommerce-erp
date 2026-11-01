<?php
/**
 * ERP-Seite „Steuer & DATEV“.
 *
 * 1. Umsatzsteuer: Als Kleinunternehmer (Einstellung „kleinunternehmer“) zeigt
 *    die Seite die beiden Grenzen nach § 19 UStG (seit 2025: Vorjahr höchstens
 *    25.000 €, laufendes Jahr höchstens 100.000 €) mit Hochrechnung. Mit
 *    Regelbesteuerung zeigt sie je Monat oder Quartal die Bemessungsgrundlagen
 *    und die Umsatzsteuer aus den Shop-Bestellungen (Kennzahlen 81 und 86 der
 *    Voranmeldung). Die Vorsteuer steht nicht in den Belegen und muss aus den
 *    Eingangsrechnungen kommen – das sagt die Seite auch so.
 * 2. DATEV: Das Buchungsjournal (Tabelle buchungen, GoBD) als
 *    Buchungsstapel im DATEV-Format EXTF 700 für den Steuerberater.
 *
 * Umsatz zählt hier wie in der EÜR: abgeschlossene Shop-Bestellungen abzüglich
 * Erstattungen, dazu manuelle Aufträge (ohne Shop-Bestellung).
 *
 * Reine Regeln sind getestet (tests/ErpSteuerTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\RestApi;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Steuer und DATEV.
 */
class Steuer {

	/**
	 * Grenze Vorjahr (§ 19 Abs. 1 UStG, ab 2025).
	 */
	const GRENZE_VORJAHR = 25000;

	/**
	 * Grenze laufendes Jahr (§ 19 Abs. 1 UStG, ab 2025).
	 */
	const GRENZE_LAUFEND = 100000;

	/* ====================================================================
	 * Reine Regeln: Umsatzsteuer
	 * ================================================================== */

	/**
	 * Stand der Kleinunternehmerregelung.
	 *
	 * @param float  $vorjahr  Umsatz Vorjahr.
	 * @param float  $laufend  Umsatz laufendes Jahr bis heute.
	 * @param string $heute    JJJJ-MM-TT.
	 * @return array {level: ok|warn|bad, prognose, quote_vorjahr, quote_laufend, text[]}
	 */
	public static function ku_status( $vorjahr, $laufend, $heute ) {
		$year    = (int) substr( $heute, 0, 4 );
		$day     = (int) gmdate( 'z', strtotime( $heute . ' 12:00:00 UTC' ) ) + 1;
		$days    = 366 === (int) gmdate( 'z', strtotime( $year . '-12-31 12:00:00 UTC' ) ) + 1 ? 366 : 365;
		$prog    = $day > 0 ? round( (float) $laufend / $day * $days, 2 ) : 0.0;
		$level   = 'ok';
		$text    = array();

		if ( $vorjahr > self::GRENZE_VORJAHR ) {
			$level  = 'bad';
			$text[] = sprintf( /* translators: 1: Jahr, 2: Grenze. */ __( 'Der Umsatz %1$d lag über %2$s. Für dieses Jahr gilt die Kleinunternehmerregelung nicht mehr – bitte mit dem Steuerberater sprechen und Rechnungen mit Umsatzsteuer schreiben.', 'lindenweg16' ), $year - 1, Kit::eur( self::GRENZE_VORJAHR ) );
		}

		if ( $laufend > self::GRENZE_LAUFEND ) {
			$level  = 'bad';
			$text[] = sprintf( /* translators: %s: Grenze. */ __( 'Der Umsatz in diesem Jahr liegt über %s. Ab dem Umsatz, der die Grenze überschritten hat, fällt Umsatzsteuer an – sofort handeln.', 'lindenweg16' ), Kit::eur( self::GRENZE_LAUFEND ) );
		} elseif ( $laufend > self::GRENZE_LAUFEND * 0.8 ) {
			$level  = 'bad' === $level ? 'bad' : 'warn';
			$text[] = sprintf( /* translators: %s: Grenze. */ __( 'Der Umsatz nähert sich %s. Wird die Grenze im Jahr überschritten, gilt die Regelung schon ab diesem Umsatz nicht mehr.', 'lindenweg16' ), Kit::eur( self::GRENZE_LAUFEND ) );
		}

		if ( $laufend > self::GRENZE_VORJAHR ) {
			$level  = 'bad' === $level ? 'bad' : 'warn';
			$text[] = sprintf( /* translators: 1: Grenze, 2: Jahr. */ __( 'Schon jetzt über %1$s: Im Jahr %2$d bist du kein Kleinunternehmer mehr. Rechtzeitig Preise, Shop und Rechnungen auf Umsatzsteuer umstellen.', 'lindenweg16' ), Kit::eur( self::GRENZE_VORJAHR ), $year + 1 );
		} elseif ( $prog > self::GRENZE_VORJAHR ) {
			$level  = 'bad' === $level ? 'bad' : 'warn';
			$text[] = sprintf( /* translators: 1: Prognose, 2: Grenze, 3: Jahr. */ __( 'Hochgerechnet auf das Jahr: %1$s – über %2$s. Dann wärst du %3$d kein Kleinunternehmer mehr.', 'lindenweg16' ), Kit::eur( $prog ), Kit::eur( self::GRENZE_VORJAHR ), $year + 1 );
		}

		if ( ! $text ) {
			$text[] = __( 'Beide Grenzen sind eingehalten. Die Kleinunternehmerregelung gilt weiter.', 'lindenweg16' );
		}

		return array(
			'level'         => $level,
			'prognose'      => $prog,
			'quote_vorjahr' => self::GRENZE_VORJAHR ? round( (float) $vorjahr / self::GRENZE_VORJAHR * 100, 1 ) : 0,
			'quote_laufend' => round( (float) $laufend / self::GRENZE_VORJAHR * 100, 1 ),
			'text'          => $text,
		);
	}

	/**
	 * Steuersatz einer Position aus Netto und Steuer, auf 19, 7 oder 0 gerundet.
	 *
	 * @param float $net Netto.
	 * @param float $tax Steuer.
	 * @return int
	 */
	public static function rate_of( $net, $tax ) {
		if ( abs( (float) $net ) < 0.005 || abs( (float) $tax ) < 0.005 ) {
			return 0;
		}

		$rate = abs( (float) $tax / (float) $net ) * 100;

		return abs( $rate - 19 ) <= abs( $rate - 7 ) ? 19 : 7;
	}

	/**
	 * Positionen nach Steuersatz zusammenfassen.
	 *
	 * @param array $items Liste {net, tax}.
	 * @return array Satz => {net, tax}
	 */
	public static function by_rate( array $items ) {
		$out = array(
			19 => array( 'net' => 0.0, 'tax' => 0.0 ),
			7  => array( 'net' => 0.0, 'tax' => 0.0 ),
			0  => array( 'net' => 0.0, 'tax' => 0.0 ),
		);

		foreach ( $items as $item ) {
			$rate                = self::rate_of( $item['net'], $item['tax'] );
			$out[ $rate ]['net'] += (float) $item['net'];
			$out[ $rate ]['tax'] += (float) $item['tax'];
		}

		foreach ( $out as $rate => $sum ) {
			$out[ $rate ] = array( 'net' => round( $sum['net'], 2 ), 'tax' => round( $sum['tax'], 2 ) );
		}

		return $out;
	}

	/**
	 * Zeiträume eines Jahres.
	 *
	 * @param int    $year Jahr.
	 * @param string $mode monat oder quartal.
	 * @return array Liste {label, from, to}
	 */
	public static function periods( $year, $mode ) {
		$out    = array();
		$months = array( 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' );

		if ( 'quartal' === $mode ) {
			for ( $q = 1; $q <= 4; $q++ ) {
				$from  = sprintf( '%04d-%02d-01', $year, ( $q - 1 ) * 3 + 1 );
				$out[] = array( 'label' => $q . '. Quartal', 'from' => $from, 'to' => gmdate( 'Y-m-t', strtotime( sprintf( '%04d-%02d-01', $year, $q * 3 ) . ' 12:00:00 UTC' ) ) );
			}
			return $out;
		}

		for ( $m = 1; $m <= 12; $m++ ) {
			$from  = sprintf( '%04d-%02d-01', $year, $m );
			$out[] = array( 'label' => $months[ $m - 1 ], 'from' => $from, 'to' => gmdate( 'Y-m-t', strtotime( $from . ' 12:00:00 UTC' ) ) );
		}

		return $out;
	}

	/* ====================================================================
	 * Reine Regeln: DATEV
	 * ================================================================== */

	/**
	 * Feldnamen der Datenzeilen (erste 14 Felder des Buchungsstapels).
	 *
	 * @return string[]
	 */
	public static function datev_columns() {
		return array( 'Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Kurs', 'Basis-Umsatz', 'WKZ Basis-Umsatz', 'Konto', 'Gegenkonto (ohne BU-Schlüssel)', 'BU-Schlüssel', 'Belegdatum', 'Belegfeld 1', 'Belegfeld 2', 'Skonto', 'Buchungstext' );
	}

	/**
	 * Konto einer Buchung: führende Kontonummer der Kategorie, sonst Vorgabe.
	 *
	 * @param array  $b        Buchung.
	 * @param string $erloes   Erlöskonto.
	 * @param string $sonstige Konto für Ausgaben ohne Nummer.
	 * @return string
	 */
	public static function account_of( array $b, $erloes, $sonstige = '4900' ) {
		if ( preg_match( '/^\s*(\d{4,8})\b/', (string) $b['kategorie'], $m ) ) {
			return $m[1];
		}

		return 'einnahme' === $b['typ'] ? (string) $erloes : (string) $sonstige;
	}

	/**
	 * Buchungen in DATEV-Zeilen übersetzen.
	 *
	 * Einnahme: Bank an Erlöse (Soll Bank). Ausgabe: Aufwand an Bank (Soll
	 * Aufwand). Storno: dieselben Konten mit Haben – die Buchung hebt das
	 * Original auf, so wie im Journal.
	 *
	 * @param array $buchungen Buchungen (id, buchungsnummer, buchungsdatum, typ, betrag, kategorie, beschreibung, referenz_typ, storno_von_id).
	 * @param array $s         Einstellungen {bank_konto, kasse_konto, erloes_konto}.
	 * @return array {rows: array[], skipped: string[]}
	 */
	public static function datev_rows( array $buchungen, array $s ) {
		$by_id   = array();
		$rows    = array();
		$skipped = array();

		foreach ( $buchungen as $b ) {
			$by_id[ (int) $b['id'] ] = $b;
		}

		foreach ( $buchungen as $b ) {
			$sign = 'S';
			$base = $b;

			if ( 'storno' === $b['typ'] ) {
				$orig = isset( $by_id[ (int) $b['storno_von_id'] ] ) ? $by_id[ (int) $b['storno_von_id'] ] : ( isset( $b['original'] ) ? $b['original'] : null );
				if ( ! $orig ) {
					$skipped[] = (string) $b['buchungsnummer'];
					continue;
				}
				$base = $orig;
				$sign = 'H';
			}

			$money = 'kasse' === (string) $base['referenz_typ'] ? (string) $s['kasse_konto'] : (string) $s['bank_konto'];
			$acct  = self::account_of( $base, (string) $s['erloes_konto'] );

			if ( 'einnahme' === $base['typ'] ) {
				$konto  = $money;
				$gegen  = $acct;
			} else {
				$konto  = $acct;
				$gegen  = $money;
			}

			$amount = abs( (float) $b['betrag'] );
			if ( $amount < 0.005 ) {
				continue;
			}

			$date   = substr( (string) $b['buchungsdatum'], 0, 10 );
			$text   = trim( (string) $b['beschreibung'] );
			$rows[] = array(
				number_format( $amount, 2, ',', '' ),
				$sign,
				'EUR',
				'',
				'',
				'',
				$konto,
				$gegen,
				'',
				substr( $date, 8, 2 ) . substr( $date, 5, 2 ),
				substr( preg_replace( '/[^A-Za-z0-9$%&*+\-\/._]/', '', (string) $b['buchungsnummer'] ), 0, 36 ),
				'',
				'',
				Kit::cut( '' !== $text ? $text : (string) $b['kategorie'], 60 ),
			);
		}

		return array( 'rows' => $rows, 'skipped' => $skipped );
	}

	/**
	 * Kopfzeile des Buchungsstapels (EXTF, Version 700, Kategorie 21).
	 *
	 * @param array  $s       Einstellungen {berater, mandant, sachkonten, wj_beginn, diktat}.
	 * @param string $from    JJJJ-MM-TT.
	 * @param string $to      JJJJ-MM-TT.
	 * @param string $created Zeitstempel JJJJMMTTHHMMSS.
	 * @return array
	 */
	public static function datev_header( array $s, $from, $to, $created ) {
		$year = substr( $from, 0, 4 );
		$wj   = preg_match( '/^(\d{2})-(\d{2})$/', (string) $s['wj_beginn'], $m ) ? $year . $m[1] . $m[2] : $year . '0101';

		return array(
			'EXTF', 700, 21, 'Buchungsstapel', 13, $created . '000', '', 'RE', '', '',
			(int) $s['berater'], (int) $s['mandant'], $wj, max( 4, min( 8, (int) $s['sachkonten'] ) ),
			str_replace( '-', '', $from ), str_replace( '-', '', $to ),
			'Lindenweg16 ' . Kit::dmy( $from ) . '-' . Kit::dmy( $to ), substr( (string) $s['diktat'], 0, 2 ),
			1, 0, 0, 'EUR', '', '', '', '', '03', '', '', '', '',
		);
	}

	/**
	 * Eine CSV-Zeile im DATEV-Stil: Semikolon, Text in Anführungszeichen, Zahlen ohne.
	 *
	 * @param array $cells Zellen.
	 * @param array $text  Indizes der Textfelder (alle anderen ohne Anführungszeichen).
	 * @return string
	 */
	public static function datev_line( array $cells, array $text = array() ) {
		$out = array();

		foreach ( array_values( $cells ) as $i => $cell ) {
			$cell  = (string) $cell;
			$out[] = ( in_array( $i, $text, true ) || ( ! $text && ! is_numeric( $cell ) && '' !== $cell ) ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
		}

		return implode( ';', $out );
	}

	/**
	 * Die ganze Datei (Windows-1252, CRLF – so liest DATEV sie ein).
	 *
	 * @param array $header Kopf.
	 * @param array $rows   Datenzeilen.
	 * @return string
	 */
	public static function datev_file( array $header, array $rows ) {
		$lines   = array();
		$lines[] = self::datev_line( $header, array( 0, 3, 7, 16, 17, 21, 26 ) );
		$lines[] = implode( ';', self::datev_columns() );

		foreach ( $rows as $row ) {
			// Text: Soll/Haben, WKZ, Konto/Gegenkonto als Zahl, Belegfeld 1, Buchungstext.
			$lines[] = self::datev_line( $row, array( 1, 2, 10, 11, 13 ) );
		}

		$text = implode( "\r\n", $lines ) . "\r\n";

		return function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' ) : $text;
	}

	/* ====================================================================
	 * Routen
	 * ================================================================== */

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns    = RestApi::NAMESPACE_V1;
		$needs = $api->needs( array( 'finanzen.steuer' ) );

		register_rest_route( $ns, '/erp/steuer', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/steuer/einstellungen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/steuer/datev', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'datev' ), 'permission_callback' => $needs ) );
	}

	/**
	 * Umsatz eines Zeitraums wie in der EÜR plus manuelle Aufträge.
	 *
	 * @param string $from JJJJ-MM-TT.
	 * @param string $to   JJJJ-MM-TT.
	 * @return array {shop, manuell, summe}
	 */
	public static function revenue( $from, $to ) {
		global $wpdb;

		$shop = function_exists( 'wc_get_orders' ) ? (float) \Lindenweg16\Modules\Accounting\EuerCalculator::einnahmen( $from, $to ) : 0.0;
		$t    = \Lindenweg16\Modules\Betrieb\OrderOpsRepository::table();
		$man  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(manual_order_value), 0) FROM {$t} WHERE wc_order_id IS NULL AND order_status = 'bestaetigt' AND invoice_status <> 'storniert' AND manual_order_date >= %s AND manual_order_date <= %s", $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array(
			'shop'    => round( $shop, 2 ),
			'manuell' => round( $man, 2 ),
			'summe'   => round( $shop + $man, 2 ),
		);
	}

	/**
	 * Umsatzsteuer aus abgeschlossenen Shop-Bestellungen eines Zeitraums.
	 *
	 * @param string $from JJJJ-MM-TT.
	 * @param string $to   JJJJ-MM-TT.
	 * @return array Satz => {net, tax}
	 */
	public static function vat_of( $from, $to ) {
		$items = array();

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return self::by_rate( $items );
		}

		$orders = wc_get_orders(
			array(
				'status'       => array( 'wc-completed' ),
				'date_created' => $from . '...' . $to,
				'limit'        => -1,
				'return'       => 'objects',
			)
		);

		foreach ( (array) $orders as $order ) {
			foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
				$items[] = array( 'net' => (float) $item->get_total(), 'tax' => (float) $item->get_total_tax() );
			}
			foreach ( $order->get_refunds() as $refund ) {
				foreach ( $refund->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
					$items[] = array( 'net' => (float) $item->get_total(), 'tax' => (float) $item->get_total_tax() );
				}
			}
		}

		return self::by_rate( $items );
	}

	/**
	 * Übersicht.
	 *
	 * @param WP_REST_Request $request Anfrage (jahr, modus).
	 * @return WP_REST_Response
	 */
	public static function overview( WP_REST_Request $request ) {
		$today = current_time( 'Y-m-d' );
		$year  = (int) $request->get_param( 'jahr' );
		$year  = $year >= 2000 && $year <= (int) substr( $today, 0, 4 ) ? $year : (int) substr( $today, 0, 4 );
		$mode  = 'quartal' === $request->get_param( 'modus' ) ? 'quartal' : 'monat';
		$ku    = 1 === (int) \Lindenweg16\Core\Settings::get( 'kleinunternehmer', 1 );
		$out   = array(
			'jahr'             => $year,
			'modus'            => $mode,
			'kleinunternehmer' => $ku,
			'datev'            => Kit::settings( 'datev' ),
			'erloes_konto'     => \Lindenweg16\Modules\Accounting\Kontenrahmen::erloes_konto( $ku ),
			'can_settings'     => Access::can( 'verwaltung.einstellungen' ),
		);

		$cur_to = (int) substr( $today, 0, 4 ) === $year ? $today : $year . '-12-31';
		$prev   = self::revenue( ( $year - 1 ) . '-01-01', ( $year - 1 ) . '-12-31' );
		$cur    = self::revenue( $year . '-01-01', $cur_to );

		$out['umsatz'] = array(
			'vorjahr' => $prev,
			'laufend' => $cur,
			'grenzen' => array( 'vorjahr' => self::GRENZE_VORJAHR, 'laufend' => self::GRENZE_LAUFEND ),
			'status'  => self::ku_status( $prev['summe'], $cur['summe'], $cur_to ),
		);

		if ( ! $ku ) {
			$rows = array();
			foreach ( self::periods( $year, $mode ) as $p ) {
				if ( $p['from'] > $today ) {
					break;
				}
				$vat    = self::vat_of( $p['from'], min( $p['to'], $today ) );
				$rows[] = array(
					'label' => $p['label'],
					'from'  => $p['from'],
					'to'    => $p['to'],
					'kz81'  => $vat[19]['net'],
					'ust19' => $vat[19]['tax'],
					'kz86'  => $vat[7]['net'],
					'ust7'  => $vat[7]['tax'],
					'frei'  => $vat[0]['net'],
					'summe' => round( $vat[19]['tax'] + $vat[7]['tax'], 2 ),
				);
			}
			$out['ust'] = $rows;
		}

		return new WP_REST_Response( $out );
	}

	/**
	 * DATEV-Einstellungen speichern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function save( WP_REST_Request $request ) {
		$in = Kit::input( $request );

		foreach ( array( 'berater', 'mandant' ) as $key ) {
			if ( isset( $in[ $key ] ) && '' !== (string) $in[ $key ] && ! preg_match( '/^\d{1,7}$/', (string) $in[ $key ] ) ) {
				return Kit::fail( __( 'Berater- und Mandantennummer bestehen nur aus Ziffern (Berater bis 7, Mandant bis 5 Stellen).', 'lindenweg16' ) );
			}
		}
		if ( isset( $in['wj_beginn'] ) && ! preg_match( '/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', (string) $in['wj_beginn'] ) ) {
			return Kit::fail( __( 'Beginn des Wirtschaftsjahres bitte als MM-TT, z. B. 01-01.', 'lindenweg16' ) );
		}

		$saved = Kit::save_settings( 'datev', $in );
		AuditLog::record( 'erp_datev_einstellungen', 'settings', 0, $saved );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'DATEV-Einstellungen gespeichert.', 'lindenweg16' ), 'datev' => $saved ) );
	}

	/**
	 * Buchungsstapel herunterladen.
	 *
	 * @param WP_REST_Request $request Anfrage (von, bis).
	 * @return WP_REST_Response
	 */
	public static function datev( WP_REST_Request $request ) {
		global $wpdb;

		$from = Kit::date( (string) $request->get_param( 'von' ) );
		$to   = Kit::date( (string) $request->get_param( 'bis' ) );
		$s    = Kit::settings( 'datev' );

		if ( '' === $from || '' === $to || $from > $to ) {
			return Kit::fail( __( 'Bitte einen gültigen Zeitraum wählen (von … bis).', 'lindenweg16' ) );
		}
		if ( substr( $from, 0, 4 ) !== substr( $to, 0, 4 ) ) {
			return Kit::fail( __( 'Ein DATEV-Stapel darf nur ein Wirtschaftsjahr umfassen. Bitte je Jahr einzeln exportieren.', 'lindenweg16' ) );
		}
		if ( '' === (string) $s['berater'] || '' === (string) $s['mandant'] ) {
			return Kit::fail( __( 'Bitte zuerst Berater- und Mandantennummer eintragen (bekommst du vom Steuerberater).', 'lindenweg16' ) );
		}

		$t    = \Lindenweg16\Modules\Accounting\BuchungRepository::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE buchungsdatum >= %s AND buchungsdatum <= %s ORDER BY buchungsdatum ASC, id ASC", $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		// Stornos, deren Original vor dem Zeitraum liegt, brauchen das Original für die Konten.
		foreach ( $rows as $i => $row ) {
			if ( 'storno' === $row['typ'] && $row['storno_von_id'] ) {
				$orig = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $row['storno_von_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( $orig ) {
					$rows[ $i ]['original'] = $orig;
				}
			}
		}

		$ku               = 1 === (int) \Lindenweg16\Core\Settings::get( 'kleinunternehmer', 1 );
		$s['erloes_konto'] = \Lindenweg16\Modules\Accounting\Kontenrahmen::erloes_konto( $ku );
		$built            = self::datev_rows( (array) $rows, $s );

		if ( ! $built['rows'] ) {
			return Kit::fail( __( 'In diesem Zeitraum gibt es keine Buchungen.', 'lindenweg16' ), 404 );
		}

		$file = self::datev_file( self::datev_header( $s, $from, $to, current_time( 'YmdHis' ) ), $built['rows'] );
		AuditLog::record( 'erp_datev_export', 'buchungen', 0, array( 'von' => $from, 'bis' => $to, 'zeilen' => count( $built['rows'] ) ) );

		$res = Kit::file_response( 'EXTF_Buchungsstapel_' . str_replace( '-', '', $from ) . '_' . str_replace( '-', '', $to ) . '.csv', 'text/csv;charset=windows-1252', $file );
		$data             = $res->get_data();
		$data['count']    = count( $built['rows'] );
		$data['skipped']  = $built['skipped'];
		$res->set_data( $data );

		return $res;
	}
}
