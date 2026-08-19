<?php
/**
 * Einstellungsseite des Moduls Betrieb: Kalkulationsannahmen mit Historie
 * und Prüfung der Audit-Kette.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\BetriebAudit;
use Lindenweg16\Modules\Betrieb\CalcSettings;

/**
 * Rendert die Seite und verarbeitet ihre Formulare.
 */
class SettingsPage {

	const NONCE = 'lw16_betrieb_settings';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_einstellungen', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_betrieb_save_setting', array( $this, 'handle_save' ) );
	}

	/**
	 * Zugriff prüfen oder abbrechen.
	 */
	protected function require_cap() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_manage_settings' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Neuen Wert festschreiben.
	 */
	public function handle_save() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$key        = isset( $_POST['setting_key'] ) ? sanitize_key( wp_unslash( $_POST['setting_key'] ) ) : '';
		$raw        = isset( $_POST['value_decimal'] ) ? sanitize_text_field( wp_unslash( $_POST['value_decimal'] ) ) : '';
		$valid_from = isset( $_POST['valid_from'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_from'] ) ) : '';
		$note       = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

		$labels = CalcSettings::labels();
		if ( ! isset( $labels[ $key ] ) ) {
			$this->redirect_back( __( 'Unbekannte Annahme.', 'lindenweg16' ), 'error' );
		}

		// Deutsche Eingabe mit Komma zulassen.
		$value = (float) str_replace( ',', '.', $raw );
		if ( '' === $raw || ! is_numeric( str_replace( ',', '.', $raw ) ) ) {
			$this->redirect_back( __( 'Bitte eine Zahl eingeben.', 'lindenweg16' ), 'error' );
		}
		if ( $value < 0 ) {
			$this->redirect_back( __( 'Negative Werte sind hier nicht sinnvoll.', 'lindenweg16' ), 'error' );
		}

		CalcSettings::set( $key, $value, $valid_from, $note );
		$this->redirect_back( __( 'Annahme festgeschrieben.', 'lindenweg16' ), 'success' );
	}

	/**
	 * Zurück zur Seite mit Meldung.
	 *
	 * @param string $msg  Meldung.
	 * @param string $type success oder error.
	 */
	protected function redirect_back( $msg, $type ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'lw16-betrieb-einstellungen',
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
		$this->require_cap();

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'annahmen'; // phpcs:ignore WordPress.Security.NonceVerification
		$base = admin_url( 'admin.php?page=lw16-betrieb-einstellungen' );

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Betrieb · Einstellungen', 'lindenweg16' ) . '</h1>';

		echo '<nav class="lw16-tabs">';
		printf(
			'<a href="%s" class="%s">%s</a> ',
			esc_url( $base ),
			'annahmen' === $view ? 'active' : '',
			esc_html__( 'Kalkulationsannahmen', 'lindenweg16' )
		);
		printf(
			'<a href="%s" class="%s">%s</a>',
			esc_url( add_query_arg( 'view', 'protokoll', $base ) ),
			'protokoll' === $view ? 'active' : '',
			esc_html__( 'Protokoll & Kettenprüfung', 'lindenweg16' )
		);
		echo '</nav>';

		$this->render_notice();

		if ( 'protokoll' === $view ) {
			$this->render_protokoll();
		} else {
			$this->render_annahmen();
		}

		echo '</div>';
	}

	/**
	 * Meldung aus dem Redirect anzeigen.
	 */
	protected function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_GET['lw16_msg'] ) ) {
			return;
		}
		$msg  = sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) );
		$type = isset( $_GET['lw16_typ'] ) && 'error' === $_GET['lw16_typ'] ? 'error' : 'success';
		// phpcs:enable WordPress.Security.NonceVerification
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $msg )
		);
	}

	/**
	 * Annahmen: aktuelle Werte, Formular, Verlauf.
	 */
	protected function render_annahmen() {
		$stichtag = isset( $_GET['stichtag'] ) ? CalcSettings::sanitize_date( wp_unslash( $_GET['stichtag'] ) ) : gmdate( 'Y-m-d' ); // phpcs:ignore WordPress.Security.NonceVerification
		$values   = CalcSettings::for_date( $stichtag );
		$labels   = CalcSettings::labels();

		echo '<p>' . esc_html__( 'Werte werden nie überschrieben. Jede Änderung gilt ab einem Datum und erzeugt eine neue Zeile – so bleibt jede alte Kalkulation nachrechenbar.', 'lindenweg16' ) . '</p>';

		printf(
			'<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="lw16-betrieb-einstellungen" />%s <input type="date" name="stichtag" value="%s" /> <button class="button">%s</button></form>',
			esc_html__( 'Gültig zum Stichtag:', 'lindenweg16' ),
			esc_attr( $stichtag ),
			esc_html__( 'Anzeigen', 'lindenweg16' )
		);

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Annahme', 'lindenweg16' ) . '</th>';
		echo '<th style="text-align:right">' . esc_html__( 'Wert am Stichtag', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Neuer Wert ab', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Verlauf', 'lindenweg16' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $labels as $key => $label ) {
			$history = CalcSettings::history( $key );
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong><br /><code>' . esc_html( $key ) . '</code></td>';
			echo '<td style="text-align:right">' . esc_html( number_format_i18n( isset( $values[ $key ] ) ? $values[ $key ] : 0, 4 ) ) . '</td>';

			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="action" value="lw16_betrieb_save_setting" />';
			printf( '<input type="hidden" name="setting_key" value="%s" />', esc_attr( $key ) );
			printf(
				'<input type="text" inputmode="decimal" name="value_decimal" size="8" value="%s" /> ',
				esc_attr( isset( $values[ $key ] ) ? (string) $values[ $key ] : '0' )
			);
			printf( '<input type="date" name="valid_from" value="%s" /> ', esc_attr( gmdate( 'Y-m-d' ) ) );
			printf( '<input type="text" name="note" placeholder="%s" size="18" /> ', esc_attr__( 'Begründung', 'lindenweg16' ) );
			printf( '<button class="button button-primary">%s</button>', esc_html__( 'Festschreiben', 'lindenweg16' ) );
			echo '</form></td>';

			echo '<td>';
			if ( $history ) {
				echo '<ul style="margin:0">';
				foreach ( array_slice( $history, 0, 5 ) as $row ) {
					printf(
						'<li>%s: <strong>%s</strong>%s</li>',
						esc_html( mysql2date( 'd.m.Y', $row['valid_from'] ) ),
						esc_html( number_format_i18n( (float) $row['value_decimal'], 4 ) ),
						$row['note'] ? ' – ' . esc_html( $row['note'] ) : ''
					);
				}
				echo '</ul>';
			} else {
				echo '<em>' . esc_html__( 'Startwert', 'lindenweg16' ) . '</em>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Protokoll und Prüfung der Hash-Kette.
	 */
	protected function render_protokoll() {
		global $wpdb;

		echo '<h2>' . esc_html__( 'Kettenprüfung', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Jeder Protokolleintrag enthält den Hash seines Vorgängers. Wird ein Datensatz nachträglich geändert, bricht die Kette an dieser Stelle.', 'lindenweg16' ) . '</p>';

		$errors = BetriebAudit::verify_chain();
		if ( empty( $errors ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Kette unversehrt.', 'lindenweg16' ) . '</p></div>';
		} else {
			$first = reset( $errors );
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				sprintf(
					/* translators: 1: Datensatz-ID, 2: Fehlertext. */
					__( 'Erster Bruch bei Datensatz %1$d: %2$s', 'lindenweg16' ),
					(int) $first['id'],
					$first['error']
				)
			) . '</p></div>';
		}

		$table = BetriebAudit::table();
		$rows  = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE aktion LIKE 'betrieb_%' ORDER BY id DESC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		echo '<h2>' . esc_html__( 'Letzte 50 Vorgänge', 'lindenweg16' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Zeit', 'lindenweg16' ), __( 'Aktion', 'lindenweg16' ), __( 'Tabelle', 'lindenweg16' ), __( 'Datensatz', 'lindenweg16' ), __( 'Nutzer', 'lindenweg16' ), __( 'Begründung', 'lindenweg16' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Noch keine Vorgänge protokolliert.', 'lindenweg16' ) . '</td></tr>';
		}
		foreach ( (array) $rows as $row ) {
			$user = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( mysql2date( 'd.m.Y H:i', $row['erstellt_am'] ) ),
				esc_html( str_replace( 'betrieb_', '', $row['aktion'] ) ),
				esc_html( (string) $row['objekt_typ'] ),
				esc_html( (string) $row['objekt_id'] ),
				esc_html( $user ? $user->display_name : '—' ),
				esc_html( isset( $row['grund'] ) && $row['grund'] ? $row['grund'] : '—' )
			);
		}
		echo '</tbody></table>';
	}
}
