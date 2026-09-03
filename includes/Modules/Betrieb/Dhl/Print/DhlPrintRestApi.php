<?php
/**
 * REST-Schnittstelle der DHL-Druck-Warteschlange für Raspberry-Pi-Clients.
 *
 * Eigener Namespace, eigene Authentifizierung: Anders als lw16/v1 (Cookie
 * + Nonce, für angemeldete Redakteure) authentifiziert sich hier ein
 * Gerät ohne WordPress-Konto über ein langes Bearer-Token je Drucker.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Dhl\Print;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registriert und beantwortet die Endpunkte unter lw16-dhl-print/v1.
 */
class DhlPrintRestApi {

	const NAMESPACE_V1 = 'lw16-dhl-print/v1';

	/**
	 * Untergrenze/Obergrenze für den Long-Poll-Parameter wait (Sekunden).
	 */
	const WAIT_MIN = 5;
	const WAIT_MAX = 30;

	/**
	 * Wie oft während eines Long-Polls die Warteschlange geprüft wird.
	 */
	const POLL_INTERVAL_SECONDS = 1;

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

		foreach ( array( 'claim', 'printing', 'complete', 'failed' ) as $action ) {
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
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'authenticate' ),
			)
		);
	}

	/* -----------------------------------------------------------------
	 * Authentifizierung
	 * ------------------------------------------------------------- */

	/**
	 * Bearer-Token prüfen, Ratenlimit gegen Rateraten anwenden, Drucker in
	 * die Anfrage einhängen. Nonces genügen hier nicht – ein Pi hat kein
	 * WordPress-Konto und keine Session, gegen die eine Nonce prüfen könnte.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return bool|WP_Error
	 */
	public function authenticate( $request ) {
		$header = $request->get_header( 'authorization' );
		$token  = '';

		if ( $header && 0 === stripos( trim( $header ), 'Bearer ' ) ) {
			$token = trim( substr( trim( $header ), 7 ) );
		}

		$ip = $this->client_ip();

		if ( $this->rate_limited( $ip ) ) {
			return new WP_Error( 'lw16_dhl_print_rate_limited', __( 'Zu viele Anfragen. Bitte kurz warten.', 'lindenweg16' ), array( 'status' => 429 ) );
		}

		if ( '' === $token ) {
			$this->register_auth_failure( $ip );
			return new WP_Error( 'lw16_dhl_print_unauthorized', __( 'Kein Bearer-Token übermittelt.', 'lindenweg16' ), array( 'status' => 401 ) );
		}

		$printer = DhlPrinterRepository::find_by_token( $token );

		if ( ! $printer ) {
			$this->register_auth_failure( $ip );
			// Niemals das Token selbst loggen – nur, dass ein Versuch scheiterte.
			return new WP_Error( 'lw16_dhl_print_unauthorized', __( 'Ungültiges Token.', 'lindenweg16' ), array( 'status' => 401 ) );
		}

		$requested_printer_id = (string) $request->get_param( 'printer_id' );

		if ( '' !== $requested_printer_id && $requested_printer_id !== $printer->printer_id ) {
			// Ein Drucker darf ausschließlich seine eigenen Jobs sehen – das
			// Token entscheidet die Identität, printer_id ist nur eine
			// Bestätigung, kein zweiter Zugangsweg.
			return new WP_Error( 'lw16_dhl_print_forbidden', __( 'Dieses Token gehört zu einem anderen Drucker.', 'lindenweg16' ), array( 'status' => 403 ) );
		}

		DhlPrinterRepository::touch( (int) $printer->id, $ip );

		$request->set_param( '_dhl_printer', $printer );

		return true;
	}

	/**
	 * Anfragende IP – nur zur Diagnose und fürs Ratenlimit, kein
	 * Sicherheitsmerkmal für sich genommen (hinter Proxys unzuverlässig).
	 *
	 * @return string
	 */
	protected function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Einfaches Ratenlimit gegen Token-Raten: mehr als 30 gescheiterte
	 * Versuche je IP und Minute werden abgewiesen.
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	protected function rate_limited( $ip ) {
		if ( '' === $ip ) {
			return false;
		}
		$key = 'lw16_dhl_print_authfail_' . md5( $ip );
		return (int) get_transient( $key ) >= 30;
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
		$key   = 'lw16_dhl_print_authfail_' . md5( $ip );
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
		return $request->get_param( '_dhl_printer' );
	}

	/* -----------------------------------------------------------------
	 * GET /jobs (Long Polling)
	 * ------------------------------------------------------------- */

	/**
	 * Wartende Jobs liefern – sofort, wenn welche da sind, sonst bis zu
	 * `wait` Sekunden warten und danach 204 liefern.
	 *
	 * PHP-/Proxy-Timeouts: Die Wartezeit ist serverseitig auf WAIT_MAX
	 * begrenzt (Default-Empfehlung an den Betreiber: PHP max_execution_time
	 * und ein eventueller Reverse-Proxy-Timeout müssen WAIT_MAX zzgl.
	 * etwas Puffer überschreiten – siehe raspberry-pi/README.md).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public function get_jobs( $request ) {
		$printer = $this->printer_of( $request );
		$wait    = DhlPrintJobRepository::clamp_wait( $request->get_param( 'wait' ), self::WAIT_MIN, self::WAIT_MAX );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $wait + 15 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		}

		DhlPrintJobRepository::release_stale_locks();

		$deadline = microtime( true ) + $wait;

		while ( true ) {
			$jobs = DhlPrintJobRepository::queued_for_printer( $printer->printer_id, 20 );

			if ( $jobs ) {
				return new WP_REST_Response( array( 'jobs' => array_map( array( $this, 'job_to_array' ), $jobs ) ), 200 );
			}

			if ( microtime( true ) >= $deadline ) {
				return new WP_REST_Response( null, 204 );
			}

			if ( function_exists( 'connection_aborted' ) && connection_aborted() ) {
				// Der Pi hat die Verbindung schon getrennt (Timeout, Neustart) –
				// den PHP-Worker nicht länger als nötig blockieren.
				return new WP_REST_Response( null, 204 );
			}

			// Kurze Pause statt Busy-Loop: moderate Prüfabstände statt
			// dauerhafter Last, aber ohne WebSockets/SSE auszukommen.
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
			'id'         => (int) $job->id,
			'order_id'   => $job->order_id ? (int) $job->order_id : null,
			'printer_id' => (string) $job->printer_id,
			'filename'   => (string) $job->label_filename,
			'format'     => (string) $job->label_format,
			'width_mm'   => (int) $job->width_mm,
			'height_mm'  => (int) $job->height_mm,
			'is_test'    => (bool) $job->is_test,
			'created_at' => self::to_rfc3339( $job->created_at ),
		);
	}

	/**
	 * MySQL-Datetime (GMT, wie in dieser Warteschlange gespeichert) als
	 * ISO-8601/RFC-3339-Zeitstempel.
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
	 * Labeldatei ausliefern. Der Pfad kommt ausschließlich aus der
	 * Datenbank (server-generiert bei der Job-Anlage) – der Client liefert
	 * nur die numerische Job-ID, nie einen Dateinamen oder Pfad. Das
	 * schließt Path Traversal strukturell aus.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job_file( $request ) {
		$printer = $this->printer_of( $request );
		$job     = DhlPrintJobRepository::find_for_printer( (int) $request->get_param( 'id' ), $printer->printer_id );

		if ( ! $job ) {
			return new WP_Error( 'lw16_dhl_print_not_found', __( 'Job nicht gefunden.', 'lindenweg16' ), array( 'status' => 404 ) );
		}

		if ( ! in_array( $job->status, array( 'claimed', 'printing' ), true ) ) {
			return new WP_Error( 'lw16_dhl_print_not_claimed', __( 'Der Job muss zuerst geclaimt werden.', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		$pfad = $job->label_path;

		// Nur PDF, nur eine tatsächlich vorhandene, von diesem Plugin
		// erzeugte Datei – keine beliebige Datei des Systems.
		if ( ! is_file( $pfad ) || 'pdf' !== strtolower( pathinfo( $pfad, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'lw16_dhl_print_missing_file', __( 'Labeldatei nicht gefunden.', 'lindenweg16' ), array( 'status' => 404 ) );
		}

		$groesse = filesize( $pfad );

		// 15 MB genügt für ein Rollenetikett um ein Vielfaches – ein
		// größerer Wert deutet auf eine falsche Datei hin, nicht auf ein
		// echtes Label.
		if ( false === $groesse || $groesse > 15 * MB_IN_BYTES ) {
			return new WP_Error( 'lw16_dhl_print_file_too_large', __( 'Datei ist unerwartet groß.', 'lindenweg16' ), array( 'status' => 500 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $job->label_filename ) . '"' );
		header( 'Content-Length: ' . $groesse );
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

		if ( ! DhlPrintJobRepository::claim( $id, $printer->printer_id ) ) {
			return new WP_Error( 'lw16_dhl_print_claim_failed', __( 'Job ist nicht mehr verfügbar (schon geclaimt oder nicht vorhanden).', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		AuditLog::record( 'dhl_druckjob_geclaimt', 'dhl_print_job', $id, array( 'drucker' => $printer->printer_id ) );

		$job = DhlPrintJobRepository::find_for_printer( $id, $printer->printer_id );

		return new WP_REST_Response( array( 'ok' => true, 'job' => $this->job_to_array( $job ) ), 200 );
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

		if ( ! DhlPrintJobRepository::mark_printing( $id, $printer->printer_id ) ) {
			return new WP_Error( 'lw16_dhl_print_invalid_state', __( 'Ungültiger Statuswechsel.', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		AuditLog::record( 'dhl_druck_gestartet', 'dhl_print_job', $id, array( 'drucker' => $printer->printer_id ) );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Druck erfolgreich abgeschlossen. Idempotent – ein wiederholter
	 * Aufruf für einen bereits gedruckten Job bleibt erfolgreich, ohne
	 * den Job erneut anzustoßen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function action_complete( $request ) {
		$printer = $this->printer_of( $request );
		$id      = (int) $request->get_param( 'id' );
		$result  = DhlPrintJobRepository::complete( $id, $printer->printer_id );

		if ( ! $result['ok'] ) {
			return new WP_Error( 'lw16_dhl_print_invalid_state', __( 'Ungültiger Statuswechsel.', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		if ( ! $result['already'] ) {
			AuditLog::record( 'dhl_druck_erfolgreich', 'dhl_print_job', $id, array( 'drucker' => $printer->printer_id ) );
		}

		return new WP_REST_Response( array( 'ok' => true, 'already' => $result['already'] ), 200 );
	}

	/**
	 * Druck fehlgeschlagen (z. B. CUPS-Fehler). Idempotent wie complete().
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function action_failed( $request ) {
		$printer = $this->printer_of( $request );
		$id      = (int) $request->get_param( 'id' );
		$message = (string) $request->get_param( 'message' );

		if ( ! DhlPrintJobRepository::fail( $id, $printer->printer_id, $message ) ) {
			return new WP_Error( 'lw16_dhl_print_invalid_state', __( 'Ungültiger Statuswechsel (Job evtl. bereits gedruckt).', 'lindenweg16' ), array( 'status' => 409 ) );
		}

		AuditLog::record( 'dhl_druck_fehlgeschlagen', 'dhl_print_job', $id, array( 'drucker' => $printer->printer_id, 'meldung' => $message ) );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* -----------------------------------------------------------------
	 * GET /status
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
				'online'      => DhlPrinterRepository::is_online( $printer ),
				'last_poll'   => self::to_rfc3339( $printer->last_seen_at ),
				'queued_jobs' => DhlPrintJobRepository::count_queued( $printer->printer_id ),
			),
			200
		);
	}
}
