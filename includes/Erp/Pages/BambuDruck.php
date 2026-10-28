<?php
/**
 * Bambu-Lab nativ, zweiter Teil: Druckaufträge und Slicing-Profile, dazu
 * die Bettansicht der Platten für den Workflow.
 *
 * Platten werden ausschließlich im Workflow angeordnet, freigegeben und
 * geslict (siehe Erp\Workflow) – nur dort entsteht eine Werkstatt-Platte,
 * deren Ist-Meldung Lager und Druckjournal bucht. Der frühere eigene Reiter
 * „Platten anordnen“ ist entfallen: Was dort gedruckt wurde, buchte nichts.
 *
 * Ergänzt Produktion.php (Status, Verbindung, Gerätezuordnung). Aufgerufen
 * werden dieselben Dienste mit denselben Prüfungen (BambuService,
 * SlicingProfileRepository); Protokolleinträge entstehen
 * dort. Hier stehen die Routen mit ihren Rechten, reine Regeln als
 * statische Methoden ohne Datenbank (getestet in tests/ErpBambuDruckTest.php)
 * und das Aufbereiten der Antworten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Erp\ProductFile;
use Lindenweg16\Erp\RestApi;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuFilamentCatalogRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuJobRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuService;
use Lindenweg16\Modules\Betrieb\Nesting\MeshOutline;
use Lindenweg16\Modules\Betrieb\Nesting\PlateJobRepository;
use Lindenweg16\Modules\Betrieb\Nesting\PlateService;
use Lindenweg16\Modules\Betrieb\Nesting\Polygon;
use Lindenweg16\Modules\Betrieb\Nesting\Silhouette;
use Lindenweg16\Modules\Betrieb\Nesting\SlicingProfileRepository;
use Lindenweg16\Modules\Betrieb\ModelFile;
use Lindenweg16\Modules\Betrieb\RevisionRepository;
use Lindenweg16\Modules\Betrieb\WorkOrderRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routen und Regeln der Bambu-Druckaufträge, Platten und Slicing-Profile.
 */
class BambuDruck {

	/**
	 * Wie viele Teile höchstens auf einmal angeordnet werden (Schutz vor
	 * Tippfehlern wie 5000 statt 50; jedes Teil kostet Rechenzeit).
	 */
	const MAX_PARTS = 200;

	/**
	 * Größte erlaubte Profildatei (OrcaSlicer-Profile sind wenige KB groß).
	 */
	const MAX_PROFILE_BYTES = 5242880;

