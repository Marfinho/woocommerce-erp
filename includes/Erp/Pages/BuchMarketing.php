<?php
/**
 * Marketing-Kosten in der Buchhaltung: Budget gegen Kosten gegen Umsatz je
 * Kostenstelle, Summen nach Kanal und Monat, Belege ohne Kampagne und der
 * CSV-Export der Auswertung.
 *
 * Gerechnet wird nicht neu: Kosten, Umsatz, Einsatz, ROAS und Ergebnis jeder
 * Kampagne kommen aus `KampagnenReport` / `Kampagnenrechnung`, Zuordnungen
 * laufen über `Marketing::assign_to()` (dieselben Prüfungen wie auf der
 * Marketing-Seite). Hier stehen nur das Zusammenfassen und die Regeln, die
 * als statische Methoden ohne Datenbank getestet werden
 * (tests/ErpBuchMarketingTest.php).
 *
 * Rechte: Lesen und Export brauchen `finanzen.buchhaltung`. Das Zuordnen
 * ändert eine Kampagne und braucht zusätzlich `verkauf.marketing`.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Accounting\BelegRepository;
use Lindenweg16\Modules\Accounting\Kontenrahmen;
use Lindenweg16\Modules\Marketing\KampagnenReport;
use Lindenweg16\Modules\Marketing\KampagnenRepository;
use Lindenweg16\Modules\Marketing\Kampagnenrechnung;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen `erp/buch/marketing*` und ihre reinen Regeln.
 */
class BuchMarketing {

	/**
	 * Konten, die nach Marketing aussehen (Werbekosten, Streuartikel, Geschenke, Aufmerksamkeiten).
	 * Bewirtung und Fahrtkosten bewusst nicht: Die Fahrt hängt am Kilometerbeleg der Kampagne.
	 */
	const MARKETING_KONTEN = array( '4600', '4610', '4630', '4653' );

	/**
	 * Obergrenze der Liste „noch nicht zugeordnet“ in einer Antwort.
	 */
	const MAX_UNASSIGNED = 200;

	/**
	 * Sekunden je Tag (ohne WordPress-Konstante, damit die Regeln ohne WordPress laufen).
	 */
	const DAY = 86400;

	/* ====================================================================
	 * Reine Regeln (ohne Datenbank, getestet)
	 * ================================================================== */

	/**
	 * Sieht der Beleg nach Marketing aus (Kategorie Werbung/Marketing)?
	 *
	 * @param string $konto     Kontonummer des Belegs (leer = unbekannt).
	 * @param string $kategorie Freitext-Kategorie des Belegs.
	 * @return bool
	 */
	public static function looks_like_marketing( $konto, $kategorie ) {
		if ( in_array( (string) $konto, self::MARKETING_KONTEN, true ) ) {
			return true;
		}
		return 1 === preg_match( '/werb|marketing/iu', (string) $kategorie );
	}

	/**
	 * Zeitraum prüfen; ohne Angabe das laufende Jahr.
	 *
	 * @param mixed  $von   Beginn (JJJJ-MM-TT) oder leer.
	 * @param mixed  $bis   Ende (JJJJ-MM-TT) oder leer.
	 * @param string $heute Heute.
	 * @return array {error: string, von: string, bis: string}
	 */
	public static function normalize_range( $von, $bis, $heute ) {
		$von  = is_string( $von ) ? trim( $von ) : '';
		$bis  = is_string( $bis ) ? trim( $bis ) : '';
		$jahr = substr( (string) $heute, 0, 4 );
		if ( '' === $von ) {
			$von = $jahr . '-01-01';
		}
		if ( '' === $bis ) {
			$bis = $jahr . '-12-31';
		}
		if ( ! Marketing::is_date( $von ) || ! Marketing::is_date( $bis ) ) {
			return array(
				'error' => __( 'Bitte den Zeitraum als gültiges Datum angeben (Von und Bis).', 'lindenweg16' ),
				'von'   => $von,
				'bis'   => $bis,
			);
		}
		if ( $bis < $von ) {
			return array(
				'error' => __( 'Das Ende liegt vor dem Beginn. Bitte den Zeitraum prüfen.', 'lindenweg16' ),
				'von'   => $von,
				'bis'   => $bis,
			);
		}
		return array( 'error' => '', 'von' => $von, 'bis' => $bis );
	}

