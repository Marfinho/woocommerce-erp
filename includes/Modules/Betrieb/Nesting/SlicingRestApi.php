<?php
/**
 * REST-Schnittstelle der Slicing-Warteschlange für den Headless-Worker.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Nesting;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registriert und beantwortet die Endpunkte unter lw16-slicing/v1.
 *
 * Authentifizierung wie bei den übrigen Gerätewarteschlangen: ein langes
 * Bearer-Token statt eines WordPress-Kontos – hier aber ein einziges,
 * geteiltes Token für den (typischerweise einen) Slicing-Worker statt
 * eines je Gerät (siehe SlicingWorkerAuth).
 */
class SlicingRestApi {

	const NAMESPACE_V1 = 'lw16-slicing/v1';

	const WAIT_MIN = 5;
	const WAIT_MAX = 30;
	const POLL_INTERVAL_SECONDS = 1;

	/**
	 * Höchstgrösse einer Projekt- oder Druckdatei.
	 */
	const MAX_FILE_BYTES = 200 * MB_IN_BYTES;

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

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>\d+)/profile/(?P<kind>printer|filament|process)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_job_profile' ),
				'permission_callback' => array( $this, 'authenticate' ),
			)
		);

		foreach ( array( 'claim', 'slicing', 'complete', 'failed' ) as $action ) {
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
	}

	/* -----------------------------------------------------------------
	 * Authentifizierung
	 * ------------------------------------------------------------- */

	/**
	 * Bearer-Token prüfen, Ratenlimit anwenden.
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

		if ( $this->rate_limited( $ip ) ) {
			return new WP_Error( 'lw16_slicing_rate_limited', __( 'Zu viele Anfragen. Bitte kurz warten.', 'lindenweg16' ), array( 'status' => 429 ) );
		}

		if ( '' === $token || ! SlicingWorkerAuth::verify( $token ) ) {
			$this->register_auth_failure( $ip );
			return new WP_Error( 'lw16_slicing_unauthorized', __( 'Ungültiges Token.', 'lindenweg16' ), array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Siehe Bambu\BambuRestApi für die ausführliche Begründung (Seiten-/
	 * CDN-Caches dürfen Live-Zustand nie zwischenspeichern).
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
		return (int) get_transient( 'lw16_slicing_authfail_' . md5( $ip ) ) >= 30;
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
		$key   = 'lw16_slicing_authfail_' . md5( $ip );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
	}

	/* -----------------------------------------------------------------
	 * GET /jobs (Long/klassisches Polling)
	 * ------------------------------------------------------------- */

	/**
	 * Wartende Slicing-Aufträge liefern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function get_jobs( $request ) {
		$wait = SlicingJobRepository::clamp_wait( $request->get_param( 'wait' ), self::WAIT_MIN, self::WAIT_MAX );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $wait + 15 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		}

		SlicingJobRepository::release_stale_locks();

		$deadline = microtime( true ) + $wait;

		while ( true ) {
			$jobs = SlicingJobRepository::queued( 1 );

			if ( $jobs ) {
				return new WP_REST_Response( array( 'jobs' => array_map( array( $this, 'job_to_array' ), $jobs ) ), 200 );
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
		$plate = PlateJobRepository::find( (int) $job->plate_job_id );

		return array(
			'id'         => (int) $job->id,
			'filename'   => $plate ? (string) $plate->file_name : '',
			'created_at' => self::to_rfc3339( $job->created_at ),
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
	 * Dateien
	 * ------------------------------------------------------------- */

	/**
	 * Zu slicende Projekt-3MF ausliefern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job_file( $request ) {
		$job = SlicingJobRepository::find( (int) $request->get_param( 'id' ) );

		if ( ! $job || ! in_array( $job->status, array( 'claimed', 'slicing' ), true ) ) {
			return new WP_Error( 'lw16_slicing_not_claimed', __( 'Der Auftrag muss zuerst geclaimt werden.', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		$plate = PlateJobRepository::find( (int) $job->plate_job_id );

		if ( ! $plate || ! is_file( $plate->file_path ) ) {
			return new WP_Error( 'lw16_slicing_missing_file', __( 'Plattendatei nicht gefunden.', 'lindenweg16' ), array( 'status' => 404 ) );
		}

		return $this->serve_file( $plate->file_path, $plate->file_name, 'model/3mf' );
	}

	/**
	 * Eine der drei Profildateien (printer/filament/process) ausliefern.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job_profile( $request ) {
		$job = SlicingJobRepository::find( (int) $request->get_param( 'id' ) );

		if ( ! $job || ! in_array( $job->status, array( 'claimed', 'slicing' ), true ) ) {
			return new WP_Error( 'lw16_slicing_not_claimed', __( 'Der Auftrag muss zuerst geclaimt werden.', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		$profile = SlicingProfileRepository::find( (int) $job->profile_id );

		if ( ! $profile ) {
			return new WP_Error( 'lw16_slicing_missing_profile', __( 'Profil nicht gefunden.', 'lindenweg16' ), array( 'status' => 404 ) );
		}

		$kind   = (string) $request->get_param( 'kind' );
		$column = $kind . '_profile_path';
		$path   = SlicingProfileRepository::safe_path( isset( $profile->$column ) ? $profile->$column : '' );

		if ( '' === $path ) {
			return new WP_Error( 'lw16_slicing_missing_file', __( 'Profildatei nicht gefunden.', 'lindenweg16' ), array( 'status' => 404 ) );
		}

		return $this->serve_file( $path, basename( $path ), 'application/json' );
	}

	/**
	 * Datei ausliefern (Grössenprüfung, keine Cachebarkeit).
	 *
	 * @param string $path         Absoluter Pfad.
	 * @param string $filename     Angezeigter Dateiname.
	 * @param string $content_type MIME-Typ.
	 * @return WP_Error|void Beendet den Request per exit bei Erfolg.
	 */
	protected function serve_file( $path, $filename, $content_type ) {
		$groesse = filesize( $path );

		if ( false === $groesse || $groesse > self::MAX_FILE_BYTES ) {
			return new WP_Error( 'lw16_slicing_file_too_large', __( 'Datei ist unerwartet gross.', 'lindenweg16' ), array( 'status' => 500 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );
		header( 'Content-Length: ' . $groesse );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/* -----------------------------------------------------------------
	 * Statuswechsel
	 * ------------------------------------------------------------- */

	/**
	 * Auftrag claimen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function action_claim( $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! SlicingJobRepository::claim( $id ) ) {
			return new WP_Error( 'lw16_slicing_claim_failed', __( 'Auftrag ist nicht mehr verfügbar.', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'ok' => true, 'job' => $this->job_to_array( SlicingJobRepository::find( $id ) ) ), 200 );
	}

	/**
	 * Slice-Lauf gestartet.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function action_slicing( $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! SlicingJobRepository::mark_slicing( $id ) ) {
			return new WP_Error( 'lw16_slicing_invalid_state', __( 'Ungültiger Statuswechsel.', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Slice-Lauf erfolgreich abgeschlossen – die fertige Druckdatei kommt
	 * als multipart-Upload (Feld "datei") mit.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function action_complete( $request ) {
		$id = (int) $request->get_param( 'id' );

		$files = $request->get_file_params();

		if ( empty( $files['datei']['tmp_name'] ) || UPLOAD_ERR_OK !== $files['datei']['error'] ) {
			return new WP_Error( 'lw16_slicing_missing_upload', __( 'Keine Datei hochgeladen.', 'lindenweg16' ), array( 'status' => 400 ) );
		}

		$result = SlicingService::handle_result_upload( $id, $files['datei']['tmp_name'], $files['datei']['name'] );

		if ( ! $result['ok'] ) {
			return new WP_Error( 'lw16_slicing_complete_failed', $result['error'], array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Slice-Lauf fehlgeschlagen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function action_failed( $request ) {
		$id      = (int) $request->get_param( 'id' );
		$message = (string) $request->get_param( 'message' );

		if ( ! SlicingService::handle_failure( $id, $message ) ) {
			return new WP_Error( 'lw16_slicing_invalid_state', __( 'Ungültiger Statuswechsel (Auftrag evtl. bereits abgeschlossen oder unbekannt).', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