	/**
	 * Zustandsgruppen der Druckaufträge für die Oberfläche.
	 */
	const GROUPS = array(
		'queued'    => 'wartend',
		'claimed'   => 'laeuft',
		'uploading' => 'laeuft',
		'printing'  => 'laeuft',
		'unklar'    => 'laeuft',
		'completed' => 'fertig',
		'failed'    => 'fehler',
		'cancelled' => 'storniert',
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

		// Wie die alten Seiten: Aufträge mit dem Erfassungsrecht, Profile mit
		// dem Produktionsrecht; Profile ändern zusätzlich mit „Stammdaten
		// ändern“ (siehe ActionMap).
		$jobs    = self::gate( array( 'fertigung.bambu' ), 'lw16_capture' );
		$profile = self::gate( array( 'fertigung.bambu' ), 'lw16_manage_production' );
		$edit    = self::gate( array( 'fertigung.bambu', 'fertigung.stammdaten.edit' ), 'lw16_manage_production' );

		self::route( 'bambu/auftraege', 'GET', 'jobs', $jobs );
		self::route( 'bambu/auftraege/hochladen', 'POST', 'job_upload', $jobs );
		self::route( 'bambu/auftraege/modell', 'POST', 'job_model', $jobs );
		self::route( 'bambu/auftraege/(?P<id>\d+)/abbrechen', 'POST', 'job_cancel', $jobs );
		self::route( 'bambu/auftraege/(?P<id>\d+)/klaeren', 'POST', 'job_resolve', $jobs );
		self::route( 'bambu/auftraege/(?P<id>\d+)/erneut', 'POST', 'job_requeue', $jobs );

		self::route( 'bambu/slicing', 'GET', 'profiles', $profile );
		self::route( 'bambu/slicing/profil', 'POST', 'profile_save', $edit );
		self::route( 'bambu/slicing/profil/(?P<id>\d+)/loeschen', 'POST', 'profile_delete', $edit );
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
	 * Rechteprüfung einer Route, mit deutscher Meldung.
	 *
	 * @param string[] $perms Feine Rechte (alle nötig).
	 * @param string   $cap   Altes Betrieb-Recht der eingebetteten Seite.
	 * @return callable
	 */
	protected static function gate( array $perms, $cap = '' ) {
		return function () use ( $perms, $cap ) {
			if ( Produktion::allowed( $perms, $cap ) ) {
				return true;
			}

			return new WP_Error( 'lw16_forbidden', __( 'Dafür fehlt dir die Berechtigung.', 'lindenweg16' ), array( 'status' => 403 ) );
		};
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
	 * Eingaben der Anfrage als Liste (JSON oder Formularfelder).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array
	 */
	protected static function body( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		return is_array( $body ) ? $body : (array) $request->get_params();
	}

	/**
	 * Unix-Zeit aus einer GMT-Datenbankzeit.
	 *
	 * @param mixed $mysql_gmt Zeit oder leer.
	 * @return int 0 wenn leer.
	 */
	protected static function ts( $mysql_gmt ) {
		return $mysql_gmt ? (int) strtotime( $mysql_gmt . ' UTC' ) : 0;
	}

	/* ====================================================================
	 * Reine Regeln (ohne Datenbank, getestet in tests/ErpBambuDruckTest.php)
	 * ================================================================== */

	/**
	 * Gruppe eines Auftragsstatus: wartend, laeuft, fertig, fehler, storniert.
	 *
	 * @param string $status Status aus BambuJobRepository.
	 * @return string
	 */
	public static function job_group( $status ) {
		$status = (string) $status;

		return isset( self::GROUPS[ $status ] ) ? self::GROUPS[ $status ] : 'fehler';
	}

	/**
	 * Klartext zum Auftragsstatus.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function job_status_text( $status ) {
		$labels = array(
			'queued'    => __( 'Wartet auf den Drucker', 'lindenweg16' ),
			'claimed'   => __( 'Von der Bridge übernommen', 'lindenweg16' ),
			'uploading' => __( 'Wird zum Drucker übertragen', 'lindenweg16' ),
			'printing'  => __( 'Druckt', 'lindenweg16' ),
			'unklar'    => __( 'Unklar – bitte am Drucker prüfen', 'lindenweg16' ),
			'completed' => __( 'Fertig', 'lindenweg16' ),
			'failed'    => __( 'Fehlgeschlagen', 'lindenweg16' ),
			'cancelled' => __( 'Storniert', 'lindenweg16' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Darf ein Auftrag noch abgebrochen werden? Nur solange ihn noch keine
	 * Bridge übernommen hat: Danach steuert der Drucker selbst, und ein
	 * Eintrag „storniert“ würde einen laufenden Druck nur verschleiern.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function can_cancel( $status ) {
		return 'queued' === (string) $status;
	}

	/**
	 * Darf ein Auftrag von Hand geklärt werden? Jeder, der seinen Drucker
	 * belegt – vor allem „unklar“, aber auch einer, dessen Bridge sich nicht
	 * mehr meldet.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function can_resolve( $status ) {
		return in_array( (string) $status, BambuJobRepository::ACTIVE_STATUSES, true );
	}

	/**
	 * Aufträge nach Gruppe filtern (leer = alle).
	 *
	 * @param array  $jobs  Zeilen mit Schlüssel „group“.
	 * @param string $group Gruppe.
	 * @return array
	 */
	public static function filter_by_group( array $jobs, $group ) {
		$group = (string) $group;

		if ( '' === $group ) {
			return array_values( $jobs );
		}

		return array_values(
			array_filter(
				$jobs,
				static function ( $job ) use ( $group ) {
					return isset( $job['group'] ) && $group === $job['group'];
				}
			)
		);
	}

	/**
	 * Anzahl je Gruppe.
	 *
	 * @param array $jobs Zeilen mit Schlüssel „group“.
	 * @return array Gruppe => Anzahl (alle Gruppen vorhanden).
	 */
	public static function group_counts( array $jobs ) {
		$counts = array( 'wartend' => 0, 'laeuft' => 0, 'fertig' => 0, 'fehler' => 0, 'storniert' => 0 );

		foreach ( $jobs as $job ) {
			$group = isset( $job['group'] ) ? $job['group'] : 'fehler';
			if ( isset( $counts[ $group ] ) ) {
				++$counts[ $group ];
			}
		}

		return $counts;
	}

	/**
	 * Gewähltes AMS-Fach: leer heißt „kein AMS“, nicht Fach 0.
	 *
	 * @param mixed $raw Eingabe.
	 * @return int|null
	 */
	public static function clean_tray( $raw ) {
		if ( null === $raw || '' === $raw || ! is_scalar( $raw ) || ! preg_match( '/^\d+$/', trim( (string) $raw ) ) ) {
			return null;
		}

		return max( 0, min( 255, (int) $raw ) );
	}

	/**
	 * Endet der Dateiname auf .3mf?
	 *
	 * @param string $name Dateiname.
	 * @return bool
	 */
	public static function is_3mf_name( $name ) {
		return '3mf' === strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
	}

	/**
	 * Größte Druckdatei, die der Server annimmt.
	 *
	 * @param int $server_max Limit des Servers (wp_max_upload_size).
	 * @return int Byte.
	 */
	public static function max_upload( $server_max ) {
		$limit = (int) BambuService::MAX_FILE_BYTES;

		return $server_max > 0 ? min( $limit, (int) $server_max ) : $limit;
	}

	/**
	 * Artikel und Mengen für die Anordnung bereinigen.
	 *
	 * @param mixed $raw Liste aus {article_id, qty}.
	 * @return array Liste {article_id, qty} ohne Nullmengen, doppelte Artikel zusammengezählt.
	 */
	public static function plate_items( $raw ) {
		$sum = array();

		if ( ! is_array( $raw ) ) {
			return array();
		}

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id  = isset( $row['article_id'] ) && is_scalar( $row['article_id'] ) ? (int) $row['article_id'] : 0;
			$qty = isset( $row['qty'] ) && is_scalar( $row['qty'] ) ? (int) $row['qty'] : 0;

			if ( $id > 0 && $qty > 0 ) {
				$sum[ $id ] = ( isset( $sum[ $id ] ) ? $sum[ $id ] : 0 ) + $qty;
			}
		}

		$out = array();
		foreach ( $sum as $id => $qty ) {
			$out[] = array( 'article_id' => $id, 'qty' => $qty );
		}

		return $out;
	}

	/**
	 * Teile insgesamt.
	 *
	 * @param array $items Ergebnis von plate_items().
	 * @return int
	 */
	public static function parts_total( array $items ) {
		$total = 0;
		foreach ( $items as $item ) {
			$total += (int) $item['qty'];
		}

		return $total;
	}

	/**
	 * Sicherheitsabstand: Komma erlaubt; unlesbar oder ≤ 0 heißt Vorgabe
	 * (wie auf der alten Seite), höchstens 50 mm.
	 *
	 * @param mixed $raw Eingabe.
	 * @return float
	 */
	public static function clean_margin( $raw ) {
		$default = (float) PlateService::DEFAULT_MARGIN_MM;

		if ( null === $raw || ! is_scalar( $raw ) || '' === trim( (string) $raw ) ) {
			return $default;
		}

		$value = str_replace( ',', '.', trim( (string) $raw ) );

		if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
			return $default;
		}

		return min( 50.0, round( (float) $value, 1 ) );
	}

