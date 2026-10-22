<?php
/**
 * Buchhaltung im ERP: Ausgangsrechnungen, Kasse, Journal, Dauerbuchungen.
 *
 * Schreibende Vorgänge laufen über dieselben Repositories wie die klassische
 * Ansicht (AccountingModule): Rechnungen stornieren über
 * InvoiceGenerator::stornieren (Rechnung bleibt stehen, Gegenbuchung im
 * Journal, Protokoll), Kasse über KassenbuchRepository (Insert-only,
 * Hash-Kette, Storno als Gegenbuchung), Dauerbuchungen über
 * DauerbuchungRepository (Beleg, Festschreiben, Journal). Gelöscht wird
 * nirgends. Hier stehen nur die Routen, die Eingabeprüfung und reine Regeln,
 * die sich ohne WordPress testen lassen (tests/ErpBuchBuchungenTest.php).
 *
 * Bewusst NICHT vorhanden, weil die klassische Ansicht es auch nicht kennt:
 * manuelle Journalbuchungen (das Journal entsteht nur aus Rechnungen,
 * Belegen und Gebühren) und ein manuelles Nachholen der Rechnungserzeugung
 * (die Rechnung entsteht automatisch beim Abschluss der Bestellung).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Accounting\AccountingModule;
use Lindenweg16\Modules\Accounting\BelegRepository;
use Lindenweg16\Modules\Accounting\BuchungRepository;
use Lindenweg16\Modules\Accounting\DauerbuchungRepository;
use Lindenweg16\Modules\Accounting\InvoiceGenerator;
use Lindenweg16\Modules\Accounting\KassenbuchRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen und Regeln für die Buchungs-Reiter der Buchhaltung.
 */
class BuchBuchungen {

	/**
	 * Einträge je Seite.
	 */
	const PER_PAGE = 50;

	/**
	 * Obergrenze für einen Betrag (Tippfehler abfangen).
	 */
	const MAX_BETRAG = 1000000;

	/**
	 * Kürzester Stornogrund.
	 */
	const MIN_GRUND = 5;

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns   = RestApi::NAMESPACE_V1;
		$buch = $api->needs( array( 'finanzen.buchhaltung' ) );

		self::route( $ns, '/erp/buch/rechnungen', 'GET', 'rechnungen', $buch );
		self::route( $ns, '/erp/buch/rechnungen/fehlend', 'GET', 'rechnungen_fehlend', $buch );
		self::route( $ns, '/erp/buch/rechnungen/storno', 'POST', 'rechnung_storno', $buch );
		self::route( $ns, '/erp/buch/rechnungen/(?P<id>\d+)', 'GET', 'rechnung_detail', $buch );

		self::route( $ns, '/erp/buch/kasse', 'GET', 'kasse', $buch );
		self::route( $ns, '/erp/buch/kasse/tagesabschluss', 'GET', 'kasse_tagesabschluss', $buch );
		self::route( $ns, '/erp/buch/kasse/eintrag', 'POST', 'kasse_eintrag', $buch );
		self::route( $ns, '/erp/buch/kasse/storno', 'POST', 'kasse_storno', $buch );

		self::route( $ns, '/erp/buch/journal', 'GET', 'journal', $buch );
		self::route( $ns, '/erp/buch/journal/export', 'GET', 'journal_export', $buch );

