<?php
/**
 * ERP-Bereich „Zahlen“: Dashboard Finanzen, Berichte, Reporting, Buchhaltung.
 *
 * Die Seiten laufen nativ in /erp. Fachliche Regeln, Rechte und Protokoll
 * kommen unverändert aus den Modulen (Betrieb: Reporting, Kpi,
 * KpiGoalRepository; Accounting: Repositories, EuerCalculator, GobdChecker;
 * Support: Audit-Log). Hier stehen nur die REST-Routen und kleine, reine
 * Regeln (Datum, Seite, Filter, CSV), die sich ohne WordPress testen lassen.
 *
 * Buchhaltung (GoBD): nativ nur lesend (Belege, Rechnungen, Journal, EÜR,
 * Ergebnisse der Prüfung) plus die Prüfung selbst und die Downloads. Erfassen,
 * Festschreiben und Stornieren bleiben in der klassischen Ansicht, damit
 * Unveränderbarkeit, Storno statt Löschen, Hash-Kette und Protokoll dort
 * weiter an genau einer Stelle gelten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Settings;
use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Accounting\AccountingDashboard;
use Lindenweg16\Modules\Accounting\BelegRepository;
use Lindenweg16\Modules\Accounting\BuchungRepository;
use Lindenweg16\Modules\Accounting\EuerCalculator;
use Lindenweg16\Modules\Accounting\GobdChecker;
use Lindenweg16\Modules\Accounting\InvoiceGenerator;
use Lindenweg16\Modules\Accounting\Kontenrahmen;
use Lindenweg16\Modules\Betrieb\AnalyseExport;
use Lindenweg16\Modules\Betrieb\Kpi;
use Lindenweg16\Modules\Betrieb\KpiGoalRepository;
use Lindenweg16\Modules\Betrieb\KpiGoals;
use Lindenweg16\Modules\Betrieb\KpiMeter;
use Lindenweg16\Modules\Betrieb\Period;
use Lindenweg16\Modules\Betrieb\Reporting;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen und Regeln für den Bereich Zahlen.
 */
class Zahlen {

	/**
	 * Einträge je Seite in Listen (wie die alten Seiten).
	 */
	const PER_PAGE = 50;

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns = RestApi::NAMESPACE_V1;

		self::get( $ns, '/erp/zahlen/dashboard', 'dashboard', $api->needs( array( 'finanzen.dashboard' ) ) );

		self::get( $ns, '/erp/zahlen/berichte', 'berichte', $api->needs( array( 'finanzen.berichte' ) ) );
		self::get( $ns, '/erp/zahlen/berichte/export', 'berichte_export', $api->needs( array( 'finanzen.berichte' ) ) );
		self::post( $ns, '/erp/zahlen/berichte/ziele', 'ziele_speichern', $api->needs( array( 'finanzen.berichte', 'verwaltung.einstellungen' ) ) );
		self::post( $ns, '/erp/zahlen/berichte/abschluss', 'monat_abschliessen', $api->needs( array( 'finanzen.berichte' ) ) );

		self::get( $ns, '/erp/zahlen/reporting', 'reporting', $api->needs( array( 'finanzen.reporting' ) ) );
		self::post( $ns, '/erp/zahlen/reporting/datenexport', 'datenexport_senden', $api->needs( array( 'finanzen.reporting', 'verwaltung.support' ) ) );