	/**
	 * Bezugsdatum einer Kampagne für den Zeitraumfilter: Veranstaltungstag, sonst Beginn.
	 *
	 * @param array $k Felder event_datum, start_datum.
	 * @return string
	 */
	public static function reference_date( array $k ) {
		if ( ! empty( $k['event_datum'] ) ) {
			return (string) $k['event_datum'];
		}
		return isset( $k['start_datum'] ) ? (string) $k['start_datum'] : '';
	}

	/**
	 * Liegt die Kampagne im Zeitraum (nach Bezugsdatum)?
	 *
	 * @param array  $k   Kampagne.
	 * @param string $von Beginn.
	 * @param string $bis Ende.
	 * @return bool
	 */
	public static function in_range( array $k, $von, $bis ) {
		$d = self::reference_date( $k );
		return '' !== $d && $d >= $von && $d <= $bis;
	}

	/**
	 * Summen aller Zeilen.
	 *
	 * @param array $rows Zeilen mit budget, kosten, rabatt, einsatz, umsatz, bestellungen.
	 * @return array
	 */
	public static function totals( array $rows ) {
		$t = array( 'count' => 0, 'budget' => 0.0, 'kosten' => 0.0, 'rabatt' => 0.0, 'einsatz' => 0.0, 'umsatz' => 0.0, 'bestellungen' => 0 );
		foreach ( $rows as $r ) {
			$t['count']++;
			foreach ( array( 'budget', 'kosten', 'rabatt', 'einsatz', 'umsatz' ) as $f ) {
				$t[ $f ] += isset( $r[ $f ] ) ? (float) $r[ $f ] : 0.0;
			}
			$t['bestellungen'] += isset( $r['bestellungen'] ) ? (int) $r['bestellungen'] : 0;
		}
		foreach ( array( 'budget', 'kosten', 'rabatt', 'einsatz', 'umsatz' ) as $f ) {
			$t[ $f ] = round( $t[ $f ], 2 );
		}
		$t['ergebnis'] = Kampagnenrechnung::ergebnis( $t['umsatz'], $t['einsatz'] );
		$t['roas']     = Kampagnenrechnung::roas( $t['umsatz'], $t['einsatz'] );
		$t['ampel']    = Kampagnenrechnung::budget_status( $t['budget'], $t['kosten'] )['ampel'];
		return $t;
	}

