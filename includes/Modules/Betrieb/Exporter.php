<?php
/**
 * CSV- und PDF-Ausgaben des Moduls.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Pdf;
use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\PrintQueue\PrintQueueService;

/**
 * Liefert Exporte aus.
 */
class Exporter {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'admin_post_lw16_betrieb_export', array( $this, 'handle_csv' ) );
		add_action( 'admin_post_lw16_betrieb_gpsr', array( $this, 'handle_gpsr' ) );
		add_action( 'admin_post_lw16_betrieb_verfahren', array( $this, 'handle_verfahren' ) );
		add_action( 'admin_post_lw16_betrieb_analyse', array( $this, 'handle_analyse' ) );
		add_action( 'admin_post_lw16_betrieb_labels', array( $this, 'handle_labels' ) );
		add_action( 'admin_post_lw16_betrieb_labels_queue', array( $this, 'handle_labels_queue' ) );
	}

	/**
	 * CSV-Export.
	 */
	public function handle_csv() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_view_reports' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_betrieb_export' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- oben geprüft.
		$what = isset( $_GET['what'] ) ? sanitize_key( wp_unslash( $_GET['what'] ) ) : '';
		$von  = isset( $_GET['von'] ) ? sanitize_text_field( wp_unslash( $_GET['von'] ) ) : gmdate( 'Y-m-01' );
		$bis  = isset( $_GET['bis'] ) ? sanitize_text_field( wp_unslash( $_GET['bis'] ) ) : gmdate( 'Y-m-d' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		switch ( $what ) {
			case 'print_jobs':
				$this->stream( 'druckjournal', $this->rows_print_jobs() );
				break;
			case 'stock':
				$this->stream( 'lagerbewegungen', $this->rows_stock() );
				break;
			case 'kpi':
				$this->stream( 'kennzahlen', $this->rows_kpi( $von, $bis ) );
				break;
			default:
				wp_die( esc_html__( 'Unbekannter Export.', 'lindenweg16' ) );
		}
	}

	/**
	 * Etiketten aus den Formularfeldern von handle_labels() bzw.
	 * handle_labels_queue() zusammenstellen – reine Auswahl, kein PDF.
	 *
	 * @return array
	 */
	protected function requested_labels() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- die Aufrufer prüfen die Nonce vorher.
		$was  = isset( $_GET['was'] ) ? (array) wp_unslash( $_GET['was'] ) : array();
		$alle = ! empty( $_GET['alle'] );
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$was    = array_map( 'sanitize_key', $was );
		$labels = array();

		if ( in_array( 'bins', $was, true ) ) {
			$labels = array_merge( $labels, LabelPdf::bin_labels( ! $alle ) );
		}

		if ( in_array( 'articles', $was, true ) ) {
			$labels = array_merge( $labels, LabelPdf::article_labels( ! $alle ) );
		}

		if ( in_array( 'rolls', $was, true ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- die Aufrufer prüfen die Nonce vorher.
			$labels = array_merge( $labels, LabelPdf::roll_labels( ! $alle, isset( $_GET['lot'] ) ? (int) $_GET['lot'] : 0 ) );
		}

		if ( in_array( 'printers', $was, true ) ) {
			$labels = array_merge( $labels, LabelPdf::printer_labels( ! $alle ) );
		}

		// Ein einzelnes Etikett nachdrucken: dieselbe Liste, nur gefiltert.
		if ( '' !== $code ) {
			$labels = array_values(
				array_filter(
					$labels,
					static function ( $label ) use ( $code ) {
						return $label['code'] === $code;
					}
				)
			);
		}

		return $labels;
	}

	/**
	 * Alle Etiketten als ein PDF.
	 *
	 * Zum Erstausstatten eines Lagers: einmal drucken statt vierzig Seiten
	 * einzeln zu öffnen.
	 */
	public function handle_labels() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_capture' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_betrieb_export' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- oben geprüft.
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : LabelSheet::DEFAULT_ROLL;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$labels = $this->requested_labels();

		// Rollenformate bekommen eine Seite je Etikett, Bögen eine Seite
		// voller Etiketten.
		$pdf = LabelSheet::has_roll( $format )
			? LabelPdf::build_roll( $labels, $format )
			: LabelPdf::build( $labels, $format );

		if ( ! $pdf ) {
			wp_die( esc_html__( 'Es gibt nichts zu drucken – bitte mindestens eine Sorte wählen, zu der es auch Datensätze gibt.', 'lindenweg16' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="lw16-etiketten-' . gmdate( 'Y-m-d' ) . '.pdf"' );

		echo $pdf->output(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binärdaten.
		exit;
	}

	/**
	 * Etiketten statt herunterzuladen in die Druck-Warteschlange legen.
	 *
	 * Nur Rollenformate (eine Seite = ein Etikett, keine Skalierung) sind
	 * dafür geeignet – ein A4-Bogen mit vielen Etiketten je Seite passt
	 * nicht auf einen Rollendrucker und wird deshalb hier nicht angeboten.
	 * Der Job landet direkt im Status queued; ein Raspberry Pi mit
	 * passender Drucker-ID holt ihn beim nächsten Kontakt ab.
	 */
	public function handle_labels_queue() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_capture' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_betrieb_export' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- oben geprüft.
		$format     = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : LabelSheet::DEFAULT_ROLL;
		$printer_id = isset( $_GET['printer_id'] ) ? sanitize_key( wp_unslash( $_GET['printer_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! LabelSheet::has_roll( $format ) ) {
			$this->redirect_to_labels( __( 'Für die Druck-Warteschlange nur ein Rollenformat wählen – ein A4-Bogen passt nicht auf einen Rollendrucker.', 'lindenweg16' ), 'error' );
		}

		if ( '' === $printer_id ) {
			$this->redirect_to_labels( __( 'Bitte einen Drucker wählen.', 'lindenweg16' ), 'error' );
		}

		$labels = $this->requested_labels();

		if ( ! $labels ) {
			$this->redirect_to_labels( __( 'Es gibt nichts zu drucken – bitte mindestens eine Sorte wählen, zu der es auch Datensätze gibt.', 'lindenweg16' ), 'error' );
		}

		$roll   = LabelSheet::roll( $format );
		$breite = LabelSheet::is_landscape( $roll ) ? (float) $roll['h'] : (float) $roll['w'];
		$hoehe  = LabelSheet::is_landscape( $roll ) ? (float) $roll['w'] : (float) $roll['h'];
		$anzahl = count( $labels );

		// Ein Druckauftrag je Etikett: Der Etikettendrucker nimmt je Auftrag
		// eine Seite, weitere gingen sonst verloren.
		foreach ( $labels as $label ) {
			$pdf    = LabelPdf::build_roll( array( $label ), $format );
			$job_id = $pdf ? PrintQueueService::enqueue_pdf_labels( $printer_id, $pdf->output(), 'article_bin_label', $breite, $hoehe, $format ) : 0;

			if ( ! $job_id ) {
				$this->redirect_to_labels( __( 'Der Druckjob konnte nicht angelegt werden – ist die Drucker-ID richtig geschrieben?', 'lindenweg16' ), 'error' );
			}
		}

		/* translators: 1: Anzahl Etiketten, 2: Drucker-ID. */
		$this->redirect_to_labels( sprintf( __( '%1$d Etikett(en) in die Warteschlange von „%2$s" gelegt.', 'lindenweg16' ), $anzahl, $printer_id ), 'success' );
	}

	/**
	 * Zurück zur Seite „Lagerplätze, Fertigung & Picklisten", auf der das
	 * Etiketten-Formular steht.
	 *
	 * @param string $msg  Meldung.
	 * @param string $type success oder error.
	 */
	protected function redirect_to_labels( $msg, $type = 'success' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'lw16-betrieb-lagerplaetze',
					'lw16_msg' => rawurlencode( $msg ),
					'lw16_typ' => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Auszug zur Analyse als Textdatei.
	 */
	public function handle_analyse() {
		// Der Auszug enthält Herstellkosten, Preise und Umsätze – er gehört
		// deshalb hinter dasselbe Recht wie die Kalkulation.
		if ( ! Roles::current_user_can_betrieb( 'lw16_view_costs' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_betrieb_export' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- oben geprüft.
		$von     = isset( $_GET['von'] ) ? sanitize_text_field( wp_unslash( $_GET['von'] ) ) : gmdate( 'Y-m-01' );
		$bis     = isset( $_GET['bis'] ) ? sanitize_text_field( wp_unslash( $_GET['bis'] ) ) : gmdate( 'Y-m-d' );
		$namen   = ! empty( $_GET['namen'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$text = AnalyseExport::render( AnalyseExport::collect( $von, $bis, $namen ) );

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lw16-analyse-' . gmdate( 'Y-m-d' ) . '.md"' );
		header( 'Content-Length: ' . strlen( $text ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Textdatei, kein HTML.
		exit;
	}

	/**
	 * Zeilen des Druckjournals.
	 *
	 * @return array
	 */
	protected function rows_print_jobs() {
		$printers = array();
		foreach ( PrinterRepository::all() as $printer ) {
			$printers[ (int) $printer['id'] ] = $printer['code'];
		}
		$articles = ArticleRepository::all_by_id();

		$rows = array(
			array( 'Datum', 'ISO-Jahr', 'KW', 'Drucker', 'Artikel', 'Farbe', 'Geplant', 'i.O.', 'Ausschuss', 'Quote', 'Ursache', 'Druckzeit h', 'Plattenwechsel', 'Storniert' ),
		);

		foreach ( PrintJobRepository::all( array( 'limit' => 5000 ) ) as $job ) {
			$metrics = PrintJobRepository::metrics( $job );

			$rows[] = array(
				$job['job_date'],
				$job['iso_year'],
				$job['iso_week'],
				isset( $printers[ (int) $job['printer_id'] ] ) ? $printers[ (int) $job['printer_id'] ] : '',
				isset( $articles[ (int) $job['article_id'] ] ) ? $articles[ (int) $job['article_id'] ]['sku'] : '',
				$job['color'],
				$job['parts_planned'],
				$job['parts_ok'],
				$metrics['parts_scrap'],
				round( $metrics['scrap_rate'], 4 ),
				$job['failure_reason'],
				$job['print_time_h'],
				$job['plate_changes'],
				$job['voided_at'] ? 'ja' : 'nein',
			);
		}

		return $rows;
	}

	/**
	 * Zeilen der Lagerbewegungen.
	 *
	 * @return array
	 */
	protected function rows_stock() {
		$articles = ArticleRepository::all_by_id();

		$rows = array(
			array( 'Zeit', 'Artikel', 'Farbe', 'Menge', 'Art', 'Ort', 'Herkunft', 'Referenz', 'Storniert von', 'Bemerkung' ),
		);

		foreach ( StockRepository::all() as $movement ) {
			$rows[] = array(
				$movement['moved_at'],
				isset( $articles[ (int) $movement['article_id'] ] ) ? $articles[ (int) $movement['article_id'] ]['sku'] : '',
				$movement['color'],
				$movement['qty'],
				$movement['movement_type'],
				$movement['location'],
				$movement['ref_type'],
				$movement['ref_id'],
				$movement['reverses_id'],
				$movement['note'],
			);
		}

		return $rows;
	}

	/**
	 * Zeilen der Kennzahlen.
	 *
	 * @param string $von Von.
	 * @param string $bis Bis.
	 * @return array
	 */
	protected function rows_kpi( $von, $bis ) {
		$metrics     = Reporting::metrics( $von, $bis );
		$definitions = Kpi::definitions();

		$rows = array( array( 'Kennzahl', 'Wert', 'Einheit', 'Ziel', 'Alarm', 'Status' ) );

		foreach ( $definitions as $key => $definition ) {
			$value      = isset( $metrics[ $key ] ) ? $metrics[ $key ] : null;
			$evaluation = Kpi::evaluate( $key, $value );

			$rows[] = array(
				$definition['label'],
				null === $value ? '' : round( $value, 4 ),
				$definition['unit'],
				$definition['target'],
				$definition['alarm'],
				$evaluation['level'],
			);
		}

		return $rows;
	}

	/**
	 * CSV ausliefern.
	 *
	 * @param string $name Dateiname ohne Endung.
	 * @param array  $rows Zeilen.
	 */
	protected function stream( $name, array $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lw16-' . $name . '-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );

		// BOM, damit Excel die Umlaute richtig liest.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		foreach ( $rows as $row ) {
			fputcsv( $out, $row, ';' );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * GPSR-Nachweis einer Charge als PDF.
	 */
	public function handle_gpsr() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_view_reports' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_betrieb_gpsr' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- oben geprüft.
		$batch_id = isset( $_GET['batch'] ) ? (int) $_GET['batch'] : 0;
		$dossier  = Traceability::dossier( $batch_id );

		if ( ! $dossier ) {
			wp_die( esc_html__( 'Charge nicht gefunden.', 'lindenweg16' ) );
		}

		$lines = array(
			array( 'h1', __( 'Chargennachweis nach GPSR', 'lindenweg16' ) ),
			array( 'p', sprintf( /* translators: %s: Chargencode. */ __( 'Chargencode: %s', 'lindenweg16' ), $dossier['batch']['batch_code'] ) ),
			array( 'p', sprintf( /* translators: %s: Datum. */ __( 'Produziert am: %s', 'lindenweg16' ), $dossier['batch']['produced_on'] ) ),
			array( 'h2', __( 'Artikel', 'lindenweg16' ) ),
			array( 'p', $dossier['article'] ? $dossier['article']['sku'] . ' – ' . $dossier['article']['name'] : '—' ),
			array( 'p', sprintf( /* translators: %s: Traglast. */ __( 'Angegebene Traglast: %s kg', 'lindenweg16' ), $dossier['article'] ? $dossier['article']['load_rating_kg'] : '—' ) ),
		);

		if ( $dossier['revision'] ) {
			$revision = $dossier['revision'];
			$lines[]  = array( 'h2', __( 'Druckprofil', 'lindenweg16' ) );
			$lines[]  = array( 'p', sprintf( /* translators: %s: Version. */ __( 'Version: %s', 'lindenweg16' ), $revision['version'] ) );
			$lines[]  = array(
				'p',
				sprintf(
					/* translators: 1: Material, 2: Düse, 3: Schicht, 4: Perimeter, 5: Infill. */
					__( 'Material %1$s · Düse %2$s mm · Schicht %3$s mm · %4$s Perimeter · Infill %5$s', 'lindenweg16' ),
					$revision['material'],
					$revision['nozzle_mm'],
					$revision['layer_mm'],
					$revision['perimeters'],
					$revision['infill_pct']
				),
			);
			$lines[] = array( 'p', sprintf( /* translators: %s: Gewicht. */ __( 'Bauteilgewicht: %s g', 'lindenweg16' ), $revision['weight_g'] ) );
			if ( $revision['orientation_note'] ) {
				$lines[] = array( 'p', sprintf( /* translators: %s: Orientierung. */ __( 'Orientierung: %s', 'lindenweg16' ), $revision['orientation_note'] ) );
			}
		}

		$lines[] = array( 'h2', __( 'Material', 'lindenweg16' ) );
		$lines[] = array(
			'p',
			$dossier['lot']
				? sprintf(
					/* translators: 1: Charge, 2: Hersteller, 3: Material. */
					__( 'Filamentcharge %1$s · %2$s · %3$s', 'lindenweg16' ),
					$dossier['lot']['lot_code'],
					$dossier['lot']['manufacturer'],
					$dossier['lot']['material']
				)
				: __( 'Keine Filamentcharge hinterlegt.', 'lindenweg16' ),
		);

		$lines[] = array( 'h2', __( 'Umfang', 'lindenweg16' ) );
		$lines[] = array( 'p', sprintf( /* translators: %s: Stückzahl. */ __( 'Stückzahl der Charge: %s', 'lindenweg16' ), $dossier['batch']['qty'] ) );
		$lines[] = array( 'p', sprintf( /* translators: %d: Anzahl. */ __( 'Betroffene Bestellungen: %d', 'lindenweg16' ), count( $dossier['orders'] ) ) );

		$this->render_pdf( 'gpsr-' . $dossier['batch']['batch_code'], $lines );
	}

	/**
	 * Verfahrensdokumentation als PDF.
	 */
	public function handle_verfahren() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_view_reports' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_betrieb_verfahren' );

		$this->render_pdf( 'verfahrensdokumentation-betrieb', VerfahrensDokuBetrieb::lines() );
	}

	/**
	 * PDF ausliefern.
	 *
	 * @param string $name  Dateiname.
	 * @param array  $lines Zeilen als [Typ, Text].
	 */
	protected function render_pdf( $name, array $lines ) {
		// Core\Pdf kennt keine Überschriftenmethoden – Größe und Fettdruck
		// bilden die Gliederung ab, so wie es die übrigen Ausgaben des
		// Plugins auch tun.
		$pdf = new Pdf();
		$pdf->enable_ci();

		foreach ( $lines as $line ) {
			list( $type, $text ) = $line;

			switch ( $type ) {
				case 'h1':
					$pdf->text( $text, 18, true );
					$pdf->hr();
					$pdf->space( 6 );
					break;
				case 'h2':
					$pdf->space( 8 );
					$pdf->text( $text, 13, true );
					$pdf->space( 2 );
					break;
				default:
					$pdf->text( $text, 11 );
			}
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="lw16-' . sanitize_file_name( $name ) . '.pdf"' );

		echo $pdf->output(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binärdaten.
		exit;
	}
}
