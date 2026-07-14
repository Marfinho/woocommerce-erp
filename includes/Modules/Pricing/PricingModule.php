<?php
/**
 * Modul 5: Preiskalkulation.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Pricing;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Inventory\Repository;
use Lindenweg16\Core\AuditLog;

/**
 * Admin-Oberfläche für die Preiskalkulation samt „Preis übernehmen".
 * Schreibt _regular_price und _lw16_margin_percent ins WooCommerce-Produkt.
 */
class PricingModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_render_page_lw16_preiskalkulation', array( $this, 'render_admin' ) );
		add_action( 'admin_post_lw16_save_kalk_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_lw16_save_kalk_produkt', array( $this, 'handle_save_produkt' ) );
		add_action( 'admin_post_lw16_preis_uebernehmen', array( $this, 'handle_uebernehmen' ) );
		add_action( 'admin_post_lw16_preis_alle_uebernehmen', array( $this, 'handle_alle_uebernehmen' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_kalk_data' ) );
	}

	/**
	 * Kalkulationsdaten für Live-JS-Recalculation auf der Preiskalkulationsseite.
	 *
	 * @param string $hook Admin-Page-Hook.
	 */
	public function enqueue_kalk_data( $hook ) {
		if ( false === strpos( (string) $hook, 'lw16' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'lw16-preiskalkulation' !== $page ) {
			return;
		}
		$s = PricingCalculator::settings();
		wp_localize_script(
			'lw16-admin',
			'LW16Kalk',
			array(
				'druckerKostenH'  => PricingCalculator::drucker_kosten_pro_stunde(),
				'filamentPreisKg' => (float) $s['filamentpreis_pro_kg'],
				'stundenlohn'     => (float) $s['stundenlohn'],
				'ausschuss'       => (float) $s['ausschuss_prozent'],
				'zielmarge'       => (float) $s['zielmarge_module_prozent'],
			)
		);
	}

	/**
	 * Kalkulationsseite rendern.
	 */
	public function render_admin() {
		$s = PricingCalculator::settings();
		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Preiskalkulation', 'lindenweg16' ) . '</h1>';

		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}

		// --- Globale Annahmen. -------------------------------------------
		echo '<h2>' . esc_html__( 'Globale Annahmen', 'lindenweg16' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="lw16_save_kalk_settings">';
		wp_nonce_field( 'lw16_save_kalk_settings' );
		echo '<table class="form-table"><tbody>';
		$labels = array(
			'drucker_anschaffungspreis'      => __( 'Drucker Anschaffungspreis (€)', 'lindenweg16' ),
			'drucker_lebensdauer_stunden'    => __( 'Drucker Lebensdauer (Std)', 'lindenweg16' ),
			'drucker_ersatzteile_pro_stunde' => __( 'Ersatzteile pro Stunde (€)', 'lindenweg16' ),
			'drucker_leistung_kw'            => __( 'Leistung (kW)', 'lindenweg16' ),
			'strompreis_pro_kwh'             => __( 'Strompreis pro kWh (€)', 'lindenweg16' ),
			'filamentpreis_pro_kg'           => __( 'Filamentpreis pro kg (€)', 'lindenweg16' ),
			'stundenlohn'                    => __( 'Stundenlohn (€)', 'lindenweg16' ),
			'ausschuss_prozent'              => __( 'Ausschuss (%)', 'lindenweg16' ),
			'zielmarge_module_prozent'       => __( 'Zielmarge Module (%)', 'lindenweg16' ),
		);
		foreach ( $labels as $k => $label ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="number" step="any" name="' . esc_attr( $k ) . '" value="' . esc_attr( $s[ $k ] ) . '" class="small-text"></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: Betrag */ __( 'Druckerkosten pro Stunde: %s €', 'lindenweg16' ), number_format( PricingCalculator::drucker_kosten_pro_stunde(), 4, ',', '.' ) ) ) . '</p>';
		submit_button( __( 'Annahmen speichern', 'lindenweg16' ), 'secondary' );
		echo '</form>';

		$this->render_module_table();
		$this->render_set_table();

		// "Alle übernehmen"-Button.
		$alle_url = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_preis_alle_uebernehmen' ), 'lw16_alle_uebernehmen' );
		echo '<hr><p><a class="button button-primary button-hero" href="' . esc_url( $alle_url ) . '" onclick="return confirm(\'' . esc_attr__( 'Alle Preise übernehmen?', 'lindenweg16' ) . '\')">' . esc_html__( 'Alle Preise übernehmen', 'lindenweg16' ) . '</a>';
		echo ' <span class="description">' . esc_html__( 'Schreibt den kalkulierten Preis und die Marge in alle WooCommerce-Produkte.', 'lindenweg16' ) . '</span></p>';

		echo '</div>';
	}

	/**
	 * Tabelle aller Module mit Kalkulation und Übernahme.
	 */
	protected function render_module_table() {
		$module = Repository::get_products( 'modul' );
		echo '<h2>' . esc_html__( 'Module', 'lindenweg16' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Modul', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Kostenfelder', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Kosten', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Vorschlag', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Aktuell', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Marge', 'lindenweg16' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $module as $m ) {
			$calc    = PricingCalculator::calc_modul( $m );
			$aktuell = PricingCalculator::aktueller_preis( $m );
			echo '<tr>';
			echo '<td>' . esc_html( $m->name ) . '</td>';

			// Editierbare Kostenfelder.
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-inline-form">';
			echo '<input type="hidden" name="action" value="lw16_save_kalk_produkt"><input type="hidden" name="produkt_id" value="' . (int) $m->id . '">';
			wp_nonce_field( 'lw16_save_kalk_produkt_' . $m->id );
			echo '<label>' . esc_html__( 'Filament (g)', 'lindenweg16' ) . ' <input type="number" step="any" name="filament_gramm" value="' . esc_attr( $m->filament_gramm ) . '" class="small-text"></label> ';
			echo '<label>' . esc_html__( 'Druckzeit (h)', 'lindenweg16' ) . ' <input type="number" step="any" name="druckzeit_stunden" value="' . esc_attr( $m->druckzeit_stunden ) . '" class="small-text"></label> ';
			echo '<label>' . esc_html__( 'Arbeitszeit (min)', 'lindenweg16' ) . ' <input type="number" step="any" name="arbeitszeit_minuten" value="' . esc_attr( $m->arbeitszeit_minuten ) . '" class="small-text"></label> ';
			echo '<label>' . esc_html__( 'Verpackung (€)', 'lindenweg16' ) . ' <input type="number" step="any" name="verpackung_euro" value="' . esc_attr( $m->verpackung_euro ) . '" class="small-text"></label> ';
			echo '<label>' . esc_html__( 'Zusatz (€)', 'lindenweg16' ) . ' <input type="number" step="any" name="zusatzmaterial_euro" value="' . esc_attr( $m->zusatzmaterial_euro ) . '" class="small-text"></label> ';
			submit_button( __( 'Speichern', 'lindenweg16' ), 'small', '', false );
			echo '</form></td>';

			echo '<td>' . esc_html( number_format( $calc['kosten'], 2, ',', '.' ) ) . ' €</td>';
			echo '<td><strong>' . esc_html( number_format( $calc['vorschlag'], 0, ',', '.' ) ) . ' €</strong></td>';
			echo '<td>' . ( $aktuell > 0 ? esc_html( number_format( $aktuell, 0, ',', '.' ) . ' €' ) : '–' ) . '</td>';
			echo '<td>' . esc_html( number_format( $calc['marge'], 1, ',', '.' ) ) . ' %</td>';

			echo '<td>';
			if ( $m->woo_product_id ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_preis_uebernehmen&id=' . $m->id ), 'lw16_preis_' . $m->id );
				echo '<a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Preis übernehmen', 'lindenweg16' ) . '</a>';
			} else {
				echo '<span class="description">' . esc_html__( 'keine Woo-Zuordnung', 'lindenweg16' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Tabelle aller Sets.
	 */
	protected function render_set_table() {
		$sets = Repository::get_products( 'set' );
		echo '<h2>' . esc_html__( 'Sets', 'lindenweg16' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Set', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Rabatt (%)', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Einzelsumme', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Vorschlag', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Aktuell', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Marge', 'lindenweg16' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $sets as $set ) {
			$calc    = PricingCalculator::calc_set( $set );
			$aktuell = PricingCalculator::aktueller_preis( $set );
			echo '<tr>';
			echo '<td>' . esc_html( $set->name ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-inline-form">';
			echo '<input type="hidden" name="action" value="lw16_save_kalk_produkt"><input type="hidden" name="produkt_id" value="' . (int) $set->id . '">';
			wp_nonce_field( 'lw16_save_kalk_produkt_' . $set->id );
			echo '<input type="number" step="any" name="rabatt_prozent" value="' . esc_attr( $set->rabatt_prozent ) . '" class="small-text"> ';
			submit_button( __( 'Speichern', 'lindenweg16' ), 'small', '', false );
			echo '</form></td>';
			echo '<td>' . esc_html( number_format( $calc['einzelsumme'], 2, ',', '.' ) ) . ' €</td>';
			echo '<td><strong>' . esc_html( number_format( $calc['vorschlag'], 0, ',', '.' ) ) . ' €</strong></td>';
			echo '<td>' . ( $aktuell > 0 ? esc_html( number_format( $aktuell, 0, ',', '.' ) . ' €' ) : '–' ) . '</td>';
			echo '<td>' . esc_html( number_format( $calc['marge'], 1, ',', '.' ) ) . ' %</td>';
			echo '<td>';
			if ( $set->woo_product_id ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_preis_uebernehmen&id=' . $set->id ), 'lw16_preis_' . $set->id );
				echo '<a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Preis übernehmen', 'lindenweg16' ) . '</a>';
			} else {
				echo '<span class="description">' . esc_html__( 'keine Woo-Zuordnung', 'lindenweg16' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* --------------------------------------------------------------------
	 * POST-Handler
	 * ------------------------------------------------------------------ */

	/**
	 * Globale Annahmen speichern.
	 */
	public function handle_save_settings() {
		$this->guard( 'lw16_save_kalk_settings' );
		PricingCalculator::save_settings( wp_unslash( $_POST ) );
		$this->redirect( __( 'Annahmen gespeichert.', 'lindenweg16' ) );
	}

	/**
	 * Kostenfelder eines Produkts speichern.
	 */
	public function handle_save_produkt() {
		$id = (int) ( $_POST['produkt_id'] ?? 0 );
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_save_kalk_produkt_' . $id );

		$fields = array( 'filament_gramm', 'druckzeit_stunden', 'arbeitszeit_minuten', 'verpackung_euro', 'zusatzmaterial_euro', 'rabatt_prozent' );
		$data   = array();
		foreach ( $fields as $f ) {
			if ( isset( $_POST[ $f ] ) ) {
				$data[ $f ] = (float) $_POST[ $f ];
			}
		}
		if ( $data ) {
			Repository::update_product( $id, $data );
		}
		$this->redirect( __( 'Kostenfelder gespeichert.', 'lindenweg16' ) );
	}

	/**
	 * Vorgeschlagenen Preis ins WooCommerce-Produkt übernehmen und die
	 * tatsächliche Marge in _lw16_margin_percent schreiben.
	 */
	public function handle_uebernehmen() {
		$id = (int) ( $_GET['id'] ?? 0 );
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_preis_' . $id );

		$produkt = Repository::get_product( $id );
		if ( ! $produkt || empty( $produkt->woo_product_id ) ) {
			$this->redirect( __( 'Kein zugeordnetes WooCommerce-Produkt.', 'lindenweg16' ) );
		}

		$calc = 'set' === $produkt->typ ? PricingCalculator::calc_set( $produkt ) : PricingCalculator::calc_modul( $produkt );

		$wc = wc_get_product( $produkt->woo_product_id );
		if ( ! $wc ) {
			$this->redirect( __( 'WooCommerce-Produkt nicht gefunden.', 'lindenweg16' ) );
		}

		$preis = (float) $calc['vorschlag'];
		$wc->set_regular_price( (string) $preis );
		$wc->save();

		// Tatsächliche Marge fürs Referral-Programm hinterlegen.
		$marge = PricingCalculator::marge_prozent( $calc['kosten'], $preis );
		update_post_meta( $produkt->woo_product_id, '_lw16_margin_percent', $marge );

		AuditLog::record( 'preis_uebernommen', 'produkt', $id, array( 'preis' => $preis, 'marge' => $marge ) );

		$this->redirect( sprintf( /* translators: 1: Preis, 2: Marge */ __( 'Preis übernommen: %1$s € (Marge %2$s %%).', 'lindenweg16' ), number_format( $preis, 0, ',', '.' ), number_format( $marge, 1, ',', '.' ) ) );
	}

	/**
	 * Alle kalkulierten Preise in die WooCommerce-Produkte schreiben.
	 */
	public function handle_alle_uebernehmen() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_alle_uebernehmen' );

		$count    = 0;
		$products = Repository::get_products();
		foreach ( $products as $produkt ) {
			if ( empty( $produkt->woo_product_id ) ) {
				continue;
			}
			$wc = wc_get_product( $produkt->woo_product_id );
			if ( ! $wc ) {
				continue;
			}
			$calc  = 'set' === $produkt->typ ? PricingCalculator::calc_set( $produkt ) : PricingCalculator::calc_modul( $produkt );
			$preis = (float) $calc['vorschlag'];
			$wc->set_regular_price( (string) $preis );
			$wc->save();
			$marge = PricingCalculator::marge_prozent( $calc['kosten'], $preis );
			update_post_meta( $produkt->woo_product_id, '_lw16_margin_percent', $marge );
			AuditLog::record( 'preis_uebernommen', 'produkt', $produkt->id, array( 'preis' => $preis, 'marge' => $marge, 'batch' => true ) );
			$count++;
		}

		$this->redirect( sprintf( /* translators: %d: Anzahl */ __( '%d Preise übernommen.', 'lindenweg16' ), $count ) );
	}

	/**
	 * Berechtigung + Nonce.
	 *
	 * @param string $nonce Nonce.
	 */
	protected function guard( $nonce ) {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Zurück zur Kalkulationsseite.
	 *
	 * @param string $msg Meldung.
	 */
	protected function redirect( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'lw16-preiskalkulation', 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
