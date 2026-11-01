<?php
/**
 * ERP-Seite „Bankabgleich“: Kontoauszüge einlesen und Zahlungen zuordnen.
 *
 * Eingelesen wird CAMT.053 (XML, das Format, das jede deutsche Bank im
 * Online-Banking als „Kontoauszug/Umsätze als XML“ anbietet) oder eine CSV-Datei
 * mit Kopfzeile (Sparkasse, Volksbank, DKB, ING, Commerzbank, N26 …). Jeder
 * Umsatz bekommt einen Fingerabdruck – derselbe Auszug zweimal eingelesen legt
 * nichts doppelt an.
 *
 * Zuordnen:
 * - Eingang zu einem B2B-Auftrag: Rechnung wird „bezahlt“ mit Zahldatum
 *   (Protokoll über BetriebAudit wie beim Bearbeiten des Auftrags).
 * - Eingang zu einer Shop-Bestellung, die auf Zahlung wartet (Überweisung,
 *   „In Wartestellung“): WooCommerce bekommt den Zahlungseingang.
 * - Ausgang zu einem Beleg: nur die Verknüpfung, der Beleg bleibt unverändert
 *   (GoBD – festgeschriebene Belege werden nicht angefasst).
 * - Oder bewusst „ohne Zuordnung“ (Privatentnahme, Umbuchung) mit Notiz.
 *
 * Reine Regeln (Einlesen, Fingerabdruck, Vorschläge) sind getestet
 * (tests/ErpBankTest.php).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Erp\Kit;
use Lindenweg16\Erp\RestApi;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Bankabgleich.
 */
class Bank {

	/**
	 * Größte Datei, die eingelesen wird (Byte).
	 */
	const MAX_BYTES = 5242880;

	/* ====================================================================
	 * Reine Regeln
	 * ================================================================== */

	/**
	 * Ist der Text eine CAMT-Datei?
	 *
	 * @param string $text Inhalt.
	 * @return bool
	 */
	public static function is_camt( $text ) {
		return false !== strpos( substr( (string) $text, 0, 2000 ), '<' ) && (bool) preg_match( '/camt\.05[234]|<BkToCstmrStmt|<BkToCstmrAcctRpt/i', substr( (string) $text, 0, 5000 ) );
	}

