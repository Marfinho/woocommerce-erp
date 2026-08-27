<?php
/**
 * Modul 7: Support und Reporting.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Support;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;
use Lindenweg16\Core\Roles;
use Lindenweg16\Core\Scheduler;
use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Modules\Loyalty\LoyaltyRepository;

/**
 * Reklamations-Workflow mit Gutschrift, VIP-Report und Wochen-Digest.
 */
class SupportModule {

	const ENDPOINT = 'problem-melden';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Frontend: Ticket-Formular im Kundenkonto.
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_account' ) );

		// Wochen-Digest und wöchentlicher Datenexport laufen über den
		// Action Scheduler, der seine Warteschlange auch im Frontend
		// abarbeitet.
		add_action( Scheduler::HOOK_WEEKLY_DIGEST, array( $this, 'send_digest' ) );
		add_action( Scheduler::HOOK_WEEKLY_EXPORT, array( $this, 'weekly_export' ) );

		if ( ! is_admin() ) {
			return;
		}

		$this->register_admin();
	}

	/**
	 * Bildschirmmasken und Formularempfänger – nur im Backend.
	 *
	 * Auch das Ticket-Formular des Kunden schickt an admin-post.php, und
	 * dort gilt `is_admin()`.
	 */
	protected function register_admin() {
		add_action( 'admin_post_lw16_ticket_create', array( $this, 'handle_ticket_create' ) );

		add_action( 'lw16_render_page_lw16_support', array( $this, 'render_support_admin' ) );
		add_action( 'lw16_render_page_lw16_reporting', array( $this, 'render_reporting_admin' ) );
		add_action( 'admin_post_lw16_ticket_action', array( $this, 'handle_ticket_action' ) );
		add_action( 'admin_post_lw16_export_now', array( $this, 'export_now' ) );
	}

	/* --------------------------------------------------------------------
	 * Phase 9: Datenexport & Audit-Log
	 * ------------------------------------------------------------------ */

	/**
	 * Wöchentlicher Export der Buchhaltungs- und Lagerdaten per E-Mail.
	 */
	public function weekly_export() {
		$email = (string) Settings::get( 'export_email', get_bloginfo( 'admin_email' ) );
		if ( ! is_email( $email ) ) {
			return;
		}

		$attachments = $this->build_export_files();
		MailTemplate::send( $email, 'datenexport', array(), $attachments );

		// Temporäre Dateien aufräumen.
		foreach ( $attachments as $f ) {
			@unlink( $f ); // phpcs:ignore
		}
	}

	/**
	 * Export sofort auslösen (Admin-Button).
	 */
	public function export_now() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_export_now' );
		$this->weekly_export();
		$this->redirect_reporting( __( 'Datenexport per E-Mail versendet.', 'lindenweg16' ) );
	}

	/**
	 * CSV-Exportdateien erzeugen und Pfade zurückgeben.
	 *
	 * @return string[]
	 */
	protected function build_export_files() {
		global $wpdb;
		$dir   = get_temp_dir();
		$files = array();

		$exports = array(
			'belege'        => 'SELECT * FROM ' . $wpdb->prefix . 'lw16_belege ORDER BY id',
			'lagerbewegung' => 'SELECT * FROM ' . $wpdb->prefix . 'lw16_lagerbewegung ORDER BY id',
			'rechnungen'    => 'SELECT * FROM ' . $wpdb->prefix . 'lw16_rechnungen ORDER BY id',
		);

		foreach ( $exports as $name => $sql ) {
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
			if ( empty( $rows ) ) {
				continue;
			}
			$path = trailingslashit( $dir ) . 'lw16-' . $name . '-' . gmdate( 'Ymd' ) . '.csv';
			$fh   = fopen( $path, 'w' ); // phpcs:ignore
			if ( ! $fh ) {
				continue;
			}
			fputcsv( $fh, array_keys( $rows[0] ) );
			foreach ( $rows as $row ) {
				fputcsv( $fh, $row );
			}
			fclose( $fh ); // phpcs:ignore
			$files[] = $path;
		}
		return $files;
	}

	/**
	 * Paginiertes Audit-Log rendern (für die Reporting-Seite).
	 */
	protected function render_audit_log() {
		$page   = isset( $_GET['ap'] ) ? max( 1, (int) $_GET['ap'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$rows   = AuditLog::get_page( 50, $page );
		echo '<h2>' . esc_html__( 'Audit-Log', 'lindenweg16' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Zeit', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Nutzer', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Aktion', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Objekt', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Details', 'lindenweg16' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$user = $r->user_id ? get_userdata( $r->user_id ) : null;
			echo '<tr><td>' . esc_html( $r->erstellt_am ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : '#' . $r->user_id ) . '</td>';
			echo '<td>' . esc_html( $r->aktion ) . '</td>';
			echo '<td>' . esc_html( trim( $r->objekt_typ . ' #' . $r->objekt_id ) ) . '</td>';
			echo '<td>' . esc_html( (string) $r->details ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Redirect zur Reporting-Seite.
	 *
	 * @param string $msg Meldung.
	 */
	protected function redirect_reporting( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'lw16-reporting', 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_tickets';
	}

	/* --------------------------------------------------------------------
	 * Kundenkonto: Ticket melden
	 * ------------------------------------------------------------------ */

	/**
	 * Endpoint registrieren.
	 */
	public function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Query-Var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Menüpunkt.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public function menu_item( $items ) {
		$new = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Problem melden', 'lindenweg16' );
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'Problem melden', 'lindenweg16' );
		}
		return $new;
	}

	/**
	 * Ticket-Formular + eigene Tickets rendern.
	 */
	public function render_account() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		if ( isset( $_GET['lw16_ticket'] ) && 'ok' === $_GET['lw16_ticket'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="woocommerce-message">' . esc_html__( 'Danke, wir haben Deine Nachricht erhalten und melden uns.', 'lindenweg16' ) . '</div>';
		}

		// Bestellungen des Kunden für die Zuordnung.
		$orders = wc_get_orders( array( 'customer_id' => $user_id, 'limit' => 20, 'return' => 'objects' ) );

		echo '<h3>' . esc_html__( 'Problem melden', 'lindenweg16' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="lw16_ticket_create">';
		wp_nonce_field( 'lw16_ticket_create' );
		echo '<p><label>' . esc_html__( 'Bestellung (optional)', 'lindenweg16' ) . '<br><select name="order_id"><option value="">' . esc_html__( '– keine –', 'lindenweg16' ) . '</option>';
		foreach ( $orders as $o ) {
			echo '<option value="' . (int) $o->get_id() . '">#' . esc_html( $o->get_order_number() ) . ' – ' . esc_html( $o->get_date_created()->date_i18n( 'd.m.Y' ) ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Betreff', 'lindenweg16' ) . '<br><input type="text" name="betreff" required class="input-text"></label></p>';
		echo '<p><label>' . esc_html__( 'Nachricht', 'lindenweg16' ) . '<br><textarea name="nachricht" rows="5" required class="input-text"></textarea></label></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Absenden', 'lindenweg16' ) . '</button></p>';
		echo '</form>';

		// Eigene Tickets.
		global $wpdb;
		$tickets = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id DESC', $user_id ) ); // phpcs:ignore
		if ( $tickets ) {
			echo '<h3>' . esc_html__( 'Deine Anfragen', 'lindenweg16' ) . '</h3>';
			echo '<table class="woocommerce-orders-table shop_table"><thead><tr><th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Betreff', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th></tr></thead><tbody>';
			foreach ( $tickets as $t ) {
				echo '<tr><td>' . esc_html( date_i18n( 'd.m.Y', strtotime( $t->erstellt_am ) ) ) . '</td><td>' . esc_html( $t->betreff ) . '</td><td>' . esc_html( $t->status ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Ticket anlegen.
	 */
	public function handle_ticket_create() {
		check_admin_referer( 'lw16_ticket_create' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_die( esc_html__( 'Bitte einloggen.', 'lindenweg16' ) );
		}
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'user_id'     => $user_id,
				'order_id'    => ! empty( $_POST['order_id'] ) ? (int) $_POST['order_id'] : null,
				'betreff'     => sanitize_text_field( wp_unslash( $_POST['betreff'] ?? '' ) ),
				'nachricht'   => sanitize_textarea_field( wp_unslash( $_POST['nachricht'] ?? '' ) ),
				'status'      => 'offen',
				'erstellt_am' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		$ticket_id = (int) $wpdb->insert_id;

		// Manager benachrichtigen.
		$emails = Roles::get_manager_emails();
		if ( $emails ) {
			MailTemplate::send( $emails, 'neues_ticket', array(
				'{ticket_id}' => (string) $ticket_id,
				'{betreff}'   => sanitize_text_field( wp_unslash( $_POST['betreff'] ?? '' ) ),
			) );
		}

		$redirect = wc_get_account_endpoint_url( self::ENDPOINT );
		wp_safe_redirect( add_query_arg( 'lw16_ticket', 'ok', $redirect ) );
		exit;
	}

	/* --------------------------------------------------------------------
	 * Admin: Tickets & Gutschrift
	 * ------------------------------------------------------------------ */

	/**
	 * Support-Admin-Seite.
	 */
	public function render_support_admin() {
		global $wpdb;
		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Support', 'lindenweg16' ) . '</h1>';
		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}

		$tickets = $wpdb->get_results( "SELECT * FROM " . self::table() . " WHERE status <> 'geloest' ORDER BY id DESC" ); // phpcs:ignore
		if ( empty( $tickets ) ) {
			echo '<p>' . esc_html__( 'Keine offenen Tickets.', 'lindenweg16' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Kunde', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Bestellung', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Betreff', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Nachricht', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Aktionen', 'lindenweg16' ) . '</th></tr></thead><tbody>';
		foreach ( $tickets as $t ) {
			$user = get_userdata( $t->user_id );
			echo '<tr>';
			echo '<td>' . (int) $t->id . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : '#' . $t->user_id ) . '</td>';
			echo '<td>' . ( $t->order_id ? '#' . (int) $t->order_id : '–' ) . '</td>';
			echo '<td>' . esc_html( $t->betreff ) . '</td>';
			echo '<td>' . esc_html( wp_trim_words( (string) $t->nachricht, 20 ) ) . '</td>';
			echo '<td>' . esc_html( $t->status ) . '</td>';
			echo '<td>';
			// Status setzen.
			$in_bearb = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_ticket_action&id=' . $t->id . '&do=in_bearbeitung' ), 'lw16_ticket_' . $t->id );
			$geloest  = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_ticket_action&id=' . $t->id . '&do=geloest' ), 'lw16_ticket_' . $t->id );
			echo '<a class="button button-small" href="' . esc_url( $in_bearb ) . '">' . esc_html__( 'In Bearbeitung', 'lindenweg16' ) . '</a> ';
			echo '<a class="button button-small" href="' . esc_url( $geloest ) . '">' . esc_html__( 'Gelöst', 'lindenweg16' ) . '</a>';
			// Antwort an den Kunden.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-inline-form" style="margin-top:6px">';
			echo '<input type="hidden" name="action" value="lw16_ticket_action"><input type="hidden" name="id" value="' . (int) $t->id . '"><input type="hidden" name="do" value="antwort">';
			wp_nonce_field( 'lw16_ticket_' . $t->id );
			echo '<textarea name="antwort_text" rows="2" cols="40" placeholder="' . esc_attr__( 'Antwort an den Kunden…', 'lindenweg16' ) . '" required></textarea> ';
			submit_button( __( 'Antwort senden', 'lindenweg16' ), 'small', '', false );
			echo '</form>';
			// Gutschrift.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-inline-form" style="margin-top:6px">';
			echo '<input type="hidden" name="action" value="lw16_ticket_action"><input type="hidden" name="id" value="' . (int) $t->id . '"><input type="hidden" name="do" value="gutschrift">';
			wp_nonce_field( 'lw16_ticket_' . $t->id );
			echo '<input type="number" step="0.01" name="betrag" placeholder="€" class="small-text" required> ';
			echo '<input type="text" name="grund" placeholder="' . esc_attr__( 'Begründung', 'lindenweg16' ) . '"> ';
			submit_button( __( 'Gutschrift buchen', 'lindenweg16' ), 'small primary', '', false );
			echo '</form>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Ticket-Aktion (Status / Gutschrift).
	 */
	public function handle_ticket_action() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_REQUEST['id'] ?? 0 );
		check_admin_referer( 'lw16_ticket_' . $id );
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';

		global $wpdb;
		$ticket = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore
		if ( ! $ticket ) {
			$this->redirect( __( 'Ticket nicht gefunden.', 'lindenweg16' ) );
		}

		if ( 'antwort' === $do ) {
			$text = sanitize_textarea_field( wp_unslash( $_POST['antwort_text'] ?? '' ) );
			if ( $text ) {
				$wpdb->update(
					self::table(),
					array( 'status' => 'in_bearbeitung', 'beantwortet_am' => current_time( 'mysql' ) ),
					array( 'id' => $id )
				);
				$this->notify_customer(
					$ticket,
					sprintf(
						/* translators: 1: Betreff, 2: Antworttext */
						__( "Betreff: %1\$s\n\n%2\$s", 'lindenweg16' ),
						$ticket->betreff,
						$text
					)
				);
			}
			$this->redirect( __( 'Antwort gesendet.', 'lindenweg16' ) );
		}

		if ( 'gutschrift' === $do ) {
			$betrag = (float) ( $_POST['betrag'] ?? 0 );
			$grund  = sanitize_text_field( wp_unslash( $_POST['grund'] ?? '' ) );
			if ( $betrag > 0 ) {
				LoyaltyRepository::adjust_credit( (int) $ticket->user_id, $betrag );
				AuditLog::record( 'gutschrift_reklamation', 'ticket', $id, array( 'betrag' => $betrag, 'grund' => $grund, 'user' => $ticket->user_id ) );
				$this->notify_customer( $ticket, sprintf( /* translators: %s: Betrag */ __( 'Wir haben Dir eine Gutschrift von %s € auf Dein Guthaben gebucht.', 'lindenweg16' ), number_format( $betrag, 2, ',', '.' ) ) );
			}
			$this->redirect( __( 'Gutschrift gebucht.', 'lindenweg16' ) );
		}

		if ( in_array( $do, array( 'in_bearbeitung', 'geloest' ), true ) ) {
			$wpdb->update(
				self::table(),
				array( 'status' => $do, 'beantwortet_am' => current_time( 'mysql' ) ),
				array( 'id' => $id )
			);
			$this->notify_customer( $ticket, 'geloest' === $do ? __( 'Wir haben Dein Anliegen bearbeitet.', 'lindenweg16' ) : __( 'Wir kümmern uns um Dein Anliegen.', 'lindenweg16' ) );
			$this->redirect( __( 'Status aktualisiert.', 'lindenweg16' ) );
		}

		$this->redirect( __( 'Keine Aktion.', 'lindenweg16' ) );
	}

	/**
	 * Kurze Benachrichtigung an den Kunden.
	 *
	 * @param object $ticket  Ticket.
	 * @param string $message Nachricht.
	 */
	protected function notify_customer( $ticket, $message ) {
		$user = get_userdata( $ticket->user_id );
		if ( $user && is_email( $user->user_email ) ) {
			MailTemplate::send( $user->user_email, 'ticket_update', array(
				'{nachricht}' => $message,
			) );
		}
	}

	/* --------------------------------------------------------------------
	 * Reporting: VIP-Report
	 * ------------------------------------------------------------------ */

	/**
	 * Reporting-Admin-Seite (VIP-Report).
	 */
	public function render_reporting_admin() {
		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Reporting', 'lindenweg16' ) . '</h1>';

		$jahr = (int) gmdate( 'Y' );
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : $jahr . '-01-01'; // phpcs:ignore WordPress.Security.NonceVerification
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : $jahr . '-12-31'; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<h2>' . esc_html__( 'VIP-Kunden', 'lindenweg16' ) . '</h2>';
		echo '<form method="get"><input type="hidden" name="page" value="lw16-reporting">';
		echo '<label>' . esc_html__( 'Von', 'lindenweg16' ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '"></label> ';
		echo '<label>' . esc_html__( 'Bis', 'lindenweg16' ) . ' <input type="date" name="to" value="' . esc_attr( $to ) . '"></label> ';
		submit_button( __( 'Anzeigen', 'lindenweg16' ), 'secondary', '', false );
		echo '</form>';

		$vips = $this->vip_customers( $from, $to );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Kunde', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Bestellungen', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Gesamtwert', 'lindenweg16' ) . '</th></tr></thead><tbody>';
		foreach ( $vips as $v ) {
			echo '<tr><td>' . esc_html( $v['name'] ) . '</td><td>' . (int) $v['count'] . '</td><td>' . esc_html( number_format( $v['total'], 2, ',', '.' ) ) . ' €</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Reine Lese-Ansicht als Grundlage für persönliche Gesten.', 'lindenweg16' ) . '</p>';

		// Bestellstatistiken.
		$this->render_order_statistics();

		// Datenexport.
		echo '<h2>' . esc_html__( 'Datensicherung', 'lindenweg16' ) . '</h2>';
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_export_now' ), 'lw16_export_now' );
		echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Datenexport jetzt per E-Mail senden', 'lindenweg16' ) . '</a> ';
		echo '<span class="description">' . esc_html( sprintf( /* translators: %s: E-Mail */ __( 'Empfänger: %s (in den Einstellungen änderbar). Läuft zusätzlich wöchentlich automatisch.', 'lindenweg16' ), Settings::get( 'export_email' ) ) ) . '</span></p>';

		// Audit-Log.
		$this->render_audit_log();

		echo '</div>';
	}

	/**
	 * Bestellstatistiken: Tages-/Wochen-/Monatsumsatz.
	 */
	protected function render_order_statistics() {
		$now   = current_time( 'timestamp' );
		$today = gmdate( 'Y-m-d', $now );
		$week  = gmdate( 'Y-m-d', $now - 7 * DAY_IN_SECONDS );
		$month = gmdate( 'Y-m-d', $now - 30 * DAY_IN_SECONDS );

		$periods = array(
			__( 'Heute', 'lindenweg16' )           => $today,
			__( 'Letzte 7 Tage', 'lindenweg16' )   => $week,
			__( 'Letzte 30 Tage', 'lindenweg16' )  => $month,
		);

		echo '<h2>' . esc_html__( 'Bestellstatistiken', 'lindenweg16' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Zeitraum', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Bestellungen', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Umsatz', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Durchschnitt', 'lindenweg16' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $periods as $label => $since ) {
			$orders = wc_get_orders( array(
				'status'       => array( 'wc-completed', 'wc-processing' ),
				'date_created' => '>=' . strtotime( $since ),
				'limit'        => -1,
				'return'       => 'objects',
			) );
			$count  = count( $orders );
			$umsatz = 0;
			foreach ( $orders as $o ) {
				$umsatz += (float) $o->get_total();
			}
			$avg = $count > 0 ? $umsatz / $count : 0;

			echo '<tr>';
			echo '<td><strong>' . esc_html( $label ) . '</strong></td>';
			echo '<td>' . (int) $count . '</td>';
			echo '<td>' . esc_html( number_format( $umsatz, 2, ',', '.' ) ) . ' &euro;</td>';
			echo '<td>' . esc_html( number_format( $avg, 2, ',', '.' ) ) . ' &euro;</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * VIP-Kunden für einen Zeitraum (Transient-Cache).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array
	 */
	protected function vip_customers( $from, $to ) {
		$cache_key = 'lw16_vip_' . md5( $from . $to );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$orders = wc_get_orders(
			array( 'status' => array( 'wc-completed' ), 'date_created' => $from . '...' . $to, 'limit' => -1, 'return' => 'objects' )
		);
		$agg = array();
		foreach ( $orders as $order ) {
			$uid = (int) $order->get_customer_id();
			$key = $uid ?: 'g_' . $order->get_billing_email();
			if ( ! isset( $agg[ $key ] ) ) {
				$name = trim( $order->get_formatted_billing_full_name() );
				$agg[ $key ] = array( 'name' => $name ?: __( 'Gast', 'lindenweg16' ), 'count' => 0, 'total' => 0 );
			}
			$agg[ $key ]['count']++;
			$agg[ $key ]['total'] += (float) $order->get_total();
		}
		usort( $agg, function ( $a, $b ) {
			return $b['total'] <=> $a['total'];
		} );
		$agg = array_slice( $agg, 0, 50 );

		set_transient( $cache_key, $agg, 30 * MINUTE_IN_SECONDS );
		return $agg;
	}

	/* --------------------------------------------------------------------
	 * Wochen-Digest
	 * ------------------------------------------------------------------ */

	/**
	 * Wöchentlichen Digest an alle Manager verschicken.
	 */
	public function send_digest() {
		// Nur am konfigurierten Wochentag senden.
		$wochentag = (int) Settings::get( 'digest_wochentag', 1 );
		if ( (int) gmdate( 'N' ) !== $wochentag ) {
			return;
		}

		$emails = Roles::get_manager_emails();
		if ( empty( $emails ) ) {
			return;
		}

		$seit   = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
		$orders = wc_get_orders( array( 'status' => array( 'wc-completed', 'wc-processing' ), 'date_created' => '>=' . strtotime( $seit ), 'limit' => -1, 'return' => 'objects' ) );
		$anzahl = count( $orders );
		$umsatz = 0;
		foreach ( $orders as $o ) {
			$umsatz += (float) $o->get_total();
		}

		// Lagerwarnungen.
		$warnungen = $this->lager_warnungen();

		// Neue Referrals.
		global $wpdb;
		$neue_refs = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'lw16_referrals WHERE created_at >= %s', $seit ) ); // phpcs:ignore

		// Neue Produktbewertungen.
		$neue_reviews = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'review' AND comment_approved = '1' AND comment_date >= %s",
			$seit
		) ); // phpcs:ignore

		MailTemplate::send( $emails, 'wochen_digest', array(
			'{bestellungen}'   => (string) $anzahl,
			'{umsatz}'         => number_format( $umsatz, 2, ',', '.' ),
			'{referrals}'      => (string) $neue_refs,
			'{bewertungen}'    => (string) $neue_reviews,
			'{lagerwarnungen}' => $warnungen ?: __( 'keine', 'lindenweg16' ),
		) );
	}

	/**
	 * Textliste der Lager-Ampelwarnungen (gelb/rot).
	 *
	 * @return string
	 */
	protected function lager_warnungen() {
		$produkte = \Lindenweg16\Modules\Inventory\Repository::get_products();
		$lines    = array();
		foreach ( $produkte as $p ) {
			$ampel = \Lindenweg16\Modules\Inventory\StockManager::ampel( $p );
			if ( 'gruen' === $ampel ) {
				continue;
			}
			$bestand = \Lindenweg16\Modules\Inventory\Repository::get_stock( $p->id );
			$lines[] = '- ' . $p->name . ' (' . $ampel . '): ' . rtrim( rtrim( number_format( $bestand, 2, ',', '.' ), '0' ), ',' ) . ' ' . $p->basis_einheit;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Redirect zur Support-Seite.
	 *
	 * @param string $msg Meldung.
	 */
	protected function redirect( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'lw16-support', 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
