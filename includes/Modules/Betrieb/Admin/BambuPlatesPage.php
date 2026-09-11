<?php
/**
 * Admin-Oberfläche der Plattenanordnung: mehrere Bauteile automatisch auf
 * einem virtuellen Druckbett anordnen und daraus Bambu-Druckaufträge machen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository;
use Lindenweg16\Modules\Betrieb\Nesting\PlateJobRepository;
use Lindenweg16\Modules\Betrieb\Nesting\PlateService;
use Lindenweg16\Modules\Betrieb\Nesting\SlicingJobRepository;
use Lindenweg16\Modules\Betrieb\Nesting\SlicingService;
use Lindenweg16\Modules\Betrieb\RevisionRepository;

/**
 * „Platte anordnen": Artikel und Mengen wählen, automatisch anordnen
 * lassen (Nesting\PlateService), Ergebnis prüfen, zum Slicen einreihen
 * oder als Projekt-3MF herunterladen und von Hand slicen.
 */
class BambuPlatesPage {

	const NONCE = 'lw16_bambu_plate';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'admin_post_lw16_bambu_build_plate', array( $this, 'handle_build' ) );
		add_action( 'admin_post_lw16_bambu_slice_plate', array( $this, 'handle_slice' ) );
		add_action( 'admin_post_lw16_bambu_download_plate', array( $this, 'handle_download' ) );
	}

	/**
	 * Zugriff prüfen.
	 */
	protected function require_cap() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_manage_production' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Zurück zum Reiter „Platte anordnen".
	 *
	 * @param string $msg Meldung.
	 */
	protected function redirect( $msg ) {
		wp_safe_redirect(
			add_query_arg(
				array( 'page' => BambuPrintersPage::SLUG, 'tab' => 'platte', 'lw16_msg' => rawurlencode( $msg ) ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Platte(n) aus gewählten Artikeln/Mengen anlegen.
	 */
	public function handle_build() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$printer_id = isset( $_POST['bambu_printer_id'] ) ? sanitize_key( wp_unslash( $_POST['bambu_printer_id'] ) ) : '';
		$qty_raw    = isset( $_POST['qty'] ) && is_array( $_POST['qty'] ) ? wp_unslash( $_POST['qty'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$margin     = isset( $_POST['margin_mm'] ) ? (float) str_replace( ',', '.', wp_unslash( $_POST['margin_mm'] ) ) : PlateService::DEFAULT_MARGIN_MM;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$items = array();
		foreach ( $qty_raw as $article_id => $qty ) {
			$items[] = array( 'article_id' => (int) $article_id, 'qty' => (int) $qty );
		}

		$result = PlateService::build_plates( $printer_id, $items, $margin > 0 ? $margin : PlateService::DEFAULT_MARGIN_MM );

		if ( ! $result['ok'] ) {
			$this->redirect( $result['error'] ? $result['error'] : implode( ' ', $result['warnings'] ) );
		}

		$msg = sprintf(
			/* translators: %d: Anzahl Platten. */
			_n( '%d Platte angeordnet.', '%d Platten angeordnet.', count( $result['plate_ids'] ), 'lindenweg16' ),
			count( $result['plate_ids'] )
		);
		if ( $result['warnings'] ) {
			$msg .= ' ' . implode( ' ', $result['warnings'] );
		}

		$this->redirect( $msg );
	}

	/**
	 * Platte zum automatischen Slicen einreihen.
	 */
	public function handle_slice() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$plate_id = isset( $_POST['plate_id'] ) ? (int) $_POST['plate_id'] : 0;
		$result   = SlicingService::enqueue_plate( $plate_id );

		$this->redirect(
			$result['ok']
				? __( 'Platte zum Slicen eingereiht.', 'lindenweg16' )
				: $result['error']
		);
	}

	/**
	 * Projekt-3MF einer Platte herunterladen (für manuelles Slicen).
	 */
	public function handle_download() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$plate_id = isset( $_GET['plate_id'] ) ? (int) $_GET['plate_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$plate    = PlateJobRepository::find( $plate_id );

		if ( ! $plate || ! is_file( $plate->file_path ) ) {
			wp_die( esc_html__( 'Datei nicht gefunden.', 'lindenweg16' ) );
		}

		nocache_headers();
		header( 'Content-Type: model/3mf' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $plate->file_name ) . '"' );
		header( 'Content-Length: ' . filesize( $plate->file_path ) );
		readfile( $plate->file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Inhalt des Reiters.
	 */
	public function render_content() {
		$this->require_cap();

		echo '<p class="description">' . esc_html__( 'Mehrere Artikel und Mengen automatisch auf dem Druckbett des gewählten Bambu-Druckers anordnen (konturbasiertes Nesting, siehe Handbuch) – aus einer oder mehreren Projekt-3MF-Dateien entsteht eine gemeinsame, noch ungeslicte Platte.', 'lindenweg16' ) . '</p>';

		$this->render_build_form();

		echo '<h2>' . esc_html__( 'Angeordnete Platten', 'lindenweg16' ) . '</h2>';

		$plates = PlateJobRepository::list_for_admin( '', 50 );

		if ( ! $plates ) {
			echo '<p>' . esc_html__( 'Noch keine Platte angeordnet.', 'lindenweg16' ) . '</p>';
			return;
		}

		$this->render_plate_list( $plates );
	}

	/**
	 * Formular: Drucker, Artikel und Mengen wählen.
	 */
	protected function render_build_form() {
		$printers = BambuPrinterRepository::all();

		echo '<h2>' . esc_html__( 'Neue Platte anordnen', 'lindenweg16' ) . '</h2>';

		if ( ! $printers ) {
			echo '<p>' . esc_html__( 'Zuerst einen Bambu-Drucker anlegen (Reiter „Drucker").', 'lindenweg16' ) . '</p>';
			return;
		}

		$articles = $this->nestable_articles();

		if ( ! $articles ) {
			echo '<p>' . esc_html__( 'Kein Artikel mit ungeslicter Projekt-3MF und aktiver Revision gefunden.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="lw16_bambu_build_plate">';
		wp_nonce_field( self::NONCE );

		echo '<p><label>' . esc_html__( 'Drucker', 'lindenweg16' ) . ' <select name="bambu_printer_id">';
		foreach ( $printers as $printer ) {
			$bed = BambuPrinterRepository::effective_bed_size( $printer );
			printf(
				'<option value="%s">%s%s</option>',
				esc_attr( $printer->printer_id ),
				esc_html( $printer->name ),
				$bed ? esc_html( sprintf( ' (%d × %d mm)', $bed['width_mm'], $bed['height_mm'] ) ) : esc_html__( ' (Bettmass unbekannt)', 'lindenweg16' )
			);
		}
		echo '</select></label> ';
		printf(
			'<label>%s <input type="text" name="margin_mm" value="%s" size="4"></label></p>',
			esc_html__( 'Sicherheitsabstand (mm)', 'lindenweg16' ),
			esc_attr( (string) PlateService::DEFAULT_MARGIN_MM )
		);

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Artikel', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Menge', 'lindenweg16' ) . '</th></tr></thead><tbody>';
		foreach ( $articles as $article ) {
			printf(
				'<tr><td>%s <code>%s</code></td><td><input type="number" min="0" step="1" name="qty[%d]" value="0" class="small-text"></td></tr>',
				esc_html( $article['name'] ),
				esc_html( $article['sku'] ),
				(int) $article['id']
			);
		}
		echo '</tbody></table>';

		submit_button( __( 'Automatisch anordnen', 'lindenweg16' ) );
		echo '</form>';
	}

	/**
	 * Artikel mit aktiver Revision und ungeslicter Projekt-3MF.
	 *
	 * @return array
	 */
	protected function nestable_articles() {
		$out = array();
		foreach ( ArticleRepository::all() as $article ) {
			if ( 'druck' !== $article['sourcing_type'] ) {
				continue;
			}
			$revision = RevisionRepository::active_for_article( (int) $article['id'] );
			if ( $revision && ! empty( $revision['model_file'] ) && 'modell' === $revision['model_kind'] ) {
				$out[] = $article;
			}
		}
		return $out;
	}

	/**
	 * Liste der bisherigen Platten.
	 *
	 * @param array $plates Datensätze.
	 */
	protected function render_plate_list( array $plates ) {
		$labels = array(
			'entworfen'      => __( 'Entworfen', 'lindenweg16' ),
			'wird_geslict'   => __( 'Wird geslict', 'lindenweg16' ),
			'geslict'        => __( 'Geslict', 'lindenweg16' ),
			'fehlgeschlagen' => __( 'Fehlgeschlagen', 'lindenweg16' ),
		);

		// Jüngster Slicing-Auftrag je Platte: liefert den Grund eines
		// Fehlschlags (vorher nur in der Datenbank sichtbar) und zeigt bei
		// "Wird geslict", ob der Worker die Platte überhaupt schon abgeholt
		// hat - "wartet seit 20 Minuten auf den Worker" ist ein anderer
		// Fehler (Worker läuft nicht / Token) als ein Slicer-Absturz.
		$latest_jobs = SlicingJobRepository::latest_for_plates(
			array_map(
				static function ( $plate ) {
					return (int) $plate->id;
				},
				$plates
			)
		);

		echo '<div class="lw16-scrollx"><table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'ID', 'lindenweg16' ), __( 'Drucker', 'lindenweg16' ), __( 'Masse', 'lindenweg16' ), __( 'Teile', 'lindenweg16' ), __( 'Status', 'lindenweg16' ), __( 'Erstellt', 'lindenweg16' ), '' ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $plates as $plate ) {
			$placements  = json_decode( (string) $plate->placements, true );
			$item_count  = is_array( $placements ) && isset( $placements['items'] ) ? count( $placements['items'] ) : 0;
			$slicing_job = isset( $latest_jobs[ (int) $plate->id ] ) ? $latest_jobs[ (int) $plate->id ] : null;

			echo '<tr>';
			printf( '<td>%d</td>', (int) $plate->id );
			echo '<td><code>' . esc_html( $plate->printer_id ) . '</code></td>';
			printf( '<td>%d × %d mm</td>', (int) $plate->plate_width_mm, (int) $plate->plate_height_mm );
			printf( '<td>%d</td>', $item_count );
			printf(
				'<td><span class="lw16-status lw16-status-%s">%s</span>%s</td>',
				esc_attr( $plate->status ),
				esc_html( isset( $labels[ $plate->status ] ) ? $labels[ $plate->status ] : $plate->status ),
				$this->slicing_detail_html( $plate, $slicing_job ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in slicing_detail_html().
			);
			echo '<td>' . esc_html( get_date_from_gmt( $plate->created_at, 'd.m.Y H:i' ) ) . '</td>';

			echo '<td>';
			printf(
				'<a class="button button-small" href="%s">%s</a> ',
				esc_url(
					wp_nonce_url(
						add_query_arg( array( 'action' => 'lw16_bambu_download_plate', 'plate_id' => (int) $plate->id ), admin_url( 'admin-post.php' ) ),
						self::NONCE
					)
				),
				esc_html__( 'Projekt-3MF', 'lindenweg16' )
			);
			if ( PlateJobRepository::can_enqueue_for_slicing( $plate->status ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
				echo '<input type="hidden" name="action" value="lw16_bambu_slice_plate">';
				printf( '<input type="hidden" name="plate_id" value="%d">', (int) $plate->id );
				wp_nonce_field( self::NONCE );
				printf(
					'<button type="submit" class="button button-small button-primary">%s</button>',
					'fehlgeschlagen' === $plate->status
						? esc_html__( 'Erneut slicen', 'lindenweg16' )
						: esc_html__( 'Automatisch slicen', 'lindenweg16' )
				);
				echo '</form>';
			}
			if ( 'geslict' === $plate->status && $plate->bambu_job_id ) {
				printf( '<span class="description"> %s</span>', esc_html__( 'in der Bambu-Warteschlange', 'lindenweg16' ) );
			}
			echo '</td></tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Zusatzzeile unter dem Plattenstatus: Fehlergrund des letzten
	 * Slice-Laufs bzw. Fortschritt beim Slicen. Gibt fertiges, escaptes
	 * HTML zurück.
	 *
	 * @param object      $plate       Platte.
	 * @param object|null $slicing_job Jüngster Slicing-Auftrag dazu, falls vorhanden.
	 * @return string
	 */
	protected function slicing_detail_html( $plate, $slicing_job ) {
		if ( ! $slicing_job ) {
			return '';
		}

		if ( 'fehlgeschlagen' === $plate->status ) {
			$message = trim( (string) $slicing_job->error_message );
			return sprintf(
				'<br><span class="description" style="color:#b32d2e">%s</span>',
				esc_html( '' !== $message ? $message : __( 'Kein Fehlergrund übermittelt.', 'lindenweg16' ) )
			);
		}

		if ( 'wird_geslict' === $plate->status ) {
			if ( 'queued' === $slicing_job->status ) {
				$text = sprintf(
					/* translators: %s: Zeitpunkt. */
					__( 'wartet seit %s auf den Slicing-Worker', 'lindenweg16' ),
					get_date_from_gmt( $slicing_job->created_at, 'd.m.Y H:i' )
				);
			} elseif ( in_array( $slicing_job->status, array( 'claimed', 'slicing' ), true ) ) {
				$text = sprintf(
					/* translators: %s: Zeitpunkt. */
					__( 'Worker hat die Platte um %s übernommen', 'lindenweg16' ),
					get_date_from_gmt( $slicing_job->started_at ? $slicing_job->started_at : $slicing_job->created_at, 'd.m.Y H:i' )
				);
			} else {
				return '';
			}
			return '<br><span class="description">' . esc_html( $text ) . '</span>';
		}

		return '';
	}
}