	/**
	 * Darf der Artikel angeordnet werden? Nur gedruckte Artikel mit aktiver
	 * Revision, deren Datei eine ungeslicte Projekt-3MF ist.
	 *
	 * @param array      $article  Artikel.
	 * @param array|null $revision Aktive Revision.
	 * @return bool
	 */
	public static function is_nestable( array $article, $revision ) {
		if ( ! isset( $article['sourcing_type'] ) || 'druck' !== $article['sourcing_type'] ) {
			return false;
		}

		return is_array( $revision ) && ! empty( $revision['model_file'] ) && isset( $revision['model_kind'] ) && 'modell' === $revision['model_kind'];
	}

	/**
	 * Klartext zum Plattenstatus.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function plate_status_text( $status ) {
		$labels = array(
			'entworfen'      => __( 'Angeordnet, noch nicht geslict', 'lindenweg16' ),
			'wird_geslict'   => __( 'Wird geslict', 'lindenweg16' ),
			'geslict'        => __( 'Geslict', 'lindenweg16' ),
			'fehlgeschlagen' => __( 'Slicen fehlgeschlagen', 'lindenweg16' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Hinweis zum letzten Slicing-Lauf einer Platte: Fehlergrund, oder ob der
	 * Worker die Platte schon abgeholt hat (Worker aus oder Slicer-Absturz
	 * sind verschiedene Fehler).
	 *
	 * @param string     $plate_status Status der Platte.
	 * @param array|null $job          {status, error_message, created_ts, started_ts} oder null.
	 * @return array|null {kind: error|wait|work, text, since}
	 */
	public static function slicing_note( $plate_status, $job ) {
		if ( ! is_array( $job ) ) {
			return null;
		}

		if ( 'fehlgeschlagen' === $plate_status ) {
			$message = trim( isset( $job['error_message'] ) ? (string) $job['error_message'] : '' );

			return array(
				'kind'  => 'error',
				'text'  => '' !== $message ? $message : __( 'Kein Fehlergrund übermittelt.', 'lindenweg16' ),
				'since' => 0,
			);
		}

		if ( 'wird_geslict' !== $plate_status ) {
			return null;
		}

		$status = isset( $job['status'] ) ? $job['status'] : '';

		if ( 'queued' === $status ) {
			return array(
				'kind'  => 'wait',
				'text'  => __( 'Wartet auf den Slicing-Worker. Passiert lange nichts, läuft der Worker nicht oder sein Token stimmt nicht.', 'lindenweg16' ),
				'since' => isset( $job['created_ts'] ) ? (int) $job['created_ts'] : 0,
			);
		}

		if ( in_array( $status, array( 'claimed', 'slicing' ), true ) ) {
			$since = ! empty( $job['started_ts'] ) ? (int) $job['started_ts'] : ( isset( $job['created_ts'] ) ? (int) $job['created_ts'] : 0 );

			return array( 'kind' => 'work', 'text' => __( 'Der Worker hat die Platte übernommen und slict gerade.', 'lindenweg16' ), 'since' => $since );
		}

		return null;
	}

