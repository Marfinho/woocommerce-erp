<?php
/**
 * Modul 4: Produktions- und Bestellplanung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Production;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Installer;
use Lindenweg16\Core\Scheduler;
use Lindenweg16\Modules\Inventory\Repository;
use Lindenweg16\Modules\Inventory\StockManager;
use Lindenweg16\Modules\Pricing\PricingCalculator;

/**
 * Druck-Queue, Packliste, DHL-Verlinkung/-Tracking, Ist-Zeit-Erfassung
 * und vorausschauende Mengenvorschläge.
 */
class ProductionModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_render_page_lw16_produktion', array( $this, 'render_admin' ) );

		// Packliste automatisch bei Status processing (asynchron).
		add_action( 'woocommerce_order_status_processing', array( $this, 'enqueue_packing_slip' ), 20, 1 );
		add_action( Scheduler::HOOK_GENERATE_PACKING, array( $this, 'generate_packing_slip' ), 10, 1 );
		add_action( 'admin_post_lw16_packliste', array( $this, 'serve_or_generate_packing_slip' ) );

		// Druckauftrag-Status + Ist-Zeit-Erfassung.
		add_action( 'admin_post_lw16_job_status', array( $this, 'handle_job_status' ) );
		add_action( 'admin_post_lw16_job_complete', array( $this, 'handle_job_complete' ) );

		// Dashboard: vorausschauende Mengenvorschläge.
		add_action( 'lw16_dashboard_widgets', array( $this, 'render_forecast_widget' ) );

		// DHL-Sendungsverfolgung.
		( new DhlTracking() )->register();
	}

	/* --------------------------------------------------------------------
	 * Packliste
	 * ------------------------------------------------------------------ */

	/**
	 * Packlisten-Erzeugung asynchron einreihen.
	 *
	 * @param int $order_id Bestell-ID.
	 */
	public function enqueue_packing_slip( $order_id ) {
		Scheduler::enqueue_async( Scheduler::HOOK_GENERATE_PACKING, array( 'order_id' => (int) $order_id ) );
	}

	/**
	 * Packliste erzeugen (Action-Scheduler-Callback).
	 *
	 * @param int $order_id Bestell-ID.
	 */
	public function generate_packing_slip( $order_id ) {
		PackingSlipGenerator::generate( (int) $order_id );
	}

	/**
	 * Packliste ausliefern (bei Bedarf neu generieren).
	 */
	public function serve_or_generate_packing_slip() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$order_id = (int) ( $_GET['order_id'] ?? 0 );
		check_admin_referer( 'lw16_packliste_' . $order_id );

		$notiz = isset( $_GET['notiz'] ) ? sanitize_text_field( wp_unslash( $_GET['notiz'] ) ) : '';
		// Immer frisch generieren (deckt Adressänderungen ab).
		$filename = PackingSlipGenerator::generate( $order_id, $notiz );
		if ( ! $filename ) {
			wp_die( esc_html__( 'Packliste konnte nicht erzeugt werden.', 'lindenweg16' ) );
		}

		$path = trailingslashit( Installer::docs_dir() ) . $filename;
		if ( ! is_file( $path ) ) {
			wp_die( esc_html__( 'Datei nicht verfügbar.', 'lindenweg16' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore
		exit;
	}

	/* --------------------------------------------------------------------
	 * Admin-Oberfläche
	 * ------------------------------------------------------------------ */

	/**
	 * Produktions-Admin-Seite mit Tabs.
	 */
	public function render_admin() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'queue'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Produktionsplanung', 'lindenweg16' ) . '</h1>';

		$base = admin_url( 'admin.php?page=lw16-produktion' );
		echo '<nav class="lw16-tabs">';
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( $base ), 'queue' === $view ? 'active' : '', esc_html__( 'Druck-Queue', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a> ', esc_url( add_query_arg( 'view', 'jobs', $base ) ), 'jobs' === $view ? 'active' : '', esc_html__( 'Druckaufträge', 'lindenweg16' ) );
		printf( '<a href="%s" class="%s">%s</a>', esc_url( add_query_arg( 'view', 'auswertung', $base ) ), 'auswertung' === $view ? 'active' : '', esc_html__( 'Ist vs. Plan', 'lindenweg16' ) );
		echo '</nav>';

		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}

		if ( 'jobs' === $view ) {
			$this->render_jobs();
		} elseif ( 'auswertung' === $view ) {
			$this->render_auswertung();
		} else {
			$this->render_queue();
		}
		echo '</div>';
	}

	/**
	 * Druck-Queue aus offenen Bestellungen (Status processing).
	 */
	protected function render_queue() {
		echo '<h2>' . esc_html__( 'Druck-Queue (offene Bestellungen)', 'lindenweg16' ) . '</h2>';

		$orders = wc_get_orders(
			array( 'status' => array( 'wc-processing' ), 'limit' => -1, 'orderby' => 'date', 'order' => 'ASC', 'return' => 'objects' )
		);

		// Aggregierte Modulmengen.
		$queue = array();
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$produkt = Repository::get_by_woo_id( $item->get_product_id() );
				if ( ! $produkt ) {
					continue;
				}
				$this->add_modules( $produkt, (float) $item->get_quantity(), '#' . $order->get_order_number(), $queue );
			}
		}

		if ( ! empty( $queue ) ) {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Modul', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Benötigt', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Bestand', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Fehlend', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Bestellungen', 'lindenweg16' ) . '</th><th></th>';
			echo '</tr></thead><tbody>';
			foreach ( $queue as $modul_id => $row ) {
				$bestand = Repository::get_stock( $modul_id );
				$fehlend = max( 0, $row['benoetigt'] - $bestand );
				echo '<tr>';
				echo '<td>' . esc_html( $row['name'] ) . '</td>';
				echo '<td>' . esc_html( $this->fmt( $row['benoetigt'] ) ) . '</td>';
				echo '<td>' . esc_html( $this->fmt( $bestand ) ) . '</td>';
				echo '<td' . ( $fehlend > 0 ? ' class="lw16-fehlend"' : '' ) . '>' . esc_html( $this->fmt( $fehlend ) ) . '</td>';
				echo '<td>' . esc_html( implode( ', ', array_unique( $row['orders'] ) ) ) . '</td>';
				echo '<td>';
				if ( $fehlend > 0 ) {
					$form = admin_url( 'admin-post.php' );
					echo '<form method="post" action="' . esc_url( $form ) . '" class="lw16-inline-form">';
					echo '<input type="hidden" name="action" value="lw16_job_status"><input type="hidden" name="new_job" value="1">';
					echo '<input type="hidden" name="produkt_id" value="' . (int) $modul_id . '"><input type="hidden" name="menge" value="' . esc_attr( $fehlend ) . '">';
					wp_nonce_field( 'lw16_job_new' );
					submit_button( __( 'In Druckauftrag', 'lindenweg16' ), 'small', '', false );
					echo '</form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Keine offenen Bestellungen.', 'lindenweg16' ) . '</p>';
		}

		// Packlisten + DHL-Label je Bestellung.
		echo '<h2>' . esc_html__( 'Versand-Aktionen je Bestellung', 'lindenweg16' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Bestellung', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Kunde', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Aktionen', 'lindenweg16' ) . '</th></tr></thead><tbody>';
		foreach ( $orders as $order ) {
			echo '<tr><td>#' . esc_html( $order->get_order_number() ) . '</td>';
			echo '<td>' . esc_html( $order->get_formatted_billing_full_name() ) . '</td><td>';
			$pl = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_packliste&order_id=' . $order->get_id() ), 'lw16_packliste_' . $order->get_id() );
			echo '<a class="button button-small" href="' . esc_url( $pl ) . '" target="_blank">' . esc_html__( 'Packliste', 'lindenweg16' ) . '</a> ';
			$label = admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
			echo '<a class="button button-small" href="' . esc_url( $label ) . '#dhl">' . esc_html__( 'DHL-Label erstellen', 'lindenweg16' ) . '</a>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Der DHL-Label-Button führt zur Bestellung, wo das DHL-Plugin die Ein-Klick-Label-Erstellung bereitstellt.', 'lindenweg16' ) . '</p>';
	}

	/**
	 * Druckauftrags-Liste mit Statusverwaltung + Ist-Zeit-Erfassung.
	 */
	protected function render_jobs() {
		$jobs = ProductionRepository::open_jobs();
		if ( empty( $jobs ) ) {
			echo '<p>' . esc_html__( 'Keine offenen Druckaufträge.', 'lindenweg16' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Modul', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Menge', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Aktion', 'lindenweg16' ) . '</th>';
		echo '</tr></thead><tbody>';

		$stufen = array( 'geplant', 'in_produktion', 'fertig', 'verpackt' );
		foreach ( $jobs as $job ) {
			$produkt = Repository::get_product( $job->produkt_id );
			echo '<tr><td>' . esc_html( $produkt ? $produkt->name : '#' . $job->produkt_id ) . '</td>';
			echo '<td>' . esc_html( $this->fmt( $job->menge ) ) . '</td>';
			echo '<td>' . esc_html( $job->status ) . '</td><td>';

			if ( 'fertig' === $job->status ) {
				// Beim Abschluss Ist-Zeiten erfassen.
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-inline-form">';
				echo '<input type="hidden" name="action" value="lw16_job_complete"><input type="hidden" name="job_id" value="' . (int) $job->id . '">';
				wp_nonce_field( 'lw16_job_complete_' . $job->id );
				echo '<label>' . esc_html__( 'Ist-Druckzeit (h)', 'lindenweg16' ) . ' <input type="number" step="any" name="ist_druckzeit_h" class="small-text"></label> ';
				echo '<label>' . esc_html__( 'Ist-Arbeitszeit (min)', 'lindenweg16' ) . ' <input type="number" step="any" name="ist_arbeitszeit_min" class="small-text"></label> ';
				submit_button( __( 'Verpackt & Log speichern', 'lindenweg16' ), 'small primary', '', false );
				echo '</form>';
			} else {
				$naechste = $stufen[ min( count( $stufen ) - 1, array_search( $job->status, $stufen, true ) + 1 ) ];
				$url      = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_job_status&job_id=' . $job->id . '&status=' . $naechste ), 'lw16_job_' . $job->id );
				echo '<a class="button button-small" href="' . esc_url( $url ) . '">→ ' . esc_html( $naechste ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Ist-vs-Plan-Auswertung je Modul.
	 */
	protected function render_auswertung() {
		$module = Repository::get_products( 'modul' );
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Modul', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Plan Arbeitszeit', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Ist-Ø Arbeitszeit', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Abweichung', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Ist-Ø Druckzeit', 'lindenweg16' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $module as $m ) {
			$avg = ProductionRepository::avg_ist( $m->id, 8 );
			echo '<tr><td>' . esc_html( $m->name ) . '</td>';
			echo '<td>' . esc_html( $this->fmt( $m->arbeitszeit_minuten ) ) . ' min</td>';
			if ( $avg ) {
				$plan  = (float) $m->arbeitszeit_minuten;
				$ist   = $avg['ist_arbeitszeit_min'];
				$abw   = $plan > 0 ? round( ( $ist - $plan ) / $plan * 100 ) : 0;
				echo '<td>' . esc_html( $this->fmt( $ist ) ) . ' min</td>';
				echo '<td' . ( abs( $abw ) >= 20 ? ' class="lw16-fehlend"' : '' ) . '>' . esc_html( ( $abw > 0 ? '+' : '' ) . $abw . ' %' ) . '</td>';
				echo '<td>' . esc_html( $this->fmt( $avg['ist_druckzeit_h'] ) ) . ' h</td>';
			} else {
				echo '<td>–</td><td>–</td><td>–</td>';
			}
			echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=lw16-preiskalkulation' ) ) . '">' . esc_html__( 'zur Kalkulation', 'lindenweg16' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Empfehlung, keine automatische Anpassung – die Kalkulationswerte änderst Du bewusst selbst.', 'lindenweg16' ) . '</p>';
	}

	/**
	 * Dashboard-Widget: vorausschauende Mengenvorschläge.
	 */
	public function render_forecast_widget() {
		$cache = get_transient( 'lw16_forecast_widget' );
		if ( false === $cache ) {
			$cache  = '';
			$module = Repository::get_products( 'modul' );
			$rows   = '';
			foreach ( $module as $m ) {
				$schnitt = ProductionRepository::weekly_average( $m->id, 6 );
				if ( $schnitt <= 0 ) {
					continue;
				}
				$bestand   = Repository::get_stock( $m->id );
				$tage_reicht = $schnitt > 0 ? floor( $bestand / ( $schnitt / 7 ) ) : 0;
				$vorschlag = max( 0, ceil( $schnitt * 2 - $bestand ) ); // Puffer für ~2 Wochen.
				if ( $vorschlag <= 0 ) {
					continue;
				}
				$rows .= '<tr><td>' . esc_html( $m->name ) . '</td>';
				$rows .= '<td>' . esc_html( number_format( $vorschlag, 0, ',', '.' ) ) . '</td>';
				$rows .= '<td>' . esc_html( sprintf( /* translators: %d: Tage */ __( 'Bestand reicht noch ~%d Tage', 'lindenweg16' ), (int) $tage_reicht ) ) . '</td></tr>';
			}
			if ( $rows ) {
				$cache = '<div class="lw16-card"><h2>' . esc_html__( 'Vorproduktions-Vorschläge', 'lindenweg16' ) . '</h2>';
				$cache .= '<table class="widefat"><thead><tr><th>' . esc_html__( 'Modul', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Vorschlag (Stück)', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Begründung', 'lindenweg16' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
				$cache .= '<p class="lw16-hint">' . esc_html__( 'Reine Empfehlung auf Basis des gleitenden 6-Wochen-Durchschnitts – keine automatische Produktion.', 'lindenweg16' ) . '</p></div>';
			}
			set_transient( 'lw16_forecast_widget', $cache, HOUR_IN_SECONDS );
		}
		echo $cache; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/* --------------------------------------------------------------------
	 * POST-Handler
	 * ------------------------------------------------------------------ */

	/**
	 * Druckauftrag-Status weiterschalten oder neuen Auftrag anlegen.
	 */
	public function handle_job_status() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}

		if ( ! empty( $_POST['new_job'] ) ) {
			check_admin_referer( 'lw16_job_new' );
			$produkt_id = (int) ( $_POST['produkt_id'] ?? 0 );
			$menge      = (float) ( $_POST['menge'] ?? 0 );
			if ( $produkt_id && $menge > 0 ) {
				ProductionRepository::create_job( $produkt_id, $menge );
			}
			$this->redirect( 'jobs', __( 'Druckauftrag angelegt.', 'lindenweg16' ) );
		}

		$job_id = (int) ( $_GET['job_id'] ?? 0 );
		check_admin_referer( 'lw16_job_' . $job_id );
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( in_array( $status, array( 'in_produktion', 'fertig', 'verpackt' ), true ) ) {
			ProductionRepository::set_job_status( $job_id, $status );
		}
		$this->redirect( 'jobs', __( 'Status aktualisiert.', 'lindenweg16' ) );
	}

	/**
	 * Druckauftrag abschließen: Ist-Zeit loggen, Bestand als Produktion buchen.
	 */
	public function handle_job_complete() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		$job_id = (int) ( $_POST['job_id'] ?? 0 );
		check_admin_referer( 'lw16_job_complete_' . $job_id );

		$job = ProductionRepository::get_job( $job_id );
		if ( ! $job ) {
			$this->redirect( 'jobs', __( 'Druckauftrag nicht gefunden.', 'lindenweg16' ) );
		}
		$produkt = Repository::get_product( $job->produkt_id );

		ProductionRepository::add_log(
			array(
				'produkt_id'             => (int) $job->produkt_id,
				'menge'                  => (float) $job->menge,
				'geplante_druckzeit_h'   => $produkt ? (float) $produkt->druckzeit_stunden : null,
				'ist_druckzeit_h'        => (float) ( $_POST['ist_druckzeit_h'] ?? 0 ),
				'geplante_arbeitszeit_min' => $produkt ? (float) $produkt->arbeitszeit_minuten : null,
				'ist_arbeitszeit_min'    => (float) ( $_POST['ist_arbeitszeit_min'] ?? 0 ),
			)
		);

		// Fertige Menge dem Lager als Produktion gutschreiben (inkl. Rezeptur-Verbrauch).
		StockManager::record_movement( (int) $job->produkt_id, (float) $job->menge, 'produktion', array( 'referenz' => __( 'Druckauftrag', 'lindenweg16' ) . ' #' . $job->id ) );
		foreach ( Repository::get_rezeptur( $job->produkt_id ) as $rez ) {
			StockManager::record_movement( (int) $rez->rohmaterial_id, -1 * (float) $job->menge * (float) $rez->menge, 'produktion', array( 'referenz' => __( 'Verbrauch Druckauftrag', 'lindenweg16' ) . ' #' . $job->id ) );
		}

		ProductionRepository::set_job_status( $job_id, 'verpackt' );
		$this->redirect( 'jobs', __( 'Druckauftrag abgeschlossen und Ist-Zeit erfasst.', 'lindenweg16' ) );
	}

	/* --------------------------------------------------------------------
	 * Helfer
	 * ------------------------------------------------------------------ */

	/**
	 * Produkt rekursiv in Module auflösen und in die Queue eintragen.
	 *
	 * @param object $produkt  Produkt.
	 * @param float  $menge    Menge.
	 * @param string $order_no Bestellnummer.
	 * @param array  $queue    Queue-Referenz.
	 */
	protected function add_modules( $produkt, $menge, $order_no, array &$queue ) {
		if ( 'set' === $produkt->typ ) {
			foreach ( Repository::get_stueckliste( $produkt->id ) as $pos ) {
				$modul = Repository::get_product( $pos->modul_id );
				if ( $modul ) {
					$this->add_modules( $modul, $menge * (float) $pos->menge, $order_no, $queue );
				}
			}
			return;
		}
		if ( 'modul' === $produkt->typ ) {
			if ( ! isset( $queue[ $produkt->id ] ) ) {
				$queue[ $produkt->id ] = array( 'name' => $produkt->name, 'benoetigt' => 0, 'orders' => array() );
			}
			$queue[ $produkt->id ]['benoetigt'] += $menge;
			$queue[ $produkt->id ]['orders'][]   = $order_no;
		}
	}

	/**
	 * Zahl formatieren.
	 *
	 * @param float $n Zahl.
	 * @return string
	 */
	protected function fmt( $n ) {
		$s = number_format( (float) $n, 2, ',', '.' );
		return rtrim( rtrim( $s, '0' ), ',' );
	}

	/**
	 * Redirect zur Produktionsseite.
	 *
	 * @param string $view Ansicht.
	 * @param string $msg  Meldung.
	 */
	protected function redirect( $view, $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'lw16-produktion', 'view' => $view, 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