	/**
	 * Summen nach Kanal. Gleiche Kanäle in anderer Schreibweise („google ads“, „Google Ads“) zählen zusammen.
	 *
	 * @param array $rows Zeilen mit kanal, budget, kosten, rabatt, einsatz, umsatz.
	 * @return array Liste aus channel, count, budget, kosten, rabatt, einsatz, umsatz, ergebnis, roas; größter Einsatz zuerst.
	 */
	public static function summarize_channels( array $rows ) {
		$groups = array();
		foreach ( $rows as $r ) {
			$name = trim( isset( $r['kanal'] ) ? (string) $r['kanal'] : '' );
			$key  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'channel' => '' === $name ? __( 'Ohne Kanal', 'lindenweg16' ) : $name,
					'rows'    => array(),
				);
			}
			$groups[ $key ]['rows'][] = $r;
		}

		$out = array();
		foreach ( $groups as $g ) {
			$t     = self::totals( $g['rows'] );
			$out[] = array(
				'channel'  => $g['channel'],
				'count'    => $t['count'],
				'budget'   => $t['budget'],
				'kosten'   => $t['kosten'],
				'rabatt'   => $t['rabatt'],
				'einsatz'  => $t['einsatz'],
				'umsatz'   => $t['umsatz'],
				'ergebnis' => $t['ergebnis'],
				'roas'     => $t['roas'],
			);
		}
		usort( $out, function ( $a, $b ) {
			if ( $a['einsatz'] === $b['einsatz'] ) {
				return strcmp( $a['channel'], $b['channel'] );
			}
			return $a['einsatz'] < $b['einsatz'] ? 1 : -1;
		} );
		return $out;
	}

	/**
	 * Kosten nach Monat (Belegdatum). Monate ohne Kosten dazwischen erscheinen mit 0,
	 * damit die Balken eine durchgehende Zeitachse zeigen.
	 *
	 * @param array $items Liste aus datum (JJJJ-MM-TT) und betrag.
	 * @return array Liste aus month (JJJJ-MM) und sum, aufsteigend.
	 */
	public static function summarize_months( array $items ) {
		$sums = array();
		foreach ( $items as $i ) {
			$d = isset( $i['datum'] ) ? (string) $i['datum'] : '';
			if ( ! preg_match( '/^(\d{4})-(\d{2})/', $d, $m ) ) {
				continue;
			}
			$key = $m[1] . '-' . $m[2];
			if ( ! isset( $sums[ $key ] ) ) {
				$sums[ $key ] = 0.0;
			}
			$sums[ $key ] += isset( $i['betrag'] ) ? (float) $i['betrag'] : 0.0;
		}
		if ( ! $sums ) {
			return array();
		}
		ksort( $sums );
		$keys = array_keys( $sums );
		$y    = (int) substr( $keys[0], 0, 4 );
		$mo   = (int) substr( $keys[0], 5, 2 );
		$last = end( $keys );
		$out  = array();
		for ( $n = 0; $n < 120; $n++ ) {
			$key   = sprintf( '%04d-%02d', $y, $mo );
			$out[] = array( 'month' => $key, 'sum' => round( isset( $sums[ $key ] ) ? $sums[ $key ] : 0.0, 2 ) );
			if ( $key >= $last ) {
				break;
			}
			$mo++;
			if ( $mo > 12 ) {
				$mo = 1;
				$y++;
			}
		}
		return $out;
	}

	/**
	 * Vorschlag: zu welcher Kampagne gehört dieser Beleg?
	 *
	 * Punkte: Belegdatum im Zeitraum der Kampagne (Veranstaltungstag oder Beginn bis Ende,
	 * 60 Tage Nachlauf für Rechnungen), Veranstaltungstag nah am Belegdatum, Kampagnenname
	 * oder Kanal im Händler- oder Notiztext, laufende Kampagne vor geplanter vor
	 * abgeschlossener. Ohne Datums- oder Namenstreffer gibt es keinen Vorschlag.
	 *
	 * @param array $beleg     Felder datum, haendler, notiz.
	 * @param array $kampagnen Liste von Kampagnen (id, kostenstelle, name, kanal, status, start_datum, ende_datum, event_datum).
	 * @return array Liste aus id, score, reason; bester zuerst; leer ohne Treffer.
	 */
	public static function suggest_campaigns( array $beleg, array $kampagnen ) {
		$datum = isset( $beleg['datum'] ) ? (string) $beleg['datum'] : '';
		$text  = self::lower( ( isset( $beleg['haendler'] ) ? $beleg['haendler'] : '' ) . ' ' . ( isset( $beleg['notiz'] ) ? $beleg['notiz'] : '' ) );
		$t     = '' === $datum ? false : strtotime( $datum . ' 00:00:00 UTC' );

		$out = array();
		foreach ( $kampagnen as $k ) {
			$score   = 0;
			$reasons = array();

			$start = ! empty( $k['start_datum'] ) ? (string) $k['start_datum'] : '';
			$event = ! empty( $k['event_datum'] ) ? (string) $k['event_datum'] : '';
			$ende  = ! empty( $k['ende_datum'] ) ? (string) $k['ende_datum'] : '';
			$lo    = '' !== $event && ( '' === $start || $event < $start ) ? $event : $start;
			$hi    = max( $start, $event, $ende );

			if ( false !== $t && '' !== $lo && '' !== $hi ) {
				$von = strtotime( $lo . ' 00:00:00 UTC' ) - 30 * self::DAY;
				$bis = strtotime( $hi . ' 00:00:00 UTC' ) + 60 * self::DAY;
				if ( $t >= $von && $t <= $bis ) {
					$score    += 3;
					$reasons[] = __( 'Belegdatum liegt im Zeitraum der Kampagne', 'lindenweg16' );
				}
			}
			if ( false !== $t && '' !== $event ) {
				$diff = abs( $t - strtotime( $event . ' 00:00:00 UTC' ) );
				if ( $diff <= 3 * self::DAY ) {
					$score    += 2;
					$reasons[] = __( 'nah am Veranstaltungstag', 'lindenweg16' );
				}
			}
			if ( '' !== trim( $text ) ) {
				foreach ( preg_split( '/[^\p{L}\p{N}]+/u', self::lower( isset( $k['name'] ) ? $k['name'] : '' ), -1, PREG_SPLIT_NO_EMPTY ) as $wort ) {
					if ( self::strlen( $wort ) >= 4 && false !== strpos( $text, $wort ) ) {
						$score    += 2;
						$reasons[] = __( 'Name der Kampagne steht auf dem Beleg', 'lindenweg16' );
						break;
					}
				}
				$kanal = trim( self::lower( isset( $k['kanal'] ) ? $k['kanal'] : '' ) );
				if ( self::strlen( $kanal ) >= 3 && false !== strpos( $text, $kanal ) ) {
					$score    += 2;
					$reasons[] = __( 'Kanal steht auf dem Beleg', 'lindenweg16' );
				}
			}

			if ( $score < 3 ) {
				continue;
			}
			$status = isset( $k['status'] ) ? (string) $k['status'] : '';
			if ( 'aktiv' === $status ) {
				$score++;
			} elseif ( 'abgeschlossen' === $status ) {
				$score--;
			}

			$out[] = array(
				'id'     => (int) $k['id'],
				'score'  => $score,
				'reason' => implode( ', ', array_unique( $reasons ) ),
			);
		}

		usort( $out, function ( $a, $b ) {
			if ( $a['score'] === $b['score'] ) {
				return $b['id'] - $a['id'];
			}
			return $b['score'] - $a['score'];
		} );
		return $out;
	}

	/**
	 * Kleinbuchstaben, mehrbytesicher wenn möglich.
	 *
	 * @param mixed $s Text.
	 * @return string
	 */
	protected static function lower( $s ) {
		$s = (string) $s;
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/**
	 * Zeichenlänge, mehrbytesicher wenn möglich.
	 *
	 * @param string $s Text.
	 * @return int
	 */
	protected static function strlen( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	/**
	 * Zelle für CSV: Zahlen mit Dezimalkomma, Text gegen Formel-Einschleusung geschützt
	 * (Tabellenprogramme führen Zellen aus, die mit = + - @ beginnen) und bei Bedarf in Anführungszeichen.
	 *
	 * @param mixed $v Wert.
	 * @return string
	 */
	public static function csv_cell( $v ) {
		if ( null === $v ) {
			return '';
		}
		if ( is_int( $v ) ) {
			return (string) $v;
		}
		if ( is_float( $v ) ) {
			return number_format( $v, 2, ',', '' );
		}
		$s = str_replace( array( "\r\n", "\r", "\n" ), ' ', (string) $v );
		if ( '' !== $s && false !== strpos( "=+-@\t", $s[0] ) ) {
			$s = "'" . $s;
		}
		if ( preg_match( '/[;"]/', $s ) ) {
			$s = '"' . str_replace( '"', '""', $s ) . '"';
		}
		return $s;
	}

	/**
	 * CSV-Text (Semikolon, Dezimalkomma, Zeilenende CRLF) für deutsche Tabellenprogramme.
	 *
	 * @param array $header Spaltenköpfe.
	 * @param array $rows   Zeilen aus Werten.
	 * @return string
	 */
	public static function csv( array $header, array $rows ) {
		$lines = array( implode( ';', array_map( array( __CLASS__, 'csv_cell' ), $header ) ) );
		foreach ( $rows as $r ) {
			$lines[] = implode( ';', array_map( array( __CLASS__, 'csv_cell' ), $r ) );
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Spaltenköpfe des Exports.
	 *
	 * @return array
	 */
	public static function csv_header() {
		return array(
			__( 'Kostenstelle', 'lindenweg16' ),
			__( 'Kampagne', 'lindenweg16' ),
			__( 'Kanal', 'lindenweg16' ),
			__( 'Art', 'lindenweg16' ),
			__( 'Status', 'lindenweg16' ),
			__( 'Datum', 'lindenweg16' ),
			__( 'Budget', 'lindenweg16' ),
			__( 'Kosten (Belege)', 'lindenweg16' ),
			__( 'Rabatt', 'lindenweg16' ),
			__( 'Einsatz', 'lindenweg16' ),
			__( 'Umsatz', 'lindenweg16' ),
			__( 'Ergebnis', 'lindenweg16' ),
			__( 'Umsatz je Euro Einsatz', 'lindenweg16' ),
			__( 'Bestellungen', 'lindenweg16' ),
			__( 'Budget-Ampel', 'lindenweg16' ),
		);
	}

	/**
	 * Zeilen des Exports: je Kampagne eine, am Ende die Summe.
	 *
	 * @param array $rows   Zeilen der Übersicht.
	 * @param array $totals Summen (totals()).
	 * @return array
	 */
	public static function csv_rows( array $rows, array $totals ) {
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				(string) $r['kst'],
				(string) $r['name'],
				(string) $r['kanal'],
				(string) $r['art'],
				(string) $r['status'],
				(string) $r['datum'],
				(float) $r['budget'],
				(float) $r['kosten'],
				(float) $r['rabatt'],
				(float) $r['einsatz'],
				(float) $r['umsatz'],
				(float) $r['ergebnis'],
				null === $r['roas'] ? null : (float) $r['roas'],
				(int) $r['bestellungen'],
				(string) $r['ampel_text'],
			);
		}
		$out[] = array(
			'',
			__( 'Summe', 'lindenweg16' ),
			'',
			'',
			'',
			'',
			(float) $totals['budget'],
			(float) $totals['kosten'],
			(float) $totals['rabatt'],
			(float) $totals['einsatz'],
			(float) $totals['umsatz'],
			(float) $totals['ergebnis'],
			null === $totals['roas'] ? null : (float) $totals['roas'],
			(int) $totals['bestellungen'],
			'',
		);
		return $out;
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
		$read  = $api->needs( array( 'finanzen.buchhaltung' ) );
		$write = $api->needs( array( 'finanzen.buchhaltung', 'verkauf.marketing' ) );

		register_rest_route( $ns, '/erp/buch/marketing', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'overview' ),
			'permission_callback' => $read,
		) );
		register_rest_route( $ns, '/erp/buch/marketing/export', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'export' ),
			'permission_callback' => $read,
		) );
		register_rest_route( $ns, '/erp/buch/marketing/zuordnen', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'assign' ),
			'permission_callback' => $write,
		) );
	}

	/**
	 * Antwort mit Fehlertext.
	 *
	 * @param string $message Satz für die Person am Bildschirm.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}

	/**
	 * Zeilen der Kampagnen im Zeitraum, mit den Werten aus KampagnenReport.
	 *
	 * @param string $von Beginn.
	 * @param string $bis Ende.
	 * @return array {rows: array, months: array}
	 */
	protected static function campaign_rows( $von, $bis ) {
		$rows  = array();
		$items = array();
		foreach ( KampagnenRepository::all( array( 'limit' => 500 ) ) as $k ) {
			if ( ! self::in_range( (array) $k, $von, $bis ) ) {
				continue;
			}
			$rep    = KampagnenReport::report( $k );
			$budget = $rep['budget'];

			$rows[] = array(
				'id'           => (int) $k->id,
				'kst'          => (string) $k->kostenstelle,
				'name'         => (string) $k->name,
				'kanal'        => (string) $k->kanal,
				'art'          => Verkauf::campaign_type_label( (string) $k->typ ),
				'status'       => (string) $k->status,
				'datum'        => self::reference_date( (array) $k ),
				'budget'       => (float) $budget['budget'],
				'kosten'       => (float) $rep['kosten'],
				'rabatt'       => (float) $rep['rabatt'],
				'einsatz'      => (float) $rep['einsatz'],
				'umsatz'       => (float) $rep['umsatz'],
				'ergebnis'     => (float) $rep['ergebnis'],
				'roas'         => $rep['roas'],
				'bestellungen' => (int) $rep['shop']['anzahl'],
				'ampel'        => (string) $budget['ampel'],
				'ampel_text'   => Marketing::ampel_label( (string) $budget['ampel'] ),
				'auslastung'   => null === $budget['auslastung'] ? null : (float) $budget['auslastung'],
			);

			foreach ( KampagnenRepository::belege( (int) $k->id ) as $b ) {
				$konto = (string) $b->konto;
				if ( '' === $konto ) {
					$konto = Kontenrahmen::konto_fuer_kategorie( $b->eur_kategorie );
				}
				if ( Marketing::konto_zaehlt_als_kosten( $konto ) ) {
					$items[] = array( 'datum' => (string) $b->datum, 'betrag' => (float) $b->anteil_betrag );
				}
			}
		}

		usort( $rows, function ( $a, $b ) {
			return strcmp( $b['datum'], $a['datum'] );
		} );

		return array( 'rows' => $rows, 'months' => self::summarize_months( $items ) );
	}

	/**
	 * Belege im Zeitraum, die nach Marketing aussehen und (noch) keiner oder nicht
	 * vollständig einer Kampagne zugeordnet sind, mit Vorschlag.
	 *
	 * @param string $von Beginn.
	 * @param string $bis Ende.
	 * @return array {rows: array, total: int}
	 */
	protected static function unassigned( $von, $bis ) {
		$belege = array();
		foreach ( BelegRepository::query( array( 'from' => $von, 'to' => $bis, 'per_page' => 1000 ) ) as $b ) {
			if ( '' !== Marketing::beleg_blocker( $b ) ) {
				continue;
			}
			$konto = (string) $b->konto;
			if ( '' === $konto ) {
				$konto = Kontenrahmen::konto_fuer_kategorie( $b->eur_kategorie );
			}
			if ( ! self::looks_like_marketing( $konto, (string) $b->eur_kategorie ) ) {
				continue;
			}
			$belege[ (int) $b->id ] = array( 'b' => $b, 'konto' => $konto );
		}
		if ( ! $belege ) {
			return array( 'rows' => array(), 'total' => 0 );
		}

		$given     = KampagnenRepository::vergebene_anteile( array_keys( $belege ) );
		$kampagnen = array();
		$labels    = array();
		foreach ( KampagnenRepository::all( array( 'limit' => 500 ) ) as $k ) {
			$kampagnen[] = (array) $k;
			$labels[ (int) $k->id ] = $k->kostenstelle . ' – ' . $k->name;
		}

		$rows = array();
		foreach ( $belege as $id => $e ) {
			$b    = $e['b'];
			$free = Kampagnenrechnung::freier_anteil( isset( $given[ $id ] ) ? $given[ $id ] : 0 );
			if ( $free <= 0 ) {
				continue;
			}
			$sug = self::suggest_campaigns( (array) $b, $kampagnen );
			$rows[] = array(
				'id'         => $id,
				'number'     => $b->belegnummer ? (string) $b->belegnummer : '#' . $id,
				'date'       => (string) $b->datum,
				'vendor'     => (string) $b->haendler,
				'account'    => $e['konto'] ? Kontenrahmen::label( $e['konto'] ) : (string) $b->eur_kategorie,
				'amount'     => (float) $b->betrag,
				'free'       => (float) $free,
				'file'       => ! empty( $b->datei_pfad ) ? wp_nonce_url( admin_url( 'admin-post.php?action=lw16_beleg_datei&id=' . $id ), 'lw16_beleg_datei_' . $id ) : '',
				'suggestion' => $sug ? array(
					'id'     => $sug[0]['id'],
					'label'  => $labels[ $sug[0]['id'] ],
					'reason' => $sug[0]['reason'],
				) : null,
			);
		}

		return array( 'rows' => array_slice( $rows, 0, self::MAX_UNASSIGNED ), 'total' => count( $rows ) );
	}

	/**
	 * Übersicht: Kampagnen im Zeitraum, Summen nach Kanal und Monat, nicht zugeordnete Belege.
	 *
	 * @param WP_REST_Request $request Anfrage (von, bis).
	 * @return WP_REST_Response
	 */
	public static function overview( WP_REST_Request $request ) {
		$range = self::normalize_range( $request->get_param( 'von' ), $request->get_param( 'bis' ), gmdate( 'Y-m-d' ) );
		if ( '' !== $range['error'] ) {
			return self::fail( $range['error'] );
		}

		$data = self::campaign_rows( $range['von'], $range['bis'] );

		// Auswahl für „andere Kampagne“: alle nicht abgeschlossenen, unabhängig vom Zeitraum.
		$campaigns = array();
		foreach ( KampagnenRepository::options() as $cid => $label ) {
			$campaigns[] = array( 'id' => (int) $cid, 'label' => (string) $label );
		}

		return new WP_REST_Response( array(
			'campaigns'  => $campaigns,
			'von'        => $range['von'],
			'bis'        => $range['bis'],
			'rows'       => $data['rows'],
			'totals'     => self::totals( $data['rows'] ),
			'channels'   => self::summarize_channels( $data['rows'] ),
			'months'     => $data['months'],
			'unassigned' => self::unassigned( $range['von'], $range['bis'] ),
		) );
	}

	/**
	 * CSV-Export der Auswertung als Text für den Download im Browser.
	 *
	 * @param WP_REST_Request $request Anfrage (von, bis).
	 * @return WP_REST_Response
	 */
	public static function export( WP_REST_Request $request ) {
		$range = self::normalize_range( $request->get_param( 'von' ), $request->get_param( 'bis' ), gmdate( 'Y-m-d' ) );
		if ( '' !== $range['error'] ) {
			return self::fail( $range['error'] );
		}

		$data = self::campaign_rows( $range['von'], $range['bis'] );
		$csv  = self::csv( self::csv_header(), self::csv_rows( $data['rows'], self::totals( $data['rows'] ) ) );

		return new WP_REST_Response( array(
			'ok'       => true,
			'filename' => 'marketing-kosten-' . $range['von'] . '_' . $range['bis'] . '.csv',
			'csv'      => "\xEF\xBB\xBF" . $csv,
		) );
	}

	/**
	 * Beleg mit einem Klick einer Kampagne zuordnen (Anteil: der freie Rest, sonst der angegebene).
	 *
	 * @param WP_REST_Request $request Anfrage (beleg_id, kampagne_id, anteil optional).
	 * @return WP_REST_Response
	 */
	public static function assign( WP_REST_Request $request ) {
		$in = (array) $request->get_json_params();
		$k  = KampagnenRepository::get( isset( $in['kampagne_id'] ) ? (int) $in['kampagne_id'] : 0 );
		if ( ! $k ) {
			return self::fail( __( 'Bitte eine Kampagne wählen. Diese gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		return Marketing::assign_to( $k, isset( $in['beleg_id'] ) ? (int) $in['beleg_id'] : 0, isset( $in['anteil'] ) ? $in['anteil'] : null );
	}
}
