<?php
/**
 * Modul 1: Buchhaltung (Belege, EÜR, geschützte Beleg-Auslieferung).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;
use Lindenweg16\Core\AuditLog;

/**
 * Verdrahtet Belegerfassung, EÜR-Ansicht, Dashboard-Rücklage und die
 * authentifizierte Auslieferung von Beleg-Fotos.
 */
class AccountingModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// Diese Haken laufen auch ohne Backend: Bestellungen wechseln ihren
		// Status im Kundenkonto, an der Kasse und per Webhook, und der
		// Action Scheduler arbeitet seine Warteschlange in ganz normalen
		// Seitenaufrufen ab.

		// Automatische Rechnungserstellung: bei Abschluss asynchron einreihen.
		add_action( 'woocommerce_order_status_completed', array( $this, 'enqueue_invoice' ), 30, 1 );
		add_action( \Lindenweg16\Core\Scheduler::HOOK_GENERATE_INVOICE, array( $this, 'generate_invoice' ), 10, 1 );

		// Zahlungsgebühr übernehmen. Bewusst an mehreren Statuswechseln: Die
		// Gebühr schreibt der Zahlungsdienstleister mal beim Bezahlen, mal
		// erst Tage später in eine Notiz. Die Buchung selbst ist gegen
		// Doppelung gesichert, also schadet ein Aufruf zu viel nicht.
		add_action( 'woocommerce_order_status_processing', array( $this, 'capture_order_fee' ), 40, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'capture_order_fee' ), 40, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'capture_order_fee' ), 40, 1 );
		add_action( 'woocommerce_order_note_added', array( $this, 'capture_fee_from_note' ), 10, 2 );

		// Nachlauf: WooPayments traegt die Gebuehr per Webhook nach, oft
		// Stunden nach dem Kauf und ohne dass ein Status wechselt oder eine
		// Notiz entsteht. Ohne diesen taeglichen Nachlauf bliebe sie liegen.
		add_action( \Lindenweg16\Core\Scheduler::HOOK_DAILY_FEES, array( $this, 'sync_recent_fees' ) );

		// Dauerbuchungen automatisch ausführen (Scheduler-Hook).
		add_action( \Lindenweg16\Core\Scheduler::HOOK_RECURRING_BOOKINGS, array( $this, 'run_recurring_bookings' ) );

		// Rechnung im Kundenkonto verlinken.
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'account_order_invoice_action' ), 10, 2 );

		if ( ! is_admin() ) {
			return;
		}

		$this->register_admin();
	}

	/**
	 * Alles, was nur im Backend gebraucht wird.
	 *
	 * Formularempfänger, AJAX-Antworten und Bildschirmmasken. Sie hängen an
	 * `admin_post_*`, `wp_ajax_*` und den eigenen Seiten-Hooks – und die
	 * laufen sämtlich im Adminkontext, admin-post.php und admin-ajax.php
	 * eingeschlossen. Im Frontend wären es nur Einträge in der Hakentabelle,
	 * die nie zum Zug kommen.
	 */
	protected function register_admin() {
		add_action( 'lw16_render_page_lw16_buchhaltung', array( $this, 'render_admin' ) );

		add_action( 'admin_post_lw16_save_beleg', array( $this, 'handle_save_beleg' ) );
		add_action( 'admin_post_lw16_festschreiben', array( $this, 'handle_festschreiben' ) );

		// Geschützte Beleg-Foto-Auslieferung (nur mit Capability).
		add_action( 'admin_post_lw16_beleg_datei', array( $this, 'serve_beleg_file' ) );

		// Gebühr von Hand nachtragen oder erneut suchen.
		add_action( 'admin_post_lw16_fee_rescan', array( $this, 'handle_fee_rescan' ) );

		// Beleg-Storno.
		add_action( 'admin_post_lw16_beleg_storno', array( $this, 'handle_beleg_storno' ) );

		// Rechnungs-Storno.
		add_action( 'admin_post_lw16_rechnung_storno', array( $this, 'handle_rechnung_storno' ) );

		// E-Rechnung Upload.
		add_action( 'admin_post_lw16_erechnung_upload', array( $this, 'handle_erechnung_upload' ) );
		add_action( 'admin_post_lw16_erechnung_beleg', array( $this, 'handle_erechnung_beleg' ) );

		// Betriebsprüfungs-Export.
		add_action( 'admin_post_lw16_bp_export', array( $this, 'handle_bp_export' ) );

		// GoBD-Prüfung.
		add_action( 'admin_post_lw16_gobd_check', array( $this, 'handle_gobd_check' ) );

		// Verfahrensdokumentation.
		add_action( 'admin_post_lw16_verfahrensdoku', array( $this, 'handle_verfahrensdoku' ) );

		// Dauerbuchungen.
		add_action( 'admin_post_lw16_save_dauerbuchung', array( $this, 'handle_save_dauerbuchung' ) );
		add_action( 'admin_post_lw16_deactivate_dauerbuchung', array( $this, 'handle_deactivate_dauerbuchung' ) );
		add_action( 'admin_post_lw16_execute_dauerbuchungen', array( $this, 'handle_execute_dauerbuchungen' ) );

		// Anlagenbuchhaltung.
		add_action( 'admin_post_lw16_save_anlage', array( $this, 'handle_save_anlage' ) );
		add_action( 'admin_post_lw16_book_afa', array( $this, 'handle_book_afa' ) );
		add_action( 'admin_post_lw16_ausscheiden_anlage', array( $this, 'handle_ausscheiden_anlage' ) );

		// Kassenbuch.
		add_action( 'admin_post_lw16_save_kassenbuch', array( $this, 'handle_save_kassenbuch' ) );
		add_action( 'admin_post_lw16_storno_kassenbuch', array( $this, 'handle_storno_kassenbuch' ) );

		// Beleg-OCR (AJAX).
		add_action( 'wp_ajax_lw16_ocr_scan', array( $this, 'handle_ocr_scan' ) );

		// EÜR-PDF-Export.
		add_action( 'admin_post_lw16_euer_pdf', array( $this, 'export_euer_pdf' ) );

		// Rechnungs-Download (Admin + Kunde).
		add_action( 'admin_post_lw16_rechnung', array( $this, 'serve_invoice' ) );
		add_action( 'admin_post_nopriv_lw16_rechnung', array( $this, 'serve_invoice' ) );

		// Rechnung in der Bestellansicht (Admin) verlinken.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'admin_order_invoice_link' ) );
	}

	/**
	 * Rechnungserzeugung asynchron einreihen (nicht im Kunden-Request).
	 *
	 * @param int $order_id Bestell-ID.
	 */
	public function enqueue_invoice( $order_id ) {
		\Lindenweg16\Core\Scheduler::enqueue_async( \Lindenweg16\Core\Scheduler::HOOK_GENERATE_INVOICE, array( 'order_id' => (int) $order_id ) );
	}

	/**
	 * Zahlungsgebühr einer Bestellung erkennen und buchen.
	 *
	 * @param int $order_id Bestell-ID.
	 * @return int Buchungs-ID, 0 wenn nichts gebucht wurde.
	 */
	public function capture_order_fee( $order_id ) {
		if ( ! (int) Settings::get( 'gebuehren_automatisch', 1 ) ) {
			return 0;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return 0;
		}

		$fee = FeeExtractor::for_order( $order );

		if ( null === $fee['amount'] ) {
			return 0;
		}

		return BuchungRepository::create_from_order_fee( $order, $fee['amount'], $fee['source'] );
	}

	/**
	 * Gebühren der letzten Wochen nachtragen.
	 *
	 * Läuft täglich. Betrachtet werden bezahlte Bestellungen der letzten 30
	 * Tage; für jede, die noch keine Gebührenbuchung hat, wird erneut
	 * gesucht. Die Buchung selbst ist gegen Doppelung gesichert, der Lauf
	 * kann also gefahrlos wiederholt werden.
	 *
	 * @return int Anzahl neu gebuchter Gebühren.
	 */
	public function sync_recent_fees() {
		if ( ! (int) Settings::get( 'gebuehren_automatisch', 1 ) || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$orders = wc_get_orders(
			array(
				'status'       => array( 'processing', 'completed', 'on-hold' ),
				'limit'        => 200,
				'date_created' => '>' . ( time() - 30 * DAY_IN_SECONDS ),
				'return'       => 'objects',
			)
		);

		$booked = 0;

		foreach ( (array) $orders as $order ) {
			if ( $this->capture_order_fee( $order->get_id() ) ) {
				$booked++;
			}
		}

		return $booked;
	}

	/**
	 * Neu hinzugekommene Notiz sofort auswerten.
	 *
	 * Viele Dienstleister tragen die Gebühr erst nach, wenn die Zahlung
	 * abgerechnet ist – oft Tage nach dem letzten Statuswechsel. Ohne diesen
	 * Haken bliebe sie bis zum nächsten Statuswechsel unbeachtet, und bei
	 * abgeschlossenen Bestellungen käme keiner mehr.
	 *
	 * @param int   $note_id Notiz-ID.
	 * @param array $args    Argumente von WooCommerce.
	 */
	public function capture_fee_from_note( $note_id, $args = array() ) {
		$order_id = 0;

		if ( is_array( $args ) && isset( $args['order_id'] ) ) {
			$order_id = (int) $args['order_id'];
		} elseif ( is_object( $args ) && isset( $args->id ) ) {
			$order_id = (int) $args->id;
		}

		if ( $order_id ) {
			$this->capture_order_fee( $order_id );
		}
	}

	/**
	 * Gebühr einer Bestellung von Hand suchen lassen.
	 */
	public function handle_fee_rescan() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'lw16_fee_rescan_' . $order_id );

		$booked = $this->capture_order_fee( $order_id );

		$msg = $booked
			? __( 'Zahlungsgebühr gefunden und gebucht.', 'lindenweg16' )
			: __( 'Keine neue Zahlungsgebühr gefunden – entweder steht keine in den Hinweisen, oder sie ist bereits gebucht.', 'lindenweg16' );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'lw16-buchhaltung', 'view' => 'journal', 'msg' => rawurlencode( $msg ) ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Rechnung asynchron erzeugen (Action-Scheduler-Callback).
	 *
	 * @param int $order_id Bestell-ID.
	 */
	public function generate_invoice( $order_id ) {
		InvoiceGenerator::generate_for_order( (int) $order_id );
	}

	/* --------------------------------------------------------------------
	 * Admin: Belege & EÜR
	 * ------------------------------------------------------------------ */

	/**
	 * Buchhaltungs-Admin-Seite rendern.
	 */
	public function render_admin() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'belege'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Buchhaltung', 'lindenweg16' ) . '</h1>';

		$base = admin_url( 'admin.php?page=lw16-buchhaltung' );
		echo '<nav class="lw16-tabs">';
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'dashboard', $base ) ), 'dashboard' === $view ? 'active' : '', esc_html__( 'Dashboard', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( $base ), 'belege' === $view ? 'active' : '', esc_html__( 'Belege', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'neu', $base ) ), 'neu' === $view ? 'active' : '', esc_html__( 'Beleg erfassen', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'rechnungen', $base ) ), 'rechnungen' === $view ? 'active' : '', esc_html__( 'Rechnungen', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'journal', $base ) ), 'journal' === $view ? 'active' : '', esc_html__( 'Journal', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'kassenbuch', $base ) ), 'kassenbuch' === $view ? 'active' : '', esc_html__( 'Kassenbuch', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'euer', $base ) ), 'euer' === $view ? 'active' : '', esc_html__( 'EÜR', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'dauerbuchungen', $base ) ), 'dauerbuchungen' === $view ? 'active' : '', esc_html__( 'Dauerbuchungen', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'anlagen', $base ) ), 'anlagen' === $view ? 'active' : '', esc_html__( 'Anlagen', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'erechnung', $base ) ), 'erechnung' === $view ? 'active' : '', esc_html__( 'E-Rechnung', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'bp_export', $base ) ), 'bp_export' === $view ? 'active' : '', esc_html__( 'Betriebsprüfung', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a>', esc_url( add_query_arg( 'view', 'gobd', $base ) ), 'gobd' === $view ? 'active' : '', esc_html__( 'GoBD-Prüfung', 'lindenweg16' ) );
		echo '</nav>';

		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}

		switch ( $view ) {
			case 'dashboard':
				$dj = isset( $_GET['dj'] ) ? (int) $_GET['dj'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
				AccountingDashboard::render( $dj );
				break;
			case 'neu':
				$this->render_beleg_form();
				break;
			case 'rechnungen':
				$this->render_rechnungen_list();
				break;
			case 'journal':
				$this->render_journal();
				break;
			case 'kassenbuch':
				$this->render_kassenbuch();
				break;
			case 'euer':
				$this->render_euer();
				break;
			case 'dauerbuchungen':
				$this->render_dauerbuchungen();
				break;
			case 'anlagen':
				$this->render_anlagen();
				break;
			case 'erechnung':
				$this->render_erechnung();
				break;
			case 'bp_export':
				$this->render_bp_export();
				break;
			case 'gobd':
				$this->render_gobd();
				break;
			default:
				$this->render_belege_list();
				break;
		}
		echo '</div>';
	}

	/**
	 * Belegliste (paginiert) mit Festschreibungs-Aktion.
	 */
	protected function render_belege_list() {
		$per_page = 50;
		$page     = isset( $_GET['bp'] ) ? max( 1, (int) $_GET['bp'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$total    = BelegRepository::count();
		$belege   = BelegRepository::query( array( 'per_page' => $per_page, 'page' => $page ) );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Nr.', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Händler', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Kategorie', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Betrag', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Quelle', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Foto', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $belege as $b ) {
			$is_storniert = isset( $b->storniert ) && (int) $b->storniert === 1;
			echo '<tr' . ( $is_storniert ? ' style="opacity:0.5"' : '' ) . '>';
			echo '<td>' . esc_html( isset( $b->belegnummer ) ? $b->belegnummer : '#' . $b->id ) . '</td>';
			echo '<td>' . esc_html( $b->datum ) . '</td>';
			echo '<td>' . esc_html( (string) $b->haendler ) . '</td>';
			echo '<td>' . esc_html( (string) $b->eur_kategorie ) . '</td>';
			echo '<td>' . esc_html( self::euro( $b->betrag ) ) . '</td>';
			$quelle_label = $b->quelle;
			if ( 'wareneingang' === $b->quelle ) {
				$quelle_label = '<span title="' . esc_attr__( 'automatisch aus Wareneingang übernommen', 'lindenweg16' ) . '">' . esc_html__( 'Wareneingang', 'lindenweg16' ) . '</span>';
			} elseif ( 'erechnung' === $b->quelle ) {
				$quelle_label = esc_html__( 'E-Rechnung', 'lindenweg16' );
			} elseif ( 'storno' === $b->quelle ) {
				$quelle_label = esc_html__( 'Storno', 'lindenweg16' );
			} else {
				$quelle_label = esc_html__( 'manuell', 'lindenweg16' );
			}
			echo '<td>' . $quelle_label . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $b->datei_pfad ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_beleg_datei&id=' . $b->id ), 'lw16_beleg_datei_' . $b->id );
				echo '<td><a href="' . esc_url( $url ) . '" target="_blank">' . esc_html__( 'ansehen', 'lindenweg16' ) . '</a></td>';
			} else {
				echo '<td>–</td>';
			}
			if ( $is_storniert ) {
				echo '<td><span class="lw16-storno">' . esc_html__( 'storniert', 'lindenweg16' ) . '</span></td><td></td>';
			} elseif ( (int) $b->festgeschrieben === 1 ) {
				echo '<td><span class="lw16-locked">' . esc_html__( 'festgeschrieben', 'lindenweg16' ) . '</span></td>';
				if ( 'storno' === $b->quelle ) {
					echo '<td></td>'; // Storno-Belege können nicht nochmal storniert werden.
				} else {
					$st = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_beleg_storno&id=' . $b->id ), 'lw16_beleg_storno_' . $b->id );
					echo '<td><a class="button button-small" href="' . esc_url( $st ) . '" onclick="return confirm(\'' . esc_attr__( 'Beleg wirklich stornieren?', 'lindenweg16' ) . '\')">' . esc_html__( 'Stornieren', 'lindenweg16' ) . '</a></td>';
				}
			} else {
				echo '<td>' . esc_html__( 'offen', 'lindenweg16' ) . '</td>';
				$fs = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_festschreiben&id=' . $b->id ), 'lw16_festschreiben_' . $b->id );
				echo '<td><a class="button button-small" href="' . esc_url( $fs ) . '" onclick="return confirm(\'' . esc_attr__( 'Beleg festschreiben? Danach nicht mehr änderbar.', 'lindenweg16' ) . '\')">' . esc_html__( 'Festschreiben', 'lindenweg16' ) . '</a></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Pagination.
		$total_pages = (int) ceil( $total / $per_page );
		if ( $total_pages > 1 ) {
			$base = admin_url( 'admin.php?page=lw16-buchhaltung' );
			echo '<div class="lw16-pagination">';
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				if ( $i === $page ) {
					echo '<span class="current">' . (int) $i . '</span> ';
				} else {
					echo '<a href="' . esc_url( add_query_arg( 'bp', $i, $base ) ) . '">' . (int) $i . '</a> ';
				}
			}
			echo '</div>';
		}
	}

	/**
	 * Formular zur manuellen Belegerfassung.
	 */
	protected function render_beleg_form() {
		$ocr_available = BelegOcr::is_available();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="lw16-beleg-form">
			<input type="hidden" name="action" value="lw16_save_beleg">
			<?php wp_nonce_field( 'lw16_save_beleg' ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Beleg (Foto oder PDF)', 'lindenweg16' ); ?></th><td>
					<input type="file" name="beleg_foto" accept="image/*,application/pdf" id="lw16-beleg-foto">
					<button type="button" class="button" id="lw16-ocr-btn" style="margin-left:8px" disabled>
						<?php esc_html_e( 'Beleg auslesen', 'lindenweg16' ); ?>
					</button>
					<span id="lw16-ocr-status" style="margin-left:8px;color:#6E7882"></span>
					<p class="description">
						<?php
						echo esc_html(
							$ocr_available
								? __( 'PDFs mit Textschicht werden exakt gelesen, Fotos und Scans über die Zeichenerkennung.', 'lindenweg16' )
								: __( 'PDFs mit Textschicht werden exakt gelesen – das sind die meisten Lieferantenrechnungen. Für Fotos und Scans wäre Tesseract OCR auf dem Server nötig (apt install tesseract-ocr tesseract-ocr-deu).', 'lindenweg16' )
						);
						?>
					</p>
				</td></tr>
				<tr><th><?php esc_html_e( 'Betrag (€)', 'lindenweg16' ); ?></th><td><input type="number" step="0.01" name="betrag" id="lw16-betrag" class="small-text" required></td></tr>
				<tr><th><?php esc_html_e( 'Datum', 'lindenweg16' ); ?></th><td><input type="date" name="datum" id="lw16-datum" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Händler', 'lindenweg16' ); ?></th><td><input type="text" name="haendler" id="lw16-haendler" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'EÜR-Kategorie', 'lindenweg16' ); ?></th><td>
					<select name="eur_kategorie">
						<?php foreach ( self::eur_kategorien() as $k ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $k ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'Notiz', 'lindenweg16' ); ?></th><td><textarea name="notiz" class="large-text" rows="2"></textarea></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Beleg speichern', 'lindenweg16' ) ); ?>
		</form>
		<?php // Das Skript laeuft immer: PDFs kommen auch ohne Tesseract durch. ?>
		<script>
		(function(){
			var fileInput = document.getElementById('lw16-beleg-foto');
			var ocrBtn = document.getElementById('lw16-ocr-btn');
			var status = document.getElementById('lw16-ocr-status');

			fileInput.addEventListener('change', function(){
				ocrBtn.disabled = !fileInput.files.length;
			});

			ocrBtn.addEventListener('click', function(){
				if (!fileInput.files.length) return;
				ocrBtn.disabled = true;
				status.textContent = '<?php echo esc_js( __( 'Wird analysiert…', 'lindenweg16' ) ); ?>';

				var fd = new FormData();
				fd.append('action', 'lw16_ocr_scan');
				fd.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'lw16_ocr_scan' ) ); ?>');
				fd.append('beleg_foto', fileInput.files[0]);

				fetch(ajaxurl, {method:'POST', body:fd})
					.then(function(r){return r.json()})
					.then(function(d){
						if (d.success && d.data) {
							if (d.data.betrag) document.getElementById('lw16-betrag').value = d.data.betrag;
							if (d.data.datum) document.getElementById('lw16-datum').value = d.data.datum;
							if (d.data.haendler) document.getElementById('lw16-haendler').value = d.data.haendler;
							status.textContent = '<?php echo esc_js( __( 'Felder vorausgefüllt – bitte prüfen.', 'lindenweg16' ) ); ?>';
							status.style.color = '#4CAF50';
						} else {
							status.textContent = d.data && d.data.error ? d.data.error : '<?php echo esc_js( __( 'Keine Daten erkannt.', 'lindenweg16' ) ); ?>';
							status.style.color = '#F44336';
						}
						ocrBtn.disabled = false;
					})
					.catch(function(){
						status.textContent = '<?php echo esc_js( __( 'Fehler bei der Verarbeitung.', 'lindenweg16' ) ); ?>';
						status.style.color = '#F44336';
						ocrBtn.disabled = false;
					});
			});
		})();
		</script>
		<?php
	}

	/**
	 * EÜR-Ansicht mit Zeitraumauswahl.
	 */
	protected function render_euer() {
		$jahr = (int) gmdate( 'Y' );
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : $jahr . '-01-01'; // phpcs:ignore WordPress.Security.NonceVerification
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : $jahr . '-12-31'; // phpcs:ignore WordPress.Security.NonceVerification

		$rep = EuerCalculator::report( $from, $to );

		echo '<form method="get" class="lw16-euer-filter">';
		echo '<input type="hidden" name="page" value="lw16-buchhaltung"><input type="hidden" name="view" value="euer">';
		echo '<label>' . esc_html__( 'Von', 'lindenweg16' ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '"></label> ';
		echo '<label>' . esc_html__( 'Bis', 'lindenweg16' ) . ' <input type="date" name="to" value="' . esc_attr( $to ) . '"></label> ';
		submit_button( __( 'Berechnen', 'lindenweg16' ), 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped" style="max-width:640px">';
		echo '<tr><th>' . esc_html__( 'Einnahmen (WooCommerce, abzgl. Refunds)', 'lindenweg16' ) . '</th><td>' . esc_html( self::euro( $rep['einnahmen'] ) ) . '</td></tr>';
		echo '<tr><th colspan="2">' . esc_html__( 'Ausgaben nach EÜR-Kategorie', 'lindenweg16' ) . '</th></tr>';
		foreach ( $rep['ausgaben_kategorien'] as $kat => $summe ) {
			echo '<tr><td style="padding-left:2em">' . esc_html( $kat ) . '</td><td>' . esc_html( self::euro( $summe ) ) . '</td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Ausgaben gesamt', 'lindenweg16' ) . '</th><td>' . esc_html( self::euro( $rep['ausgaben_summe'] ) ) . '</td></tr>';
		echo '<tr class="lw16-euer-result"><th>' . esc_html__( 'Gewinn', 'lindenweg16' ) . '</th><td><strong>' . esc_html( self::euro( $rep['gewinn'] ) ) . '</strong></td></tr>';
		echo '</table>';

		if ( (int) Settings::get( 'kleinunternehmer', 1 ) === 1 ) {
			echo '<p class="description">' . esc_html__( 'Kleinunternehmer §19 UStG: keine Umsatzsteuer ausgewiesen, alle Beträge brutto.', 'lindenweg16' ) . '</p>';
		}
		$pdf_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=lw16_euer_pdf&from=' . rawurlencode( $from ) . '&to=' . rawurlencode( $to ) ),
			'lw16_euer_pdf'
		);
		echo '<p><a class="button button-primary" href="' . esc_url( $pdf_url ) . '">' . esc_html__( 'EÜR als PDF exportieren (inkl. Belege)', 'lindenweg16' ) . '</a></p>';
	}

	/* --------------------------------------------------------------------
	 * POST-Handler
	 * ------------------------------------------------------------------ */

	/**
	 * Manuellen Beleg speichern.
	 */
	public function handle_save_beleg() {
		$this->guard( 'lw16_save_beleg' );

		$datei_pfad = '';
		if ( ! empty( $_FILES['beleg_foto']['name'] ) ) {
			$datei_pfad = BelegRepository::store_upload( $_FILES['beleg_foto'] );
		}

		BelegRepository::create(
			array(
				'datei_pfad'    => $datei_pfad,
				'betrag'        => (float) ( $_POST['betrag'] ?? 0 ),
				'datum'         => sanitize_text_field( wp_unslash( $_POST['datum'] ?? gmdate( 'Y-m-d' ) ) ),
				'haendler'      => sanitize_text_field( wp_unslash( $_POST['haendler'] ?? '' ) ),
				'eur_kategorie' => sanitize_text_field( wp_unslash( $_POST['eur_kategorie'] ?? '' ) ),
				'notiz'         => sanitize_textarea_field( wp_unslash( $_POST['notiz'] ?? '' ) ),
				'quelle'        => 'manuell',
			)
		);

		$this->redirect( __( 'Beleg gespeichert.', 'lindenweg16' ) );
	}

	/**
	 * Beleg festschreiben.
	 */
	public function handle_festschreiben() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_festschreiben_' . $id );
		BelegRepository::festschreiben( $id );
		$this->redirect( __( 'Beleg festgeschrieben.', 'lindenweg16' ) );
	}

	/* --------------------------------------------------------------------
	 * Geschützte Datei-Auslieferung
	 * ------------------------------------------------------------------ */

	/**
	 * Beleg-Foto nur für berechtigte Nutzer ausliefern.
	 */
	public function serve_beleg_file() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_beleg_datei_' . $id );

		$beleg = BelegRepository::get( $id );
		if ( ! $beleg || empty( $beleg->datei_pfad ) ) {
			wp_die( esc_html__( 'Beleg nicht gefunden.', 'lindenweg16' ) );
		}

		$path = BelegRepository::file_path( $beleg->datei_pfad );
		// Path-Traversal-Schutz.
		$real_base = realpath( \Lindenweg16\Core\Installer::belege_dir() );
		$real_file = realpath( $path );
		if ( ! $real_file || ! $real_base || strpos( $real_file, $real_base ) !== 0 || ! is_file( $real_file ) ) {
			wp_die( esc_html__( 'Datei nicht verfügbar.', 'lindenweg16' ) );
		}

		$mime = wp_check_filetype( $real_file );
		header( 'Content-Type: ' . ( $mime['type'] ? $mime['type'] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $real_file ) );
		header( 'Content-Disposition: inline; filename="' . basename( $real_file ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $real_file ); // phpcs:ignore
		exit;
	}

	/* --------------------------------------------------------------------
	 * Rechnungen & EÜR-Export
	 * ------------------------------------------------------------------ */

	/**
	 * EÜR als PDF exportieren (inkl. Beleg-Fotos als Anhang).
	 */
	public function export_euer_pdf() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_euer_pdf' );

		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y' ) . '-01-01';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : gmdate( 'Y' ) . '-12-31';
		$notiz = isset( $_GET['notiz'] ) ? sanitize_text_field( wp_unslash( $_GET['notiz'] ) ) : '';

		$rep = EuerCalculator::report( $from, $to );
		$pdf = new \Lindenweg16\Core\Pdf();
		$pdf->enable_ci();

		$pdf->set_color( \Lindenweg16\Core\Pdf::COLOR_TINTE );
		$pdf->text( __( 'Einnahmen-Überschuss-Rechnung', 'lindenweg16' ), 22, true );
		$pdf->set_color( '#6E7882' );
		$pdf->text( __( 'Zeitraum:', 'lindenweg16' ) . ' ' . $from . ' – ' . $to, 11 );
		$pdf->set_color( \Lindenweg16\Core\Pdf::COLOR_TINTE );
		$pdf->space( 8 );

		$pdf->set_draw_color( \Lindenweg16\Core\Pdf::COLOR_TINTE );
		$pdf->hr( 1.5 );

		$pdf->set_color( \Lindenweg16\Core\Pdf::COLOR_COGNAC );
		$pdf->text( mb_strtoupper( __( 'Einnahmen', 'lindenweg16' ) ), 8, true );
		$pdf->set_color( \Lindenweg16\Core\Pdf::COLOR_TINTE );
		$pdf->row( __( 'WooCommerce (abzgl. Refunds)', 'lindenweg16' ), self::euro( $rep['einnahmen'] ), 11, true );
		$pdf->space( 8 );

		$pdf->set_color( \Lindenweg16\Core\Pdf::COLOR_COGNAC );
		$pdf->text( mb_strtoupper( __( 'Ausgaben nach Kategorie', 'lindenweg16' ) ), 8, true );
		$pdf->set_color( \Lindenweg16\Core\Pdf::COLOR_TINTE );
		foreach ( $rep['ausgaben_kategorien'] as $kat => $summe ) {
			$pdf->row( $kat, self::euro( $summe ), 11, false );
		}

		$pdf->set_draw_color( '#E0E0E0' );
		$pdf->hr( 0.3 );
		$pdf->row( __( 'Ausgaben gesamt', 'lindenweg16' ), self::euro( $rep['ausgaben_summe'] ), 11, true );
		$pdf->space( 4 );

		$pdf->set_draw_color( \Lindenweg16\Core\Pdf::COLOR_TINTE );
		$pdf->hr( 0.8 );
		$pdf->set_color( \Lindenweg16\Core\Pdf::COLOR_COGNAC );
		$pdf->row( __( 'Gewinn', 'lindenweg16' ), self::euro( $rep['gewinn'] ), 14, true );
		$pdf->set_color( \Lindenweg16\Core\Pdf::COLOR_TINTE );

		if ( (int) Settings::get( 'kleinunternehmer', 1 ) === 1 ) {
			$pdf->space( 8 );
			$pdf->set_color( '#6E7882' );
			$pdf->text( __( 'Kleinunternehmer §19 UStG: keine Umsatzsteuer, Beträge brutto.', 'lindenweg16' ), 9 );
			$pdf->set_color( \Lindenweg16\Core\Pdf::COLOR_TINTE );
		}
		if ( $notiz ) {
			$pdf->space( 6 );
			$pdf->set_color( '#6E7882' );
			$pdf->text( __( 'Notiz: ', 'lindenweg16' ) . $notiz, 10 );
			$pdf->set_color( \Lindenweg16\Core\Pdf::COLOR_TINTE );
		}

		// Beleg-Fotos als Anhang (nur JPEG einbettbar).
		$belege = BelegRepository::query( array( 'from' => $from, 'to' => $to, 'per_page' => 500, 'page' => 1 ) );
		foreach ( $belege as $b ) {
			if ( empty( $b->datei_pfad ) ) {
				continue;
			}
			$path = BelegRepository::file_path( $b->datei_pfad );
			$pdf->add_page();
			$pdf->text( __( 'Beleg', 'lindenweg16' ) . ' #' . $b->id . ' – ' . $b->datum . ' – ' . self::euro( $b->betrag ) . ' – ' . $b->haendler, 11, true );
			$pdf->space( 6 );
			$pdf->image_jpeg( $path, 480, 640 );
		}

		$content = $pdf->output();
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="euer-' . $from . '_' . $to . '.pdf"' );
		header( 'Content-Length: ' . strlen( $content ) );
		echo $content; // phpcs:ignore
		exit;
	}

	/**
	 * Rechnung ausliefern – für Manager oder den Bestell-Eigentümer.
	 */
	public function serve_invoice() {
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_rechnung_' . $id );

		$rechnung = InvoiceGenerator::get( $id );
		if ( ! $rechnung || empty( $rechnung->datei_pfad ) ) {
			wp_die( esc_html__( 'Rechnung nicht gefunden.', 'lindenweg16' ) );
		}

		// Berechtigung: Manager ODER Eigentümer der Bestellung.
		$erlaubt = current_user_can( LW16_CAPABILITY );
		if ( ! $erlaubt ) {
			$order = wc_get_order( $rechnung->order_id );
			if ( $order && get_current_user_id() && (int) $order->get_customer_id() === get_current_user_id() ) {
				$erlaubt = true;
			}
		}
		if ( ! $erlaubt ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}

		$path      = trailingslashit( \Lindenweg16\Core\Installer::docs_dir() ) . $rechnung->datei_pfad;
		$real_base = realpath( \Lindenweg16\Core\Installer::docs_dir() );
		$real_file = realpath( $path );
		if ( ! $real_file || ! $real_base || strpos( $real_file, $real_base ) !== 0 || ! is_file( $real_file ) ) {
			wp_die( esc_html__( 'Datei nicht verfügbar.', 'lindenweg16' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . filesize( $real_file ) );
		header( 'Content-Disposition: inline; filename="rechnung-' . $rechnung->rechnungsnummer . '.pdf"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $real_file ); // phpcs:ignore
		exit;
	}

	/**
	 * Rechnungs-Link in der Admin-Bestellansicht.
	 *
	 * @param \WC_Order $order Bestellung.
	 */
	public function admin_order_invoice_link( $order ) {
		$rechnung = InvoiceGenerator::get_by_order( $order->get_id() );
		if ( ! $rechnung ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_rechnung&id=' . $rechnung->id ), 'lw16_rechnung_' . $rechnung->id );
		echo '<p class="form-field form-field-wide"><strong>' . esc_html__( 'Lindenweg16-Rechnung', 'lindenweg16' ) . ':</strong> ';
		echo '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $rechnung->rechnungsnummer ) . '</a></p>';
	}

	/**
	 * Rechnungs-Aktion in der Bestellliste des Kundenkontos.
	 *
	 * @param array     $actions Aktionen.
	 * @param \WC_Order $order   Bestellung.
	 * @return array
	 */
	public function account_order_invoice_action( $actions, $order ) {
		$rechnung = InvoiceGenerator::get_by_order( $order->get_id() );
		if ( $rechnung ) {
			$actions['lw16_invoice'] = array(
				'url'  => wp_nonce_url( admin_url( 'admin-post.php?action=lw16_rechnung&id=' . $rechnung->id ), 'lw16_rechnung_' . $rechnung->id ),
				'name' => __( 'Rechnung', 'lindenweg16' ),
			);
		}
		return $actions;
	}

	/* --------------------------------------------------------------------
	 * Rechnungsliste
	 * ------------------------------------------------------------------ */

	/**
	 * Rechnungsliste (paginiert) rendern.
	 */
	protected function render_rechnungen_list() {
		$per_page = 50;
		$page     = isset( $_GET['rp'] ) ? max( 1, (int) $_GET['rp'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$total    = InvoiceGenerator::count();
		$rechnungen = InvoiceGenerator::query( array( 'per_page' => $per_page, 'page' => $page ) );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Rechnungsnr.', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Bestellung', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Betrag', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Erstellt', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $rechnungen as $r ) {
			$is_storniert = isset( $r->storniert ) && (int) $r->storniert === 1;
			echo '<tr' . ( $is_storniert ? ' style="opacity:0.5"' : '' ) . '>';
			echo '<td>';
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_rechnung&id=' . $r->id ), 'lw16_rechnung_' . $r->id );
			echo '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $r->rechnungsnummer ) . '</a>';
			echo '</td>';
			echo '<td>#' . esc_html( $r->order_id ) . '</td>';
			echo '<td>' . esc_html( self::euro( $r->betrag ) ) . '</td>';
			echo '<td>' . esc_html( $r->erstellt_am ) . '</td>';
			if ( $is_storniert ) {
				echo '<td><span class="lw16-storno">' . esc_html__( 'storniert', 'lindenweg16' ) . '</span></td><td></td>';
			} else {
				echo '<td>' . esc_html__( 'aktiv', 'lindenweg16' ) . '</td>';
				$st = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_rechnung_storno&id=' . $r->id ), 'lw16_rechnung_storno_' . $r->id );
				echo '<td><a class="button button-small" href="' . esc_url( $st ) . '" onclick="return confirm(\'' . esc_attr__( 'Rechnung wirklich stornieren?', 'lindenweg16' ) . '\')">' . esc_html__( 'Stornieren', 'lindenweg16' ) . '</a></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		$total_pages = (int) ceil( $total / $per_page );
		if ( $total_pages > 1 ) {
			$base = add_query_arg( 'view', 'rechnungen', admin_url( 'admin.php?page=lw16-buchhaltung' ) );
			echo '<div class="lw16-pagination">';
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				if ( $i === $page ) {
					echo '<span class="current">' . (int) $i . '</span> ';
				} else {
					echo '<a href="' . esc_url( add_query_arg( 'rp', $i, $base ) ) . '">' . (int) $i . '</a> ';
				}
			}
			echo '</div>';
		}
	}

	/* --------------------------------------------------------------------
	 * Buchungsjournal
	 * ------------------------------------------------------------------ */

	/**
	 * Buchungsjournal rendern.
	 */
	protected function render_journal() {
		$per_page = 50;
		$page     = isset( $_GET['jp'] ) ? max( 1, (int) $_GET['jp'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$total    = BuchungRepository::count();
		$buchungen = BuchungRepository::query( array( 'per_page' => $per_page, 'page' => $page ) );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Nr.', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Typ', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Betrag', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Kategorie', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Beschreibung', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $buchungen as $bu ) {
			$is_storniert = (int) $bu->storniert === 1;
			echo '<tr' . ( $is_storniert ? ' style="opacity:0.5"' : '' ) . '>';
			echo '<td>' . esc_html( $bu->buchungsnummer ) . '</td>';
			echo '<td>' . esc_html( $bu->buchungsdatum ) . '</td>';
			echo '<td>' . esc_html( $bu->typ ) . '</td>';
			echo '<td>' . esc_html( self::euro( $bu->betrag ) ) . '</td>';
			echo '<td>' . esc_html( $bu->kategorie ) . '</td>';
			echo '<td>' . esc_html( mb_substr( (string) $bu->beschreibung, 0, 80 ) ) . '</td>';
			echo '<td>' . ( $is_storniert ? esc_html__( 'storniert', 'lindenweg16' ) : esc_html__( 'aktiv', 'lindenweg16' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$total_pages = (int) ceil( $total / $per_page );
		if ( $total_pages > 1 ) {
			$base = add_query_arg( 'view', 'journal', admin_url( 'admin.php?page=lw16-buchhaltung' ) );
			echo '<div class="lw16-pagination">';
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				if ( $i === $page ) {
					echo '<span class="current">' . (int) $i . '</span> ';
				} else {
					echo '<a href="' . esc_url( add_query_arg( 'jp', $i, $base ) ) . '">' . (int) $i . '</a> ';
				}
			}
			echo '</div>';
		}
	}

	/* --------------------------------------------------------------------
	 * E-Rechnungsempfang
	 * ------------------------------------------------------------------ */

	/**
	 * E-Rechnungen-Liste und Upload-Formular.
	 */
	protected function render_erechnung() {
		echo '<h2>' . esc_html__( 'E-Rechnung importieren', 'lindenweg16' ) . '</h2>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="lw16_erechnung_upload">
			<?php wp_nonce_field( 'lw16_erechnung_upload' ); ?>
			<p>
				<label><?php esc_html_e( 'XML- oder PDF-Datei:', 'lindenweg16' ); ?>
				<input type="file" name="erechnung" accept=".xml,.pdf" required></label>
				<?php submit_button( __( 'Hochladen', 'lindenweg16' ), 'secondary', '', false ); ?>
			</p>
		</form>
		<?php

		$erechnungen = ERechnungImporter::get_list();
		if ( $erechnungen ) {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Absender', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Re-Nr.', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Betrag', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Format', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th><th></th>';
			echo '</tr></thead><tbody>';
			foreach ( $erechnungen as $er ) {
				echo '<tr>';
				echo '<td>' . esc_html( $er->datum ) . '</td>';
				echo '<td>' . esc_html( $er->absender_name ) . '</td>';
				echo '<td>' . esc_html( $er->rechnungsnummer_ext ) . '</td>';
				echo '<td>' . esc_html( self::euro( $er->betrag ) ) . '</td>';
				echo '<td>' . esc_html( $er->format ) . '</td>';
				echo '<td>' . esc_html( $er->status ) . '</td>';
				if ( 'empfangen' === $er->status ) {
					$url = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_erechnung_beleg&id=' . $er->id ), 'lw16_erechnung_beleg_' . $er->id );
					echo '<td><a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Als Beleg übernehmen', 'lindenweg16' ) . '</a></td>';
				} else {
					echo '<td>' . esc_html__( 'verarbeitet', 'lindenweg16' ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/* --------------------------------------------------------------------
	 * Betriebsprüfungs-Export
	 * ------------------------------------------------------------------ */

	/**
	 * Betriebsprüfungs-Export-Seite.
	 */
	protected function render_bp_export() {
		echo '<h2>' . esc_html__( 'Export für Betriebsprüfung', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Strukturierter Datenexport gemäß §147 Abs. 6 AO (Z3: Datenträgerüberlassung). Enthält alle Belege, Rechnungen, Buchungen, Audit-Log und EÜR-Zusammenfassung als CSV-Dateien mit maschinenlesbarer Strukturbeschreibung (INDEX.xml).', 'lindenweg16' ) . '</p>';
		$jahr = (int) gmdate( 'Y' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_bp_export">
			<?php wp_nonce_field( 'lw16_bp_export' ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Von', 'lindenweg16' ); ?></th><td><input type="date" name="from" value="<?php echo esc_attr( $jahr . '-01-01' ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Bis', 'lindenweg16' ); ?></th><td><input type="date" name="to" value="<?php echo esc_attr( $jahr . '-12-31' ); ?>"></td></tr>
			</tbody></table>
			<?php submit_button( __( 'ZIP-Export herunterladen', 'lindenweg16' ) ); ?>
		</form>
		<?php
	}

	/* --------------------------------------------------------------------
	 * GoBD-Prüfung
	 * ------------------------------------------------------------------ */

	/**
	 * GoBD-Prüfung rendern.
	 */
	protected function render_gobd() {
		echo '<h2>' . esc_html__( 'GoBD-Integritätsprüfung', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Prüft Hash-Ketten, Nummern-Lückenlosigkeit und zeitgerechte Erfassung. Empfehlung: mindestens jährlich vor dem Jahresabschluss durchführen.', 'lindenweg16' ) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_gobd_check">
			<?php wp_nonce_field( 'lw16_gobd_check' ); ?>
			<p>
				<label><?php esc_html_e( 'Jahr:', 'lindenweg16' ); ?>
				<input type="number" name="jahr" value="<?php echo esc_attr( gmdate( 'Y' ) ); ?>" min="2020" max="2099" class="small-text"></label>
				<?php submit_button( __( 'Prüfung starten', 'lindenweg16' ), 'primary', '', false ); ?>
			</p>
		</form>
		<?php
		$doku_url = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_verfahrensdoku' ), 'lw16_verfahrensdoku' );
		echo '<p><a class="button" href="' . esc_url( $doku_url ) . '">' . esc_html__( 'Verfahrensdokumentation als PDF', 'lindenweg16' ) . '</a></p>';

		echo '<hr>';

		// Letzte Prüfergebnisse anzeigen.
		$results = GobdChecker::get_results( 5 );
		if ( $results ) {
			echo '<h3>' . esc_html__( 'Letzte Prüfungen', 'lindenweg16' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Jahr', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Ergebnis', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Fehler', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Warnungen', 'lindenweg16' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $results as $res ) {
				$status_class = 'ok' === $res->ergebnis ? 'lw16-ok' : ( 'fehler' === $res->ergebnis ? 'lw16-error' : 'lw16-warn' );
				echo '<tr>';
				echo '<td>' . esc_html( $res->pruef_datum ) . '</td>';
				echo '<td>' . esc_html( $res->jahr ) . '</td>';
				echo '<td><span class="' . esc_attr( $status_class ) . '">' . esc_html( strtoupper( $res->ergebnis ) ) . '</span></td>';
				echo '<td>' . esc_html( $res->fehler_anzahl ) . '</td>';
				echo '<td>' . esc_html( $res->warn_anzahl ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/* --------------------------------------------------------------------
	 * Neue POST-Handler
	 * ------------------------------------------------------------------ */

	/**
	 * Beleg stornieren.
	 */
	public function handle_beleg_storno() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_beleg_storno_' . $id );
		BelegRepository::stornieren( $id, __( 'Manuelle Stornierung', 'lindenweg16' ) );
		$this->redirect( __( 'Beleg storniert.', 'lindenweg16' ) );
	}

	/**
	 * Rechnung stornieren.
	 */
	public function handle_rechnung_storno() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_rechnung_storno_' . $id );
		InvoiceGenerator::stornieren( $id, __( 'Manuelle Stornierung', 'lindenweg16' ) );
		$this->redirect( __( 'Rechnung storniert.', 'lindenweg16' ), 'rechnungen' );
	}

	/**
	 * E-Rechnung hochladen.
	 */
	public function handle_erechnung_upload() {
		$this->guard( 'lw16_erechnung_upload' );
		if ( empty( $_FILES['erechnung']['name'] ) ) {
			$this->redirect( __( 'Keine Datei ausgewählt.', 'lindenweg16' ), 'erechnung' );
		}
		$parsed = ERechnungImporter::process_upload( $_FILES['erechnung'] );
		if ( ! $parsed ) {
			$this->redirect( __( 'Datei konnte nicht verarbeitet werden.', 'lindenweg16' ), 'erechnung' );
		}
		ERechnungImporter::save( $parsed );
		$this->redirect( __( 'E-Rechnung importiert.', 'lindenweg16' ), 'erechnung' );
	}

	/**
	 * E-Rechnung als Beleg übernehmen.
	 */
	public function handle_erechnung_beleg() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_erechnung_beleg_' . $id );
		ERechnungImporter::create_beleg( $id );
		$this->redirect( __( 'E-Rechnung als Beleg übernommen.', 'lindenweg16' ), 'erechnung' );
	}

	/**
	 * Betriebsprüfungs-Export.
	 */
	public function handle_bp_export() {
		$this->guard( 'lw16_bp_export' );
		$from = sanitize_text_field( wp_unslash( $_POST['from'] ?? gmdate( 'Y' ) . '-01-01' ) );
		$to   = sanitize_text_field( wp_unslash( $_POST['to'] ?? gmdate( 'Y' ) . '-12-31' ) );
		ExportGenerator::download( $from, $to );
	}

	/**
	 * GoBD-Prüfung durchführen.
	 */
	public function handle_gobd_check() {
		$this->guard( 'lw16_gobd_check' );
		$jahr     = (int) ( $_POST['jahr'] ?? gmdate( 'Y' ) );
		$ergebnis = GobdChecker::run( $jahr );
		$msg = sprintf(
			/* translators: %1$d year, %2$s status */
			__( 'GoBD-Prüfung %1$d: %2$s (%3$d Fehler, %4$d Warnungen)', 'lindenweg16' ),
			$jahr,
			strtoupper( $ergebnis['status'] ),
			$ergebnis['fehler'],
			$ergebnis['warnungen']
		);
		$this->redirect( $msg, 'gobd' );
	}

	/**
	 * Verfahrensdokumentation als PDF.
	 */
	public function handle_verfahrensdoku() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_verfahrensdoku' );
		VerfahrensDoku::download();
	}

	/* --------------------------------------------------------------------
	 * Kassenbuch
	 * ------------------------------------------------------------------ */

	/**
	 * Kassenbuch rendern.
	 */
	protected function render_kassenbuch() {
		echo '<h2>' . esc_html__( 'Kassenbuch (Bargeschäfte)', 'lindenweg16' ) . '</h2>';

		// Neuen Eintrag erfassen.
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:24px">
			<input type="hidden" name="action" value="lw16_save_kassenbuch">
			<?php wp_nonce_field( 'lw16_save_kassenbuch' ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Datum', 'lindenweg16' ); ?></th><td><input type="date" name="datum" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required></td></tr>
				<tr><th><?php esc_html_e( 'Beschreibung', 'lindenweg16' ); ?></th><td><input type="text" name="beschreibung" class="regular-text" required></td></tr>
				<tr><th><?php esc_html_e( 'Einnahme (€)', 'lindenweg16' ); ?></th><td><input type="number" step="0.01" min="0" name="einnahme" value="0" class="small-text"></td></tr>
				<tr><th><?php esc_html_e( 'Ausgabe (€)', 'lindenweg16' ); ?></th><td><input type="number" step="0.01" min="0" name="ausgabe" value="0" class="small-text"></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Eintrag buchen', 'lindenweg16' ), 'secondary' ); ?>
		</form>
		<?php

		echo '<p><strong>' . esc_html__( 'Aktueller Kassensaldo:', 'lindenweg16' ) . '</strong> '
			. esc_html( self::euro( KassenbuchRepository::aktueller_saldo() ) ) . '</p>';

		// Einträge auflisten.
		$per_page = 50;
		$page     = isset( $_GET['kp'] ) ? max( 1, (int) $_GET['kp'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$total    = KassenbuchRepository::count();
		$eintraege = KassenbuchRepository::query( array( 'per_page' => $per_page, 'page' => $page ) );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Nr.', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Beschreibung', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Einnahme', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Ausgabe', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Saldo', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $eintraege as $e ) {
			$is_storniert = (int) $e->storniert === 1;
			echo '<tr' . ( $is_storniert ? ' style="opacity:0.5"' : '' ) . '>';
			echo '<td>' . esc_html( $e->kassenbuchnummer ) . '</td>';
			echo '<td>' . esc_html( $e->datum ) . '</td>';
			echo '<td>' . esc_html( mb_substr( $e->beschreibung, 0, 60 ) ) . '</td>';
			echo '<td style="text-align:right;color:#4CAF50">' . ( (float) $e->einnahme > 0 ? esc_html( self::euro( $e->einnahme ) ) : '–' ) . '</td>';
			echo '<td style="text-align:right;color:#F44336">' . ( (float) $e->ausgabe > 0 ? esc_html( self::euro( $e->ausgabe ) ) : '–' ) . '</td>';
			echo '<td style="text-align:right;font-weight:bold">' . esc_html( self::euro( $e->saldo ) ) . '</td>';
			if ( $is_storniert ) {
				echo '<td>' . esc_html__( 'storniert', 'lindenweg16' ) . '</td><td></td>';
			} else {
				echo '<td>' . esc_html__( 'aktiv', 'lindenweg16' ) . '</td>';
				$st = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_storno_kassenbuch&id=' . $e->id ), 'lw16_storno_kassenbuch_' . $e->id );
				echo '<td><a class="button button-small" href="' . esc_url( $st ) . '" onclick="return confirm(\'' . esc_attr__( 'Eintrag stornieren?', 'lindenweg16' ) . '\')">' . esc_html__( 'Storno', 'lindenweg16' ) . '</a></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		$total_pages = (int) ceil( $total / $per_page );
		if ( $total_pages > 1 ) {
			$pag_base = add_query_arg( 'view', 'kassenbuch', admin_url( 'admin.php?page=lw16-buchhaltung' ) );
			echo '<div class="lw16-pagination">';
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				if ( $i === $page ) {
					echo '<span class="current">' . (int) $i . '</span> ';
				} else {
					echo '<a href="' . esc_url( add_query_arg( 'kp', $i, $pag_base ) ) . '">' . (int) $i . '</a> ';
				}
			}
			echo '</div>';
		}
	}

	/* --------------------------------------------------------------------
	 * Dauerbuchungen
	 * ------------------------------------------------------------------ */

	/**
	 * Dauerbuchungen-Tab rendern.
	 */
	protected function render_dauerbuchungen() {
		echo '<h2>' . esc_html__( 'Wiederkehrende Buchungen', 'lindenweg16' ) . '</h2>';

		$faellig = DauerbuchungRepository::count_due();
		if ( $faellig > 0 ) {
			$exec_url = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_execute_dauerbuchungen' ), 'lw16_execute_dauerbuchungen' );
			echo '<div class="notice notice-warning"><p>';
			echo esc_html( sprintf(
				/* translators: %d number of due bookings */
				__( '%d Dauerbuchung(en) fällig.', 'lindenweg16' ),
				$faellig
			) );
			echo ' <a class="button button-small" href="' . esc_url( $exec_url ) . '">' . esc_html__( 'Jetzt ausführen', 'lindenweg16' ) . '</a>';
			echo '</p></div>';
		}

		// Formular für neue Dauerbuchung.
		?>
		<h3><?php esc_html_e( 'Neue Dauerbuchung anlegen', 'lindenweg16' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_save_dauerbuchung">
			<?php wp_nonce_field( 'lw16_save_dauerbuchung' ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Bezeichnung', 'lindenweg16' ); ?></th><td><input type="text" name="bezeichnung" class="regular-text" required placeholder="<?php esc_attr_e( 'z. B. Büromiete, Hosting, Versicherung', 'lindenweg16' ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Betrag (€)', 'lindenweg16' ); ?></th><td><input type="number" step="0.01" name="betrag" class="small-text" required></td></tr>
				<tr><th><?php esc_html_e( 'EÜR-Kategorie', 'lindenweg16' ); ?></th><td>
					<select name="kategorie">
						<?php foreach ( self::eur_kategorien() as $k ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $k ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'Intervall', 'lindenweg16' ); ?></th><td>
					<select name="intervall">
						<?php foreach ( DauerbuchungRepository::intervall_optionen() as $k => $v ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'Start', 'lindenweg16' ); ?></th><td><input type="date" name="start_datum" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required></td></tr>
				<tr><th><?php esc_html_e( 'Ende (optional)', 'lindenweg16' ); ?></th><td><input type="date" name="ende_datum"></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Dauerbuchung anlegen', 'lindenweg16' ), 'secondary' ); ?>
		</form>
		<?php

		// Bestehende Dauerbuchungen.
		$alle = DauerbuchungRepository::get_all( false );
		if ( $alle ) {
			echo '<h3>' . esc_html__( 'Aktive Dauerbuchungen', 'lindenweg16' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Bezeichnung', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Betrag', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Kategorie', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Intervall', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Nächste', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Letzte', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th><th></th>';
			echo '</tr></thead><tbody>';

			$intv = DauerbuchungRepository::intervall_optionen();
			foreach ( $alle as $db ) {
				$aktiv = (int) $db->aktiv === 1;
				echo '<tr' . ( ! $aktiv ? ' style="opacity:0.5"' : '' ) . '>';
				echo '<td>' . esc_html( $db->bezeichnung ) . '</td>';
				echo '<td>' . esc_html( self::euro( $db->betrag ) ) . '</td>';
				echo '<td>' . esc_html( $db->kategorie ) . '</td>';
				echo '<td>' . esc_html( isset( $intv[ $db->intervall ] ) ? $intv[ $db->intervall ] : $db->intervall ) . '</td>';
				echo '<td>' . esc_html( $aktiv ? $db->naechste_ausfuehrung : '–' ) . '</td>';
				echo '<td>' . esc_html( $db->letzte_ausfuehrung ?: '–' ) . '</td>';
				echo '<td>' . ( $aktiv ? esc_html__( 'aktiv', 'lindenweg16' ) : esc_html__( 'inaktiv', 'lindenweg16' ) ) . '</td>';
				if ( $aktiv ) {
					$deact = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_deactivate_dauerbuchung&id=' . $db->id ), 'lw16_deactivate_dauerbuchung_' . $db->id );
					echo '<td><a class="button button-small" href="' . esc_url( $deact ) . '" onclick="return confirm(\'' . esc_attr__( 'Dauerbuchung deaktivieren?', 'lindenweg16' ) . '\')">' . esc_html__( 'Deaktivieren', 'lindenweg16' ) . '</a></td>';
				} else {
					echo '<td></td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/* --------------------------------------------------------------------
	 * Anlagenbuchhaltung (AfA)
	 * ------------------------------------------------------------------ */

	/**
	 * Anlagen-Tab rendern.
	 */
	protected function render_anlagen() {
		echo '<h2>' . esc_html__( 'Anlagenbuchhaltung', 'lindenweg16' ) . '</h2>';

		// AfA buchen.
		$afa_url = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_book_afa' ), 'lw16_book_afa' );
		echo '<p>' . esc_html__( 'Anlagevermögen gesamt:', 'lindenweg16' ) . ' <strong>' . esc_html( self::euro( AnlagenRepository::summe_buchwerte() ) ) . '</strong></p>';

		// Neue Anlage erfassen.
		?>
		<h3><?php esc_html_e( 'Neue Anlage erfassen', 'lindenweg16' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lw16_save_anlage">
			<?php wp_nonce_field( 'lw16_save_anlage' ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Bezeichnung', 'lindenweg16' ); ?></th><td><input type="text" name="bezeichnung" class="regular-text" required placeholder="<?php esc_attr_e( 'z. B. Laptop, 3D-Drucker, Büromöbel', 'lindenweg16' ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Anschaffungsdatum', 'lindenweg16' ); ?></th><td><input type="date" name="anschaffungsdatum" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required></td></tr>
				<tr><th><?php esc_html_e( 'Anschaffungskosten (€)', 'lindenweg16' ); ?></th><td><input type="number" step="0.01" name="anschaffungskosten" class="small-text" required></td></tr>
				<tr><th><?php esc_html_e( 'Nutzungsdauer (Jahre)', 'lindenweg16' ); ?></th><td><input type="number" min="1" max="50" name="nutzungsdauer_jahre" value="3" class="small-text" required></td></tr>
				<tr><th><?php esc_html_e( 'Kategorie', 'lindenweg16' ); ?></th><td>
					<select name="kategorie">
						<option value="EDV/Hardware"><?php esc_html_e( 'EDV/Hardware', 'lindenweg16' ); ?></option>
						<option value="Maschinen"><?php esc_html_e( 'Maschinen', 'lindenweg16' ); ?></option>
						<option value="Büroausstattung"><?php esc_html_e( 'Büroausstattung', 'lindenweg16' ); ?></option>
						<option value="Fahrzeuge"><?php esc_html_e( 'Fahrzeuge', 'lindenweg16' ); ?></option>
						<option value="Werkzeuge"><?php esc_html_e( 'Werkzeuge', 'lindenweg16' ); ?></option>
						<option value="Sonstiges"><?php esc_html_e( 'Sonstiges', 'lindenweg16' ); ?></option>
					</select>
				</td></tr>
			</tbody></table>
			<?php submit_button( __( 'Anlage erfassen', 'lindenweg16' ), 'secondary' ); ?>
		</form>
		<?php

		// AfA-Buchung.
		echo '<h3>' . esc_html__( 'Jährliche Abschreibung buchen', 'lindenweg16' ) . '</h3>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:24px">
			<input type="hidden" name="action" value="lw16_book_afa">
			<?php wp_nonce_field( 'lw16_book_afa' ); ?>
			<p>
				<label><?php esc_html_e( 'Jahr:', 'lindenweg16' ); ?>
				<input type="number" name="afa_jahr" value="<?php echo esc_attr( gmdate( 'Y' ) ); ?>" min="2020" max="2099" class="small-text"></label>
				<?php submit_button( __( 'AfA für alle Anlagen buchen', 'lindenweg16' ), 'primary', '', false ); ?>
			</p>
		</form>
		<?php

		// Anlagenverzeichnis.
		$anlagen = AnlagenRepository::get_all( false );
		if ( $anlagen ) {
			echo '<h3>' . esc_html__( 'Anlagenverzeichnis', 'lindenweg16' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Nr.', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Bezeichnung', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Anschaffung', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Kosten', 'lindenweg16' ) . '</th><th>' . esc_html__( 'ND', 'lindenweg16' ) . '</th><th>' . esc_html__( 'AfA/Jahr', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Buchwert', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Kategorie', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th><th></th>';
			echo '</tr></thead><tbody>';

			foreach ( $anlagen as $a ) {
				$ausgeschieden = (int) $a->ausgeschieden === 1;
				$jahres_afa = round( (float) $a->anschaffungskosten / max( 1, (int) $a->nutzungsdauer_jahre ), 2 );
				echo '<tr' . ( $ausgeschieden ? ' style="opacity:0.5"' : '' ) . '>';
				echo '<td>' . esc_html( $a->anlagennummer ) . '</td>';
				echo '<td>' . esc_html( $a->bezeichnung ) . '</td>';
				echo '<td>' . esc_html( $a->anschaffungsdatum ) . '</td>';
				echo '<td>' . esc_html( self::euro( $a->anschaffungskosten ) ) . '</td>';
				echo '<td>' . esc_html( $a->nutzungsdauer_jahre ) . ' ' . esc_html__( 'J.', 'lindenweg16' ) . '</td>';
				echo '<td>' . esc_html( self::euro( $jahres_afa ) ) . '</td>';
				echo '<td><strong>' . esc_html( self::euro( $a->buchwert ) ) . '</strong></td>';
				echo '<td>' . esc_html( $a->kategorie ) . '</td>';
				if ( $ausgeschieden ) {
					echo '<td>' . esc_html__( 'ausgeschieden', 'lindenweg16' ) . '</td><td></td>';
				} else {
					echo '<td>' . esc_html__( 'aktiv', 'lindenweg16' ) . '</td>';
					$aus = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_ausscheiden_anlage&id=' . $a->id ), 'lw16_ausscheiden_anlage_' . $a->id );
					echo '<td><a class="button button-small" href="' . esc_url( $aus ) . '" onclick="return confirm(\'' . esc_attr__( 'Anlage als ausgeschieden markieren?', 'lindenweg16' ) . '\')">' . esc_html__( 'Ausscheiden', 'lindenweg16' ) . '</a></td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/* --------------------------------------------------------------------
	 * Neue POST-Handler: Kassenbuch, Dauerbuchungen, Anlagen, OCR
	 * ------------------------------------------------------------------ */

	/**
	 * Kassenbuch-Eintrag speichern.
	 */
	public function handle_save_kassenbuch() {
		$this->guard( 'lw16_save_kassenbuch' );
		KassenbuchRepository::create( array(
			'datum'         => sanitize_text_field( wp_unslash( $_POST['datum'] ?? gmdate( 'Y-m-d' ) ) ),
			'beschreibung'  => sanitize_text_field( wp_unslash( $_POST['beschreibung'] ?? '' ) ),
			'einnahme'      => abs( (float) ( $_POST['einnahme'] ?? 0 ) ),
			'ausgabe'       => abs( (float) ( $_POST['ausgabe'] ?? 0 ) ),
		) );
		$this->redirect( __( 'Kassenbuch-Eintrag gebucht.', 'lindenweg16' ), 'kassenbuch' );
	}

	/**
	 * Kassenbuch-Eintrag stornieren.
	 */
	public function handle_storno_kassenbuch() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_storno_kassenbuch_' . $id );
		KassenbuchRepository::create_storno( $id, __( 'Manuelle Stornierung', 'lindenweg16' ) );
		$this->redirect( __( 'Kassenbuch-Eintrag storniert.', 'lindenweg16' ), 'kassenbuch' );
	}

	/**
	 * Neue Dauerbuchung speichern.
	 */
	public function handle_save_dauerbuchung() {
		$this->guard( 'lw16_save_dauerbuchung' );
		DauerbuchungRepository::create( array(
			'bezeichnung'  => sanitize_text_field( wp_unslash( $_POST['bezeichnung'] ?? '' ) ),
			'betrag'       => abs( (float) ( $_POST['betrag'] ?? 0 ) ),
			'kategorie'    => sanitize_text_field( wp_unslash( $_POST['kategorie'] ?? '' ) ),
			'typ'          => 'ausgabe',
			'intervall'    => sanitize_text_field( wp_unslash( $_POST['intervall'] ?? 'monatlich' ) ),
			'start_datum'  => sanitize_text_field( wp_unslash( $_POST['start_datum'] ?? gmdate( 'Y-m-d' ) ) ),
			'ende_datum'   => ! empty( $_POST['ende_datum'] ) ? sanitize_text_field( wp_unslash( $_POST['ende_datum'] ) ) : null,
		) );
		$this->redirect( __( 'Dauerbuchung angelegt.', 'lindenweg16' ), 'dauerbuchungen' );
	}

	/**
	 * Dauerbuchung deaktivieren.
	 */
	public function handle_deactivate_dauerbuchung() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_deactivate_dauerbuchung_' . $id );
		DauerbuchungRepository::deactivate( $id );
		$this->redirect( __( 'Dauerbuchung deaktiviert.', 'lindenweg16' ), 'dauerbuchungen' );
	}

	/**
	 * Fällige Dauerbuchungen manuell ausführen.
	 */
	public function handle_execute_dauerbuchungen() {
		$this->guard( 'lw16_execute_dauerbuchungen' );
		$count = DauerbuchungRepository::execute_due();
		$msg   = sprintf(
			/* translators: %d number of executed bookings */
			__( '%d Dauerbuchung(en) ausgeführt.', 'lindenweg16' ),
			$count
		);
		$this->redirect( $msg, 'dauerbuchungen' );
	}

	/**
	 * Neue Anlage speichern.
	 */
	public function handle_save_anlage() {
		$this->guard( 'lw16_save_anlage' );
		AnlagenRepository::create( array(
			'bezeichnung'         => sanitize_text_field( wp_unslash( $_POST['bezeichnung'] ?? '' ) ),
			'anschaffungsdatum'   => sanitize_text_field( wp_unslash( $_POST['anschaffungsdatum'] ?? gmdate( 'Y-m-d' ) ) ),
			'anschaffungskosten'  => abs( (float) ( $_POST['anschaffungskosten'] ?? 0 ) ),
			'nutzungsdauer_jahre' => max( 1, (int) ( $_POST['nutzungsdauer_jahre'] ?? 3 ) ),
			'kategorie'           => sanitize_text_field( wp_unslash( $_POST['kategorie'] ?? '' ) ),
		) );
		$this->redirect( __( 'Anlage erfasst.', 'lindenweg16' ), 'anlagen' );
	}

	/**
	 * AfA für ein Jahr buchen.
	 */
	public function handle_book_afa() {
		$this->guard( 'lw16_book_afa' );
		$jahr  = (int) ( $_POST['afa_jahr'] ?? gmdate( 'Y' ) );
		$count = AnlagenRepository::book_yearly_afa( $jahr );
		$msg   = sprintf(
			/* translators: %1$d number of assets, %2$d year */
			__( 'AfA für %1$d Anlage(n) im Jahr %2$d gebucht.', 'lindenweg16' ),
			$count,
			$jahr
		);
		$this->redirect( $msg, 'anlagen' );
	}

	/**
	 * Anlage als ausgeschieden markieren.
	 */
	public function handle_ausscheiden_anlage() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'lw16_ausscheiden_anlage_' . $id );
		AnlagenRepository::ausscheiden( $id );
		$this->redirect( __( 'Anlage als ausgeschieden markiert.', 'lindenweg16' ), 'anlagen' );
	}

	/**
	 * OCR-Scan eines Beleg-Fotos (AJAX).
	 */
	public function handle_ocr_scan() {
		check_ajax_referer( 'lw16_ocr_scan' );
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_send_json_error( array( 'error' => __( 'Keine Berechtigung.', 'lindenweg16' ) ) );
		}

		if ( empty( $_FILES['beleg_foto']['tmp_name'] ) ) {
			wp_send_json_error( array( 'error' => __( 'Keine Datei empfangen.', 'lindenweg16' ) ) );
		}

		$tmp = $_FILES['beleg_foto']['tmp_name'];
		$result = BelegOcr::scan( $tmp );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'error' => $result['error'] ) );
		}

		wp_send_json_success( array(
			'betrag'   => $result['betrag'],
			'datum'    => $result['datum'],
			'haendler' => $result['haendler'],
		) );
	}

	/**
	 * Scheduler-Callback: Fällige Dauerbuchungen automatisch ausführen.
	 */
	public function run_recurring_bookings() {
		DauerbuchungRepository::execute_due();
	}

	/* --------------------------------------------------------------------
	 * Helfer
	 * ------------------------------------------------------------------ */

	/**
	 * Amtliche EÜR-Kategorien (vereinfacht).
	 *
	 * @return string[]
	 */
	public static function eur_kategorien() {
		return array(
			'Wareneinkauf',
			'Büromaterial',
			'Fahrtkosten',
			'Telefon/Internet',
			'Fortbildung',
			'Porto/Versand',
			'Hosting/Software',
			'AfA',
			'Sonstiges',
		);
	}

	/**
	 * Betrag als Euro formatieren.
	 *
	 * @param float $n Betrag.
	 * @return string
	 */
	public static function euro( $n ) {
		return number_format( (float) $n, 2, ',', '.' ) . ' €';
	}

	/**
	 * Berechtigung + Nonce prüfen.
	 *
	 * @param string $nonce Nonce-Aktion.
	 */
	protected function guard( $nonce ) {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Zurück zur Buchhaltung mit Meldung.
	 *
	 * @param string $msg  Meldung.
	 * @param string $view Ansicht.
	 */
	protected function redirect( $msg, $view = '' ) {
		$args = array( 'page' => 'lw16-buchhaltung', 'msg' => rawurlencode( $msg ) );
		if ( $view ) {
			$args['view'] = $view;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
