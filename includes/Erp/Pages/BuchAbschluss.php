<?php
/**
 * ERP-Bereich Buchhaltung: Anlagen (AfA), EÜR mit Vorjahresvergleich,
 * GoBD-Prüfung, Exporte und die Jahresabschluss-Checkliste.
 *
 * Alle Schreibvorgänge laufen über dieselben Repositories wie die klassische
 * Ansicht (`AccountingModule::handle_save_anlage`, `handle_book_afa`,
 * `handle_ausscheiden_anlage`, `handle_execute_dauerbuchungen`,
 * `handle_fee_rescan`, `handle_gobd_check`): Belege entstehen nur über
 * `BelegRepository::create` und werden sofort festgeschrieben (Hash-Kette,
 * Belegnummernkreis, Buchung im Journal), jede Änderung steht im Protokoll
 * (`AuditLog`). Nichts wird gelöscht oder überschrieben.
 *
 * Gegenüber der klassischen Ansicht gelten zusätzlich diese Schutzregeln,
 * weil gebuchte Belege unveränderbar sind und ein Fehler nur per Storno
 * korrigiert werden könnte:
 * - AfA wird je Anlage und Jahr nur einmal gebucht (die klassische Ansicht
 *   bucht bei jedem Klick erneut).
 * - AfA nur für Jahre bis zum laufenden Jahr.
 * - Ausscheiden verlangt einen Grund und geschieht nur einmal.
 *
 * Reine Regeln (AfA-Vorschau, Ampel, Zeiträume, Vergleich) sind statische
 * Methoden ohne Datenbank und in tests/ErpBuchAbschlussTest.php geprüft.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Lock;
use Lindenweg16\Core\Settings;
use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Accounting\AccountingModule;
use Lindenweg16\Modules\Accounting\AnlagenRepository;
use Lindenweg16\Modules\Accounting\BelegRepository;
use Lindenweg16\Modules\Accounting\BuchungRepository;
use Lindenweg16\Modules\Accounting\DauerbuchungRepository;
use Lindenweg16\Modules\Accounting\EuerCalculator;
use Lindenweg16\Modules\Accounting\FeeExtractor;
use Lindenweg16\Modules\Accounting\GobdChecker;
use Lindenweg16\Modules\Accounting\KassenbuchRepository;
use Lindenweg16\Modules\Accounting\Kontenrahmen;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen erp/buchabschluss/….
 */
class BuchAbschluss {

	/**
	 * Wahl der Kategorie bei Anlagen (wie das Auswahlfeld der klassischen Ansicht).
	 */
	const KATEGORIEN = array( 'EDV/Hardware', 'Maschinen', 'Büroausstattung', 'Fahrzeuge', 'Werkzeuge', 'Sonstiges' );

	/**
	 * Höchstzahl Bestellungen, die die Gebührenprüfung je Lauf ansieht.
	 */
	const FEE_SCAN_LIMIT = 500;

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns     = RestApi::NAMESPACE_V1;
		$buch   = $api->needs( array( 'finanzen.buchhaltung' ) );
		$export = $api->needs( array( 'finanzen.buchhaltung', 'finanzen.export' ) );

		self::get( $ns, '/erp/buchabschluss/anlagen', 'anlagen', $buch );
		self::post( $ns, '/erp/buchabschluss/anlagen', 'anlage_speichern', $buch );
		self::get( $ns, '/erp/buchabschluss/afa-vorschau', 'afa_vorschau_route', $buch );
		self::post( $ns, '/erp/buchabschluss/afa-buchen', 'afa_buchen', $buch );
		self::post( $ns, '/erp/buchabschluss/anlagen-ausscheiden', 'anlage_ausscheiden', $buch );

		self::get( $ns, '/erp/buchabschluss/euer', 'euer', $buch );
		self::get( $ns, '/erp/buchabschluss/euer-buchungen', 'euer_buchungen', $buch );
		self::get( $ns, '/erp/buchabschluss/euer-csv', 'euer_csv', $export );

		self::get( $ns, '/erp/buchabschluss/gobd', 'gobd', $buch );
		self::post( $ns, '/erp/buchabschluss/gobd', 'gobd_pruefen', $buch );

		self::get( $ns, '/erp/buchabschluss/export', 'export_info', $export );

