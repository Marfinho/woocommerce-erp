<?php
/**
 * Druckjournal: Liste, Schnellerfassung, Storno.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\WorkOrderRepository;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\FilamentRepository;
use Lindenweg16\Modules\Betrieb\IsoWeek;
use Lindenweg16\Modules\Betrieb\PrinterRepository;
use Lindenweg16\Modules\Betrieb\PrintJobRepository;
use Lindenweg16\Modules\Betrieb\RevisionRepository;

/**
 * Rendert das Druckjournal.
 */
class PrintJobsPage {

	const NONCE = 'lw16_betrieb_printjob';
	const SLUG  = 'lw16-betrieb-druckjournal';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_druckjournal', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_betrieb_save_printjob', array( $this, 'handle_save' ) );
		add_action( 'admin_post_lw16_betrieb_void_printjob', array( $this, 'handle_void' ) );
	}

	/**
	 * Zugriff prüfen.
	 */
	protected function require_cap( $cap = 'lw16_manage_production' ) {
		if ( ! Roles::current_user_can_betrieb( $cap ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Druckjob erfassen.
	 */
	public function handle_save() {
		$this->require_cap( 'lw16_capture' );
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$input  = array_map( 'wp_unslash', array_filter( (array) $_POST, 'is_scalar' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = PrintJobRepository::validate( $input );

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error' );
		}

		$id = PrintJobRepository::insert( $result['data'] );

		if ( ! $id ) {
			$this->redirect( __( 'Druckjob konnte nicht gespeichert werden.', 'lindenweg16' ), 'error' );
		}

		// Kam der Job aus einem Druckauftrag, ist der damit erledigt. Der
		// Zugang ist oben schon gebucht - der Auftrag wird nur geschlossen.
		$order_id = isset( $input['work_order_id'] ) ? (int) $input['work_order_id'] : 0;
		$message  = __( 'Druckjob erfasst, Lagerzugang und Charge gebucht.', 'lindenweg16' );

		if ( $order_id && WorkOrderRepository::close_by_print_job( $order_id, $id, (float) $result['data']['parts_ok'] ) ) {
			$message = __( 'Druckjob erfasst und Druckauftrag fertiggemeldet.', 'lindenweg16' );
		}

		$this->redirect( $message, 'success' );
	}

	/**
	 * Druckjob stornieren.
	 */
	public function handle_void() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$id     = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = PrintJobRepository::void( $id, $reason );
		$this->redirect( $result['message'], $result['success'] ? 'success' : 'error' );
	}

	/**
	 * Zurück zur Seite.
	 *
	 * @param string $msg  Meldung.
	 * @param string $type Typ.
	 */
	protected function redirect( $msg, $type ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::SLUG,
					'lw16_msg' => rawurlencode( $msg ),
					'lw16_typ' => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		// Wie im Menue: Wer Druckjobs bucht, darf das Journal auch sehen.
		$this->require_cap( 'lw16_capture' );

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Druckjournal', 'lindenweg16' ) . '</h1>';

		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['lw16_msg'] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				isset( $_GET['lw16_typ'] ) && 'error' === $_GET['lw16_typ'] ? 'error' : 'success',
				esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) ) )
			);
		}
		$filter_printer = isset( $_GET['printer_id'] ) ? (int) $_GET['printer_id'] : 0;
		$filter_article = isset( $_GET['article_id'] ) ? (int) $_GET['article_id'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification

		$printers = PrinterRepository::all();

		// Zwei Listen mit Absicht: Neu drucken laesst sich nur, was noch
		// angeboten wird - aber ein alter Druckjob muss seinen Artikel
		// weiterhin beim Namen nennen koennen, auch einen ausgelaufenen.
		$articles     = ArticleRepository::selectable();
		$all_articles = ArticleRepository::all();

		if ( ! $printers || ! $articles ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Vor dem ersten Druckjob braucht es mindestens einen Drucker und einen Artikel.', 'lindenweg16' ) . '</p></div>';
		}

		printf(
			'<p><a class="button button-primary" href="%s">%s</a> %s</p>',
			esc_url( home_url( '/betrieb-erfassen/' ) ),
			esc_html__( 'Mobile Schnellerfassung öffnen', 'lindenweg16' ),
			esc_html__( 'Am Drucker mit dem Handy: fünf Eingaben, alles andere vorbelegt.', 'lindenweg16' )
		);

		$this->render_form( $printers, $articles );
		$this->render_list( $printers, $all_articles, $filter_printer, $filter_article );

		echo '</div>';
	}

	/**
	 * Erfassungsformular.
	 *
	 * @param array $printers Drucker.
	 * @param array $articles Artikel.
	 */
	protected function render_form( array $printers, array $articles ) {
		if ( ! $printers || ! $articles ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Druckjob erfassen', 'lindenweg16' ) . '</h2>';

		// Kommt der Aufruf aus einem Druckauftrag, ist alles Geplante schon
		// bekannt. Es noch einmal abzutippen waere die Stelle, an der sich
		// Zahlendreher einschleichen.
		// phpcs:ignore WordPress.Security.NonceVerification
		$order = isset( $_GET['work_order'] ) ? WorkOrderRepository::find( (int) $_GET['work_order'] ) : null;

		if ( $order && ( 'druck' !== $order['kind'] || ! in_array( $order['status'], WorkOrderRepository::OPEN_STATES, true ) ) ) {
			$order = null;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_save_printjob" />';

		if ( $order ) {
			printf( '<input type="hidden" name="work_order_id" value="%d" />', (int) $order['id'] );
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %s: Auftragscode. */
					__( 'Aus Druckauftrag %s. Mit dem Buchen wird er fertiggemeldet.', 'lindenweg16' ),
					$order['code']
				) )
			);
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="lw16-job_date">%s</label></th><td><input type="date" name="job_date" id="lw16-job_date" value="%s" /></td></tr>',
			esc_html__( 'Datum', 'lindenweg16' ),
			esc_attr( gmdate( 'Y-m-d' ) )
		);

		echo '<tr><th scope="row"><label for="lw16-printer">' . esc_html__( 'Drucker', 'lindenweg16' ) . '</label></th><td><select name="printer_id" id="lw16-printer">';
		foreach ( $printers as $printer ) {
			printf(
				'<option value="%d"%s>%s – %s</option>',
				(int) $printer['id'],
				$order ? selected( (int) $order['printer_id'], (int) $printer['id'], false ) : '',
				esc_html( $printer['code'] ),
				esc_html( trim( (string) $printer['manufacturer'] . ' ' . (string) $printer['model'] ) )
			);
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="lw16-article">' . esc_html__( 'Artikel', 'lindenweg16' ) . '</label></th><td><select name="article_id" id="lw16-article">';
		// Eine Abfrage fuer alle aktiven Revisionen statt einer je Artikel.
		$revisions = RevisionRepository::active_by_article();
		foreach ( $articles as $article ) {
			if ( in_array( $article['sourcing_type'], array( 'zukauf', 'montage' ), true ) ) {
				continue;
			}
			$revision = isset( $revisions[ (int) $article['id'] ] ) ? $revisions[ (int) $article['id'] ] : null;
			printf(
				'<option value="%d"%s>%s – %s%s</option>',
				(int) $article['id'],
				$order ? selected( (int) $order['article_id'], (int) $article['id'], false ) : '',
				esc_html( $article['sku'] ),
				esc_html( $article['name'] ),
				$revision ? ' (' . esc_html( $revision['version'] ) . ')' : ' – ' . esc_html__( 'keine aktive Revision', 'lindenweg16' )
			);
		}
		echo '</select><p class="description">' . esc_html__( 'Die aktive Revision wird automatisch mitgeschrieben – sie bestimmt Gewicht und Druckzeit.', 'lindenweg16' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="lw16-color">' . esc_html__( 'Farbe', 'lindenweg16' ) . '</label></th><td><select name="color" id="lw16-color">';
		foreach ( ColorRegistry::all() as $slug => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $slug ),
				selected( $order ? (string) $order['color'] : ColorRegistry::default_color(), $slug, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		$geplant = $order ? (string) (int) $order['qty_planned'] : '';

		foreach ( array(
			array( 'parts_planned', __( 'Teile geplant', 'lindenweg16' ), $geplant ),
			array( 'parts_ok', __( 'Teile in Ordnung', 'lindenweg16' ), $geplant ),
			array( 'print_time_h', __( 'Druckzeit (h)', 'lindenweg16' ), '' ),
			array( 'plate_changes', __( 'Plattenwechsel', 'lindenweg16' ), '1' ),
		) as $field ) {
			printf(
				'<tr><th scope="row"><label for="lw16-%1$s">%2$s</label></th><td><input type="text" inputmode="decimal" name="%1$s" id="lw16-%1$s" value="%3$s" class="small-text" /></td></tr>',
				esc_attr( $field[0] ),
				esc_html( $field[1] ),
				esc_attr( $field[2] )
			);
		}

		echo '<tr><th scope="row"><label for="lw16-failure">' . esc_html__( 'Fehlerursache', 'lindenweg16' ) . '</label></th><td><select name="failure_reason" id="lw16-failure">';
		foreach ( PrintJobRepository::failure_labels() as $key => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Bei Ausschuss Pflicht – ohne Ursache lässt sich die Quote später nicht auswerten.', 'lindenweg16' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="lw16-note">' . esc_html__( 'Bemerkung', 'lindenweg16' ) . '</label></th>';
		echo '<td><textarea name="note" id="lw16-note" rows="2" class="large-text"></textarea></td></tr>';

		echo '</tbody></table>';
		printf( '<p><button class="button button-primary">%s</button></p></form>', esc_html__( 'Druckjob buchen', 'lindenweg16' ) );
	}

	/**
	 * Journalliste.
	 *
	 * @param array $printers       Drucker.
	 * @param array $articles       Artikel.
	 * @param int   $filter_printer Filter.
	 * @param int   $filter_article Filter.
	 */
	protected function render_list( array $printers, array $articles, $filter_printer, $filter_article ) {
		$jobs = PrintJobRepository::all(
			array(
				'printer_id' => $filter_printer,
				'article_id' => $filter_article,
				'limit'      => 100,
			)
		);

		$printer_map = array();
		foreach ( $printers as $printer ) {
			$printer_map[ (int) $printer['id'] ] = $printer['code'];
		}
		$article_map = array();
		foreach ( $articles as $article ) {
			$article_map[ (int) $article['id'] ] = $article['sku'];
		}

		echo '<h2>' . esc_html__( 'Journal', 'lindenweg16' ) . '</h2>';

		// Filter.
		echo '<form method="get" style="margin:12px 0">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		echo '<select name="printer_id"><option value="0">' . esc_html__( 'alle Drucker', 'lindenweg16' ) . '</option>';
		foreach ( $printers as $printer ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $printer['id'],
				selected( $filter_printer, (int) $printer['id'], false ),
				esc_html( $printer['code'] )
			);
		}
		echo '</select> <select name="article_id"><option value="0">' . esc_html__( 'alle Artikel', 'lindenweg16' ) . '</option>';
		foreach ( $articles as $article ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $article['id'],
				selected( $filter_article, (int) $article['id'], false ),
				esc_html( $article['sku'] )
			);
		}
		echo '</select> <button class="button">' . esc_html__( 'Filtern', 'lindenweg16' ) . '</button>';
		printf(
			' <a class="button" href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lw16_betrieb_export&what=print_jobs' ), 'lw16_betrieb_export' ) ),
			esc_html__( 'CSV-Export', 'lindenweg16' )
		);
		echo '</form>';

		if ( ! $jobs ) {
			echo '<p>' . esc_html__( 'Noch keine Druckjobs erfasst.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Datum', 'lindenweg16' ),
			__( 'KW', 'lindenweg16' ),
			__( 'Drucker', 'lindenweg16' ),
			__( 'Artikel', 'lindenweg16' ),
			__( 'Farbe', 'lindenweg16' ),
			__( 'geplant', 'lindenweg16' ),
			__( 'i.O.', 'lindenweg16' ),
			__( 'Ausschuss', 'lindenweg16' ),
			__( 'Quote', 'lindenweg16' ),
			__( 'Ursache', 'lindenweg16' ),
			__( 'Druckzeit', 'lindenweg16' ),
			__( 'Wechsel', 'lindenweg16' ),
			__( 'Teile/Wechsel', 'lindenweg16' ),
			'',
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$failures = PrintJobRepository::failure_labels();

		foreach ( $jobs as $job ) {
			$metrics = PrintJobRepository::metrics( $job );
			$voided  = ! empty( $job['voided_at'] );

			printf( '<tr%s>', $voided ? ' style="opacity:.55"' : '' );
			printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y', $job['job_date'] ) ) );
			printf( '<td>%s</td>', esc_html( IsoWeek::label( $job['iso_year'], $job['iso_week'] ) ) );
			printf( '<td>%s</td>', esc_html( isset( $printer_map[ (int) $job['printer_id'] ] ) ? $printer_map[ (int) $job['printer_id'] ] : '—' ) );
			printf( '<td>%s</td>', esc_html( isset( $article_map[ (int) $job['article_id'] ] ) ? $article_map[ (int) $job['article_id'] ] : '—' ) );
			printf( '<td>%s</td>', esc_html( ColorRegistry::label( $job['color'] ) ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $job['parts_planned'], 0 ) ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $job['parts_ok'], 0 ) ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $metrics['parts_scrap'], 0 ) ) );

			$quote = $metrics['scrap_rate'] * 100;
			printf(
				'<td><span class="lw16-ampel lw16-ampel--%s">%s %%</span></td>',
				esc_attr( $quote > 10 ? 'rot' : ( $quote > 6 ? 'gelb' : 'gruen' ) ),
				esc_html( number_format_i18n( $quote, 1 ) )
			);

			printf( '<td>%s</td>', esc_html( isset( $failures[ $job['failure_reason'] ] ) ? $failures[ $job['failure_reason'] ] : $job['failure_reason'] ) );
			printf( '<td>%s h</td>', esc_html( number_format_i18n( (float) $job['print_time_h'], 2 ) ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $job['plate_changes'], 0 ) ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $metrics['parts_per_plate_change'], 1 ) ) );

			echo '<td>';
			if ( $voided ) {
				printf( '<em>%s</em>', esc_html__( 'storniert', 'lindenweg16' ) );
			} else {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="action" value="lw16_betrieb_void_printjob" />';
				printf( '<input type="hidden" name="job_id" value="%d" />', (int) $job['id'] );
				printf(
					'<input type="text" name="reason" placeholder="%s" size="12" /> <button class="button button-small">%s</button>',
					esc_attr__( 'Begründung', 'lindenweg16' ),
					esc_html__( 'Stornieren', 'lindenweg16' )
				);
				echo '</form>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
