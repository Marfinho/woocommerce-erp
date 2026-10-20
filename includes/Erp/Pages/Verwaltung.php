<?php
/**
 * Verwaltung im ERP: Zeiterfassung, Löhne und Protokoll, Etikettendrucker,
 * Einstellungen.
 *
 * Die Seiten ersetzen die eingebetteten wp-admin-Seiten. Sie rufen dieselben
 * Repositories auf wie die alten Seiten und schreiben dieselben
 * Protokolleinträge (BetriebAudit). Reine Regeln stehen oben als statische
 * Methoden ohne Datenbank, damit sie sich testen lassen; die Routen unten
 * bringen nur die Abfragen mit.
 *
 * Der Import (`import`) bleibt bewusst eine eingebettete Seite: CSV-Upload mit
 * Spaltenzuordnung und Vorschau über mehrere Aufrufe, dazu Migration und
 * Verfahrensdokumentation – das ist ein eigenes Vorhaben.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Admin\SettingsPage as PluginSettingsPage;
use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Core\Settings;
use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\AccessProfiles;
use Lindenweg16\Modules\Betrieb\BetriebAudit;
use Lindenweg16\Modules\Betrieb\CalcSettings;
use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\Consistency;
use Lindenweg16\Modules\Betrieb\IsoWeek;
use Lindenweg16\Modules\Betrieb\LabelSheet;
use Lindenweg16\Modules\Betrieb\LaborCost;
use Lindenweg16\Modules\Betrieb\PriceTierRepository;
use Lindenweg16\Modules\Betrieb\PrintQueue\PrinterRepository;
use Lindenweg16\Modules\Betrieb\PrintQueue\PrintJobRepository;
use Lindenweg16\Modules\Betrieb\PrintQueue\PrintQueueService;
use Lindenweg16\Modules\Betrieb\Stopwatch;
use Lindenweg16\Modules\Betrieb\StockOwnership;
use Lindenweg16\Modules\Betrieb\TimeRepository;
use Lindenweg16\Modules\Betrieb\TimerState;
use Lindenweg16\Modules\Betrieb\UserActivity;
use Lindenweg16\Modules\Betrieb\WageRepository;
use Lindenweg16\Core\Roles;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Seiten der Verwaltung.
 */
class Verwaltung {

	/**
	 * Richtwert Wochenstunden (wie auf der alten Zeiterfassung).
	 */
	const BUDGET_HOURS = 14.0;

	/**
	 * Darüber färbt sich die Wochensumme rot.
	 */
	const RED_HOURS = 25.0;

	/* ====================================================================
	 * Reine Regeln
	 * ================================================================== */

	/**
	 * Zahl aus einer Eingabe lesen, deutsch oder englisch geschrieben.
	 *
	 * „1,5“ und „1.5“ sind 1,5; „1.000,50“ ist 1000,5; „1.000“ ist 1000.
	 *
	 * @param mixed $raw Eingabe.
	 * @return float|null Null, wenn es keine Zahl ist.
	 */
	public static function parse_number( $raw ) {
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return (float) $raw;
		}

		$text = preg_replace( '/[\s\x{00A0}]+/u', '', (string) $raw );

		if ( '' === $text || null === $text ) {
			return null;
		}

		if ( preg_match( '/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $text ) ) {
			$text = str_replace( '.', '', $text );
			$text = str_replace( ',', '.', $text );
		} elseif ( preg_match( '/^-?\d+([.,]\d+)?$/', $text ) ) {
			$text = str_replace( ',', '.', $text );
		} else {
			return null;
		}

		return (float) $text;
	}

	/**
	 * Datum als Y-m-d prüfen.
	 *
	 * @param mixed  $raw     Eingabe.
	 * @param string $default Wert, wenn die Eingabe leer ist.
	 * @return string|null Null, wenn die Eingabe kein echtes Datum ist.
	 */
	public static function normalize_date( $raw, $default = '' ) {
		$text = trim( (string) $raw );

		if ( '' === $text ) {
			return $default;
		}

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}

