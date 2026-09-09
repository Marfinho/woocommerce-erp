<?php
/**
 * Admin-Übersicht der Druck-Warteschlange: Drucker.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\LabelSheet;
use Lindenweg16\Modules\Betrieb\PrintQueue\PrinterRepository;
use Lindenweg16\Modules\Betrieb\PrintQueue\PrintJobRepository;
use Lindenweg16\Modules\Betrieb\PrintQueue\PrintQueueService;

/**
 * „Drucker": Registrierte Raspberry-Pi-Clients, ihr Online-Status und
 * die Token-Verwaltung.
 */
class PrintQueuePrintersPage {

	const SLUG  = 'lw16-betrieb-druckwarteschlange-drucker';
	const NONCE = 'lw16_print_queue_printers';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_druckwarteschlange_drucker', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_print_queue_add_printer', array( $this, 'handle_add' ) );
		add_action( 'admin_post_lw16_print_queue_regen_token', array( $this, 'handle_regen' ) );
		add_action( 'admin_post_lw16_print_queue_test_label', array( $this, 'handle_test_label' ) );
	}

	/**
	 * Zugriff prüfen.
	 */
	protected function require_cap() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_capture' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Neuen Drucker anlegen.
	 */
	public function handle_add() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$printer_id = isset( $_POST['printer_id'] ) ? sanitize_key( wp_unslash( $_POST['printer_id'] ) ) : '';
		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( '' === $printer_id || '' === $name ) {
			$this->back( __( 'Drucker-ID und Name sind Pflicht.', 'lindenweg16' ), 0 );
		}

		if ( PrinterRepository::find_by_printer_id( $printer_id ) ) {
			$this->back( __( 'Diese Drucker-ID gibt es schon.', 'lindenweg16' ), 0 );
		}

		$result = PrinterRepository::create( $printer_id, $name );

		$this->back( __( 'Drucker angelegt.', 'lindenweg16' ), $result['id'], $result['token'] );
	}

	/**
	 * Token neu erzeugen – das alte wird sofort ungültig.
	 */
	public function handle_regen() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$id    = isset( $_POST['printer_pk'] ) ? (int) $_POST['printer_pk'] : 0;
		$token = PrinterRepository::regenerate_token( $id );

		if ( '' === $token ) {
			$this->back( __( 'Drucker nicht gefunden.', 'lindenweg16' ), 0 );
		}

		$this->back( __( 'Neues Token erzeugt. Es wird nur jetzt angezeigt.', 'lindenweg16' ), $id, $token );
	}

	/**
	 * Testlabel-Job anlegen.
	 *
	 * Die Größe ist wählbar (DHL-Mass oder eine der Rollenformate aus
	 * LabelSheet) – ein Drucker mit schmaler Etikettenrolle kann mit
	 * einem 103×199-mm-DHL-Testlabel nichts anfangen, und beim
	 * brother_ql-Backend weist der Pi-Client ein falsch bemessenes Label
	 * ohnehin zurück (siehe README).
	 */
	public function handle_test_label() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$printer_id = isset( $_POST['printer_id'] ) ? sanitize_key( wp_unslash( $_POST['printer_id'] ) ) : '';
		$format     = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'dhl';

		if ( LabelSheet::has_roll( $format ) ) {
			$roll         = LabelSheet::roll( $format );
			$width_mm     = (float) $roll['w'];
			$height_mm    = (float) $roll['h'];
			$label_format = $roll['label'];
		} else {
			$width_mm     = 103;
			$height_mm    = 199;
			$label_format = '910-300-610 (DHL)';
		}

		$job_id = PrintQueueService::create_test_job( $printer_id, $width_mm, $height_mm, $label_format );

		$this->back(
			$job_id
				? __( 'Testlabel in die Warteschlange gelegt. Der Raspberry Pi holt es beim nächsten Kontakt ab.', 'lindenweg16' )
				: __( 'Testlabel konnte nicht angelegt werden.', 'lindenweg16' ),
			0
		);
	}

	/**
	 * Zurück zur Liste, optional mit einmalig angezeigtem Klartext-Token.
	 *
	 * @param string $msg    Meldung.
	 * @param int    $ignore Ungenutzt (Kompatibilität mit ShippingPage-Stil).
	 * @param string $token  Klartext-Token, nur einmalig transportiert.
	 */
	protected function back( $msg, $ignore = 0, $token = '' ) {
		$args = array(
			'page'     => self::SLUG,
			'lw16_msg' => rawurlencode( $msg ),
		);

		// Das Token reist nur in der Redirect-URL des aktuellen Requests,
		// nicht in der Datenbank oder in einem Log – nach dem Rendern der
		// Seite ist es verloren, wenn es sich niemand notiert hat.
		if ( '' !== $token ) {
			$args['lw16_token'] = rawurlencode( $token );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		echo '<h1>' . esc_html__( 'Drucker', 'lindenweg16' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['lw16_msg'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['lw16_token'] ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong><br><code style="font-size:14px;user-select:all">%s</code></p><p class="description">%s</p></div>',
				esc_html__( 'Token (wird nur jetzt angezeigt – jetzt in die .env des Raspberry Pi eintragen):', 'lindenweg16' ),
				esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_token'] ) ) ), // phpcs:ignore WordPress.Security.NonceVerification
				esc_html__( 'Diese Seite neu laden oder verlassen, und das Token ist nicht mehr abrufbar – dann hilft nur „Token neu erzeugen".', 'lindenweg16' )
			);
		}

		$this->render_add_form();

		echo '<h2>' . esc_html__( 'Registrierte Drucker', 'lindenweg16' ) . '</h2>';

		$printers = PrinterRepository::all();

		if ( ! $printers ) {
			echo '<p>' . esc_html__( 'Noch kein Drucker angelegt.', 'lindenweg16' ) . '</p>';
			return;
		}

		foreach ( $printers as $printer ) {
			$this->render_card( $printer );
		}
	}

	/**
	 * Formular für einen neuen Drucker.
	 */
	protected function render_add_form() {
		echo '<h2>' . esc_html__( 'Neuen Drucker anlegen', 'lindenweg16' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="lw16_print_queue_add_printer">';
		wp_nonce_field( self::NONCE );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Drucker-ID', 'lindenweg16' ) . '</th><td>';
		echo '<input type="text" name="printer_id" class="regular-text" placeholder="eg_dhl" pattern="[a-z0-9_\-]+" required>';
		echo '<p class="description">' . esc_html__( 'Technischer Schlüssel, z. B. eg_dhl, office_dhl, warehouse_dhl. Trägt der Raspberry Pi in PRINTER_ID ein.', 'lindenweg16' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Name', 'lindenweg16' ) . '</th><td>';
		echo '<input type="text" name="name" class="regular-text" placeholder="EG Etikettendrucker" required>';
		echo '</td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Drucker anlegen', 'lindenweg16' ) );
		echo '</form>';
	}

	/**
	 * Eine Druckerkarte.
	 *
	 * @param object $printer Datensatz.
	 */
	protected function render_card( $printer ) {
		$online = PrinterRepository::is_online( $printer );
		$queue  = PrintJobRepository::count_queued( $printer->printer_id );

		echo '<div class="lw16-card" style="border:1px solid #dcdcde;border-radius:4px;padding:16px;margin-bottom:12px;max-width:480px">';
		printf( '<h3 style="margin-top:0">%s <code>%s</code></h3>', esc_html( $printer->name ), esc_html( $printer->printer_id ) );

		printf(
			'<p><strong>%s:</strong> %s</p>',
			esc_html__( 'Status', 'lindenweg16' ),
			$online
				? '<span style="color:#00a32a">●</span> ' . esc_html__( 'Online', 'lindenweg16' )
				: '<span style="color:#d63638">●</span> ' . esc_html__( 'Offline', 'lindenweg16' )
		);

		printf(
			'<p><strong>%s:</strong> %s</p>',
			esc_html__( 'Letzter Kontakt', 'lindenweg16' ),
			$printer->last_seen_at ? esc_html( $this->human_time_diff( $printer->last_seen_at ) ) : esc_html__( 'nie', 'lindenweg16' )
		);

		if ( $printer->printer_model ) {
			printf( '<p><strong>%s:</strong> %s</p>', esc_html__( 'Drucker', 'lindenweg16' ), esc_html( $printer->printer_model ) );
		}

		printf( '<p><strong>%s:</strong> %d</p>', esc_html__( 'Warteschlange', 'lindenweg16' ), (int) $queue );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		echo '<input type="hidden" name="action" value="lw16_print_queue_test_label">';
		printf( '<input type="hidden" name="printer_id" value="%s">', esc_attr( $printer->printer_id ) );
		wp_nonce_field( self::NONCE );
		echo '<select name="format" style="margin-right:4px">';
		printf( '<option value="dhl">%s</option>', esc_html__( 'DHL-Mass 103 × 199 mm', 'lindenweg16' ) );
		foreach ( LabelSheet::rolls() as $key => $roll ) {
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $roll['label'] ) );
		}
		echo '</select>';
		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Testlabel drucken', 'lindenweg16' ) );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block" onsubmit="return confirm(\'' . esc_js( __( 'Neues Token erzeugen? Das alte wird sofort ungültig – der Raspberry Pi muss neu konfiguriert werden.', 'lindenweg16' ) ) . '\')">';
		echo '<input type="hidden" name="action" value="lw16_print_queue_regen_token">';
		printf( '<input type="hidden" name="printer_pk" value="%d">', (int) $printer->id );
		wp_nonce_field( self::NONCE );
		printf( '<button type="submit" class="button button-secondary">%s</button>', esc_html__( 'Token neu erzeugen', 'lindenweg16' ) );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * "vor X Sekunden/Minuten" aus einem GMT-Datetime.
	 *
	 * @param string $mysql_gmt GMT-Datetime.
	 * @return string
	 */
	protected function human_time_diff( $mysql_gmt ) {
		$timestamp = strtotime( $mysql_gmt . ' UTC' );

		if ( ! $timestamp ) {
			return __( 'unbekannt', 'lindenweg16' );
		}

		/* translators: %s: Zeitspanne, z. B. "3 Sekunden". */
		return sprintf( __( 'vor %s', 'lindenweg16' ), human_time_diff( $timestamp, time() ) );
	}
}
