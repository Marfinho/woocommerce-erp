<?php
/**
 * Fertigung nativ: Druckjournal, Drucker & Wartung, Bambu-Lab-Drucker,
 * Qualität, Kapazität und Produktion.
 *
 * Diese Datei ersetzt die eingebetteten wp-admin-Seiten
 * (PrintJobsPage, PrintersPage, BambuPrintersPage, SimplePages::quality()
 * und ::capacity(), ProductionModule). Sie ruft dieselben Repositories mit
 * denselben Prüfungen auf – Protokoll (BetriebAudit), Lagerbuchungen und
 * Chargen entstehen dort, nicht hier. Was hier steht, sind
 *
 * - die Routen mit ihren Rechten (fein, wie in ActionMap, zusätzlich das
 *   alte Betrieb-Recht, das die wp-admin-Seite verlangte),
 * - reine Regeln als statische Methoden ohne Datenbank (testbar),
 * - das Aufbereiten der Antworten für die Oberfläche.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Erp\Access;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuJobRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository;
use Lindenweg16\Modules\Betrieb\BatchRepository;
use Lindenweg16\Modules\Betrieb\CalcSettings;
use Lindenweg16\Modules\Betrieb\CalculationContext;
use Lindenweg16\Modules\Betrieb\CapacityPlanner;
use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\FilamentRepository;
use Lindenweg16\Modules\Betrieb\IsoWeek;
use Lindenweg16\Modules\Betrieb\LabelSheet;
use Lindenweg16\Modules\Betrieb\MachineRate;
use Lindenweg16\Modules\Betrieb\MaintenanceChecklist;
use Lindenweg16\Modules\Betrieb\MaintenanceRepository;
use Lindenweg16\Modules\Betrieb\PrinterRepository;
use Lindenweg16\Modules\Betrieb\PrintJobRepository;
use Lindenweg16\Modules\Betrieb\RevisionRepository;
use Lindenweg16\Modules\Betrieb\StockLedger;
use Lindenweg16\Modules\Betrieb\StockRepository;
use Lindenweg16\Modules\Betrieb\TimeRepository;
use Lindenweg16\Modules\Betrieb\Traceability;
use Lindenweg16\Modules\Betrieb\WorkOrderRepository;
use Lindenweg16\Modules\Inventory\Repository as InventoryRepository;
use Lindenweg16\Modules\Production\ProductionModule;
use Lindenweg16\Modules\Production\ProductionRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen und Regeln der Fertigungsseiten.
 */
class Produktion {

	/**
	 * Felder eines Geräts, die das Formular schickt.
	 */
	const DEVICE_FIELDS = array(
		'code',
		'manufacturer',
		'model',
		'serial',
		'status',
		'acquired_on',
		'acquisition_cost',
		'lifetime_h',
		'power_cost_per_h',
		'power_kw',
		'spare_parts_per_year',
		'annual_operating_hours',
		'nozzle_mm',
		'hours_at_last_nozzle_change',
		'notes',
	);

	/**
	 * Schritte eines Produktionsauftrags, die ohne Buchung weitergehen.
	 * „fertig“ → „verpackt“ gibt es nur über den Abschluss mit Ist-Zeit.
	 */
	const JOB_STEPS = array(
		'geplant'       => 'in_produktion',
		'in_produktion' => 'fertig',
	);

	/* ====================================================================
	 * Routen
	 * ================================================================== */

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		unset( $api );

		$journal   = self::gate( array( 'fertigung.druckjournal' ), 'lw16_capture' );
		$void      = self::gate( array( 'fertigung.druckjournal' ), 'lw16_manage_production' );
		$devices   = self::gate( array( 'fertigung.drucker' ), 'lw16_manage_production' );
		$dev_edit  = self::gate( array( 'fertigung.drucker', 'fertigung.stammdaten.edit' ), 'lw16_manage_production' );
		$service   = self::gate( array( 'fertigung.drucker' ), 'lw16_capture' );
		$bambu     = self::gate( array( 'fertigung.bambu' ), 'lw16_capture' );
		$bambu_adm = self::gate( array( 'fertigung.bambu', 'fertigung.stammdaten.edit' ), 'lw16_manage_production' );
		$quality   = self::gate( array( 'fertigung.qualitaet' ), 'lw16_capture' );
		$capacity  = self::gate( array( 'fertigung.kapazitaet' ), 'lw16_manage_production' );
		$produce   = self::gate( array( 'fertigung.produktion' ), 'lw16_mod_produktion' );

		// Druckjournal.
		self::route( 'druckjournal', 'GET', 'journal', $journal );
		self::route( 'druckjournal/optionen', 'GET', 'journal_options', $journal );
		self::route( 'druckjournal/erfassen', 'POST', 'journal_save', $journal );
		self::route( 'druckjournal/(?P<id>\d+)/stornieren', 'POST', 'journal_void', $void );

		// Drucker & Wartung.
		self::route( 'geraete', 'GET', 'devices', $devices );
		self::route( 'geraete', 'POST', 'device_create', $dev_edit );
		self::route( 'geraete/(?P<id>\d+)', 'GET', 'device', $devices );
		self::route( 'geraete/(?P<id>\d+)', 'POST', 'device_update', $dev_edit );
		self::route( 'geraete/(?P<id>\d+)/wartung', 'POST', 'maintenance_save', $service );

		// Bambu-Lab-Drucker.
		self::route( 'bambu/drucker', 'GET', 'bambu_list', $bambu );
		self::route( 'bambu/drucker', 'POST', 'bambu_create', $bambu_adm );
		self::route( 'bambu/drucker/(?P<id>\d+)', 'POST', 'bambu_update', $bambu_adm );
		self::route( 'bambu/drucker/(?P<id>\d+)/token', 'POST', 'bambu_token', $bambu_adm );
		self::route( 'bambu/drucker/(?P<id>\d+)/loeschen', 'POST', 'bambu_delete', $bambu_adm );
		self::route( 'bambu/drucker/(?P<id>\d+)/platte-frei', 'POST', 'bambu_bed_clear', $bambu );

		// Qualität.
		self::route( 'qualitaet/chargen', 'GET', 'batches', $quality );
		self::route( 'qualitaet/chargen/(?P<id>\d+)', 'GET', 'batch', $quality );

		// Kapazität.
		self::route( 'kapazitaet', 'GET', 'capacity', $capacity );
		self::route( 'kapazitaet/rechnen', 'POST', 'capacity_calc', $capacity );

