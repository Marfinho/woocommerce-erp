<?php
/**
 * Seite „Livegang" – Probedaten abräumen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\ResetTool;

/**
 * Vorschau und Ausführung des Zurücksetzens.
 */
class ResetPage {

	const NONCE = 'lw16_reset';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_render_page_lw16_livegang', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_reset_run', array( $this, 'handle_run' ) );
	}

	/**
	 * Zugriff prüfen.
	 *
	 * Bewusst enger als der Rest des Plugins: Wer Bewegungsdaten löschen
	 * darf, muss die Website auch sonst verwalten dürfen.
	 */
	protected function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Dieses Werkzeug ist Administratoren vorbehalten.', 'lindenweg16' ) );
		}
	}

	/**
	 * Zurück zur Seite.
	 *
	 * @param string $msg  Meldung.
	 * @param string $type success oder error.
	 */
	protected function redirect( $msg, $type = 'success' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'lw16-livegang',
					'lw16_msg' => rawurlencode( $msg ),
					'lw16_typ' => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Ausführen.
	 */
	public function handle_run() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$phrase = isset( $_POST['bestaetigung'] ) ? sanitize_text_field( wp_unslash( $_POST['bestaetigung'] ) ) : '';
		$ticket = isset( $_POST['ticket'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket'] ) ) : '';
		$scopes = isset( $_POST['bereich'] ) && is_array( $_POST['bereich'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['bereich'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! ResetTool::phrase_matches( $phrase ) ) {
			$this->redirect(
				sprintf(
					/* translators: %s: Bestätigungssatz. */
					__( 'Abgebrochen: Der Bestätigungssatz stimmt nicht. Es muss genau „%s" dort stehen.', 'lindenweg16' ),
					ResetTool::PHRASE
				),
				'error'
			);
		}

		// Der Schlüssel wird in jedem Fall verbraucht – auch bei Abbruch.
		// Ein zweiter Versuch beginnt bewusst wieder bei der Vorschau.
		if ( ! ResetTool::consume_ticket( $ticket ) ) {
			$this->redirect(
				__( 'Abgebrochen: Die Bestätigung ist abgelaufen oder gehört zu einer anderen Sitzung. Bitte die Vorschau neu aufrufen.', 'lindenweg16' ),
				'error'
			);
		}

		$result = ResetTool::run( $scopes );

		$this->redirect( $result['message'], $result['success'] ? 'success' : 'error' );
	}

	/* ====================================================================
	 * Ausgabe
	 * ================================================================== */

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Livegang', 'lindenweg16' ) . '</h1>';
		$this->notice();

		$history = ResetTool::history();

		echo '<p>' . esc_html__( 'Vor dem echten Start stehen im System Probedaten: Testbestellungen, Probebuchungen, Druckjobs zum Ausprobieren. Hier werden sie abgeräumt, damit die erste echte Rechnung die Nummer 1 trägt und die Auswertungen ab dem ersten Tag stimmen.', 'lindenweg16' ) . '</p>';

		$this->render_warning( $history );
		$this->render_keeps();
		$this->render_form();
		$this->render_history( $history );

		echo '</div>';
	}

	/**
	 * Meldung aus dem Umleitungsparameter.
	 */
	protected function notice() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_GET['lw16_msg'] ) ) {
			return;
		}
		$type = isset( $_GET['lw16_typ'] ) && 'error' === $_GET['lw16_typ'] ? 'error' : 'success';
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Warnung – und ab dem zweiten Mal eine deutlichere.
	 *
	 * @param array $history Bisherige Zurücksetzungen.
	 */
	protected function render_warning( array $history ) {
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Das lässt sich nicht rückgängig machen.', 'lindenweg16' ) . '</strong> ';
		echo esc_html__( 'Es gibt keinen Papierkorb und keine Wiederherstellung im Plugin. Lege vorher eine Sicherung der Datenbank an – über deinen Hoster oder ein Backup-Plugin.', 'lindenweg16' ) . '</p>';

		if ( $history ) {
			$last = end( $history );
			printf(
				'<p><strong>%s</strong> %s</p>',
				esc_html__( 'Achtung:', 'lindenweg16' ),
				esc_html(
					sprintf(
						/* translators: %s: Datum. */
						__( 'Es wurde bereits am %s zurückgesetzt. Wenn seitdem echte Geschäftsvorfälle gebucht wurden, darf dieses Werkzeug nicht mehr benutzt werden – Buchhaltungsdaten unterliegen der Aufbewahrungspflicht.', 'lindenweg16' ),
						mysql2date( 'd.m.Y H:i', $last['zeit'] )
					)
				)
			);
		}

		echo '</div>';
	}

	/**
	 * Was in jedem Fall erhalten bleibt.
	 */
	protected function render_keeps() {
		echo '<h2>' . esc_html__( 'Was bleibt', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Stammdaten werden nie gelöscht – sie sind Arbeit, die niemand zweimal machen will:', 'lindenweg16' ) . '</p>';

		echo '<ul class="ul-disc">';
		foreach ( array(
			__( 'Artikel, Revisionen, Stücklisten und Beschaffungsdaten', 'lindenweg16' ),
			__( 'Komponenten, Drucker, Filamentchargen und Lagerplätze', 'lindenweg16' ),
			__( 'Kalkulationsannahmen, Rabattstaffel und Sollbestände', 'lindenweg16' ),
			__( 'Kontenrahmen, Dauerbuchungen und Anlagevermögen', 'lindenweg16' ),
			__( 'Alle Einstellungen, Rollen und WooCommerce-Produkte', 'lindenweg16' ),
		) as $item ) {
			printf( '<li>%s</li>', esc_html( $item ) );
		}
		echo '</ul>';
	}

	/**
	 * Vorschau mit echten Zahlen und das Ausführungsformular.
	 */
	protected function render_form() {
		$preview  = ResetTool::preview();
		$scopes   = ResetTool::scopes();
		$defaults = ResetTool::default_scopes();
		$total    = 0;

		echo '<h2>' . esc_html__( 'Was gelöscht wird', 'lindenweg16' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_reset_run" />';
		printf( '<input type="hidden" name="ticket" value="%s" />', esc_attr( ResetTool::issue_ticket() ) );

		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		foreach ( array(
			'',
			__( 'Bereich', 'lindenweg16' ),
			__( 'Datensätze', 'lindenweg16' ),
			__( 'Was dazugehört', 'lindenweg16' ),
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $scopes as $key => $scope ) {
			$count  = isset( $preview[ $key ]['count'] ) ? (int) $preview[ $key ]['count'] : 0;
			$total += $count;

			echo '<tr>';
			printf(
				'<td><input type="checkbox" id="lw16-bereich-%1$s" name="bereich[]" value="%1$s"%2$s /></td>',
				esc_attr( $key ),
				in_array( $key, $defaults, true ) ? ' checked' : ''
			);
			printf(
				'<td><label for="lw16-bereich-%s"><strong>%s</strong></label></td>',
				esc_attr( $key ),
				esc_html( $scope['label'] )
			);
			printf(
				'<td><strong>%s</strong></td>',
				esc_html( number_format_i18n( $count, 0 ) )
			);
			printf( '<td><small>%s</small></td>', esc_html( $scope['hint'] ) );
			echo '</tr>';
		}

		echo '</tbody></table>';

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: Anzahl. */
					__( 'Insgesamt betroffen: %s Datensätze.', 'lindenweg16' ),
					number_format_i18n( $total, 0 )
				)
			)
		);

		echo '<h2>' . esc_html__( 'Bestätigen', 'lindenweg16' ) . '</h2>';

		printf(
			'<p><label for="lw16-bestaetigung">%s</label><br />'
			. '<input type="text" id="lw16-bestaetigung" name="bestaetigung" class="regular-text" autocomplete="off" placeholder="%s" /></p>',
			esc_html(
				sprintf(
					/* translators: %s: Bestätigungssatz. */
					__( 'Tippe zur Bestätigung %s in das Feld:', 'lindenweg16' ),
					ResetTool::PHRASE
				)
			),
			esc_attr( ResetTool::PHRASE )
		);

		echo '<p class="description">' . esc_html__( 'Die Bestätigung gilt zehn Minuten. Danach muss die Seite neu geladen werden – das verhindert, dass ein offener Tab von gestern noch etwas löscht.', 'lindenweg16' ) . '</p>';

		printf(
			'<p><button class="button button-primary button-large" style="background:#b32d2e;border-color:#b32d2e">%s</button></p>',
			esc_html__( 'Jetzt endgültig löschen', 'lindenweg16' )
		);

		echo '</form>';
	}

	/**
	 * Protokoll der bisherigen Zurücksetzungen.
	 *
	 * @param array $history Einträge.
	 */
	protected function render_history( array $history ) {
		if ( ! $history ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Protokoll', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Jeder Schnitt wird festgehalten und selbst nicht gelöscht. Eine Hash-Kette, die bei null beginnt, ist erklärbar – eine, die einfach kürzer geworden ist, nicht.', 'lindenweg16' ) . '</p>';

		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		foreach ( array(
			__( 'Zeitpunkt', 'lindenweg16' ),
			__( 'Benutzer', 'lindenweg16' ),
			__( 'Bereiche', 'lindenweg16' ),
			__( 'Gelöscht', 'lindenweg16' ),
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$scopes = ResetTool::scopes();

		foreach ( array_reverse( $history ) as $entry ) {
			$labels = array();
			foreach ( (array) $entry['bereiche'] as $key ) {
				$labels[] = isset( $scopes[ $key ] ) ? $scopes[ $key ]['label'] : $key;
			}

			$counts = array();
			foreach ( (array) $entry['mengen'] as $key => $count ) {
				$counts[] = sprintf( '%s: %d', isset( $scopes[ $key ] ) ? $scopes[ $key ]['label'] : $key, (int) $count );
			}

			echo '<tr>';
			printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y H:i', $entry['zeit'] ) ) );
			printf( '<td>%s</td>', esc_html( (string) $entry['benutzer'] ) );
			printf( '<td><small>%s</small></td>', esc_html( implode( ', ', $labels ) ) );
			printf( '<td><small>%s</small></td>', esc_html( implode( ' · ', $counts ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
