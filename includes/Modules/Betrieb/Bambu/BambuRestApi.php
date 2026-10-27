<?php
/**
 * REST-Schnittstelle der Bambu-Lab-Anbindung für den Bridge-Dienst auf
 * dem Raspberry Pi.
 *
 * Eigener Namespace, eigene Authentifizierung – wie bei der
 * Etikettendrucker-Warteschlange (siehe PrintQueue\PrintQueueRestApi):
 * Eine Bridge hat kein WordPress-Konto, sondern ein langes Bearer-Token
 * je Drucker.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Bambu;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registriert und beantwortet die Endpunkte unter lw16-bambu/v1.
 */
class BambuRestApi {

	const NAMESPACE_V1 = 'lw16-bambu/v1';

	const WAIT_MIN = 5;
	const WAIT_MAX = 30;
	const POLL_INTERVAL_SECONDS = 1;

	/**
	 * Höchstgröße einer 3MF-Datei. Eine Grenze für beide Enden, deshalb
	 * steht sie beim Service, der das Einreihen prüft (siehe
	 * BambuService::MAX_FILE_BYTES).
	 */
	const MAX_FILE_BYTES = BambuService::MAX_FILE_BYTES;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Routen anmelden.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_jobs' ),
				'permission_callback' => array( $this, 'authenticate' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>\d+)/file',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_job_file' ),
				'permission_callback' => array( $this, 'authenticate' ),
			)
		);

		foreach ( array( 'claim', 'uploading', 'printing', 'progress', 'complete', 'failed' ) as $action ) {
			register_rest_route(
				self::NAMESPACE_V1,
				'/jobs/(?P<id>\d+)/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'action_' . $action ),
					'permission_callback' => array( $this, 'authenticate' ),
				)
			);
		}

		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'authenticate' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'post_status' ),
					'permission_callback' => array( $this, 'authenticate' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/snapshot',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_snapshot' ),
				'permission_callback' => array( $this, 'authenticate' ),
			)
		);
	}

	/* -----------------------------------------------------------------
	 * Authentifizierung
	 * ------------------------------------------------------------- */

	/**
	 * Bearer-Token prüfen, Ratenlimit anwenden, Drucker in die Anfrage
	 * einhängen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return bool|WP_Error
	 */
	public function authenticate( $request ) {
		$this->send_nocache_headers();

		$header = $request->get_header( 'authorization' );
		$token  = '';

		if ( $header && 0 === stripos( trim( $header ), 'Bearer ' ) ) {
			$token = trim( substr( trim( $header ), 7 ) );
		}

		$ip = $this->client_ip();

		// Erst das Token prüfen, dann erst das Ratenlimit: Das Limit zählt
		// Fehlversuche je IP – und hinter einem Reverse-Proxy oder CDN
		// tragen alle Anfragen dieselbe REMOTE_ADDR, auch die der eigenen
		// Bridge. Käme die Sperre zuerst, könnte jeder Fremde ohne
		// Anmeldung mit 30 falschen Token pro Minute die eigene Bridge
		// dauerhaft aussperren und damit die ganze Warteschlange
		// stillegen. Ein gültiges Token wird deshalb nie gesperrt; der
		// Schutz gegen Token-Raten bleibt unverändert, weil weiterhin nur
		// 30 Fehlversuche je Minute und IP durchkommen.
		$printer = '' === $token ? null : BambuPrinterRepository::find_by_token( $token );

		if ( ! $printer ) {
			if ( $this->rate_limited( $ip ) ) {
				return new WP_Error( 'lw16_bambu_rate_limited', __( 'Zu viele Anfragen. Bitte kurz warten.', 'lindenweg16' ), array( 'status' => 429 ) );
			}

			$this->register_auth_failure( $ip );

			// Niemals das Token selbst loggen – nur, dass ein Versuch scheiterte.
			return new WP_Error(
				'lw16_bambu_unauthorized',
				'' === $token
					? __( 'Kein Bearer-Token übermittelt.', 'lindenweg16' )
					: __( 'Ungültiges Token.', 'lindenweg16' ),
				array( 'status' => 401 )
			);
		}

		$requested_printer_id = (string) $request->get_param( 'printer_id' );

		if ( '' !== $requested_printer_id && $requested_printer_id !== $printer->printer_id ) {
			return new WP_Error( 'lw16_bambu_forbidden', __( 'Dieses Token gehört zu einem anderen Drucker.', 'lindenweg16' ), array( 'status' => 403 ) );
		}

		$request->set_param( '_bambu_printer', $printer );

		return true;
	}

	/**
	 * Jede Antwort ist Live-Zustand eines einzelnen Druckers – ein Seiten-
	 * /CDN-Cache darf sie nie zwischenspeichern (siehe PrintQueueRestApi
	 * für die ausführliche Begründung).
	 */
	protected function send_nocache_headers() {
		if ( headers_sent() ) {
			return;
		}

		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}

	/**
	 * Anfragende IP – nur zur Diagnose und fürs Ratenlimit.
	 *
	 * @return string
	 */
	protected function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Einfaches Ratenlimit gegen Token-Raten.
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	protected function rate_limited( $ip ) {
		if ( '' === $ip ) {
			return false;
		}
		return (int) get_transient( 'lw16_bambu_authfail_' . md5( $ip ) ) >= 30;
	}

	/**
	 * Fehlgeschlagenen Versuch zählen.
	 *
	 * @param string $ip IP.
	 */
	protected function register_auth_failure( $ip ) {
		if ( '' === $ip ) {
			return;
		}
		$key   = 'lw16_bambu_authfail_' . md5( $ip );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Drucker aus der authentifizierten Anfrage.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return object
	 */
	protected function printer_of( $request ) {
		return $request->get_param( '_bambu_printer' );
	}

	/* -----------------------------------------------------------------
	 * GET /jobs (Long Polling)
	 * ------------------------------------------------------------- */

	/**
	 * Wartende Jobs liefern – sofort, wenn welche da sind, sonst bis zu
	 * `wait` Sekunden warten und danach 204 liefern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function get_jobs( $request ) {
		$printer = $this->printer_of( $request );
		$wait    = BambuJobRepository::clamp_wait( $request->get_param( 'wait' ), self::WAIT_MIN, self::WAIT_MAX );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $wait + 15 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		}

		BambuJobRepository::release_stale_locks();
		BambuPrinterRepository::apply_status( (int) $printer->id, array( 'ip' => $this->client_ip() ) );

		$deadline = microtime( true ) + $wait;

		while ( true ) {
			// Ein Drucker kann immer nur einen Auftrag gleichzeitig drucken –
			// läuft noch einer, wird kein neuer ausgeliefert, auch wenn schon
			// mehrere in der Warteschlange stehen. Das muss INNERHALB der
			// Warteschleife geprüft werden, nicht davor: sonst würde die
			// Bridge während eines laufenden Drucks bei jeder Anfrage sofort
			// eine leere Liste zurückbekommen (statt bis `wait` Sekunden zu
			// warten) und in eine Busy-Loop laufen – unabhängig davon, ob
			// Long Polling aktiv ist oder die Bridge klassisch pollt.
			if ( ! BambuJobRepository::active_for_printer( $printer->printer_id ) ) {
				$jobs = BambuJobRepository::queued_for_printer( $printer->printer_id, 1 );

				if ( $jobs ) {
					return new WP_REST_Response( array( 'jobs' => array_map( array( $this, 'job_to_array' ), $jobs ) ), 200 );
				}
			}

			if ( microtime( true ) >= $deadline ) {
				return new WP_REST_Response( null, 204 );
			}

			if ( function_exists( 'connection_aborted' ) && connection_aborted() ) {
				return new WP_REST_Response( null, 204 );
			}

			usleep( self::POLL_INTERVAL_SECONDS * 1000000 );
		}
	}

	/**
	 * Job-Datensatz in die öffentliche API-Form bringen.
	 *
	 * @param object $job Datensatz.
	 * @return array
	 */
	protected function job_to_array( $job ) {
		return array(
			'id'          => (int) $job->id,
			'printer_id'  => (string) $job->printer_id,
			'filename'    => (string) $job->file_name,
			'created_at'  => self::to_rfc3339( $job->created_at ),
			// Gewähltes AMS-Fach für den gesamten Druckauftrag - null, wenn
			// beim Einreihen keins gewählt wurde (die Bridge startet den
			// Druck dann ohne AMS, wie bisher).
			'ams_tray_id' => isset( $job->ams_tray_id ) && null !== $job->ams_tray_id ? (int) $job->ams_tray_id : null,
		);
	}

	/**
	 * MySQL-Datetime (GMT) als ISO-8601/RFC-3339-Zeitstempel.
	 *
	 * @param string|null $mysql_datetime GMT-Datetime aus der Datenbank.
	 * @return string|null
	 */
	protected static function to_rfc3339( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) ) {
			return null;
		}

		$timestamp = strtotime( $mysql_datetime . ' UTC' );

		return $timestamp ? gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) : null;
	}

	/* -----------------------------------------------------------------
	 * GET /jobs/{id}/file
	 * ------------------------------------------------------------- */

	/**
	 * 3MF-Datei ausliefern. Der Pfad kommt ausschließlich aus der
	 * Datenbank – der Client liefert nur die numerische Job-ID, nie einen
	 * Dateinamen oder Pfad. Das schließt Path Traversal strukturell aus.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job_file( $request ) {
		$printer = $this->printer_of( $request );
		$job     = BambuJobRepository::find_for_printer( (int) $request->get_param( 'id' ), $printer->printer_id );

		if ( ! $job ) {
			return new WP_Error( 'lw16_bambu_not_found', __( 'Job nicht gefunden.', 'lindenweg16' ), array( 'status' => 404 ) );
		}

		if ( ! in_array( $job->status, array( 'claimed', 'uploading', 'printing' ), true ) ) {
			return new WP_Error( 'lw16_bambu_not_claimed', __( 'Der Job muss zuerst geclaimt werden.', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		$pfad = $job->file_path;

		if ( ! is_file( $pfad ) || '3mf' !== strtolower( pathinfo( $pfad, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'lw16_bambu_missing_file', __( '3MF-Datei nicht gefunden.', 'lindenweg16' ), array( 'status' => 404 ) );
		}

		$groesse = filesize( $pfad );

		if ( false === $groesse || $groesse > self::MAX_FILE_BYTES ) {
			return new WP_Error( 'lw16_bambu_file_too_large', __( 'Datei ist unerwartet groß.', 'lindenweg16' ), array( 'status' => 500 ) );
		}

		nocache_headers();
		header( 'Content-Type: model/3mf' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $job->file_name ) . '"' );
		header( 'Content-Length: ' . $groesse );

		// Alle offenen Ausgabepuffer leeren, bevor die Datei rausgeht: Eine
		// 3MF-Druckdatei kann hunderte Megabyte groß sein, und liefe sie
		// durch einen Puffer (WordPress, Caching-Plugin, ob_gzhandler),
		// landete sie komplett im Arbeitsspeicher des PHP-Prozesses – der
		// Download bräche mit "memory exhausted" ab, statt zu laufen.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		readfile( $pfad ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/* -----------------------------------------------------------------
	 * Statuswechsel
	 * ------------------------------------------------------------- */

	/**
	 * Job claimen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function action_claim( $request ) {
		$printer = $this->printer_of( $request );
		$id      = (int) $request->get_param( 'id' );

		if ( ! BambuJobRepository::claim( $id, $printer->printer_id ) ) {
			return new WP_Error( 'lw16_bambu_claim_failed', __( 'Job ist nicht mehr verfügbar (schon geclaimt oder nicht vorhanden).', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		AuditLog::record( 'bambu_druckjob_geclaimt', 'bambu_job', $id, array( 'drucker' => $printer->printer_id ) );

		$job = BambuJobRepository::find_for_printer( $id, $printer->printer_id );

		return new WP_REST_Response( array( 'ok' => true, 'job' => $this->job_to_array( $job ) ), 200 );
	}

	/**
	 * 3MF-Upload an den Drucker läuft.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function action_uploading( $request ) {
		$printer = $this->printer_of( $request );
		$id      = (int) $request->get_param( 'id' );

		if ( ! BambuJobRepository::mark_uploading( $id, $printer->printer_id ) ) {
			return new WP_Error( 'lw16_bambu_invalid_state', __( 'Ungültiger Statuswechsel.', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Druckvorgang gestartet.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function action_printing( $request ) {
		$printer = $this->printer_of( $request );
		$id      = (int) $request->get_param( 'id' );

		if ( ! BambuJobRepository::mark_printing( $id, $printer->printer_id ) ) {
			return new WP_Error( 'lw16_bambu_invalid_state', __( 'Ungültiger Statuswechsel.', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		AuditLog::record( 'bambu_druck_gestartet', 'bambu_job', $id, array( 'drucker' => $printer->printer_id ) );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Fortschritt eines laufenden Drucks melden (aus dem MQTT-Report der
	 * Bambu-Firmware: mc_percent, mc_remaining_time). Erneuert nebenbei
	 * das Lease, damit ein mehrstündiger Druck nicht als verwaist gilt.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function action_progress( $request ) {
		$printer = $this->printer_of( $request );
		$id      = (int) $request->get_param( 'id' );

		BambuJobRepository::touch_progress(
			$id,
			$printer->printer_id,
			(int) $request->get_param( 'progress_percent' ),
			(int) $request->get_param( 'remaining_minutes' )
		);

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Druck erfolgreich abgeschlossen. Idempotent – ein wiederholter
	 * Aufruf für einen bereits abgeschlossenen Job bleibt erfolgreich.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function action_complete( $request ) {
		$printer = $this->printer_of( $request );
		$id      = (int) $request->get_param( 'id' );
		$result  = BambuJobRepository::complete( $id, $printer->printer_id );

		if ( ! $result['ok'] ) {
			return new WP_Error( 'lw16_bambu_invalid_state', __( 'Ungültiger Statuswechsel.', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		if ( ! $result['already'] ) {
			AuditLog::record( 'bambu_druck_erfolgreich', 'bambu_job', $id, array( 'drucker' => $printer->printer_id ) );
		}

		return new WP_REST_Response( array( 'ok' => true, 'already' => $result['already'] ), 200 );
	}

	/**
	 * Druck fehlgeschlagen. Idempotent wie complete().
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function action_failed( $request ) {
		$printer = $this->printer_of( $request );
		$id      = (int) $request->get_param( 'id' );
		// Die Meldung kommt von der Bridge und landet in einem TEXT-Feld;
		// ohne Grenze ließe sich die Tabelle damit vollschreiben.
		$message = mb_substr( (string) $request->get_param( 'message' ), 0, 1000 );

		if ( ! BambuJobRepository::fail( $id, $printer->printer_id, $message ) ) {
			return new WP_Error( 'lw16_bambu_invalid_state', __( 'Ungültiger Statuswechsel (Job evtl. bereits abgeschlossen).', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		AuditLog::record( 'bambu_druck_fehlgeschlagen', 'bambu_job', $id, array( 'drucker' => $printer->printer_id, 'meldung' => $message ) );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* -----------------------------------------------------------------
	 * GET/POST /status
	 * ------------------------------------------------------------- */

	/**
	 * Status des anfragenden Druckers.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function get_status( $request ) {
		$printer = $this->printer_of( $request );

		return new WP_REST_Response(
			array(
				'printer_id'  => (string) $printer->printer_id,
				'online'      => BambuPrinterRepository::is_online( $printer ),
				'last_poll'   => self::to_rfc3339( $printer->last_seen_at ),
				'queued_jobs' => BambuJobRepository::count_queued( $printer->printer_id ),
			),
			200
		);
	}

	/**
	 * Live-Telemetrie der Bridge entgegennehmen (aus dem MQTT-Report des
	 * Druckers gelesen: gcode_state, mc_percent, mc_remaining_time,
	 * nozzle_temper, bed_temper). Dient zugleich als Heartbeat – wird auch
	 * dann regelmäßig aufgerufen, wenn der Drucker gerade untätig ist.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function post_status( $request ) {
		$printer = $this->printer_of( $request );

		$update = array(
			'ip'                => $this->client_ip(),
			'status'            => $request->get_param( 'status' ),
			'progress_percent'  => null !== $request->get_param( 'progress_percent' ) ? (int) $request->get_param( 'progress_percent' ) : null,
			'remaining_minutes' => null !== $request->get_param( 'remaining_minutes' ) ? (int) $request->get_param( 'remaining_minutes' ) : null,
			'nozzle_temp'       => null !== $request->get_param( 'nozzle_temp' ) ? (float) $request->get_param( 'nozzle_temp' ) : null,
			'bed_temp'          => null !== $request->get_param( 'bed_temp' ) ? (float) $request->get_param( 'bed_temp' ) : null,
			'printer_model'     => (string) $request->get_param( 'printer_model' ),
			'last_error'        => (string) $request->get_param( 'error' ),
		);

		if ( null !== $request->get_param( 'current_file' ) ) {
			$update['current_file'] = (string) $request->get_param( 'current_file' );
		}

		// 'ams' nur mitschicken, wenn der Parameter tatsächlich da ist -
		// sonst würde jeder Status-Heartbeat ohne AMS-Angabe (z. B. weil
		// die Bridge gerade nicht verbunden ist) die zuletzt bekannte
		// AMS-Belegung löschen, obwohl sich am AMS gar nichts geändert hat.
		if ( null !== $request->get_param( 'ams' ) ) {
			$update['ams'] = (string) $request->get_param( 'ams' );
		}

		BambuPrinterRepository::apply_status( (int) $printer->id, $update );

		// Die Platten der Werkstatt folgen dem Drucker sofort, nicht erst, wenn jemand die Seite öffnet –
		// sonst steht als Druckende der Moment des Hinsehens und die Druckzeit stimmt nicht.
		// Höchstens alle 15 Sekunden: Der Heartbeat kommt viel öfter.
		if ( class_exists( '\\Lindenweg16\\Erp\\Workflow' ) && ! get_transient( 'lw16_sync_printers' ) ) {
			set_transient( 'lw16_sync_printers', 1, 15 );
			try {
				\Lindenweg16\Erp\Workflow::sync_printers();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Der Heartbeat darf daran nie scheitern.
				unset( $e );
			}
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* -----------------------------------------------------------------
	 * POST /snapshot
	 * ------------------------------------------------------------- */

	/**
	 * Höchstgröße eines Kamera-Standbilds. Ein einzelnes JPEG-Frame vom
	 * Drucker ist klein (typischerweise deutlich unter 500 KB) - die
	 * Grenze ist grosszügig bemessen, aber deutlich unter der einer
	 * kompletten 3MF-Druckdatei.
	 */
	const MAX_SNAPSHOT_BYTES = 5 * MB_IN_BYTES;

	/**
	 * Aktuelles Kamera-Standbild entgegennehmen - dieselbe Bridge, dieselbe
	 * Authentifizierung wie beim Status-Heartbeat, nur mit rohem JPEG statt
	 * Formulardaten im Body. Überschreibt je Drucker immer nur die eine,
	 * zuletzt gemeldete Datei - es wird kein Verlauf aufgehoben.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_snapshot( $request ) {
		$printer = $this->printer_of( $request );
		$body    = $request->get_body();

		if ( '' === $body ) {
			return new WP_Error( 'lw16_bambu_empty_snapshot', __( 'Leeres Standbild.', 'lindenweg16' ), array( 'status' => 400 ) );
		}

		if ( strlen( $body ) > self::MAX_SNAPSHOT_BYTES ) {
			return new WP_Error( 'lw16_bambu_snapshot_too_large', __( 'Standbild ist unerwartet groß.', 'lindenweg16' ), array( 'status' => 413 ) );
		}

		if ( ! BambuService::save_snapshot( $printer->printer_id, $body ) ) {
			return new WP_Error( 'lw16_bambu_snapshot_failed', __( 'Standbild konnte nicht gespeichert werden.', 'lindenweg16' ), array( 'status' => 500 ) );
		}

		BambuPrinterRepository::mark_snapshot( (int) $printer->id );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
