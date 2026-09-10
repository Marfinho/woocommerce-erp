<?php
/**
 * Admin-Übersicht der Bambu-Lab-Anbindung: Druckaufträge.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\Bambu\BambuJobRepository;

/**
 * „Aufträge": Liste aller 3MF-Druckjobs mit Statusfilter und Live-Fortschritt.
 */
class BambuJobsPage {

	/**
	 * Anzeige-Bezeichnungen je Status.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'queued'    => __( 'Wartend', 'lindenweg16' ),
			'claimed'   => __( 'Geclaimt', 'lindenweg16' ),
			'uploading' => __( 'Wird übertragen', 'lindenweg16' ),
			'printing'  => __( 'Druckt', 'lindenweg16' ),
			'completed' => __( 'Fertig', 'lindenweg16' ),
			'failed'    => __( 'Fehler', 'lindenweg16' ),
			'cancelled' => __( 'Storniert', 'lindenweg16' ),
		);
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
	 * Nur der Inhalt, ohne eigene Überschrift – zur Einbettung als Reiter
	 * auf der gemeinsamen Bambu-Seite (siehe BambuPrintersPage::render()).
	 *
	 * @param string $base Grund-URL für die Statusfilter-Links.
	 */
	public function render_content( $base ) {
		$this->require_cap();

		// phpcs:ignore WordPress.Security.NonceVerification
		$filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		$this->render_filter( $filter, $base );

		$jobs = BambuJobRepository::list_for_admin( $filter, 200 );

		if ( ! $jobs ) {
			echo '<p>' . esc_html__( 'Keine Druckaufträge in dieser Ansicht.', 'lindenweg16' ) . '</p>';
			return;
		}

		$labels = self::status_labels();

		echo '<div class="lw16-scrollx"><table class="widefat striped"><thead><tr>';
		foreach (
			array(
				__( 'ID', 'lindenweg16' ),
				__( 'Drucker', 'lindenweg16' ),
				__( 'Datei', 'lindenweg16' ),
				__( 'Fertigungsauftrag', 'lindenweg16' ),
				__( 'Status', 'lindenweg16' ),
				__( 'Fortschritt', 'lindenweg16' ),
				__( 'Restzeit', 'lindenweg16' ),
				__( 'Erstellt', 'lindenweg16' ),
				__( 'Fertig', 'lindenweg16' ),
				__( 'Ist-Druckzeit', 'lindenweg16' ),
				__( 'Fehler', 'lindenweg16' ),
			) as $head
		) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $jobs as $job ) {
			echo '<tr>';
			printf( '<td>%d</td>', (int) $job->id );
			echo '<td><code>' . esc_html( $job->printer_id ) . '</code></td>';
			echo '<td>' . esc_html( $job->file_name ) . '</td>';
			echo '<td>' . ( $job->druckauftrag_id ? '#' . esc_html( (string) (int) $job->druckauftrag_id ) : '—' ) . '</td>';
			printf(
				'<td><span class="lw16-status lw16-status-%s">%s</span></td>',
				esc_attr( $job->status ),
				esc_html( isset( $labels[ $job->status ] ) ? $labels[ $job->status ] : $job->status )
			);
			echo '<td>' . ( null !== $job->progress_percent ? esc_html( (string) (int) $job->progress_percent ) . ' %' : '—' ) . '</td>';
			echo '<td>' . ( null !== $job->remaining_minutes ? esc_html( (string) (int) $job->remaining_minutes ) . ' min' : '—' ) . '</td>';
			echo '<td>' . esc_html( $this->local_time( $job->created_at ) ) . '</td>';
			echo '<td>' . ( $job->completed_at ? esc_html( $this->local_time( $job->completed_at ) ) : '—' ) . '</td>';
			echo '<td>' . ( null !== $job->actual_print_minutes ? esc_html( number_format_i18n( (float) $job->actual_print_minutes / 60, 1 ) ) . ' h' : '—' ) . '</td>';
			echo '<td>' . ( $job->error_message ? esc_html( $job->error_message ) : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';

		printf( '<p class="description">%s</p>', esc_html__( 'Gezeigt werden die letzten 200 Einträge des gewählten Filters, neueste zuerst.', 'lindenweg16' ) );
	}

	/**
	 * Filterleiste.
	 *
	 * @param string $filter Aktueller Filter.
	 * @param string $base   Grund-URL.
	 */
	protected function render_filter( $filter, $base ) {
		$ansichten = array( '' => __( 'Alle', 'lindenweg16' ) ) + array(
			'queued'    => __( 'Wartend', 'lindenweg16' ),
			'printing'  => __( 'Druckt', 'lindenweg16' ),
			'completed' => __( 'Fertig', 'lindenweg16' ),
			'failed'    => __( 'Fehler', 'lindenweg16' ),
		);

		echo '<nav class="lw16-tabs">';
		foreach ( $ansichten as $key => $titel ) {
			printf(
				'<a href="%s" class="%s">%s</a> ',
				esc_url( '' === $key ? $base : add_query_arg( 'status', $key, $base ) ),
				$filter === $key ? 'active' : '',
				esc_html( $titel )
			);
		}
		echo '</nav>';
	}

	/**
	 * GMT-Datetime aus der Datenbank für die Anzeige in Blog-Zeitzone.
	 *
	 * @param string $mysql_gmt GMT-Datetime.
	 * @return string
	 */
	protected function local_time( $mysql_gmt ) {
		if ( ! $mysql_gmt ) {
			return '';
		}
		return get_date_from_gmt( $mysql_gmt, 'd.m.Y H:i' );
	}
}
