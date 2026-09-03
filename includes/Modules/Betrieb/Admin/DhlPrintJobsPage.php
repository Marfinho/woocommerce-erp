<?php
/**
 * Admin-Übersicht der DHL-Druck-Warteschlange: Druckjobs.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\Dhl\Print\DhlPrintJobRepository;

/**
 * „Druckjobs": Liste aller Jobs mit Statusfilter.
 */
class DhlPrintJobsPage {

	const SLUG = 'lw16-betrieb-dhl-druckjobs';

	/**
	 * Anzeige-Bezeichnungen je Status.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'queued'    => __( 'Wartend', 'lindenweg16' ),
			'claimed'   => __( 'Geclaimt', 'lindenweg16' ),
			'printing'  => __( 'Druckt', 'lindenweg16' ),
			'printed'   => __( 'Gedruckt', 'lindenweg16' ),
			'failed'    => __( 'Fehler', 'lindenweg16' ),
			'cancelled' => __( 'Storniert', 'lindenweg16' ),
		);
	}

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_dhl_druckjobs', array( $this, 'render' ) );
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
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		echo '<h1>' . esc_html__( 'Druckjobs', 'lindenweg16' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification
		$filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$base   = admin_url( 'admin.php?page=' . self::SLUG );

		$this->render_filter( $filter, $base );

		$jobs = DhlPrintJobRepository::list_for_admin( $filter, 200 );

		if ( ! $jobs ) {
			echo '<p>' . esc_html__( 'Keine Druckjobs in dieser Ansicht.', 'lindenweg16' ) . '</p>';
			return;
		}

		$labels = self::status_labels();

		echo '<div class="lw16-scrollx"><table class="widefat striped"><thead><tr>';
		foreach (
			array(
				__( 'ID', 'lindenweg16' ),
				__( 'Bestellung', 'lindenweg16' ),
				__( 'Drucker', 'lindenweg16' ),
				__( 'Status', 'lindenweg16' ),
				__( 'Versuche', 'lindenweg16' ),
				__( 'Erstellt', 'lindenweg16' ),
				__( 'Gedruckt', 'lindenweg16' ),
				__( 'Fehler', 'lindenweg16' ),
			) as $head
		) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $jobs as $job ) {
			echo '<tr>';
			printf( '<td>%d%s</td>', (int) $job->id, $job->is_test ? ' <span class="description">(' . esc_html__( 'Test', 'lindenweg16' ) . ')</span>' : '' );
			echo '<td>' . ( $job->order_id ? '#' . esc_html( (string) (int) $job->order_id ) : '—' ) . '</td>';
			echo '<td><code>' . esc_html( $job->printer_id ) . '</code></td>';
			printf(
				'<td><span class="lw16-status lw16-status-%s">%s</span></td>',
				esc_attr( $job->status ),
				esc_html( isset( $labels[ $job->status ] ) ? $labels[ $job->status ] : $job->status )
			);
			echo '<td>' . (int) $job->attempts . '</td>';
			echo '<td>' . esc_html( $this->local_time( $job->created_at ) ) . '</td>';
			echo '<td>' . ( $job->printed_at ? esc_html( $this->local_time( $job->printed_at ) ) : '—' ) . '</td>';
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
			'queued'   => __( 'Wartend', 'lindenweg16' ),
			'printed'  => __( 'Gedruckt', 'lindenweg16' ),
			'failed'   => __( 'Fehler', 'lindenweg16' ),
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
		$timestamp = strtotime( $mysql_gmt . ' UTC' );

		return $timestamp ? date_i18n( 'd.m.Y H:i', $timestamp ) : '';
	}
}