	/**
	 * CAMT.052/053/054 lesen.
	 *
	 * @param string $xml Inhalt.
	 * @return array {iban, closing_balance, closing_date, rows[], error}
	 */
	public static function parse_camt( $xml ) {
		$out = array(
			'iban'            => '',
			'closing_balance' => null,
			'closing_date'    => '',
			'rows'            => array(),
			'error'           => '',
		);

		if ( ! class_exists( 'DOMDocument' ) ) {
			$out['error'] = __( 'Auf dem Server fehlt die XML-Erweiterung (DOM). Bitte die CSV-Datei der Bank nehmen.', 'lindenweg16' );
			return $out;
		}

		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $doc->loadXML( (string) $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok ) {
			$out['error'] = __( 'Die XML-Datei lässt sich nicht lesen. Ist es wirklich ein CAMT-Kontoauszug?', 'lindenweg16' );
			return $out;
		}

		$x   = new \DOMXPath( $doc );
		$val = function ( $query, $ctx = null ) use ( $x ) {
			$n = $ctx ? $x->query( $query, $ctx ) : $x->query( $query );
			return $n && $n->length ? trim( (string) $n->item( 0 )->textContent ) : '';
		};

		$out['iban'] = strtoupper( str_replace( ' ', '', $val( "//*[local-name()='Stmt' or local-name()='Rpt' or local-name()='Ntfctn']/*[local-name()='Acct']/*[local-name()='Id']/*[local-name()='IBAN']" ) ) );

		foreach ( $x->query( "//*[local-name()='Bal']" ) as $bal ) {
			$code = $val( "*[local-name()='Tp']/*[local-name()='CdOrPrtry']/*[local-name()='Cd']", $bal );
			if ( 'CLBD' === $code ) {
				$amount = (float) $val( "*[local-name()='Amt']", $bal );
				$sign   = 'DBIT' === $val( "*[local-name()='CdtDbtInd']", $bal ) ? -1 : 1;
				$date   = Kit::date( $val( "*[local-name()='Dt']/*[local-name()='Dt']", $bal ) . $val( "*[local-name()='Dt']/*[local-name()='DtTm']", $bal ) );

				if ( '' === $out['closing_date'] || $date >= $out['closing_date'] ) {
					$out['closing_balance'] = round( $sign * $amount, 2 );
					$out['closing_date']    = $date;
				}
			}
		}

		foreach ( $x->query( "//*[local-name()='Ntry']" ) as $entry ) {
			// Vorgemerkte Umsätze (PDNG) zählen nicht – sie können noch platzen.
			$status = $val( "*[local-name()='Sts']", $entry ) . $val( "*[local-name()='Sts']/*[local-name()='Cd']", $entry );
			if ( false !== stripos( $status, 'PDNG' ) ) {
				continue;
			}

			$amount = (float) $val( "*[local-name()='Amt']", $entry );
			$debit  = 'DBIT' === $val( "*[local-name()='CdtDbtInd']", $entry );
			$book   = Kit::date( $val( "*[local-name()='BookgDt']/*[local-name()='Dt']", $entry ) . $val( "*[local-name()='BookgDt']/*[local-name()='DtTm']", $entry ) );
			$value  = Kit::date( $val( "*[local-name()='ValDt']/*[local-name()='Dt']", $entry ) . $val( "*[local-name()='ValDt']/*[local-name()='DtTm']", $entry ) );

			$purpose = array();
			foreach ( $x->query( ".//*[local-name()='RmtInf']/*[local-name()='Ustrd']", $entry ) as $n ) {
				$purpose[] = trim( (string) $n->textContent );
			}
			foreach ( $x->query( ".//*[local-name()='RmtInf']/*[local-name()='Strd']//*[local-name()='Ref']", $entry ) as $n ) {
				$purpose[] = trim( (string) $n->textContent );
			}
			if ( ! $purpose ) {
				$purpose[] = $val( "*[local-name()='AddtlNtryInf']", $entry );
			}

			// Gegenpartei: bei Eingang der Zahler (Dbtr), bei Ausgang der Empfänger (Cdtr).
			$party = $debit ? 'Cdtr' : 'Dbtr';
			$name  = $val( ".//*[local-name()='RltdPties']/*[local-name()='{$party}']//*[local-name()='Nm']", $entry );
			$iban  = $val( ".//*[local-name()='RltdPties']/*[local-name()='{$party}Acct']/*[local-name()='Id']/*[local-name()='IBAN']", $entry );

			$out['rows'][] = array(
				'booking_date'      => '' !== $book ? $book : $value,
				'value_date'        => $value,
				'amount'            => round( ( $debit ? -1 : 1 ) * $amount, 2 ),
				'currency'          => strtoupper( substr( (string) $x->query( "*[local-name()='Amt']", $entry )->item( 0 )->getAttribute( 'Ccy' ), 0, 3 ) ) ?: 'EUR',
				'counterparty'      => $name,
				'counterparty_iban' => strtoupper( str_replace( ' ', '', $iban ) ),
				'purpose'           => trim( implode( ' ', array_filter( $purpose ) ) ),
				'end_to_end'        => 'NOTPROVIDED' === $val( ".//*[local-name()='Refs']/*[local-name()='EndToEndId']", $entry ) ? '' : $val( ".//*[local-name()='Refs']/*[local-name()='EndToEndId']", $entry ),
			);
		}

		if ( ! $out['rows'] && ! $out['error'] ) {
			$out['error'] = __( 'In der Datei stehen keine gebuchten Umsätze.', 'lindenweg16' );
		}

		return $out;
	}

	/**
	 * Spaltennamen der Bank-CSV auf unsere Felder.
	 *
	 * @return array Feld => mögliche Überschriften (klein, ohne Sonderzeichen).
	 */
	public static function csv_aliases() {
		return array(
			'booking_date'      => array( 'buchungstag', 'buchungsdatum', 'datum', 'buchung', 'bookingdate', 'date', 'belegdatum' ),
			'value_date'        => array( 'valutadatum', 'valuta', 'wertstellung', 'wertstellungsdatum', 'valuedate' ),
			'amount'            => array( 'betrag', 'betrageur', 'umsatz', 'umsatzeur', 'betraginwahrung', 'amount', 'amounteur', 'betraginwaehrung' ),
			'debit'             => array( 'soll', 'ausgang', 'belastung', 'sollbetrag' ),
			'credit'            => array( 'haben', 'eingang', 'gutschrift', 'habenbetrag' ),
			'counterparty'      => array( 'beguenstigterzahlungspflichtiger', 'namezahlungsbeteiligter', 'zahlungsempfaenger', 'auftraggeberempfaenger', 'empfaengerauftraggeber', 'auftraggeberbeguenstigter', 'empfaenger', 'auftraggeber', 'name', 'payee', 'beguenstigter', 'zahlungspflichtiger', 'gegenpartei' ),
			'counterparty_iban' => array( 'ibanzahlungsbeteiligter', 'kontonummeriban', 'kontonummer', 'iban', 'ibanauftraggeberempfaenger', 'kontoibangegenpartei', 'accountnumber' ),
			'purpose'           => array( 'verwendungszweck', 'buchungsdetails', 'purpose', 'betreff', 'beschreibung', 'zweck', 'paymentreference' ),
			'currency'          => array( 'waehrung', 'wahrung', 'currency' ),
			'end_to_end'        => array( 'endtoendreferenz', 'endtoendid', 'kundenreferenz' ),
		);
	}

