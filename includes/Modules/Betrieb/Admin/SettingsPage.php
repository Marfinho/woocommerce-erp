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
use Lindenweg16\Core\Settings;
use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\StockOwnership;
use Lindenweg16\Modules\Betrieb\BetriebAudit;
use Lindenweg16\Modules\Betrieb\CalcSettings;
use Lindenweg16\Modules\Betrieb\Consistency;
use Lindenweg16\Modules\Betrieb\PriceTierRepository;

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
		add_action( 'admin_post_lw16_betrieb_save_lager', array( $this, 'handle_save_lager' ) );
		add_action( 'admin_post_lw16_betrieb_save_tiers', array( $this, 'handle_save_tiers' ) );
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
	protected function redirect_back( $msg, $type, $view = '' ) {
		$args = array(
			'page'     => 'lw16-betrieb-einstellungen',
			'lw16_msg' => rawurlencode( $msg ),
			'lw16_typ' => $type,
		);

		if ( $view ) {
			$args['view'] = $view;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
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
			'<a href="%s" class="%s">%s</a> ',
			esc_url( add_query_arg( 'view', 'lager', $base ) ),
			'lager' === $view ? 'active' : '',
			esc_html__( 'Farben & Lagerhoheit', 'lindenweg16' )
		);
		printf(
			'<a href="%s" class="%s">%s</a> ',
			esc_url( add_query_arg( 'view', 'staffel', $base ) ),
			'staffel' === $view ? 'active' : '',
			esc_html__( 'Rabattstaffel B2B', 'lindenweg16' )
		);
		printf(
			'<a href="%s" class="%s">%s</a> ',
			esc_url( add_query_arg( 'view', 'protokoll', $base ) ),
			'protokoll' === $view ? 'active' : '',
			esc_html__( 'Protokoll & Kettenprüfung', 'lindenweg16' )
		);
		printf(
			'<a href="%s" class="%s">%s</a>',
			esc_url( add_query_arg( 'view', 'systempruefung', $base ) ),
			'systempruefung' === $view ? 'active' : '',
			esc_html__( 'Systemprüfung', 'lindenweg16' )
		);
		echo '</nav>';

		$this->render_notice();

		if ( 'systempruefung' === $view ) {
			$this->render_systempruefung();
		} elseif ( 'protokoll' === $view ) {
			$this->render_protokoll();
		} elseif ( 'lager' === $view ) {
			$this->render_lager();
		} elseif ( 'staffel' === $view ) {
			$this->render_tiers();
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
	 * Farb- und Lagereinstellungen speichern.
	 */
	public function handle_save_lager() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$attribute = isset( $_POST['betrieb_color_attribute'] ) ? sanitize_key( wp_unslash( $_POST['betrieb_color_attribute'] ) ) : 'pa_farbe';
		$default   = isset( $_POST['betrieb_default_color'] ) ? sanitize_text_field( wp_unslash( $_POST['betrieb_default_color'] ) ) : 'anthrazit';
		$hoheit    = empty( $_POST['betrieb_lagerhoheit'] ) ? 0 : 1;
		$mail      = empty( $_POST['betrieb_wochenmail'] ) ? 0 : 1;
		$layout    = isset( $_POST['betrieb_reorder_layout'] ) ? sanitize_key( wp_unslash( $_POST['betrieb_reorder_layout'] ) ) : 'theme';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		Settings::update(
			array(
				'betrieb_color_attribute' => $attribute ? $attribute : 'pa_farbe',
				'betrieb_default_color'   => ColorRegistry::normalize( $default ),
				'betrieb_lagerhoheit'     => $hoheit,
				'betrieb_wochenmail'      => $mail,
				'betrieb_reorder_layout'  => 'schlicht' === $layout ? 'schlicht' : 'theme',
			)
		);

		$this->redirect_back( __( 'Einstellungen gespeichert.', 'lindenweg16' ), 'success' );
	}

	/**
	 * Farbsystem und Lagerhoheit.
	 */
	protected function render_lager() {
		echo '<h2>' . esc_html__( 'Farbsystem', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Die Farbe kommt aus einem globalen WooCommerce-Produktattribut. So gibt es sie nur einmal – im Shop sichtbar, an der Bestellposition auslesbar und im Lager buchbar. Intern wird immer der Slug gespeichert, nie der Anzeigename: Der darf sich ändern, ohne alte Buchungen zu entwerten.', 'lindenweg16' ) . '</p>';

		$taxonomy = ColorRegistry::taxonomy();
		$exists   = taxonomy_exists( $taxonomy );
		$colors   = ColorRegistry::all();

		if ( ! $exists ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: %s: Taxonomie-Slug. */
				esc_html__( 'Das Attribut %s existiert in WooCommerce noch nicht. Solange bucht alles auf die Standardfarbe. Anlegen unter Produkte → Attribute; als Slug genügt „farbe".', 'lindenweg16' ),
				'<code>' . esc_html( $taxonomy ) . '</code>'
			);
			echo '</p></div>';
		}

		echo '<h3>' . esc_html__( 'Bekannte Farben', 'lindenweg16' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:520px"><thead><tr>';
		printf( '<th>%s</th><th>%s</th>', esc_html__( 'Slug (gespeichert)', 'lindenweg16' ), esc_html__( 'Anzeigename', 'lindenweg16' ) );
		echo '</tr></thead><tbody>';
		foreach ( $colors as $slug => $label ) {
			printf(
				'<tr><td><code>%s</code>%s</td><td>%s</td></tr>',
				esc_html( $slug ),
				ColorRegistry::default_color() === $slug ? ' <em>(' . esc_html__( 'Standard', 'lindenweg16' ) . ')</em>' : '',
				esc_html( $label )
			);
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Lagerhoheit', 'lindenweg16' ) . '</h3>';
		echo '<p>' . esc_html__( 'Das Plugin hat zwei Lagermodelle: den fortgeschriebenen Bestand des Lager-Moduls und das Bewegungskonto von Betrieb. Beide auf dieselbe Bestellung loszulassen, würde doppelt abbuchen.', 'lindenweg16' ) . '</p>';
		echo '<p>' . esc_html__( 'Ist die Übernahme aktiv, bucht Betrieb genau die Produkte, die auf einen Artikel im Artikelstamm zeigen; das Lager-Modul überspringt sie. Alles andere bleibt unverändert beim Lager-Modul. Die Umstellung erfolgt damit je Artikel, nicht schlagartig.', 'lindenweg16' ) . '</p>';

		if ( ! StockOwnership::is_active() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Abgeschaltet: Das Lager-Modul bucht alle Abgänge. Das Bewegungskonto bleibt dann für Verkäufe leer und die Bestände im Betrieb stimmen nicht.', 'lindenweg16' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_save_lager" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="lw16-attr">%s</label></th><td><input type="text" name="betrieb_color_attribute" id="lw16-attr" value="%s" class="regular-text" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Farbattribut (Taxonomie)', 'lindenweg16' ),
			esc_attr( $taxonomy ),
			esc_html__( 'Globales Produktattribut, üblicherweise pa_farbe.', 'lindenweg16' )
		);

		printf(
			'<tr><th scope="row"><label for="lw16-defcolor">%s</label></th><td><input type="text" name="betrieb_default_color" id="lw16-defcolor" value="%s" class="regular-text" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Standardfarbe', 'lindenweg16' ),
			esc_attr( ColorRegistry::default_color() ),
			esc_html__( 'Gilt für jede Position ohne eigene Farbe. Solange nur eine Farbe produziert wird, ist das der Normalfall.', 'lindenweg16' )
		);

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="betrieb_lagerhoheit" value="1"%s /> %s</label></td></tr>',
			esc_html__( 'Lagerhoheit', 'lindenweg16' ),
			checked( StockOwnership::is_active(), true, false ),
			esc_html__( 'Betrieb bucht die Lagerabgänge der ihm bekannten Artikel', 'lindenweg16' )
		);

		$layout = Settings::get( 'betrieb_reorder_layout' );

		printf(
			'<tr><th scope="row">%1$s</th><td><select name="betrieb_reorder_layout">'
			. '<option value="theme"%2$s>%3$s</option>'
			. '<option value="schlicht"%4$s>%5$s</option>'
			. '</select><p class="description">%6$s</p></td></tr>',
			esc_html__( 'Nachbestellseite', 'lindenweg16' ),
			'schlicht' === $layout ? '' : ' selected',
			esc_html__( 'In das Website-Design einbetten', 'lindenweg16' ),
			'schlicht' === $layout ? ' selected' : '',
			esc_html__( 'Schlichte eigene Seite mit Logo', 'lindenweg16' ),
			esc_html__( 'Eingebettet sieht der Händler die vertraute Seite mit Kopfzeile, Logo und Navigation. Schlicht ist die Rückfallebene, falls das Theme mit dem Formular nicht zurechtkommt – dort steht das Logo aus den CI-Einstellungen.', 'lindenweg16' )
		);

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="betrieb_wochenmail" value="1"%s /> %s</label><p class="description">%s</p></td></tr>',
			esc_html__( 'Wochenmail', 'lindenweg16' ),
			checked( (bool) Settings::get( 'betrieb_wochenmail', 1 ), true, false ),
			esc_html__( 'Wöchentliche Zusammenfassung per E-Mail senden', 'lindenweg16' ),
			esc_html__( 'Kennzahlen, Alarme, Beschaffung, Wartung und Wiedervorlagen der vergangenen Woche. Läuft über den Action Scheduler.', 'lindenweg16' )
		);

		echo '</tbody></table>';
		printf( '<p><button class="button button-primary">%s</button></p></form>', esc_html__( 'Speichern', 'lindenweg16' ) );
	}

	/**
	 * Protokoll und Prüfung der Hash-Kette.
	 */
	/**
	 * Standard-Rabattstaffel speichern.
	 */
	public function handle_save_tiers() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$rows   = isset( $_POST['tier'] ) && is_array( $_POST['tier'] ) ? wp_unslash( $_POST['tier'] ) : array();
		$from   = isset( $_POST['valid_from'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_from'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = PriceTierRepository::validate( (array) $rows );

		if ( $result['errors'] ) {
			$this->redirect_back( implode( ' ', $result['errors'] ), 'error', 'staffel' );
		}

		$count = PriceTierRepository::replace_from( $result['tiers'], $from, $reason );

		$this->redirect_back(
			sprintf(
				/* translators: 1: Anzahl Stufen, 2: Datum. */
				__( '%1$d Stufen festgeschrieben, gültig ab %2$s. Die bisherige Staffel bleibt für zurückliegende Auswertungen erhalten.', 'lindenweg16' ),
				$count,
				mysql2date( 'd.m.Y', CalcSettings::sanitize_date( $from ) )
			),
			'success',
			'staffel'
		);
	}

	/**
	 * Standard-Rabattstaffel.
	 */
	protected function render_tiers() {
		// phpcs:disable WordPress.Security.NonceVerification
		$stichtag = isset( $_GET['stichtag'] ) ? sanitize_text_field( wp_unslash( $_GET['stichtag'] ) ) : gmdate( 'Y-m-d' );
		// phpcs:enable WordPress.Security.NonceVerification

		$stichtag = CalcSettings::sanitize_date( $stichtag );
		$current  = PriceTierRepository::at( $stichtag );

		echo '<h2>' . esc_html__( 'Standard-Rabattstaffel', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Gilt für alle Händler ohne Sondervereinbarung. Wer eigene Konditionen hat, bekommt sie unter Kontakte / B2B beim jeweiligen Kontakt – die gehen dieser Staffel vor.', 'lindenweg16' ) . '</p>';
		echo '<p>' . esc_html__( 'Der Rabatt gilt auf den Bruttopreis der rabattfähigen Positionen. Personalisierte Artikel lassen sich am Artikel von der Staffel ausnehmen.', 'lindenweg16' ) . '</p>';

		// Stichtagswahl.
		echo '<form method="get" style="margin:14px 0">';
		echo '<input type="hidden" name="page" value="lw16-betrieb-einstellungen" />';
		echo '<input type="hidden" name="view" value="staffel" />';
		printf(
			'<label>%s <input type="date" name="stichtag" value="%s" /></label> <button class="button">%s</button>',
			esc_html__( 'Gültig zum Stichtag:', 'lindenweg16' ),
			esc_attr( $stichtag ),
			esc_html__( 'Anzeigen', 'lindenweg16' )
		);
		echo '</form>';

		if ( ! $current ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Zu diesem Stichtag gilt keine Staffel – Händler zahlen dann den vollen Preis.', 'lindenweg16' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_save_tiers" />';

		echo '<table class="widefat striped" style="max-width:760px"><thead><tr>';
		foreach ( array(
			__( 'Bezeichnung', 'lindenweg16' ),
			__( 'ab Bestellwert (€ brutto)', 'lindenweg16' ),
			__( 'Rabatt (%)', 'lindenweg16' ),
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		// Bestehende Stufen plus zwei Reservezeilen zum Erweitern.
		$rows = $current;
		for ( $i = 0; $i < 2; $i++ ) {
			$rows[] = array(
				'tier_name'       => '',
				'min_order_value' => '',
				'discount_pct'    => '',
			);
		}

		foreach ( $rows as $index => $row ) {
			echo '<tr>';
			printf(
				'<td><input type="text" name="tier[%1$d][tier_name]" value="%2$s" class="regular-text" /></td>',
				(int) $index,
				esc_attr( (string) $row['tier_name'] )
			);
			printf(
				'<td><input type="text" name="tier[%1$d][min_order_value]" value="%2$s" size="10" /></td>',
				(int) $index,
				esc_attr( '' === $row['min_order_value'] ? '' : number_format_i18n( (float) $row['min_order_value'], 2 ) )
			);
			printf(
				'<td><input type="text" name="tier[%1$d][discount_pct]" value="%2$s" size="6" /></td>',
				(int) $index,
				esc_attr( '' === $row['discount_pct'] ? '' : number_format_i18n( (float) $row['discount_pct'], 1 ) )
			);
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<p>';
		printf(
			'<label>%s <input type="date" name="valid_from" value="%s" /></label> ',
			esc_html__( 'Neue Staffel gültig ab', 'lindenweg16' ),
			esc_attr( gmdate( 'Y-m-d' ) )
		);
		printf(
			'<input type="text" name="reason" placeholder="%s" size="30" /> ',
			esc_attr__( 'Begründung', 'lindenweg16' )
		);
		printf( '<button class="button button-primary">%s</button>', esc_html__( 'Festschreiben', 'lindenweg16' ) );
		echo '</p></form>';

		echo '<p class="description">' . esc_html__( 'Festschreiben überschreibt nichts: Die bisherigen Stufen werden zum Vortag abgeschlossen und bleiben stehen. Eine Auswertung von März rechnet weiter mit der März-Staffel.', 'lindenweg16' ) . '</p>';

		// --- Verlauf -------------------------------------------------------
		$history = PriceTierRepository::history();

		if ( ! $history ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Verlauf', 'lindenweg16' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:760px"><thead><tr>';
		foreach ( array(
			__( 'Gültig ab', 'lindenweg16' ),
			__( 'Gültig bis', 'lindenweg16' ),
			__( 'Stufe', 'lindenweg16' ),
			__( 'ab Bestellwert', 'lindenweg16' ),
			__( 'Rabatt', 'lindenweg16' ),
			__( 'Begründung', 'lindenweg16' ),
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $history as $row ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y', $row['valid_from'] ) ) );
			printf( '<td>%s</td>', $row['valid_until'] ? esc_html( mysql2date( 'd.m.Y', $row['valid_until'] ) ) : '—' );
			printf( '<td>%s</td>', esc_html( $row['tier_name'] ) );
			printf( '<td>%s €</td>', esc_html( number_format_i18n( (float) $row['min_order_value'], 2 ) ) );
			printf( '<td>%s %%</td>', esc_html( number_format_i18n( (float) $row['discount_pct'], 1 ) ) );
			printf( '<td>%s</td>', esc_html( (string) $row['note'] ) );
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Systemprüfung: Widersprüche im Zusammenspiel.
	 *
	 * Die Kettenprüfung nebenan beantwortet „wurde etwas manipuliert".
	 * Diese hier beantwortet „passt das alles noch zusammen" – eine
	 * andere Frage, die kein Formular beim Speichern stellen kann.
	 */
	protected function render_systempruefung() {
		$findings = Consistency::check( Consistency::collect() );
		$summe    = Consistency::summarize( $findings );

		echo '<h2>' . esc_html__( 'Systemprüfung', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Jedes Formular prüft seine eigene Eingabe. Diese Prüfung sucht Widersprüche, die erst später entstehen: ein Artikel, dessen Beschaffungsweg nachträglich geändert wurde, eine Verknüpfung auf ein gelöschtes Shop-Produkt, ein Bestand unter null.', 'lindenweg16' ) . '</p>';

		if ( ! $findings ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Keine Widersprüche gefunden.', 'lindenweg16' ) . '</p></div>';
			return;
		}

		printf(
			'<div class="notice notice-%s inline"><p>%s</p></div>',
			$summe['fehler'] > 0 ? 'error' : 'warning',
			esc_html(
				sprintf(
					/* translators: 1: Fehler, 2: Warnungen. */
					__( '%1$d Fehler, %2$d Warnungen. Ein Fehler blockiert einen Vorgang; eine Warnung ist etwas, das niemand mehr braucht.', 'lindenweg16' ),
					$summe['fehler'],
					$summe['warnung']
				)
			)
		);

		echo '<div class="lw16-scrollx"><table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Schwere', 'lindenweg16' ),
			__( 'Bereich', 'lindenweg16' ),
			__( 'Befund', 'lindenweg16' ),
			__( 'Was zu tun ist', 'lindenweg16' ),
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $findings as $finding ) {
			echo '<tr>';
			printf(
				'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
				'fehler' === $finding['level'] ? 'rot' : 'gelb',
				esc_html( 'fehler' === $finding['level'] ? __( 'Fehler', 'lindenweg16' ) : __( 'Warnung', 'lindenweg16' ) )
			);
			printf( '<td>%s</td>', esc_html( $finding['area'] ) );
			printf( '<td>%s</td>', esc_html( $finding['text'] ) );
			printf( '<td class="description">%s</td>', esc_html( $finding['fix'] ) );
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Protokoll und Kettenprüfung.
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