		$buch = $api->needs( array( 'finanzen.buchhaltung' ) );
		self::get( $ns, '/erp/zahlen/buchhaltung', 'buchhaltung', $buch );
		self::get( $ns, '/erp/zahlen/buchhaltung/belege', 'belege', $buch );
		self::get( $ns, '/erp/zahlen/buchhaltung/rechnungen', 'rechnungen', $buch );
		self::get( $ns, '/erp/zahlen/buchhaltung/journal', 'journal', $buch );
		self::get( $ns, '/erp/zahlen/buchhaltung/euer', 'euer', $buch );
		self::get( $ns, '/erp/zahlen/buchhaltung/gobd', 'gobd', $buch );
		self::post( $ns, '/erp/zahlen/buchhaltung/gobd', 'gobd_pruefen', $buch );
	}

	/**
	 * Lese-Route anmelden.
	 *
	 * @param string   $ns       Namensraum.
	 * @param string   $route    Pfad.
	 * @param string   $method   Methode dieser Klasse.
	 * @param callable $perm     Rechteprüfung.
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
	 * Reine Regeln (ohne Datenbank, getestet in tests/ErpZahlenTest.php)
	 * ================================================================== */

	/**
	 * Ein gültiges Datum Y-m-d, sonst die Vorgabe.
	 *
	 * @param mixed  $value    Eingabe.
	 * @param string $fallback Vorgabe.
	 * @return string
	 */
	public static function clean_date( $value, $fallback ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return $fallback;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : $fallback;
	}

	/**
	 * Passt der Zeitraum? Leer = ja, sonst der Hinweis an die Person.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return string
	 */
	public static function range_error( $von, $bis ) {
		if ( $von > $bis ) {
			return __( 'Das Startdatum liegt nach dem Enddatum. Bitte den Zeitraum richtig herum wählen.', 'lindenweg16' );
		}

		return '';
	}

	/**
	 * Ein gültiges Jahr, sonst das aktuelle.
	 *
	 * @param mixed $value Eingabe.
	 * @param int   $now   Aktuelles Jahr.
	 * @return int
	 */
	public static function clean_year( $value, $now ) {
		$year = (int) $value;

		return ( $year >= 2000 && $year <= 2100 ) ? $year : (int) $now;
	}

	/**
	 * Jahre zur Auswahl: zwei zurück, eines voraus (wie die alte Seite).
	 *
	 * @param int $year Gewähltes Jahr.
	 * @return int[]
	 */
	public static function year_choices( $year ) {
		return range( (int) $year - 2, (int) $year + 1 );
	}

	/**
	 * Seitenzahl ab 1.
	 *
	 * @param mixed $value Eingabe.
	 * @return int
	 */
	public static function clean_page( $value ) {
		return max( 1, (int) $value );
	}

	/**
	 * Anzahl Seiten einer Liste.
	 *
	 * @param int $total    Einträge.
	 * @param int $per_page Je Seite.
	 * @return int
	 */
	public static function page_count( $total, $per_page = self::PER_PAGE ) {
		return max( 1, (int) ceil( max( 0, (int) $total ) / max( 1, (int) $per_page ) ) );
	}

	/**
	 * Marge in Prozent der Einnahmen; ohne Einnahmen gibt es keine.
	 *
	 * @param float $einnahmen Einnahmen.
	 * @param float $gewinn    Gewinn.
	 * @return float|null
	 */
	public static function margin( $einnahmen, $gewinn ) {
		$einnahmen = (float) $einnahmen;

		return $einnahmen > 0 ? round( (float) $gewinn / $einnahmen * 100, 1 ) : null;
	}

	/**
	 * Kennzahlen, die die Person sehen darf. Geldgrößen nur mit dem Kostenrecht;
	 * die Ausschussquote bleibt, sie sagt etwas über die Arbeit, nicht über den Ertrag.
	 *
	 * @param array $definitions Alle Kennzahlen.
	 * @param bool  $costs       Darf die Person Preise und Kosten sehen?
	 * @return array
	 */
	public static function visible_kpis( array $definitions, $costs ) {
		if ( $costs ) {
			return $definitions;
		}

		return array_filter(
			$definitions,
			static function ( $definition ) {
				return '€' !== ( isset( $definition['unit'] ) ? $definition['unit'] : '' );
			}
		);
	}

	/**
	 * Ein Zielwert als Eingabewert, ohne Tausenderpunkte.
	 *
	 * @param float $wert Wert.
	 * @return string
	 */
	public static function goal_input( $wert ) {
		return rtrim( rtrim( number_format( (float) $wert, 2, ',', '' ), '0' ), ',' );
	}

	/**
	 * Steht der abgeleitete Wert für den Zeitraum gesondert da?
	 *
	 * Nur wenn er sich vom wirksamen Wert (eingetragen, sonst Vorgabe) unterscheidet;
	 * bei Verhältnisgrößen ist er derselbe und die Zeile wiederholte sich nur.
	 *
	 * @param float|null $abgeleitet Abgeleiteter Wert.
	 * @param float|null $gespeichert Eingetragener Wert.
	 * @param float|null $vorgabe    Vorgabe.
	 * @return bool
	 */
	public static function shows_derived( $abgeleitet, $gespeichert, $vorgabe ) {
		$wirksam = null === $gespeichert ? $vorgabe : $gespeichert;

		return null !== $abgeleitet && ( null === $wirksam || abs( (float) $abgeleitet - (float) $wirksam ) > 0.005 );
	}

	/**
	 * Text einer Bewertungsstufe.
	 *
	 * @param string $level Stufe.
	 * @return string
	 */
	public static function level_label( $level ) {
		$labels = array(
			'gruen'   => __( 'erreicht', 'lindenweg16' ),
			'rot'     => __( 'Alarm', 'lindenweg16' ),
			'gelb'    => __( 'beobachten', 'lindenweg16' ),
			'neutral' => __( 'kein Ziel', 'lindenweg16' ),
		);

		return isset( $labels[ $level ] ) ? $labels[ $level ] : (string) $level;
	}

	/**
	 * Zeilen der Kennzahlen-CSV (wie der Export der alten Seite), auf die erlaubten Kennzahlen begrenzt.
	 *
	 * @param array $metrics     Kennzahl => Wert.
	 * @param array $definitions Erlaubte Kennzahlen.
	 * @return array
	 */
	public static function kpi_rows( array $metrics, array $definitions ) {
		$rows = array( array( 'Kennzahl', 'Wert', 'Einheit', 'Ziel', 'Alarm', 'Status' ) );

		foreach ( $definitions as $key => $definition ) {
			$value      = isset( $metrics[ $key ] ) ? $metrics[ $key ] : null;
			$evaluation = Kpi::evaluate( $key, $value );

			$rows[] = array(
				$definition['label'],
				null === $value ? '' : round( $value, 4 ),
				$definition['unit'],
				$definition['target'],
				$definition['alarm'],
				$evaluation['level'],
			);
		}

		return $rows;
	}

	/**
	 * CSV-Text mit Semikolon und BOM (damit Excel die Umlaute richtig liest).
	 *
	 * @param array $rows Zeilen.
	 * @return string
	 */
	public static function csv( array $rows ) {
		$out = fopen( 'php://memory', 'w+' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		foreach ( $rows as $row ) {
			fputcsv( $out, $row, ';' );
		}

		rewind( $out );
		$text = (string) stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $text;
	}

	/**
	 * Dateiname eines Exports.
	 *
	 * @param string $was   kennzahlen oder analyse.
	 * @param string $datum Datum Y-m-d.
	 * @return string
	 */
	public static function export_filename( $was, $datum ) {
		return 'analyse' === $was ? 'lw16-analyse-' . $datum . '.md' : 'lw16-kennzahlen-' . $datum . '.csv';
	}

	/**
	 * Status eines Belegs aus den Merkern der Zeile.
	 *
	 * @param bool $storniert       Storniert?
	 * @param bool $festgeschrieben Festgeschrieben?
	 * @return string storniert, festgeschrieben oder offen.
	 */
	public static function beleg_status( $storniert, $festgeschrieben ) {
		if ( $storniert ) {
			return 'storniert';
		}

		return $festgeschrieben ? 'festgeschrieben' : 'offen';
	}

	/**
	 * Beschriftung der Beleg-Quelle.
	 *
	 * @param string $quelle Quelle.
	 * @return string
	 */
	public static function source_label( $quelle ) {
		switch ( (string) $quelle ) {
			case 'wareneingang':
				return __( 'Wareneingang', 'lindenweg16' );
			case 'erechnung':
				return __( 'E-Rechnung', 'lindenweg16' );
			case 'storno':
				return __( 'Storno', 'lindenweg16' );
		}

		return __( 'manuell', 'lindenweg16' );
	}

	/**
	 * Kunden eines Zeitraums nach Umsatz, höchstens fünfzig.
	 *
	 * @param array $orders Zeilen {customer_id, email, name, total}.
	 * @return array Liste {name, count, total}, größter Umsatz zuerst.
	 */
	public static function vip_list( array $orders ) {
		$agg = array();

		foreach ( $orders as $order ) {
			$uid = (int) $order['customer_id'];
			$key = $uid ? $uid : 'g_' . $order['email'];

			if ( ! isset( $agg[ $key ] ) ) {
				$name        = trim( (string) $order['name'] );
				$agg[ $key ] = array(
					'name'  => '' !== $name ? $name : __( 'Gast', 'lindenweg16' ),
					'count' => 0,
					'total' => 0.0,
				);
			}

			$agg[ $key ]['count']++;
			$agg[ $key ]['total'] += (float) $order['total'];
		}

		usort(
			$agg,
			static function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);

		return array_slice( $agg, 0, 50 );
	}

	/**
	 * Zahl, Umsatz und Durchschnitt einer Bestellreihe.
	 *
	 * @param float[] $totals Bestellsummen.
	 * @return array {count, umsatz, avg}
	 */
	public static function order_stats( array $totals ) {
		$count  = count( $totals );
		$umsatz = array_sum( array_map( 'floatval', $totals ) );

		return array(
			'count'  => $count,
			'umsatz' => (float) $umsatz,
			'avg'    => $count > 0 ? $umsatz / $count : 0.0,
		);
	}

	/**
	 * Monat und Jahr für den Monatsabschluss prüfen. Leer = in Ordnung.
	 *
	 * @param int $year  Jahr.
	 * @param int $month Monat.
	 * @return string
	 */
	public static function freeze_error( $year, $month ) {
		if ( $year < 2000 || $year > 2100 ) {
			return __( 'Bitte ein Jahr zwischen 2000 und 2100 angeben.', 'lindenweg16' );
		}

		if ( $month < 1 || $month > 12 ) {
			return __( 'Bitte einen Monat von 1 bis 12 angeben.', 'lindenweg16' );
		}

		return '';
	}

	/**
	 * Ausgabenkategorien absteigend nach Betrag.
	 *
	 * @param array $groups Name => Betrag.
	 * @return array Liste {name, summe}.
	 */
	public static function sorted_groups( array $groups ) {
		arsort( $groups );
		$out = array();

		foreach ( $groups as $name => $summe ) {
			$out[] = array(
				'name'  => (string) $name,
				'summe' => (float) $summe,
			);
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
	 * Zahl für JSON: Unendliches und NaN werden null.
	 *
	 * @param mixed $value Wert.
	 * @return float|null
	 */
	protected static function num( $value ) {
		return ( null === $value || ! is_numeric( $value ) || ! is_finite( (float) $value ) ) ? null : (float) $value;
	}

	/* ====================================================================
	 * Dashboard Finanzen
	 * ================================================================== */

	/**
	 * Kennzahlen-Übersicht.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function dashboard( WP_REST_Request $request ) {
		$now   = (int) gmdate( 'Y' );
		$jahr  = self::clean_year( $request->get_param( 'jahr' ), $now );
		$stats = AccountingDashboard::get_stats( $jahr );

		$cache = 'lw16_dashboard_kpis';
		$kpis  = get_transient( $cache );

		if ( false === $kpis ) {
			$kpis = ( new \Lindenweg16\Admin\Dashboard() )->calculate_kpis();
			set_transient( $cache, $kpis, 15 * MINUTE_IN_SECONDS );
		}

		$satz      = (float) Settings::get( 'steuersatz_ruecklage', 25 );
		$ruecklage = EuerCalculator::ruecklage( $satz );

		$monate = array();
		foreach ( $stats['monate'] as $m => $mon ) {
			$monate[] = array(
				'monat'     => (int) $m,
				'einnahmen' => (float) $mon['einnahmen'],
				'ausgaben'  => (float) $mon['ausgaben'],
				'gewinn'    => (float) $mon['gewinn'],
			);
		}

		return new WP_REST_Response(
			array(
				'jahr'                    => $jahr,
				'jahre'                   => self::year_choices( $jahr ),
				'einnahmen'               => (float) $stats['einnahmen'],
				'ausgaben'                => (float) $stats['ausgaben'],
				'gewinn'                  => (float) $stats['gewinn'],
				'marge'                   => self::margin( $stats['einnahmen'], $stats['gewinn'] ),
				'offene_belege'           => (int) $stats['offene_belege'],
				'anlagevermoegen'         => (float) $stats['anlagevermoegen'],
				'kassensaldo'             => (float) $stats['kassensaldo'],
				'faellige_dauerbuchungen' => (int) $stats['faellige_dauerbuchungen'],
				'monate'                  => $monate,
				'kategorien'              => self::sorted_groups( (array) $stats['ausgaben_kategorien'] ),
				'betrieb'                 => array(
					'umsatz_monat'        => (float) $kpis['umsatz_monat'],
					'umsatz_jahr'         => (float) $kpis['umsatz_jahr'],
					'offene_bestellungen' => (int) $kpis['offene_bestellungen'],
					'bestellungen_heute'  => (int) $kpis['bestellungen_heute'],
					'lagerwarnungen'      => (int) $kpis['lagerwarnungen'],
					'offene_tickets'      => (int) $kpis['offene_tickets'],
					'offene_picklisten'   => (int) $kpis['offene_picklisten'],
				),
				'ruecklage'               => array(
					'betrag' => (float) $ruecklage['ruecklage'],
					'gewinn' => (float) $ruecklage['gewinn'],
					'satz'   => $satz,
				),
				'kleinunternehmer'        => 1 === (int) Settings::get( 'kleinunternehmer', 1 ),
				'lager'                   => self::low_stock(),
				'bestellungen'            => self::recent_orders(),
			)
		);
	}

	/**
	 * Produkte unter dem Mindestbestand (wie das Lagerwarnungen-Widget der alten Seite).
	 *
	 * @return array
	 */
	protected static function low_stock() {
		$out = array();
		$map = \Lindenweg16\Modules\Betrieb\StockOwnership::legacy_map();

		foreach ( \Lindenweg16\Modules\Inventory\Repository::get_products() as $p ) {
			// Von Betrieb geführte Produkte: Ihr alter Bestand ist eingefroren.
			if ( \Lindenweg16\Modules\Betrieb\StockOwnership::superseded( (int) $p->id, $map ) ) {
				continue;
			}

			$ampel = \Lindenweg16\Modules\Inventory\StockManager::ampel( $p );

			if ( 'gruen' === $ampel ) {
				continue;
			}

			$out[] = array(
				'name'    => (string) $p->name,
				'ampel'   => $ampel,
				'bestand' => (float) \Lindenweg16\Modules\Inventory\Repository::get_stock( $p->id ),
				'einheit' => (string) $p->basis_einheit,
				'min'     => (float) $p->mindestbestand,
			);
		}

		return $out;
	}

	/**
	 * Die fünf letzten Bestellungen.
	 *
	 * @return array
	 */
	protected static function recent_orders() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$out    = array();
		$orders = wc_get_orders(
			array(
				'limit'   => 5,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'objects',
			)
		);

		foreach ( (array) $orders as $o ) {
			$out[] = array(
				'nummer'  => (string) $o->get_order_number(),
				'kunde'   => (string) $o->get_formatted_billing_full_name(),
				'datum'   => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'd.m.Y H:i' ) : '',
				'betrag'  => (float) $o->get_total(),
				'status'  => (string) wc_get_order_status_name( $o->get_status() ),
				'url'     => (string) $o->get_edit_order_url(),
			);
		}

		return $out;
	}

	/* ====================================================================
	 * Berichte (Betrieb)
	 * ================================================================== */

	/**
	 * Kennzahlen, Verlauf und Ziele.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function berichte( WP_REST_Request $request ) {
		$costs = Access::can( 'finanzen.kosten' );
		$von   = self::clean_date( $request->get_param( 'von' ), gmdate( 'Y-m-01' ) );
		$bis   = self::clean_date( $request->get_param( 'bis' ), gmdate( 'Y-m-d' ) );
		$error = self::range_error( $von, $bis );

		if ( '' !== $error ) {
			return self::fail( $error );
		}

		$raster      = Period::clean( sanitize_key( (string) $request->get_param( 'raster' ) ) );
		$gewaehlt    = sanitize_key( (string) $request->get_param( 'kennzahl' ) );
		$definitions = self::visible_kpis( Kpi::definitions(), $costs );
		$alle_ziele  = KpiGoalRepository::all();
		$darf_ziele  = Access::can( 'verwaltung.einstellungen' );
		$quartal     = self::goal_quarter( (string) $request->get_param( 'quartal' ), $bis );
		$gespeichert = $darf_ziele ? KpiGoalRepository::for_quarter( $quartal['key'] ) : array();
		$metrics     = Reporting::metrics( $von, $bis );
		$zeitraum    = array(
			'von' => $von,
			'bis' => $bis,
		);

		$kpis = array();

		foreach ( $definitions as $key => $definition ) {
			$value   = isset( $metrics[ $key ] ) ? $metrics[ $key ] : null;
			$grenzen = Reporting::limits( $key, $zeitraum, isset( $alle_ziele[ $key ] ) ? $alle_ziele[ $key ] : array() );
			$eval    = Kpi::evaluate_against( $key, $value, $grenzen['target'], $grenzen['alarm'] );

			$row = array(
				'key'          => $key,
				'label'        => $definition['label'],
				'unit'         => $definition['unit'],
				'value'        => self::num( $value ),
				'text'         => Kpi::format( $key, $value ),
				'target'       => $grenzen['target'],
				'target_text'  => null === $grenzen['target'] ? '—' : Kpi::format( $key, $grenzen['target'] ),
				'alarm'        => $grenzen['alarm'],
				'alarm_text'   => null === $grenzen['alarm'] ? '—' : Kpi::format( $key, $grenzen['alarm'] ),
				'level'        => $eval['level'],
				'level_text'   => self::level_label( $eval['level'] ),
				'meter'        => KpiMeter::scale( $value, $grenzen['target'], $grenzen['alarm'] ),
			);

			if ( $darf_ziele ) {
				$goal = array();

				foreach ( array( 'target', 'alarm' ) as $spalte ) {
					$wert    = isset( $gespeichert[ $key ][ $spalte ] ) ? $gespeichert[ $key ][ $spalte ] : null;
					$vorgabe = isset( $definition[ $spalte ] ) ? $definition[ $spalte ] : null;

					$goal[ $spalte ] = array(
						'value'       => null === $wert ? '' : self::goal_input( $wert ),
						'placeholder' => null === $vorgabe ? __( 'kein Ziel', 'lindenweg16' ) : self::goal_input( $vorgabe ),
						'derived'     => self::shows_derived( $grenzen[ $spalte ], $wert, $vorgabe )
							/* translators: %s: Wert im Zeitraum. */
							? sprintf( __( 'im Zeitraum: %s', 'lindenweg16' ), Kpi::format( $key, $grenzen[ $spalte ] ) )
							: '',
					);
				}

				$row['goal'] = $goal;
			}

			$kpis[] = $row;
		}

		$raster_liste = array();
		foreach ( Period::labels() as $key => $label ) {
			$raster_liste[] = array(
				'key'   => $key,
				'label' => $label,
			);
		}

		$kennzahlen = array();
		foreach ( $definitions as $key => $definition ) {
			$kennzahlen[] = array(
				'key'   => $key,
				'label' => $definition['label'],
			);
		}

		if ( $definitions && ! isset( $definitions[ $gewaehlt ] ) ) {
			$schluessel = array_keys( $definitions );
			$gewaehlt   = $schluessel[0];
		}

		return new WP_REST_Response(
			array(
				'von'         => $von,
				'bis'         => $bis,
				'raster'      => $raster,
				'raster_liste' => $raster_liste,
				'kennzahl'    => $gewaehlt,
				'kennzahlen'  => $kennzahlen,
				'trend'       => $definitions ? self::trend( $gewaehlt, $definitions[ $gewaehlt ], $raster, $bis ) : null,
				'kpis'        => $kpis,
				'quartal'     => array(
					'key'   => $quartal['key'],
					'label' => $quartal['label'],
				),
				'quartale'    => $darf_ziele ? self::quarter_choices( $quartal ) : array(),
				'can_goals'   => $darf_ziele,
				'can_costs'   => $costs,
				'erklaerung'  => __( 'Ziel und Alarmgrenze trägst du hier ein, je Quartal – an einer Stelle, nicht zweimal. Beim Wechsel des Zeitrasters rechnet das System sie um: Summengrößen wie Umsatz und Bestellungen werden nach Tagen verteilt, Verhältnisgrößen wie Quoten und Durchschnitte gelten unverändert. Ein früheres Quartal behält seine Ziele, auch wenn du die Latte später höher legst.', 'lindenweg16' ),
			)
		);
	}

	/**
	 * Das Quartal, dessen Ziele bearbeitet werden.
	 *
	 * @param string $key Gewünschter Quartalsschlüssel (leer = das des Bis-Datums).
	 * @param string $bis Bis-Datum.
	 * @return array
	 */
	protected static function goal_quarter( $key, $bis ) {
		$quartal = Period::quarter_bounds( sanitize_text_field( $key ) );

		return $quartal ? $quartal : Period::bucket( 'quartal', $bis );
	}

	/**
	 * Vier Quartale zurück und eines voraus: Wer plant, trägt das nächste ein, bevor es beginnt.
	 *
	 * @param array $aktuell Gewähltes Quartal.
	 * @return array Liste {key, label}.
	 */
	protected static function quarter_choices( array $aktuell ) {
		$start = Period::bucket( 'quartal', $aktuell['von'] );
		$out   = array();

		for ( $i = -4; $i <= 1; $i++ ) {
			$q = $start;

			for ( $j = 0; $j < abs( $i ); $j++ ) {
				$q = $i < 0
					? Period::previous( 'quartal', $q )
					: Period::bucket( 'quartal', gmdate( 'Y-m-d', strtotime( $q['bis'] . ' 12:00:00 UTC' ) + DAY_IN_SECONDS ) );
			}

			$out[] = array(
				'key'   => $q['key'],
				'label' => $q['label'],
			);
		}

		return $out;
	}

	/**
	 * Verlauf einer Kennzahl über das gewählte Raster.
	 *
	 * @param string $metric     Kennzahl.
	 * @param array  $definition Definition.
	 * @param string $raster     Raster.
	 * @param string $bis        Letztes Datum.
	 * @return array
	 */
	protected static function trend( $metric, array $definition, $raster, $bis ) {
		$buckets = Period::series( $raster, $bis, Period::default_count( $raster ) );
		$reihen  = Reporting::series( $buckets );
		$ziele   = KpiGoalRepository::for_metric( $metric );
		$kind    = KpiGoals::kind( $metric );
		$punkte  = array();

		foreach ( $buckets as $bucket ) {
			$wert    = isset( $reihen[ $bucket['key'] ][ $metric ] ) ? $reihen[ $bucket['key'] ][ $metric ] : null;
			$grenzen = Reporting::limits( $metric, $bucket, $ziele );
			$eval    = Kpi::evaluate_against( $metric, $wert, $grenzen['target'], $grenzen['alarm'] );
			$text    = $bucket['label'] . ': ' . Kpi::format( $metric, $wert );

			if ( null !== $grenzen['target'] ) {
				/* translators: %s: Zielwert. */
				$text .= ' — ' . sprintf( __( 'Ziel %s', 'lindenweg16' ), Kpi::format( $metric, $grenzen['target'] ) );
			}

			if ( null !== $grenzen['alarm'] ) {
				/* translators: %s: Alarmgrenze. */
				$text .= ', ' . sprintf( __( 'Alarm %s', 'lindenweg16' ), Kpi::format( $metric, $grenzen['alarm'] ) );
			}

			$punkte[] = array(
				'label'       => $bucket['label'],
				'value'       => self::num( $wert ),
				'text_value'  => Kpi::format( $metric, $wert ),
				'target'      => $grenzen['target'],
				'target_text' => null === $grenzen['target'] ? '—' : Kpi::format( $metric, $grenzen['target'] ),
				'alarm'       => $grenzen['alarm'],
				'alarm_text'  => null === $grenzen['alarm'] ? '—' : Kpi::format( $metric, $grenzen['alarm'] ),
				'level'       => $eval['level'],
				'level_text'  => self::level_label( $eval['level'] ),
				'text'        => $text,
			);
		}

		return array(
			'label'      => $definition['label'],
			'unit'       => $definition['unit'],
			'punkte'     => $punkte,
			'erklaerung' => KpiGoals::explain( $kind ) . ' ' . __( 'Ziel- und Alarmlinie liegen nur über den Zeiträumen, für die ein Quartalsziel eingetragen ist – für die übrigen wird keines erfunden.', 'lindenweg16' ),
		);
	}

	/**
	 * Export als Datei: Kennzahlen-CSV oder Auszug zur Analyse.
	 *
	 * Die Datei geht als Text an die Oberfläche, die sie als Download anbietet.
	 * Rechte wie bei der alten Seite: die CSV braucht die Berichte, der Auszug
	 * enthält Herstellkosten, Preise und Umsätze und braucht deshalb das
	 * Kostenrecht. Die CSV enthält ohne Kostenrecht keine Geldgrößen (die
	 * alte Seite ließ sie ungefiltert durch).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function berichte_export( WP_REST_Request $request ) {
		$was   = sanitize_key( (string) $request->get_param( 'was' ) );
		$von   = self::clean_date( $request->get_param( 'von' ), gmdate( 'Y-m-01' ) );
		$bis   = self::clean_date( $request->get_param( 'bis' ), gmdate( 'Y-m-d' ) );
		$costs = Access::can( 'finanzen.kosten' );
		$error = self::range_error( $von, $bis );

		if ( '' !== $error ) {
			return self::fail( $error );
		}

		if ( 'analyse' === $was ) {
			if ( ! $costs ) {
				return self::fail( __( 'Der Auszug zur Analyse enthält Preise und Kosten. Dafür brauchst du das Recht „Preise, Kosten und Löhne sehen“.', 'lindenweg16' ), 403 );
			}

			$text = AnalyseExport::render( AnalyseExport::collect( $von, $bis, '1' === (string) $request->get_param( 'namen' ) ) );

			return new WP_REST_Response(
				array(
					'filename' => self::export_filename( 'analyse', gmdate( 'Y-m-d' ) ),
					'mime'     => 'text/markdown;charset=utf-8',
					'text'     => $text,
				)
			);
		}

		if ( 'kennzahlen' !== $was ) {
			return self::fail( __( 'Diesen Export gibt es nicht. Bitte „Kennzahlen“ oder „Auszug zur Analyse“ wählen.', 'lindenweg16' ) );
		}

		$rows = self::kpi_rows( Reporting::metrics( $von, $bis ), self::visible_kpis( Kpi::definitions(), $costs ) );

		return new WP_REST_Response(
			array(
				'filename' => self::export_filename( 'kennzahlen', gmdate( 'Y-m-d' ) ),
				'mime'     => 'text/csv;charset=utf-8',
				'text'     => self::csv( $rows ),
			)
		);
	}

	/**
	 * Quartalsziele speichern (Protokoll schreibt das Repository).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function ziele_speichern( WP_REST_Request $request ) {
		$quartal = sanitize_text_field( (string) $request->get_param( 'quartal' ) );
		$roh     = $request->get_param( 'ziel' );
		$ein     = array();

		foreach ( is_array( $roh ) ? $roh : array() as $metric => $werte ) {
			if ( ! is_array( $werte ) ) {
				continue;
			}

			$ein[ sanitize_key( $metric ) ] = array(
				'target' => isset( $werte['target'] ) ? sanitize_text_field( (string) $werte['target'] ) : '',
				'alarm'  => isset( $werte['alarm'] ) ? sanitize_text_field( (string) $werte['alarm'] ) : '',
			);
		}

		$result = KpiGoalRepository::save(
			$quartal,
			$ein,
			array_keys( self::visible_kpis( Kpi::definitions(), Access::can( 'finanzen.kosten' ) ) )
		);

		if ( empty( $result['success'] ) ) {
			return self::fail( (string) $result['message'] );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => (string) $result['message'] ) );
	}

	/**
	 * Monatsabschluss: Kennzahlen des Monats einfrieren (Protokoll schreibt Reporting).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function monat_abschliessen( WP_REST_Request $request ) {
		$year  = (int) $request->get_param( 'year' );
		$month = (int) $request->get_param( 'month' );
		$error = self::freeze_error( $year, $month );

		if ( '' !== $error ) {
			return self::fail( $error );
		}

		$count = Reporting::freeze_month( $year, $month );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				/* translators: %d: Anzahl. */
				'message' => sprintf( __( 'Monatsabschluss: %d Kennzahlen eingefroren.', 'lindenweg16' ), $count ),
			)
		);
	}

	/* ====================================================================
	 * Reporting (Support-Modul): Kunden, Bestellstatistik, Datensicherung, Protokoll
	 * ================================================================== */

	/**
	 * Reporting-Seite.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function reporting( WP_REST_Request $request ) {
		$jahr  = (int) gmdate( 'Y' );
		$von   = self::clean_date( $request->get_param( 'von' ), $jahr . '-01-01' );
		$bis   = self::clean_date( $request->get_param( 'bis' ), $jahr . '-12-31' );
		$error = self::range_error( $von, $bis );

		if ( '' !== $error ) {
			return self::fail( $error );
		}

		$seite = self::clean_page( $request->get_param( 'seite' ) );
		$total = AuditLog::count();
		$log   = array();

		foreach ( AuditLog::get_page( self::PER_PAGE, $seite ) as $r ) {
			$user  = $r->user_id ? get_userdata( $r->user_id ) : null;
			$log[] = array(
				'zeit'    => (string) $r->erstellt_am,
				'nutzer'  => $user ? (string) $user->display_name : ( $r->user_id ? __( 'Gelöschte Person', 'lindenweg16' ) : __( 'System', 'lindenweg16' ) ),
				'aktion'  => (string) $r->aktion,
				'objekt'  => trim( $r->objekt_typ . ' ' . ( $r->objekt_id ? '#' . $r->objekt_id : '' ) ),
				'details' => (string) $r->details,
			);
		}

		$mail = (string) Settings::get( 'export_email', get_bloginfo( 'admin_email' ) );

		return new WP_REST_Response(
			array(
				'von'        => $von,
				'bis'        => $bis,
				'vips'       => self::vip_customers( $von, $bis ),
				'statistik'  => self::order_statistics(),
				'woocommerce' => function_exists( 'wc_get_orders' ),
				'export'     => array(
					'darf'      => Access::can( 'verwaltung.support' ),
					'empfaenger' => $mail,
				),
				'log'        => $log,
				'seite'      => $seite,
				'seiten'     => self::page_count( $total ),
				'gesamt'     => $total,
			)
		);
	}

	/**
	 * VIP-Kunden eines Zeitraums, mit demselben Zwischenspeicher wie die alte Seite.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return array
	 */
	protected static function vip_customers( $von, $bis ) {
		$cache_key = 'lw16_vip_' . md5( $von . $bis );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$rows = array();

		foreach ( (array) wc_get_orders(
			array(
				'status'       => array( 'wc-completed' ),
				'date_created' => $von . '...' . $bis,
				'limit'        => -1,
				'return'       => 'objects',
			)
		) as $order ) {
			$rows[] = array(
				'customer_id' => (int) $order->get_customer_id(),
				'email'       => (string) $order->get_billing_email(),
				'name'        => (string) $order->get_formatted_billing_full_name(),
				'total'       => (float) $order->get_total(),
			);
		}

		$list = self::vip_list( $rows );
		set_transient( $cache_key, $list, 30 * MINUTE_IN_SECONDS );

		return $list;
	}

	/**
	 * Bestellstatistik: heute, 7 und 30 Tage.
	 *
	 * @return array
	 */
	protected static function order_statistics() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$now     = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$periods = array(
			__( 'Heute', 'lindenweg16' )          => gmdate( 'Y-m-d', $now ),
			__( 'Letzte 7 Tage', 'lindenweg16' )  => gmdate( 'Y-m-d', $now - 7 * DAY_IN_SECONDS ),
			__( 'Letzte 30 Tage', 'lindenweg16' ) => gmdate( 'Y-m-d', $now - 30 * DAY_IN_SECONDS ),
		);
		$out     = array();

		foreach ( $periods as $label => $since ) {
			$totals = array();

			foreach ( (array) wc_get_orders(
				array(
					'status'       => array( 'wc-completed', 'wc-processing' ),
					'date_created' => '>=' . strtotime( $since ),
					'limit'        => -1,
					'return'       => 'objects',
				)
			) as $o ) {
				$totals[] = (float) $o->get_total();
			}

			$stat  = self::order_stats( $totals );
			$out[] = array(
				'zeitraum' => $label,
				'anzahl'   => $stat['count'],
				'umsatz'   => $stat['umsatz'],
				'schnitt'  => $stat['avg'],
			);
		}

		return $out;
	}

	/**
	 * Datenexport sofort per E-Mail senden (wie der Knopf der alten Seite).
	 *
	 * Die alte Seite verlangte dafür das Support-Recht; hier zusätzlich das
	 * Reporting-Recht der Seite.
	 *
	 * @return WP_REST_Response
	 */
	public static function datenexport_senden() {
		$email = (string) Settings::get( 'export_email', get_bloginfo( 'admin_email' ) );

		if ( ! is_email( $email ) ) {
			return self::fail( __( 'Es ist keine gültige Empfängeradresse für den Datenexport eingetragen. Bitte in den Einstellungen die E-Mail-Adresse für den Export prüfen.', 'lindenweg16' ) );
		}

		( new \Lindenweg16\Modules\Support\SupportModule() )->weekly_export();

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Datenexport per E-Mail versendet.', 'lindenweg16' ),
			)
		);
	}

	/* ====================================================================
	 * Buchhaltung (GoBD): nur lesend, dazu Prüfung und Downloads
	 * ================================================================== */

	/**
	 * Einstieg: Downloads (mit einmaligen Schlüsseln) und Hinweise.
	 *
	 * @return WP_REST_Response
	 */
	public static function buchhaltung() {
		$admin_post = admin_url( 'admin-post.php' );
		$jahr       = (int) gmdate( 'Y' );

		return new WP_REST_Response(
			array(
				'jahr'             => $jahr,
				'admin_post'       => $admin_post,
				'bp_nonce'         => wp_create_nonce( 'lw16_bp_export' ),
				'doku_url'         => wp_nonce_url( add_query_arg( 'action', 'lw16_verfahrensdoku', $admin_post ), 'lw16_verfahrensdoku' ),
				'kleinunternehmer' => 1 === (int) Settings::get( 'kleinunternehmer', 1 ),
			)
		);
	}

	/**
	 * Belegliste.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function belege( WP_REST_Request $request ) {
		$seite = self::clean_page( $request->get_param( 'seite' ) );
		$total = BelegRepository::count();
		$rows  = array();

		foreach ( BelegRepository::query( array( 'per_page' => self::PER_PAGE, 'page' => $seite ) ) as $b ) {
			$status = self::beleg_status( isset( $b->storniert ) && 1 === (int) $b->storniert, 1 === (int) $b->festgeschrieben );
			$rows[] = array(
				'nummer'    => isset( $b->belegnummer ) && '' !== (string) $b->belegnummer ? (string) $b->belegnummer : '#' . $b->id,
				'datum'     => (string) $b->datum,
				'haendler'  => (string) $b->haendler,
				'kategorie' => $b->konto ? Kontenrahmen::label( $b->konto ) : (string) $b->eur_kategorie,
				'betrag'    => (float) $b->betrag,
				'quelle'    => self::source_label( $b->quelle ),
				'status'    => $status,
				'datei'     => $b->datei_pfad ? wp_nonce_url( admin_url( 'admin-post.php?action=lw16_beleg_datei&id=' . (int) $b->id ), 'lw16_beleg_datei_' . (int) $b->id ) : '',
			);
		}

		return new WP_REST_Response(
			array(
				'rows'   => $rows,
				'seite'  => $seite,
				'seiten' => self::page_count( $total ),
				'gesamt' => $total,
			)
		);
	}

	/**
	 * Rechnungsliste.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function rechnungen( WP_REST_Request $request ) {
		$seite = self::clean_page( $request->get_param( 'seite' ) );
		$total = InvoiceGenerator::count();
		$rows  = array();

		foreach ( InvoiceGenerator::query( array( 'per_page' => self::PER_PAGE, 'page' => $seite ) ) as $r ) {
			$rows[] = array(
				'nummer'     => (string) $r->rechnungsnummer,
				'bestellung' => (string) $r->order_id,
				'betrag'     => (float) $r->betrag,
				'erstellt'   => (string) $r->erstellt_am,
				'storniert'  => isset( $r->storniert ) && 1 === (int) $r->storniert,
				'url'        => wp_nonce_url( admin_url( 'admin-post.php?action=lw16_rechnung&id=' . (int) $r->id ), 'lw16_rechnung_' . (int) $r->id ),
			);
		}

		return new WP_REST_Response(
			array(
				'rows'   => $rows,
				'seite'  => $seite,
				'seiten' => self::page_count( $total ),
				'gesamt' => $total,
			)
		);
	}

	/**
	 * Buchungsjournal.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function journal( WP_REST_Request $request ) {
		$seite = self::clean_page( $request->get_param( 'seite' ) );
		$total = BuchungRepository::count();
		$rows  = array();

		foreach ( BuchungRepository::query( array( 'per_page' => self::PER_PAGE, 'page' => $seite ) ) as $bu ) {
			$rows[] = array(
				'nummer'       => (string) $bu->buchungsnummer,
				'datum'        => (string) $bu->buchungsdatum,
				'typ'          => (string) $bu->typ,
				'betrag'       => (float) $bu->betrag,
				'kategorie'    => (string) $bu->kategorie,
				'beschreibung' => mb_substr( (string) $bu->beschreibung, 0, 80 ),
				'storniert'    => 1 === (int) $bu->storniert,
			);
		}

		return new WP_REST_Response(
			array(
				'rows'   => $rows,
				'seite'  => $seite,
				'seiten' => self::page_count( $total ),
				'gesamt' => $total,
			)
		);
	}

	/**
	 * Einnahmenüberschussrechnung eines Zeitraums.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function euer( WP_REST_Request $request ) {
		$jahr  = (int) gmdate( 'Y' );
		$von   = self::clean_date( $request->get_param( 'von' ), $jahr . '-01-01' );
		$bis   = self::clean_date( $request->get_param( 'bis' ), $jahr . '-12-31' );
		$error = self::range_error( $von, $bis );

		if ( '' !== $error ) {
			return self::fail( $error );
		}

		$rep = EuerCalculator::report( $von, $bis );

		return new WP_REST_Response(
			array(
				'von'              => $von,
				'bis'              => $bis,
				'einnahmen'        => (float) $rep['einnahmen'],
				'gruppen'          => self::groups_in_order( (array) $rep['ausgaben_gruppen'] ),
				'kuerzung'         => (float) $rep['ausgaben_kuerzung'],
				'ausgaben'         => (float) $rep['ausgaben_summe'],
				'gewinn'           => (float) $rep['gewinn'],
				'kleinunternehmer' => 1 === (int) Settings::get( 'kleinunternehmer', 1 ),
				'pdf_url'          => wp_nonce_url(
					admin_url( 'admin-post.php?action=lw16_euer_pdf&from=' . rawurlencode( $von ) . '&to=' . rawurlencode( $bis ) ),
					'lw16_euer_pdf'
				),
			)
		);
	}

	/**
	 * Gruppen in der Reihenfolge der alten Seite (nicht sortiert).
	 *
	 * @param array $groups Name => Betrag.
	 * @return array Liste {name, summe}.
	 */
	protected static function groups_in_order( array $groups ) {
		$out = array();

		foreach ( $groups as $name => $summe ) {
			$out[] = array(
				'name'  => (string) $name,
				'summe' => (float) $summe,
			);
		}

		return $out;
	}

	/**
	 * Letzte Ergebnisse der GoBD-Prüfung.
	 *
	 * @return WP_REST_Response
	 */
	public static function gobd() {
		return new WP_REST_Response( array( 'ergebnisse' => self::gobd_results() ) );
	}

	/**
	 * Die fünf letzten Prüfergebnisse.
	 *
	 * @return array
	 */
	protected static function gobd_results() {
		$out = array();

		foreach ( GobdChecker::get_results( 5 ) as $res ) {
			$out[] = array(
				'datum'     => (string) $res->pruef_datum,
				'jahr'      => (string) $res->jahr,
				'ergebnis'  => (string) $res->ergebnis,
				'fehler'    => (int) $res->fehler_anzahl,
				'warnungen' => (int) $res->warn_anzahl,
			);
		}

		return $out;
	}

	/**
	 * GoBD-Prüfung starten (wie die alte Seite; die Prüfung legt ihr Ergebnis selbst ab).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function gobd_pruefen( WP_REST_Request $request ) {
		$jahr = (int) $request->get_param( 'jahr' );

		if ( $jahr < 2020 || $jahr > 2099 ) {
			return self::fail( __( 'Bitte ein Jahr zwischen 2020 und 2099 angeben.', 'lindenweg16' ) );
		}

		$ergebnis = GobdChecker::run( $jahr );

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'status'     => (string) $ergebnis['status'],
				'message'    => sprintf(
					/* translators: 1: Jahr, 2: Ergebnis, 3: Fehler, 4: Warnungen. */
					__( 'GoBD-Prüfung %1$d: %2$s (%3$d Fehler, %4$d Warnungen)', 'lindenweg16' ),
					$jahr,
					strtoupper( (string) $ergebnis['status'] ),
					(int) $ergebnis['fehler'],
					(int) $ergebnis['warnungen']
				),
				'pruefungen' => isset( $ergebnis['pruefungen'] ) ? $ergebnis['pruefungen'] : array(),
				'ergebnisse' => self::gobd_results(),
			)
		);
	}
}
