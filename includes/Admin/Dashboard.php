<?php
/**
 * Erweitertes Dashboard mit KPIs und Schnellzugriff.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;
use Lindenweg16\Modules\Accounting\EuerCalculator;
use Lindenweg16\Modules\Inventory\Repository;
use Lindenweg16\Modules\Inventory\StockManager;

/**
 * Rendert das Dashboard mit KPI-Karten und Übersichts-Widgets.
 */
class Dashboard {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_render_dashboard', array( $this, 'render' ), 5 );
	}

	/**
	 * Dashboard rendern.
	 */
	public function render() {
		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Lindenweg16 Dashboard', 'lindenweg16' ) . '</h1>';

		$this->render_kpi_cards();
		$this->render_low_stock_warning();
		$this->render_recent_orders();

		do_action( 'lw16_dashboard_widgets' );

		echo '</div>';
	}

	/**
	 * KPI-Karten.
	 */
	protected function render_kpi_cards() {
		$cache_key = 'lw16_dashboard_kpis';
		$kpis      = get_transient( $cache_key );

		if ( false === $kpis ) {
			$kpis = $this->calculate_kpis();
			set_transient( $cache_key, $kpis, 15 * MINUTE_IN_SECONDS );
		}

		$steuersatz = (float) Settings::get( 'steuersatz_ruecklage', 25 );
		$ruecklage  = EuerCalculator::ruecklage( $steuersatz );

		echo '<div class="lw16-cards">';

		printf(
			'<div class="lw16-card lw16-card-highlight"><h2>%s</h2><p class="lw16-big">%s</p><p class="lw16-sub">%s</p></div>',
			esc_html__( 'Umsatz (aktueller Monat)', 'lindenweg16' ),
			esc_html( self::euro( $kpis['umsatz_monat'] ) ),
			esc_html( sprintf(
				/* translators: %s: Umsatz */
				__( 'Laufendes Jahr: %s', 'lindenweg16' ),
				self::euro( $kpis['umsatz_jahr'] )
			) )
		);

		printf(
			'<div class="lw16-card"><h2>%s</h2><p class="lw16-big">%d</p><p class="lw16-sub">%s</p></div>',
			esc_html__( 'Offene Bestellungen', 'lindenweg16' ),
			$kpis['offene_bestellungen'],
			esc_html( sprintf(
				/* translators: %d: Anzahl */
				__( '%d neue heute', 'lindenweg16' ),
				$kpis['bestellungen_heute']
			) )
		);

		$stock_class = $kpis['lagerwarnungen'] > 0 ? ' lw16-card-warn' : '';
		printf(
			'<div class="lw16-card%s"><h2>%s</h2><p class="lw16-big">%d</p><p class="lw16-sub"><a href="%s">%s</a></p></div>',
			$stock_class,
			esc_html__( 'Lagerwarnungen', 'lindenweg16' ),
			$kpis['lagerwarnungen'],
			esc_url( admin_url( 'admin.php?page=lw16-lager' ) ),
			esc_html__( 'Lager ansehen', 'lindenweg16' )
		);

		printf(
			'<div class="lw16-card"><h2>%s</h2><p class="lw16-big">%d</p><p class="lw16-sub"><a href="%s">%s</a></p></div>',
			esc_html__( 'Offene Tickets', 'lindenweg16' ),
			$kpis['offene_tickets'],
			esc_url( admin_url( 'admin.php?page=lw16-support' ) ),
			esc_html__( 'Support öffnen', 'lindenweg16' )
		);

		printf(
			'<div class="lw16-card lw16-card-highlight"><h2>%s</h2><p class="lw16-big">%s</p><p class="lw16-sub">%s <strong>%s</strong></p><p class="lw16-hint">%s</p></div>',
			esc_html__( 'Steuer-Rücklage', 'lindenweg16' ),
			esc_html( self::euro( $ruecklage['ruecklage'] ) ),
			esc_html__( 'Gewinn lfd. Jahr:', 'lindenweg16' ),
			esc_html( self::euro( $ruecklage['gewinn'] ) ),
			esc_html__( 'Richtwert – ersetzt keine steuerliche Beratung.', 'lindenweg16' )
		);

		printf(
			'<div class="lw16-card"><h2>%s</h2><p class="lw16-big">%d</p><p class="lw16-sub"><a href="%s">%s</a></p></div>',
			esc_html__( 'Offene Picklisten', 'lindenweg16' ),
			$kpis['offene_picklisten'],
			esc_url( admin_url( 'admin.php?page=lw16-lager&view=picklisten' ) ),
			esc_html__( 'Picklisten ansehen', 'lindenweg16' )
		);

		echo '</div>';
	}

	/**
	 * KPIs berechnen.
	 *
	 * @return array
	 */
	protected function calculate_kpis() {
		global $wpdb;

		$jahr       = (int) gmdate( 'Y' );
		$monat      = (int) gmdate( 'n' );
		$monat_from = sprintf( '%04d-%02d-01', $jahr, $monat );
		$monat_to   = gmdate( 'Y-m-t' );
		$jahr_from  = $jahr . '-01-01';
		$jahr_to    = $jahr . '-12-31';

		$umsatz_monat = EuerCalculator::einnahmen( $monat_from, $monat_to );
		$umsatz_jahr  = EuerCalculator::einnahmen( $jahr_from, $jahr_to );

		$offene = wc_get_orders( array(
			'status' => array( 'wc-processing', 'wc-on-hold', 'wc-pending' ),
			'limit'  => -1,
			'return' => 'ids',
		) );

		$heute = wc_get_orders( array(
			'status'       => array( 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-completed' ),
			'date_created' => gmdate( 'Y-m-d' ) . '...' . gmdate( 'Y-m-d' ),
			'limit'        => -1,
			'return'       => 'ids',
		) );

		$produkte      = Repository::get_products();
		$lagerwarnungen = 0;
		foreach ( $produkte as $p ) {
			$ampel = StockManager::ampel( $p );
			if ( 'gruen' !== $ampel ) {
				$lagerwarnungen++;
			}
		}

		$offene_tickets   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lw16_tickets WHERE status NOT IN ('geloest')" ); // phpcs:ignore
		$offene_picklisten = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lw16_pickliste WHERE status = 'offen'" ); // phpcs:ignore

		return array(
			'umsatz_monat'        => $umsatz_monat,
			'umsatz_jahr'         => $umsatz_jahr,
			'offene_bestellungen' => count( $offene ),
			'bestellungen_heute'  => count( $heute ),
			'lagerwarnungen'      => $lagerwarnungen,
			'offene_tickets'      => $offene_tickets,
			'offene_picklisten'   => $offene_picklisten,
		);
	}

	/**
	 * Lagerwarnungen-Widget.
	 */
	protected function render_low_stock_warning() {
		$produkte = Repository::get_products();
		$warnings = array();
		foreach ( $produkte as $p ) {
			$ampel = StockManager::ampel( $p );
			if ( 'gruen' !== $ampel ) {
				$warnings[] = array(
					'name'    => $p->name,
					'ampel'   => $ampel,
					'bestand' => Repository::get_stock( $p->id ),
					'einheit' => $p->basis_einheit,
					'min'     => (float) $p->mindestbestand,
				);
			}
		}

		if ( empty( $warnings ) ) {
			return;
		}

		echo '<div class="lw16-card" style="width:100%;max-width:none;margin-top:16px">';
		echo '<h2>' . esc_html__( 'Lagerwarnungen', 'lindenweg16' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th></th><th>' . esc_html__( 'Produkt', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Bestand', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Mindestbestand', 'lindenweg16' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $warnings as $w ) {
			echo '<tr>';
			echo '<td><span class="lw16-ampel lw16-ampel-' . esc_attr( $w['ampel'] ) . '"></span></td>';
			echo '<td>' . esc_html( $w['name'] ) . '</td>';
			echo '<td>' . esc_html( self::fmt( $w['bestand'] ) . ' ' . $w['einheit'] ) . '</td>';
			echo '<td>' . esc_html( self::fmt( $w['min'] ) . ' ' . $w['einheit'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Letzte Bestellungen.
	 */
	protected function render_recent_orders() {
		$orders = wc_get_orders( array(
			'limit'   => 5,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		) );

		if ( empty( $orders ) ) {
			return;
		}

		echo '<div class="lw16-card" style="width:100%;max-width:none;margin-top:16px">';
		echo '<h2>' . esc_html__( 'Letzte Bestellungen', 'lindenweg16' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bestellung', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Kunde', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Datum', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Betrag', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'lindenweg16' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $orders as $o ) {
			$edit_url = $o->get_edit_order_url();
			$datum    = $o->get_date_created() ? $o->get_date_created()->date_i18n( 'd.m.Y H:i' ) : '';
			echo '<tr>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">#' . esc_html( $o->get_order_number() ) . '</a></td>';
			echo '<td>' . esc_html( $o->get_formatted_billing_full_name() ) . '</td>';
			echo '<td>' . esc_html( $datum ) . '</td>';
			echo '<td>' . esc_html( self::euro( $o->get_total() ) ) . '</td>';
			echo '<td>' . esc_html( wc_get_order_status_name( $o->get_status() ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Euro formatieren.
	 *
	 * @param float $n Betrag.
	 * @return string
	 */
	protected static function euro( $n ) {
		return number_format( (float) $n, 2, ',', '.' ) . ' €';
	}

	/**
	 * Zahl formatieren.
	 *
	 * @param float $n Zahl.
	 * @return string
	 */
	protected static function fmt( $n ) {
		$s = number_format( (float) $n, 2, ',', '.' );
		return rtrim( rtrim( $s, '0' ), ',' );
	}
}
