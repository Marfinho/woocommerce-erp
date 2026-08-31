<?php
/**
 * Buchhaltungs-Dashboard: EÜR-Übersicht mit KPIs und Monatsvergleich.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Rendert die grafische Buchhaltungsübersicht.
 */
class AccountingDashboard {

	/**
	 * KPIs und Monatsdaten für das aktuelle Geschäftsjahr berechnen.
	 *
	 * @param int $jahr Kalenderjahr.
	 * @return array
	 */
	public static function get_stats( $jahr ) {
		$from = $jahr . '-01-01';
		$to   = $jahr . '-12-31';

		$report = EuerCalculator::report( $from, $to );

		// Monatliche Aufschlüsselung.
		$monate = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$mon_from = sprintf( '%d-%02d-01', $jahr, $m );
			$mon_to   = gmdate( 'Y-m-t', strtotime( $mon_from ) );
			$mon_rep  = EuerCalculator::report( $mon_from, $mon_to );
			$monate[ $m ] = array(
				'einnahmen' => $mon_rep['einnahmen'],
				'ausgaben'  => $mon_rep['ausgaben_summe'],
				'gewinn'    => $mon_rep['gewinn'],
			);
		}

		// Offene (nicht festgeschriebene) Belege.
		global $wpdb;
		$belege_table = BelegRepository::table();
		$offene_belege = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$belege_table} WHERE festgeschrieben = 0 AND storniert = 0" // phpcs:ignore
		);

		// Fällige Dauerbuchungen.
		$faellige_dauerbuchungen = DauerbuchungRepository::count_due();

		// Anlagevermögen.
		$anlagevermoegen = AnlagenRepository::summe_buchwerte();

		// Kassensaldo.
		$kassensaldo = KassenbuchRepository::aktueller_saldo();

		return array(
			'jahr'                    => $jahr,
			'einnahmen'               => $report['einnahmen'],
			'ausgaben'                => $report['ausgaben_summe'],
			'gewinn'                  => $report['gewinn'],
			'ausgaben_kategorien'     => $report['ausgaben_gruppen'],
			'monate'                  => $monate,
			'offene_belege'           => $offene_belege,
			'faellige_dauerbuchungen' => $faellige_dauerbuchungen,
			'anlagevermoegen'         => $anlagevermoegen,
			'kassensaldo'             => $kassensaldo,
		);
	}

	/**
	 * Dashboard rendern.
	 *
	 * @param int $jahr Kalenderjahr.
	 */
	public static function render( $jahr = 0 ) {
		if ( ! $jahr ) {
			$jahr = (int) gmdate( 'Y' );
		}

		$stats = self::get_stats( $jahr );
		$euro  = array( AccountingModule::class, 'euro' );

		echo '<h2>' . esc_html( sprintf(
			/* translators: %d year */
			__( 'Finanzübersicht %d', 'lindenweg16' ),
			$jahr
		) ) . '</h2>';

		// Jahreswahl.
		$base = admin_url( 'admin.php?page=lw16-buchhaltung&view=dashboard' );
		echo '<p>';
		for ( $y = $jahr - 2; $y <= $jahr + 1; $y++ ) {
			if ( $y === $jahr ) {
				echo '<strong>[' . (int) $y . ']</strong> ';
			} else {
				echo '<a href="' . esc_url( add_query_arg( 'dj', $y, $base ) ) . '">' . (int) $y . '</a> ';
			}
		}
		echo '</p>';

		// ── KPI-Karten ──
		echo '<div class="lw16-kpi-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px;">';

		self::render_kpi(
			__( 'Einnahmen', 'lindenweg16' ),
			call_user_func( $euro, $stats['einnahmen'] ),
			'#4CAF50'
		);
		self::render_kpi(
			__( 'Ausgaben', 'lindenweg16' ),
			call_user_func( $euro, $stats['ausgaben'] ),
			'#F44336'
		);
		self::render_kpi(
			__( 'Gewinn', 'lindenweg16' ),
			call_user_func( $euro, $stats['gewinn'] ),
			$stats['gewinn'] >= 0 ? '#2196F3' : '#F44336'
		);
		self::render_kpi(
			__( 'Offene Belege', 'lindenweg16' ),
			(string) $stats['offene_belege'],
			$stats['offene_belege'] > 0 ? '#FF9800' : '#4CAF50'
		);
		self::render_kpi(
			__( 'Anlagevermögen', 'lindenweg16' ),
			call_user_func( $euro, $stats['anlagevermoegen'] ),
			'#9C27B0'
		);
		self::render_kpi(
			__( 'Kassensaldo', 'lindenweg16' ),
			call_user_func( $euro, $stats['kassensaldo'] ),
			'#009688'
		);

		if ( $stats['faellige_dauerbuchungen'] > 0 ) {
			self::render_kpi(
				__( 'Fällige Buchungen', 'lindenweg16' ),
				(string) $stats['faellige_dauerbuchungen'],
				'#FF5722'
			);
		}

		echo '</div>';

		// ── Monatsvergleich ──
		echo '<h3>' . esc_html__( 'Monatliche Übersicht', 'lindenweg16' ) . '</h3>';
		$monatsnamen = array(
			1 => 'Jan', 2 => 'Feb', 3 => 'Mär', 4 => 'Apr', 5 => 'Mai', 6 => 'Jun',
			7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Dez',
		);

		// Max-Wert für Balkendiagramm.
		$max_val = 1;
		foreach ( $stats['monate'] as $mon ) {
			$max_val = max( $max_val, $mon['einnahmen'], $mon['ausgaben'] );
		}

		echo '<table class="widefat striped" style="max-width:900px">';
		echo '<thead><tr><th>' . esc_html__( 'Monat', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Einnahmen', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Ausgaben', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Gewinn', 'lindenweg16' ) . '</th>';
		echo '<th style="width:200px">' . esc_html__( 'Visualisierung', 'lindenweg16' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $stats['monate'] as $m => $mon ) {
			$bar_e = $max_val > 0 ? round( $mon['einnahmen'] / $max_val * 100 ) : 0;
			$bar_a = $max_val > 0 ? round( $mon['ausgaben'] / $max_val * 100 ) : 0;

			echo '<tr>';
			echo '<td><strong>' . esc_html( $monatsnamen[ $m ] ) . '</strong></td>';
			echo '<td style="text-align:right;color:#4CAF50">' . esc_html( call_user_func( $euro, $mon['einnahmen'] ) ) . '</td>';
			echo '<td style="text-align:right;color:#F44336">' . esc_html( call_user_func( $euro, $mon['ausgaben'] ) ) . '</td>';
			$g_color = $mon['gewinn'] >= 0 ? '#2196F3' : '#F44336';
			echo '<td style="text-align:right;font-weight:bold;color:' . esc_attr( $g_color ) . '">' . esc_html( call_user_func( $euro, $mon['gewinn'] ) ) . '</td>';
			echo '<td>';
			echo '<div style="display:flex;flex-direction:column;gap:2px">';
			echo '<div style="height:8px;background:#4CAF50;border-radius:2px;width:' . (int) $bar_e . '%" title="' . esc_attr( call_user_func( $euro, $mon['einnahmen'] ) ) . '"></div>';
			echo '<div style="height:8px;background:#F44336;border-radius:2px;width:' . (int) $bar_a . '%" title="' . esc_attr( call_user_func( $euro, $mon['ausgaben'] ) ) . '"></div>';
			echo '</div>';
			echo '</td>';
			echo '</tr>';
		}

		// Summenzeile.
		echo '<tr style="font-weight:bold;border-top:2px solid #333">';
		echo '<td>' . esc_html__( 'Gesamt', 'lindenweg16' ) . '</td>';
		echo '<td style="text-align:right;color:#4CAF50">' . esc_html( call_user_func( $euro, $stats['einnahmen'] ) ) . '</td>';
		echo '<td style="text-align:right;color:#F44336">' . esc_html( call_user_func( $euro, $stats['ausgaben'] ) ) . '</td>';
		$g_color = $stats['gewinn'] >= 0 ? '#2196F3' : '#F44336';
		echo '<td style="text-align:right;color:' . esc_attr( $g_color ) . '">' . esc_html( call_user_func( $euro, $stats['gewinn'] ) ) . '</td>';
		echo '<td></td>';
		echo '</tr>';
		echo '</tbody></table>';

		// ── Top-Ausgabenkategorien ──
		if ( ! empty( $stats['ausgaben_kategorien'] ) ) {
			echo '<h3 style="margin-top:24px">' . esc_html__( 'Ausgaben nach Kategorie', 'lindenweg16' ) . '</h3>';
			$max_kat = max( array_values( $stats['ausgaben_kategorien'] ) );

			echo '<table class="widefat striped" style="max-width:600px">';
			echo '<thead><tr><th>' . esc_html__( 'Kategorie', 'lindenweg16' ) . '</th>';
			echo '<th style="text-align:right">' . esc_html__( 'Betrag', 'lindenweg16' ) . '</th>';
			echo '<th style="width:200px"></th></tr></thead><tbody>';

			arsort( $stats['ausgaben_kategorien'] );
			$colors = array( '#F44336', '#E91E63', '#9C27B0', '#673AB7', '#3F51B5', '#2196F3', '#00BCD4', '#009688', '#FF9800' );
			$ci = 0;
			foreach ( $stats['ausgaben_kategorien'] as $kat => $summe ) {
				$bar = $max_kat > 0 ? round( $summe / $max_kat * 100 ) : 0;
				$c   = $colors[ $ci % count( $colors ) ];
				echo '<tr>';
				echo '<td>' . esc_html( $kat ) . '</td>';
				echo '<td style="text-align:right">' . esc_html( call_user_func( $euro, $summe ) ) . '</td>';
				echo '<td><div style="height:12px;background:' . esc_attr( $c ) . ';border-radius:2px;width:' . (int) $bar . '%"></div></td>';
				echo '</tr>';
				$ci++;
			}
			echo '</tbody></table>';
		}

		// ── Hinweis §19 UStG ──
		if ( (int) Settings::get( 'kleinunternehmer', 1 ) === 1 ) {
			echo '<p class="description" style="margin-top:16px">'
				. esc_html__( 'Kleinunternehmer §19 UStG: keine Umsatzsteuer ausgewiesen, alle Beträge brutto.', 'lindenweg16' )
				. '</p>';
		}
	}

	/**
	 * Einzelne KPI-Karte rendern.
	 *
	 * @param string $label Bezeichnung.
	 * @param string $value Wert.
	 * @param string $color Akzentfarbe.
	 */
	protected static function render_kpi( $label, $value, $color ) {
		echo '<div style="background:#fff;border:1px solid #e0e0e0;border-left:4px solid ' . esc_attr( $color ) . ';border-radius:4px;padding:16px;">';
		echo '<div style="font-size:11px;color:#6E7882;text-transform:uppercase;letter-spacing:0.5px">' . esc_html( $label ) . '</div>';
		echo '<div style="font-size:22px;font-weight:bold;margin-top:4px;color:#1A2B3C">' . esc_html( $value ) . '</div>';
		echo '</div>';
	}
}