		// Produktion.
		self::route( 'produktion/auftraege', 'GET', 'jobs', $produce );
		self::route( 'produktion/auftraege/(?P<id>\d+)/weiter', 'POST', 'job_step', $produce );
		self::route( 'produktion/auftraege/(?P<id>\d+)/abschluss', 'POST', 'job_complete', $produce );
		self::route( 'produktion/wochen', 'POST', 'weeks_save', $produce );
	}

	/**
	 * Eine Route anlegen.
	 *
	 * @param string   $path     Pfad unter /erp/.
	 * @param string   $method   GET oder POST.
	 * @param string   $callback Name der statischen Methode.
	 * @param callable $perm     Rechteprüfung.
	 */
	protected static function route( $path, $method, $callback, $perm ) {
		register_rest_route(
			RestApi::NAMESPACE_V1,
			'/erp/' . $path,
			array(
				'methods'             => $method,
				'callback'            => array( __CLASS__, $callback ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * Darf die Person das? Feine Rechte und das alte Betrieb-Recht.
	 *
	 * Das alte Recht bleibt dabei, weil die eingebettete Seite es verlangte:
	 * Ein feines Recht allein darf nicht mehr erlauben als vorher.
	 *
	 * @param string[] $perms Feine Rechte (alle nötig).
	 * @param string   $cap   Altes Recht oder leer.
	 * @return bool
	 */
	public static function allowed( array $perms, $cap = '' ) {
		foreach ( $perms as $perm ) {
			if ( ! Access::can( $perm ) ) {
				return false;
			}
		}

		return '' === $cap || Roles::current_user_can_betrieb( $cap );
	}

	/**
	 * Rechteprüfung einer Route, mit deutscher Meldung.
	 *
	 * @param string[] $perms Feine Rechte.
	 * @param string   $cap   Altes Recht.
	 * @return callable
	 */
	protected static function gate( array $perms, $cap = '' ) {
		return function () use ( $perms, $cap ) {
			if ( self::allowed( $perms, $cap ) ) {
				return true;
			}

			return new WP_Error( 'lw16_forbidden', __( 'Dafür fehlt dir die Berechtigung.', 'lindenweg16' ), array( 'status' => 403 ) );
		};
	}

	/**
	 * Eingaben der Anfrage als Liste.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array
	 */
	protected static function body( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		return is_array( $body ) ? $body : (array) $request->get_params();
	}

	/**
	 * Nur einfache Werte (kein Feld darf als Liste ankommen).
	 *
	 * @param array $body Eingaben.
	 * @return array
	 */
	protected static function scalars( array $body ) {
		return array_filter( $body, 'is_scalar' );
	}

	/**
	 * Fehlerantwort mit Klartext.
	 *
	 * @param string $message Meldung.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}

	/**
	 * Erfolgsantwort.
	 *
	 * @param string $message Meldung.
	 * @param array  $extra   Weitere Felder.
	 * @return WP_REST_Response
	 */
	protected static function done( $message, array $extra = array() ) {
		return new WP_REST_Response( array_merge( array( 'ok' => true, 'message' => $message ), $extra ) );
	}

	/**
	 * Adresse eines Downloads über admin-post.php mit Nonce.
	 *
	 * @param array  $args   Parameter.
	 * @param string $action Nonce-Aktion.
	 * @return string
	 */
	protected static function download_url( array $args, $action ) {
		$args['_wpnonce'] = wp_create_nonce( $action );

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/* ====================================================================
	 * Reine Regeln (ohne Datenbank, getestet in tests/ErpProduktionTest.php)
	 * ================================================================== */

	/**
	 * Ist das ein echtes Datum im Format JJJJ-MM-TT?
	 *
	 * @param mixed $value Wert.
	 * @return bool
	 */
	public static function valid_date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Zeitraum bereinigen: ungültige Angaben entfallen, vertauschte Grenzen
	 * werden getauscht.
	 *
	 * @param mixed $von Beginn.
	 * @param mixed $bis Ende.
	 * @return array [von, bis], jeweils Datum oder leer.
	 */
	public static function normalize_range( $von, $bis ) {
		$von = self::valid_date( $von ) ? $von : '';
		$bis = self::valid_date( $bis ) ? $bis : '';

		if ( '' !== $von && '' !== $bis && $von > $bis ) {
			return array( $bis, $von );
		}

		return array( $von, $bis );
	}

	/**
	 * Jobs nach Zeitraum und Ausschuss eingrenzen.
	 *
	 * Ausschuss heißt: Es waren mehr Teile geplant als gut. Ein stornierter
	 * Job zählt dabei nicht – er ist gegengebucht.
	 *
	 * @param array $jobs    Zeilen aus dem Druckjournal.
	 * @param array $filters von, bis (Datum), ausschuss (bool).
	 * @return array
	 */
	public static function filter_jobs( array $jobs, array $filters ) {
		$von       = isset( $filters['von'] ) ? (string) $filters['von'] : '';
		$bis       = isset( $filters['bis'] ) ? (string) $filters['bis'] : '';
		$ausschuss = ! empty( $filters['ausschuss'] );
		$out       = array();

		foreach ( $jobs as $job ) {
			$date = isset( $job['job_date'] ) ? substr( (string) $job['job_date'], 0, 10 ) : '';

			if ( '' !== $von && $date < $von ) {
				continue;
			}
			if ( '' !== $bis && $date > $bis ) {
				continue;
			}
			if ( $ausschuss && ( ! empty( $job['voided_at'] ) || (float) $job['parts_planned'] <= (float) $job['parts_ok'] ) ) {
				continue;
			}

			$out[] = $job;
		}

		return $out;
	}

	/**
	 * Summen über die nicht stornierten Jobs.
	 *
	 * @param array $jobs Zeilen.
	 * @return array jobs, planned, ok, scrap, scrap_rate (Anteil), hours, changes.
	 */
	public static function summarize( array $jobs ) {
		$sum = array(
			'jobs'    => 0,
			'planned' => 0.0,
			'ok'      => 0.0,
			'hours'   => 0.0,
			'changes' => 0.0,
		);

		foreach ( $jobs as $job ) {
			if ( ! empty( $job['voided_at'] ) ) {
				continue;
			}
			$sum['jobs']++;
			$sum['planned'] += (float) $job['parts_planned'];
			$sum['ok']      += (float) $job['parts_ok'];
			$sum['hours']   += (float) $job['print_time_h'];
			$sum['changes'] += (float) $job['plate_changes'];
		}

		$sum['scrap']      = $sum['planned'] - $sum['ok'];
		$sum['scrap_rate'] = $sum['planned'] > 0 ? $sum['scrap'] / $sum['planned'] : 0.0;

		return $sum;
	}

	/**
	 * Ampel der Ausschussquote (wie im Druckjournal bisher: über 10 % rot,
	 * über 6 % gelb).
	 *
	 * @param float $percent Quote in Prozent.
	 * @return string rot, gelb oder gruen.
	 */
	public static function scrap_level( $percent ) {
		$percent = (float) $percent;

		return $percent > 10 ? 'rot' : ( $percent > 6 ? 'gelb' : 'gruen' );
	}

	/**
	 * Schlüssel gegen doppeltes Absenden eines Druckjobs.
	 *
	 * Ein zweiter Tipp auf „Buchen“ würde Lagerzugang und Charge ein zweites
	 * Mal anlegen. Gleiche Eingaben derselben Person innerhalb weniger
	 * Sekunden gelten deshalb als derselbe Vorgang.
	 *
	 * @param int   $user_id Person.
	 * @param array $input   Eingaben.
	 * @return string
	 */
	public static function dedupe_key( $user_id, array $input ) {
		ksort( $input );

		return 'lw16_pj_' . md5( (int) $user_id . '|' . json_encode( $input ) );
	}

	/**
	 * Bezeichnung der Herkunft einer Bestellung im Chargenbuch.
	 *
	 * @param string $ref_type Typ aus der Lagerbewegung.
	 * @return string
	 */
	public static function ref_label( $ref_type ) {
		$labels = array(
			'wc_order'  => __( 'Shop-Bestellung', 'lindenweg16' ),
			'order_ops' => __( 'Betriebsauftrag', 'lindenweg16' ),
		);

		return isset( $labels[ $ref_type ] ) ? $labels[ $ref_type ] : (string) $ref_type;
	}

	/**
	 * Eingaben einer Wartung prüfen und für das Repository aufbereiten.
	 *
	 * Das Repository fällt bei einem unbekannten Intervall stillschweigend
	 * auf „wöchentlich“ zurück – hier wird er ausdrücklich abgelehnt, sonst
	 * würde die falsche Checkliste geprüft.
	 *
	 * @param array    $body      Eingaben.
	 * @param string[] $intervals Erlaubte Intervalle.
	 * @return array {input, errors}
	 */
	public static function maintenance_input( array $body, array $intervals ) {
		$errors   = array();
		$interval = isset( $body['interval_type'] ) && is_scalar( $body['interval_type'] ) ? strtolower( trim( (string) $body['interval_type'] ) ) : '';
		$date     = isset( $body['performed_on'] ) && is_scalar( $body['performed_on'] ) ? trim( (string) $body['performed_on'] ) : '';

		if ( ! in_array( $interval, $intervals, true ) ) {
			$errors[] = __( 'Bitte ein Wartungsintervall wählen.', 'lindenweg16' );
		}

		if ( '' !== $date && ! self::valid_date( $date ) ) {
			$errors[] = __( 'Bitte ein gültiges Datum für die Wartung angeben.', 'lindenweg16' );
			$date     = '';
		}

		$tasks = array();
		if ( isset( $body['tasks'] ) && is_array( $body['tasks'] ) ) {
			foreach ( $body['tasks'] as $task ) {
				if ( is_scalar( $task ) ) {
					$tasks[] = trim( (string) $task );
				}
			}
		}

		return array(
			'input'  => array(
				'printer_id'     => isset( $body['printer_id'] ) && is_scalar( $body['printer_id'] ) ? (int) $body['printer_id'] : 0,
				'interval_type'  => $interval,
				'performed_on'   => $date,
				'part_replaced'  => isset( $body['part_replaced'] ) && is_scalar( $body['part_replaced'] ) ? (string) $body['part_replaced'] : '',
				'note'           => isset( $body['note'] ) && is_scalar( $body['note'] ) ? (string) $body['note'] : '',
				'nozzle_changed' => isset( $body['nozzle_changed'] ) ? filter_var( $body['nozzle_changed'], FILTER_VALIDATE_BOOLEAN ) : false,
				'tasks'          => $tasks,
			),
			'errors' => $errors,
		);
	}

	/**
	 * Eingaben eines Geräts mit dem gespeicherten Stand zusammenführen.
	 *
	 * Das Repository überschreibt jedes Feld, das fehlt, mit „leer“. Eine
	 * Anfrage, die nur den Status ändern will, würde sonst Preis, Lebensdauer
	 * und den Düsenzähler löschen.
	 *
	 * @param array $existing Gespeicherte Zeile oder leer bei neuem Gerät.
	 * @param array $body     Eingaben.
	 * @return array
	 */
	public static function merge_device( array $existing, array $body ) {
		$out = array();

		foreach ( self::DEVICE_FIELDS as $field ) {
			if ( array_key_exists( $field, $body ) && is_scalar( $body[ $field ] ) ) {
				$out[ $field ] = (string) $body[ $field ];
			} elseif ( isset( $existing[ $field ] ) && null !== $existing[ $field ] ) {
				$out[ $field ] = (string) $existing[ $field ];
			} else {
				$out[ $field ] = '';
			}
		}

		return $out;
	}

	/**
	 * Stunden seit dem letzten Düsenwechsel.
	 *
	 * @param float $hours   Betriebsstunden gesamt.
	 * @param float $at_last Stand beim letzten Wechsel.
	 * @return float
	 */
	public static function nozzle_hours( $hours, $at_last ) {
		return max( 0.0, (float) $hours - (float) $at_last );
	}

	/**
	 * Drucker-ID der Bridge prüfen: Kleinbuchstaben, Ziffern, _ und -.
	 *
	 * @param mixed $raw Eingabe.
	 * @return string Bereinigte ID oder leer, wenn sie so nicht gilt.
	 */
	public static function normalize_bambu_id( $raw ) {
		$id = strtolower( trim( (string) $raw ) );

		return preg_match( '/^[a-z0-9_\-]{1,50}$/', $id ) ? $id : '';
	}

	/**
	 * Wie steht der Drucker zum Gerätestamm?
	 *
	 * @param int   $geraet_id  Verknüpfung (0 = keine).
	 * @param int[] $device_ids Vorhandene Geräte.
	 * @return string ok, none oder missing.
	 */
	public static function link_state( $geraet_id, array $device_ids ) {
		$geraet_id = (int) $geraet_id;

		if ( $geraet_id < 1 ) {
			return 'none';
		}

		return in_array( $geraet_id, array_map( 'intval', $device_ids ), true ) ? 'ok' : 'missing';
	}

	/**
	 * Geräte, auf die mehr als ein Bambu-Drucker verweist.
	 *
	 * @param int[] $geraet_ids Verknüpfungen aller Drucker (0 = keine).
	 * @return int[]
	 */
	public static function duplicate_links( array $geraet_ids ) {
		$counts = array_count_values( array_filter( array_map( 'intval', $geraet_ids ) ) );

		return array_map( 'intval', array_keys( array_filter( $counts, static function ( $n ) {
			return $n > 1;
		} ) ) );
	}

	/**
	 * Klartext zum gemeldeten Zustand eines Bambu-Druckers.
	 *
	 * @param string $status Meldung der Bridge.
	 * @return string
	 */
	public static function bambu_status_text( $status ) {
		$texte = array(
			'mqtt_getrennt' => __( 'Drucker nicht erreichbar (die Bridge läuft, aber das Gerät antwortet nicht)', 'lindenweg16' ),
			'idle'          => __( 'bereit', 'lindenweg16' ),
			'running'       => __( 'druckt', 'lindenweg16' ),
			'prepare'       => __( 'bereitet vor', 'lindenweg16' ),
			'pause'         => __( 'pausiert', 'lindenweg16' ),
			'finish'        => __( 'fertig', 'lindenweg16' ),
			'failed'        => __( 'Fehler', 'lindenweg16' ),
		);
		$key   = strtolower( (string) $status );

		return isset( $texte[ $key ] ) ? $texte[ $key ] : (string) $status;
	}

	/**
	 * Zustand für die Anzeige: offline, unreachable, busy, paused, error, ready.
	 *
	 * @param bool   $online Meldet die Bridge sich noch?
	 * @param string $status Gemeldeter Zustand.
	 * @return string
	 */
	public static function bambu_state( $online, $status ) {
		if ( ! $online ) {
			return 'offline';
		}

		$status = strtolower( (string) $status );

		if ( 'mqtt_getrennt' === $status ) {
			return 'unreachable';
		}
		if ( in_array( $status, array( 'running', 'prepare' ), true ) ) {
			return 'busy';
		}
		if ( 'pause' === $status ) {
			return 'paused';
		}
		if ( 'failed' === $status ) {
			return 'error';
		}

		return 'ready';
	}

	/**
	 * AMS-Fächer als flache Liste, leere Fächer entfallen.
	 *
	 * @param array $units AMS-Einheiten aus der Bridge.
	 * @return array Liste aus unit, tray, material, color (6 Hex-Zeichen), remain (Prozent oder null).
	 */
	public static function ams_trays( array $units ) {
		$out = array();

		foreach ( $units as $unit ) {
			if ( ! is_array( $unit ) ) {
				continue;
			}
			$unit_id = isset( $unit['id'] ) ? (int) $unit['id'] : 0;

			foreach ( (array) ( isset( $unit['trays'] ) ? $unit['trays'] : array() ) as $tray ) {
				$material = isset( $tray['type'] ) ? trim( (string) $tray['type'] ) : '';

				if ( '' === $material ) {
					continue;
				}

				$color = isset( $tray['color'] ) ? preg_replace( '/[^0-9A-Fa-f]/', '', (string) $tray['color'] ) : '';
				$color = $color ? substr( str_pad( $color, 6, '0' ), 0, 6 ) : 'cccccc';

				$out[] = array(
					'unit'     => $unit_id + 1,
					'tray'     => ( isset( $tray['id'] ) ? (int) $tray['id'] : 0 ) + 1,
					'material' => $material,
					'color'    => strtolower( $color ),
					'remain'   => isset( $tray['remain'] ) && null !== $tray['remain'] && '' !== $tray['remain'] ? (int) $tray['remain'] : null,
				);
			}
		}

		return $out;
	}

	/**
	 * Mengen aus der Kapazitätsvorschau bereinigen.
	 *
	 * @param mixed $map Artikelnummer => Menge.
	 * @return array Artikelnummer (int) => Menge (float, größer 0, höchstens 100000).
	 */
	public static function clean_quantities( $map ) {
		$out = array();

		if ( ! is_array( $map ) ) {
			return $out;
		}

		foreach ( $map as $id => $qty ) {
			$id  = (int) $id;
			$qty = is_scalar( $qty ) ? (float) str_replace( ',', '.', (string) $qty ) : 0.0;

			if ( $id > 0 && $qty > 0 ) {
				$out[ $id ] = min( 100000.0, $qty );
			}
		}

		return $out;
	}

	/**
	 * Bedarf je Bestellung aus dem Gesamtbedarf einer Wochenplanung.
	 *
	 * @param array $demand Ergebnis von CapacityPlanner::demand().
	 * @return array print_time_h, postprocessing_min, purchased (Artikel => Menge).
	 */
	public static function per_order( array $demand ) {
		$orders = max( 1.0, (float) $demand['orders'] );

		return array(
			'print_time_h'       => (float) $demand['print_time_h'] / $orders,
			'postprocessing_min' => (float) $demand['postprocessing_min'] / $orders,
			'purchased'          => array_map(
				static function ( $row ) use ( $orders ) {
					return (float) $row['qty'] / $orders;
				},
				$demand['purchased']
			),
		);
	}

	/**
	 * Lagerbestand je Artikel (nur Lagerort „lager“) aus den Salden.
	 *
	 * @param array $balances Artikel => Farbe => Ort => Menge.
	 * @param int[] $ids      Gesuchte Artikel.
	 * @return array Artikel => Menge.
	 */
	public static function stock_for( array $balances, array $ids ) {
		$out = array();

		foreach ( $ids as $id ) {
			$sum = 0.0;

			if ( isset( $balances[ $id ] ) ) {
				foreach ( $balances[ $id ] as $locations ) {
					$sum += isset( $locations['lager'] ) ? (float) $locations['lager'] : 0.0;
				}
			}

			$out[ $id ] = $sum;
		}

		return $out;
	}

	/**
	 * Nächster Schritt eines Produktionsauftrags ohne Buchung.
	 *
	 * @param string $status Aktueller Status.
	 * @return string Neuer Status oder leer, wenn es hier nicht weitergeht.
	 */
	public static function next_job_status( $status ) {
		return isset( self::JOB_STEPS[ $status ] ) ? self::JOB_STEPS[ $status ] : '';
	}

	/**
	 * Bezeichnung eines Auftragsstatus.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function job_status_text( $status ) {
		$labels = array(
			'geplant'       => __( 'geplant', 'lindenweg16' ),
			'in_produktion' => __( 'in Produktion', 'lindenweg16' ),
			'fertig'        => __( 'fertig, wartet auf Ist-Zeit', 'lindenweg16' ),
			'verpackt'      => __( 'verpackt', 'lindenweg16' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Ausgeschlossene Wochen einlesen (JJJJ-WW, getrennt durch Komma, Semikolon
	 * oder Leerzeichen).
	 *
	 * @param mixed $text Eingabe.
	 * @return array weeks (einheitlich JJJJ-WW), invalid (nicht lesbare Angaben).
	 */
	public static function parse_weeks( $text ) {
		$weeks   = array();
		$invalid = array();

		foreach ( preg_split( '/[\s,;]+/', trim( (string) $text ) ) as $part ) {
			if ( '' === $part ) {
				continue;
			}

			if ( preg_match( '/^(\d{4})-(\d{1,2})$/', $part, $m ) && (int) $m[2] >= 1 && (int) $m[2] <= 53 ) {
				$week           = sprintf( '%04d-%02d', (int) $m[1], (int) $m[2] );
				$weeks[ $week ] = $week;
			} else {
				$invalid[] = $part;
			}
		}

		return array(
			'weeks'   => array_values( $weeks ),
			'invalid' => $invalid,
		);
	}

	/**
	 * Abweichung der Ist- von der Planzeit in Prozent.
	 *
	 * @param float $plan Planwert.
	 * @param float $ist  Istwert.
	 * @return int|null Null, wenn es keinen Plan gibt.
	 */
	public static function deviation_percent( $plan, $ist ) {
		$plan = (float) $plan;

		return $plan > 0 ? (int) round( ( (float) $ist - $plan ) / $plan * 100 ) : null;
	}

	/* ====================================================================
	 * Druckjournal
	 * ================================================================== */

	/**
	 * Journal mit Filtern und Summen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function journal( WP_REST_Request $request ) {
		list( $von, $bis ) = self::normalize_range( (string) $request->get_param( 'von' ), (string) $request->get_param( 'bis' ) );

		$jobs = PrintJobRepository::all(
			array(
				'printer_id' => (int) $request->get_param( 'printer_id' ),
				'article_id' => (int) $request->get_param( 'article_id' ),
				'limit'      => 1000,
			)
		);
		$jobs = self::filter_jobs(
			$jobs,
			array(
				'von'       => $von,
				'bis'       => $bis,
				'ausschuss' => '1' === (string) $request->get_param( 'ausschuss' ),
			)
		);

		$summary  = self::summarize( $jobs );
		$total    = count( $jobs );
		$shown    = array_slice( $jobs, 0, 200 );
		$printers = array();
		foreach ( PrinterRepository::all() as $printer ) {
			$printers[ (int) $printer['id'] ] = $printer['code'];
		}
		$articles = ArticleRepository::all_by_id();
		$failures = PrintJobRepository::failure_labels();
		$rows     = array();

		foreach ( $shown as $job ) {
			$m       = PrintJobRepository::metrics( $job );
			$percent = $m['scrap_rate'] * 100;
			$aid     = (int) $job['article_id'];

			$rows[] = array(
				'id'          => (int) $job['id'],
				'date'        => substr( (string) $job['job_date'], 0, 10 ),
				'week'        => IsoWeek::label( $job['iso_year'], $job['iso_week'] ),
				'printer'     => isset( $printers[ (int) $job['printer_id'] ] ) ? $printers[ (int) $job['printer_id'] ] : '',
				'sku'         => isset( $articles[ $aid ] ) ? $articles[ $aid ]['sku'] : '',
				'article'     => isset( $articles[ $aid ] ) ? $articles[ $aid ]['name'] : '',
				'color'       => ColorRegistry::label( $job['color'] ),
				'planned'     => (float) $job['parts_planned'],
				'ok'          => (float) $job['parts_ok'],
				'scrap'       => (float) $m['parts_scrap'],
				'rate'        => round( $percent, 1 ),
				'level'       => self::scrap_level( $percent ),
				'reason'      => isset( $failures[ $job['failure_reason'] ] ) ? $failures[ $job['failure_reason'] ] : (string) $job['failure_reason'],
				'hours'       => (float) $job['print_time_h'],
				'changes'     => (float) $job['plate_changes'],
				'per_change'  => round( (float) $m['parts_per_plate_change'], 1 ),
				'note'        => (string) $job['note'],
				'voided'      => ! empty( $job['voided_at'] ),
				'void_reason' => (string) $job['void_reason'],
			);
		}

		$export = '';
		if ( Access::can( 'finanzen.berichte' ) && Access::can( 'finanzen.export' ) ) {
			$export = self::download_url( array( 'action' => 'lw16_betrieb_export', 'what' => 'print_jobs' ), 'lw16_betrieb_export' );
		}

		return new WP_REST_Response(
			array(
				'jobs'       => $rows,
				'total'      => $total,
				'summary'    => array(
					'jobs'       => $summary['jobs'],
					'planned'    => $summary['planned'],
					'ok'         => $summary['ok'],
					'scrap'      => $summary['scrap'],
					'rate'       => round( $summary['scrap_rate'] * 100, 1 ),
					'level'      => self::scrap_level( $summary['scrap_rate'] * 100 ),
					'hours'      => round( $summary['hours'], 1 ),
					'changes'    => $summary['changes'],
				),
				'can_void'   => self::allowed( array( 'fertigung.druckjournal' ), 'lw16_manage_production' ),
				'export_url' => $export,
				'mobile_url' => home_url( '/betrieb-erfassen/' ),
			)
		);
	}

	/**
	 * Auswahllisten für das Erfassungsformular.
	 *
	 * @return WP_REST_Response
	 */
	public static function journal_options() {
		$printers = array();
		foreach ( PrinterRepository::all() as $printer ) {
			$printers[] = array(
				'id'    => (int) $printer['id'],
				'code'  => $printer['code'],
				'label' => $printer['code'] . ' – ' . trim( (string) $printer['manufacturer'] . ' ' . (string) $printer['model'] ),
			);
		}

		// Neu drucken lässt sich nur, was noch angeboten wird; zugekaufte und
		// montierte Artikel werden nicht gedruckt.
		$revisions = RevisionRepository::active_by_article();
		$articles  = array();
		foreach ( ArticleRepository::selectable() as $article ) {
			if ( in_array( $article['sourcing_type'], array( 'zukauf', 'montage' ), true ) ) {
				continue;
			}
			$rev        = isset( $revisions[ (int) $article['id'] ] ) ? $revisions[ (int) $article['id'] ] : null;
			$articles[] = array(
				'id'       => (int) $article['id'],
				'sku'      => $article['sku'],
				'name'     => $article['name'],
				'revision' => $rev ? (string) $rev['version'] : '',
			);
		}

		$colors = array();
		foreach ( ColorRegistry::all() as $slug => $label ) {
			$colors[] = array( 'slug' => $slug, 'label' => $label );
		}

		$orders = array();
		foreach ( WorkOrderRepository::unfinished() as $order ) {
			if ( 'druck' !== $order['kind'] ) {
				continue;
			}
			$orders[] = array(
				'id'          => (int) $order['id'],
				'code'        => $order['code'],
				'printer_id'  => (int) $order['printer_id'],
				'article_id'  => (int) $order['article_id'],
				'color'       => (string) $order['color'],
				'qty_planned' => (float) $order['qty_planned'],
			);
		}

		$failures = array();
		foreach ( PrintJobRepository::failure_labels() as $key => $label ) {
			$failures[] = array( 'key' => $key, 'label' => $label );
		}

		return new WP_REST_Response(
			array(
				'printers'      => $printers,
				'articles'      => $articles,
				'colors'        => $colors,
				'default_color' => ColorRegistry::default_color(),
				'orders'        => $orders,
				'failures'      => $failures,
				'today'         => gmdate( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Druckjob erfassen (nachtragen): Lagerzugang, Charge, Kleinteile.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function journal_save( WP_REST_Request $request ) {
		$input = self::scalars( self::body( $request ) );

		if ( isset( $input['job_date'] ) && '' !== trim( (string) $input['job_date'] ) && ! self::valid_date( trim( (string) $input['job_date'] ) ) ) {
			return self::fail( __( 'Bitte ein gültiges Datum für den Druckjob wählen.', 'lindenweg16' ) );
		}

		$result = PrintJobRepository::validate( $input );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', $result['errors'] ) );
		}

		$key = self::dedupe_key( get_current_user_id(), $input );
		if ( get_transient( $key ) ) {
			return self::fail( __( 'Genau dieser Druckjob wurde gerade eben schon gebucht. Bitte im Journal nachsehen, bevor du ihn noch einmal buchst.', 'lindenweg16' ), 409 );
		}
		set_transient( $key, 1, 30 );

		$id = PrintJobRepository::insert( $result['data'] );

		if ( ! $id ) {
			delete_transient( $key );

			return self::fail( __( 'Der Druckjob konnte nicht gespeichert werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		// Kam der Job aus einem Druckauftrag, ist der damit erledigt. Der
		// Zugang ist oben schon gebucht – der Auftrag wird nur geschlossen.
		$order_id = isset( $input['work_order_id'] ) ? (int) $input['work_order_id'] : 0;
		$message  = __( 'Druckjob erfasst, Lagerzugang und Charge gebucht.', 'lindenweg16' );

		if ( $order_id && WorkOrderRepository::close_by_print_job( $order_id, $id, (float) $result['data']['parts_ok'] ) ) {
			$message = __( 'Druckjob erfasst und Druckauftrag fertiggemeldet.', 'lindenweg16' );
		}

		return self::done( $message, array( 'id' => $id ) );
	}

	/**
	 * Druckjob stornieren: Gegenbuchung, nie Löschen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function journal_void( WP_REST_Request $request ) {
		$body   = self::scalars( self::body( $request ) );
		$reason = isset( $body['reason'] ) ? sanitize_text_field( (string) $body['reason'] ) : '';
		$result = PrintJobRepository::void( (int) $request['id'], $reason );

		return $result['success'] ? self::done( $result['message'] ) : self::fail( $result['message'] );
	}

	/* ====================================================================
	 * Drucker & Wartung
	 * ================================================================== */

	/**
	 * Zeile eines Geräts für die Liste.
	 *
	 * @param array $printer  Gerät.
	 * @param array $settings Annahmen.
	 * @return array
	 */
	protected static function device_row( array $printer, array $settings ) {
		$b      = MachineRate::breakdown( $printer, $settings );
		$labels = PrinterRepository::status_labels();
		$hours  = PrintJobRepository::operating_hours( (int) $printer['id'] );

		// Die Druckerakte gehört dem Bambu-Drucker (Schlüssel wie bambu_p1s_1), nicht
		// dem Gerät. Ohne verknüpften Bambu-Drucker gibt es keine Akte zu öffnen.
		$bambu = '';
		foreach ( BambuPrinterRepository::all() as $bp ) {
			if ( (int) $bp->geraet_id === (int) $printer['id'] ) {
				$bambu = (string) $bp->printer_id;
				break;
			}
		}

		return array(
			'id'           => (int) $printer['id'],
			'bambu'        => $bambu,
			'code'         => $printer['code'],
			'manufacturer' => (string) $printer['manufacturer'],
			'model'        => (string) $printer['model'],
			'status'       => $printer['status'],
			'status_text'  => isset( $labels[ $printer['status'] ] ) ? $labels[ $printer['status'] ] : $printer['status'],
			'productive'   => in_array( $printer['status'], MachineRate::PRODUCTIVE_STATUS, true ),
			'acquired_on'  => (string) $printer['acquired_on'],
			'cost'         => null === $printer['acquisition_cost'] ? null : (float) $printer['acquisition_cost'],
			'hours'        => round( $hours, 1 ),
			'nozzle_hours' => round( self::nozzle_hours( $hours, (float) $printer['hours_at_last_nozzle_change'] ), 1 ),
			'rate'         => array(
				'abschreibung' => round( $b['abschreibung'], 4 ),
				'verschleiss'  => round( $b['verschleiss'], 4 ),
				'energie'      => round( $b['energie'], 4 ),
				'flaeche'      => round( $b['flaeche'], 4 ),
				'summe'        => round( $b['summe'], 4 ),
			),
			'reasons'      => MaintenanceRepository::reasons_for( $printer ),
		);
	}

	/**
	 * Annahmen, die leere Gerätefelder ersetzen (für die Hinweise im Formular).
	 *
	 * @param array $settings Annahmen.
	 * @return array
	 */
	protected static function device_defaults( array $settings ) {
		return array(
			'lifetime_h'             => (float) $settings['printer_lifetime_h'],
			'power_cost_per_h'       => round( (float) $settings['printer_power_kw'] * (float) $settings['power_price_per_kwh'], 4 ),
			'spare_parts_per_year'   => (float) $settings['spare_parts_per_year'],
			'annual_operating_hours' => (float) $settings['annual_operating_hours'],
		);
	}

	/**
	 * Geräteliste mit Stundensatz, Wartungsfälligkeit und Checklisten.
	 *
	 * @return WP_REST_Response
	 */
	public static function devices() {
		$printers = PrinterRepository::all();
		$settings = CalcSettings::for_date();
		$rows     = array();

		foreach ( $printers as $printer ) {
			$rows[] = self::device_row( $printer, $settings );
		}

		$fleet = MachineRate::fleet_breakdown( $printers, $settings );

		$lists = array();
		foreach ( MaintenanceChecklist::intervals() as $key => $label ) {
			$items = array();
			foreach ( MaintenanceChecklist::items( $key ) as $item_key => $text ) {
				$items[] = array( 'key' => $item_key, 'text' => $text );
			}
			$lists[] = array(
				'key'          => $key,
				'label'        => $label,
				'items'        => $items,
				'require_all'  => MaintenanceChecklist::requires_all( $key ),
				'includes'     => in_array( $key, array( 'monatlich', 'jaehrlich' ), true ),
			);
		}

		$labels_url = '';
		if ( Access::can( 'verwaltung.etiketten' ) && Access::can( 'finanzen.berichte' ) && Access::can( 'finanzen.export' ) ) {
			$labels_url = self::download_url(
				array(
					'action' => 'lw16_betrieb_labels',
					'was'    => array( 'printers' ),
					'format' => LabelSheet::DEFAULT_ROLL,
				),
				'lw16_betrieb_export'
			);
		}

		return new WP_REST_Response(
			array(
				'devices'    => $rows,
				'fleet'      => array(
					'rate'  => round( $fleet['rate'], 4 ),
					'count' => (int) $fleet['printer_count'],
				),
				'defaults'   => self::device_defaults( $settings ),
				'checklists' => $lists,
				'statuses'   => self::status_options(),
				'can_edit'   => self::allowed( array( 'fertigung.drucker', 'fertigung.stammdaten.edit' ), 'lw16_manage_production' ),
				'labels_url' => $labels_url,
				'today'      => gmdate( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Gerätestatus als Auswahlliste.
	 *
	 * @return array
	 */
	protected static function status_options() {
		$out = array();
		foreach ( PrinterRepository::status_labels() as $key => $label ) {
			$out[] = array( 'key' => $key, 'label' => $label );
		}

		return $out;
	}

	/**
	 * Ein Gerät mit allen Feldern und seiner Wartungshistorie.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function device( WP_REST_Request $request ) {
		$printer = PrinterRepository::find( (int) $request['id'] );

		if ( ! $printer ) {
			return self::fail( __( 'Dieses Gerät gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		$fields = self::merge_device( $printer, array() );
		$intervals = MaintenanceChecklist::intervals();
		$history   = array();

		foreach ( array_slice( MaintenanceRepository::for_printer( (int) $printer['id'] ), 0, 25 ) as $entry ) {
			$tasks = json_decode( (string) $entry['tasks_json'], true );
			$user  = $entry['performed_by'] ? get_userdata( (int) $entry['performed_by'] ) : null;

			$history[] = array(
				'id'       => (int) $entry['id'],
				'date'     => (string) $entry['performed_on'],
				'interval' => isset( $intervals[ $entry['interval_type'] ] ) ? $intervals[ $entry['interval_type'] ] : (string) $entry['interval_type'],
				'tasks'    => MaintenanceChecklist::summary( $entry['interval_type'], is_array( $tasks ) ? $tasks : array() ),
				'part'     => (string) $entry['part_replaced'],
				'hours'    => round( (float) $entry['operating_hours_at'], 1 ),
				'note'     => (string) $entry['note'],
				'by'       => $user ? $user->display_name : '',
			);
		}

		return new WP_REST_Response(
			array(
				'device'  => array_merge( array( 'id' => (int) $printer['id'] ), $fields ),
				'history' => $history,
			)
		);
	}

	/**
	 * Gerät anlegen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function device_create( WP_REST_Request $request ) {
		$input  = self::merge_device( array(), self::body( $request ) );
		$result = PrinterRepository::validate( $input, null );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', array_unique( $result['errors'] ) ) );
		}

		$id = PrinterRepository::insert( $result['data'] );

		if ( ! $id ) {
			return self::fail( __( 'Das Gerät konnte nicht angelegt werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return self::done( __( 'Gerät angelegt.', 'lindenweg16' ), array( 'id' => $id ) );
	}

	/**
	 * Gerät ändern; die Begründung erscheint im Protokoll.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function device_update( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$printer = PrinterRepository::find( $id );

		if ( ! $printer ) {
			return self::fail( __( 'Dieses Gerät gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		$body   = self::body( $request );
		$input  = self::merge_device( $printer, $body );
		$reason = isset( $body['reason'] ) && is_scalar( $body['reason'] ) ? sanitize_text_field( (string) $body['reason'] ) : '';
		$result = PrinterRepository::validate( $input, $id );

		if ( $result['errors'] ) {
			return self::fail( implode( ' ', array_unique( $result['errors'] ) ) );
		}

		if ( ! PrinterRepository::update( $id, $result['data'], $reason ) ) {
			return self::fail( __( 'Das Gerät konnte nicht gespeichert werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return self::done( __( 'Gerät gespeichert.', 'lindenweg16' ) );
	}

	/**
	 * Wartung erfassen. Die Checkliste prüft der Server, nicht erst der Browser.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function maintenance_save( WP_REST_Request $request ) {
		$body                = self::body( $request );
		$body['printer_id']  = (int) $request['id'];
		$prepared            = self::maintenance_input( $body, MaintenanceRepository::INTERVALS );

		if ( $prepared['errors'] ) {
			return self::fail( implode( ' ', $prepared['errors'] ) );
		}

		$input = $prepared['input'];

		if ( ! PrinterRepository::find( (int) $input['printer_id'] ) ) {
			return self::fail( __( 'Dieses Gerät gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		$check = MaintenanceRepository::validate( $input );

		if ( $check['errors'] ) {
			return self::fail( implode( ' ', $check['errors'] ) );
		}

		// Doppeltes Absenden derselben Wartung verhindern.
		$key = self::dedupe_key( get_current_user_id(), $input );
		if ( get_transient( $key ) ) {
			return self::fail( __( 'Diese Wartung wurde gerade eben schon erfasst.', 'lindenweg16' ), 409 );
		}
		set_transient( $key, 1, 30 );

		if ( ! MaintenanceRepository::insert( $input ) ) {
			delete_transient( $key );

			return self::fail( __( 'Die Wartung konnte nicht gespeichert werden. Bitte noch einmal versuchen.', 'lindenweg16' ), 500 );
		}

		return self::done( __( 'Wartung erfasst.', 'lindenweg16' ) );
	}

	/* ====================================================================
	 * Bambu-Lab-Drucker
	 * ================================================================== */

	/**
	 * Drucker mit Live-Stand und Geräteverknüpfung.
	 *
	 * Der LAN-Zugangscode verlässt den Server nie – gemeldet wird nur, ob
	 * einer hinterlegt ist. IP und Seriennummer sehen nur Personen, die die
	 * Drucker verwalten dürfen.
	 *
	 * @return WP_REST_Response
	 */
	public static function bambu_list() {
		$manage  = self::allowed( array( 'fertigung.bambu', 'fertigung.stammdaten.edit' ), 'lw16_manage_production' );
		$devices = array();
		foreach ( PrinterRepository::all() as $device ) {
			$devices[ (int) $device['id'] ] = $device;
		}

		$printers = BambuPrinterRepository::all();
		$links    = array();
		foreach ( $printers as $printer ) {
			$links[] = (int) $printer->geraet_id;
		}
		$doubles = self::duplicate_links( $links );

		$rows = array();
		foreach ( $printers as $printer ) {
			$online = BambuPrinterRepository::is_online( $printer );
			$link   = self::link_state( (int) $printer->geraet_id, array_keys( $devices ) );
			$seen   = $printer->last_seen_at ? strtotime( $printer->last_seen_at . ' UTC' ) : 0;
			$bed    = BambuPrinterRepository::effective_bed_size( $printer );

			$row = array(
				'id'          => (int) $printer->id,
				'printer_id'  => $printer->printer_id,
				'name'        => $printer->name,
				'online'      => $online,
				'state'       => self::bambu_state( $online, $printer->status ),
				'status_text' => $printer->status ? self::bambu_status_text( $printer->status ) : '',
				'last_seen'   => $seen ? (int) $seen : 0,
				'progress'    => $online && null !== $printer->progress_percent ? (int) $printer->progress_percent : null,
				'remaining'   => $online && null !== $printer->remaining_minutes ? (int) $printer->remaining_minutes : null,
				'nozzle_temp' => $online && null !== $printer->nozzle_temp ? (float) $printer->nozzle_temp : null,
				'bed_temp'    => $online && null !== $printer->bed_temp ? (float) $printer->bed_temp : null,
				'last_error'  => (string) $printer->last_error,
				'model'       => (string) $printer->printer_model,
				'queued'      => BambuJobRepository::count_queued( $printer->printer_id ),
				'bed_clear'   => BambuPrinterRepository::bed_is_clear( $printer ),
				'unclear_job' => self::unclear_job( $printer->printer_id ),
				'ams'         => $online ? self::ams_trays( BambuPrinterRepository::ams_units( $printer ) ) : array(),
				'bed'         => $bed ? $bed['width_mm'] . ' × ' . $bed['height_mm'] . ' mm' : '',
				'link'        => $link,
				'link_double' => in_array( (int) $printer->geraet_id, $doubles, true ),
				'device'      => 'ok' === $link ? $devices[ (int) $printer->geraet_id ]['code'] : '',
				'snapshot'    => $printer->snapshot_at
					? self::download_url(
						array(
							'action'     => 'lw16_bambu_snapshot',
							'printer_id' => $printer->printer_id,
							't'          => (int) strtotime( $printer->snapshot_at . ' UTC' ),
						),
						'lw16_bambu_printers_snapshot'
					)
					: '',
			);

			if ( $manage ) {
				$row['ip']             = (string) $printer->ip;
				$row['serial']         = (string) $printer->bambu_serial;
				$row['geraet_id']      = (int) $printer->geraet_id;
				$row['bed_width_mm']   = $printer->bed_width_mm ? (int) $printer->bed_width_mm : '';
				$row['bed_height_mm']  = $printer->bed_height_mm ? (int) $printer->bed_height_mm : '';
			}

			$rows[] = $row;
		}

		$options = array();
		foreach ( $devices as $device ) {
			$options[] = array(
				'id'    => (int) $device['id'],
				'label' => $device['code'] . ' — ' . trim( (string) $device['manufacturer'] . ' ' . (string) $device['model'] ),
			);
		}

		return new WP_REST_Response(
			array(
				'printers' => $rows,
				'devices'  => $options,
				'manage'   => $manage,
				'now'      => time(),
			)
		);
	}

	/**
	 * Verknüpftes Gerät prüfen.
	 *
	 * @param int $geraet_id Gerät (0 = keine Verknüpfung).
	 * @return string Fehlertext oder leer.
	 */
	protected static function check_link( $geraet_id ) {
		if ( $geraet_id && ! PrinterRepository::find( $geraet_id ) ) {
			return __( 'Das gewählte Gerät gibt es nicht (mehr). Bitte ein Gerät aus der Liste wählen.', 'lindenweg16' );
		}

		return '';
	}

	/**
	 * Neuen Bambu-Drucker anlegen. Das Token steht nur in dieser Antwort.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function bambu_create( WP_REST_Request $request ) {
		$body = self::scalars( self::body( $request ) );
		$id   = self::normalize_bambu_id( isset( $body['printer_id'] ) ? $body['printer_id'] : '' );
		$name = isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '';
		$geraet = isset( $body['geraet_id'] ) ? (int) $body['geraet_id'] : 0;

		if ( '' === $id || '' === $name ) {
			return self::fail( __( 'Drucker-ID und Name sind Pflicht. Die ID darf nur Kleinbuchstaben, Ziffern, Unterstrich und Bindestrich enthalten, z. B. bambu_p1s_1.', 'lindenweg16' ) );
		}

		if ( BambuPrinterRepository::find_by_printer_id( $id ) ) {
			return self::fail( __( 'Diese Drucker-ID gibt es schon. Bitte eine andere wählen.', 'lindenweg16' ) );
		}

		$link_error = self::check_link( $geraet );
		if ( $link_error ) {
			return self::fail( $link_error );
		}

		$result = BambuPrinterRepository::create(
			$id,
			$name,
			isset( $body['bambu_serial'] ) ? (string) $body['bambu_serial'] : '',
			isset( $body['ip'] ) ? (string) $body['ip'] : '',
			$geraet
		);

		if ( ! $result['id'] ) {
			return self::fail(
				$result['error']
					? sprintf(
						/* translators: %s: Datenbankfehler. */
						__( 'Der Drucker konnte nicht gespeichert werden: %s', 'lindenweg16' ),
						$result['error']
					)
					: __( 'Der Drucker konnte nicht gespeichert werden.', 'lindenweg16' ),
				500
			);
		}

		return self::done( __( 'Drucker angelegt.', 'lindenweg16' ), array( 'id' => $result['id'], 'token' => $result['token'] ) );
	}

	/**
	 * Verbindungsdaten ändern. Fehlende Felder bleiben unverändert.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function bambu_update( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$body = self::scalars( self::body( $request ) );

		if ( ! BambuPrinterRepository::find( $id ) ) {
			return self::fail( __( 'Diesen Drucker gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		$data = array();
		foreach ( array( 'name', 'ip', 'bambu_serial', 'printer_model' ) as $field ) {
			if ( array_key_exists( $field, $body ) ) {
				$data[ $field ] = (string) $body[ $field ];
			}
		}

		if ( array_key_exists( 'name', $data ) && '' === trim( sanitize_text_field( $data['name'] ) ) ) {
			return self::fail( __( 'Der Name darf nicht leer sein.', 'lindenweg16' ) );
		}

		if ( array_key_exists( 'geraet_id', $body ) ) {
			$data['geraet_id'] = (int) $body['geraet_id'];
			$link_error        = self::check_link( $data['geraet_id'] );
			if ( $link_error ) {
				return self::fail( $link_error );
			}
		}

		// Leeres Feld heißt „automatisch nach gemeldetem Modell“ – ausdrücklich
		// null, nicht 0.
		foreach ( array( 'bed_width_mm', 'bed_height_mm' ) as $field ) {
			if ( array_key_exists( $field, $body ) ) {
				$value = trim( (string) $body[ $field ] );
				if ( '' !== $value && ( ! ctype_digit( $value ) || (int) $value < 1 ) ) {
					return self::fail( __( 'Das Druckbettmaß muss eine ganze Zahl in Millimetern sein, oder leer bleiben.', 'lindenweg16' ) );
				}
				$data[ $field ] = '' === $value ? null : (int) $value;
			}
		}

		if ( ! BambuPrinterRepository::update( $id, $data ) ) {
			return self::fail( __( 'Die Verbindungsdaten konnten nicht gespeichert werden.', 'lindenweg16' ), 500 );
		}

		return self::done( __( 'Verbindungsdaten gespeichert.', 'lindenweg16' ) );
	}

	/**
	 * Token neu erzeugen; das alte ist sofort ungültig.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function bambu_token( WP_REST_Request $request ) {
		$token = BambuPrinterRepository::regenerate_token( (int) $request['id'] );

		if ( '' === $token ) {
			return self::fail( __( 'Diesen Drucker gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		return self::done( __( 'Neues Token erzeugt. Es wird nur jetzt angezeigt.', 'lindenweg16' ), array( 'token' => $token ) );
	}

	/**
	 * Unklarer Auftrag eines Druckers, der seine Warteschlange sperrt.
	 *
	 * @param string $printer_id Drucker.
	 * @return array|null {id, file, message}
	 */
	protected static function unclear_job( $printer_id ) {
		$job = BambuJobRepository::active_for_printer( $printer_id );

		if ( ! $job || 'unklar' !== $job->status ) {
			return null;
		}

		return array(
			'id'      => (int) $job->id,
			'file'    => (string) $job->file_name,
			'message' => (string) $job->error_message,
		);
	}

	/**
	 * „Platte ist frei“: Jemand hat am Drucker die Platte geräumt. Erst
	 * danach liefert die Warteschlange den nächsten Auftrag aus.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function bambu_bed_clear( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = BambuPrinterRepository::mark_bed_clear( $id );

		if ( ! $result['ok'] ) {
			return 'printing' === $result['error']
				? self::fail( __( 'Der Drucker meldet gerade einen laufenden Druck – die Platte kann noch nicht frei sein.', 'lindenweg16' ), 409 )
				: self::fail( __( 'Diesen Drucker gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		$printer = BambuPrinterRepository::find( $id );
		\Lindenweg16\Core\AuditLog::record( 'bambu_platte_frei', 'bambu_printer', $id, array( 'drucker' => $printer ? $printer->printer_id : '' ) );

		return self::done( __( 'Platte als frei bestätigt. Der nächste Auftrag wird jetzt ausgeliefert.', 'lindenweg16' ) );
	}

	/**
	 * Drucker löschen – samt seiner Druckaufträge in der Warteschlange.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function bambu_delete( WP_REST_Request $request ) {
		if ( ! BambuPrinterRepository::delete( (int) $request['id'] ) ) {
			return self::fail( __( 'Diesen Drucker gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		return self::done( __( 'Drucker gelöscht.', 'lindenweg16' ) );
	}

	/* ====================================================================
	 * Qualität
	 * ================================================================== */

	/**
	 * Chargenbuch.
	 *
	 * @return WP_REST_Response
	 */
	public static function batches() {
		$articles = ArticleRepository::all_by_id();
		$rows     = array();

		foreach ( BatchRepository::all( array( 'limit' => 200 ) ) as $batch ) {
			$aid    = (int) $batch['article_id'];
			$rows[] = array(
				'id'        => (int) $batch['id'],
				'code'      => $batch['batch_code'],
				'date'      => substr( (string) $batch['produced_on'], 0, 10 ),
				'sku'       => isset( $articles[ $aid ] ) ? $articles[ $aid ]['sku'] : '',
				'article'   => isset( $articles[ $aid ] ) ? $articles[ $aid ]['name'] : '',
				'qty'       => (float) $batch['qty'],
				'inspected' => (bool) $batch['inspected'],
			);
		}

		return new WP_REST_Response( array( 'batches' => $rows ) );
	}

	/**
	 * Chargenakte.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function batch( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$dossier = Traceability::dossier( $id );

		if ( ! $dossier ) {
			return self::fail( __( 'Diese Charge gibt es nicht (mehr).', 'lindenweg16' ), 404 );
		}

		$gpsr = '';
		if ( Access::can( 'fertigung.artikel' ) ) {
			$gpsr = self::download_url( array( 'action' => 'lw16_betrieb_gpsr', 'batch' => $id ), 'lw16_betrieb_gpsr' );
		}

		return new WP_REST_Response(
			array(
				'id'        => $id,
				'code'      => $dossier['batch']['batch_code'],
				'date'      => substr( (string) $dossier['batch']['produced_on'], 0, 10 ),
				'article'   => $dossier['article'] ? $dossier['article']['sku'] . ' – ' . $dossier['article']['name'] : '',
				'revision'  => $dossier['revision'] ? (string) $dossier['revision']['version'] : '',
				'material'  => $dossier['revision'] ? (string) $dossier['revision']['material'] : '',
				'color'     => $dossier['lot'] ? trim( ColorRegistry::label( (string) $dossier['lot']['color'] ) . ' ' . (string) $dossier['lot']['material'] ) : '',
				'printer'   => $dossier['printer'] ? (string) $dossier['printer']['code'] : '',
				'qty'       => (float) $dossier['batch']['qty'],
				'inspected' => (bool) $dossier['batch']['inspected'],
				'orders'    => self::order_rows( $dossier['orders'] ),
				'gpsr_url'  => $gpsr,
			)
		);
	}

	/**
	 * Betroffene Bestellungen aufbereiten.
	 *
	 * @param array $orders Lagerbewegungen.
	 * @return array
	 */
	protected static function order_rows( array $orders ) {
		$out = array();

		foreach ( $orders as $order ) {
			$out[] = array(
				'source' => self::ref_label( $order['ref_type'] ),
				'ref'    => (int) $order['ref_id'],
				'date'   => substr( (string) $order['moved_at'], 0, 10 ),
				'qty'    => abs( (float) $order['qty'] ),
			);
		}

		return $out;
	}

	/* ====================================================================
	 * Kapazität
	 * ================================================================== */

	/**
	 * Anzahl produzierender Geräte heute.
	 *
	 * @param array $printers Geräte.
	 * @return int
	 */
	protected static function productive_count( array $printers ) {
		return MachineRate::productive_at( $printers );
	}

	/**
	 * Auslastungsverlauf der letzten acht Wochen und Artikel für die Vorschau.
	 *
	 * @return WP_REST_Response
	 */
	public static function capacity() {
		$settings = CalculationContext::settings();
		$printers = PrinterRepository::all();
		$current  = IsoWeek::from_date( gmdate( 'Y-m-d' ) );
		$weeks    = array();

		for ( $i = 7; $i >= 0; $i-- ) {
			$week   = IsoWeek::shift( $current['iso_year'], $current['iso_week'], -$i );
			$bounds = IsoWeek::bounds( $week['iso_year'], $week['iso_week'] );
			$agg    = PrintJobRepository::aggregate( $bounds['von'], $bounds['bis'] );
			// Geräte, die es in der Woche noch nicht gab, zählen nicht mit.
			$count  = MachineRate::productive_at( $printers, $bounds['bis'] );
			$util   = CapacityPlanner::utilisation( $agg['print_time_h'], $count, (float) $settings['net_hours_per_printer'] );

			$weeks[] = array(
				'label'       => IsoWeek::label( $week['iso_year'], $week['iso_week'] ),
				'print_h'     => round( (float) $agg['print_time_h'], 1 ),
				'capacity_h'  => round( $util['capacity_h'], 0 ),
				'utilisation' => round( $util['utilisation'] * 100, 0 ),
				'level'       => $util['level'],
				'changes'     => (float) $agg['plate_changes'],
				'operator_h'  => round( CapacityPlanner::operator_hours( $agg['plate_changes'], (float) $settings['plate_change_minutes'] ), 1 ),
				'work_h'      => round( TimeRepository::hours_in_week( $week['iso_year'], $week['iso_week'] ), 1 ),
			);
		}

		$articles = array();
		foreach ( ArticleRepository::selectable( true ) as $article ) {
			$articles[] = array( 'id' => (int) $article['id'], 'sku' => $article['sku'], 'name' => $article['name'] );
		}

		return new WP_REST_Response(
			array(
				'weeks'    => $weeks,
				'articles' => $articles,
				'printers' => self::productive_count( $printers ),
				'net_h'    => (float) $settings['net_hours_per_printer'],
			)
		);
	}

	/**
	 * Wochenvorschau rechnen: erwartete Bestellungen je Artikel.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function capacity_calc( WP_REST_Request $request ) {
		$body     = self::body( $request );
		$mengen   = self::clean_quantities( isset( $body['mengen'] ) ? $body['mengen'] : array() );

		if ( ! $mengen ) {
			return self::fail( __( 'Bitte bei mindestens einem Artikel eine erwartete Stückzahl größer 0 eintragen.', 'lindenweg16' ) );
		}

		$settings = CalculationContext::settings();
		$ctx      = CalculationContext::build();
		$printers = self::productive_count( PrinterRepository::all() );
		$bundles  = array();

		foreach ( $mengen as $article_id => $qty ) {
			$bundles[] = array(
				'items' => array( array( 'article_id' => $article_id, 'qty' => 1 ) ),
				'count' => $qty,
			);
		}

		$demand      = CapacityPlanner::demand( $bundles, $ctx, $settings );
		$utilisation = CapacityPlanner::utilisation( $demand['print_time_h'], $printers, (float) $settings['net_hours_per_printer'] );

		// Einmal alle Bewegungen, nicht je Artikel eine eigene Abfrage.
		$stock = self::stock_for( StockRepository::balances(), array_keys( $demand['purchased'] ) );

		$backwards = CapacityPlanner::backwards(
			self::per_order( $demand ),
			array(
				'print_h' => $utilisation['capacity_h'],
				'work_h'  => 14.0,
				'stock'   => $stock,
			)
		);

		$purchased = array();
		foreach ( $demand['purchased'] as $article_id => $row ) {
			$purchased[] = array(
				'sku'   => $row['sku'],
				'qty'   => round( (float) $row['qty'], 1 ),
				'stock' => round( $stock[ $article_id ], 1 ),
			);
		}

		return new WP_REST_Response(
			array(
				'print_h'      => round( (float) $demand['print_time_h'], 1 ),
				'capacity_h'   => round( $utilisation['capacity_h'], 1 ),
				'post_h'       => round( (float) $demand['postprocessing_min'] / 60, 1 ),
				'utilisation'  => round( $utilisation['utilisation'] * 100, 1 ),
				'level'        => $utilisation['level'],
				'purchased'    => $purchased,
				'max_orders'   => $backwards['max_orders'],
				'limiting'     => $backwards['limiting_label'],
				'printers'     => $printers,
			)
		);
	}

	/* ====================================================================
	 * Produktion (Modul Produktionsplanung)
	 * ================================================================== */

	/**
	 * Offene Aufträge, Ist gegen Plan und ausgeschlossene Wochen.
	 *
	 * @return WP_REST_Response
	 */
	public static function jobs() {
		$jobs = array();

		foreach ( ProductionRepository::open_jobs() as $job ) {
			$product = InventoryRepository::get_product( $job->produkt_id );
			$next    = self::next_job_status( $job->status );

			$jobs[] = array(
				'id'      => (int) $job->id,
				'name'    => $product ? $product->name : '#' . (int) $job->produkt_id,
				'qty'     => (float) $job->menge,
				'status'  => $job->status,
				'text'    => self::job_status_text( $job->status ),
				'next'    => $next,
				'next_text' => $next ? self::job_status_text( $next ) : '',
				'created' => substr( (string) $job->erstellt_am, 0, 10 ),
				'ref'     => (string) $job->order_referenz,
				'plan_h'  => $product ? (float) $product->druckzeit_stunden : null,
				'plan_min' => $product ? (float) $product->arbeitszeit_minuten : null,
			);
		}

		$modules = array();
		foreach ( InventoryRepository::get_products( 'modul' ) as $module ) {
			$avg  = ProductionRepository::avg_ist( $module->id, 8 );
			$plan = (float) $module->arbeitszeit_minuten;

			$modules[] = array(
				'name'      => $module->name,
				'plan_min'  => $plan,
				'ist_min'   => $avg ? round( $avg['ist_arbeitszeit_min'], 1 ) : null,
				'ist_h'     => $avg ? round( $avg['ist_druckzeit_h'], 2 ) : null,
				'count'     => $avg ? (int) $avg['anzahl'] : 0,
				'deviation' => $avg ? self::deviation_percent( $plan, $avg['ist_arbeitszeit_min'] ) : null,
			);
		}

		return new WP_REST_Response(
			array(
				'jobs'     => $jobs,
				'modules'  => $modules,
				'excluded' => (string) get_option( 'lw16_excluded_weeks', '' ),
			)
		);
	}

	/**
	 * Auftrag einen Schritt weiterschalten (geplant → in Produktion → fertig).
	 *
	 * Den Sprung auf „verpackt“ gibt es hier nicht: Er bucht Bestand und
	 * Ist-Zeit und geht nur über den Abschluss.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function job_step( WP_REST_Request $request ) {
		$job = ProductionRepository::get_job( (int) $request['id'] );

		if ( ! $job ) {
			return self::fail( __( 'Diesen Druckauftrag gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		$next = self::next_job_status( $job->status );

		if ( '' === $next ) {
			return self::fail( __( 'Dieser Auftrag ist schon fertig. Als Nächstes braucht er die Ist-Zeit zum Abschluss.', 'lindenweg16' ), 409 );
		}

		ProductionRepository::set_job_status( (int) $job->id, $next );

		return self::done( __( 'Status aktualisiert.', 'lindenweg16' ), array( 'status' => $next ) );
	}

	/**
	 * Auftrag abschließen: Ist-Zeit ins Log, Bestand buchen, „verpackt“.
	 *
	 * Nur ein fertiger Auftrag lässt sich abschließen – sonst würde ein zweiter
	 * Aufruf den Bestand ein zweites Mal buchen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function job_complete( WP_REST_Request $request ) {
		$job = ProductionRepository::get_job( (int) $request['id'] );

		if ( ! $job ) {
			return self::fail( __( 'Diesen Druckauftrag gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		if ( 'fertig' !== $job->status ) {
			return self::fail( __( 'Dieser Auftrag ist nicht im Status „fertig“ – vielleicht wurde er schon abgeschlossen. Bitte die Seite neu laden.', 'lindenweg16' ), 409 );
		}

		$body = self::scalars( self::body( $request ) );
		$h    = isset( $body['ist_druckzeit_h'] ) ? str_replace( ',', '.', trim( (string) $body['ist_druckzeit_h'] ) ) : '';
		$min  = isset( $body['ist_arbeitszeit_min'] ) ? str_replace( ',', '.', trim( (string) $body['ist_arbeitszeit_min'] ) ) : '';

		if ( ! is_numeric( $h ) || (float) $h < 0 || ! is_numeric( $min ) || (float) $min < 0 ) {
			return self::fail( __( 'Bitte Druckzeit in Stunden und Arbeitszeit in Minuten als Zahl eintragen (0 ist erlaubt, wenn es nicht bekannt ist).', 'lindenweg16' ) );
		}

		if ( ! ProductionModule::complete_job( (int) $job->id, (float) $h, (float) $min ) ) {
			return self::fail( __( 'Der Druckauftrag wurde nicht gefunden.', 'lindenweg16' ), 404 );
		}

		return self::done( __( 'Druckauftrag abgeschlossen und Ist-Zeit erfasst.', 'lindenweg16' ) );
	}

	/**
	 * Ausgeschlossene Wochen speichern (saisonale Ausreißer).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function weeks_save( WP_REST_Request $request ) {
		$body   = self::scalars( self::body( $request ) );
		$parsed = self::parse_weeks( isset( $body['excluded_weeks'] ) ? $body['excluded_weeks'] : '' );

		if ( $parsed['invalid'] ) {
			return self::fail(
				sprintf(
					/* translators: %s: nicht lesbare Angaben. */
					__( 'Diese Angaben sind keine Kalenderwoche: %s. Bitte im Format JJJJ-WW schreiben, z. B. 2026-51, 2026-52.', 'lindenweg16' ),
					implode( ', ', $parsed['invalid'] )
				)
			);
		}

		$value = implode( ', ', $parsed['weeks'] );
		update_option( 'lw16_excluded_weeks', $value );
		delete_transient( 'lw16_forecast_widget' );

		return self::done( __( 'Ausgeschlossene Wochen gespeichert.', 'lindenweg16' ), array( 'excluded' => $value ) );
	}
}