	/**
	 * Überschrift vereinheitlichen.
	 *
	 * @param string $text Überschrift.
	 * @return string
	 */
	public static function norm_head( $text ) {
		$text = strtolower( trim( (string) $text, " \t\"'\xEF\xBB\xBF" ) );
		$text = strtr( $text, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue' ) );

		return preg_replace( '/[^a-z0-9]/', '', $text );
	}

	/**
	 * Bank-CSV lesen. Vorspann (Kontoname, Zeitraum) wird übersprungen, bis eine
	 * Zeile mit Datum und Betrag als Überschrift auftaucht.
	 *
	 * @param string $text Inhalt.
	 * @return array {iban, closing_balance, closing_date, rows[], error}
	 */
	public static function parse_csv( $text ) {
		$out = array(
			'iban'            => '',
			'closing_balance' => null,
			'closing_date'    => '',
			'rows'            => array(),
			'error'           => '',
		);

		$text = (string) $text;
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		$text  = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		$lines = preg_split( '/\r\n|\r|\n/', $text );

		// Trennzeichen: das häufigste der ersten Zeilen.
		$sample = implode( "\n", array_slice( $lines, 0, 20 ) );
		$counts = array(
			';'  => substr_count( $sample, ';' ),
			','  => substr_count( $sample, ',' ),
			"\t" => substr_count( $sample, "\t" ),
		);
		arsort( $counts );
		$sep = key( $counts );

		$aliases = self::csv_aliases();
		$map     = null;

		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = str_getcsv( $line, $sep, '"', '' );

			if ( null === $map ) {
				// Vorspann mit IBAN merken („Kontonummer: DE…“).
				if ( preg_match( '/\b(DE\d{2}[\d ]{18,24})\b/', $line, $m ) && '' === $out['iban'] ) {
					$out['iban'] = str_replace( ' ', '', $m[1] );
				}

				$candidate = array();
				foreach ( $cells as $i => $head ) {
					$key = self::norm_head( $head );
					foreach ( $aliases as $field => $names ) {
						if ( ! isset( $candidate[ $field ] ) && in_array( $key, $names, true ) ) {
							$candidate[ $field ] = $i;
							break;
						}
					}
				}

				if ( isset( $candidate['booking_date'] ) && ( isset( $candidate['amount'] ) || isset( $candidate['debit'] ) || isset( $candidate['credit'] ) ) ) {
					$map = $candidate;
				}
				continue;
			}

			$get = function ( $field ) use ( $map, $cells ) {
				return isset( $map[ $field ] ) && isset( $cells[ $map[ $field ] ] ) ? trim( (string) $cells[ $map[ $field ] ] ) : '';
			};

			$date = Kit::date( $get( 'booking_date' ) );
			if ( '' === $date ) {
				continue;
			}

			if ( isset( $map['amount'] ) ) {
				$amount = Kit::amount( $get( 'amount' ) );
			} else {
				$debit  = Kit::amount( $get( 'debit' ) );
				$credit = Kit::amount( $get( 'credit' ) );
				$amount = null === $debit && null === $credit ? null : (float) $credit - abs( (float) $debit );
			}

			if ( null === $amount ) {
				continue;
			}

			$out['rows'][] = array(
				'booking_date'      => $date,
				'value_date'        => Kit::date( $get( 'value_date' ) ),
				'amount'            => round( $amount, 2 ),
				'currency'          => strtoupper( substr( $get( 'currency' ), 0, 3 ) ) ?: 'EUR',
				'counterparty'      => $get( 'counterparty' ),
				'counterparty_iban' => strtoupper( str_replace( ' ', '', $get( 'counterparty_iban' ) ) ),
				'purpose'           => preg_replace( '/\s+/', ' ', $get( 'purpose' ) ),
				'end_to_end'        => $get( 'end_to_end' ),
			);
		}

		if ( null === $map ) {
			$out['error'] = __( 'In der CSV-Datei fehlt eine Überschrift mit Buchungstag und Betrag. Bitte den Umsatz-Export der Bank als CSV nehmen (oder CAMT/XML).', 'lindenweg16' );
		} elseif ( ! $out['rows'] ) {
			$out['error'] = __( 'In der Datei stehen keine Umsätze.', 'lindenweg16' );
		}

		return $out;
	}

	/**
	 * Fingerabdruck eines Umsatzes.
	 *
	 * @param array  $row  Umsatz.
	 * @param string $iban Eigenes Konto.
	 * @param int    $seq  Laufende Nummer gleicher Umsätze in derselben Datei.
	 * @return string
	 */
	public static function fingerprint( array $row, $iban, $seq = 0 ) {
		return sha1(
			implode(
				'|',
				array(
					strtoupper( (string) $iban ),
					(string) $row['booking_date'],
					number_format( (float) $row['amount'], 2, '.', '' ),
					strtolower( preg_replace( '/\s+/', '', (string) $row['purpose'] ) ),
					strtolower( preg_replace( '/\s+/', '', (string) $row['counterparty'] ) ),
					(string) $row['end_to_end'],
					(int) $seq,
				)
			)
		);
	}

