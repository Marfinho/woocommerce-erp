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
	 *
	 * Kein eigener Menüpunkt mehr – diese Seite ist jetzt der Reiter
	 * "Plugin" auf der gemeinsamen Einstellungen-Seite (siehe
	 * Lindenweg16\Modules\Betrieb\Admin\SettingsPage::render(), das
	 * render_content() dieser Klasse direkt aufruft). Der Speichern-Hook
	 * bleibt unverändert bestehen, das Formular postet weiterhin hierher.
	 */
	public function register() {
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
			__( 'Storno und Erstattung', 'lindenweg16' ) => array(
				'rechnung_auto_storno' => array( __( 'Rechnung samt Einnahmebuchung automatisch stornieren, wenn die Bestellung storniert oder erstattet wird', 'lindenweg16' ), 'checkbox' ),
				'betrieb_storno_lager' => array( __( 'Lagerabgang automatisch zurückbuchen, wenn die Bestellung storniert oder erstattet wird (nur wenn die Ware nicht schon beim Kunden ist)', 'lindenweg16' ), 'checkbox' ),
			),
			__( 'Oberfläche', 'lindenweg16' )     => array(
				'erp_klassisch_umleiten' => array( __( 'Betriebsseiten im wp-admin (Lager, Aufträge, Versand, Artikel …) leiten ins ERP um. Die klassische Ansicht bleibt im ERP erreichbar.', 'lindenweg16' ), 'checkbox' ),
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
			__( 'Reporting & DHL-Sendungsverfolgung', 'lindenweg16' ) => array(
				'export_email'         => array( __( 'E-Mail für Datenexport', 'lindenweg16' ), 'text' ),
				'dhl_tracking_api_key' => array( __( 'DHL Sendungsverfolgung API-Key', 'lindenweg16' ), 'text' ),
				'dhl_tracking_sandbox' => array( __( 'DHL Sandbox-Modus', 'lindenweg16' ), 'checkbox' ),
			),
			__( 'DHL Geschäftskundenversand (Labels)', 'lindenweg16' ) => array(
				'dhl_versand_sandbox_api_key'    => array( __( 'Sandbox: API-Key der Anwendung (developer.dhl.com, Sandbox-App)', 'lindenweg16' ), 'text' ),
				'dhl_versand_sandbox_api_secret' => array( __( 'Sandbox: API-Secret der Anwendung (developer.dhl.com, Sandbox-App)', 'lindenweg16' ), 'password' ),
				'dhl_versand_sandbox_user'       => array( __( 'Sandbox: Benutzer (von DHL fest vorgegebener Testzugang, i. d. R. unverändert lassen)', 'lindenweg16' ), 'text' ),
				'dhl_versand_sandbox_password'   => array( __( 'Sandbox: Passwort (von DHL fest vorgegebener Testzugang, i. d. R. unverändert lassen)', 'lindenweg16' ), 'password' ),
				'dhl_versand_live_api_key'       => array( __( 'Live: API-Key der Anwendung (developer.dhl.com, nach Go-Live-Freigabe)', 'lindenweg16' ), 'text' ),
				'dhl_versand_live_api_secret'    => array( __( 'Live: API-Secret der Anwendung (developer.dhl.com, nach Go-Live-Freigabe)', 'lindenweg16' ), 'password' ),
				'dhl_versand_live_user'          => array( __( 'Live: Benutzer des Geschäftskundenportals', 'lindenweg16' ), 'text' ),
				'dhl_versand_live_password'      => array( __( 'Live: Passwort des Geschäftskundenportals', 'lindenweg16' ), 'password' ),
				'dhl_versand_ekp'          => array( __( 'Live: Kundennummer EKP (10 Stellen)', 'lindenweg16' ), 'text' ),
				'dhl_versand_teilnahme'    => array( __( 'Live: Teilnahme (2 Stellen, gilt für alle Produkte)', 'lindenweg16' ), 'text' ),
				'dhl_versand_teilnahme_01' => array( __( 'Teilnahme abweichend: DHL Paket', 'lindenweg16' ), 'text' ),
				'dhl_versand_teilnahme_62' => array( __( 'Teilnahme abweichend: Warenpost', 'lindenweg16' ), 'text' ),
				'dhl_versand_teilnahme_54' => array( __( 'Teilnahme abweichend: Europaket', 'lindenweg16' ), 'text' ),
				'dhl_versand_teilnahme_53' => array( __( 'Teilnahme abweichend: Paket International', 'lindenweg16' ), 'text' ),
				'dhl_versand_teilnahme_66' => array( __( 'Teilnahme abweichend: Warenpost International', 'lindenweg16' ), 'text' ),
				'dhl_versand_profil'       => array( __( 'Abrechnungsprofil', 'lindenweg16' ), 'text' ),
				'dhl_versand_printformat'  => array( __( 'Labelformat (910-300-610 für das 103×199-mm-Rollenetikett, A4 für Papier)', 'lindenweg16' ), 'text' ),
				'dhl_versand_verpackung_g' => array( __( 'Verpackungszuschlag je Sendung (g)', 'lindenweg16' ), 'number' ),
				'dhl_versand_warenpost'    => array( __( 'Warenpost/Kleinpaket ist gebucht', 'lindenweg16' ), 'checkbox' ),
				'dhl_versand_vorabmeldung' => array( __( 'Kunden per E-Mail vorab benachrichtigen', 'lindenweg16' ), 'checkbox' ),
				'dhl_versand_sandbox'      => array( __( 'Sandbox-Modus (erzeugt keine echten Labels)', 'lindenweg16' ), 'checkbox' ),
			),
			__( 'Automatischer DHL-Druck (Raspberry Pi)', 'lindenweg16' ) => array(
				'dhl_print_automatisch'       => array( __( 'Nach erfolgreicher Label-Erzeugung automatisch einen Druckjob anlegen', 'lindenweg16' ), 'checkbox' ),
				'dhl_print_standarddrucker'   => array( __( 'Standard-Drucker-ID (z. B. eg_dhl)', 'lindenweg16' ), 'text' ),
				'dhl_print_aufbewahrung_tage' => array( __( 'Aufbewahrung der Druckjob-Einträge der ganzen Warteschlange (Tage, 0 = unbegrenzt)', 'lindenweg16' ), 'number' ),
			),
			__( 'Bambu-Lab-Drucker (Raspberry Pi)', 'lindenweg16' ) => array(
				'bambu_aufbewahrung_tage' => array( __( 'Aufbewahrung der Bambu-Druckaufträge samt hochgeladener Druckdatei (Tage, 0 = unbegrenzt)', 'lindenweg16' ), 'number' ),
			),
		);
	}

	/**
	 * Einstellungsformular rendern – Inhalt ohne eigene Überschrift, zur
	 * Einbettung als Reiter auf der gemeinsamen Einstellungen-Seite
	 * (siehe Betrieb\Admin\SettingsPage::render_plugin_tab()).
	 */
	public function render_content() {
		$values = Settings::all();

		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Einstellungen gespeichert.', 'lindenweg16' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="lw16_save_settings">';
		wp_nonce_field( 'lw16_save_settings' );

		foreach ( $this->fields() as $group => $fields ) {
			echo '<h2>' . esc_html( $group ) . '</h2>';
			$this->group_hint( $group );
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
				} elseif ( 'password' === $type ) {
					// Ein gespeichertes Passwort wird nicht zurückgezeigt.
					// Wer über die Schulter schaut, sieht sonst den Zugang
					// zum Frankierkonto.
					echo '<input type="password" class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== (string) $val ? __( '— gespeichert, zum Ändern neu eingeben —', 'lindenweg16' ) : '' ) . '">';
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
		echo '</form>';
	}

	/**
	 * Erklärender Vorspann zu einer Gruppe.
	 *
	 * Nur dort, wo die Feldnamen allein in die Irre führen.
	 *
	 * @param string $group Überschrift der Gruppe.
	 */
	protected function group_hint( $group ) {
		if ( __( 'DHL Geschäftskundenversand (Labels)', 'lindenweg16' ) !== $group ) {
			return;
		}

		echo '<div class="notice notice-info inline" style="margin:0 0 12px"><p><strong>';
		esc_html_e( 'Für die Sandbox legt man keinen Benutzer an.', 'lindenweg16' );
		echo '</strong> ';
		esc_html_e( 'Benutzer und Passwort der Sandbox sind der feste Testzugang der DHL und stehen schon da – selbst eintragen musst du dort nur API-Key und API-Secret deiner Sandbox-App.', 'lindenweg16' );
		echo '</p><p>';
		esc_html_e( 'Kundennummer, Teilnahme und Abrechnungsprofil gelten nur im Echtbetrieb: In der Sandbox bucht die DHL auf ihre eigene Testkundennummer, und das Plugin setzt sie ein, solange der Sandbox-Haken sitzt. Deine Angaben bleiben dabei unberührt.', 'lindenweg16' );
		echo '</p><p>';
		printf(
			/* translators: %s: Link zur Versandseite. */
			esc_html__( 'Ob der Zugang sitzt, sagt der Knopf „DHL-Zugang prüfen" auf der Seite %s. Er kostet kein Label.', 'lindenweg16' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=lw16-betrieb-versand' ) ) . '">' . esc_html__( 'Betrieb → Versand', 'lindenweg16' ) . '</a>'
		);
		echo '</p></div>';
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
				} elseif ( 'password' === $type ) {
					// Das Formular zeigt ein gespeichertes Passwort nicht
					// an. Ein leeres Feld heißt deshalb „unverändert" –
					// sonst löschte jedes Speichern der Seite den Zugang
					// zum Frankierkonto.
					$eingabe = isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';

					if ( '' !== $eingabe ) {
						$clean[ $key ] = sanitize_text_field( $eingabe );
					}
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

		// Zurück auf den "Plugin"-Reiter der gemeinsamen Einstellungen-Seite
		// (kein eigener Menüpunkt/Slug mehr, siehe register()).
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'lw16-betrieb-einstellungen',
					'view'    => 'plugin',
					'updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
