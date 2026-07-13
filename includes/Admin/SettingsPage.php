<?php
/**
 * Einstellungsseite des Plugins.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Admin;

use Lindenweg16\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Rendert und speichert die Plugin-Einstellungen.
 */
class SettingsPage {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_render_page_lw16_einstellungen', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_save_settings', array( $this, 'save' ) );
	}

	/**
	 * Feldgruppen für die Darstellung.
	 *
	 * @return array
	 */
	protected function fields() {
		return array(
			__( 'Firmendaten', 'lindenweg16' )   => array(
				'firma_name'         => array( __( 'Firmenname', 'lindenweg16' ), 'text' ),
				'firma_strasse'      => array( __( 'Straße', 'lindenweg16' ), 'text' ),
				'firma_plz_ort'      => array( __( 'PLZ / Ort', 'lindenweg16' ), 'text' ),
				'firma_steuernummer' => array( __( 'Steuernummer', 'lindenweg16' ), 'text' ),
				'firma_email'        => array( __( 'E-Mail', 'lindenweg16' ), 'text' ),
			),
			__( 'Buchhaltung', 'lindenweg16' )    => array(
				'kleinunternehmer'      => array( __( 'Kleinunternehmer §19 UStG', 'lindenweg16' ), 'checkbox' ),
				'steuersatz_ruecklage'  => array( __( 'Geschätzter Steuersatz für Rücklage (%)', 'lindenweg16' ), 'number' ),
				'rechnungsnummer_modus' => array( __( 'Rechnungsnummern-Modus (jahr/durchlaufend)', 'lindenweg16' ), 'text' ),
			),
			__( 'Loyalty', 'lindenweg16' )        => array(
				'referral_cookie_tage'     => array( __( 'Referral-Cookie Laufzeit (Tage)', 'lindenweg16' ), 'number' ),
				'referral_prozent'         => array( __( 'Referral-Guthaben (%)', 'lindenweg16' ), 'number' ),
				'referral_margin_fallback' => array( __( 'Margen-Fallback (%)', 'lindenweg16' ), 'number' ),
				'referral_wartezeit_tage'  => array( __( 'Mindestwartezeit Referral (Tage)', 'lindenweg16' ), 'number' ),
				'referral_review_schwelle' => array( __( 'Review-Schwelle Reward (€)', 'lindenweg16' ), 'number' ),
				'punkte_pro_euro'          => array( __( 'Punkte pro Euro', 'lindenweg16' ), 'number' ),
				'punkte_einloesewert'      => array( __( 'Punkte für 1 €', 'lindenweg16' ), 'number' ),
				'punkte_wartezeit_tage'    => array( __( 'Mindestwartezeit Punkte (Tage)', 'lindenweg16' ), 'number' ),
			),
			__( 'Kundenbindung', 'lindenweg16' )  => array(
				'cart_erinnerung_1_stunden' => array( __( '1. Warenkorb-Erinnerung nach (Std)', 'lindenweg16' ), 'number' ),
				'cart_erinnerung_2_stunden' => array( __( '2. Warenkorb-Erinnerung nach (Std)', 'lindenweg16' ), 'number' ),
				'postpurchase_danke_tage'   => array( __( 'Dankeschön-Mail nach (Tage)', 'lindenweg16' ), 'number' ),
				'postpurchase_review_tage'  => array( __( 'Bewertungsanfrage nach (Tage)', 'lindenweg16' ), 'number' ),
				'review_bonus_punkte'       => array( __( 'Bonus-Punkte für Bewertung', 'lindenweg16' ), 'number' ),
				'review_min_zeichen'        => array( __( 'Mindestlänge Bewertung (Zeichen)', 'lindenweg16' ), 'number' ),
			),
			__( 'Reporting & DHL', 'lindenweg16' ) => array(
				'export_email'         => array( __( 'E-Mail für Datenexport', 'lindenweg16' ), 'text' ),
				'dhl_tracking_api_key' => array( __( 'DHL Sendungsverfolgung API-Key', 'lindenweg16' ), 'text' ),
				'dhl_tracking_sandbox' => array( __( 'DHL Sandbox-Modus', 'lindenweg16' ), 'checkbox' ),
			),
		);
	}

	/**
	 * Einstellungsformular rendern.
	 */
	public function render() {
		$values = Settings::all();
		echo '<div class="wrap lw16-wrap">';
		echo '<h1>' . esc_html__( 'Lindenweg16 – Einstellungen', 'lindenweg16' ) . '</h1>';

		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Einstellungen gespeichert.', 'lindenweg16' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="lw16_save_settings">';
		wp_nonce_field( 'lw16_save_settings' );

		foreach ( $this->fields() as $group => $fields ) {
			echo '<h2>' . esc_html( $group ) . '</h2>';
			echo '<table class="form-table"><tbody>';
			foreach ( $fields as $key => $meta ) {
				list( $label, $type ) = $meta;
				$val = isset( $values[ $key ] ) ? $values[ $key ] : '';
				echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
				if ( 'checkbox' === $type ) {
					echo '<input type="checkbox" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="1" ' . checked( 1, (int) $val, false ) . '>';
				} elseif ( 'number' === $type ) {
					echo '<input type="number" step="any" class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '">';
				} else {
					echo '<input type="text" class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '">';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		submit_button( __( 'Einstellungen speichern', 'lindenweg16' ) );
		echo '</form></div>';
	}

	/**
	 * Einstellungen speichern.
	 */
	public function save() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
		check_admin_referer( 'lw16_save_settings' );

		$clean = array();
		foreach ( $this->fields() as $fields ) {
			foreach ( $fields as $key => $meta ) {
				$type = $meta[1];
				if ( 'checkbox' === $type ) {
					$clean[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
				} elseif ( 'number' === $type ) {
					$clean[ $key ] = isset( $_POST[ $key ] ) ? (float) wp_unslash( $_POST[ $key ] ) : 0;
				} else {
					$clean[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
				}
			}
		}

		Settings::update( $clean );

		wp_safe_redirect( add_query_arg( array( 'page' => 'lw16-einstellungen', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