		return $text;
	}

	/**
	 * Zeitraum: von/bis, sonst der laufende Monat bis heute.
	 *
	 * @param mixed  $von   Anfang.
	 * @param mixed  $bis   Ende.
	 * @param string $heute Heute als Y-m-d.
	 * @return array {von, bis}
	 */
	public static function period( $von, $bis, $heute ) {
		$default_von = substr( $heute, 0, 8 ) . '01';

		$von = self::normalize_date( $von, $default_von );
		$bis = self::normalize_date( $bis, $heute );

		$von = null === $von ? $default_von : $von;
		$bis = null === $bis ? $heute : $bis;

		if ( $von > $bis ) {
			return array( 'von' => $bis, 'bis' => $von );
		}

		return array( 'von' => $von, 'bis' => $bis );
	}

	/**
	 * Ampel der Wochensumme.
	 *
	 * @param float $hours Stunden.
	 * @return string gruen, gelb oder rot.
	 */
	public static function week_ampel( $hours ) {
		$hours = (float) $hours;

		if ( $hours > self::RED_HOURS ) {
			return 'rot';
		}

		return $hours > self::BUDGET_HOURS ? 'gelb' : 'gruen';
	}

	/**
	 * Stunden je ISO-Woche, neueste zuerst.
	 *
	 * @param array $entries Zeilen mit iso_year, iso_week, hours.
	 * @param int   $limit   Höchstens so viele Wochen.
	 * @return array Liste aus year, week, hours, ampel.
	 */
	public static function week_rows( array $entries, $limit = 8 ) {
		$sum = array();

		foreach ( $entries as $entry ) {
			$entry = (array) $entry;
			$key   = sprintf( '%04d-%02d', (int) $entry['iso_year'], (int) $entry['iso_week'] );

			$sum[ $key ] = ( isset( $sum[ $key ] ) ? $sum[ $key ] : 0.0 ) + (float) $entry['hours'];
		}

		krsort( $sum );

		$out = array();

		foreach ( array_slice( $sum, 0, max( 1, (int) $limit ), true ) as $key => $hours ) {
			$hours = round( $hours, 2 );
			$out[] = array(
				'year'  => (int) substr( $key, 0, 4 ),
				'week'  => (int) substr( $key, 5 ),
				'hours' => $hours,
				'ampel' => self::week_ampel( $hours ),
			);
		}

		return $out;
	}

	/**
	 * Hinweis zur laufenden Uhr: vergessen, versehentlich oder in Ordnung.
	 *
	 * @param bool $bookable    Mindestens eine Minute.
	 * @param bool $implausible Länger als zehn Stunden.
	 * @return array {code, text}
	 */
	public static function stop_hint( $bookable, $implausible ) {
		if ( $implausible ) {
			return array(
				'code' => 'vergessen',
				'text' => __( 'Das sieht nach einer vergessenen Uhr aus. Trag die tatsächlich gearbeitete Zeit ein, bevor du erfasst – die Vorgabe ist die volle Laufzeit.', 'lindenweg16' ),
			);
		}

		if ( ! $bookable ) {
			return array(
				'code' => 'kurz',
				'text' => __( 'Unter einer Minute – vermutlich ein Fehlklick. Du kannst die Uhr verwerfen, statt sie zu buchen.', 'lindenweg16' ),
			);
		}

		return array( 'code' => '', 'text' => '' );
	}

	/**
	 * Eine Kalkulationsannahme aus der Eingabe lesen.
	 *
	 * @param mixed $raw Eingabe.
	 * @return array {value, error}
	 */
	public static function parse_assumption( $raw ) {
		if ( '' === trim( (string) $raw ) ) {
			return array( 'value' => null, 'error' => __( 'Bitte eine Zahl eintragen.', 'lindenweg16' ) );
		}

		$value = self::parse_number( $raw );

		if ( null === $value ) {
			return array( 'value' => null, 'error' => __( 'Das ist keine Zahl – bitte nur Ziffern und ein Komma oder einen Punkt verwenden.', 'lindenweg16' ) );
		}

		if ( $value < 0 ) {
			return array( 'value' => null, 'error' => __( 'Negative Werte sind hier nicht sinnvoll.', 'lindenweg16' ) );
		}

		return array( 'value' => $value, 'error' => '' );
	}

	/**
	 * Drucker-ID prüfen: Kleinbuchstaben, Ziffern, Unter- und Bindestrich.
	 *
	 * @param mixed $raw Eingabe.
	 * @return array {id, error}
	 */
	public static function printer_id( $raw ) {
		$text = trim( (string) $raw );

		if ( '' === $text ) {
			return array( 'id' => '', 'error' => __( 'Bitte eine Drucker-ID eintragen, zum Beispiel eg_dhl.', 'lindenweg16' ) );
		}

		if ( ! preg_match( '/^[a-z0-9_\-]+$/', $text ) ) {
			return array( 'id' => '', 'error' => __( 'Die Drucker-ID darf nur Kleinbuchstaben, Ziffern, Unterstrich und Bindestrich enthalten, zum Beispiel eg_dhl.', 'lindenweg16' ) );
		}

		return array( 'id' => $text, 'error' => '' );
	}

	/**
	 * Was darf man mit einem Druckauftrag tun?
	 *
	 * Abbrechen nur, solange ihn noch kein Drucker abgeholt hat – bei einem
	 * laufenden Druck wüsste der Pi nichts davon. Erneut senden, wenn er
	 * nicht mehr in Arbeit ist.
	 *
	 * @param string $status Status.
	 * @return array {erneut, abbrechen}
	 */
	public static function job_actions( $status ) {
		$status = (string) $status;

		return array(
			'erneut'    => in_array( $status, array( 'failed', 'cancelled', 'printed' ), true ),
			'abbrechen' => 'queued' === $status,
		);
	}

	/**
	 * Ist ein Einstellungsfeld geheim und darf nie ausgegeben werden?
	 *
	 * @param string $key  Schlüssel.
	 * @param string $type Feldtyp der alten Seite.
	 * @return bool
	 */
	public static function is_secret_key( $key, $type = '' ) {
		if ( 'password' === $type ) {
			return true;
		}

		return 1 === preg_match( '/(api_key|secret|passwort|password|token)/i', (string) $key );
	}

	/**
	 * Wert eines Einstellungsfelds nach Typ prüfen.
	 *
	 * Text bleibt Text; die Bereinigung dafür übernimmt WordPress beim
	 * Speichern.
	 *
	 * @param string $type Feldtyp: text, textarea, number, checkbox, color.
	 * @param mixed  $raw  Eingabe.
	 * @return array {ok, value, error}
	 */
	public static function plugin_value( $type, $raw ) {
		if ( 'checkbox' === $type ) {
			$on = true === $raw || 1 === $raw || '1' === $raw || 'true' === $raw || 'on' === $raw;

			return array( 'ok' => true, 'value' => $on ? 1 : 0, 'error' => '' );
		}

		if ( 'number' === $type ) {
			if ( '' === trim( (string) $raw ) ) {
				return array( 'ok' => true, 'value' => 0, 'error' => '' );
			}

			$number = self::parse_number( $raw );

			if ( null === $number ) {
				return array( 'ok' => false, 'value' => null, 'error' => __( 'Das ist keine Zahl – bitte nur Ziffern und ein Komma oder einen Punkt verwenden.', 'lindenweg16' ) );
			}

			return array( 'ok' => true, 'value' => $number, 'error' => '' );
		}

		if ( 'color' === $type ) {
			$text = trim( (string) $raw );

			if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $text ) ) {
				return array( 'ok' => false, 'value' => null, 'error' => __( 'Bitte eine Farbe im Format #rrggbb eintragen, zum Beispiel #8B6914.', 'lindenweg16' ) );
			}

			return array( 'ok' => true, 'value' => strtolower( $text ), 'error' => '' );
		}

		return array( 'ok' => true, 'value' => is_scalar( $raw ) ? (string) $raw : '', 'error' => '' );
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
		$ns = RestApi::NAMESPACE_V1;

		$zeit = $api->needs( array( 'verwaltung.zeit' ) );

		register_rest_route( $ns, '/erp/zeit', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'zeit' ), 'permission_callback' => $zeit ) );
		register_rest_route( $ns, '/erp/zeit/start', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'zeit_start' ), 'permission_callback' => $zeit ) );
		register_rest_route( $ns, '/erp/zeit/uhr', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'zeit_uhr' ), 'permission_callback' => $zeit ) );
		register_rest_route( $ns, '/erp/zeit/pause', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'zeit_pause' ), 'permission_callback' => $zeit ) );
		register_rest_route( $ns, '/erp/zeit/weiter', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'zeit_weiter' ), 'permission_callback' => $zeit ) );
		register_rest_route( $ns, '/erp/zeit/stopp', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'zeit_stopp' ), 'permission_callback' => $zeit ) );
		register_rest_route( $ns, '/erp/zeit/verwerfen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'zeit_verwerfen' ), 'permission_callback' => $zeit ) );
		register_rest_route( $ns, '/erp/zeit/eintrag', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'zeit_eintrag' ), 'permission_callback' => $zeit ) );

		$loehne = $api->needs( array( 'verwaltung.loehne' ) );

		register_rest_route( $ns, '/erp/loehne', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'loehne' ), 'permission_callback' => $loehne ) );
		register_rest_route( $ns, '/erp/loehne/nutzer/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'loehne_nutzer' ), 'permission_callback' => $loehne ) );
		register_rest_route( $ns, '/erp/loehne/lohn', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'loehne_lohn' ), 'permission_callback' => $loehne ) );

		$etiketten = $api->needs( array( 'verwaltung.etiketten' ) );

		register_rest_route( $ns, '/erp/etiketten', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'etiketten' ), 'permission_callback' => $etiketten ) );
		register_rest_route( $ns, '/erp/etiketten/drucker', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'drucker_anlegen' ), 'permission_callback' => $etiketten ) );
		register_rest_route( $ns, '/erp/etiketten/drucker/(?P<id>\d+)/token', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'drucker_token' ), 'permission_callback' => $etiketten ) );
		register_rest_route( $ns, '/erp/etiketten/drucker/(?P<id>\d+)/test', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'drucker_test' ), 'permission_callback' => $etiketten ) );
		register_rest_route( $ns, '/erp/etiketten/auftraege', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'auftraege' ), 'permission_callback' => $etiketten ) );
		register_rest_route( $ns, '/erp/etiketten/auftraege/(?P<id>\d+)/erneut', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'auftrag_erneut' ), 'permission_callback' => $etiketten ) );
		register_rest_route( $ns, '/erp/etiketten/auftraege/(?P<id>\d+)/abbrechen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'auftrag_abbrechen' ), 'permission_callback' => $etiketten ) );

		$einstellungen = $api->needs( array( 'verwaltung.einstellungen' ) );

		register_rest_route( $ns, '/erp/einstellungen/annahmen', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'annahmen' ), 'permission_callback' => $einstellungen ) );
		register_rest_route( $ns, '/erp/einstellungen/annahme', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'annahme_speichern' ), 'permission_callback' => $einstellungen ) );
		register_rest_route( $ns, '/erp/einstellungen/staffel', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'staffel' ), 'permission_callback' => $einstellungen ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'staffel_speichern' ), 'permission_callback' => $einstellungen ),
		) );
		register_rest_route( $ns, '/erp/einstellungen/lager', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'lager' ), 'permission_callback' => $einstellungen ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'lager_speichern' ), 'permission_callback' => $einstellungen ),
		) );
		register_rest_route( $ns, '/erp/einstellungen/protokoll', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'protokoll' ), 'permission_callback' => $einstellungen ) );
		register_rest_route( $ns, '/erp/einstellungen/systempruefung', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'systempruefung' ), 'permission_callback' => $einstellungen ) );
		register_rest_route( $ns, '/erp/einstellungen/plugin', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'plugin' ), 'permission_callback' => $einstellungen ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'plugin_speichern' ), 'permission_callback' => $einstellungen ),
		) );
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
	 * Eingaben der Anfrage (JSON oder Formular).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array
	 */
	protected static function body( WP_REST_Request $request ) {
		$json = $request->get_json_params();

		return is_array( $json ) ? $json : (array) $request->get_params();
	}

	/**
	 * Text aus einer Eingabe.
	 *
	 * @param array  $p   Eingaben.
	 * @param string $key Schlüssel.
	 * @return string
	 */
	protected static function str( array $p, $key ) {
		return isset( $p[ $key ] ) && is_scalar( $p[ $key ] ) ? trim( (string) $p[ $key ] ) : '';
	}

	/**
	 * Heute in der Zeitzone des Shops.
	 *
	 * @return string
	 */
	protected static function today() {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * Zeitstempel aus einem GMT-Datum der Datenbank.
	 *
	 * @param string|null $gmt Datum.
	 * @return int Null (0), wenn leer.
	 */
	protected static function ts( $gmt ) {
		if ( empty( $gmt ) ) {
			return 0;
		}

		$ts = strtotime( $gmt . ' UTC' );

		return $ts ? (int) $ts : 0;
	}

	/* ====================================================================
	 * 1. Zeiterfassung
	 * ================================================================== */

	/**
	 * Stand der Stempeluhr für die Oberfläche.
	 *
	 * @return array|null
	 */
	protected static function clock() {
		$stopp = TimerState::stop_form();

		if ( ! $stopp ) {
			return null;
		}

		$hint        = self::stop_hint( $stopp['bookable'], $stopp['implausible'] );
		$suggestion  = $stopp['suggestion'];
		$category    = $suggestion['confident'] && '' !== $suggestion['category'] ? $suggestion['category'] : 'sonstiges';

		return array(
			'seconds'     => (int) $stopp['seconds'],
			'paused'      => ! empty( $stopp['paused'] ),
			'hours'       => (float) $stopp['hours'],
			'started_at'  => self::ts( $stopp['started_at'] ),
			'entry_date'  => $stopp['entry_date'],
			'category'    => $category,
			'confident'   => (bool) $suggestion['confident'],
			'explanation' => (string) $stopp['explanation'],
			'hint'        => $hint,
		);
	}

	/**
	 * Eigene Zeit: Uhr, Woche, Wochensummen.
	 *
	 * @param WP_REST_Request $request Anfrage (woche = 0, -1, …).
	 * @return WP_REST_Response
	 */
	public static function zeit( WP_REST_Request $request ) {
		$offset = max( -52, min( 0, (int) $request->get_param( 'woche' ) ) );
		$today  = self::today();
		$iso    = IsoWeek::from_date( $today );
		$week   = IsoWeek::shift( $iso['iso_year'], $iso['iso_week'], $offset );
		$bounds = IsoWeek::bounds( $week['iso_year'], $week['iso_week'] );

		$mine = TimeRepository::for_user( get_current_user_id(), 500 );

		$rows  = array();
		$total = 0.0;

		foreach ( $mine as $entry ) {
			if ( (int) $entry['iso_year'] !== $week['iso_year'] || (int) $entry['iso_week'] !== $week['iso_week'] ) {
				continue;
			}

			$total += (float) $entry['hours'];
			$rows[] = array(
				'id'          => (int) $entry['id'],
				'date'        => $entry['entry_date'],
				'category'    => $entry['category'],
				'description' => (string) $entry['description'],
				'hours'       => (float) $entry['hours'],
				'block'       => (string) $entry['phase_block'],
			);
		}

		$weeks = array();
		foreach ( self::week_rows( $mine, 8 ) as $row ) {
			$row['label'] = IsoWeek::label( $row['year'], $row['week'] );
			$weeks[]      = $row;
		}

		$categories = array();
		foreach ( TimeRepository::category_labels() as $key => $label ) {
			$categories[] = array( 'key' => $key, 'label' => $label );
		}

		return new WP_REST_Response(
			array(
				'now'        => time(),
				'today'      => $today,
				'clock'      => self::clock(),
				'categories' => $categories,
				'offset'     => $offset,
				'week'       => array(
					'label'   => IsoWeek::label( $week['iso_year'], $week['iso_week'] ),
					'von'     => $bounds['von'],
					'bis'     => $bounds['bis'],
					'hours'   => round( $total, 2 ),
					'ampel'   => self::week_ampel( $total ),
					'entries' => $rows,
				),
				'weeks'      => $weeks,
				'budget'     => self::BUDGET_HOURS,
			)
		);
	}

	/**
	 * Uhr starten.
	 *
	 * @return WP_REST_Response
	 */
	public static function zeit_start() {
		if ( TimerState::is_running() ) {
			return self::fail( __( 'Es läuft schon eine Uhr. Stoppe oder verwirf sie zuerst.', 'lindenweg16' ), 409 );
		}

		if ( ! TimerState::start() ) {
			return self::fail( __( 'Die Uhr ließ sich nicht starten. Bitte melde dich neu an und versuche es noch einmal.', 'lindenweg16' ), 500 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'clock' => self::clock(), 'now' => time() ) );
	}

	/**
	 * Nur der Stand der Uhr – leicht genug für die Kopfzeile.
	 *
	 * @return WP_REST_Response
	 */
	public static function zeit_uhr() {
		return new WP_REST_Response( array( 'clock' => self::clock(), 'now' => time() ) );
	}

	/**
	 * Uhr anhalten.
	 *
	 * @return WP_REST_Response
	 */
	public static function zeit_pause() {
		if ( ! TimerState::is_running() ) {
			return self::fail( __( 'Es läuft keine Uhr, die sich anhalten ließe.', 'lindenweg16' ), 409 );
		}

		if ( ! TimerState::pause() ) {
			return self::fail( __( 'Die Uhr ist schon angehalten.', 'lindenweg16' ), 409 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'clock' => self::clock(), 'now' => time() ) );
	}

	/**
	 * Nach der Pause weitermachen.
	 *
	 * @return WP_REST_Response
	 */
	public static function zeit_weiter() {
		if ( ! TimerState::is_running() ) {
			return self::fail( __( 'Es läuft keine Uhr, die sich fortsetzen ließe.', 'lindenweg16' ), 409 );
		}

		if ( ! TimerState::resume() ) {
			return self::fail( __( 'Die Uhr läuft schon.', 'lindenweg16' ), 409 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'clock' => self::clock(), 'now' => time() ) );
	}

	/**
	 * Uhr stoppen und genau einen Eintrag buchen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function zeit_stopp( WP_REST_Request $request ) {
		// Kommt der Eintrag aus der Uhr, muss die Uhr auch noch laufen.
		// Sonst bucht ein zweiter Klick dieselbe Zeit ein weiteres Mal.
		$state = TimerState::get();

		if ( ! $state ) {
			return self::fail( __( 'Die Uhr wurde inzwischen gestoppt oder verworfen – es wurde nichts gebucht.', 'lindenweg16' ), 409 );
		}

		$p     = self::body( $request );
		$input = array(
			'entry_date'    => self::str( $p, 'entry_date' ),
			'category'      => self::str( $p, 'category' ),
			'hours'         => self::str( $p, 'hours' ),
			'description'   => self::str( $p, 'description' ),
			// Der Auftrag kommt aus der Uhr, nie aus der Anfrage.
			'work_order_id' => (int) $state['work_order_id'],
		);

		if ( null === self::normalize_date( $input['entry_date'], '' ) ) {
			return self::fail( __( 'Das Datum ist ungültig. Bitte im Format Jahr-Monat-Tag eintragen, zum Beispiel 2026-03-14.', 'lindenweg16' ) );
		}

		$result = TimeRepository::validate( $input );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		if ( ! TimeRepository::insert( $result['data'] ) ) {
			return self::fail( __( 'Der Eintrag ließ sich nicht speichern. Die Uhr läuft weiter – bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		TimerState::discard();

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Zeit erfasst.', 'lindenweg16' ) ) );
	}

	/**
	 * Uhr verwerfen, ohne zu buchen.
	 *
	 * @return WP_REST_Response
	 */
	public static function zeit_verwerfen() {
		TimerState::discard();

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Die Uhr wurde verworfen, es wurde nichts gebucht.', 'lindenweg16' ) ) );
	}

	/**
	 * Zeit nachtragen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function zeit_eintrag( WP_REST_Request $request ) {
		$p     = self::body( $request );
		$input = array(
			'entry_date'  => self::str( $p, 'entry_date' ),
			'category'    => self::str( $p, 'category' ),
			'hours'       => self::str( $p, 'hours' ),
			'description' => self::str( $p, 'description' ),
			'phase_block' => self::str( $p, 'phase_block' ),
		);

		if ( null === self::normalize_date( $input['entry_date'], '' ) ) {
			return self::fail( __( 'Das Datum ist ungültig. Bitte im Format Jahr-Monat-Tag eintragen, zum Beispiel 2026-03-14.', 'lindenweg16' ) );
		}

		if ( '' === $input['entry_date'] ) {
			$input['entry_date'] = self::today();
		}

		$result = TimeRepository::validate( $input );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		if ( ! TimeRepository::insert( $result['data'] ) ) {
			return self::fail( __( 'Der Eintrag ließ sich nicht speichern. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Zeit erfasst.', 'lindenweg16' ) ) );
	}

	/* ====================================================================
	 * 2. Löhne und Protokoll
	 * ================================================================== */

	/**
	 * Protokollzeilen eines Zeitraums.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return array
	 */
	protected static function audit_between( $von, $bis ) {
		global $wpdb;

		$table = BetriebAudit::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE aktion LIKE 'betrieb_%%' AND erstellt_am BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$von . ' 00:00:00',
				$bis . ' 23:59:59'
			),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * Übersicht aller Nutzer mit Lohn, Stunden und Vorgängen.
	 *
	 * @param WP_REST_Request $request Anfrage (von, bis).
	 * @return WP_REST_Response
	 */
	public static function loehne( WP_REST_Request $request ) {
		$period  = self::period( $request->get_param( 'von' ), $request->get_param( 'bis' ), self::today() );
		$entries = TimeRepository::between( $period['von'], $period['bis'] );
		$wages   = WageRepository::all();
		$by_user = LaborCost::by_user( $entries, $wages );

		$log_by_user = array();
		foreach ( self::audit_between( $period['von'], $period['bis'] ) as $row ) {
			$log_by_user[ (int) $row['user_id'] ][] = $row;
		}

		$people = array();

		foreach ( Roles::betrieb_users() as $user ) {
			$id      = (int) $user->ID;
			$profile = Roles::betrieb_profile( $id );
			$rate    = WageRepository::current( $id );
			$cost    = isset( $by_user[ $id ] ) ? $by_user[ $id ] : null;
			$summary = UserActivity::summarize( isset( $log_by_user[ $id ] ) ? $log_by_user[ $id ] : array() );

			$people[] = array(
				'id'             => $id,
				'name'           => $user->display_name,
				'email'          => $user->user_email,
				'profile'        => '' === $profile ? __( 'eigene Zusammenstellung', 'lindenweg16' ) : AccessProfiles::label( $profile ),
				'rate'           => $rate,
				'hours'          => $cost ? round( $cost['hours'] + $cost['hours_unpriced'], 2 ) : 0,
				'cost'           => $cost ? round( (float) $cost['cost'], 2 ) : 0,
				'hours_unpriced' => $cost ? round( (float) $cost['hours_unpriced'], 2 ) : 0,
				'ops'            => (int) $summary['total'],
				'last'           => self::ts( $summary['last'] ),
			);
		}

		// Ist-Stundensatz gegen den kalkulatorischen Satz.
		$total     = LaborCost::total( $entries, $wages );
		$settings  = CalcSettings::for_date( $period['bis'] );
		$vergleich = LaborCost::compare( $total['rate'], $settings['labor_rate_per_h'] );

		return new WP_REST_Response(
			array(
				'von'     => $period['von'],
				'bis'     => $period['bis'],
				'people'  => $people,
				'compare' => array(
					'known'          => (bool) $vergleich['known'],
					'actual'         => $vergleich['known'] ? round( (float) $vergleich['actual'], 2 ) : null,
					'calculated'     => $vergleich['known'] ? round( (float) $vergleich['calculated'], 2 ) : null,
					'diff_pct'       => $vergleich['known'] ? round( (float) $vergleich['diff_pct'] * 100, 1 ) : null,
					'level'          => isset( $vergleich['level'] ) ? $vergleich['level'] : '',
					'hours_unpriced' => round( (float) $total['hours_unpriced'], 2 ),
				),
			)
		);
	}

	/**
	 * Ein Nutzer: Lohnverlauf, erfasste Zeit, Protokoll.
	 *
	 * @param WP_REST_Request $request Anfrage (id, von, bis).
	 * @return WP_REST_Response
	 */
	public static function loehne_nutzer( WP_REST_Request $request ) {
		global $wpdb;

		$user_id = (int) $request['id'];
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return self::fail( __( 'Diesen Nutzer gibt es nicht mehr.', 'lindenweg16' ), 404 );
		}

		$period = self::period( $request->get_param( 'von' ), $request->get_param( 'bis' ), self::today() );
		$wages  = WageRepository::all();
		$labels = TimeRepository::category_labels();

		// Protokoll: die letzten 100 Vorgänge dieses Nutzers.
		$table = BetriebAudit::table();
		$log   = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND aktion LIKE 'betrieb_%%' ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				100
			),
			ARRAY_A
		);

		$summary = UserActivity::summarize( $log );

		$protocol = array();
		foreach ( $log as $row ) {
			$protocol[] = array(
				'time'   => self::ts( $row['erstellt_am'] ),
				'text'   => UserActivity::describe( $row ),
				'reason' => empty( $row['grund'] ) ? '' : (string) $row['grund'],
			);
		}

		// Erfasste Zeit im Zeitraum.
		$entries = array_values(
			array_filter(
				TimeRepository::between( $period['von'], $period['bis'] ),
				static function ( $entry ) use ( $user_id ) {
					return (int) $entry['created_by'] === $user_id;
				}
			)
		);

		$time = array();
		foreach ( $entries as $entry ) {
			$cost   = LaborCost::entry_cost( $entry, $wages );
			$time[] = array(
				'date'        => $entry['entry_date'],
				'category'    => isset( $labels[ $entry['category'] ] ) ? $labels[ $entry['category'] ] : $entry['category'],
				'description' => (string) $entry['description'],
				'hours'       => (float) $entry['hours'],
				'cost'        => null === $cost ? null : round( (float) $cost, 2 ),
			);
		}

		$total = LaborCost::total( $entries, $wages );

		$history = array();
		foreach ( WageRepository::history( $user_id ) as $row ) {
			$history[] = array(
				'from' => $row['valid_from'],
				'rate' => (float) $row['rate_per_h'],
				'note' => empty( $row['note'] ) ? '' : (string) $row['note'],
			);
		}

		return new WP_REST_Response(
			array(
				'von'     => $period['von'],
				'bis'     => $period['bis'],
				'user'    => array( 'id' => $user_id, 'name' => $user->display_name, 'email' => $user->user_email ),
				'wages'   => $history,
				'summary' => array(
					'total' => (int) $summary['total'],
					'areas' => $summary['areas'] ? UserActivity::areas_label( $summary['areas'] ) : '',
				),
				'time'    => $time,
				'sum'     => array(
					'hours' => round( $total['hours'] + $total['hours_unpriced'], 2 ),
					'cost'  => round( (float) $total['cost'], 2 ),
				),
				'log'     => $protocol,
				'today'   => self::today(),
			)
		);
	}

	/**
	 * Stundenlohn mit Stichtag festlegen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function loehne_lohn( WP_REST_Request $request ) {
		$p       = self::body( $request );
		$user_id = isset( $p['user_id'] ) ? (int) $p['user_id'] : 0;
		$rate    = self::parse_number( self::str( $p, 'rate_per_h' ) );
		$from    = self::normalize_date( self::str( $p, 'valid_from' ), '' );

		if ( null === $rate ) {
			return self::fail( __( 'Bitte den Stundenlohn als Zahl eintragen, zum Beispiel 14,50.', 'lindenweg16' ) );
		}

		if ( null === $from || '' === $from ) {
			return self::fail( __( 'Bitte einen gültigen Stichtag angeben, ab dem der Lohn gilt.', 'lindenweg16' ) );
		}

		$result = WageRepository::set( $user_id, (string) $rate, $from, self::str( $p, 'note' ) );

		if ( empty( $result['success'] ) ) {
			return self::fail( $result['message'] );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => $result['message'] ) );
	}

	/* ====================================================================
	 * 3. Etikettendrucker
	 * ================================================================== */

	/**
	 * Drucker, Rollenformate, Zähler.
	 *
	 * @return WP_REST_Response
	 */
	public static function etiketten() {
		$printers = array();

		foreach ( PrinterRepository::all() as $printer ) {
			$printers[] = array(
				'id'            => (int) $printer->id,
				'printer_id'    => $printer->printer_id,
				'name'          => $printer->name,
				'online'        => PrinterRepository::is_online( $printer ),
				'last_seen'     => self::ts( $printer->last_seen_at ),
				'model'         => (string) $printer->printer_model,
				'queued'        => PrintJobRepository::count_queued( $printer->printer_id ),
				'token_created' => self::ts( $printer->token_created_at ),
				'active'        => (bool) (int) $printer->active,
			);
		}

		$rolls = array( array( 'key' => 'dhl', 'label' => __( 'DHL-Maß 103 × 199 mm', 'lindenweg16' ) ) );
		foreach ( LabelSheet::rolls() as $key => $roll ) {
			$rolls[] = array( 'key' => $key, 'label' => $roll['label'] );
		}

		return new WP_REST_Response( array( 'now' => time(), 'printers' => $printers, 'rolls' => $rolls ) );
	}

	/**
	 * Antwort mit einem Token: nie zwischenspeichern.
	 *
	 * @param array $data Daten.
	 * @return WP_REST_Response
	 */
	protected static function token_response( array $data ) {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Neuen Drucker anlegen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function drucker_anlegen( WP_REST_Request $request ) {
		$p    = self::body( $request );
		$name = sanitize_text_field( self::str( $p, 'name' ) );
		$id   = self::printer_id( self::str( $p, 'printer_id' ) );

		if ( $id['error'] ) {
			return self::fail( $id['error'] );
		}

		if ( '' === $name ) {
			return self::fail( __( 'Bitte dem Drucker einen Namen geben, zum Beispiel „EG Etikettendrucker“.', 'lindenweg16' ) );
		}

		if ( PrinterRepository::find_by_printer_id( $id['id'] ) ) {
			return self::fail( __( 'Diese Drucker-ID gibt es schon. Bitte eine andere wählen.', 'lindenweg16' ), 409 );
		}

		$result = PrinterRepository::create( $id['id'], $name );

		if ( empty( $result['id'] ) ) {
			return self::fail( __( 'Der Drucker ließ sich nicht anlegen. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		// Das Token selbst gehört nie ins Protokoll.
		AuditLog::record( 'druckerclient_angelegt', 'print_queue_printer', (int) $result['id'], array( 'drucker' => $id['id'] ) );

		return self::token_response( array( 'ok' => true, 'message' => __( 'Drucker angelegt.', 'lindenweg16' ), 'token' => $result['token'], 'name' => $name ) );
	}

	/**
	 * Token neu erzeugen: das alte wird sofort ungültig.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function drucker_token( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$printer = PrinterRepository::find( $id );

		if ( ! $printer ) {
			return self::fail( __( 'Diesen Drucker gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		$token = PrinterRepository::regenerate_token( $id );

		if ( '' === $token ) {
			return self::fail( __( 'Das Token ließ sich nicht erneuern. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		AuditLog::record( 'druckerclient_token_erneuert', 'print_queue_printer', $id, array( 'drucker' => $printer->printer_id ) );

		return self::token_response( array( 'ok' => true, 'message' => __( 'Neues Token erzeugt. Es wird nur jetzt angezeigt.', 'lindenweg16' ), 'token' => $token, 'name' => $printer->name ) );
	}

	/**
	 * Testetikett in die Warteschlange legen.
	 *
	 * @param WP_REST_Request $request Anfrage (format).
	 * @return WP_REST_Response
	 */
	public static function drucker_test( WP_REST_Request $request ) {
		$printer = PrinterRepository::find( (int) $request['id'] );

		if ( ! $printer ) {
			return self::fail( __( 'Diesen Drucker gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		$p      = self::body( $request );
		$format = sanitize_key( self::str( $p, 'format' ) );
		$format = '' === $format ? 'dhl' : $format;

		// Die Größe ist wählbar: Ein Drucker mit schmaler Rolle kann mit
		// einem 103×199-mm-Testetikett nichts anfangen.
		if ( LabelSheet::has_roll( $format ) ) {
			$roll         = LabelSheet::roll( $format );
			$width_mm     = (float) $roll['w'];
			$height_mm    = (float) $roll['h'];
			$landscape    = LabelSheet::is_landscape( $roll );
			$label_format = $format;
		} else {
			$width_mm     = 103;
			$height_mm    = 199;
			$landscape    = false;
			$label_format = '910-300-610';
		}

		$job_id = PrintQueueService::create_test_job( $printer->printer_id, $width_mm, $height_mm, $label_format, $landscape );

		if ( ! $job_id ) {
			return self::fail( __( 'Das Testetikett ließ sich nicht anlegen. Prüfe, ob der Ordner für Etiketten beschreibbar ist.', 'lindenweg16' ), 500 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Testetikett in die Warteschlange gelegt. Der Raspberry Pi holt es beim nächsten Kontakt ab.', 'lindenweg16' ) ) );
	}

	/**
	 * Druckaufträge, neueste zuerst.
	 *
	 * @param WP_REST_Request $request Anfrage (status).
	 * @return WP_REST_Response
	 */
	public static function auftraege( WP_REST_Request $request ) {
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$status = in_array( $status, PrintJobRepository::STATUSES, true ) ? $status : '';

		$jobs = array();

		foreach ( PrintJobRepository::list_for_admin( $status, 200 ) as $job ) {
			$jobs[] = array(
				'id'      => (int) $job->id,
				'order'   => $job->order_id ? (int) $job->order_id : 0,
				'printer' => $job->printer_id,
				'status'  => $job->status,
				'test'    => (bool) (int) $job->is_test,
				'tries'   => (int) $job->attempts,
				'created' => self::ts( $job->created_at ),
				'printed' => self::ts( $job->printed_at ),
				'error'   => $job->error_message ? (string) $job->error_message : '',
				'actions' => self::job_actions( $job->status ),
			);
		}

		return new WP_REST_Response( array( 'status' => $status, 'jobs' => $jobs ) );
	}

	/**
	 * Auftrag erneut in die Warteschlange legen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function auftrag_erneut( WP_REST_Request $request ) {
		global $wpdb;

		$id  = (int) $request['id'];
		$job = PrintJobRepository::find( $id );

		if ( ! $job ) {
			return self::fail( __( 'Diesen Druckauftrag gibt es nicht (mehr). Bitte die Liste neu laden.', 'lindenweg16' ), 404 );
		}

		if ( ! self::job_actions( $job->status )['erneut'] ) {
			return self::fail( __( 'Dieser Auftrag ist noch in Arbeit und kann nicht erneut gesendet werden. Nach zehn Minuten ohne Antwort des Druckers wird er automatisch wieder freigegeben.', 'lindenweg16' ), 409 );
		}

		// Ohne die Etikettendatei würde der Pi sofort wieder scheitern.
		if ( empty( $job->label_path ) || ! is_file( $job->label_path ) ) {
			return self::fail( __( 'Die Etikettendatei gibt es nicht mehr (sie wird nach der Aufbewahrungszeit gelöscht). Bitte das Etikett neu erzeugen.', 'lindenweg16' ), 410 );
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . PrintJobRepository::table() . " SET status = 'queued', error_message = NULL, locked_at = NULL, updated_at = %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND status IN ('failed','cancelled','printed')",
				current_time( 'mysql', true ),
				$id
			)
		);

		if ( (int) $updated < 1 ) {
			return self::fail( __( 'Der Auftrag hat sich inzwischen geändert. Bitte die Liste neu laden.', 'lindenweg16' ), 409 );
		}

		AuditLog::record( 'druckjob_erneut_gesendet', 'print_queue_job', $id, array( 'vorher' => $job->status, 'drucker' => $job->printer_id ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Der Auftrag wartet wieder auf den Drucker.', 'lindenweg16' ) ) );
	}

	/**
	 * Wartenden Auftrag abbrechen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function auftrag_abbrechen( WP_REST_Request $request ) {
		global $wpdb;

		$id  = (int) $request['id'];
		$job = PrintJobRepository::find( $id );

		if ( ! $job ) {
			return self::fail( __( 'Diesen Druckauftrag gibt es nicht (mehr). Bitte die Liste neu laden.', 'lindenweg16' ), 404 );
		}

		// Nur ein noch wartender Auftrag lässt sich sicher zurückziehen; ein
		// vom Drucker abgeholter ist vielleicht schon im Druck.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . PrintJobRepository::table() . " SET status = 'cancelled', updated_at = %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " WHERE id = %d AND status = 'queued'",
				current_time( 'mysql', true ),
				$id
			)
		);

		if ( (int) $updated < 1 ) {
			return self::fail( __( 'Der Auftrag wurde inzwischen vom Drucker abgeholt oder ist erledigt und lässt sich nicht mehr abbrechen. Bitte die Liste neu laden.', 'lindenweg16' ), 409 );
		}

		AuditLog::record( 'druckjob_abgebrochen', 'print_queue_job', $id, array( 'drucker' => $job->printer_id ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Der Auftrag wurde abgebrochen.', 'lindenweg16' ) ) );
	}

	/* ====================================================================
	 * 4. Einstellungen
	 * ================================================================== */

	/**
	 * Kalkulationsannahmen zum Stichtag mit Verlauf.
	 *
	 * @param WP_REST_Request $request Anfrage (stichtag).
	 * @return WP_REST_Response
	 */
	public static function annahmen( WP_REST_Request $request ) {
		$today    = self::today();
		$stichtag = self::normalize_date( $request->get_param( 'stichtag' ), $today );
		$stichtag = null === $stichtag ? $today : $stichtag;
		$values   = CalcSettings::for_date( $stichtag );

		$items = array();

		foreach ( CalcSettings::labels() as $key => $label ) {
			$history = array();

			foreach ( array_slice( CalcSettings::history( $key ), 0, 5 ) as $row ) {
				$history[] = array(
					'from'  => $row['valid_from'],
					'value' => (float) $row['value_decimal'],
					'note'  => $row['note'] ? (string) $row['note'] : '',
				);
			}

			$items[] = array(
				'key'     => $key,
				'label'   => $label,
				'value'   => isset( $values[ $key ] ) ? (float) $values[ $key ] : 0.0,
				'history' => $history,
			);
		}

		return new WP_REST_Response( array( 'stichtag' => $stichtag, 'today' => $today, 'items' => $items ) );
	}

	/**
	 * Neuen Wert einer Annahme festschreiben (nie überschreiben, ab Stichtag).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function annahme_speichern( WP_REST_Request $request ) {
		$p      = self::body( $request );
		$key    = sanitize_key( self::str( $p, 'key' ) );
		$labels = CalcSettings::labels();

		if ( ! isset( $labels[ $key ] ) ) {
			return self::fail( __( 'Diese Annahme gibt es nicht. Bitte die Seite neu laden.', 'lindenweg16' ) );
		}

		$value = self::parse_assumption( isset( $p['value'] ) ? $p['value'] : '' );

		if ( $value['error'] ) {
			return self::fail( sprintf( /* translators: 1: Name der Annahme, 2: Fehler. */ __( '%1$s: %2$s', 'lindenweg16' ), $labels[ $key ], $value['error'] ) );
		}

		$from = self::normalize_date( self::str( $p, 'valid_from' ), self::today() );

		if ( null === $from ) {
			return self::fail( __( 'Das Datum ist ungültig. Bitte einen Stichtag im Format Jahr-Monat-Tag eintragen.', 'lindenweg16' ) );
		}

		if ( ! CalcSettings::set( $key, $value['value'], $from, sanitize_text_field( self::str( $p, 'note' ) ) ) ) {
			return self::fail( __( 'Die Annahme ließ sich nicht speichern. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Annahme festgeschrieben.', 'lindenweg16' ) ) );
	}

	/**
	 * Standard-Rabattstaffel.
	 *
	 * @param WP_REST_Request $request Anfrage (stichtag).
	 * @return WP_REST_Response
	 */
	public static function staffel( WP_REST_Request $request ) {
		$today    = self::today();
		$stichtag = self::normalize_date( $request->get_param( 'stichtag' ), $today );
		$stichtag = null === $stichtag ? $today : $stichtag;

		$map = static function ( $row ) {
			return array(
				'name'  => (string) $row['tier_name'],
				'min'   => (float) $row['min_order_value'],
				'pct'   => (float) $row['discount_pct'],
				'from'  => isset( $row['valid_from'] ) ? $row['valid_from'] : '',
				'until' => ! empty( $row['valid_until'] ) ? $row['valid_until'] : '',
				'note'  => ! empty( $row['note'] ) ? (string) $row['note'] : '',
			);
		};

		return new WP_REST_Response(
			array(
				'stichtag' => $stichtag,
				'today'    => $today,
				'tiers'    => array_map( $map, PriceTierRepository::at( $stichtag ) ),
				'history'  => array_map( $map, PriceTierRepository::history() ),
			)
		);
	}

	/**
	 * Neue Staffel ab einem Stichtag festschreiben.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function staffel_speichern( WP_REST_Request $request ) {
		$p    = self::body( $request );
		$from = self::normalize_date( self::str( $p, 'valid_from' ), self::today() );

		if ( null === $from ) {
			return self::fail( __( 'Das Datum ist ungültig. Bitte einen Stichtag im Format Jahr-Monat-Tag eintragen.', 'lindenweg16' ) );
		}

		$rows = array();

		foreach ( isset( $p['tiers'] ) && is_array( $p['tiers'] ) ? $p['tiers'] : array() as $row ) {
			$row = (array) $row;
			$min = self::parse_number( isset( $row['min'] ) ? $row['min'] : '' );
			$pct = self::parse_number( isset( $row['pct'] ) ? $row['pct'] : '' );

			$name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';

			// Eine halb ausgefüllte oder unlesbare Zeile nennt sich beim Namen.
			if ( ( isset( $row['min'] ) && '' !== trim( (string) $row['min'] ) && null === $min ) || ( isset( $row['pct'] ) && '' !== trim( (string) $row['pct'] ) && null === $pct ) ) {
				return self::fail( sprintf( /* translators: %s: Stufenname. */ __( 'In der Stufe „%s“ steht ein Wert, der keine Zahl ist. Bitte nur Ziffern und ein Komma verwenden.', 'lindenweg16' ), '' === $name ? __( 'ohne Namen', 'lindenweg16' ) : $name ) );
			}

			$rows[] = array(
				'tier_name'       => $name,
				'min_order_value' => null === $min ? 0.0 : $min,
				'discount_pct'    => null === $pct ? 0.0 : $pct,
			);
		}

		$result = PriceTierRepository::validate( $rows );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		$reason = sanitize_text_field( self::str( $p, 'reason' ) );
		$count  = PriceTierRepository::replace_from( $result['tiers'], $from, $reason );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: 1: Anzahl Stufen, 2: Datum. */
					__( '%1$d Stufen festgeschrieben, gültig ab %2$s. Die bisherige Staffel bleibt für zurückliegende Auswertungen erhalten.', 'lindenweg16' ),
					$count,
					mysql2date( 'd.m.Y', $from )
				),
			)
		);
	}

	/**
	 * Farbsystem und Lagerhoheit.
	 *
	 * @return WP_REST_Response
	 */
	public static function lager() {
		$colors = array();

		foreach ( ColorRegistry::all() as $slug => $label ) {
			$colors[] = array( 'slug' => $slug, 'label' => $label, 'default' => ColorRegistry::default_color() === $slug );
		}

		return new WP_REST_Response(
			array(
				'attribute'  => ColorRegistry::taxonomy(),
				'exists'     => taxonomy_exists( ColorRegistry::taxonomy() ),
				'default'    => ColorRegistry::default_color(),
				'colors'     => $colors,
				'hoheit'     => (bool) StockOwnership::is_active(),
				'wochenmail' => (bool) Settings::get( 'betrieb_wochenmail', 1 ),
				'layout'     => 'schlicht' === Settings::get( 'betrieb_reorder_layout' ) ? 'schlicht' : 'theme',
			)
		);
	}

	/**
	 * Farb- und Lagereinstellungen speichern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function lager_speichern( WP_REST_Request $request ) {
		$p         = self::body( $request );
		$attribute = sanitize_key( self::str( $p, 'attribute' ) );
		$default   = sanitize_text_field( self::str( $p, 'default' ) );
		$layout    = sanitize_key( self::str( $p, 'layout' ) );

		Settings::update(
			array(
				'betrieb_color_attribute' => $attribute ? $attribute : 'pa_farbe',
				'betrieb_default_color'   => ColorRegistry::normalize( '' === $default ? 'anthrazit' : $default ),
				'betrieb_lagerhoheit'     => empty( $p['hoheit'] ) ? 0 : 1,
				'betrieb_wochenmail'      => empty( $p['wochenmail'] ) ? 0 : 1,
				'betrieb_reorder_layout'  => 'schlicht' === $layout ? 'schlicht' : 'theme',
			)
		);

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Einstellungen gespeichert.', 'lindenweg16' ) ) );
	}

	/**
	 * Kettenprüfung und letzte Vorgänge.
	 *
	 * @return WP_REST_Response
	 */
	public static function protokoll() {
		global $wpdb;

		$errors = BetriebAudit::verify_chain();
		$first  = $errors ? reset( $errors ) : null;

		$table = BetriebAudit::table();
		$rows  = (array) $wpdb->get_results(
			"SELECT * FROM {$table} WHERE aktion LIKE 'betrieb_%' ORDER BY id DESC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$log = array();
		foreach ( $rows as $row ) {
			$user  = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
			$log[] = array(
				'time'   => self::ts( $row['erstellt_am'] ),
				'action' => str_replace( 'betrieb_', '', $row['aktion'] ),
				'object' => (string) $row['objekt_typ'],
				'record' => (string) $row['objekt_id'],
				'user'   => $user ? $user->display_name : '',
				'reason' => ! empty( $row['grund'] ) ? (string) $row['grund'] : '',
			);
		}

		return new WP_REST_Response(
			array(
				'intact' => ! $errors,
				'broken' => $first ? array( 'id' => (int) $first['id'], 'error' => (string) $first['error'] ) : null,
				'log'    => $log,
			)
		);
	}

	/**
	 * Systemprüfung: Widersprüche im Zusammenspiel.
	 *
	 * @return WP_REST_Response
	 */
	public static function systempruefung() {
		$findings = Consistency::check( Consistency::collect() );
		$sum      = Consistency::summarize( $findings );

		$out = array();
		foreach ( $findings as $finding ) {
			$out[] = array(
				'level' => $finding['level'],
				'area'  => (string) $finding['area'],
				'text'  => (string) $finding['text'],
				'fix'   => (string) $finding['fix'],
			);
		}

		return new WP_REST_Response( array( 'findings' => $out, 'errors' => (int) $sum['fehler'], 'warnings' => (int) $sum['warnung'] ) );
	}

	/**
	 * Die Felder der Plugin-Einstellungen, so wie die alte Seite sie kennt.
	 *
	 * @return array Gruppenname => [ Schlüssel => [Beschriftung, Typ] ].
	 */
	protected static function plugin_fields() {
		$reader = new class() extends PluginSettingsPage {
			/**
			 * Felder offenlegen.
			 *
			 * @return array
			 */
			public function groups() {
				return $this->fields();
			}
		};

		return $reader->groups();
	}

	/**
	 * Plugin-Einstellungen (Firma, DHL, E-Mail, …) lesen.
	 *
	 * Geheimnisse werden nie ausgegeben, nur ob sie gesetzt sind.
	 *
	 * @return WP_REST_Response
	 */
	public static function plugin() {
		// Diese Einstellungen reichen weiter als die des Betriebs (DHL-Zugang,
		// Firmendaten): Wie auf der alten Seite zeigt nur der Vollzugriff sie.
		if ( ! Access::is_full() ) {
			return new WP_REST_Response( array( 'allowed' => false ) );
		}

		$values = Settings::all();
		$groups = array();

		foreach ( self::plugin_fields() as $title => $fields ) {
			$list = array();

			foreach ( $fields as $key => $meta ) {
				$type   = $meta[1];
				$secret = self::is_secret_key( $key, $type );
				$value  = isset( $values[ $key ] ) ? $values[ $key ] : '';

				$list[] = array(
					'key'    => $key,
					'label'  => $meta[0],
					'type'   => 'password' === $type || $secret ? 'password' : $type,
					'secret' => $secret,
					'set'    => $secret ? '' !== (string) $value : null,
					'value'  => $secret || 'media' === $type ? null : $value,
					'media'  => 'media' === $type,
				);
			}

			$groups[] = array( 'id' => sanitize_title( $title ), 'title' => $title, 'fields' => $list );
		}

		$templates = array();

		foreach ( MailTemplate::templates() as $key => $tpl ) {
			$subject = isset( $values[ 'mail_' . $key . '_betreff' ] ) ? (string) $values[ 'mail_' . $key . '_betreff' ] : '';
			$text    = isset( $values[ 'mail_' . $key . '_text' ] ) ? (string) $values[ 'mail_' . $key . '_text' ] : '';

			$templates[] = array(
				'key'          => $key,
				'label'        => $tpl['label'],
				'placeholders' => ( $tpl['platzhalter'] ? $tpl['platzhalter'] . ', ' : '' ) . '{firmenname}',
				'subject'      => $subject,
				'text'         => $text,
				'subject_hint' => $tpl['betreff'],
				'text_hint'    => $tpl['text'],
			);
		}

		return new WP_REST_Response( array( 'allowed' => true, 'groups' => $groups, 'templates' => $templates ) );
	}

	/**
	 * Eine Gruppe der Plugin-Einstellungen speichern.
	 *
	 * Nur die Felder der gesendeten Gruppe ändern sich – alles andere bleibt,
	 * wie es ist. Ein leeres Geheimnis heißt „unverändert“, sonst löschte jedes
	 * Speichern den Zugang zum Frankierkonto.
	 *
	 * @param WP_REST_Request $request Anfrage (group, values).
	 * @return WP_REST_Response
	 */
	public static function plugin_speichern( WP_REST_Request $request ) {
		if ( ! Access::is_full() ) {
			return self::fail( __( 'Für diesen Bereich (Firma, DHL-Zugänge, E-Mail-Vorlagen) fehlt dir die Berechtigung.', 'lindenweg16' ), 403 );
		}

		$p      = self::body( $request );
		$group  = self::str( $p, 'group' );
		$values = isset( $p['values'] ) && is_array( $p['values'] ) ? $p['values'] : array();
		$clean  = array();

		if ( 'mail' === $group ) {
			foreach ( MailTemplate::templates() as $key => $tpl ) {
				$subject = 'mail_' . $key . '_betreff';
				$text    = 'mail_' . $key . '_text';

				if ( array_key_exists( $subject, $values ) ) {
					$clean[ $subject ] = sanitize_text_field( (string) $values[ $subject ] );
				}
				if ( array_key_exists( $text, $values ) ) {
					$clean[ $text ] = sanitize_textarea_field( (string) $values[ $text ] );
				}
			}
		} else {
			$found = false;

			foreach ( self::plugin_fields() as $title => $fields ) {
				if ( sanitize_title( $title ) !== $group ) {
					continue;
				}

				$found = true;

				foreach ( $fields as $key => $meta ) {
					$type = $meta[1];

					// Das Logo wählt man in der Mediathek – das geht nur in der klassischen Ansicht.
					if ( 'media' === $type || ! array_key_exists( $key, $values ) ) {
						continue;
					}

					$secret = self::is_secret_key( $key, $type );
					$raw    = $values[ $key ];

					if ( $secret ) {
						$input = is_scalar( $raw ) ? (string) $raw : '';

						if ( '' !== $input ) {
							$clean[ $key ] = sanitize_text_field( $input );
						}

						continue;
					}

					$checked = self::plugin_value( $type, $raw );

					if ( ! $checked['ok'] ) {
						return self::fail( sprintf( /* translators: 1: Feld, 2: Fehler. */ __( '%1$s: %2$s', 'lindenweg16' ), $meta[0], $checked['error'] ) );
					}

					if ( 'textarea' === $type ) {
						$clean[ $key ] = sanitize_textarea_field( $checked['value'] );
					} elseif ( 'text' === $type ) {
						$clean[ $key ] = sanitize_text_field( $checked['value'] );
					} else {
						$clean[ $key ] = $checked['value'];
					}
				}
			}

			if ( ! $found ) {
				return self::fail( __( 'Diese Gruppe gibt es nicht. Bitte die Seite neu laden.', 'lindenweg16' ) );
			}
		}

		if ( ! $clean ) {
			return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Nichts geändert.', 'lindenweg16' ) ) );
		}

		Settings::update( $clean );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Einstellungen gespeichert.', 'lindenweg16' ) ) );
	}
}