	/**
	 * Fingerabdrücke für alle Zeilen einer Datei: Gleiche Umsätze am selben Tag
	 * (zweimal 9,90 € an denselben Händler) bekommen eine laufende Nummer.
	 *
	 * @param array  $rows Umsätze.
	 * @param string $iban Konto.
	 * @return string[]
	 */
	public static function fingerprints( array $rows, $iban ) {
		$seen = array();
		$out  = array();

		foreach ( $rows as $i => $row ) {
			$base         = self::fingerprint( $row, $iban, 0 );
			$seen[ $base ] = isset( $seen[ $base ] ) ? $seen[ $base ] + 1 : 0;
			$out[ $i ]    = $seen[ $base ] ? self::fingerprint( $row, $iban, $seen[ $base ] ) : $base;
		}

		return $out;
	}

	/**
	 * Wörter eines Namens für den Vergleich.
	 *
	 * @param string $text Name.
	 * @return string[]
	 */
	public static function words( $text ) {
		$text  = strtolower( strtr( (string) $text, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue' ) ) );
		$parts = preg_split( '/[^a-z0-9]+/', $text );
		$stop  = array( 'gmbh', 'ug', 'kg', 'ag', 'ohg', 'gbr', 'und', 'der', 'die', 'das', 'co', 'mbh', 'e', 'k', 'ek', 'inh', 'herr', 'frau' );

		return array_values( array_unique( array_filter( $parts, function ( $w ) use ( $stop ) {
			return strlen( $w ) > 2 && ! in_array( $w, $stop, true );
		} ) ) );
	}

	/**
	 * Vorschläge für einen Umsatz, beste zuerst.
	 *
	 * Punkte: gleicher Betrag 50, Nummer (Bestellung, Rechnung, Beleg) im
	 * Verwendungszweck 40, Name passt 15, Datum innerhalb von 45 Tagen 5. Ein
	 * Vorschlag braucht mindestens 50 Punkte – Betrag allein reicht, ein Name
	 * allein nicht.
	 *
	 * @param array $tx         Umsatz (amount, purpose, counterparty, booking_date).
	 * @param array $candidates Kandidaten {type, id, label, amount, refs[], name, date}.
	 * @param int   $max        Höchstzahl.
	 * @return array Kandidaten mit score und why[], absteigend.
	 */
	public static function suggest( array $tx, array $candidates, $max = 3 ) {
		$amount  = round( (float) $tx['amount'], 2 );
		$purpose = ' ' . strtolower( preg_replace( '/\s+/', ' ', (string) $tx['purpose'] . ' ' . ( isset( $tx['end_to_end'] ) ? $tx['end_to_end'] : '' ) ) ) . ' ';
		$names   = self::words( (string) $tx['counterparty'] );
		$out     = array();

		foreach ( $candidates as $c ) {
			$score = 0;
			$why   = array();

			if ( abs( round( (float) $c['amount'], 2 ) - $amount ) < 0.005 ) {
				$score += 50;
				$why[]  = __( 'Betrag stimmt', 'lindenweg16' );
			} elseif ( ( $amount > 0 ) !== ( (float) $c['amount'] > 0 ) ) {
				continue; // Eingang passt nie zu einer Ausgabe und umgekehrt.
			}

			foreach ( (array) $c['refs'] as $ref ) {
				$ref = strtolower( trim( (string) $ref ) );
				if ( strlen( ltrim( $ref, '#' ) ) >= 3 && preg_match( '/(?<![a-z0-9])' . preg_quote( $ref, '/' ) . '(?![0-9])/', $purpose ) ) {
					$score += 40;
					/* translators: %s: Nummer. */
					$why[] = sprintf( __( 'Nummer %s im Verwendungszweck', 'lindenweg16' ), $ref );
					break;
				}
			}

			if ( $names && array_intersect( $names, self::words( (string) $c['name'] ) ) ) {
				$score += 15;
				$why[]  = __( 'Name passt', 'lindenweg16' );
			}

			if ( ! empty( $c['date'] ) && abs( Kit::days_between( $c['date'], $tx['booking_date'] ) ) <= 45 ) {
				$score += 5;
			}

			if ( $score >= 50 ) {
				$c['score'] = $score;
				$c['why']   = $why;
				$out[]      = $c;
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $b['score'] - $a['score'];
			}
		);

		return array_slice( $out, 0, $max );
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
		$needs = $api->needs( array( 'finanzen.bank' ) );

		register_rest_route( $ns, '/erp/bank', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/bank/import', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'import' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/bank/(?P<id>\d+)/kandidaten', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'candidates_for' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/bank/(?P<id>\d+)/zuordnen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'assign' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/bank/(?P<id>\d+)/ignorieren', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'ignore' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/bank/(?P<id>\d+)/loesen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'release' ), 'permission_callback' => $needs ) );
	}

	/**
	 * Umsätze mit Vorschlägen.
	 *
	 * @param WP_REST_Request $request Anfrage (status, q, page).
	 * @return WP_REST_Response
	 */
	public static function overview( WP_REST_Request $request ) {
		global $wpdb;

		$t      = Kit::table( 'bank_transactions' );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$status = in_array( $status, array( 'offen', 'zugeordnet', 'ignoriert', 'alle' ), true ) ? $status : 'offen';
		$q      = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$per    = 50;
		$where  = array( '1 = 1' );
		$args   = array();

		if ( 'alle' !== $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(purpose LIKE %s OR counterparty LIKE %s OR CAST(amount AS CHAR) LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = '%' . $wpdb->esc_like( str_replace( ',', '.', $q ) ) . '%';
		}

		$sql_where = implode( ' AND ', $where );
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$sql_where}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$sql_where}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$sql_where} ORDER BY booking_date DESC, id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$candidates = null;
		$out        = array();

		foreach ( (array) $rows as $row ) {
			$item = self::row_out( $row );

			if ( 'offen' === $row['status'] ) {
				if ( null === $candidates ) {
					$candidates = self::candidates();
				}
				$item['suggestions'] = self::suggest( $row, $candidates );
			}

			$out[] = $item;
		}

		$counts = array();
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$t} GROUP BY status", ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$counts[ $c['status'] ] = (int) $c['n'];
		}