	/**
	 * Umriss eines angeordneten Teils auf dem Bett, genau wie BinPacker ihn
	 * gesetzt hat: Hülle auf die Ecke ihres Begrenzungsrechtecks normieren,
	 * drehen (gegen den Uhrzeigersinn), Ecke an (x, y) legen.
	 *
	 * @param array $hull Punkte [[x, y], …] in mm.
	 * @param float $x    Ziel-X der linken unteren Ecke.
	 * @param float $y    Ziel-Y der linken unteren Ecke.
	 * @param float $deg  Winkel.
	 * @return array Punkte [[x, y], …] auf 0,1 mm gerundet; leer, wenn die Hülle keine Fläche hat.
	 */
	public static function outline( array $hull, $x, $y, $deg ) {
		$hull = Polygon::convex_hull( $hull );

		if ( count( $hull ) < 3 ) {
			return array();
		}

		$box    = Polygon::bounding_box( $hull );
		$hull   = Polygon::translate( $hull, -$box['minx'], -$box['miny'] );
		$placed = Polygon::place( $hull, (float) $deg, (float) $x, (float) $y );
		$out    = array();

		foreach ( $placed as $point ) {
			$out[] = array( round( (float) $point[0], 1 ), round( (float) $point[1], 1 ) );
		}

		return $out;
	}

	/**
	 * Material und Druckermodell eines Profils prüfen.
	 *
	 * @param mixed $material      Material.
	 * @param mixed $printer_model Druckermodell.
	 * @param array $materials     Erlaubte Materialien.
	 * @return array {material, printer_model, error}
	 */
	public static function profile_target( $material, $printer_model, array $materials ) {
		$material = strtoupper( trim( is_scalar( $material ) ? (string) $material : '' ) );
		$model    = trim( is_scalar( $printer_model ) ? preg_replace( '/\s+/', ' ', (string) $printer_model ) : '' );
		$error    = '';

		if ( '' === $material || '' === $model ) {
			$error = __( 'Material und Druckermodell sind Pflicht. Das Druckermodell muss zu dem passen, was die Bridge meldet (z. B. P1S).', 'lindenweg16' );
		} elseif ( ! in_array( $material, $materials, true ) ) {
			$error = sprintf(
				/* translators: %s: Liste der Materialien. */
				__( 'Dieses Material gibt es nicht. Erlaubt sind: %s.', 'lindenweg16' ),
				implode( ', ', $materials )
			);
		} elseif ( strlen( $model ) > 60 ) {
			$error = __( 'Das Druckermodell ist zu lang (höchstens 60 Zeichen).', 'lindenweg16' );
		}

		return array( 'material' => $material, 'printer_model' => $model, 'error' => $error );
	}

	/**
	 * Eine hochgeladene Profildatei vorab prüfen.
	 *
	 * @param string     $label Anzeigename („Druckerprofil“).
	 * @param array|null $file  Element aus den Datei-Parametern.
	 * @param string     $json  Inhalt der Datei, wenn lesbar (sonst leer).
	 * @return string Fehlertext oder leer.
	 */
	public static function profile_file_error( $label, $file, $json ) {
		if ( ! is_array( $file ) || ( empty( $file['name'] ) && empty( $file['error'] ) ) ) {
			return sprintf(
				/* translators: %s: Name der Profildatei. */
				__( '%s fehlt. Bitte alle drei Profildateien (Drucker, Filament, Prozess) als JSON aus OrcaSlicer oder Bambu Studio wählen.', 'lindenweg16' ),
				$label
			);
		}

		if ( ! empty( $file['error'] ) ) {
			return sprintf(
				/* translators: 1: Name der Profildatei, 2: Grund. */
				__( '%1$s: %2$s', 'lindenweg16' ),
				$label,
				ProductFile::upload_error( (int) $file['error'], self::MAX_PROFILE_BYTES )
			);
		}

		if ( 'json' !== strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) ) {
			return sprintf(
				/* translators: %s: Name der Profildatei. */
				__( '%s muss eine .json-Datei sein (in OrcaSlicer: Rechtsklick auf das Profil → Exportieren).', 'lindenweg16' ),
				$label
			);
		}

		if ( isset( $file['size'] ) && (int) $file['size'] > self::MAX_PROFILE_BYTES ) {
			return sprintf(
				/* translators: %s: Name der Profildatei. */
				__( '%s ist ungewöhnlich groß. Ein Profil ist nur wenige KB groß; vermutlich ist es die falsche Datei.', 'lindenweg16' ),
				$label
			);
		}

		$data = json_decode( (string) $json, true );

		if ( '' === trim( (string) $json ) || ! is_array( $data ) ) {
			return sprintf(
				/* translators: %s: Name der Profildatei. */
				__( '%s ist keine gültige JSON-Datei. Bitte neu aus dem Slicer exportieren.', 'lindenweg16' ),
				$label
			);
		}