		self::get( $ns, '/erp/buchabschluss/abschluss', 'abschluss', $buch );
		self::post( $ns, '/erp/buchabschluss/dauerbuchungen-ausfuehren', 'dauerbuchungen_ausfuehren', $buch );
		self::post( $ns, '/erp/buchabschluss/kassenbuch-bestaetigen', 'kassenbuch_bestaetigen', $buch );
		self::post( $ns, '/erp/buchabschluss/gebuehren-pruefen', 'gebuehren_pruefen', $buch );
	}

	/**
	 * Lese-Route anmelden.
	 *
	 * @param string   $ns     Namensraum.
	 * @param string   $route  Pfad.
	 * @param string   $method Methode dieser Klasse.
	 * @param callable $perm   Rechteprüfung.
	 */
	protected static function get( $ns, $route, $method, $perm ) {
		register_rest_route(
			$ns,
			$route,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, $method ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * Schreib-Route anmelden.
	 *
	 * @param string   $ns     Namensraum.
	 * @param string   $route  Pfad.
	 * @param string   $method Methode dieser Klasse.
	 * @param callable $perm   Rechteprüfung.
	 */
	protected static function post( $ns, $route, $method, $perm ) {
		register_rest_route(
			$ns,
			$route,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, $method ),
				'permission_callback' => $perm,
			)
		);
	}

	/* ====================================================================
	 * Reine Regeln (ohne Datenbank, getestet in tests/ErpBuchAbschlussTest.php)
	 * ================================================================== */

	/**
	 * Betrag aus Eingabe lesen: „12,50“, „1.234,50“, „12.5“ oder eine Zahl.
	 *
	 * @param mixed $value Eingabe.
	 * @return float|null null, wenn keine Zahl.
	 */
	public static function parse_money( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return is_finite( (float) $value ) ? round( (float) $value, 2 ) : null;
		}

		$text = is_string( $value ) ? trim( str_replace( array( ' ', '€', "\xC2\xA0" ), '', $value ) ) : '';

		if ( '' === $text ) {
			return null;
		}

		if ( false !== strpos( $text, ',' ) ) {
			// Deutsche Schreibweise: Punkte sind Tausendertrenner.
			$text = str_replace( '.', '', $text );
			$text = str_replace( ',', '.', $text );
		}

		if ( ! preg_match( '/^-?\d+(\.\d+)?$/', $text ) ) {
			return null;
		}

		return round( (float) $text, 2 );
	}

	/**
	 * Prozentuale Abweichung zum Vergleichswert.
	 *
	 * Ohne Vergleichswert (0) lässt sich keine Prozentzahl bilden: null.
	 * Zwei Nullen sind keine Abweichung (0).
	 *
	 * @param float $aktuell Aktueller Wert.
	 * @param float $vorher  Vergleichswert.
	 * @return float|null
	 */
	public static function percent_change( $aktuell, $vorher ) {
		$aktuell = (float) $aktuell;
		$vorher  = (float) $vorher;

		if ( abs( $vorher ) < 0.005 ) {
			return abs( $aktuell ) < 0.005 ? 0.0 : null;
		}

		return round( ( $aktuell - $vorher ) / abs( $vorher ) * 100, 1 );
	}

	/**
	 * Eine Vergleichszeile: aktuell, Vorjahr, Unterschied, Prozent.
	 *
	 * @param float $aktuell Aktueller Wert.
	 * @param float $vorher  Vorjahreswert.
	 * @return array
	 */
	public static function delta_row( $aktuell, $vorher ) {
		return array(
			'aktuell' => round( (float) $aktuell, 2 ),
			'vorjahr' => round( (float) $vorher, 2 ),
			'diff'    => round( (float) $aktuell - (float) $vorher, 2 ),
			'prozent' => self::percent_change( $aktuell, $vorher ),
		);
	}

	/**
	 * Vorjahresvergleich der drei Eckwerte.
	 *
	 * @param array $aktuell Bericht (einnahmen, ausgaben_summe, gewinn).
	 * @param array $vorher  Bericht des Vorjahres.
	 * @return array einnahmen, ausgaben, gewinn je als delta_row.
	 */
	public static function year_compare( array $aktuell, array $vorher ) {
		$pick = static function ( array $r, $key ) {
			return isset( $r[ $key ] ) ? (float) $r[ $key ] : 0.0;
		};

		return array(
			'einnahmen' => self::delta_row( $pick( $aktuell, 'einnahmen' ), $pick( $vorher, 'einnahmen' ) ),
			'ausgaben'  => self::delta_row( $pick( $aktuell, 'ausgaben_summe' ), $pick( $vorher, 'ausgaben_summe' ) ),
			'gewinn'    => self::delta_row( $pick( $aktuell, 'gewinn' ), $pick( $vorher, 'gewinn' ) ),
		);
	}

	/**
	 * Konten (Konto => brutto/abziehbar) nach Kontengruppe zusammenfassen.
	 *
	 * Die Reihenfolge der Gruppen folgt der Kontonummer wie in der EÜR; je
	 * Gruppe die Konten mit Bezeichnung.
	 *
	 * @param array $konten Konto => array( brutto, abziehbar ).
	 * @return array Liste {name, summe, konten:[{konto,label,brutto,abziehbar}]}.
	 */
	public static function groups_from_accounts( array $konten ) {
		$map = array();

		foreach ( $konten as $konto => $summen ) {
			$name = Kontenrahmen::gruppe( (string) $konto );

			if ( ! isset( $map[ $name ] ) ) {
				$map[ $name ] = array(
					'name'   => $name,
					'summe'  => 0.0,
					'konten' => array(),
				);
			}

			$abziehbar = isset( $summen['abziehbar'] ) ? (float) $summen['abziehbar'] : 0.0;

			$map[ $name ]['summe']   += $abziehbar;
			$map[ $name ]['konten'][] = array(
				'konto'     => (string) $konto,
				'label'     => Kontenrahmen::label( (string) $konto ),
				'brutto'    => round( isset( $summen['brutto'] ) ? (float) $summen['brutto'] : 0.0, 2 ),
				'abziehbar' => round( $abziehbar, 2 ),
			);
		}

		foreach ( $map as $name => $g ) {
			$map[ $name ]['summe'] = round( $g['summe'], 2 );
		}

		return array_values( $map );
	}

	/**
	 * Gruppen des aktuellen Zeitraums mit den Werten des Vorjahres versehen.
	 *
	 * Gruppen, die es nur im Vorjahr gab, erscheinen mit Betrag 0.
	 *
	 * @param array      $gruppen Aus groups_from_accounts().
	 * @param array|null $vorher  Gruppen des Vorjahres (gleiche Form) oder null ohne Vergleich.
	 * @return array
	 */
	public static function groups_compare( array $gruppen, $vorher ) {
		if ( null === $vorher ) {
			return $gruppen;
		}

		$prev = array();

		foreach ( $vorher as $g ) {
			$prev[ $g['name'] ] = (float) $g['summe'];
		}

		$out  = array();
		$seen = array();

		foreach ( $gruppen as $g ) {
			$seen[ $g['name'] ] = true;
			$g['vergleich']     = self::delta_row( $g['summe'], isset( $prev[ $g['name'] ] ) ? $prev[ $g['name'] ] : 0 );
			$out[]              = $g;
		}

		foreach ( $prev as $name => $summe ) {
			if ( isset( $seen[ $name ] ) ) {
				continue;
			}

			$out[] = array(
				'name'      => $name,
				'summe'     => 0.0,
				'konten'    => array(),
				'vergleich' => self::delta_row( 0, $summe ),
			);
		}

		return $out;
	}

	/**
	 * Ein Datum um ein Jahr zurück, mit Schaltjahr-Korrektur (29.02. → 28.02.).
	 *
	 * @param string $date Y-m-d.
	 * @param int    $years Jahre (positiv = zurück).
	 * @return string
	 */
	public static function shift_years_back( $date, $years = 1 ) {
		$y = (int) substr( $date, 0, 4 ) - (int) $years;
		$m = (int) substr( $date, 5, 2 );
		$d = (int) substr( $date, 8, 2 );
		$t = (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) );

		return sprintf( '%04d-%02d-%02d', $y, $m, min( $d, $t ) );
	}

	/**
	 * Zeitraum aus der Auswahl bestimmen.
	 *
	 * @param string $modus    jahr, quartal, monat oder frei.
	 * @param int    $jahr     Jahr.
	 * @param int    $quartal  1 bis 4.
	 * @param int    $monat    1 bis 12.
	 * @param string $von      Anfang bei „frei“.
	 * @param string $bis      Ende bei „frei“.
	 * @return array {modus, von, bis, label, error}
	 */
	public static function resolve_period( $modus, $jahr, $quartal, $monat, $von = '', $bis = '' ) {
		$modus = in_array( $modus, array( 'jahr', 'quartal', 'monat', 'frei' ), true ) ? $modus : 'jahr';
		$jahr  = (int) $jahr;
		$out   = array(
			'modus' => $modus,
			'von'   => '',
			'bis'   => '',
			'label' => '',
			'error' => '',
		);

		if ( 'frei' === $modus ) {
			$von = Zahlen::clean_date( $von, '' );
			$bis = Zahlen::clean_date( $bis, '' );

			if ( '' === $von || '' === $bis ) {
				$out['error'] = __( 'Bitte Anfang und Ende des Zeitraums als Datum angeben.', 'lindenweg16' );
				return $out;
			}

			$err = Zahlen::range_error( $von, $bis );

			if ( '' !== $err ) {
				$out['error'] = $err;
				return $out;
			}

			$out['von']   = $von;
			$out['bis']   = $bis;
			$out['label'] = self::de_date( $von ) . ' bis ' . self::de_date( $bis );
			return $out;
		}

		if ( $jahr < 2000 || $jahr > 2100 ) {
			$out['error'] = __( 'Bitte ein Jahr zwischen 2000 und 2100 wählen.', 'lindenweg16' );
			return $out;
		}

		if ( 'jahr' === $modus ) {
			$out['von']   = $jahr . '-01-01';
			$out['bis']   = $jahr . '-12-31';
			$out['label'] = (string) $jahr;
			return $out;
		}

		if ( 'quartal' === $modus ) {
			$quartal = (int) $quartal;

			if ( $quartal < 1 || $quartal > 4 ) {
				$out['error'] = __( 'Bitte ein Quartal von 1 bis 4 wählen.', 'lindenweg16' );
				return $out;
			}

			$m1           = ( $quartal - 1 ) * 3 + 1;
			$m3           = $m1 + 2;
			$out['von']   = sprintf( '%04d-%02d-01', $jahr, $m1 );
			$out['bis']   = sprintf( '%04d-%02d-%02d', $jahr, $m3, (int) gmdate( 't', gmmktime( 0, 0, 0, $m3, 1, $jahr ) ) );
			$out['label'] = $quartal . '. Quartal ' . $jahr;
			return $out;
		}

		$monat = (int) $monat;

		if ( $monat < 1 || $monat > 12 ) {
			$out['error'] = __( 'Bitte einen Monat von 1 bis 12 wählen.', 'lindenweg16' );
			return $out;
		}

		$namen        = array( 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' );
		$out['von']   = sprintf( '%04d-%02d-01', $jahr, $monat );
		$out['bis']   = sprintf( '%04d-%02d-%02d', $jahr, $monat, (int) gmdate( 't', gmmktime( 0, 0, 0, $monat, 1, $jahr ) ) );
		$out['label'] = $namen[ $monat - 1 ] . ' ' . $jahr;
		return $out;
	}

	/**
	 * Vergleichszeitraum: derselbe Zeitraum ein Jahr früher.
	 *
	 * @param array $period Ergebnis von resolve_period().
	 * @param int   $quartal Quartal (für die Neuberechnung).
	 * @param int   $monat   Monat.
	 * @param int   $jahr    Jahr der Auswahl.
	 * @return array|null Wie resolve_period() oder null, wenn der Zeitraum selbst ungültig ist.
	 */
	public static function previous_period( array $period, $quartal, $monat, $jahr ) {
		if ( '' !== $period['error'] ) {
			return null;
		}

		if ( 'frei' !== $period['modus'] ) {
			return self::resolve_period( $period['modus'], (int) $jahr - 1, $quartal, $monat );
		}

		$von = self::shift_years_back( $period['von'] );
		$bis = self::shift_years_back( $period['bis'] );

		return array(
			'modus' => 'frei',
			'von'   => $von,
			'bis'   => $bis,
			'label' => self::de_date( $von ) . ' bis ' . self::de_date( $bis ),
			'error' => '',
		);
	}

	/**
	 * Datum Y-m-d als TT.MM.JJJJ.
	 *
	 * @param string $date Datum.
	 * @return string
	 */
	public static function de_date( $date ) {
		return preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m ) ? $m[3] . '.' . $m[2] . '.' . $m[1] : (string) $date;
	}

	/**
	 * Vermerk einer AfA-Buchung im Beleg (dient auch zum Wiedererkennen).
	 *
	 * @param string $nummer Anlagennummer.
	 * @param int    $jahr   Geschäftsjahr.
	 * @return string
	 */
	public static function afa_note( $nummer, $jahr ) {
		return sprintf(
			/* translators: %1$s asset number, %2$d year */
			__( 'Lineare AfA %1$s, Geschäftsjahr %2$d', 'lindenweg16' ),
			$nummer,
			$jahr
		);
	}

	/**
	 * Jahresbetrag der linearen AfA (wie AnlagenRepository::calculate_afa).
	 *
	 * @param float $kosten   Anschaffungskosten.
	 * @param int   $dauer    Nutzungsdauer in Jahren.
	 * @param float $buchwert Aktueller Buchwert.
	 * @return float
	 */
	public static function afa_amount( $kosten, $dauer, $buchwert ) {
		return min( round( (float) $kosten / max( 1, (int) $dauer ), 2 ), (float) $buchwert );
	}

	/**
	 * Darf die AfA für dieses Jahr gebucht werden? Leer = ja.
	 *
	 * @param int $jahr Jahr.
	 * @param int $now  Aktuelles Jahr.
	 * @return string
	 */
	public static function afa_year_error( $jahr, $now ) {
		if ( $jahr < 2020 || $jahr > 2099 ) {
			return __( 'Bitte ein Jahr zwischen 2020 und 2099 angeben.', 'lindenweg16' );
		}

		if ( $jahr > $now ) {
			return __( 'Die Abschreibung für ein Jahr, das noch nicht begonnen hat, kann noch nicht gebucht werden.', 'lindenweg16' );
		}

		return '';
	}

	/**
	 * Welche Anlagen haben die AfA für das Jahr schon im Journal?
	 *
	 * @param array $anlagen Zeilen mit id und anlagennummer.
	 * @param array $notizen Vermerke gebuchter AfA-Belege.
	 * @param int   $jahr    Jahr.
	 * @return int[] Anlagen-IDs.
	 */
	public static function booked_ids( array $anlagen, array $notizen, $jahr ) {
		$ids = array();

		foreach ( $anlagen as $a ) {
			if ( in_array( self::afa_note( $a['anlagennummer'], $jahr ), $notizen, true ) ) {
				$ids[] = (int) $a['id'];
			}
		}

		return $ids;
	}

	/**
	 * AfA-Vorschau für ein Jahr: was würde gebucht, was nicht und warum.
	 *
	 * Reihenfolge der Prüfungen wie AnlagenRepository::book_yearly_afa
	 * (ausgeschiedene Anlagen sind dort gar nicht dabei; Jahr im
	 * Abschreibungszeitraum; Buchwert > 0; Betrag > 0), ergänzt um „schon
	 * gebucht“.
	 *
	 * @param array $anlagen     Zeilen: id, anlagennummer, bezeichnung, anschaffungsdatum,
	 *                           anschaffungskosten, nutzungsdauer_jahre, buchwert, ausgeschieden.
	 * @param int   $jahr        Geschäftsjahr.
	 * @param int[] $gebucht_ids Anlagen, für die das Jahr schon gebucht ist.
	 * @return array {zeilen, summe, anzahl, offen, gebucht}
	 */
	public static function afa_preview( array $anlagen, $jahr, array $gebucht_ids ) {
		$jahr   = (int) $jahr;
		$zeilen = array();
		$summe  = 0.0;
		$offen  = 0;
		$done   = 0;

		foreach ( $anlagen as $a ) {
			$id       = (int) $a['id'];
			$start    = (int) substr( (string) $a['anschaffungsdatum'], 0, 4 );
			$ende     = $start + (int) $a['nutzungsdauer_jahre'] - 1;
			$buchwert = (float) $a['buchwert'];
			$betrag   = 0.0;
			$status   = 'buchen';

			if ( 1 === (int) $a['ausgeschieden'] ) {
				$status = 'ausgeschieden';
			} elseif ( $jahr < $start || $jahr > $ende ) {
				$status = 'ausserhalb';
			} elseif ( in_array( $id, $gebucht_ids, true ) ) {
				$status = 'gebucht';
			} elseif ( $buchwert <= 0 ) {
				$status = 'abgeschrieben';
			} else {
				$betrag = self::afa_amount( $a['anschaffungskosten'], $a['nutzungsdauer_jahre'], $buchwert );

				if ( $betrag <= 0 ) {
					$status = 'abgeschrieben';
				}
			}

			if ( 'buchen' === $status ) {
				$summe += $betrag;
				$offen++;
			} elseif ( 'gebucht' === $status ) {
				$done++;
			}

			$zeilen[] = array(
				'id'           => $id,
				'nummer'       => (string) $a['anlagennummer'],
				'bezeichnung'  => (string) $a['bezeichnung'],
				'status'       => $status,
				'betrag'       => round( $betrag, 2 ),
				'buchwert_alt' => round( $buchwert, 2 ),
				'buchwert_neu' => 'buchen' === $status ? round( max( 0, $buchwert - $betrag ), 2 ) : round( $buchwert, 2 ),
				'hinweis'      => self::afa_status_text( $status, $start, $ende, $jahr ),
			);
		}

		return array(
			'zeilen'  => $zeilen,
			'summe'   => round( $summe, 2 ),
			'anzahl'  => count( $zeilen ),
			'offen'   => $offen,
			'gebucht' => $done,
		);
	}

	/**
	 * Erklärung zum Status einer AfA-Zeile.
	 *
	 * @param string $status Status.
	 * @param int    $start  Erstes AfA-Jahr.
	 * @param int    $ende   Letztes AfA-Jahr.
	 * @param int    $jahr   Gewähltes Jahr.
	 * @return string
	 */
	public static function afa_status_text( $status, $start, $ende, $jahr ) {
		switch ( $status ) {
			case 'buchen':
				return __( 'Wird gebucht.', 'lindenweg16' );
			case 'gebucht':
				return sprintf(
					/* translators: %d year */
					__( 'Für %d bereits gebucht.', 'lindenweg16' ),
					$jahr
				);
			case 'ausgeschieden':
				return __( 'Ausgeschieden, wird nicht mehr abgeschrieben.', 'lindenweg16' );
			case 'abgeschrieben':
				return __( 'Schon voll abgeschrieben.', 'lindenweg16' );
			default:
				return sprintf(
					/* translators: 1: first year, 2: last year */
					__( 'Abschreibung nur von %1$d bis %2$d.', 'lindenweg16' ),
					$start,
					$ende
				);
		}
	}

	/**
	 * Eingaben einer neuen Anlage prüfen und bereinigen.
	 *
	 * @param array $in    Roheingaben: bezeichnung, anschaffungsdatum, anschaffungskosten,
	 *                     nutzungsdauer_jahre, kategorie.
	 * @param int   $today Jahr heute (für die Obergrenze des Datums).
	 * @return array {data, error}
	 */
	public static function clean_anlage( array $in, $today ) {
		$name  = isset( $in['bezeichnung'] ) ? trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $in['bezeichnung'] ) ) ) : '';
		$datum = Zahlen::clean_date( isset( $in['anschaffungsdatum'] ) ? $in['anschaffungsdatum'] : '', '' );
		$kost  = self::parse_money( isset( $in['anschaffungskosten'] ) ? $in['anschaffungskosten'] : '' );
		$dauer = isset( $in['nutzungsdauer_jahre'] ) ? (int) $in['nutzungsdauer_jahre'] : 0;
		$kat   = isset( $in['kategorie'] ) ? (string) $in['kategorie'] : '';

		$fail = static function ( $msg ) {
			return array( 'data' => array(), 'error' => $msg );
		};

		if ( '' === $name ) {
			return $fail( __( 'Bitte eine Bezeichnung angeben, zum Beispiel „3D-Drucker“.', 'lindenweg16' ) );
		}

		if ( '' === $datum ) {
			return $fail( __( 'Bitte das Anschaffungsdatum angeben.', 'lindenweg16' ) );
		}

		$jahr = (int) substr( $datum, 0, 4 );

		if ( $jahr < 2000 || $jahr > (int) $today + 1 ) {
			return $fail( __( 'Das Anschaffungsdatum passt nicht: Bitte ein Datum aus den letzten Jahren wählen.', 'lindenweg16' ) );
		}

		if ( null === $kost || $kost <= 0 ) {
			return $fail( __( 'Bitte die Anschaffungskosten als Betrag über 0 angeben.', 'lindenweg16' ) );
		}

		if ( $dauer < 1 || $dauer > 50 ) {
			return $fail( __( 'Die Nutzungsdauer muss zwischen 1 und 50 Jahren liegen.', 'lindenweg16' ) );
		}

		if ( ! in_array( $kat, self::KATEGORIEN, true ) ) {
			$kat = 'Sonstiges';
		}

		return array(
			'data'  => array(
				'bezeichnung'         => mb_substr( $name, 0, 190 ),
				'anschaffungsdatum'   => $datum,
				'anschaffungskosten'  => abs( $kost ),
				'nutzungsdauer_jahre' => $dauer,
				'kategorie'           => $kat,
			),
			'error' => '',
		);
	}

	/**
	 * Eingaben zum Ausscheiden prüfen.
	 *
	 * @param string $grund  Grund.
	 * @param string $datum  Datum (Y-m-d) oder leer = heute.
	 * @param string $kauf   Anschaffungsdatum der Anlage.
	 * @param string $heute  Heutiges Datum.
	 * @return array {grund, datum, error}
	 */
	public static function clean_ausscheiden( $grund, $datum, $kauf, $heute ) {
		$grund = trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $grund ) ) );
		$datum = '' === trim( (string) $datum ) ? $heute : Zahlen::clean_date( $datum, '' );
		$out   = array( 'grund' => $grund, 'datum' => $datum, 'error' => '' );

		if ( mb_strlen( $grund ) < 3 ) {
			$out['error'] = __( 'Bitte einen Grund angeben, zum Beispiel „verkauft“, „defekt entsorgt“ oder „privat entnommen“.', 'lindenweg16' );
		} elseif ( '' === $datum ) {
			$out['error'] = __( 'Bitte das Datum des Ausscheidens als gültiges Datum angeben.', 'lindenweg16' );
		} elseif ( $datum > $heute ) {
			$out['error'] = __( 'Das Datum des Ausscheidens darf nicht in der Zukunft liegen.', 'lindenweg16' );
		} elseif ( $datum < $kauf ) {
			$out['error'] = __( 'Das Datum des Ausscheidens liegt vor der Anschaffung.', 'lindenweg16' );
		}

		$out['grund'] = mb_substr( $grund, 0, 200 );

		return $out;
	}

	/**
	 * Konto eines Belegs für die EÜR (wie BelegRepository::sum_by_konto).
	 *
	 * @param string $konto     Gespeichertes Konto.
	 * @param string $kategorie Alte Kategorie.
	 * @return string
	 */
	public static function beleg_konto( $konto, $kategorie ) {
		$konto = (string) $konto;

		if ( '' === $konto ) {
			$konto = Kontenrahmen::konto_fuer_kategorie( $kategorie );
		}

		return '' === $konto ? Kontenrahmen::KONTO_SONSTIGES : $konto;
	}

	/* ---------------- Ampel der Checkliste ---------------- */

	/**
	 * Ergebnis einer Prüfzeile.
	 *
	 * @param string $status gruen, gelb, rot oder grau (noch nicht geprüft).
	 * @param string $text   Erklärung.
	 * @return array
	 */
	protected static function lamp( $status, $text ) {
		return array( 'status' => $status, 'text' => $text );
	}

	/**
	 * Ampel: Belege, die noch nicht festgeschrieben sind.
	 *
	 * @param int $offen Anzahl.
	 * @return array
	 */
	public static function lamp_open_receipts( $offen ) {
		$offen = (int) $offen;

		if ( 0 === $offen ) {
			return self::lamp( 'gruen', __( 'Alle Belege des Jahres sind festgeschrieben.', 'lindenweg16' ) );
		}

		return self::lamp(
			'rot',
			sprintf(
				/* translators: %d number */
				_n(
					'%d Beleg ist noch nicht festgeschrieben. Erst festgeschriebene Belege zählen in der EÜR und sind unveränderbar.',
					'%d Belege sind noch nicht festgeschrieben. Erst festgeschriebene Belege zählen in der EÜR und sind unveränderbar.',
					$offen,
					'lindenweg16'
				),
				$offen
			)
		);
	}

	/**
	 * Ampel: fällige Dauerbuchungen.
	 *
	 * @param int $faellig Anzahl.
	 * @return array
	 */
	public static function lamp_recurring( $faellig ) {
		$faellig = (int) $faellig;

		if ( 0 === $faellig ) {
			return self::lamp( 'gruen', __( 'Keine Dauerbuchung ist fällig.', 'lindenweg16' ) );
		}

		return self::lamp(
			'gelb',
			sprintf(
				/* translators: %d number */
				_n(
					'%d Dauerbuchung ist fällig und noch nicht gebucht. Sie wird mit dem heutigen Datum gebucht.',
					'%d Dauerbuchungen sind fällig und noch nicht gebucht. Sie werden mit dem heutigen Datum gebucht.',
					$faellig,
					'lindenweg16'
				),
				$faellig
			)
		);
	}

	/**
	 * Ampel: AfA des Jahres.
	 *
	 * @param int $offen   Anlagen, deren AfA noch zu buchen ist.
	 * @param int $gebucht Anlagen, deren AfA gebucht ist.
	 * @return array
	 */
	public static function lamp_afa( $offen, $gebucht ) {
		$offen   = (int) $offen;
		$gebucht = (int) $gebucht;

		if ( $offen > 0 ) {
			return self::lamp(
				'rot',
				sprintf(
					/* translators: %d number */
					_n(
						'Für %d Anlage fehlt die Abschreibung des Jahres. Sie mindert den Gewinn und gehört in die EÜR.',
						'Für %d Anlagen fehlt die Abschreibung des Jahres. Sie mindert den Gewinn und gehört in die EÜR.',
						$offen,
						'lindenweg16'
					),
					$offen
				)
			);
		}

		if ( $gebucht > 0 ) {
			return self::lamp( 'gruen', __( 'Die Abschreibung des Jahres ist für alle Anlagen gebucht.', 'lindenweg16' ) );
		}

		return self::lamp( 'gruen', __( 'In diesem Jahr ist keine Anlage abzuschreiben.', 'lindenweg16' ) );
	}

	/**
	 * Ampel: Kassenbuch zum Jahresende.
	 *
	 * Das Kassenbuch kennt keinen Abschluss als Datensatz. Als abgeschlossen
	 * gilt es, wenn die Person den gezählten Bargeldbestand eingetragen hat
	 * (Kassensturz), er zum Buchbestand am 31.12. passt und seither nichts
	 * mehr hinzukam.
	 *
	 * @param int        $anzahl Einträge des Jahres.
	 * @param float      $saldo  Buchbestand am 31.12.
	 * @param array|null $kasse  Letzte Bestätigung {buchsaldo, gezaehlt, differenz} oder null.
	 * @return array
	 */
	public static function lamp_cash( $anzahl, $saldo, $kasse ) {
		$saldo = round( (float) $saldo, 2 );

		if ( (int) $anzahl <= 0 ) {
			return self::lamp( 'gruen', __( 'In diesem Jahr gibt es keine Kassenbucheinträge.', 'lindenweg16' ) );
		}

		if ( $saldo < 0 ) {
			return self::lamp( 'rot', __( 'Der Kassenbestand ist negativ. Das kann in der Wirklichkeit nicht sein, es fehlt ein Eintrag (zum Beispiel eine Einlage). Bitte im Kassenbuch nachtragen.', 'lindenweg16' ) );
		}

		if ( null === $kasse ) {
			return self::lamp( 'gelb', __( 'Der Kassensturz fehlt noch: Bargeld zählen und den Betrag eintragen.', 'lindenweg16' ) );
		}

		if ( abs( round( (float) $kasse['buchsaldo'], 2 ) - $saldo ) >= 0.005 ) {
			return self::lamp( 'gelb', __( 'Seit dem letzten Kassensturz gab es neue Kassenbucheinträge. Bitte den Bestand noch einmal zählen und eintragen.', 'lindenweg16' ) );
		}

		if ( abs( (float) $kasse['differenz'] ) >= 0.005 ) {
			return self::lamp(
				'rot',
				sprintf(
					/* translators: %s amount */
					__( 'Beim Kassensturz fehlte oder lag zu viel Geld in der Kasse (Differenz %s). Bitte die Ursache klären und im Kassenbuch nachtragen, dann neu zählen.', 'lindenweg16' ),
					self::eur_text( $kasse['differenz'] )
				)
			);
		}

		return self::lamp(
			'gruen',
			sprintf(
				/* translators: %s amount */
				__( 'Kassensturz gemacht: Der gezählte Bestand von %s stimmt mit dem Kassenbuch überein.', 'lindenweg16' ),
				self::eur_text( $kasse['gezaehlt'] )
			)
		);
	}

	/**
	 * Ampel: GoBD-Prüfung des Jahres.
	 *
	 * @param array|null $pruefung    Letzte Prüfung des Jahres {datum, ergebnis, fehler, warnungen} oder null.
	 * @param bool       $neue_belege Gab es seit der Prüfung neue Belege dieses Jahres?
	 * @return array
	 */
	public static function lamp_gobd( $pruefung, $neue_belege ) {
		if ( null === $pruefung ) {
			return self::lamp( 'rot', __( 'Für dieses Jahr wurde noch keine GoBD-Prüfung durchgeführt.', 'lindenweg16' ) );
		}

		if ( (int) $pruefung['fehler'] > 0 || 'fehler' === $pruefung['ergebnis'] ) {
			return self::lamp( 'rot', __( 'Die letzte Prüfung hat Fehler gefunden. Bitte im Reiter „GoBD-Prüfung“ ansehen.', 'lindenweg16' ) );
		}

		if ( $neue_belege ) {
			return self::lamp( 'gelb', __( 'Seit der letzten Prüfung wurden neue Belege erfasst. Bitte die Prüfung wiederholen.', 'lindenweg16' ) );
		}

		if ( (int) $pruefung['warnungen'] > 0 || 'ok' !== $pruefung['ergebnis'] ) {
			return self::lamp( 'gelb', __( 'Die letzte Prüfung hat Hinweise ergeben (keine Fehler). Bitte im Reiter „GoBD-Prüfung“ ansehen.', 'lindenweg16' ) );
		}

		return self::lamp( 'gruen', __( 'Die Prüfung wurde durchgeführt, alles in Ordnung.', 'lindenweg16' ) );
	}

	/**
	 * Ampel: Zahlungsgebühren.
	 *
	 * @param int|null $fehlend   Bestellungen mit erkannter, aber nicht gebuchter Gebühr; null = noch nicht geprüft.
	 * @param bool     $automatik Ist die automatische Erkennung eingeschaltet?
	 * @param bool     $gekuerzt  Wurden nicht alle Bestellungen angesehen?
	 * @return array
	 */
	public static function lamp_fees( $fehlend, $automatik, $gekuerzt = false ) {
		if ( null === $fehlend ) {
			return self::lamp( 'grau', __( 'Wird geprüft …', 'lindenweg16' ) );
		}

		$fehlend = (int) $fehlend;

		if ( $fehlend > 0 ) {
			$text = sprintf(
				/* translators: %d number */
				_n(
					'Bei %d Bestellung steht eine Zahlungsgebühr in den Bestelldaten, die noch nicht gebucht ist.',
					'Bei %d Bestellungen steht eine Zahlungsgebühr in den Bestelldaten, die noch nicht gebucht ist.',
					$fehlend,
					'lindenweg16'
				),
				$fehlend
			);

			if ( ! $automatik ) {
				$text .= ' ' . __( 'Die automatische Erkennung ist in den Einstellungen ausgeschaltet, deshalb wird nichts gebucht.', 'lindenweg16' );
			}

			return self::lamp( 'gelb', $text );
		}

		$text = __( 'Alle erkennbaren Zahlungsgebühren sind gebucht.', 'lindenweg16' );

		if ( $gekuerzt ) {
			$text .= ' ' . sprintf(
				/* translators: %d number */
				__( 'Geprüft wurden die ersten %d Bestellungen des Jahres.', 'lindenweg16' ),
				self::FEE_SCAN_LIMIT
			);
			return self::lamp( 'gelb', $text );
		}

		return self::lamp( 'gruen', $text );
	}

	/**
	 * Wie weit deckt ein Betriebsprüfungs-Export das Jahr ab?
	 *
	 * @param array $exporte Liste {von, bis} bisheriger Exporte.
	 * @param int   $jahr    Jahr.
	 * @return string voll, teil oder kein.
	 */
	public static function export_coverage( array $exporte, $jahr ) {
		$a   = $jahr . '-01-01';
		$e   = $jahr . '-12-31';
		$teil = false;

		foreach ( $exporte as $x ) {
			if ( empty( $x['von'] ) || empty( $x['bis'] ) ) {
				continue;
			}

			if ( $x['von'] <= $a && $x['bis'] >= $e ) {
				return 'voll';
			}

			if ( $x['von'] <= $e && $x['bis'] >= $a ) {
				$teil = true;
			}
		}

		return $teil ? 'teil' : 'kein';
	}

	/**
	 * Ampel: Export.
	 *
	 * @param string $abdeckung voll, teil oder kein.
	 * @return array
	 */
	public static function lamp_export( $abdeckung ) {
		if ( 'voll' === $abdeckung ) {
			return self::lamp( 'gruen', __( 'Ein Export für das ganze Jahr wurde erstellt.', 'lindenweg16' ) );
		}

		if ( 'teil' === $abdeckung ) {
			return self::lamp( 'gelb', __( 'Es gibt nur einen Export für einen Teil des Jahres. Für die Archivierung am besten das ganze Jahr exportieren.', 'lindenweg16' ) );
		}

		return self::lamp( 'gelb', __( 'Es wurde noch kein Export für dieses Jahr erstellt. Nach dem Abschluss empfiehlt sich eine Kopie für das Archiv.', 'lindenweg16' ) );
	}

	/**
	 * Gesamtampel: schlechtester Einzelwert.
	 *
	 * @param string[] $stati Status der Zeilen.
	 * @return string rot, gelb, grau oder gruen.
	 */
	public static function lamp_overall( array $stati ) {
		foreach ( array( 'rot', 'gelb', 'grau' ) as $s ) {
			if ( in_array( $s, $stati, true ) ) {
				return $s;
			}
		}

		return 'gruen';
	}

	/**
	 * Betrag als Text „1.234,50 €“.
	 *
	 * @param float $n Betrag.
	 * @return string
	 */
	public static function eur_text( $n ) {
		return number_format( (float) $n, 2, ',', '.' ) . ' €';
	}

	/* ---------------- GoBD-Ergebnisse verständlich ---------------- */

	/**
	 * Einen Prüfpunkt der GoBD-Prüfung für Menschen aufbereiten.
	 *
	 * @param array $p {name, status, details} wie von GobdChecker.
	 * @return array {name, status (gruen/gelb/rot), titel, erklaerung, technisch}
	 */
	public static function gobd_point( array $p ) {
		$name    = isset( $p['name'] ) ? (string) $p['name'] : '';
		$raw     = isset( $p['status'] ) ? (string) $p['status'] : 'ok';
		$details = isset( $p['details'] ) ? (string) $p['details'] : '';
		$status  = 'ok' === $raw ? 'gruen' : ( 'fehler' === $raw ? 'rot' : 'gelb' );
		$titel   = $name;
		$text    = '';

		if ( 0 === strpos( $name, 'Hash-Kette: ' ) ) {
			$was   = substr( $name, strlen( 'Hash-Kette: ' ) );
			$titel = sprintf(
				/* translators: %s area */
				__( 'Unveränderbarkeit: %s', 'lindenweg16' ),
				$was
			);
			if ( 'gruen' === $status ) {
				$text = __( 'Jeder Eintrag ist mit dem vorigen verkettet. Nichts wurde nachträglich verändert oder entfernt.', 'lindenweg16' );
			} elseif ( 'rot' === $status ) {
				$text = __( 'Mindestens ein Eintrag passt nicht mehr zur Kette. Das heißt: Daten wurden nach dem Anlegen verändert oder entfernt, etwa direkt in der Datenbank. Bitte nichts selbst reparieren, sondern eine Sicherung einspielen oder den Support fragen.', 'lindenweg16' );
			} else {
				$text = __( 'Diese Prüfung konnte nicht durchgeführt werden, weil die Datenbank noch nicht auf dem neuesten Stand ist. Bitte das Plugin einmal deaktivieren und wieder aktivieren und die Prüfung wiederholen.', 'lindenweg16' );
			}
		} elseif ( 0 === strpos( $name, 'Belegnummern' ) || 0 === strpos( $name, 'Rechnungsnummern' ) || 0 === strpos( $name, 'Buchungsnummern' ) || 0 === strpos( $name, 'Kassenbuchnummern' ) ) {
			$titel = preg_replace( '/-Lückenlosigkeit/', ': lückenlose Nummern', $name );
			if ( 'gruen' === $status ) {
				$text = __( 'Die Nummern laufen ohne Lücke durch. Es fehlt kein Eintrag.', 'lindenweg16' );
			} elseif ( 'rot' === $status ) {
				$text = __( 'In der Nummernfolge fehlen Nummern. Nummern dürfen nie vergeben und dann entfallen: Ein Prüfer fragt nach, wohin der Eintrag verschwunden ist. Fehlerhafte Einträge werden storniert, nie gelöscht.', 'lindenweg16' );
			} else {
				$text = __( 'Diese Prüfung konnte nicht durchgeführt werden, weil die Datenbank noch nicht auf dem neuesten Stand ist.', 'lindenweg16' );
			}
		} elseif ( 0 === strpos( $name, 'Zeitgerechte Erfassung' ) ) {
			$titel = __( 'Belege zeitnah erfasst', 'lindenweg16' );
			if ( 'gruen' === $status ) {
				$text = __( 'Alle Belege wurden innerhalb von 10 Tagen nach dem Belegdatum erfasst.', 'lindenweg16' );
			} else {
				$text = __( 'Einige Belege wurden später als 10 Tage nach ihrem Datum erfasst. Das ist kein Fehler, aber die GoBD verlangen zeitnahes Erfassen. Künftig früher erfassen, ein Foto genügt zunächst.', 'lindenweg16' );
			}
		} else {
			$text = 'gruen' === $status ? __( 'In Ordnung.', 'lindenweg16' ) : __( 'Bitte ansehen.', 'lindenweg16' );
		}

		return array(
			'name'       => $name,
			'status'     => $status,
			'titel'      => $titel,
			'erklaerung' => $text,
			'technisch'  => $details,
		);
	}

	/**
	 * Alle Prüfpunkte aufbereiten.
	 *
	 * @param array $punkte Prüfpunkte.
	 * @return array
	 */
	public static function gobd_points( array $punkte ) {
		$out = array();

		foreach ( $punkte as $p ) {
			if ( is_array( $p ) ) {
				$out[] = self::gobd_point( $p );
			}
		}

		return $out;
	}

	/**
	 * Fehlerantwort mit lesbarem Text.
	 *
	 * @param string $message Text.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}

	/**
	 * Jahr aus der Anfrage, sonst das laufende.
	 *
	 * @param mixed $value Eingabe.
	 * @return int
	 */
	protected static function year_param( $value ) {
		return Zahlen::clean_year( $value, (int) gmdate( 'Y' ) );
	}

	/**
	 * Anlage als Zeile für die Reine-Regeln-Funktionen.
	 *
	 * @param object $a Datensatz.
	 * @return array
	 */
	protected static function anlage_row( $a ) {
		return array(
			'id'                  => (int) $a->id,
			'anlagennummer'       => (string) $a->anlagennummer,
			'bezeichnung'         => (string) $a->bezeichnung,
			'anschaffungsdatum'   => (string) $a->anschaffungsdatum,
			'anschaffungskosten'  => (float) $a->anschaffungskosten,
			'nutzungsdauer_jahre' => (int) $a->nutzungsdauer_jahre,
			'buchwert'            => (float) $a->buchwert,
			'ausgeschieden'       => (int) $a->ausgeschieden,
			'ausgeschieden_am'    => isset( $a->ausgeschieden_am ) ? (string) $a->ausgeschieden_am : '',
			'kategorie'           => isset( $a->kategorie ) ? (string) $a->kategorie : '',
		);
	}

	/**
	 * Vermerke der AfA-Belege (für ein Jahr oder alle).
	 *
	 * @param int $jahr Jahr; 0 = alle.
	 * @return string[]
	 */
	protected static function afa_notes( $jahr = 0 ) {
		global $wpdb;

		$t = BelegRepository::table();

		if ( $jahr > 0 ) {
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT notiz FROM {$t} WHERE quelle = 'afa' AND datum = %s", $jahr . '-12-31' ) ); // phpcs:ignore
		} else {
			$rows = $wpdb->get_col( "SELECT notiz FROM {$t} WHERE quelle = 'afa' LIMIT 20000" ); // phpcs:ignore
		}

		return array_map( 'strval', (array) $rows );
	}

	/**
	 * Alle Anlagen als Zeilen.
	 *
	 * @param bool $nur_aktive Nur nicht ausgeschiedene.
	 * @return array
	 */
	protected static function all_anlagen( $nur_aktive ) {
		return array_map( array( __CLASS__, 'anlage_row' ), (array) AnlagenRepository::get_all( $nur_aktive ) );
	}

	/* ====================================================================
	 * Anlagen
	 * ================================================================== */

	/**
	 * Anlagenverzeichnis mit Plan und Restbuchwerten.
	 *
	 * @return WP_REST_Response
	 */
	public static function anlagen() {
		$rows   = self::all_anlagen( false );
		$notes  = self::afa_notes();
		$out    = array();
		$jahr   = (int) gmdate( 'Y' );
		$kosten = 0.0;

		foreach ( $rows as $a ) {
			$obj  = (object) $a;
			$plan = array();

			foreach ( AnlagenRepository::get_afa_plan( $obj ) as $p ) {
				$p['gebucht'] = in_array( self::afa_note( $a['anlagennummer'], $p['jahr'] ), $notes, true );
				$plan[]       = $p;
			}

			if ( 0 === $a['ausgeschieden'] ) {
				$kosten += $a['buchwert'];
			}

			$out[] = array(
				'id'               => $a['id'],
				'nummer'           => $a['anlagennummer'],
				'bezeichnung'      => $a['bezeichnung'],
				'datum'            => $a['anschaffungsdatum'],
				'kosten'           => $a['anschaffungskosten'],
				'dauer'            => $a['nutzungsdauer_jahre'],
				'afa_jahr'         => round( $a['anschaffungskosten'] / max( 1, $a['nutzungsdauer_jahre'] ), 2 ),
				'buchwert'         => $a['buchwert'],
				'kategorie'        => $a['kategorie'],
				'ausgeschieden'    => 1 === $a['ausgeschieden'],
				'ausgeschieden_am' => $a['ausgeschieden_am'],
				'plan'             => $plan,
			);
		}

		return new WP_REST_Response(
			array(
				'anlagen'     => $out,
				'buchwerte'   => round( $kosten, 2 ),
				'jahr'        => $jahr,
				'kategorien'  => self::KATEGORIEN,
				'heute'       => gmdate( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Neue Anlage erfassen (wie handle_save_anlage; Nummer, Buchwert und Protokoll legt das Repository an).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function anlage_speichern( WP_REST_Request $request ) {
		$clean = self::clean_anlage( (array) $request->get_json_params() + (array) $request->get_body_params(), (int) gmdate( 'Y' ) );

		if ( '' !== $clean['error'] ) {
			return self::fail( $clean['error'] );
		}

		if ( ! Lock::acquire( 'buch_anlage', 10 ) ) {
			return self::fail( __( 'Gerade wird eine andere Anlage erfasst. Bitte in einem Moment noch einmal versuchen.', 'lindenweg16' ), 409 );
		}

		try {
			$id = AnlagenRepository::create( $clean['data'] );
		} finally {
			Lock::release( 'buch_anlage' );
		}

		$anlage = $id ? AnlagenRepository::get( $id ) : null;

		if ( ! $anlage ) {
			return self::fail( __( 'Die Anlage konnte nicht gespeichert werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: %s asset number */
					__( 'Anlage %s erfasst.', 'lindenweg16' ),
					$anlage->anlagennummer
				),
			)
		);
	}

	/**
	 * AfA-Vorschau eines Jahres.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function afa_vorschau_route( WP_REST_Request $request ) {
		$jahr = self::year_param( $request->get_param( 'jahr' ) );

		return new WP_REST_Response( array_merge( array( 'jahr' => $jahr ), self::afa_preview_for( $jahr ) ) );
	}

	/**
	 * Vorschau aus den gespeicherten Anlagen.
	 *
	 * @param int $jahr Jahr.
	 * @return array
	 */
	protected static function afa_preview_for( $jahr ) {
		$rows = self::all_anlagen( false );
		$ids  = self::booked_ids( $rows, self::afa_notes( $jahr ), $jahr );

		return self::afa_preview( $rows, $jahr, $ids );
	}

	/**
	 * AfA buchen: alle Anlagen des Jahres oder eine einzelne.
	 *
	 * Beleg und Festschreibung wie AnlagenRepository::book_yearly_afa
	 * (Kategorie „AfA“, Datum 31.12., Quelle „afa“, danach Buchwert senken und
	 * protokollieren), zusätzlich nur einmal je Anlage und Jahr.
	 *
	 * @param WP_REST_Request $request Anfrage (jahr, optional id).
	 * @return WP_REST_Response
	 */
	public static function afa_buchen( WP_REST_Request $request ) {
		$jahr = (int) $request->get_param( 'jahr' );
		$id   = (int) $request->get_param( 'id' );
		$err  = self::afa_year_error( $jahr, (int) gmdate( 'Y' ) );

		if ( '' !== $err ) {
			return self::fail( $err );
		}

		if ( ! Lock::acquire( 'buch_afa_' . $jahr, 15 ) ) {
			return self::fail( __( 'Die Abschreibung wird gerade gebucht. Bitte kurz warten und die Seite neu laden.', 'lindenweg16' ), 409 );
		}

		try {
			$vorschau = self::afa_preview_for( $jahr );
			$gebucht  = 0;
			$summe    = 0.0;

			if ( $id > 0 ) {
				$ziel = null;

				foreach ( $vorschau['zeilen'] as $z ) {
					if ( $z['id'] === $id ) {
						$ziel = $z;
					}
				}

				if ( null === $ziel ) {
					return self::fail( __( 'Diese Anlage gibt es nicht.', 'lindenweg16' ), 404 );
				}

				if ( 'buchen' !== $ziel['status'] ) {
					return self::fail( $ziel['hinweis'] );
				}
			}

			foreach ( $vorschau['zeilen'] as $z ) {
				if ( 'buchen' !== $z['status'] || ( $id > 0 && $z['id'] !== $id ) ) {
					continue;
				}

				$anlage = AnlagenRepository::get( $z['id'] );

				if ( $anlage && self::book_one( $anlage, $jahr, $z['betrag'] ) ) {
					$gebucht++;
					$summe += $z['betrag'];
				}
			}
		} finally {
			Lock::release( 'buch_afa_' . $jahr );
		}

		if ( 0 === $gebucht ) {
			return self::fail( __( 'Es gab nichts zu buchen: Für dieses Jahr ist die Abschreibung schon gebucht oder keine Anlage betroffen.', 'lindenweg16' ) );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'anzahl'  => $gebucht,
				'summe'   => round( $summe, 2 ),
				'message' => sprintf(
					/* translators: 1: number, 2: year, 3: amount */
					__( 'Abschreibung für %1$d Anlage(n) im Jahr %2$d gebucht (zusammen %3$s).', 'lindenweg16' ),
					$gebucht,
					$jahr,
					self::eur_text( $summe )
				),
			)
		);
	}

	/**
	 * Abschreibung einer Anlage buchen (Inhalt wie AnlagenRepository::book_yearly_afa).
	 *
	 * @param object $anlage Anlage.
	 * @param int    $jahr   Jahr.
	 * @param float  $afa    Betrag.
	 * @return bool
	 */
	protected static function book_one( $anlage, $jahr, $afa ) {
		global $wpdb;

		$beleg_id = BelegRepository::create(
			array(
				'betrag'        => $afa,
				'datum'         => $jahr . '-12-31',
				'haendler'      => $anlage->bezeichnung,
				'eur_kategorie' => 'AfA',
				'notiz'         => self::afa_note( $anlage->anlagennummer, $jahr ),
				'quelle'        => 'afa',
			)
		);

		if ( ! $beleg_id ) {
			return false;
		}

		BelegRepository::festschreiben( $beleg_id );

		$neuer_buchwert = max( 0, (float) $anlage->buchwert - $afa );
		$wpdb->update(
			AnlagenRepository::table(),
			array( 'buchwert' => $neuer_buchwert ),
			array( 'id' => (int) $anlage->id )
		);

		AuditLog::record(
			'afa_gebucht',
			'anlage',
			(int) $anlage->id,
			array(
				'jahr'           => $jahr,
				'betrag'         => $afa,
				'neuer_buchwert' => $neuer_buchwert,
			)
		);

		return true;
	}

	/**
	 * Anlage ausscheiden lassen, mit Grund und Datum (Protokoll zusätzlich zum Repository).
	 *
	 * @param WP_REST_Request $request Anfrage (id, grund, datum).
	 * @return WP_REST_Response
	 */
	public static function anlage_ausscheiden( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$anlage = $id > 0 ? AnlagenRepository::get( $id ) : null;

		if ( ! $anlage ) {
			return self::fail( __( 'Diese Anlage gibt es nicht.', 'lindenweg16' ), 404 );
		}

		if ( 1 === (int) $anlage->ausgeschieden ) {
			return self::fail( __( 'Diese Anlage ist schon als ausgeschieden vermerkt.', 'lindenweg16' ) );
		}

		$clean = self::clean_ausscheiden( $request->get_param( 'grund' ), (string) $request->get_param( 'datum' ), (string) $anlage->anschaffungsdatum, gmdate( 'Y-m-d' ) );

		if ( '' !== $clean['error'] ) {
			return self::fail( $clean['error'] );
		}

		AnlagenRepository::ausscheiden( $id, $clean['datum'] );

		AuditLog::record(
			'anlage_ausscheiden_grund',
			'anlage',
			$id,
			array(
				'grund'     => $clean['grund'],
				'datum'     => $clean['datum'],
				'restwert'  => round( (float) $anlage->buchwert, 2 ),
			)
		);

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: 1: asset number, 2: amount */
					__( 'Anlage %1$s ist ausgeschieden. Der Restbuchwert war %2$s. Er wird nicht mehr abgeschrieben.', 'lindenweg16' ),
					$anlage->anlagennummer,
					self::eur_text( $anlage->buchwert )
				),
			)
		);
	}

	/* ====================================================================
	 * EÜR
	 * ================================================================== */

	/**
	 * Zeitraum aus der Anfrage.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array {period, quartal, monat, jahr}
	 */
	protected static function period_from( WP_REST_Request $request ) {
		$now     = time();
		$jahr    = Zahlen::clean_year( $request->get_param( 'jahr' ), (int) gmdate( 'Y', $now ) );
		$quartal = (int) $request->get_param( 'quartal' );
		$monat   = (int) $request->get_param( 'monat' );
		$modus   = (string) $request->get_param( 'modus' );

		if ( $quartal < 1 || $quartal > 4 ) {
			$quartal = (int) ceil( (int) gmdate( 'n', $now ) / 3 );
		}

		if ( $monat < 1 || $monat > 12 ) {
			$monat = (int) gmdate( 'n', $now );
		}

		return array(
			'period'  => self::resolve_period( $modus, $jahr, $quartal, $monat, (string) $request->get_param( 'von' ), (string) $request->get_param( 'bis' ) ),
			'quartal' => $quartal,
			'monat'   => $monat,
			'jahr'    => $jahr,
		);
	}

	/**
	 * EÜR eines Zeitraums, optional mit Vorjahresvergleich.
	 *
	 * @param WP_REST_Request $request Anfrage (modus, jahr, quartal, monat, von, bis, vergleich).
	 * @return WP_REST_Response
	 */
	public static function euer( WP_REST_Request $request ) {
		$p = self::period_from( $request );

		if ( '' !== $p['period']['error'] ) {
			return self::fail( $p['period']['error'] );
		}

		$period = $p['period'];
		$rep    = EuerCalculator::report( $period['von'], $period['bis'] );
		$gruppen = self::groups_from_accounts( (array) $rep['ausgaben_konten'] );
		$prev    = null;
		$vorjahr = null;
		$delta   = null;

		if ( '0' !== (string) $request->get_param( 'vergleich' ) ) {
			$prev = self::previous_period( $period, $p['quartal'], $p['monat'], $p['jahr'] );

			if ( $prev && '' === $prev['error'] ) {
				$prep    = EuerCalculator::report( $prev['von'], $prev['bis'] );
				$vorjahr = array(
					'von'       => $prev['von'],
					'bis'       => $prev['bis'],
					'label'     => $prev['label'],
					'einnahmen' => (float) $prep['einnahmen'],
					'ausgaben'  => (float) $prep['ausgaben_summe'],
					'gewinn'    => (float) $prep['gewinn'],
				);
				$delta   = self::year_compare( $rep, $prep );
				$gruppen = self::groups_compare( $gruppen, self::groups_from_accounts( (array) $prep['ausgaben_konten'] ) );
			}
		}

		$darf_export = Access::can( 'finanzen.export' );

		return new WP_REST_Response(
			array(
				'modus'            => $period['modus'],
				'jahr'             => $p['jahr'],
				'quartal'          => $p['quartal'],
				'monat'            => $p['monat'],
				'von'              => $period['von'],
				'bis'              => $period['bis'],
				'label'            => $period['label'],
				'einnahmen'        => (float) $rep['einnahmen'],
				'kuerzung'         => (float) $rep['ausgaben_kuerzung'],
				'ausgaben'         => (float) $rep['ausgaben_summe'],
				'gewinn'           => (float) $rep['gewinn'],
				'gruppen'          => $gruppen,
				'vorjahr'          => $vorjahr,
				'vergleich'        => $delta,
				'kleinunternehmer' => 1 === (int) Settings::get( 'kleinunternehmer', 1 ),
				'darf_export'      => $darf_export,
				'pdf_url'          => $darf_export ? wp_nonce_url(
					admin_url( 'admin-post.php?action=lw16_euer_pdf&from=' . rawurlencode( $period['von'] ) . '&to=' . rawurlencode( $period['bis'] ) ),
					'lw16_euer_pdf'
				) : '',
			)
		);
	}

	/**
	 * Belege einer Kontengruppe im Zeitraum (zum Ausklappen).
	 *
	 * Es zählen dieselben Belege wie in der EÜR: festgeschrieben, nicht
	 * storniert, keine Storno-Belege, nur Ausgabenkonten.
	 *
	 * @param WP_REST_Request $request Anfrage (von, bis, gruppe).
	 * @return WP_REST_Response
	 */
	public static function euer_buchungen( WP_REST_Request $request ) {
		$von    = Zahlen::clean_date( $request->get_param( 'von' ), '' );
		$bis    = Zahlen::clean_date( $request->get_param( 'bis' ), '' );
		$gruppe = trim( (string) $request->get_param( 'gruppe' ) );

		if ( '' === $von || '' === $bis || '' === $gruppe ) {
			return self::fail( __( 'Bitte Zeitraum und Kontengruppe angeben.', 'lindenweg16' ) );
		}

		$err = Zahlen::range_error( $von, $bis );

		if ( '' !== $err ) {
			return self::fail( $err );
		}

		$rows   = array();
		$gekuerzt = false;

		for ( $seite = 1; $seite <= 20; $seite++ ) {
			$batch = BelegRepository::query( array( 'from' => $von, 'to' => $bis, 'per_page' => 500, 'page' => $seite ) );

			foreach ( (array) $batch as $b ) {
				if ( 1 !== (int) $b->festgeschrieben || 1 === (int) $b->storniert || 'storno' === $b->quelle ) {
					continue;
				}

				$konto   = self::beleg_konto( isset( $b->konto ) ? $b->konto : '', isset( $b->eur_kategorie ) ? $b->eur_kategorie : '' );
				$eintrag = Kontenrahmen::get( $konto );

				if ( $eintrag && 'einnahme' === $eintrag['typ'] ) {
					continue;
				}

				if ( Kontenrahmen::gruppe( $konto ) !== $gruppe ) {
					continue;
				}

				$rows[] = array(
					'nummer'    => isset( $b->belegnummer ) && '' !== (string) $b->belegnummer ? (string) $b->belegnummer : '#' . $b->id,
					'datum'     => (string) $b->datum,
					'haendler'  => (string) $b->haendler,
					'konto'     => Kontenrahmen::label( $konto ),
					'betrag'    => (float) $b->betrag,
					'abziehbar' => Kontenrahmen::abziehbarer_betrag( $konto, (float) $b->betrag ),
				);
			}

			if ( count( (array) $batch ) < 500 ) {
				break;
			}

			if ( 20 === $seite ) {
				$gekuerzt = true;
			}
		}

		return new WP_REST_Response( array( 'rows' => $rows, 'gekuerzt' => $gekuerzt ) );
	}

	/**
	 * EÜR als CSV (Datei kommt als Text, die Oberfläche speichert sie).
	 *
	 * @param WP_REST_Request $request Anfrage wie bei euer().
	 * @return WP_REST_Response
	 */
	public static function euer_csv( WP_REST_Request $request ) {
		$p = self::period_from( $request );

		if ( '' !== $p['period']['error'] ) {
			return self::fail( $p['period']['error'] );
		}

		$period = $p['period'];
		$rep    = EuerCalculator::report( $period['von'], $period['bis'] );
		$rows   = array(
			array( 'Einnahmenüberschussrechnung', $period['von'] . ' bis ' . $period['bis'] ),
			array( 'Position', 'Betrag in EUR' ),
			array( 'Einnahmen (WooCommerce, abzgl. Rückerstattungen)', self::csv_num( $rep['einnahmen'] ) ),
		);

		foreach ( self::groups_from_accounts( (array) $rep['ausgaben_konten'] ) as $g ) {
			$rows[] = array( 'Ausgaben: ' . $g['name'], self::csv_num( $g['summe'] ) );

			foreach ( $g['konten'] as $k ) {
				$rows[] = array( '   ' . $k['label'], self::csv_num( $k['abziehbar'] ) );
			}
		}

		if ( (float) $rep['ausgaben_kuerzung'] > 0 ) {
			$rows[] = array( 'darin nicht abziehbarer Anteil (bereits abgezogen)', self::csv_num( $rep['ausgaben_kuerzung'] ) );
		}

		$rows[] = array( 'Ausgaben gesamt (abziehbar)', self::csv_num( $rep['ausgaben_summe'] ) );
		$rows[] = array( 'Gewinn', self::csv_num( $rep['gewinn'] ) );

		return new WP_REST_Response(
			array(
				'filename' => 'euer-' . $period['von'] . '_' . $period['bis'] . '.csv',
				'mime'     => 'text/csv;charset=utf-8',
				'text'     => Zahlen::csv( $rows ),
			)
		);
	}

	/**
	 * Zahl für die CSV mit Komma.
	 *
	 * @param float $n Zahl.
	 * @return string
	 */
	public static function csv_num( $n ) {
		return number_format( (float) $n, 2, ',', '' );
	}

	/* ====================================================================
	 * GoBD
	 * ================================================================== */

	/**
	 * Verlauf der Prüfungen mit aufbereiteten Prüfpunkten.
	 *
	 * @param int $limit Anzahl.
	 * @return array
	 */
	protected static function gobd_history( $limit = 10 ) {
		$out = array();

		foreach ( GobdChecker::get_results( $limit ) as $res ) {
			$punkte = json_decode( isset( $res->details ) ? (string) $res->details : '', true );

			$out[] = array(
				'datum'     => (string) $res->pruef_datum,
				'jahr'      => (int) $res->jahr,
				'ergebnis'  => (string) $res->ergebnis,
				'fehler'    => (int) $res->fehler_anzahl,
				'warnungen' => (int) $res->warn_anzahl,
				'punkte'    => self::gobd_points( is_array( $punkte ) ? $punkte : array() ),
			);
		}

		return $out;
	}

	/**
	 * Verlauf der GoBD-Prüfungen.
	 *
	 * @return WP_REST_Response
	 */
	public static function gobd() {
		return new WP_REST_Response( array( 'verlauf' => self::gobd_history(), 'jahr' => (int) gmdate( 'Y' ) ) );
	}

	/**
	 * GoBD-Prüfung starten (GobdChecker legt Ergebnis und Protokoll selbst ab).
	 *
	 * @param WP_REST_Request $request Anfrage (jahr).
	 * @return WP_REST_Response
	 */
	public static function gobd_pruefen( WP_REST_Request $request ) {
		$jahr = (int) $request->get_param( 'jahr' );

		if ( $jahr < 2020 || $jahr > 2099 ) {
			return self::fail( __( 'Bitte ein Jahr zwischen 2020 und 2099 angeben.', 'lindenweg16' ) );
		}

		$erg = GobdChecker::run( $jahr );

		return new WP_REST_Response(
			array(
				'ok'        => true,
				'jahr'      => $jahr,
				'status'    => (string) $erg['status'],
				'fehler'    => (int) $erg['fehler'],
				'warnungen' => (int) $erg['warnungen'],
				'punkte'    => self::gobd_points( isset( $erg['pruefungen'] ) ? (array) $erg['pruefungen'] : array() ),
				'verlauf'   => self::gobd_history(),
			)
		);
	}

	/* ====================================================================
	 * Export
	 * ================================================================== */

	/**
	 * Downloads und Inhalt der Exporte.
	 *
	 * @return WP_REST_Response
	 */
	public static function export_info() {
		$admin_post = admin_url( 'admin-post.php' );
		$jahr       = (int) gmdate( 'Y' );

		return new WP_REST_Response(
			array(
				'jahr'       => $jahr,
				'admin_post' => $admin_post,
				'bp_nonce'   => wp_create_nonce( 'lw16_bp_export' ),
				'doku_url'   => wp_nonce_url( add_query_arg( 'action', 'lw16_verfahrensdoku', $admin_post ), 'lw16_verfahrensdoku' ),
				'zip_ok'     => class_exists( 'ZipArchive' ),
				'frueher'    => self::export_history(),
				'dateien'    => self::export_files(),
			)
		);
	}

	/**
	 * Was im ZIP-Export steckt (entspricht ExportGenerator::download).
	 *
	 * @return array
	 */
	public static function export_files() {
		return array(
			array( 'name' => 'belege.csv', 'text' => __( 'Alle Belege des Zeitraums mit Nummer, Datum, Betrag, Konto und Status.', 'lindenweg16' ) ),
			array( 'name' => 'rechnungen.csv', 'text' => __( 'Alle Ausgangsrechnungen.', 'lindenweg16' ) ),
			array( 'name' => 'buchungen.csv', 'text' => __( 'Das Buchungsjournal mit lückenlosen Buchungsnummern.', 'lindenweg16' ) ),
			array( 'name' => 'audit_log.csv', 'text' => __( 'Das Änderungsprotokoll: wer hat wann was getan.', 'lindenweg16' ) ),
			array( 'name' => 'kassenbuch.csv', 'text' => __( 'Das Kassenbuch.', 'lindenweg16' ) ),
			array( 'name' => 'euer.csv', 'text' => __( 'Zusammenfassung der Einnahmenüberschussrechnung.', 'lindenweg16' ) ),
			array( 'name' => 'BESCHREIBUNG.txt', 'text' => __( 'Erklärung der Dateien und Spalten für den Prüfer.', 'lindenweg16' ) ),
			array( 'name' => 'INDEX.xml', 'text' => __( 'Maschinenlesbare Strukturbeschreibung für die Prüfsoftware.', 'lindenweg16' ) ),
		);
	}

	/**
	 * Bisherige Exporte aus dem Protokoll (Aktion „bp_export“).
	 *
	 * @param int $limit Anzahl.
	 * @return array Liste {zeit, von, bis}.
	 */
	protected static function export_history( $limit = 20 ) {
		global $wpdb;

		$t    = AuditLog::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT details, erstellt_am FROM {$t} WHERE aktion = %s ORDER BY id DESC LIMIT %d", 'bp_export', $limit ) ); // phpcs:ignore
		$out  = array();

		foreach ( (array) $rows as $r ) {
			$d = json_decode( (string) $r->details, true );

			if ( is_array( $d ) && ! empty( $d['from'] ) && ! empty( $d['to'] ) ) {
				$out[] = array(
					'zeit' => (string) $r->erstellt_am,
					'von'  => (string) $d['from'],
					'bis'  => (string) $d['to'],
				);
			}
		}

		return $out;
	}

	/* ====================================================================
	 * Jahresabschluss-Checkliste
	 * ================================================================== */

	/**
	 * Die Checkliste eines Jahres.
	 *
	 * Ohne `gebuehren=1` fehlt die (langsame) Gebührenprüfung: Die Zeile steht
	 * dann auf grau und die Oberfläche fragt gleich noch einmal mit
	 * `gebuehren=1`.
	 *
	 * @param WP_REST_Request $request Anfrage (jahr, gebuehren).
	 * @return WP_REST_Response
	 */
	public static function abschluss( WP_REST_Request $request ) {
		global $wpdb;

		$jahr  = self::year_param( $request->get_param( 'jahr' ) );
		$von   = $jahr . '-01-01';
		$bis   = $jahr . '-12-31';
		$belt  = BelegRepository::table();
		$zeilen = array();

		// 1. Offene Belege.
		$offen = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$belt} WHERE festgeschrieben = 0 AND storniert = 0 AND datum >= %s AND datum <= %s", $von, $bis ) ); // phpcs:ignore
		$zeilen[] = array_merge(
			array(
				'key'    => 'belege',
				'titel'  => __( 'Belege festgeschrieben', 'lindenweg16' ),
				'anzahl' => $offen,
				'aktion' => array( 'art' => 'reiter', 'ziel' => 'belege', 'label' => __( 'Zu den Belegen', 'lindenweg16' ) ),
			),
			self::lamp_open_receipts( $offen )
		);

		// 2. Fällige Dauerbuchungen.
		$faellig  = DauerbuchungRepository::count_due();
		$zeilen[] = array_merge(
			array(
				'key'    => 'dauer',
				'titel'  => __( 'Dauerbuchungen gebucht', 'lindenweg16' ),
				'anzahl' => $faellig,
				'aktion' => $faellig > 0 ? array( 'art' => 'post', 'route' => 'buchabschluss/dauerbuchungen-ausfuehren', 'label' => __( 'Fällige jetzt buchen', 'lindenweg16' ) ) : null,
			),
			self::lamp_recurring( $faellig )
		);

		// 3. AfA des Jahres.
		$afa      = self::afa_preview_for( $jahr );
		$zeilen[] = array_merge(
			array(
				'key'    => 'afa',
				'titel'  => __( 'Abschreibung (AfA) des Jahres gebucht', 'lindenweg16' ),
				'anzahl' => $afa['offen'],
				'aktion' => array( 'art' => 'reiter', 'ziel' => 'anlagen', 'label' => __( 'Zu den Anlagen', 'lindenweg16' ) ),
			),
			self::lamp_afa( $afa['offen'], $afa['gebucht'] )
		);

		// 4. Kassenbuch.
		$kbt   = KassenbuchRepository::table();
		$kb_n  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$kbt} WHERE datum >= %s AND datum <= %s", $von, $bis ) ); // phpcs:ignore
		$ende  = KassenbuchRepository::tagesabschluss( $bis );
		$kasse = self::audit_json( 'kassenbuch_jahresabschluss', $jahr );
		$kasse = ( $kasse && isset( $kasse['buchsaldo'], $kasse['gezaehlt'], $kasse['differenz'] ) ) ? $kasse : null;
		$zeilen[] = array_merge(
			array(
				'key'    => 'kasse',
				'titel'  => __( 'Kassenbuch abgeschlossen (Kassensturz)', 'lindenweg16' ),
				'anzahl' => $kb_n,
				'saldo'  => round( (float) $ende['saldo'], 2 ),
				'aktion' => $kb_n > 0 ? array( 'art' => 'kassensturz', 'label' => __( 'Kassensturz eintragen', 'lindenweg16' ) ) : null,
			),
			self::lamp_cash( $kb_n, (float) $ende['saldo'], $kasse )
		);

		// 5. GoBD-Prüfung des Jahres.
		$pruef = null;

		foreach ( GobdChecker::get_results( 100 ) as $r ) {
			if ( (int) $r->jahr === $jahr ) {
				$pruef = array(
					'datum'     => (string) $r->pruef_datum,
					'ergebnis'  => (string) $r->ergebnis,
					'fehler'    => (int) $r->fehler_anzahl,
					'warnungen' => (int) $r->warn_anzahl,
				);
				break;
			}
		}

		$neue = false;

		if ( $pruef ) {
			$neue = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$belt} WHERE erstellt_am > %s AND datum >= %s AND datum <= %s", $pruef['datum'], $von, $bis ) ) > 0; // phpcs:ignore
		}

		$zeilen[] = array_merge(
			array(
				'key'    => 'gobd',
				'titel'  => __( 'GoBD-Prüfung durchgeführt', 'lindenweg16' ),
				'datum'  => $pruef ? $pruef['datum'] : '',
				'aktion' => array( 'art' => 'reiter', 'ziel' => 'gobd', 'label' => __( 'Zur Prüfung', 'lindenweg16' ) ),
			),
			self::lamp_gobd( $pruef, $neue )
		);

		// 6. Zahlungsgebühren.
		$automatik = 0 !== (int) Settings::get( 'gebuehren_automatisch', 1 );
		$scan      = null;

		if ( '1' === (string) $request->get_param( 'gebuehren' ) ) {
			$scan = self::fee_scan( $jahr );
		}

		$gebuehr  = $scan && ! isset( $scan['error'] ) ? self::lamp_fees( count( $scan['fehlend'] ), $automatik, $scan['gekuerzt'] ) : self::lamp_fees( null, $automatik );

		if ( $scan && isset( $scan['error'] ) ) {
			$gebuehr = self::lamp( 'gelb', $scan['error'] );
		}

		$zeilen[] = array_merge(
			array(
				'key'     => 'gebuehren',
				'titel'   => __( 'Zahlungsgebühren vollständig', 'lindenweg16' ),
				'anzahl'  => $scan && ! isset( $scan['error'] ) ? count( $scan['fehlend'] ) : null,
				'geprueft' => $scan && ! isset( $scan['error'] ) ? $scan['geprueft'] : null,
				'aktion'  => array( 'art' => 'post', 'route' => 'buchabschluss/gebuehren-pruefen', 'label' => __( 'Erneut prüfen', 'lindenweg16' ) ),
			),
			$gebuehr
		);

		// 7. Export.
		$abdeckung = self::export_coverage( self::export_history( 50 ), $jahr );
		$zeilen[]  = array_merge(
			array(
				'key'    => 'export',
				'titel'  => __( 'Export für das Archiv erstellt', 'lindenweg16' ),
				'aktion' => Access::can( 'finanzen.export' ) ? array( 'art' => 'reiter', 'ziel' => 'export', 'label' => __( 'Zum Export', 'lindenweg16' ) ) : null,
			),
			self::lamp_export( $abdeckung )
		);

		return new WP_REST_Response(
			array(
				'jahr'   => $jahr,
				'gesamt' => self::lamp_overall( array_map( static function ( $z ) { return $z['status']; }, $zeilen ) ),
				'zeilen' => $zeilen,
			)
		);
	}

	/**
	 * Letzter Protokolleintrag einer Aktion für ein Jahr, als Liste.
	 *
	 * Die Details beginnen immer mit "jahr":JJJJ.
	 *
	 * @param string $aktion Aktion.
	 * @param int    $jahr   Jahr.
	 * @return array|null
	 */
	protected static function audit_json( $aktion, $jahr ) {
		global $wpdb;

		$t   = AuditLog::table();
		$row = $wpdb->get_var( $wpdb->prepare( "SELECT details FROM {$t} WHERE aktion = %s AND details LIKE %s ORDER BY id DESC LIMIT 1", $aktion, $wpdb->esc_like( '{"jahr":' . (int) $jahr . ',' ) . '%' ) ); // phpcs:ignore
		$d   = $row ? json_decode( (string) $row, true ) : null;

		return is_array( $d ) && isset( $d['jahr'] ) && (int) $d['jahr'] === (int) $jahr ? $d : null;
	}

	/**
	 * Fällige Dauerbuchungen ausführen (wie handle_execute_dauerbuchungen).
	 *
	 * @return WP_REST_Response
	 */
	public static function dauerbuchungen_ausfuehren() {
		$count = DauerbuchungRepository::execute_due();

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: %d number of executed bookings */
					__( '%d Dauerbuchung(en) ausgeführt.', 'lindenweg16' ),
					$count
				),
			)
		);
	}

	/**
	 * Kassensturz zum Jahresende eintragen (nur Protokolleintrag, das Kassenbuch bleibt unberührt).
	 *
	 * @param WP_REST_Request $request Anfrage (jahr, bargeld).
	 * @return WP_REST_Response
	 */
	public static function kassenbuch_bestaetigen( WP_REST_Request $request ) {
		$jahr   = (int) $request->get_param( 'jahr' );
		$gezahlt = self::parse_money( $request->get_param( 'bargeld' ) );

		if ( $jahr < 2020 || $jahr > 2099 ) {
			return self::fail( __( 'Bitte ein Jahr zwischen 2020 und 2099 angeben.', 'lindenweg16' ) );
		}

		if ( null === $gezahlt || $gezahlt < 0 ) {
			return self::fail( __( 'Bitte den gezählten Bargeldbestand als Betrag eintragen, zum Beispiel 125,50.', 'lindenweg16' ) );
		}

		$ende  = KassenbuchRepository::tagesabschluss( $jahr . '-12-31' );
		$saldo = round( (float) $ende['saldo'], 2 );
		$diff  = round( $gezahlt - $saldo, 2 );

		AuditLog::record(
			'kassenbuch_jahresabschluss',
			'kassenbuch',
			0,
			array(
				'jahr'      => $jahr,
				'buchsaldo' => $saldo,
				'gezaehlt'  => $gezahlt,
				'differenz' => $diff,
			)
		);

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => abs( $diff ) < 0.005
					? __( 'Kassensturz eingetragen: Der gezählte Bestand stimmt mit dem Kassenbuch überein.', 'lindenweg16' )
					: sprintf(
						/* translators: %s amount */
						__( 'Kassensturz eingetragen, aber es gibt eine Differenz von %s. Bitte die Ursache klären und im Kassenbuch nachtragen.', 'lindenweg16' ),
						self::eur_text( $diff )
					),
			)
		);
	}

	/**
	 * Bestellungen des Jahres, deren Zahlungsgebühr erkennbar, aber nicht gebucht ist.
	 *
	 * @param int $jahr Jahr.
	 * @return array {geprueft, fehlend:[{id,nummer,betrag,quelle}], gekuerzt} oder {error}.
	 */
	protected static function fee_scan( $jahr ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( 'error' => __( 'WooCommerce ist nicht aktiv, deshalb kann die Gebührenprüfung nicht laufen.', 'lindenweg16' ) );
		}

		$orders = wc_get_orders(
			array(
				'status'       => array( 'processing', 'completed', 'on-hold' ),
				'limit'        => self::FEE_SCAN_LIMIT,
				'date_created' => $jahr . '-01-01...' . $jahr . '-12-31',
				'return'       => 'objects',
			)
		);

		$fehlend = array();

		foreach ( (array) $orders as $order ) {
			if ( BuchungRepository::get_by_referenz( 'gebuehr', (int) $order->get_id() ) ) {
				continue;
			}

			$fee = FeeExtractor::for_order( $order );

			if ( null === $fee['amount'] ) {
				continue;
			}

			$fehlend[] = array(
				'id'     => (int) $order->get_id(),
				'nummer' => (string) $order->get_order_number(),
				'betrag' => (float) $fee['amount'],
				'quelle' => (string) $fee['source'],
			);
		}

		return array(
			'geprueft' => count( (array) $orders ),
			'fehlend'  => $fehlend,
			'gekuerzt' => count( (array) $orders ) >= self::FEE_SCAN_LIMIT,
		);
	}

	/**
	 * Gebühren erneut suchen und buchen (wie handle_fee_rescan, für alle Bestellungen des Jahres).
	 *
	 * Die Buchung selbst ist gegen Doppelung gesichert
	 * (`BuchungRepository::create_from_order_fee`).
	 *
	 * @param WP_REST_Request $request Anfrage (jahr).
	 * @return WP_REST_Response
	 */
	public static function gebuehren_pruefen( WP_REST_Request $request ) {
		$jahr = self::year_param( $request->get_param( 'jahr' ) );

		if ( 0 === (int) Settings::get( 'gebuehren_automatisch', 1 ) ) {
			return self::fail( __( 'Die automatische Gebührenerkennung ist in den Einstellungen ausgeschaltet. Bitte erst einschalten, dann kann hier gebucht werden.', 'lindenweg16' ) );
		}

		$scan = self::fee_scan( $jahr );

		if ( isset( $scan['error'] ) ) {
			return self::fail( $scan['error'] );
		}

		$modul  = new AccountingModule();
		$gebucht = 0;

		foreach ( array_slice( $scan['fehlend'], 0, 200 ) as $f ) {
			if ( $modul->capture_order_fee( $f['id'] ) ) {
				$gebucht++;
			}
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'anzahl'  => $gebucht,
				'message' => $gebucht > 0
					? sprintf(
						/* translators: %d number */
						__( '%d Zahlungsgebühr(en) gefunden und gebucht.', 'lindenweg16' ),
						$gebucht
					)
					: __( 'Keine neue Zahlungsgebühr gefunden – entweder steht keine in den Hinweisen, oder sie ist bereits gebucht.', 'lindenweg16' ),
			)
		);
	}
}