		self::route( $ns, '/erp/buch/dauer', 'GET', 'dauer', $buch );
		self::route( $ns, '/erp/buch/dauer/speichern', 'POST', 'dauer_speichern', $buch );
		self::route( $ns, '/erp/buch/dauer/deaktivieren', 'POST', 'dauer_deaktivieren', $buch );
		self::route( $ns, '/erp/buch/dauer/ausfuehren', 'POST', 'dauer_ausfuehren', $buch );
	}

	/**
	 * Route anmelden.
	 *
	 * @param string   $ns     Namensraum.
	 * @param string   $route  Pfad.
	 * @param string   $http   GET oder POST.
	 * @param string   $method Methode dieser Klasse.
	 * @param callable $perm   Rechteprüfung.
	 */
	protected static function route( $ns, $route, $http, $method, $perm ) {
		register_rest_route(
			$ns,
			$route,
			array(
				'methods'             => $http,
				'callback'            => array( __CLASS__, $method ),
				'permission_callback' => $perm,
			)
		);
	}

	/* ====================================================================
	 * Reine Regeln (ohne Datenbank, getestet in tests/ErpBuchBuchungenTest.php)
	 * ================================================================== */

	/**
	 * Gültiges Datum Y-m-d oder die Vorgabe.
	 *
	 * @param mixed  $value    Eingabe.
	 * @param string $fallback Vorgabe.
	 * @return string
	 */
	public static function clean_date( $value, $fallback = '' ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return $fallback;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : $fallback;
	}

	/**
	 * Zahl aus Eingabe (auch mit Komma), sonst null.
	 *
	 * @param mixed $value Eingabe.
	 * @return float|null
	 */
	public static function clean_amount( $value ) {
		if ( is_string( $value ) ) {
			$value = str_replace( ',', '.', trim( $value ) );
		}

		if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) ) {
			return null;
		}

		return round( (float) $value, 2 );
	}

	/**
	 * Betrag in ganzen Cent (vermeidet Rundungsfehler beim Summieren).
	 *
	 * @param mixed $value Betrag.
	 * @return int
	 */
	public static function cents( $value ) {
		return (int) round( (float) $value * 100 );
	}

	/**
	 * Suchtext für LIKE: Platzhalter entschärfen, Länge begrenzen.
	 *
	 * @param mixed $value Eingabe.
	 * @return string
	 */
	public static function like_text( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 80 ) : substr( $value, 0, 80 );

		return addcslashes( $value, '_%\\' );
	}

	/**
	 * Filter einer Liste bereinigen. Leere Datumsangaben bleiben leer
	 * (= keine Grenze).
	 *
	 * @param array $in Rohe Eingabe (von, bis, status, typ, kategorie, q, seite).
	 * @return array
	 */
	public static function clean_filter( array $in ) {
		$status = isset( $in['status'] ) && is_string( $in['status'] ) ? $in['status'] : '';
		$typ    = isset( $in['typ'] ) && is_string( $in['typ'] ) ? $in['typ'] : '';
		$kat    = isset( $in['kategorie'] ) && is_string( $in['kategorie'] ) ? trim( $in['kategorie'] ) : '';
		$seite  = isset( $in['seite'] ) ? (int) $in['seite'] : 1;

		return array(
			'von'       => self::clean_date( isset( $in['von'] ) ? $in['von'] : '', '' ),
			'bis'       => self::clean_date( isset( $in['bis'] ) ? $in['bis'] : '', '' ),
			'status'    => in_array( $status, array( 'aktiv', 'storniert' ), true ) ? $status : '',
			'typ'       => in_array( $typ, array( 'einnahme', 'ausgabe', 'storno' ), true ) ? $typ : '',
			'kategorie' => function_exists( 'mb_substr' ) ? mb_substr( $kat, 0, 120 ) : substr( $kat, 0, 120 ),
			'q'         => self::like_text( isset( $in['q'] ) ? $in['q'] : '' ),
			'seite'     => max( 1, $seite ),
		);
	}

	/**
	 * Hinweis, wenn der Zeitraum falsch herum liegt, sonst leer.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return string
	 */
	public static function range_error( $von, $bis ) {
		if ( '' !== $von && '' !== $bis && $von > $bis ) {
			return __( 'Das Startdatum liegt nach dem Enddatum. Bitte den Zeitraum richtig herum wählen.', 'lindenweg16' );
		}

		return '';
	}

	/**
	 * WHERE-Teil mit Platzhaltern für die Rechnungsliste.
	 *
	 * @param array $f Bereinigter Filter.
	 * @return array array( sql, params ).
	 */
	public static function rechnungen_where( array $f ) {
		$sql    = '1=1';
		$params = array();

		if ( '' !== $f['von'] ) {
			$sql     .= ' AND erstellt_am >= %s';
			$params[] = $f['von'] . ' 00:00:00';
		}
		if ( '' !== $f['bis'] ) {
			$sql     .= ' AND erstellt_am <= %s';
			$params[] = $f['bis'] . ' 23:59:59';
		}
		if ( 'aktiv' === $f['status'] ) {
			$sql .= ' AND storniert = 0';
		} elseif ( 'storniert' === $f['status'] ) {
			$sql .= ' AND storniert = 1';
		}
		if ( '' !== $f['q'] ) {
			$sql     .= ' AND ( rechnungsnummer LIKE %s OR CAST(order_id AS CHAR) LIKE %s )';
			$params[] = '%' . $f['q'] . '%';
			$params[] = '%' . $f['q'] . '%';
		}

		return array( $sql, $params );
	}

	/**
	 * WHERE-Teil mit Platzhaltern für das Journal.
	 *
	 * @param array $f Bereinigter Filter.
	 * @return array array( sql, params ).
	 */
	public static function journal_where( array $f ) {
		$sql    = '1=1';
		$params = array();

		if ( '' !== $f['von'] ) {
			$sql     .= ' AND buchungsdatum >= %s';
			$params[] = $f['von'];
		}
		if ( '' !== $f['bis'] ) {
			$sql     .= ' AND buchungsdatum <= %s';
			$params[] = $f['bis'];
		}
		if ( '' !== $f['typ'] ) {
			$sql     .= ' AND typ = %s';
			$params[] = $f['typ'];
		}
		if ( '' !== $f['kategorie'] ) {
			$sql     .= ' AND kategorie = %s';
			$params[] = $f['kategorie'];
		}
		if ( 'aktiv' === $f['status'] ) {
			$sql .= " AND storniert = 0 AND typ <> 'storno'";
		} elseif ( 'storniert' === $f['status'] ) {
			$sql .= ' AND storniert = 1';
		}
		if ( '' !== $f['q'] ) {
			$sql     .= ' AND ( buchungsnummer LIKE %s OR beschreibung LIKE %s OR kategorie LIKE %s )';
			$params[] = '%' . $f['q'] . '%';
			$params[] = '%' . $f['q'] . '%';
			$params[] = '%' . $f['q'] . '%';
		}

		return array( $sql, $params );
	}

	/**
	 * WHERE-Teil für das Kassenbuch (nur Zeitraum, wie das Repository).
	 *
	 * @param array $f Bereinigter Filter.
	 * @return array array( sql, params ).
	 */
	public static function kasse_where( array $f ) {
		$sql    = '1=1';
		$params = array();

		if ( '' !== $f['von'] ) {
			$sql     .= ' AND datum >= %s';
			$params[] = $f['von'];
		}
		if ( '' !== $f['bis'] ) {
			$sql     .= ' AND datum <= %s';
			$params[] = $f['bis'];
		}

		return array( $sql, $params );
	}

	/**
	 * Summen des Journals aus Gruppenzeilen (typ, storniert, anzahl, summe).
	 *
	 * Zählt nur gültige Buchungen: Originale, die storniert wurden, und die
	 * Gegenbuchungen (Typ „storno“) heben sich auf und stehen getrennt.
	 *
	 * @param array $groups Zeilen.
	 * @return array einnahmen, ausgaben, saldo, storniert, gegenbuchungen, anzahl (Euro bzw. Stück).
	 */
	public static function sum_journal( array $groups ) {
		$ein = 0;
		$aus = 0;
		$sto = 0;
		$geg = 0;
		$n   = 0;

		foreach ( $groups as $g ) {
			$g     = (array) $g;
			$typ   = isset( $g['typ'] ) ? (string) $g['typ'] : '';
			$cent  = self::cents( isset( $g['summe'] ) ? $g['summe'] : 0 );
			$count = isset( $g['anzahl'] ) ? (int) $g['anzahl'] : 0;
			$n    += $count;

			if ( 'storno' === $typ ) {
				$geg += $cent;
			} elseif ( ! empty( $g['storniert'] ) ) {
				$sto += $cent;
			} elseif ( 'einnahme' === $typ ) {
				$ein += $cent;
			} elseif ( 'ausgabe' === $typ ) {
				$aus += $cent;
			}
		}

		return array(
			'einnahmen'      => (float) ( $ein / 100 ),
			'ausgaben'       => (float) ( $aus / 100 ),
			'saldo'          => (float) ( ( $ein - $aus ) / 100 ),
			'storniert'      => (float) ( $sto / 100 ),
			'gegenbuchungen' => (float) ( $geg / 100 ),
			'anzahl'         => $n,
		);
	}

	/**
	 * Pflicht-Grund eines Stornos prüfen.
	 *
	 * @param mixed $grund Eingabe.
	 * @return string Leer = in Ordnung, sonst der Hinweis.
	 */
	public static function storno_error( $grund ) {
		$grund = self::clean_grund( $grund );
		$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $grund ) : strlen( $grund );

		if ( $len < self::MIN_GRUND ) {
			return __( 'Bitte nenne einen Grund für das Storno (mindestens ein paar Worte). Er wird im Protokoll festgehalten.', 'lindenweg16' );
		}

		return '';
	}

	/**
	 * Stornogrund bereinigen (Zeilenumbrüche raus, Länge begrenzen).
	 *
	 * @param mixed $grund Eingabe.
	 * @return string
	 */
	public static function clean_grund( $grund ) {
		$grund = is_string( $grund ) ? $grund : '';
		$grund = trim( (string) preg_replace( '/\s+/u', ' ', $grund ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $grund, 0, 200 ) : substr( $grund, 0, 200 );
	}

	/**
	 * Neuen Kasseneintrag prüfen.
	 *
	 * Die Repository-Klasse prüft selbst nichts. Hier gilt: gültiges Datum,
	 * Text, genau ein Betrag über null (Einnahme ODER Ausgabe). Ein negativer
	 * Kassenbestand ist kein Fehler, aber nur nach ausdrücklicher Bestätigung.
	 *
	 * @param array $in      datum, beschreibung, art, betrag, bestaetigt.
	 * @param float $saldo   Aktueller Kassenstand.
	 * @return array ok, message, confirm (bool), data (einnahme, ausgabe, datum, beschreibung), saldo_neu.
	 */
	public static function kasse_check( array $in, $saldo ) {
		$datum  = self::clean_date( isset( $in['datum'] ) ? $in['datum'] : '', '' );
		$text   = self::clean_grund( isset( $in['beschreibung'] ) ? $in['beschreibung'] : '' );
		$art    = isset( $in['art'] ) && is_string( $in['art'] ) ? $in['art'] : '';
		$betrag = self::clean_amount( isset( $in['betrag'] ) ? $in['betrag'] : null );
		$fail   = static function ( $message, $confirm = false, $neu = null ) {
			return array(
				'ok'        => false,
				'message'   => $message,
				'confirm'   => $confirm,
				'data'      => array(),
				'saldo_neu' => $neu,
			);
		};

		if ( '' === $datum ) {
			return $fail( __( 'Bitte ein gültiges Datum wählen.', 'lindenweg16' ) );
		}
		if ( '' === $text ) {
			return $fail( __( 'Bitte beschreibe kurz, wofür das Geld ein- oder ausgeht.', 'lindenweg16' ) );
		}
		if ( ! in_array( $art, array( 'einnahme', 'ausgabe' ), true ) ) {
			return $fail( __( 'Bitte wähle, ob es eine Einnahme oder eine Ausgabe ist.', 'lindenweg16' ) );
		}
		if ( null === $betrag || $betrag <= 0 ) {
			return $fail( __( 'Bitte einen Betrag größer als 0 eingeben.', 'lindenweg16' ) );
		}
		if ( $betrag > self::MAX_BETRAG ) {
			return $fail( __( 'Der Betrag ist ungewöhnlich hoch. Bitte prüfe die Eingabe auf einen Zahlendreher.', 'lindenweg16' ) );
		}

		$neu = (float) ( ( self::cents( $saldo ) + ( 'einnahme' === $art ? 1 : -1 ) * self::cents( $betrag ) ) / 100 );
		$neg = self::negative_cash( $neu );

		if ( '' !== $neg && empty( $in['bestaetigt'] ) ) {
			return $fail( $neg, true, $neu );
		}

		return array(
			'ok'        => true,
			'message'   => '',
			'confirm'   => false,
			'data'      => array(
				'datum'        => $datum,
				'beschreibung' => $text,
				'einnahme'     => 'einnahme' === $art ? $betrag : 0,
				'ausgabe'      => 'ausgabe' === $art ? $betrag : 0,
			),
			'saldo_neu' => $neu,
		);
	}

	/**
	 * Hinweis bei negativem Kassenstand, sonst leer.
	 *
	 * @param float $saldo Kassenstand nach der Buchung.
	 * @return string
	 */
	public static function negative_cash( $saldo ) {
		if ( self::cents( $saldo ) >= 0 ) {
			return '';
		}

		return sprintf(
			/* translators: %s: Kassenstand mit Euro-Zeichen. */
			__( 'Danach wäre der Kassenstand negativ (%s). Das kann es in einer echten Kasse nicht geben – wahrscheinlich fehlt noch eine Einnahme oder das Datum stimmt nicht. Trotzdem buchen?', 'lindenweg16' ),
			number_format( (float) $saldo, 2, ',', '.' ) . ' €'
		);
	}

	/**
	 * Darf ein Kasseneintrag storniert werden?
	 *
	 * @param object|null $row Zeile aus lw16_kassenbuch.
	 * @return string Leer = ja, sonst der Hinweis.
	 */
	public static function kasse_storno_block( $row ) {
		if ( ! $row ) {
			return __( 'Diesen Kasseneintrag gibt es nicht.', 'lindenweg16' );
		}
		if ( ! empty( $row->storniert ) && 1 === (int) $row->storniert ) {
			return __( 'Dieser Eintrag ist schon storniert.', 'lindenweg16' );
		}
		if ( ! empty( $row->storno_von_id ) ) {
			return __( 'Das ist selbst eine Stornobuchung. Wurde sie versehentlich gebucht, erfasse den Vorgang einfach neu als normalen Eintrag.', 'lindenweg16' );
		}

		return '';
	}

	/**
	 * Kassenstand nach der Gegenbuchung eines Eintrags.
	 *
	 * @param float $saldo     Aktueller Stand.
	 * @param float $einnahme  Einnahme des Originals.
	 * @param float $ausgabe   Ausgabe des Originals.
	 * @return float
	 */
	public static function saldo_after_storno( $saldo, $einnahme, $ausgabe ) {
		return (float) ( ( self::cents( $saldo ) - self::cents( $einnahme ) + self::cents( $ausgabe ) ) / 100 );
	}

	/**
	 * Ist eine Dauerbuchung heute fällig (so wie execute_due es entscheidet)?
	 *
	 * @param bool   $aktiv   Aktiv?
	 * @param string $naechst Nächste Ausführung.
	 * @param string $ende    Enddatum oder leer.
	 * @param string $heute   Heute (Y-m-d).
	 * @return bool
	 */
	public static function dauer_faellig( $aktiv, $naechst, $ende, $heute ) {
		if ( ! $aktiv || '' === (string) $naechst || (string) $naechst > $heute ) {
			return false;
		}

		return '' === (string) $ende || (string) $ende >= $heute;
	}

	/**
	 * Vorschau eines Laufs: was würde gebucht?
	 *
	 * Bildet execute_due nach: Beleg zum heutigen Datum, ein Schritt
	 * weiter bei der nächsten Ausführung, Abschalten wenn das Enddatum
	 * überschritten würde. Je Lauf wird pro Dauerbuchung nur EINMAL gebucht,
	 * auch wenn mehrere Termine offen sind; der Rest folgt im nächsten Lauf.
	 *
	 * @param array  $row   bezeichnung, betrag, kategorie, intervall, naechste_ausfuehrung, ende_datum.
	 * @param string $heute Heute.
	 * @return array beleg_datum, betrag, naechste, endet, rueckstand.
	 */
	public static function dauer_vorschau( array $row, $heute ) {
		$naechst = (string) $row['naechste_ausfuehrung'];
		$ende    = isset( $row['ende_datum'] ) ? (string) $row['ende_datum'] : '';
		$next    = self::next_date( $naechst, (string) $row['intervall'] );
		$rest    = 0;
		$cursor  = $next;

		while ( $cursor <= $heute && $rest < 60 ) {
			++$rest;
			$cursor = self::next_date( $cursor, (string) $row['intervall'] );
		}

		return array(
			'beleg_datum' => $heute,
			'betrag'      => abs( (float) $row['betrag'] ),
			'naechste'    => $next,
			'endet'       => '' !== $ende && $next > $ende,
			'rueckstand'  => $rest,
		);
	}

	/**
	 * Nächstes Datum je Intervall (wie DauerbuchungRepository::calculate_next).
	 *
	 * @param string $datum    Datum.
	 * @param string $intervall Intervall.
	 * @return string
	 */
	public static function next_date( $datum, $intervall ) {
		$dt = new \DateTime( $datum );

		switch ( $intervall ) {
			case 'woechentlich':
				$dt->modify( '+1 week' );
				break;
			case 'quartalsweise':
				$dt->modify( '+3 months' );
				break;
			case 'halbjaehrlich':
				$dt->modify( '+6 months' );
				break;
			case 'jaehrlich':
				$dt->modify( '+1 year' );
				break;
			default:
				$dt->modify( '+1 month' );
		}

		return $dt->format( 'Y-m-d' );
	}

	/**
	 * Dauerbuchung prüfen (Anlegen und Ändern).
	 *
	 * @param array $in         bezeichnung, betrag, kategorie, intervall, start_datum, ende_datum.
	 * @param array $kategorien Erlaubte Kategorien.
	 * @param array $intervalle Erlaubte Intervall-Schlüssel.
	 * @param bool  $neu        Neu anlegen (Startdatum nötig)?
	 * @return array ok, message, data.
	 */
	public static function dauer_check( array $in, array $kategorien, array $intervalle, $neu ) {
		$name   = self::clean_grund( isset( $in['bezeichnung'] ) ? $in['bezeichnung'] : '' );
		$betrag = self::clean_amount( isset( $in['betrag'] ) ? $in['betrag'] : null );
		$kat    = isset( $in['kategorie'] ) && is_string( $in['kategorie'] ) ? trim( $in['kategorie'] ) : '';
		$int    = isset( $in['intervall'] ) && is_string( $in['intervall'] ) ? $in['intervall'] : '';
		$start  = self::clean_date( isset( $in['start_datum'] ) ? $in['start_datum'] : '', '' );
		$ende   = self::clean_date( isset( $in['ende_datum'] ) ? $in['ende_datum'] : '', '' );
		$has_e  = isset( $in['ende_datum'] ) && is_string( $in['ende_datum'] ) && '' !== trim( $in['ende_datum'] );
		$fail   = static function ( $m ) {
			return array( 'ok' => false, 'message' => $m, 'data' => array() );
		};

		if ( '' === $name ) {
			return $fail( __( 'Bitte gib der Dauerbuchung eine Bezeichnung, zum Beispiel „Büromiete“.', 'lindenweg16' ) );
		}
		if ( null === $betrag || $betrag <= 0 || $betrag > self::MAX_BETRAG ) {
			return $fail( __( 'Bitte einen Betrag größer als 0 eingeben (Cent-Beträge mit Komma).', 'lindenweg16' ) );
		}
		if ( '' === $kat || ! in_array( $kat, $kategorien, true ) ) {
			return $fail( __( 'Bitte wähle eine Kostenart aus der Liste.', 'lindenweg16' ) );
		}
		if ( ! in_array( $int, $intervalle, true ) ) {
			return $fail( __( 'Bitte wähle, wie oft gebucht werden soll.', 'lindenweg16' ) );
		}
		if ( $neu && '' === $start ) {
			return $fail( __( 'Bitte ein gültiges Startdatum wählen.', 'lindenweg16' ) );
		}
		if ( $has_e && '' === $ende ) {
			return $fail( __( 'Das Enddatum ist ungültig. Lass es leer, wenn die Buchung unbefristet laufen soll.', 'lindenweg16' ) );
		}
		if ( $neu && '' !== $ende && $ende < $start ) {
			return $fail( __( 'Das Enddatum liegt vor dem Start. Bitte richtig herum wählen.', 'lindenweg16' ) );
		}

		return array(
			'ok'      => true,
			'message' => '',
			'data'    => array(
				'bezeichnung' => $name,
				'betrag'      => $betrag,
				'kategorie'   => $kat,
				'intervall'   => $int,
				'start_datum' => $start,
				'ende_datum'  => '' === $ende ? null : $ende,
			),
		);
	}

	/**
	 * CSV-Zeilen des Journals (Kopf plus Daten), Beträge mit Komma.
	 *
	 * @param array $rows Zeilen aus lw16_buchungen.
	 * @return array
	 */
	public static function journal_csv_rows( array $rows ) {
		$out = array( array( 'Nummer', 'Datum', 'Typ', 'Betrag', 'Kategorie', 'Beschreibung', 'Status' ) );

		foreach ( $rows as $r ) {
			$r     = (array) $r;
			$out[] = array(
				self::csv_safe( isset( $r['buchungsnummer'] ) ? $r['buchungsnummer'] : '' ),
				isset( $r['buchungsdatum'] ) ? (string) $r['buchungsdatum'] : '',
				isset( $r['typ'] ) ? (string) $r['typ'] : '',
				number_format( (float) ( isset( $r['betrag'] ) ? $r['betrag'] : 0 ), 2, ',', '' ),
				self::csv_safe( isset( $r['kategorie'] ) ? $r['kategorie'] : '' ),
				self::csv_safe( isset( $r['beschreibung'] ) ? $r['beschreibung'] : '' ),
				! empty( $r['storniert'] ) ? 'storniert' : 'aktiv',
			);
		}

		return $out;
	}

	/**
	 * Zelle gegen Formel-Einschleusung in Tabellenprogrammen schützen.
	 *
	 * @param mixed $value Text.
	 * @return string
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;

		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/* ====================================================================
	 * Helfer
	 * ================================================================== */

	/**
	 * Fehlerantwort mit lesbarem Text.
	 *
	 * @param string $message Text.
	 * @param int    $status  HTTP-Status.
	 * @param array  $extra   Weitere Felder.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422, array $extra = array() ) {
		return new WP_REST_Response( array_merge( array( 'ok' => false, 'message' => $message ), $extra ), $status );
	}

	/**
	 * Parameter einer Anfrage (Query oder JSON) als Liste.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @param string[]        $keys    Schlüssel.
	 * @return array
	 */
	protected static function params( WP_REST_Request $request, array $keys ) {
		$out = array();

		foreach ( $keys as $key ) {
			$out[ $key ] = $request->get_param( $key );
		}

		return $out;
	}

	/**
	 * Bereinigter Filter aus der Anfrage.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array
	 */
	protected static function filter_of( WP_REST_Request $request ) {
		return self::clean_filter( self::params( $request, array( 'von', 'bis', 'status', 'typ', 'kategorie', 'q', 'seite' ) ) );
	}

	/**
	 * Zahl für JSON.
	 *
	 * @param mixed $v Wert.
	 * @return float
	 */
	protected static function f( $v ) {
		return (float) $v;
	}

	/**
	 * Fehlermeldung zum Zeitraum oder null.
	 *
	 * @param array $f Filter.
	 * @return WP_REST_Response|null
	 */
	protected static function range_fail( array $f ) {
		$error = self::range_error( $f['von'], $f['bis'] );

		return '' !== $error ? self::fail( $error ) : null;
	}

	/**
	 * Seitenangaben der Antwort.
	 *
	 * @param int $seite Seite.
	 * @param int $total Einträge.
	 * @return array
	 */
	protected static function paging( $seite, $total ) {
		return array(
			'seite'  => (int) $seite,
			'seiten' => Zahlen::page_count( $total, self::PER_PAGE ),
			'gesamt' => (int) $total,
		);
	}

	/**
	 * Kontrollierte Tabellenabfrage mit vorbereiteten Platzhaltern.
	 *
	 * @param string $sql    SQL mit Platzhaltern.
	 * @param array  $params Werte.
	 * @return string
	 */
	protected static function prepared( $sql, array $params ) {
		global $wpdb;

		return $params ? $wpdb->prepare( $sql, $params ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ====================================================================
	 * Rechnungen
	 * ================================================================== */

	/**
	 * Rechnungsliste mit Suche, Zeitraum und Status.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function rechnungen( WP_REST_Request $request ) {
		global $wpdb;

		$f = self::filter_of( $request );
		$e = self::range_fail( $f );

		if ( $e ) {
			return $e;
		}

		$t              = InvoiceGenerator::table();
		list( $w, $p )  = self::rechnungen_where( $f );
		$total          = (int) $wpdb->get_var( self::prepared( "SELECT COUNT(*) FROM {$t} WHERE {$w}", $p ) ); // phpcs:ignore
		$sums           = $wpdb->get_row( self::prepared( "SELECT COALESCE(SUM(CASE WHEN storniert = 0 THEN betrag END),0) AS aktiv, COALESCE(SUM(CASE WHEN storniert = 1 THEN betrag END),0) AS storno FROM {$t} WHERE {$w}", $p ) ); // phpcs:ignore
		$rows           = $wpdb->get_results( self::prepared( "SELECT * FROM {$t} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $p, array( self::PER_PAGE, ( $f['seite'] - 1 ) * self::PER_PAGE ) ) ) ); // phpcs:ignore
		$out            = array();

		foreach ( (array) $rows as $r ) {
			$out[] = self::invoice_row( $r );
		}

		return new WP_REST_Response(
			array_merge(
				array(
					'rows'   => $out,
					'filter' => $f,
					'summe'  => array(
						'aktiv'     => $sums ? self::f( $sums->aktiv ) : 0.0,
						'storniert' => $sums ? self::f( $sums->storno ) : 0.0,
					),
				),
				self::paging( $f['seite'], $total )
			)
		);
	}

	/**
	 * Eine Rechnungszeile für die Liste.
	 *
	 * @param object $r Zeile.
	 * @return array
	 */
	protected static function invoice_row( $r ) {
		return array(
			'id'          => (int) $r->id,
			'nummer'      => (string) $r->rechnungsnummer,
			'bestellung'  => (string) $r->order_id,
			'betrag'      => (float) $r->betrag,
			'erstellt'    => (string) $r->erstellt_am,
			'storniert'   => 1 === (int) $r->storniert,
			'storno_grund' => isset( $r->storno_grund ) ? (string) $r->storno_grund : '',
			'url'         => ! empty( $r->datei_pfad ) ? wp_nonce_url( admin_url( 'admin-post.php?action=lw16_rechnung&id=' . (int) $r->id ), 'lw16_rechnung_' . (int) $r->id ) : '',
		);
	}

	/**
	 * Eine Rechnung mit Bestellung und Journal-Buchung.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function rechnung_detail( WP_REST_Request $request ) {
		$r = InvoiceGenerator::get( (int) $request->get_param( 'id' ) );

		if ( ! $r ) {
			return self::fail( __( 'Diese Rechnung gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$row     = self::invoice_row( $r );
		$kunde   = '';
		$status  = '';
		$gebucht = array();

		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $r->order_id );
			if ( $order ) {
				$kunde  = trim( (string) $order->get_formatted_billing_full_name() );
				$status = (string) wc_get_order_status_name( $order->get_status() );
			}
		}

		global $wpdb;
		$bt = BuchungRepository::table();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT buchungsnummer, typ, betrag, buchungsdatum, storniert FROM {$bt} WHERE referenz_typ IN ('rechnung','storno') AND ( referenz_id = %d OR storno_von_id IN ( SELECT id FROM {$bt} WHERE referenz_typ = 'rechnung' AND referenz_id = %d ) ) ORDER BY id ASC", (int) $r->id, (int) $r->id ) ) as $b ) { // phpcs:ignore
			$gebucht[] = array(
				'nummer'    => (string) $b->buchungsnummer,
				'typ'       => (string) $b->typ,
				'betrag'    => (float) $b->betrag,
				'datum'     => (string) $b->buchungsdatum,
				'storniert' => 1 === (int) $b->storniert,
			);
		}

		return new WP_REST_Response(
			array(
				'rechnung'    => $row,
				'kunde'       => $kunde,
				'bestellung'  => $status,
				'buchungen'   => $gebucht,
			)
		);
	}

	/**
	 * Abgeschlossene Bestellungen ohne Rechnung (nur als Hinweis).
	 *
	 * @return WP_REST_Response
	 */
	public static function rechnungen_fehlend() {
		$out = array();

		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'status'  => array( 'completed' ),
					'limit'   => 100,
					'orderby' => 'date',
					'order'   => 'DESC',
				)
			);

			foreach ( (array) $orders as $o ) {
				if ( InvoiceGenerator::get_by_order( (int) $o->get_id() ) ) {
					continue;
				}
				$out[] = array(
					'bestellung' => (string) $o->get_order_number(),
					'datum'      => $o->get_date_created() ? $o->get_date_created()->format( 'Y-m-d' ) : '',
					'betrag'     => (float) $o->get_total(),
					'kunde'      => trim( (string) $o->get_formatted_billing_full_name() ),
				);
			}
		}

		return new WP_REST_Response( array( 'rows' => $out ) );
	}

	/**
	 * Rechnung stornieren: Pflicht-Grund, Gegenbuchung, Protokoll.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function rechnung_storno( WP_REST_Request $request ) {
		$id    = (int) $request->get_param( 'id' );
		$error = self::storno_error( $request->get_param( 'grund' ) );

		if ( '' !== $error ) {
			return self::fail( $error );
		}

		$r = InvoiceGenerator::get( $id );

		if ( ! $r ) {
			return self::fail( __( 'Diese Rechnung gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( 1 === (int) $r->storniert ) {
			return self::fail( __( 'Diese Rechnung ist schon storniert.', 'lindenweg16' ) );
		}

		if ( ! InvoiceGenerator::stornieren( $id, self::clean_grund( $request->get_param( 'grund' ) ) ) ) {
			return self::fail( __( 'Die Rechnung konnte nicht storniert werden. Bitte lade die Seite neu und versuche es noch einmal.', 'lindenweg16' ) );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: %s: Rechnungsnummer. */
					__( 'Rechnung %s wurde storniert. Die Einnahme ist im Journal gegengebucht.', 'lindenweg16' ),
					$r->rechnungsnummer
				),
			)
		);
	}

	/* ====================================================================
	 * Kasse
	 * ================================================================== */

	/**
	 * Kassenbuch mit Saldo, Summen und Tagesabschluss.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function kasse( WP_REST_Request $request ) {
		global $wpdb;

		$f = self::filter_of( $request );
		$e = self::range_fail( $f );

		if ( $e ) {
			return $e;
		}

		$t             = KassenbuchRepository::table();
		list( $w, $p ) = self::kasse_where( $f );
		$total         = (int) $wpdb->get_var( self::prepared( "SELECT COUNT(*) FROM {$t} WHERE {$w}", $p ) ); // phpcs:ignore
		$sums          = $wpdb->get_row( self::prepared( "SELECT COALESCE(SUM(CASE WHEN storniert = 0 AND storno_von_id IS NULL THEN einnahme END),0) AS ein, COALESCE(SUM(CASE WHEN storniert = 0 AND storno_von_id IS NULL THEN ausgabe END),0) AS aus FROM {$t} WHERE {$w}", $p ) ); // phpcs:ignore
		$rows          = KassenbuchRepository::query(
			array(
				'from'     => $f['von'],
				'to'       => $f['bis'],
				'per_page' => self::PER_PAGE,
				'page'     => $f['seite'],
			)
		);
		$out           = array();

		foreach ( $rows as $r ) {
			$out[] = array(
				'id'           => (int) $r->id,
				'nummer'       => (string) $r->kassenbuchnummer,
				'datum'        => (string) $r->datum,
				'beschreibung' => (string) $r->beschreibung,
				'einnahme'     => (float) $r->einnahme,
				'ausgabe'      => (float) $r->ausgabe,
				'saldo'        => (float) $r->saldo,
				'storniert'    => 1 === (int) $r->storniert,
				'ist_storno'   => ! empty( $r->storno_von_id ),
			);
		}

		return new WP_REST_Response(
			array_merge(
				array(
					'rows'   => $out,
					'filter' => $f,
					'saldo'  => KassenbuchRepository::aktueller_saldo(),
					'heute'  => gmdate( 'Y-m-d' ),
					'summe'  => array(
						'einnahmen' => $sums ? self::f( $sums->ein ) : 0.0,
						'ausgaben'  => $sums ? self::f( $sums->aus ) : 0.0,
					),
				),
				self::paging( $f['seite'], $total )
			)
		);
	}

	/**
	 * Tagesabschluss eines Tages.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function kasse_tagesabschluss( WP_REST_Request $request ) {
		$datum = self::clean_date( $request->get_param( 'datum' ), gmdate( 'Y-m-d' ) );
		$t     = KassenbuchRepository::tagesabschluss( $datum );

		return new WP_REST_Response(
			array(
				'datum'     => $t['datum'],
				'anzahl'    => $t['anzahl'],
				'einnahmen' => $t['einnahmen'],
				'ausgaben'  => $t['ausgaben'],
				'saldo'     => $t['saldo'],
			)
		);
	}

	/**
	 * Kasseneintrag buchen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function kasse_eintrag( WP_REST_Request $request ) {
		$check = self::kasse_check(
			self::params( $request, array( 'datum', 'beschreibung', 'art', 'betrag', 'bestaetigt' ) ),
			KassenbuchRepository::aktueller_saldo()
		);

		if ( ! $check['ok'] ) {
			// Rückfrage ist kein Fehler: Status 200, die Oberfläche fragt nach und sendet erneut.
			return self::fail( $check['message'], $check['confirm'] ? 200 : 422, array( 'bestaetigen' => $check['confirm'] ) );
		}

		$id = KassenbuchRepository::create( $check['data'] );

		if ( ! $id ) {
			return self::fail( __( 'Der Eintrag konnte nicht gebucht werden. Bitte versuche es noch einmal.', 'lindenweg16' ), 500 );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Kassenbuch-Eintrag gebucht.', 'lindenweg16' ),
				'saldo'   => KassenbuchRepository::aktueller_saldo(),
			)
		);
	}

	/**
	 * Kasseneintrag stornieren (Gegenbuchung, Pflicht-Grund).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function kasse_storno( WP_REST_Request $request ) {
		global $wpdb;

		$id    = (int) $request->get_param( 'id' );
		$error = self::storno_error( $request->get_param( 'grund' ) );

		if ( '' !== $error ) {
			return self::fail( $error );
		}

		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . KassenbuchRepository::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$block = self::kasse_storno_block( $row );

		if ( '' !== $block ) {
			return self::fail( $block );
		}

		$neu = self::saldo_after_storno( KassenbuchRepository::aktueller_saldo(), $row->einnahme, $row->ausgabe );
		$neg = self::negative_cash( $neu );

		if ( '' !== $neg && ! $request->get_param( 'bestaetigt' ) ) {
			return self::fail( $neg, 200, array( 'bestaetigen' => true ) );
		}

		if ( ! KassenbuchRepository::create_storno( $id, self::clean_grund( $request->get_param( 'grund' ) ) ) ) {
			return self::fail( __( 'Der Eintrag konnte nicht storniert werden. Bitte lade die Seite neu und versuche es noch einmal.', 'lindenweg16' ) );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Kassenbuch-Eintrag storniert. Die Gegenbuchung steht im Kassenbuch.', 'lindenweg16' ),
				'saldo'   => KassenbuchRepository::aktueller_saldo(),
			)
		);
	}

	/* ====================================================================
	 * Journal
	 * ================================================================== */

	/**
	 * Journal mit Filtern und Summen über den ganzen Filter.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function journal( WP_REST_Request $request ) {
		global $wpdb;

		$f = self::filter_of( $request );
		$e = self::range_fail( $f );

		if ( $e ) {
			return $e;
		}

		$t             = BuchungRepository::table();
		list( $w, $p ) = self::journal_where( $f );
		$total         = (int) $wpdb->get_var( self::prepared( "SELECT COUNT(*) FROM {$t} WHERE {$w}", $p ) ); // phpcs:ignore
		$groups        = $wpdb->get_results( self::prepared( "SELECT typ, storniert, COUNT(*) AS anzahl, COALESCE(SUM(betrag),0) AS summe FROM {$t} WHERE {$w} GROUP BY typ, storniert", $p ), ARRAY_A ); // phpcs:ignore
		$rows          = $wpdb->get_results( self::prepared( "SELECT * FROM {$t} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $p, array( self::PER_PAGE, ( $f['seite'] - 1 ) * self::PER_PAGE ) ) ) ); // phpcs:ignore
		$kategorien    = $wpdb->get_col( "SELECT DISTINCT kategorie FROM {$t} WHERE kategorie <> '' ORDER BY kategorie ASC LIMIT 200" ); // phpcs:ignore
		$out           = array();

		foreach ( (array) $rows as $b ) {
			$out[] = array(
				'nummer'       => (string) $b->buchungsnummer,
				'datum'        => (string) $b->buchungsdatum,
				'typ'          => (string) $b->typ,
				'betrag'       => (float) $b->betrag,
				'kategorie'    => (string) $b->kategorie,
				'beschreibung' => (string) $b->beschreibung,
				'storniert'    => 1 === (int) $b->storniert,
			);
		}

		return new WP_REST_Response(
			array_merge(
				array(
					'rows'       => $out,
					'filter'     => $f,
					'summe'      => self::sum_journal( (array) $groups ),
					'kategorien' => array_values( array_map( 'strval', (array) $kategorien ) ),
				),
				self::paging( $f['seite'], $total )
			)
		);
	}

	/**
	 * Journal als CSV (alle Treffer des Filters, höchstens 20.000 Zeilen).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function journal_export( WP_REST_Request $request ) {
		global $wpdb;

		$f = self::filter_of( $request );
		$e = self::range_fail( $f );

		if ( $e ) {
			return $e;
		}

		$t             = BuchungRepository::table();
		list( $w, $p ) = self::journal_where( $f );
		$rows          = $wpdb->get_results( self::prepared( "SELECT * FROM {$t} WHERE {$w} ORDER BY id ASC LIMIT 20000", $p ), ARRAY_A ); // phpcs:ignore

		return new WP_REST_Response(
			array(
				'filename' => 'lw16-journal-' . gmdate( 'Y-m-d' ) . '.csv',
				'mime'     => 'text/csv;charset=utf-8',
				'text'     => Zahlen::csv( self::journal_csv_rows( (array) $rows ) ),
				'anzahl'   => count( (array) $rows ),
			)
		);
	}

	/* ====================================================================
	 * Dauerbuchungen
	 * ================================================================== */

	/**
	 * Erlaubte Kostenarten.
	 *
	 * @return string[]
	 */
	protected static function kategorien() {
		return AccountingModule::eur_kategorien();
	}

	/**
	 * Liste mit Fälligkeit, Vorschau und Protokoll der letzten Läufe.
	 *
	 * @return WP_REST_Response
	 */
	public static function dauer() {
		global $wpdb;

		$heute = gmdate( 'Y-m-d' );
		$intv  = DauerbuchungRepository::intervall_optionen();
		$rows  = array();
		$due   = 0;

		foreach ( (array) DauerbuchungRepository::get_all( false ) as $d ) {
			$aktiv   = 1 === (int) $d->aktiv;
			$ende    = $d->ende_datum ? (string) $d->ende_datum : '';
			$faellig = self::dauer_faellig( $aktiv, (string) $d->naechste_ausfuehrung, $ende, $heute );
			$row     = array(
				'id'          => (int) $d->id,
				'bezeichnung' => (string) $d->bezeichnung,
				'betrag'      => (float) $d->betrag,
				'kategorie'   => (string) $d->kategorie,
				'intervall'   => (string) $d->intervall,
				'intervall_text' => isset( $intv[ $d->intervall ] ) ? $intv[ $d->intervall ] : (string) $d->intervall,
				'start'       => (string) $d->start_datum,
				'ende'        => $ende,
				'naechste'    => (string) $d->naechste_ausfuehrung,
				'letzte'      => $d->letzte_ausfuehrung ? (string) $d->letzte_ausfuehrung : '',
				'aktiv'       => $aktiv,
				'faellig'     => $faellig,
				'vorschau'    => null,
			);

			if ( $faellig ) {
				++$due;
				$row['vorschau'] = self::dauer_vorschau(
					array(
						'betrag'               => $d->betrag,
						'intervall'            => $d->intervall,
						'naechste_ausfuehrung' => $d->naechste_ausfuehrung,
						'ende_datum'           => $ende,
					),
					$heute
				);
			}

			$rows[] = $row;
		}

		$log = array();
		$at  = AuditLog::table();

		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$at} WHERE aktion IN ('dauerbuchungen_ausgefuehrt','dauerbuchung_erstellt','dauerbuchung_geaendert','dauerbuchung_deaktiviert') ORDER BY id DESC LIMIT 20" ) as $l ) { // phpcs:ignore
			$user  = $l->user_id ? get_userdata( (int) $l->user_id ) : false;
			$log[] = array(
				'zeit'    => (string) $l->erstellt_am,
				'person'  => $user ? (string) $user->display_name : __( 'Automatik', 'lindenweg16' ),
				'aktion'  => self::log_label( (string) $l->aktion ),
				'details' => self::log_details( (string) $l->details ),
			);
		}

		return new WP_REST_Response(
			array(
				'rows'       => $rows,
				'faellig'    => $due,
				'heute'      => $heute,
				'kategorien' => self::kategorien(),
				'intervalle' => $intv,
				'protokoll'  => $log,
			)
		);
	}

	/**
	 * Klartext zu einer Protokoll-Aktion.
	 *
	 * @param string $aktion Schlüssel.
	 * @return string
	 */
	public static function log_label( $aktion ) {
		$map = array(
			'dauerbuchungen_ausgefuehrt' => __( 'Lauf ausgeführt', 'lindenweg16' ),
			'dauerbuchung_erstellt'      => __( 'Angelegt', 'lindenweg16' ),
			'dauerbuchung_geaendert'     => __( 'Geändert', 'lindenweg16' ),
			'dauerbuchung_deaktiviert'   => __( 'Deaktiviert', 'lindenweg16' ),
		);

		return isset( $map[ $aktion ] ) ? $map[ $aktion ] : $aktion;
	}

	/**
	 * Details eines Protokolleintrags als kurzer Text.
	 *
	 * @param string $json JSON.
	 * @return string
	 */
	public static function log_details( $json ) {
		$d = json_decode( $json, true );

		if ( ! is_array( $d ) ) {
			return '';
		}

		$parts = array();

		foreach ( $d as $k => $v ) {
			if ( is_scalar( $v ) && '' !== (string) $v ) {
				$parts[] = $k . ': ' . $v;
			}
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Dauerbuchung anlegen oder ändern.
	 *
	 * Ändern gibt es in der klassischen Ansicht nicht. Hier darf nur eine
	 * aktive Vorlage geändert werden (Bezeichnung, Betrag, Kostenart,
	 * Intervall, Ende); schon gebuchte Belege bleiben unberührt, und jede
	 * Änderung steht mit altem und neuem Wert im Protokoll.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function dauer_speichern( WP_REST_Request $request ) {
		global $wpdb;

		$id   = (int) $request->get_param( 'id' );
		$cats = self::kategorien();
		$old  = $id ? DauerbuchungRepository::get( $id ) : null;

		if ( $id && ! $old ) {
			return self::fail( __( 'Diese Dauerbuchung gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( $old && 1 !== (int) $old->aktiv ) {
			return self::fail( __( 'Diese Dauerbuchung ist deaktiviert und kann nicht mehr geändert werden. Lege bei Bedarf eine neue an.', 'lindenweg16' ) );
		}
		if ( $old && ! in_array( (string) $old->kategorie, $cats, true ) ) {
			$cats[] = (string) $old->kategorie; // Bestehende Kostenart bleibt erlaubt.
		}

		$check = self::dauer_check(
			self::params( $request, array( 'bezeichnung', 'betrag', 'kategorie', 'intervall', 'start_datum', 'ende_datum' ) ),
			$cats,
			array_keys( DauerbuchungRepository::intervall_optionen() ),
			! $old
		);

		if ( ! $check['ok'] ) {
			return self::fail( $check['message'] );
		}

		$d = $check['data'];

		if ( ! $old ) {
			$new = DauerbuchungRepository::create(
				array(
					'bezeichnung' => $d['bezeichnung'],
					'betrag'      => $d['betrag'],
					'kategorie'   => $d['kategorie'],
					'typ'         => 'ausgabe',
					'intervall'   => $d['intervall'],
					'start_datum' => $d['start_datum'],
					'ende_datum'  => $d['ende_datum'],
				)
			);

			return $new
				? new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Dauerbuchung angelegt.', 'lindenweg16' ), 'id' => $new ) )
				: self::fail( __( 'Die Dauerbuchung konnte nicht angelegt werden. Bitte versuche es noch einmal.', 'lindenweg16' ), 500 );
		}

		$ende = $d['ende_datum'];

		if ( $ende && $ende < (string) $old->naechste_ausfuehrung ) {
			return self::fail( __( 'Das Enddatum liegt vor der nächsten Ausführung. Bitte ein späteres Datum wählen oder die Dauerbuchung deaktivieren.', 'lindenweg16' ) );
		}

		$wpdb->update(
			DauerbuchungRepository::table(),
			array(
				'bezeichnung' => $d['bezeichnung'],
				'betrag'      => $d['betrag'],
				'kategorie'   => $d['kategorie'],
				'intervall'   => $d['intervall'],
				'ende_datum'  => $ende,
			),
			array( 'id' => $id )
		);

		AuditLog::record(
			'dauerbuchung_geaendert',
			'dauerbuchung',
			$id,
			array(
				'bezeichnung_alt' => (string) $old->bezeichnung,
				'bezeichnung'     => $d['bezeichnung'],
				'betrag_alt'      => (float) $old->betrag,
				'betrag'          => $d['betrag'],
				'intervall_alt'   => (string) $old->intervall,
				'intervall'       => $d['intervall'],
			)
		);

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Dauerbuchung geändert. Schon gebuchte Belege bleiben unverändert.', 'lindenweg16' ), 'id' => $id ) );
	}

	/**
	 * Dauerbuchung deaktivieren (kein Löschen).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function dauer_deaktivieren( WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'id' );
		$row = DauerbuchungRepository::get( $id );

		if ( ! $row ) {
			return self::fail( __( 'Diese Dauerbuchung gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}
		if ( 1 !== (int) $row->aktiv ) {
			return self::fail( __( 'Diese Dauerbuchung ist schon deaktiviert.', 'lindenweg16' ) );
		}

		DauerbuchungRepository::deactivate( $id );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Dauerbuchung deaktiviert. Es wird nichts mehr gebucht; Gebuchtes bleibt stehen.', 'lindenweg16' ) ) );
	}

	/**
	 * Fällige Dauerbuchungen ausführen: eine (id) oder alle.
	 *
	 * Alle: DauerbuchungRepository::execute_due. Einzeln: dieselben Schritte
	 * für genau eine Vorlage (Beleg, Festschreiben mit Journalbuchung,
	 * nächstes Datum, ggf. Abschalten, Protokoll), weil das Repository keinen
	 * Einzelaufruf anbietet.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function dauer_ausfuehren( WP_REST_Request $request ) {
		global $wpdb;

		$id    = (int) $request->get_param( 'id' );
		$heute = gmdate( 'Y-m-d' );

		if ( ! $id ) {
			$n = DauerbuchungRepository::execute_due();

			return new WP_REST_Response(
				array(
					'ok'      => true,
					'anzahl'  => $n,
					'message' => 0 === $n
						? __( 'Es war nichts fällig.', 'lindenweg16' )
						: sprintf(
							/* translators: %d: Anzahl. */
							_n( '%d Dauerbuchung ausgeführt.', '%d Dauerbuchungen ausgeführt.', $n, 'lindenweg16' ),
							$n
						),
				)
			);
		}

		$db = DauerbuchungRepository::get( $id );

		if ( ! $db ) {
			return self::fail( __( 'Diese Dauerbuchung gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$ende = $db->ende_datum ? (string) $db->ende_datum : '';

		if ( ! self::dauer_faellig( 1 === (int) $db->aktiv, (string) $db->naechste_ausfuehrung, $ende, $heute ) ) {
			return self::fail( __( 'Diese Dauerbuchung ist heute nicht fällig. Sie kann nicht vorzeitig gebucht werden.', 'lindenweg16' ) );
		}

		$beleg_id = BelegRepository::create(
			array(
				'betrag'        => abs( (float) $db->betrag ),
				'datum'         => $heute,
				'haendler'      => $db->bezeichnung,
				'eur_kategorie' => $db->kategorie,
				'notiz'         => sprintf(
					/* translators: %d: Dauerbuchung ID */
					__( 'Automatisch aus Dauerbuchung #%d', 'lindenweg16' ),
					$db->id
				),
				'quelle'        => 'dauerbuchung',
			)
		);

		if ( ! $beleg_id ) {
			return self::fail( __( 'Der Beleg konnte nicht angelegt werden. Es wurde nichts gebucht.', 'lindenweg16' ), 500 );
		}

		BelegRepository::festschreiben( $beleg_id );

		$next   = DauerbuchungRepository::calculate_next( $db->naechste_ausfuehrung, $db->intervall );
		$update = array(
			'naechste_ausfuehrung' => $next,
			'letzte_ausfuehrung'   => $heute,
		);

		if ( $ende && $next > $ende ) {
			$update['aktiv'] = 0;
		}

		$wpdb->update( DauerbuchungRepository::table(), $update, array( 'id' => $id ) );

		AuditLog::record(
			'dauerbuchungen_ausgefuehrt',
			'dauerbuchung',
			$id,
			array(
				'anzahl'      => 1,
				'datum'       => $heute,
				'bezeichnung' => (string) $db->bezeichnung,
			)
		);

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'anzahl'  => 1,
				'message' => sprintf(
					/* translators: %s: Bezeichnung. */
					__( '„%s“ wurde gebucht und festgeschrieben.', 'lindenweg16' ),
					$db->bezeichnung
				),
			)
		);
	}
}