		return '';
	}

	/**
	 * Bambu-Filament-ID aus dem Inhalt eines Filamentprofils.
	 *
	 * @param string $json Dateiinhalt.
	 * @return string ID oder leer.
	 */
	public static function filament_id_from_json( $json ) {
		$data = json_decode( (string) $json, true );

		if ( ! is_array( $data ) || empty( $data['filament_id'] ) || ! is_scalar( $data['filament_id'] ) ) {
			return '';
		}

		return trim( (string) $data['filament_id'] );
	}

	/* ====================================================================
	 * Druckaufträge
	 * ================================================================== */

	/**
	 * Drucker mit Bettmaß und wählbaren AMS-Fächern, für die Auswahlfelder.
	 *
	 * @return array
	 */
	protected static function printer_options() {
		$out = array();

		foreach ( BambuPrinterRepository::all() as $printer ) {
			$bed   = BambuPrinterRepository::effective_bed_size( $printer );
			$trays = array();

			foreach ( BambuPrinterRepository::ams_tray_options( $printer ) as $tray ) {
				$trays[] = array(
					'value' => (int) $tray['value'],
					'label' => (string) $tray['label'],
					'color' => isset( $tray['color'] ) ? (string) $tray['color'] : '',
				);
			}

			$out[] = array(
				'printer_id' => $printer->printer_id,
				'name'       => $printer->name,
				'bed'        => $bed ? $bed['width_mm'] . ' × ' . $bed['height_mm'] . ' mm' : '',
				'trays'      => $trays,
			);
		}

		return $out;
	}

	/**
	 * Aufträge aller Drucker (die letzten 200), Dateien zum Einreihen und
	 * Upload-Grenze.
	 *
	 * @return WP_REST_Response
	 */
	public static function jobs() {
		$printers = self::printer_options();
		$names    = array();
		foreach ( $printers as $printer ) {
			$names[ $printer['printer_id'] ] = $printer['name'];
		}

		$rows = array();
		foreach ( BambuJobRepository::list_for_admin( '', 200 ) as $job ) {
			$rows[] = array(
				'id'         => (int) $job->id,
				'printer_id' => $job->printer_id,
				'printer'    => isset( $names[ $job->printer_id ] ) ? $names[ $job->printer_id ] : $job->printer_id,
				'file'       => (string) $job->file_name,
				'order'      => $job->druckauftrag_id ? (int) $job->druckauftrag_id : 0,
				'status'     => $job->status,
				'group'      => self::job_group( $job->status ),
				'text'       => self::job_status_text( $job->status ),
				'progress'   => null !== $job->progress_percent ? (int) $job->progress_percent : null,
				'remaining'  => null !== $job->remaining_minutes ? (int) $job->remaining_minutes : null,
				'created'    => self::ts( $job->created_at ),
				'completed'  => self::ts( $job->completed_at ),
				'actual_min' => null !== $job->actual_print_minutes ? (float) $job->actual_print_minutes : null,
				'error'      => (string) $job->error_message,
				'can_cancel' => self::can_cancel( $job->status ),
				'can_resolve' => self::can_resolve( $job->status ),
				'can_requeue' => BambuJobRepository::can_requeue( $job->status ) && is_file( (string) $job->file_path ),
			);
		}

		$server = (int) wp_max_upload_size();
		$max    = self::max_upload( $server );

		return new WP_REST_Response(
			array(
				'printers'   => $printers,
				'jobs'       => $rows,
				'counts'     => self::group_counts( $rows ),
				'files'      => BambuService::verfuegbare_druckdateien(),
				'max_bytes'  => $max,
				'limit_note' => $server > 0 && $server < (int) BambuService::MAX_FILE_BYTES,
				'now'        => time(),
			)
		);
	}

	/**
	 * Geslicte Druckdatei (.gcode.3mf) hochladen und einreihen
	 * (multipart: Feld „datei“, dazu printer_id und optional ams_tray_id).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function job_upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = isset( $files['datei'] ) ? $files['datei'] : null;
		$max   = self::max_upload( (int) wp_max_upload_size() );

		// Übersteigt die Anfrage post_max_size, kommt gar kein Feld an.
		if ( ! $file || ( empty( $file['name'] ) && empty( $file['error'] ) ) ) {
			return self::fail( __( 'Es kam keine Datei an. Entweder wurde keine gewählt, oder sie ist größer, als der Server annimmt. Bitte eine geslicte .3mf-Datei wählen.', 'lindenweg16' ) );
		}

		$error = ProductFile::upload_error( isset( $file['error'] ) ? (int) $file['error'] : 0, $max );

		if ( '' !== $error ) {
			return self::fail( $error );
		}

		$printer_id = sanitize_key( (string) $request->get_param( 'printer_id' ) );

		if ( '' === $printer_id ) {
			return self::fail( __( 'Bitte einen Drucker wählen.', 'lindenweg16' ) );
		}

		$result = BambuService::enqueue_upload(
			$printer_id,
			$file['tmp_name'],
			sanitize_file_name( (string) $file['name'] ),
			self::clean_tray( $request->get_param( 'ams_tray_id' ) )
		);

		if ( ! $result['ok'] ) {
			return self::fail( $result['error'] );
		}

		return self::done( __( 'Druckauftrag angelegt. Der Bambu-Drucker holt ihn beim nächsten Kontakt der Bridge ab.', 'lindenweg16' ), array( 'job_id' => (int) $result['job_id'] ) );
	}

	/**
	 * Geslicte Datei einer Artikelrevision einreihen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function job_model( WP_REST_Request $request ) {
		$body       = self::body( $request );
		$printer_id = isset( $body['printer_id'] ) && is_scalar( $body['printer_id'] ) ? sanitize_key( (string) $body['printer_id'] ) : '';
		$datei      = isset( $body['model_file'] ) && is_scalar( $body['model_file'] ) ? sanitize_file_name( (string) $body['model_file'] ) : '';

		if ( '' === $printer_id || '' === $datei ) {
			return self::fail( __( 'Bitte einen Drucker und eine Druckdatei wählen.', 'lindenweg16' ) );
		}

		$result = BambuService::enqueue_model( $printer_id, $datei, self::clean_tray( isset( $body['ams_tray_id'] ) ? $body['ams_tray_id'] : null ) );

		if ( ! $result['ok'] ) {
			return self::fail( $result['error'] );
		}

		return self::done( __( 'Druckauftrag angelegt. Der Bambu-Drucker holt ihn beim nächsten Kontakt der Bridge ab.', 'lindenweg16' ), array( 'job_id' => (int) $result['job_id'] ) );
	}

	/**
	 * Wartenden Auftrag abbrechen. Das bedingte UPDATE entscheidet, wer
	 * gewinnt, wenn die Bridge den Auftrag gerade übernimmt.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function job_cancel( WP_REST_Request $request ) {
		global $wpdb;

		$id  = (int) $request['id'];
		$job = BambuJobRepository::find( $id );

		if ( ! $job ) {
			return self::fail( __( 'Diesen Druckauftrag gibt es nicht (mehr). Bitte die Liste neu laden.', 'lindenweg16' ), 404 );
		}

		$taken = __( 'Dieser Auftrag wurde schon vom Drucker übernommen und lässt sich hier nicht mehr abbrechen. Einen laufenden Druck am Drucker selbst stoppen.', 'lindenweg16' );

		if ( ! self::can_cancel( $job->status ) ) {
			return self::fail( 'cancelled' === $job->status ? __( 'Dieser Auftrag ist schon storniert.', 'lindenweg16' ) : $taken );
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . BambuJobRepository::table() . " SET status = 'cancelled', updated_at = %s, locked_at = NULL WHERE id = %d AND status = 'queued'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				$id
			)
		);

		if ( (int) $updated < 1 ) {
			return self::fail( $taken );
		}

		// Nur eigens hochgeladene Dateien werden gelöscht; Dateien der
		// Modellablage gehören der Artikelrevision (siehe delete_job_file()).
		BambuService::delete_job_file( $job->file_path, $id );

		AuditLog::record( 'bambu_druckjob_storniert', 'bambu_job', $id, array( 'drucker' => $job->printer_id, 'datei' => $job->file_name ) );

		// Hängt am Auftrag noch ein Fertigungsauftrag (ältere Aufträge aus dem
		// entfallenen „Bedarf direkt drucken“), wird er mit storniert – sonst
		// deckte er scheinbar einen Bedarf, für den nichts mehr gedruckt wird.
		if ( $job->work_order_id && class_exists( WorkOrderRepository::class ) ) {
			WorkOrderRepository::cancel( (int) $job->work_order_id, __( 'Bambu-Druckauftrag storniert.', 'lindenweg16' ) );
		}

		return self::done( __( 'Druckauftrag abgebrochen.', 'lindenweg16' ) );
	}

	/**
	 * Hängenden Auftrag von Hand klären: als fertig buchen (wie ein normaler
	 * Abschluss) oder als fehlgeschlagen markieren. Gibt die Warteschlange
	 * des Druckers wieder frei – die Platte muss trotzdem noch als frei
	 * bestätigt werden.
	 *
	 * @param WP_REST_Request $request Anfrage (outcome: completed|failed, note).
	 * @return WP_REST_Response
	 */
	public static function job_resolve( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$body    = (array) $request->get_json_params();
		$outcome = isset( $body['outcome'] ) ? sanitize_key( (string) $body['outcome'] ) : '';
		$note    = isset( $body['note'] ) ? mb_substr( sanitize_textarea_field( (string) $body['note'] ), 0, 500 ) : '';

		if ( ! in_array( $outcome, array( 'completed', 'failed' ), true ) ) {
			return self::fail( __( 'Bitte „fertig“ oder „fehlgeschlagen“ wählen.', 'lindenweg16' ) );
		}

		$result = BambuJobRepository::resolve_manually( $id, $outcome, $note );

		if ( ! $result['ok'] ) {
			if ( 'not_found' === $result['error'] ) {
				return self::fail( __( 'Diesen Druckauftrag gibt es nicht (mehr). Bitte die Liste neu laden.', 'lindenweg16' ), 404 );
			}
			return self::fail( __( 'Dieser Auftrag ist schon abgeschlossen – bitte die Liste neu laden.', 'lindenweg16' ), 409 );
		}

		AuditLog::record( 'bambu_druckjob_geklaert', 'bambu_job', $id, array( 'ergebnis' => $outcome, 'notiz' => $note ) );

		return self::done(
			'completed' === $outcome
				? __( 'Als fertig gebucht.', 'lindenweg16' )
				: __( 'Als fehlgeschlagen markiert.', 'lindenweg16' )
		);
	}

	/**
	 * Fehlgeschlagenen oder stornierten Auftrag mit derselben Datei erneut
	 * einreihen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function job_requeue( WP_REST_Request $request ) {
		$result = BambuService::requeue_job( (int) $request['id'] );

		if ( ! $result['ok'] ) {
			return self::fail( $result['error'] );
		}

		return self::done( __( 'Erneut eingereiht. Er startet, sobald der Drucker frei und die Platte als frei bestätigt ist.', 'lindenweg16' ), array( 'job_id' => (int) $result['job_id'] ) );
	}

	/* ====================================================================
	 * Platten (Anordnung für den Workflow)
	 * ================================================================== */

	/**
	 * Anordnung einer Platte: Bett und Umrisse der Teile.
	 *
	 * Auch für den Workflow (Plan-Vorschau, Plattenseite).
	 *
	 * @param object $plate Plattendatei.
	 * @return array {id, width, height, items, missing}
	 */
	public static function layout( $plate ) {
		$placements = json_decode( (string) $plate->placements, true );
		$items      = is_array( $placements ) && isset( $placements['items'] ) && is_array( $placements['items'] ) ? $placements['items'] : array();
		$articles   = ArticleRepository::all_by_id();
		$hulls      = array();
		$shapes     = array();
		$out        = array();
		$missing    = 0;

		foreach ( $items as $item ) {
			$article_id = isset( $item['article_id'] ) ? (int) $item['article_id'] : 0;

			if ( ! array_key_exists( $article_id, $hulls ) ) {
				$hulls[ $article_id ] = array();
				$revision             = RevisionRepository::active_for_article( $article_id );
				$path                 = $revision && ! empty( $revision['model_file'] ) ? ModelFile::safe_path( $revision['model_file'] ) : '';

				// Das echte Schattenbild (mit Einbuchtungen), wie der Packer es sieht.
				$sil = '' !== $path ? Silhouette::for_file( $path ) : null;

				if ( $sil ) {
					$hulls[ $article_id ]  = $sil['hull'];
					$shapes[ $article_id ] = Silhouette::path_d( $sil );
				} else {
					$hull                 = '' !== $path ? MeshOutline::xy_hull_from_file( $path ) : null;
					$hulls[ $article_id ] = is_array( $hull ) ? $hull : array();
				}
			}

			$x   = isset( $item['x'] ) ? (float) $item['x'] : 0.0;
			$y   = isset( $item['y'] ) ? (float) $item['y'] : 0.0;
			$deg = isset( $item['rotation_deg'] ) ? (float) $item['rotation_deg'] : 0.0;
			$pts = self::outline( $hulls[ $article_id ], $x, $y, $deg );

			if ( ! $pts ) {
				++$missing;
			}

			// Versatz, mit dem der Pfad des ungedrehten Teils auf die Platte kommt:
			// gedreht um die Ecke, dann so verschoben, dass die Ecke des gedrehten
			// Begrenzungsrechtecks auf {x, y} liegt.
			$tx = $x;
			$ty = $y;

			if ( isset( $shapes[ $article_id ] ) && count( $hulls[ $article_id ] ) >= 3 ) {
				$box     = Polygon::bounding_box( $hulls[ $article_id ] );
				$rotated = Polygon::bounding_box( Polygon::rotate( Polygon::translate( $hulls[ $article_id ], -$box['minx'], -$box['miny'] ), $deg ) );
				$tx      = $x - $rotated['minx'];
				$ty      = $y - $rotated['miny'];
			}

			$article = isset( $articles[ $article_id ] ) ? $articles[ $article_id ] : null;
			$out[]   = array(
				'article_id' => $article_id,
				'label'      => $article ? $article['sku'] : '#' . $article_id,
				'x'          => $x,
				'y'          => $y,
				'rotation'   => $deg,
				'tx'         => round( $tx, 3 ),
				'ty'         => round( $ty, 3 ),
				'points'     => $pts,
			);
		}

		return array(
			'id'      => (int) $plate->id,
			'width'   => (int) $plate->plate_width_mm,
			'height'  => (int) $plate->plate_height_mm,
			'items'   => $out,
			'shapes'  => (object) $shapes,
			'missing' => $missing,
		);
	}

	/* ====================================================================
	 * Slicing-Profile
	 * ================================================================== */

	/**
	 * Profile mit Farbe und Dateistand.
	 *
	 * @return WP_REST_Response
	 */
	public static function profiles() {
		$rows = array();

		foreach ( SlicingProfileRepository::all() as $profile ) {
			$filament = $profile->color ? BambuFilamentCatalogRepository::find( $profile->color ) : null;
			$complete = true;
			foreach ( array( 'printer_profile_path', 'filament_profile_path', 'process_profile_path' ) as $column ) {
				if ( '' === SlicingProfileRepository::safe_path( $profile->$column ) ) {
					$complete = false;
				}
			}

			$rows[] = array(
				'id'          => (int) $profile->id,
				'material'    => $profile->material,
				'color'       => (string) $profile->color,
				'color_label' => $filament ? (string) $filament->label : '',
				'model'       => $profile->printer_model,
				'files_ok'    => $complete,
				'updated'     => self::ts( $profile->updated_at ? $profile->updated_at : $profile->created_at ),
			);
		}

		return new WP_REST_Response(
			array(
				'profiles'  => $rows,
				'materials' => RevisionRepository::MATERIALS,
				'manage'    => Produktion::allowed( array( 'fertigung.bambu', 'fertigung.stammdaten.edit' ), 'lw16_manage_production' ),
				'now'       => time(),
			)
		);
	}

	/**
	 * Profil speichern oder ersetzen (multipart: material, printer_model und
	 * die drei Dateien printer_profile, filament_profile, process_profile).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function profile_save( WP_REST_Request $request ) {
		$target = self::profile_target( $request->get_param( 'material' ), $request->get_param( 'printer_model' ), RevisionRepository::MATERIALS );

		if ( '' !== $target['error'] ) {
			return self::fail( $target['error'] );
		}

		$files  = $request->get_file_params();
		$fields = array(
			'printer'  => array( 'printer_profile', __( 'Das Druckerprofil', 'lindenweg16' ) ),
			'filament' => array( 'filament_profile', __( 'Das Filamentprofil', 'lindenweg16' ) ),
			'process'  => array( 'process_profile', __( 'Das Prozessprofil', 'lindenweg16' ) ),
		);

		// Erst alles prüfen, dann erst ablegen: sonst blieben bei einem Fehler
		// in der dritten Datei die ersten beiden als Karteileichen liegen.
		$contents = array();
		foreach ( $fields as $kind => $def ) {
			$file    = isset( $files[ $def[0] ] ) ? $files[ $def[0] ] : null;
			$content = is_array( $file ) && empty( $file['error'] ) && ! empty( $file['tmp_name'] ) && is_readable( $file['tmp_name'] )
				? (string) file_get_contents( $file['tmp_name'] ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
				: '';
			$error   = self::profile_file_error( $def[1], $file, $content );

			if ( '' !== $error ) {
				return self::fail( $error );
			}

			$contents[ $kind ] = $content;
		}

		// Die Farbe kommt ausschließlich aus der Bambu-Filament-ID im
		// Filamentprofil (wie auf der alten Seite): So weicht sie nie von der
		// hochgeladenen Datei ab. Ohne gültige ID bleibt das Profil farblos.
		$filament_id = self::filament_id_from_json( $contents['filament'] );
		$color       = BambuFilamentCatalogRepository::is_valid_filament_id( $filament_id ) ? $filament_id : '';

		$stored = array();
		foreach ( $fields as $kind => $def ) {
			$name = SlicingProfileRepository::store_file( $files[ $def[0] ], $kind );

			if ( '' === $name ) {
				foreach ( $stored as $done ) {
					$path = SlicingProfileRepository::safe_path( $done );
					if ( '' !== $path ) {
						wp_delete_file( $path );
					}
				}

				return self::fail( sprintf(
					/* translators: %s: Name der Profildatei. */
					__( '%s konnte nicht gespeichert werden. Bitte noch einmal versuchen; hilft das nicht, den Hoster fragen, ob der Ordner für Dokumente beschreibbar ist.', 'lindenweg16' ),
					$def[1]
				), 500 );
			}

			$stored[ $kind ] = $name;
		}

		BambuFilamentCatalogRepository::observe_filament_profile_json( $contents['filament'] );

		$replaced = (bool) SlicingProfileRepository::find_for( $target['material'], $target['printer_model'], $color );
		$id       = SlicingProfileRepository::upsert(
			array(
				'material'              => $target['material'],
				'color'                 => $color,
				'printer_model'         => $target['printer_model'],
				'printer_profile_path'  => $stored['printer'],
				'filament_profile_path' => $stored['filament'],
				'process_profile_path'  => $stored['process'],
			)
		);

		if ( ! $id ) {
			return self::fail( __( 'Das Profil konnte nicht gespeichert werden.', 'lindenweg16' ), 500 );
		}

		AuditLog::record(
			$replaced ? 'slicing_profil_ersetzt' : 'slicing_profil_angelegt',
			'slicing_profile',
			$id,
			array( 'material' => $target['material'], 'modell' => $target['printer_model'], 'farbe' => $color )
		);

		if ( $color ) {
			$message = sprintf(
				/* translators: 1: "ersetzt" oder "gespeichert", 2: Bambu-Filament-ID. */
				__( 'Slicing-Profil %1$s (Farbe erkannt: %2$s).', 'lindenweg16' ),
				$replaced ? __( 'ersetzt', 'lindenweg16' ) : __( 'gespeichert', 'lindenweg16' ),
				$color
			);
		} else {
			$message = sprintf(
				/* translators: %s: "ersetzt" oder "gespeichert". */
				__( 'Slicing-Profil %s, aber ohne gültige Bambu-Filament-ID im Filamentprofil, deshalb als farbloses Profil dieses Materials.', 'lindenweg16' ),
				$replaced ? __( 'ersetzt', 'lindenweg16' ) : __( 'gespeichert', 'lindenweg16' )
			);
		}

		return self::done( $message, array( 'id' => (int) $id, 'replaced' => $replaced, 'color' => $color ) );
	}

	/**
	 * Profil löschen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function profile_delete( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$profile = SlicingProfileRepository::find( $id );

		if ( ! $profile ) {
			return self::fail( __( 'Dieses Profil gibt es nicht (mehr). Bitte die Seite neu laden.', 'lindenweg16' ), 404 );
		}

		if ( ! SlicingProfileRepository::delete( $id ) ) {
			return self::fail( __( 'Das Profil konnte nicht gelöscht werden.', 'lindenweg16' ), 500 );
		}

		AuditLog::record( 'slicing_profil_geloescht', 'slicing_profile', $id, array( 'material' => $profile->material, 'modell' => $profile->printer_model, 'farbe' => $profile->color ) );

		return self::done( __( 'Profil gelöscht.', 'lindenweg16' ) );
	}
}
