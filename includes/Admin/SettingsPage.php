<?php
/**
 * Einstellungsseite des Plugins.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Admin;

use Lindenweg16\Core\Settings;
use Lindenweg16\Core\MailTemplate;

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
				'firma_telefon'      => array( __( 'Telefon', 'lindenweg16' ), 'text' ),
				'firma_iban'         => array( __( 'IBAN', 'lindenweg16' ), 'text' ),
				'firma_bic'          => array( __( 'BIC', 'lindenweg16' ), 'text' ),
			),
			__( 'Corporate Identity', 'lindenweg16' ) => array(
				'ci_logo_id' => array( __( 'Logo (JPEG empfohlen, wird in PDFs und E-Mails verwendet)', 'lindenweg16' ), 'media' ),
				'ci_farbe'   => array( __( 'Markenfarbe (Header-Hintergrund in E-Mails)', 'lindenweg16' ), 'color' ),
			),
			__( 'Buchhaltung', 'lindenweg16' )    => array(
				'kleinunternehmer'      => array( __( 'Kleinunternehmer §19 UStG', 'lindenweg16' ), 'checkbox' ),
				'gebuehren_automatisch' => array( __( 'Zahlungsgebühren aus Bestellhinweisen übernehmen', 'lindenweg16' ), 'checkbox' ),
				'steuersatz_ruecklage'  => array( __( 'Geschätzter Steuersatz für Rücklage (%)', 'lindenweg16' ), 'number' ),
				'rechnungsnummer_modus' => array( __( 'Rechnungsnummern-Modus (jahr/durchlaufend)', 'lindenweg16' ), 'text' ),
			),
			__( 'Marketing', 'lindenweg16' )      => array(
				'km_pauschale'            => array( __( 'Kilometerpauschale (€ je km)', 'lindenweg16' ), 'number' ),
				'marketing_nachlauf_tage' => array( __( 'Nachlauffenster für Kampagnenumsatz (Tage)', 'lindenweg16' ), 'number' ),
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
			__( 'E-Mail', 'lindenweg16' )           => array(
				'email_absender_name' => array( __( 'Absendername', 'lindenweg16' ), 'text' ),
				'email_footer'        => array( __( 'E-Mail-Footer-Text (erscheint in jeder Mail)', 'lindenweg16' ), 'textarea' ),
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

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
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
				} elseif ( 'color' === $type ) {
					echo '<input type="color" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ?: '#1d2327' ) . '" style="width:60px;height:36px;padding:2px;cursor:pointer">';
					echo ' <code>' . esc_html( $val ?: '#1d2327' ) . '</code>';
				} elseif ( 'media' === $type ) {
					$img_url = $val ? wp_get_attachment_url( (int) $val ) : '';
					echo '<div id="lw16-logo-preview" style="margin-bottom:8px">';
					if ( $img_url ) {
						echo '<img src="' . esc_url( $img_url ) . '" style="max-width:200px;max-height:60px;display:block;margin-bottom:4px">';
					}
					echo '</div>';
					echo '<input type="hidden" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (int) $val ) . '">';
					echo '<button type="button" class="button" id="lw16-logo-btn">' . esc_html__( 'Logo auswählen', 'lindenweg16' ) . '</button>';
					if ( $val ) {
						echo ' <button type="button" class="button" id="lw16-logo-remove">' . esc_html__( 'Entfernen', 'lindenweg16' ) . '</button>';
					}
					echo '<p class="description">' . esc_html__( 'JPEG-Format empfohlen für PDF-Einbettung.', 'lindenweg16' ) . '</p>';
					$this->enqueue_media_js( $key );
				} elseif ( 'textarea' === $type ) {
					echo '<textarea class="large-text" rows="3" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( $val ) . '</textarea>';
				} else {
					echo '<input type="text" class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '">';
				}

				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// E-Mail-Vorlagen.
		$this->render_mail_templates( $values );

		submit_button( __( 'Einstellungen speichern', 'lindenweg16' ) );
		echo '</form></div>';
	}

	/**
	 * E-Mail-Vorlagen-Abschnitt mit Platzhaltern rendern.
	 *
	 * @param array $values Aktuelle Einstellungswerte.
	 */
	protected function render_mail_templates( $values ) {
		$templates = MailTemplate::templates();

		echo '<h2>' . esc_html__( 'E-Mail-Vorlagen', 'lindenweg16' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Betreff und Text jeder automatischen E-Mail anpassen. Platzhalter werden automatisch ersetzt. Leer lassen = Standard-Text verwenden. Der Platzhalter {firmenname} steht in jeder Vorlage zur Verfügung.', 'lindenweg16' ) . '</p>';

		echo '<div style="margin-top:12px">';
		foreach ( $templates as $key => $tpl ) {
			$betreff_key = 'mail_' . $key . '_betreff';
			$text_key    = 'mail_' . $key . '_text';
			$betreff_val = isset( $values[ $betreff_key ] ) ? $values[ $betreff_key ] : '';
			$text_val    = isset( $values[ $text_key ] ) ? $values[ $text_key ] : '';

			echo '<details style="margin-bottom:12px;border:1px solid #ddd;border-radius:4px;padding:0">';
			echo '<summary style="padding:12px 16px;cursor:pointer;font-weight:600;background:#f9f9f9;border-radius:4px">';
			echo esc_html( $tpl['label'] );
			if ( $betreff_val || $text_val ) {
				echo ' <span style="color:#2271b1;font-weight:400">(' . esc_html__( 'angepasst', 'lindenweg16' ) . ')</span>';
			}
			echo '</summary>';
			echo '<div style="padding:16px">';

			if ( $tpl['platzhalter'] ) {
				echo '<p class="description" style="margin-bottom:12px">';
				echo esc_html__( 'Verfügbare Platzhalter: ', 'lindenweg16' );
				echo '<code>' . esc_html( $tpl['platzhalter'] ) . ', {firmenname}</code></p>';
			} else {
				echo '<p class="description" style="margin-bottom:12px">';
				echo esc_html__( 'Verfügbare Platzhalter: ', 'lindenweg16' );
				echo '<code>{firmenname}</code></p>';
			}

			echo '<table class="form-table" style="margin:0"><tbody>';

			echo '<tr><th scope="row"><label for="' . esc_attr( $betreff_key ) . '">' . esc_html__( 'Betreff', 'lindenweg16' ) . '</label></th><td>';
			echo '<input type="text" class="large-text" id="' . esc_attr( $betreff_key ) . '" name="' . esc_attr( $betreff_key ) . '" value="' . esc_attr( $betreff_val ) . '" placeholder="' . esc_attr( $tpl['betreff'] ) . '">';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="' . esc_attr( $text_key ) . '">' . esc_html__( 'Text', 'lindenweg16' ) . '</label></th><td>';
			echo '<textarea class="large-text" rows="6" id="' . esc_attr( $text_key ) . '" name="' . esc_attr( $text_key ) . '" placeholder="' . esc_attr( $tpl['text'] ) . '">' . esc_textarea( $text_val ) . '</textarea>';
			echo '</td></tr>';

			echo '</tbody></table>';
			echo '</div></details>';
		}
		echo '</div>';
	}

	/**
	 * WordPress-Media-Library-JS für Logo-Upload einbinden.
	 *
	 * @param string $field_id Feld-ID.
	 */
	protected function enqueue_media_js( $field_id ) {
		wp_enqueue_media();
		?>
		<script>
		jQuery(function($){
			var frame;
			$('#lw16-logo-btn').on('click',function(e){
				e.preventDefault();
				if(frame){frame.open();return;}
				frame=wp.media({title:<?php echo wp_json_encode( __( 'Logo auswählen', 'lindenweg16' ) ); ?>,button:{text:<?php echo wp_json_encode( __( 'Verwenden', 'lindenweg16' ) ); ?>},multiple:false});
				frame.on('select',function(){
					var a=frame.state().get('selection').first().toJSON();
					$('#<?php echo esc_js( $field_id ); ?>').val(a.id);
					$('#lw16-logo-preview').html('<img src="'+a.url+'" style="max-width:200px;max-height:60px;display:block;margin-bottom:4px">');
					if(!$('#lw16-logo-remove').length){
						$('#lw16-logo-btn').after(' <button type="button" class="button" id="lw16-logo-remove"><?php echo esc_js( __( 'Entfernen', 'lindenweg16' ) ); ?></button>');
						bindRemove();
					}
				});
				frame.open();
			});
			function bindRemove(){
				$(document).on('click','#lw16-logo-remove',function(e){
					e.preventDefault();
					$('#<?php echo esc_js( $field_id ); ?>').val('0');
					$('#lw16-logo-preview').empty();
					$(this).remove();
				});
			}
			bindRemove();
		});
		</script>
		<?php
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

		// Reguläre Felder.
		foreach ( $this->fields() as $fields ) {
			foreach ( $fields as $key => $meta ) {
				$type = $meta[1];
				if ( 'checkbox' === $type ) {
					$clean[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
				} elseif ( 'number' === $type ) {
					$clean[ $key ] = isset( $_POST[ $key ] ) ? (float) wp_unslash( $_POST[ $key ] ) : 0;
				} elseif ( 'color' === $type ) {
					$raw = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
					$clean[ $key ] = MailTemplate::sanitize_color( $raw );
				} elseif ( 'media' === $type ) {
					$clean[ $key ] = isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : 0;
				} elseif ( 'textarea' === $type ) {
					$clean[ $key ] = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
				} else {
					$clean[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
				}
			}
		}

		// E-Mail-Vorlagen.
		foreach ( MailTemplate::templates() as $tpl_key => $tpl ) {
			$betreff_key = 'mail_' . $tpl_key . '_betreff';
			$text_key    = 'mail_' . $tpl_key . '_text';
			$clean[ $betreff_key ] = isset( $_POST[ $betreff_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $betreff_key ] ) ) : '';
			$clean[ $text_key ]    = isset( $_POST[ $text_key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $text_key ] ) ) : '';
		}

		Settings::update( $clean );

		wp_safe_redirect( add_query_arg( array( 'page' => 'lw16-einstellungen', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
