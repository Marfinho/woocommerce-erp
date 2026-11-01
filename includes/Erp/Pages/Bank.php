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

	/**
	 * Gehört eine Zeile aus dem Kontoauszug zu einem schon per Vivid abgerufenen Umsatz?
	 *
	 * Gleicher Betrag, Buchungstag höchstens drei Tage auseinander, noch nicht
	 * ergänzt. Bei mehreren gewinnt der zeitlich nächste; sind zwei gleich nah,
	 * wird nicht geraten (null).
	 *
	 * @param array $row        Zeile aus der Datei (amount, booking_date).
	 * @param array $candidates Vivid-Umsätze (id, amount, booking_date, enriched).
	 * @param array $taken      Schon in dieser Datei vergebene Kennungen (id => true).
	 * @return int|null
	 */
	public static function merge_target( array $row, array $candidates, array $taken = array() ) {
		$best  = null;
		$dist  = 99;
		$equal = false;

		foreach ( $candidates as $c ) {
			if ( ! empty( $c['enriched'] ) || isset( $taken[ (int) $c['id'] ] ) || abs( (float) $c['amount'] - (float) $row['amount'] ) >= 0.005 ) {
				continue;
			}
			$d = abs( Kit::days_between( $c['booking_date'], $row['booking_date'] ) );
			if ( $d > 3 ) {
				continue;
			}
			if ( $d < $dist ) {
				$best  = (int) $c['id'];
				$dist  = $d;
				$equal = false;
			} elseif ( $d === $dist ) {
				$equal = true;
			}
		}

		return $equal ? null : $best;
	}

	/**
	 * Eindeutiger Vorschlag für die automatische Zuordnung.
	 *
	 * Nur wenn der beste Vorschlag mindestens die Schwelle erreicht, der Betrag
	 * genau stimmt und kein zweiter Vorschlag nahe dran ist (Abstand 40 Punkte).
	 *
	 * @param array $suggestions suggest().
	 * @param int   $threshold   Schwelle.
	 * @return array|null
	 */
	public static function auto_pick( array $suggestions, $threshold ) {
		if ( ! $suggestions ) {
			return null;
		}

		$top = $suggestions[0];
		if ( (int) $top['score'] < (int) $threshold || ! in_array( __( 'Betrag stimmt', 'lindenweg16' ), (array) $top['why'], true ) ) {
			return null;
		}
		if ( isset( $suggestions[1] ) && (int) $suggestions[1]['score'] > (int) $top['score'] - 40 ) {
			return null;
		}

		return $top;
	}

	/**
	 * Regel, die auf einen Umsatz passt.
	 *
	 * @param array $tx    Umsatz (counterparty, purpose, amount).
	 * @param array $rules Regeln {text, field: name|zweck|beide, direction: alle|ein|aus, note}.
	 * @return array|null
	 */
	public static function rule_for( array $tx, array $rules ) {
		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $tx['counterparty'], 'UTF-8' ) : strtolower( (string) $tx['counterparty'] );
		$why  = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $tx['purpose'], 'UTF-8' ) : strtolower( (string) $tx['purpose'] );

		foreach ( $rules as $r ) {
			$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $r['text'] ), 'UTF-8' ) : strtolower( trim( (string) $r['text'] ) );
			if ( '' === $needle ) {
				continue;
			}
			$dir = isset( $r['direction'] ) ? (string) $r['direction'] : 'alle';
			if ( ( 'ein' === $dir && (float) $tx['amount'] <= 0 ) || ( 'aus' === $dir && (float) $tx['amount'] >= 0 ) ) {
				continue;
			}
			$field = isset( $r['field'] ) ? (string) $r['field'] : 'beide';
			$hit   = ( 'zweck' !== $field && false !== strpos( $name, $needle ) ) || ( 'name' !== $field && false !== strpos( $why, $needle ) );
			if ( $hit ) {
				return $r;
			}
		}

		return null;
	}

	/**
	 * Regeln bereinigen.
	 *
	 * @param array $in Rohwerte.
	 * @return array
	 */
	public static function clean_rules( array $in ) {
		$out = array();

		foreach ( $in as $r ) {
			$r    = (array) $r;
			$text = trim( wp_strip_all_tags( (string) ( isset( $r['text'] ) ? $r['text'] : '' ) ) );
			$note = trim( wp_strip_all_tags( (string) ( isset( $r['note'] ) ? $r['note'] : '' ) ) );
			if ( '' === $text || '' === $note ) {
				continue;
			}
			$out[] = array(
				'text'      => Kit::cut( $text, 80 ),
				'field'     => in_array( isset( $r['field'] ) ? $r['field'] : '', array( 'name', 'zweck', 'beide' ), true ) ? $r['field'] : 'beide',
				'direction' => in_array( isset( $r['direction'] ) ? $r['direction'] : '', array( 'alle', 'ein', 'aus' ), true ) ? $r['direction'] : 'alle',
				'note'      => Kit::cut( $note, 120 ),
			);
		}

		return array_slice( $out, 0, 50 );
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
		register_rest_route( $ns, '/erp/bank/vivid', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'vivid_save' ), 'permission_callback' => $api->needs( array( 'finanzen.bank', 'verwaltung.einstellungen' ) ) ) );
		register_rest_route( $ns, '/erp/bank/vivid/abrufen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'vivid_now' ), 'permission_callback' => $needs ) );
		register_rest_route( $ns, '/erp/bank/regeln', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_rules' ), 'permission_callback' => $needs ) );
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
		$acct   = (int) $request->get_param( 'konto' );
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$per    = 50;
		$where  = array( '1 = 1' );
		$args   = array();

		if ( 'alle' !== $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( $acct ) {
			$where[] = 'bank_account_id = %d';
			$args[]  = $acct;
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
				'balance'  => self::latest_balance(),
				'accounts' => self::accounts_out(),
				'vivid'    => self::vivid_out(),
				'rules'    => self::rules(),
				'settings' => Kit::settings( 'bank' ),
				'can_connect' => \Lindenweg16\Erp\Access::can( 'verwaltung.einstellungen' ),
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

		// Live-Salden aus Vivid haben Vorrang: Summe aller offenen Vivid-Konten.
		$live = (array) $wpdb->get_results( 'SELECT balance_available, balance_at FROM ' . Kit::table( 'bank_accounts' ) . " WHERE provider = 'vivid' AND state <> 'closed' AND balance_available IS NOT NULL", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $live ) {
			$sum  = 0.0;
			$when = '';
			foreach ( $live as $l ) {
				$sum += (float) $l['balance_available'];
				$when = max( $when, (string) $l['balance_at'] );
			}
			// Dateikonten mit eigenem Stand dazu.
			foreach ( (array) $wpdb->get_results( 'SELECT balance_available FROM ' . Kit::table( 'bank_accounts' ) . " WHERE provider = 'datei' AND balance_available IS NOT NULL", ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$sum += (float) $f['balance_available'];
			}
			return array( 'amount' => round( $sum, 2 ), 'date' => substr( $when, 0, 10 ), 'source' => 'vivid' );
		}

		$row = $wpdb->get_row( 'SELECT closing_balance, closing_date FROM ' . Kit::table( 'bank_imports' ) . ' WHERE closing_balance IS NOT NULL ORDER BY closing_date DESC, id DESC LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $row ? array( 'amount' => (float) $row['closing_balance'], 'date' => (string) $row['closing_date'], 'source' => 'datei' ) : null;
	}

	/**
	 * Konten für die Oberfläche.
	 *
	 * @return array
	 */
	protected static function accounts_out() {
		global $wpdb;

		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Kit::table( 'bank_accounts' ) . " ORDER BY provider = 'vivid' DESC, name ASC", ARRAY_A ) as $a ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$out[] = array(
				'id'        => (int) $a['id'],
				'provider'  => (string) $a['provider'],
				'name'      => (string) $a['name'],
				'iban'      => (string) $a['iban'],
				'state'     => (string) $a['state'],
				'available' => null === $a['balance_available'] ? null : (float) $a['balance_available'],
				'pending'   => null === $a['balance_pending'] ? null : (float) $a['balance_pending'],
				'at'        => $a['balance_at'] ? strtotime( $a['balance_at'] . ' UTC' ) : 0,
			);
		}

		return $out;
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
			'source'       => (string) $row['source'],
			'pending'      => 'pending' === (string) $row['ext_state'],
			'ext_type'     => (string) $row['ext_type'],
			'card_last4'   => (string) $row['card_last4'],
			'enriched'     => (bool) $row['enriched'],
			'account_id'   => (int) $row['bank_account_id'],
			'match_type'   => (string) $row['match_type'],
			'match_id'     => (int) $row['match_id'],
			'match_note'   => (string) $row['match_note'],
			'matched_by'   => $user ? (string) $user->display_name : ( 'offen' !== $row['status'] && ! (int) $row['matched_by'] ? __( 'automatisch', 'lindenweg16' ) : '' ),
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
	 * Gehört eine Zeile zu einem schon per Vivid abgerufenen Umsatz, werden nur
	 * Name, IBAN und Verwendungszweck ergänzt – es entsteht kein zweiter Umsatz.
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

		$account_id = self::account_for_iban( $parsed['iban'] );

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

		// Kontostand aus dem Auszug am Konto, wenn es kein Live-Konto ist.
		if ( $account_id && null !== $parsed['closing_balance'] ) {
			$acc = $wpdb->get_row( $wpdb->prepare( 'SELECT provider FROM ' . Kit::table( 'bank_accounts' ) . ' WHERE id = %d', $account_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $acc && 'datei' === $acc['provider'] ) {
				$wpdb->update( Kit::table( 'bank_accounts' ), array( 'balance_available' => $parsed['closing_balance'], 'balance_at' => $parsed['closing_date'] . ' 23:59:59' ), array( 'id' => $account_id ) );
			}
		}

		$t      = Kit::table( 'bank_transactions' );
		$prints = self::fingerprints( $parsed['rows'], $parsed['iban'] );
		$new    = 0;
		$merged = 0;
		$ids    = array();
		$taken  = array();

		// Vivid-Umsätze dieses Zeitraums, die noch keinen Verwendungszweck haben.
		$dates = array_column( $parsed['rows'], 'booking_date' );
		$live  = $dates ? (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, amount, booking_date, enriched FROM {$t} WHERE source = 'vivid' AND enriched = 0 AND booking_date >= %s AND booking_date <= %s", Kit::add_days( min( $dates ), -3 ), Kit::add_days( max( $dates ), 3 ) ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL

		foreach ( $parsed['rows'] as $i => $row ) {
			$target = $live ? self::merge_target( $row, $live, $taken ) : null;

			if ( $target ) {
				$taken[ $target ] = true;
				$wpdb->update(
					$t,
					array(
						'counterparty'      => Kit::cut( $row['counterparty'], 250 ),
						'counterparty_iban' => substr( $row['counterparty_iban'], 0, 40 ),
						'purpose'           => $row['purpose'],
						'end_to_end'        => substr( $row['end_to_end'], 0, 100 ),
						'enriched'          => 1,
					),
					array( 'id' => $target )
				);
				$ids[] = $target;
				++$merged;
				continue;
			}

			$ok = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$t} (import_id, bank_account_id, source, fingerprint, account_iban, booking_date, value_date, amount, currency, counterparty, counterparty_iban, purpose, end_to_end, status, created_at) VALUES (%d, %d, 'datei', %s, %s, %s, %s, %f, %s, %s, %s, %s, %s, 'offen', %s)", // phpcs:ignore WordPress.DB.PreparedSQL
					$import_id,
					$account_id,
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
			if ( $ok ) {
				$ids[] = (int) $wpdb->insert_id;
				++$new;
			}
		}

		$wpdb->update( Kit::table( 'bank_imports' ), array( 'rows_new' => $new ), array( 'id' => $import_id ) );
		AuditLog::record( 'erp_bank_import', 'bank_import', $import_id, array( 'datei' => $name, 'format' => $format, 'zeilen' => count( $parsed['rows'] ), 'neu' => $new, 'ergaenzt' => $merged ) );

		$auto    = self::after_new_rows( $ids );
		$skipped = count( $parsed['rows'] ) - $new - $merged;
		$parts   = array();
		/* translators: %d: Anzahl. */
		$parts[] = sprintf( __( '%d neue Umsätze', 'lindenweg16' ), $new );
		if ( $merged ) {
			/* translators: %d: Anzahl. */
			$parts[] = sprintf( __( '%d Vivid-Umsätze um Name und Verwendungszweck ergänzt', 'lindenweg16' ), $merged );
		}
		if ( $skipped ) {
			/* translators: %d: Anzahl. */
			$parts[] = sprintf( __( '%d waren schon da', 'lindenweg16' ), $skipped );
		}
		if ( $auto['assigned'] || $auto['ruled'] ) {
			/* translators: 1: Anzahl, 2: Anzahl. */
			$parts[] = sprintf( __( '%1$d automatisch zugeordnet, %2$d per Regel erledigt', 'lindenweg16' ), $auto['assigned'], $auto['ruled'] );
		}

		return new WP_REST_Response( array( 'ok' => true, 'new' => $new, 'merged' => $merged, 'skipped' => $skipped, 'message' => implode( ', ', $parts ) . '.' ), 201 );
	}

	/**
	 * Konto zu einer IBAN (legt ein Dateikonto an, wenn es keins gibt).
	 *
	 * @param string $iban IBAN.
	 * @return int Kennung oder 0.
	 */
	protected static function account_for_iban( $iban ) {
		global $wpdb;

		$iban = strtoupper( str_replace( ' ', '', (string) $iban ) );
		if ( '' === $iban ) {
			return 0;
		}

		$t  = Kit::table( 'bank_accounts' );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE iban = %s ORDER BY provider = 'vivid' DESC, id ASC LIMIT 1", $iban ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( ! $id ) {
			$wpdb->insert( $t, array( 'provider' => 'datei', 'name' => __( 'Konto', 'lindenweg16' ) . ' ' . substr( $iban, -4 ), 'iban' => $iban, 'created_at' => current_time( 'mysql', true ) ) );
			$id = (int) $wpdb->insert_id;
		}

		return $id;
	}

	/**
	 * Neue Umsätze nachbearbeiten: Regeln anwenden, eindeutige Treffer zuordnen.
	 *
	 * @param int[] $ids Umsätze.
	 * @return array {assigned, ruled}
	 */
	public static function after_new_rows( array $ids ) {
		global $wpdb;

		$out = array( 'assigned' => 0, 'ruled' => 0 );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( ! $ids ) {
			return $out;
		}

		$s          = Kit::settings( 'bank' );
		$rules      = self::rules();
		$candidates = null;
		$t          = Kit::table( 'bank_transactions' );
		$rows       = (array) $wpdb->get_results( 'SELECT * FROM ' . $t . " WHERE status = 'offen' AND id IN (" . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		foreach ( $rows as $tx ) {
			if ( 'pending' === $tx['ext_state'] ) {
				continue; // Vorgemerkt: Betrag kann sich noch ändern.
			}

			$rule = $rules ? self::rule_for( $tx, $rules ) : null;
			if ( $rule ) {
				$wpdb->update( $t, array( 'status' => 'ignoriert', 'match_note' => $rule['note'] . ' ' . __( '(Regel)', 'lindenweg16' ), 'matched_at' => current_time( 'mysql', true ), 'matched_by' => 0 ), array( 'id' => (int) $tx['id'] ) );
				++$out['ruled'];
				continue;
			}

			if ( ! (int) $s['auto_zuordnen'] ) {
				continue;
			}
			if ( null === $candidates ) {
				$candidates = self::candidates();
			}
			$pick = self::auto_pick( self::suggest( $tx, $candidates ), (int) $s['auto_schwelle'] );
			if ( $pick ) {
				$res = self::assign_to( $tx, $pick['type'], (int) $pick['id'], true );
				if ( $res['ok'] ) {
					++$out['assigned'];
					// Ein Gegenstück wird nur einmal vergeben.
					$candidates = array_values( array_filter( $candidates, function ( $c ) use ( $pick ) {
						return ! ( $c['type'] === $pick['type'] && (int) $c['id'] === (int) $pick['id'] );
					} ) );
				}
			}
		}

		return $out;
	}

	/**
	 * Gespeicherte Regeln.
	 *
	 * @return array
	 */
	public static function rules() {
		$r = get_option( 'lw16_erp_bank_rules', array() );

		return is_array( $r ) ? $r : array();
	}

	/* ====================================================================
	 * Vivid
	 * ================================================================== */

	/**
	 * Zustand der Vivid-Anbindung für die Oberfläche (ohne Schlüssel).
	 *
	 * @return array
	 */
	protected static function vivid_out() {
		$s    = \Lindenweg16\Erp\Vivid::state();
		$days = '' !== $s['expires'] ? Kit::days_between( current_time( 'Y-m-d' ), $s['expires'] ) : null;

		return array(
			'connected'     => \Lindenweg16\Erp\Vivid::connected(),
			'has_key'       => '' !== $s['key'],
			'key_hint'      => (string) $s['key_hint'],
			'expires'       => (string) $s['expires'],
			'expires_in'    => $days,
			'last_sync'     => $s['last_sync'] ? strtotime( $s['last_sync'] . ' UTC' ) : 0,
			'last_error'    => (string) $s['last_error'],
			'last_error_at' => $s['last_error_at'] ? strtotime( $s['last_error_at'] . ' UTC' ) : 0,
			'crypto'        => \Lindenweg16\Modules\Mailbox\MailCrypto::available(),
		);
	}

	/**
	 * Schlüssel speichern (vorher mit „ping“ prüfen) oder Anbindung trennen.
	 *
	 * @param WP_REST_Request $request Anfrage {key, expires, disconnect}.
	 * @return WP_REST_Response
	 */
	public static function vivid_save( WP_REST_Request $request ) {
		$in = Kit::input( $request );

		if ( ! empty( $in['disconnect'] ) ) {
			\Lindenweg16\Erp\Vivid::update_state( array( 'key' => '', 'key_hint' => '', 'enabled' => 0, 'last_error' => '' ) );
			AuditLog::record( 'erp_vivid_getrennt', 'settings', 0 );
			return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Vivid getrennt. Der Schlüssel ist gelöscht; bitte ihn auch in Vivid Business → API löschen.', 'lindenweg16' ) ) );
		}

		$key     = trim( (string) ( isset( $in['key'] ) ? $in['key'] : '' ) );
		$expires = Kit::date( isset( $in['expires'] ) ? $in['expires'] : '' );

		if ( ! \Lindenweg16\Modules\Mailbox\MailCrypto::available() ) {
			return Kit::fail( __( 'Auf dem Server fehlt libsodium – ohne Verschlüsselung wird der Schlüssel nicht gespeichert. Bitte den Hoster fragen (PHP-Erweiterung sodium).', 'lindenweg16' ), 500 );
		}

		if ( '' === $key ) {
			// Nur das Ablaufdatum ändern.
			if ( '' === \Lindenweg16\Erp\Vivid::state()['key'] ) {
				return Kit::fail( __( 'Bitte den API-Schlüssel aus Vivid Business eintragen.', 'lindenweg16' ) );
			}
			\Lindenweg16\Erp\Vivid::update_state( array( 'expires' => $expires ) );
			return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Gespeichert.', 'lindenweg16' ) ) );
		}

		if ( ! \Lindenweg16\Erp\Vivid::looks_like_key( $key ) ) {
			return Kit::fail( __( 'Das sieht nicht nach einem Vivid-Schlüssel aus (er beginnt mit „vivid_sk_v1_“). Bitte vollständig kopieren.', 'lindenweg16' ) );
		}

		$ping = \Lindenweg16\Erp\Vivid::call( 'ping', array(), $key );
		if ( ! $ping['ok'] ) {
			return Kit::fail( $ping['message'], 422 );
		}
		$acc = \Lindenweg16\Erp\Vivid::call( 'accounts/current/list', array(), $key );
		if ( ! $acc['ok'] ) {
			return Kit::fail( $acc['message'], 422 );
		}

		\Lindenweg16\Erp\Vivid::update_state(
			array(
				'key'        => \Lindenweg16\Modules\Mailbox\MailCrypto::encrypt( $key ),
				'key_hint'   => '…' . substr( $key, -4 ),
				'expires'    => '' !== $expires ? $expires : Kit::add_days( current_time( 'Y-m-d' ), 180 ),
				'enabled'    => 1,
				'last_error' => '',
			)
		);
		AuditLog::record( 'erp_vivid_verbunden', 'settings', 0, array( 'konten' => count( (array) $acc['data']['accounts'] ) ) );

		$sync = self::vivid_sync();

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Vivid ist verbunden. ', 'lindenweg16' ) . $sync['message'] ) );
	}

	/**
	 * Jetzt abrufen.
	 *
	 * @return WP_REST_Response
	 */
	public static function vivid_now() {
		if ( ! \Lindenweg16\Erp\Vivid::connected() ) {
			return Kit::fail( __( 'Vivid ist nicht verbunden.', 'lindenweg16' ), 409 );
		}

		$lock = 'lw16_erp_vivid_sync';
		if ( get_transient( $lock ) ) {
			return Kit::fail( __( 'Ein Abruf läuft gerade. Bitte kurz warten.', 'lindenweg16' ), 409 );
		}

		$r = self::vivid_sync();

		return $r['ok'] ? new WP_REST_Response( array( 'ok' => true, 'message' => $r['message'] ) ) : Kit::fail( $r['message'], 502 );
	}

	/**
	 * Abruf von Konten und Umsätzen (stündlich und auf Knopfdruck).
	 *
	 * @return array {ok, message, new, updated}
	 */
	public static function vivid_sync() {
		global $wpdb;

		$lock = 'lw16_erp_vivid_sync';
		if ( ! \Lindenweg16\Erp\Vivid::connected() || get_transient( $lock ) ) {
			return array( 'ok' => false, 'message' => __( 'Vivid ist nicht verbunden oder ein Abruf läuft schon.', 'lindenweg16' ), 'new' => 0, 'updated' => 0 );
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		$state = \Lindenweg16\Erp\Vivid::state();
		$fail  = function ( $msg ) use ( $lock ) {
			\Lindenweg16\Erp\Vivid::update_state( array( 'last_error' => $msg, 'last_error_at' => current_time( 'mysql', true ) ) );
			\Lindenweg16\Core\Log::error( 'bank', 'Vivid: ' . $msg );
			delete_transient( $lock );
			return array( 'ok' => false, 'message' => $msg, 'new' => 0, 'updated' => 0 );
		};

		// 1. Konten und Salden.
		$acc = \Lindenweg16\Erp\Vivid::call( 'accounts/current/list' );
		if ( ! $acc['ok'] ) {
			return $fail( $acc['message'] );
		}

		$at       = Kit::table( 'bank_accounts' );
		$accounts = array();
		$now      = current_time( 'mysql', true );

		foreach ( (array) $acc['data']['accounts'] as $a ) {
			$row = \Lindenweg16\Erp\Vivid::account_row( (array) $a );
			$id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$at} WHERE provider = 'vivid' AND external_id = %s", $row['external_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$row['balance_at']   = $now;
			$row['last_sync_at'] = $now;

			if ( $id ) {
				$wpdb->update( $at, $row, array( 'id' => $id ) );
			} else {
				// Ein Dateikonto mit derselben IBAN wird zum Vivid-Konto.
				$file_id = '' !== $row['iban'] ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$at} WHERE provider = 'datei' AND iban = %s LIMIT 1", $row['iban'] ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL
				if ( $file_id ) {
					$wpdb->update( $at, array_merge( $row, array( 'provider' => 'vivid' ) ), array( 'id' => $file_id ) );
					$id = $file_id;
				} else {
					$wpdb->insert( $at, array_merge( $row, array( 'provider' => 'vivid', 'created_at' => $now ) ) );
					$id = (int) $wpdb->insert_id;
				}
			}
			$accounts[ $row['external_id'] ] = array( 'id' => $id, 'iban' => $row['iban'] );
		}

		// 2. Umsätze, seitenweise.
		$from    = \Lindenweg16\Erp\Vivid::updated_from( (string) $state['last_sync'], time() );
		$body    = array( 'limit' => 500 );
		$tt      = Kit::table( 'bank_transactions' );
		$new     = 0;
		$updated = 0;
		$ids     = array();
		$started = $now;

		if ( '' !== $from ) {
			$body['updated_from'] = $from;
		}

		for ( $page = 0; $page < 40; $page++ ) {
			$res = \Lindenweg16\Erp\Vivid::call( 'transactions/list', $body );
			if ( ! $res['ok'] ) {
				return $fail( $res['message'] );
			}

			foreach ( (array) $res['data']['transactions'] as $tx ) {
				$row = \Lindenweg16\Erp\Vivid::to_row( (array) $tx );
				if ( ! $row ) {
					continue;
				}
				$acct = isset( $accounts[ $row['account_ext'] ] ) ? $accounts[ $row['account_ext'] ] : array( 'id' => 0, 'iban' => '' );
				$have = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, enriched FROM {$tt} WHERE external_id = %s AND source = 'vivid' LIMIT 1", $row['external_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

				if ( $have ) {
					$change = array(
						'amount'         => $row['amount'],
						'booking_date'   => $row['booking_date'],
						'ext_state'      => $row['ext_state'],
						'ext_updated_at' => $row['ext_updated_at'],
					);
					if ( ! (int) $have['enriched'] ) {
						$change['counterparty'] = $row['counterparty'];
						$change['purpose']      = $row['purpose'];
					}
					// Von der Bank abgelehnt: erledigt sich von selbst.
					if ( 'declined' === $row['ext_state'] && 'offen' === $have['status'] ) {
						$change['status']     = 'ignoriert';
						$change['match_note'] = __( 'von der Bank abgelehnt', 'lindenweg16' );
						$change['matched_at'] = $now;
						$change['matched_by'] = 0;
					}
					$wpdb->update( $tt, $change, array( 'id' => (int) $have['id'] ) );
					if ( 'offen' === $have['status'] ) {
						$ids[] = (int) $have['id'];
					}
					++$updated;
					continue;
				}

				$insert = array(
					'import_id'       => 0,
					'bank_account_id' => $acct['id'] ? $acct['id'] : null,
					'source'          => 'vivid',
					'external_id'     => $row['external_id'],
					'ext_state'       => $row['ext_state'],
					'ext_type'        => $row['ext_type'],
					'card_last4'      => $row['card_last4'],
					'ext_updated_at'  => $row['ext_updated_at'],
					'fingerprint'     => $row['fingerprint'],
					'account_iban'    => $acct['iban'],
					'booking_date'    => $row['booking_date'],
					'value_date'      => $row['value_date'],
					'amount'          => $row['amount'],
					'currency'        => $row['currency'] ? $row['currency'] : 'EUR',
					'counterparty'    => Kit::cut( $row['counterparty'], 250 ),
					'purpose'         => $row['purpose'],
					'status'          => 'declined' === $row['ext_state'] ? 'ignoriert' : 'offen',
					'match_note'      => 'declined' === $row['ext_state'] ? __( 'von der Bank abgelehnt', 'lindenweg16' ) : null,
					'created_at'      => $now,
				);
				if ( $wpdb->insert( $tt, $insert ) ) {
					$ids[] = (int) $wpdb->insert_id;
					++$new;
				}
			}

			if ( empty( $res['data']['next_cursor'] ) ) {
				break;
			}
			$body['cursor'] = (string) $res['data']['next_cursor'];
		}

		\Lindenweg16\Erp\Vivid::update_state( array( 'last_sync' => $started, 'last_error' => '', 'last_error_at' => '' ) );
		delete_transient( $lock );

		$auto = self::after_new_rows( $ids );

		/* translators: 1: neu, 2: aktualisiert, 3: automatisch zugeordnet. */
		$msg = sprintf( __( 'Abgerufen: %1$d neue, %2$d aktualisierte Umsätze, %3$d automatisch zugeordnet.', 'lindenweg16' ), $new, $updated, $auto['assigned'] );

		return array( 'ok' => true, 'message' => $msg, 'new' => $new, 'updated' => $updated );
	}

	/**
	 * Regeln und Zuordnungs-Einstellungen speichern.
	 *
	 * @param WP_REST_Request $request Anfrage {rules[], auto_zuordnen, auto_schwelle, apply}.
	 * @return WP_REST_Response
	 */
	public static function save_rules( WP_REST_Request $request ) {
		global $wpdb;

		$in    = Kit::input( $request );
		$rules = self::clean_rules( (array) ( isset( $in['rules'] ) ? $in['rules'] : array() ) );

		update_option( 'lw16_erp_bank_rules', $rules, false );
		Kit::save_settings(
			'bank',
			array(
				'auto_zuordnen' => empty( $in['auto_zuordnen'] ) ? 0 : 1,
				'auto_schwelle' => max( 60, min( 150, (int) ( isset( $in['auto_schwelle'] ) ? $in['auto_schwelle'] : 100 ) ) ),
			)
		);
		AuditLog::record( 'erp_bank_regeln', 'settings', 0, array( 'regeln' => count( $rules ) ) );

		$msg = __( 'Gespeichert.', 'lindenweg16' );
		if ( ! empty( $in['apply'] ) ) {
			$ids  = array_map( 'intval', (array) $wpdb->get_col( 'SELECT id FROM ' . Kit::table( 'bank_transactions' ) . " WHERE status = 'offen'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$auto = self::after_new_rows( $ids );
			/* translators: 1: Anzahl, 2: Anzahl. */
			$msg .= ' ' . sprintf( __( 'Auf offene Umsätze angewandt: %1$d zugeordnet, %2$d per Regel erledigt.', 'lindenweg16' ), $auto['assigned'], $auto['ruled'] );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => $msg ) );
	}

	/**
	 * Umsatz zuordnen.
	 *
	 * @param WP_REST_Request $request Anfrage {type, target_id}.
	 * @return WP_REST_Response
	 */
	public static function assign( WP_REST_Request $request ) {
		$tx = self::find( (int) $request['id'] );
		$in = Kit::input( $request );

		if ( ! $tx ) {
			return Kit::fail( __( 'Diesen Umsatz gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( 'offen' !== $tx['status'] ) {
			return Kit::fail( __( 'Dieser Umsatz ist schon erledigt. Erst die Zuordnung lösen, dann neu zuordnen.', 'lindenweg16' ), 409 );
		}

		$res = self::assign_to( $tx, sanitize_key( isset( $in['type'] ) ? (string) $in['type'] : '' ), isset( $in['target_id'] ) ? (int) $in['target_id'] : 0, false );

		return $res['ok'] ? new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Zugeordnet: ', 'lindenweg16' ) . $res['note'] . '.' ) ) : Kit::fail( $res['note'], $res['status'] );
	}

	/**
	 * Zuordnung ausführen (von Hand oder automatisch).
	 *
	 * Bei einem Auftrag mit offener Mahngebühr gilt: Deckt die Zahlung Rechnung
	 * plus Gebühr, wird die Gebühr als Einnahme gebucht (Mahnwesen::book_fee).
	 *
	 * @param array  $tx     Umsatz.
	 * @param string $type   auftrag, shop oder beleg.
	 * @param int    $target Kennung.
	 * @param bool   $auto   Automatisch?
	 * @return array {ok, note, status}
	 */
	public static function assign_to( array $tx, $type, $target, $auto ) {
		global $wpdb;

		$date = (string) $tx['booking_date'];
		$note = '';

		if ( 'auftrag' === $type ) {
			$order = \Lindenweg16\Modules\Betrieb\OrderOpsRepository::find( $target );
			if ( ! $order ) {
				return array( 'ok' => false, 'note' => __( 'Diesen Auftrag gibt es nicht (mehr).', 'lindenweg16' ), 'status' => 404 );
			}
			if ( 'bezahlt' !== $order['invoice_status'] ) {
				$change = array( 'invoice_status' => 'bezahlt', 'paid_date' => $date, 'updated_at' => current_time( 'mysql', true ) );
				$wpdb->update( \Lindenweg16\Modules\Betrieb\OrderOpsRepository::table(), $change, array( 'id' => $target ) );
				\Lindenweg16\Modules\Betrieb\BetriebAudit::record( 'update', 'order_ops', $target, $order, $change, $auto ? __( 'Zahlungseingang laut Bankabgleich (automatisch)', 'lindenweg16' ) : __( 'Zahlungseingang laut Bankabgleich', 'lindenweg16' ) );
			}
			/* translators: %s: Datum. */
			$note = sprintf( __( 'Rechnung als bezahlt am %s markiert', 'lindenweg16' ), Kit::dmy( $date ) );
			$fee  = Mahnwesen::book_fee_if_paid( 'auftrag', $target, (float) $tx['amount'], \Lindenweg16\Modules\Betrieb\OrderOpsRepository::order_value( $order ), $date );
			if ( $fee > 0 ) {
				/* translators: %s: Betrag. */
				$note .= ', ' . sprintf( __( 'Mahngebühr %s gebucht', 'lindenweg16' ), Kit::eur( $fee ) );
			}
		} elseif ( 'shop' === $type ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $target ) : null;
			if ( ! $order ) {
				return array( 'ok' => false, 'note' => __( 'Diese Shop-Bestellung gibt es nicht (mehr).', 'lindenweg16' ), 'status' => 404 );
			}
			if ( in_array( $order->get_status(), array( 'on-hold', 'pending' ), true ) ) {
				/* translators: 1: Datum, 2: Betrag. */
				$order->add_order_note( sprintf( __( 'Zahlungseingang laut Bankabgleich am %1$s: %2$s', 'lindenweg16' ), Kit::dmy( $date ), Kit::eur( $tx['amount'] ) ) );
				$order->payment_complete( 'bank-' . (int) $tx['id'] );
				$note = __( 'Zahlung in WooCommerce verbucht', 'lindenweg16' );
			} else {
				$note = __( 'Bestellung war schon bezahlt – nur verknüpft', 'lindenweg16' );
			}
			$fee = Mahnwesen::book_fee_if_paid( 'shop', $target, (float) $tx['amount'], (float) $order->get_total(), $date );
			if ( $fee > 0 ) {
				/* translators: %s: Betrag. */
				$note .= ', ' . sprintf( __( 'Mahngebühr %s gebucht', 'lindenweg16' ), Kit::eur( $fee ) );
			}
		} elseif ( 'beleg' === $type ) {
			if ( ! \Lindenweg16\Modules\Accounting\BelegRepository::get( $target ) ) {
				return array( 'ok' => false, 'note' => __( 'Diesen Beleg gibt es nicht (mehr).', 'lindenweg16' ), 'status' => 404 );
			}
			$note = __( 'mit Beleg verknüpft', 'lindenweg16' );
		} else {
			return array( 'ok' => false, 'note' => __( 'Bitte wähle, wozu der Umsatz gehört.', 'lindenweg16' ), 'status' => 422 );
		}

		if ( $auto ) {
			$note .= ' ' . __( '(automatisch)', 'lindenweg16' );
		}

		$wpdb->update(
			Kit::table( 'bank_transactions' ),
			array(
				'status'     => 'zugeordnet',
				'match_type' => $type,
				'match_id'   => $target,
				'match_note' => $note,
				'matched_at' => current_time( 'mysql', true ),
				'matched_by' => $auto ? 0 : get_current_user_id(),
			),
			array( 'id' => (int) $tx['id'] )
		);
		AuditLog::record( $auto ? 'erp_bank_zuordnung_auto' : 'erp_bank_zuordnung', 'bank_transaction', (int) $tx['id'], array( 'typ' => $type, 'ziel' => $target, 'betrag' => (float) $tx['amount'] ) );

		return array( 'ok' => true, 'note' => $note, 'status' => 200 );
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
