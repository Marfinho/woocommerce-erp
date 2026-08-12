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
		add_action( 'lw16_render_page_lw16_buchhaltung', array( $this, 'render_admin' ) );

		add_action( 'admin_post_lw16_save_beleg', array( $this, 'handle_save_beleg' ) );
		add_action( 'admin_post_lw16_festschreiben', array( $this, 'handle_festschreiben' ) );

		// Geschützte Beleg-Foto-Auslieferung (nur mit Capability).
		add_action( 'admin_post_lw16_beleg_datei', array( $this, 'serve_beleg_file' ) );

		// Automatische Rechnungserstellung: bei Abschluss asynchron einreihen.
		add_action( 'woocommerce_order_status_completed', array( $this, 'enqueue_invoice' ), 30, 1 );
		add_action( \Lindenweg16\Core\Scheduler::HOOK_GENERATE_INVOICE, array( $this, 'generate_invoice' ), 10, 1 );

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

		// EÜR-PDF-Export.
		add_action( 'admin_post_lw16_euer_pdf', array( $this, 'export_euer_pdf' ) );

		// Rechnungs-Download (Admin + Kunde).
		add_action( 'admin_post_lw16_rechnung', array( $this, 'serve_invoice' ) );
		add_action( 'admin_post_nopriv_lw16_rechnung', array( $this, 'serve_invoice' ) );

		// Rechnung in der Bestellansicht (Admin) und im Kundenkonto verlinken.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'admin_order_invoice_link' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'account_order_invoice_action' ), 10, 2 );
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
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( $base ), 'belege' === $view ? 'active' : '', esc_html__( 'Belege', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'neu', $base ) ), 'neu' === $view ? 'active' : '', esc_html__( 'Beleg erfassen', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'rechnungen', $base ) ), 'rechnungen' === $view ? 'active' : '', esc_html__( 'Rechnungen', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'journal', $base ) ), 'journal' === $view ? 'active' : '', esc_html__( 'Journal', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'euer', $base ) ), 'euer' === $view ? 'active' : '', esc_html__( 'EÜR', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'erechnung', $base ) ), 'erechnung' === $view ? 'active' : '', esc_html__( 'E-Rechnung', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'bp_export', $base ) ), 'bp_export' === $view ? 'active' : '', esc_html__( 'Betriebsprüfung', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a>', esc_url( add_query_arg( 'view', 'gobd', $base ) ), 'gobd' === $view ? 'active' : '', esc_html__( 'GoBD-Prüfung', 'lindenweg16' ) );
		echo '</nav>';

		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}

		switch ( $view ) {
			case 'neu':
				$this->render_beleg_form();
				break;
			case 'rechnungen':
				$this->render_rechnungen_list();
				break;
			case 'journal':
				$this->render_journal();
				break;
			case 'euer':
				$this->render_euer();
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
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="lw16_save_beleg">
			<?php wp_nonce_field( 'lw16_save_beleg' ); ?>
			<table class="form-table"><tbody>
				<tr><th><?php esc_html_e( 'Beleg-Foto', 'lindenweg16' ); ?></th><td><input type="file" name="beleg_foto" accept="image/*"></td></tr>
				<tr><th><?php esc_html_e( 'Betrag (€)', 'lindenweg16' ); ?></th><td><input type="number" step="0.01" name="betrag" class="small-text" required></td></tr>
				<tr><th><?php esc_html_e( 'Datum', 'lindenweg16' ); ?></th><td><input type="date" name="datum" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Händler', 'lindenweg16' ); ?></th><td><input type="text" name="haendler" class="regular-text"></td></tr>
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