		return new WP_REST_Response(
			array(
				'rows'    => $out,
				'total'   => $total,
				'page'    => $page,
				'pages'   => max( 1, (int) ceil( $total / $per ) ),
				'status'  => $status,
				'counts'  => $counts,
				'balance' => self::latest_balance(),
				'imports' => $wpdb->get_results( 'SELECT id, file_name, format, rows_total, rows_new, closing_balance, closing_date, created_at FROM ' . Kit::table( 'bank_imports' ) . ' ORDER BY id DESC LIMIT 5', ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL
			)
		);
	}

	/**
	 * Letzter bekannter Kontostand aus einem Import.
	 *
	 * @return array|null {amount, date}
	 */
	public static function latest_balance() {
		global $wpdb;

		$row = $wpdb->get_row( 'SELECT closing_balance, closing_date FROM ' . Kit::table( 'bank_imports' ) . ' WHERE closing_balance IS NOT NULL ORDER BY closing_date DESC, id DESC LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $row ? array( 'amount' => (float) $row['closing_balance'], 'date' => (string) $row['closing_date'] ) : null;
	}

	/**
	 * Zeile für die Oberfläche.
	 *
	 * @param array $row Umsatz.
	 * @return array
	 */
	protected static function row_out( array $row ) {
		$user = $row['matched_by'] ? get_userdata( (int) $row['matched_by'] ) : null;

		return array(
			'id'           => (int) $row['id'],
			'date'         => (string) $row['booking_date'],
			'amount'       => (float) $row['amount'],
			'counterparty' => (string) $row['counterparty'],
			'iban'         => (string) $row['counterparty_iban'],
			'purpose'      => (string) $row['purpose'],
			'status'       => (string) $row['status'],
			'match_type'   => (string) $row['match_type'],
			'match_id'     => (int) $row['match_id'],
			'match_note'   => (string) $row['match_note'],
			'matched_by'   => $user ? (string) $user->display_name : '',
			'suggestions'  => array(),
		);
	}

	/**
	 * Mögliche Gegenstücke: offene B2B-Rechnungen, wartende Shop-Bestellungen,
	 * Belege der letzten 120 Tage, die noch keinem Umsatz zugeordnet sind.
	 *
	 * @return array
	 */
	public static function candidates() {
		global $wpdb;

		$out   = array();
		$taken = array();
		$t     = Kit::table( 'bank_transactions' );

		foreach ( (array) $wpdb->get_results( "SELECT match_type, match_id FROM {$t} WHERE status = 'zugeordnet'", ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$taken[ $m['match_type'] . ':' . (int) $m['match_id'] ] = true;
		}

		// B2B-Aufträge mit offener Rechnung.
		foreach ( \Lindenweg16\Modules\Betrieb\OrderOpsRepository::open_invoices() as $o ) {
			if ( isset( $taken[ 'auftrag:' . (int) $o['id'] ] ) ) {
				continue;
			}
			$contact = $o['contact_id'] ? \Lindenweg16\Modules\Betrieb\ContactRepository::find( (int) $o['contact_id'] ) : null;
			$refs    = array( 'A-' . (int) $o['id'] );
			if ( (int) $o['wc_order_id'] ) {
				$refs[] = (string) (int) $o['wc_order_id'];
				$inv    = \Lindenweg16\Modules\Accounting\InvoiceGenerator::get_by_order( (int) $o['wc_order_id'] );
				if ( $inv && ! empty( $inv->rechnungsnummer ) ) {
					$refs[] = (string) $inv->rechnungsnummer;
				}
			}
			$out[] = array(
				'type'   => 'auftrag',
				'id'     => (int) $o['id'],
				/* translators: 1: Nummer, 2: Firma. */
				'label'  => sprintf( __( 'Auftrag %1$s · %2$s', 'lindenweg16' ), (int) $o['wc_order_id'] ? '#' . (int) $o['wc_order_id'] : 'A-' . (int) $o['id'], $contact ? (string) $contact['company'] : __( 'ohne Kontakt', 'lindenweg16' ) ),
				'amount' => round( \Lindenweg16\Modules\Betrieb\OrderOpsRepository::order_value( $o ), 2 ),
				'refs'   => $refs,
				'name'   => $contact ? (string) $contact['company'] . ' ' . (string) $contact['contact_person'] : '',
				'date'   => (string) ( $o['invoice_date'] ? $o['invoice_date'] : \Lindenweg16\Modules\Betrieb\OrderOpsRepository::order_date( $o ) ),
			);
		}

		// Shop-Bestellungen, die auf die Überweisung warten.
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'status' => array( 'wc-on-hold', 'wc-pending' ),
					'limit'  => 200,
					'return' => 'objects',
				)
			);
			foreach ( (array) $orders as $order ) {
				if ( isset( $taken[ 'shop:' . (int) $order->get_id() ] ) ) {
					continue;
				}
				$refs = array( (string) $order->get_order_number(), (string) $order->get_id() );
				$inv  = \Lindenweg16\Modules\Accounting\InvoiceGenerator::get_by_order( (int) $order->get_id() );
				if ( $inv && ! empty( $inv->rechnungsnummer ) ) {
					$refs[] = (string) $inv->rechnungsnummer;
				}
				$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . ' ' . $order->get_billing_company() );
				$out[] = array(
					'type'   => 'shop',
					'id'     => (int) $order->get_id(),
					/* translators: 1: Nummer, 2: Name. */
					'label'  => sprintf( __( 'Shop-Bestellung #%1$s · %2$s', 'lindenweg16' ), $order->get_order_number(), $name ),
					'amount' => round( (float) $order->get_total(), 2 ),
					'refs'   => $refs,
					'name'   => $name,
					'date'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
				);
			}
		}

		// Belege (Ausgaben).
		$b = \Lindenweg16\Modules\Accounting\BelegRepository::table();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, belegnummer, betrag, datum, haendler FROM {$b} WHERE storniert = 0 AND datum >= %s ORDER BY datum DESC LIMIT 500", gmdate( 'Y-m-d', time() - 120 * DAY_IN_SECONDS ) ), ARRAY_A ) as $beleg ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( isset( $taken[ 'beleg:' . (int) $beleg['id'] ] ) ) {
				continue;
			}
			$out[] = array(
				'type'   => 'beleg',
				'id'     => (int) $beleg['id'],
				/* translators: 1: Belegnummer, 2: Händler. */
				'label'  => sprintf( __( 'Beleg %1$s · %2$s', 'lindenweg16' ), $beleg['belegnummer'] ? $beleg['belegnummer'] : '#' . (int) $beleg['id'], (string) $beleg['haendler'] ),
				'amount' => -1 * abs( round( (float) $beleg['betrag'], 2 ) ),
				'refs'   => array_filter( array( (string) $beleg['belegnummer'] ) ),
				'name'   => (string) $beleg['haendler'],
				'date'   => (string) $beleg['datum'],
			);
		}

		return $out;
	}

	/**
	 * Alle passenden Kandidaten eines Umsatzes (für die Handauswahl).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function candidates_for( WP_REST_Request $request ) {
		$tx = self::find( (int) $request['id'] );

		if ( ! $tx ) {
			return Kit::fail( __( 'Diesen Umsatz gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$incoming = (float) $tx['amount'] > 0;
		$list     = array();

		foreach ( self::candidates() as $c ) {
			if ( ( (float) $c['amount'] > 0 ) === $incoming ) {
				$list[] = $c;
			}
		}

		$best = self::suggest( $tx, $list, 3 );

		return new WP_REST_Response( array( 'suggestions' => $best, 'all' => $list ) );
	}

	/**
	 * Umsatz laden.
	 *
	 * @param int $id Kennung.
	 * @return array|null
	 */
	protected static function find( $id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Kit::table( 'bank_transactions' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $row ? $row : null;
	}

	/**
	 * Kontoauszug einlesen.
	 *
	 * @param WP_REST_Request $request Anfrage {name, content}.
	 * @return WP_REST_Response
	 */
	public static function import( WP_REST_Request $request ) {
		global $wpdb;

		$in      = Kit::input( $request );
		$name    = sanitize_file_name( isset( $in['name'] ) ? (string) $in['name'] : 'kontoauszug' );
		$content = isset( $in['content'] ) ? (string) $in['content'] : '';

		if ( '' === trim( $content ) ) {
			return Kit::fail( __( 'Die Datei ist leer.', 'lindenweg16' ) );
		}
		if ( strlen( $content ) > self::MAX_BYTES ) {
			return Kit::fail( __( 'Die Datei ist größer als 5 MB. Bitte einen kürzeren Zeitraum exportieren.', 'lindenweg16' ) );
		}

		$format = self::is_camt( $content ) ? 'camt' : 'csv';
		$parsed = 'camt' === $format ? self::parse_camt( $content ) : self::parse_csv( $content );

		if ( '' !== $parsed['error'] ) {
			return Kit::fail( $parsed['error'] );
		}

		$wpdb->insert(
			Kit::table( 'bank_imports' ),
			array(
				'file_name'       => $name,
				'format'          => $format,
				'account_iban'    => $parsed['iban'],
				'rows_total'      => count( $parsed['rows'] ),
				'rows_new'        => 0,
				'closing_balance' => $parsed['closing_balance'],
				'closing_date'    => $parsed['closing_date'] ? $parsed['closing_date'] : null,
				'created_at'      => current_time( 'mysql', true ),
				'created_by'      => get_current_user_id(),
			)
		);
		$import_id = (int) $wpdb->insert_id;

		if ( ! $import_id ) {
			return Kit::fail( __( 'Der Import ließ sich nicht speichern. Ist das Plugin-Update vollständig gelaufen (Datenbank)?', 'lindenweg16' ), 500 );
		}

		$prints = self::fingerprints( $parsed['rows'], $parsed['iban'] );
		$new    = 0;
		$t      = Kit::table( 'bank_transactions' );

		foreach ( $parsed['rows'] as $i => $row ) {
			$ok = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$t} (import_id, fingerprint, account_iban, booking_date, value_date, amount, currency, counterparty, counterparty_iban, purpose, end_to_end, status, created_at) VALUES (%d, %s, %s, %s, %s, %f, %s, %s, %s, %s, %s, 'offen', %s)", // phpcs:ignore WordPress.DB.PreparedSQL
					$import_id,
					$prints[ $i ],
					$parsed['iban'],
					$row['booking_date'],
					$row['value_date'] ? $row['value_date'] : $row['booking_date'],
					$row['amount'],
					$row['currency'],
					Kit::cut( $row['counterparty'], 250 ),
					substr( $row['counterparty_iban'], 0, 40 ),
					$row['purpose'],
					substr( $row['end_to_end'], 0, 100 ),
					current_time( 'mysql', true )
				)
			);
			$new += $ok ? 1 : 0;
		}

		$wpdb->update( Kit::table( 'bank_imports' ), array( 'rows_new' => $new ), array( 'id' => $import_id ) );
		AuditLog::record( 'erp_bank_import', 'bank_import', $import_id, array( 'datei' => $name, 'format' => $format, 'zeilen' => count( $parsed['rows'] ), 'neu' => $new ) );

		$skipped = count( $parsed['rows'] ) - $new;

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'new'     => $new,
				'skipped' => $skipped,
				'message' => $skipped
					/* translators: 1: neu, 2: schon vorhanden. */
					? sprintf( __( '%1$d neue Umsätze eingelesen, %2$d waren schon da.', 'lindenweg16' ), $new, $skipped )
					/* translators: %d: Anzahl. */
					: sprintf( __( '%d neue Umsätze eingelesen.', 'lindenweg16' ), $new ),
			),
			201
		);
	}

	/**
	 * Umsatz zuordnen.
	 *
	 * @param WP_REST_Request $request Anfrage {type, target_id}.
	 * @return WP_REST_Response
	 */
	public static function assign( WP_REST_Request $request ) {
		global $wpdb;

		$tx = self::find( (int) $request['id'] );
		$in = Kit::input( $request );

		if ( ! $tx ) {
			return Kit::fail( __( 'Diesen Umsatz gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( 'offen' !== $tx['status'] ) {
			return Kit::fail( __( 'Dieser Umsatz ist schon erledigt. Erst die Zuordnung lösen, dann neu zuordnen.', 'lindenweg16' ), 409 );
		}

		$type   = sanitize_key( isset( $in['type'] ) ? (string) $in['type'] : '' );
		$target = isset( $in['target_id'] ) ? (int) $in['target_id'] : 0;
		$date   = (string) $tx['booking_date'];
		$note   = '';

		if ( 'auftrag' === $type ) {
			$order = \Lindenweg16\Modules\Betrieb\OrderOpsRepository::find( $target );
			if ( ! $order ) {
				return Kit::fail( __( 'Diesen Auftrag gibt es nicht (mehr).', 'lindenweg16' ), 404 );
			}
			if ( 'bezahlt' !== $order['invoice_status'] ) {
				$change = array(
					'invoice_status' => 'bezahlt',
					'paid_date'      => $date,
					'updated_at'     => current_time( 'mysql', true ),
				);
				$wpdb->update( \Lindenweg16\Modules\Betrieb\OrderOpsRepository::table(), $change, array( 'id' => $target ) );
				\Lindenweg16\Modules\Betrieb\BetriebAudit::record( 'update', 'order_ops', $target, $order, $change, __( 'Zahlungseingang laut Bankabgleich', 'lindenweg16' ) );
			}
			/* translators: %s: Datum. */
			$note = sprintf( __( 'Rechnung als bezahlt am %s markiert', 'lindenweg16' ), Kit::dmy( $date ) );
		} elseif ( 'shop' === $type ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $target ) : null;
			if ( ! $order ) {
				return Kit::fail( __( 'Diese Shop-Bestellung gibt es nicht (mehr).', 'lindenweg16' ), 404 );
			}
			if ( in_array( $order->get_status(), array( 'on-hold', 'pending' ), true ) ) {
				/* translators: 1: Datum, 2: Betrag. */
				$order->add_order_note( sprintf( __( 'Zahlungseingang laut Bankabgleich am %1$s: %2$s', 'lindenweg16' ), Kit::dmy( $date ), Kit::eur( $tx['amount'] ) ) );
				$order->payment_complete( 'bank-' . (int) $tx['id'] );
				$note = __( 'Zahlung in WooCommerce verbucht', 'lindenweg16' );
			} else {
				$note = __( 'Bestellung war schon bezahlt – nur verknüpft', 'lindenweg16' );
			}
		} elseif ( 'beleg' === $type ) {
			$beleg = \Lindenweg16\Modules\Accounting\BelegRepository::get( $target );
			if ( ! $beleg ) {
				return Kit::fail( __( 'Diesen Beleg gibt es nicht (mehr).', 'lindenweg16' ), 404 );
			}
			$note = __( 'mit Beleg verknüpft', 'lindenweg16' );
		} else {
			return Kit::fail( __( 'Bitte wähle, wozu der Umsatz gehört.', 'lindenweg16' ) );
		}

		$wpdb->update(
			Kit::table( 'bank_transactions' ),
			array(
				'status'     => 'zugeordnet',
				'match_type' => $type,
				'match_id'   => $target,
				'match_note' => $note,
				'matched_at' => current_time( 'mysql', true ),
				'matched_by' => get_current_user_id(),
			),
			array( 'id' => (int) $tx['id'] )
		);
		AuditLog::record( 'erp_bank_zuordnung', 'bank_transaction', (int) $tx['id'], array( 'typ' => $type, 'ziel' => $target, 'betrag' => (float) $tx['amount'] ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Zugeordnet: ', 'lindenweg16' ) . $note . '.' ) );
	}

	/**
	 * Umsatz bewusst ohne Zuordnung erledigen.
	 *
	 * @param WP_REST_Request $request Anfrage {note}.
	 * @return WP_REST_Response
	 */
	public static function ignore( WP_REST_Request $request ) {
		global $wpdb;

		$tx   = self::find( (int) $request['id'] );
		$in   = Kit::input( $request );
		$note = Kit::cut( sanitize_text_field( isset( $in['note'] ) ? (string) $in['note'] : '' ), 250 );

		if ( ! $tx ) {
			return Kit::fail( __( 'Diesen Umsatz gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( '' === $note ) {
			return Kit::fail( __( 'Bitte kurz notieren, was das ist (z. B. „Privatentnahme“ oder „Umbuchung Tagesgeld“).', 'lindenweg16' ) );
		}

		$wpdb->update(
			Kit::table( 'bank_transactions' ),
			array(
				'status'     => 'ignoriert',
				'match_type' => null,
				'match_id'   => null,
				'match_note' => $note,
				'matched_at' => current_time( 'mysql', true ),
				'matched_by' => get_current_user_id(),
			),
			array( 'id' => (int) $tx['id'] )
		);
		AuditLog::record( 'erp_bank_ohne_zuordnung', 'bank_transaction', (int) $tx['id'], array( 'notiz' => $note ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Als erledigt ohne Zuordnung markiert.', 'lindenweg16' ) ) );
	}

	/**
	 * Zuordnung lösen. Eine schon als bezahlt markierte Rechnung bleibt bezahlt –
	 * das muss man bewusst im Auftrag ändern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function release( WP_REST_Request $request ) {
		global $wpdb;

		$tx = self::find( (int) $request['id'] );

		if ( ! $tx ) {
			return Kit::fail( __( 'Diesen Umsatz gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$wpdb->update(
			Kit::table( 'bank_transactions' ),
			array(
				'status'     => 'offen',
				'match_type' => null,
				'match_id'   => null,
				'match_note' => null,
				'matched_at' => null,
				'matched_by' => null,
			),
			array( 'id' => (int) $tx['id'] )
		);
		AuditLog::record( 'erp_bank_zuordnung_geloest', 'bank_transaction', (int) $tx['id'], array( 'typ' => (string) $tx['match_type'], 'ziel' => (int) $tx['match_id'] ) );

		$msg = 'auftrag' === $tx['match_type'] || 'shop' === $tx['match_type']
			? __( 'Zuordnung gelöst. Die Rechnung bzw. Bestellung bleibt bezahlt – falls das falsch war, bitte dort ändern.', 'lindenweg16' )
			: __( 'Zuordnung gelöst.', 'lindenweg16' );

		return new WP_REST_Response( array( 'ok' => true, 'message' => $msg ) );
	}
}
