<?php
/**
 * Verkauf → „Kurzlinks“: Links verwalten, Scans und Bestellungen nach Herkunft.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Kurzlinks\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Admin\MenuGroups;
use Lindenweg16\Modules\Kurzlinks\Links;
use Lindenweg16\Modules\Kurzlinks\OrderOrigin;
use Lindenweg16\Modules\Kurzlinks\Stats;

/**
 * Menüpunkt, Formular und Auswertungen.
 */
class KurzlinksPage {

	const SLUG       = 'lw16-kurzlinks';
	const NONCE      = 'lw16_kurzlinks_save';
	const ACTION     = 'lw16_kurzlinks_save';
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Menüpunkt unter Verkauf.
	 */
	public function add_menu() {
		add_submenu_page(
			MenuGroups::VERKAUF,
			__( 'Kurzlinks', 'lindenweg16' ),
			__( 'Kurzlinks', 'lindenweg16' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Zwischenspeicher für ein abgelehntes Formular (je Benutzer, kurz).
	 *
	 * @return string
	 */
	protected function form_key() {
		return 'lw16_kurzlinks_form_' . get_current_user_id();
	}

	/**
	 * Formular speichern. Bei einem Fehler wird nichts gespeichert.
	 */
	public function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt; Werte bereinigt Links::sanitize_row().
		$input = isset( $_POST['lw16_kurzlinks'] ) && is_array( $_POST['lw16_kurzlinks'] ) ? wp_unslash( $_POST['lw16_kurzlinks'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$rows = isset( $input['rows'] ) && is_array( $input['rows'] ) ? $input['rows'] : array();

		$result = Links::process(
			$rows,
			Links::all(),
			Links::reserved_slugs_for_site(),
			array( Links::class, 'content_exists' ),
			(string) wp_parse_url( home_url(), PHP_URL_HOST )
		);

		if ( $result['errors'] ) {
			set_transient(
				$this->form_key(),
				array(
					'rows'   => $rows,
					'errors' => $result['errors'],
				),
				5 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'fehler' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// Die Rewrite-Regel gleicht sich beim nächsten Aufruf selbst ab (Redirect::add_rewrite()).
		Links::save( $result['links'] );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'gespeichert' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Kurzlinks', 'lindenweg16' ) . '</h1>';
		echo '<p>' . esc_html__( 'Feste Adressen für Printmaterial (Paketbeilage, Flyer, Sticker, Stand). Die gedruckte Adresse bleibt, das Ziel lässt sich hier jederzeit ändern.', 'lindenweg16' ) . '</p>';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reine Anzeige einer Rückmeldung.
		if ( isset( $_GET['gespeichert'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Kurzlinks gespeichert.', 'lindenweg16' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->render_form();
		$this->render_scans();
		$this->render_origin();

		echo '</div>';
	}

	/**
	 * Formular mit allen Links und einer leeren Zeile für einen neuen.
	 */
	protected function render_form() {
		$stored = Links::all();
		$view   = array();
		$errors = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige.
		if ( isset( $_GET['fehler'] ) ) {
			$held = get_transient( $this->form_key() );
			delete_transient( $this->form_key() );

			if ( is_array( $held ) ) {
				$errors = $held['errors'];

				foreach ( $held['rows'] as $i => $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$view[ $i ] = array(
						'orig'         => isset( $row['orig'] ) ? (string) $row['orig'] : '',
						'slug'         => isset( $row['slug'] ) ? (string) $row['slug'] : '',
						'beschreibung' => isset( $row['beschreibung'] ) ? (string) $row['beschreibung'] : '',
						'seite_id'     => isset( $row['seite_id'] ) ? (int) $row['seite_id'] : 0,
						'url'          => isset( $row['url'] ) ? (string) $row['url'] : '',
						'aktiv'        => ! empty( $row['aktiv'] ),
					);
				}
			}
		}

		if ( ! $view ) {
			$i = 0;
			foreach ( $stored as $link ) {
				$view[ $i++ ] = array(
					'orig'         => $link['slug'],
					'slug'         => $link['slug'],
					'beschreibung' => $link['beschreibung'],
					'seite_id'     => $link['seite_id'],
					'url'          => $link['url'],
					'aktiv'        => $link['aktiv'],
				);
			}
			$view[ $i ] = array(
				'orig'         => '',
				'slug'         => '',
				'beschreibung' => '',
				'seite_id'     => 0,
				'url'          => '',
				'aktiv'        => true,
			);
		}

		if ( $errors ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Nichts gespeichert.', 'lindenweg16' ) . '</strong> ' . esc_html__( 'Bitte die markierten Zeilen korrigieren.', 'lindenweg16' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::NONCE );

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Adresse', 'lindenweg16' ), __( 'Beschreibung', 'lindenweg16' ), __( 'Ziel', 'lindenweg16' ), __( 'Aktiv', 'lindenweg16' ), __( 'Löschen', 'lindenweg16' ) ) as $th ) {
			echo '<th>' . esc_html( $th ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $view as $i => $row ) {
			$this->render_row( (int) $i, $row, isset( $errors[ $i ] ) ? $errors[ $i ] : array() );
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Ein bestehender Slug lässt sich nicht umbenennen, weil er schon gedruckt ist. Ein deaktivierter Link liefert „Seite nicht gefunden“. Löschen nur, wenn der Link nie gedruckt wurde.', 'lindenweg16' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Ziel auf dieser Website: Die Parameter der Anfrage (z. B. ?utm_source=beilage) werden angehängt, damit WooCommerce die Bestellherkunft erfasst. Externe Ziele (z. B. Google) bekommen keine Parameter.', 'lindenweg16' ) . '</p>';
		submit_button( __( 'Speichern', 'lindenweg16' ) );
		echo '</form>';
	}

	/**
	 * Eine Zeile des Formulars.
	 *
	 * @param int   $i      Zeilennummer.
	 * @param array $row    Anzuzeigende Werte.
	 * @param array $errors Fehlermeldungen der Zeile.
	 */
	protected function render_row( $i, array $row, array $errors ) {
		$name = 'lw16_kurzlinks[rows][' . $i . ']';
		$neu  = '' === $row['orig'];

		echo '<tr' . ( $errors ? ' style="background:#fef7f7"' : '' ) . '><td>';

		if ( $neu ) {
			echo '<code>' . esc_html( home_url( '/' ) ) . '</code>';
			echo '<input type="text" name="' . esc_attr( $name ) . '[slug]" value="' . esc_attr( $row['slug'] ) . '" placeholder="' . esc_attr__( 'neuer-slug', 'lindenweg16' ) . '" class="regular-text" style="width:10em" maxlength="64">';
		} else {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '[orig]" value="' . esc_attr( $row['orig'] ) . '">';
			echo '<a href="' . esc_url( home_url( '/' . $row['slug'] ) ) . '" target="_blank" rel="noopener"><code>' . esc_html( home_url( '/' . $row['slug'] ) ) . '</code></a>';
			echo '<br><span class="description">' . esc_html__( 'Für den QR-Code z. B.:', 'lindenweg16' ) . ' <code>?utm_source=beilage</code></span>';

			if ( $row['aktiv'] && Links::content_exists( $row['slug'] ) ) {
				echo '<br><span style="color:#b32d2e">' . esc_html__( 'Achtung: Unter diesem Pfad gibt es auch eine Seite oder einen Beitrag. Der Kurzlink hat Vorrang und verdeckt sie.', 'lindenweg16' ) . '</span>';
			}
		}

		echo '</td><td>';
		echo '<input type="text" name="' . esc_attr( $name ) . '[beschreibung]" value="' . esc_attr( $row['beschreibung'] ) . '" class="regular-text" placeholder="' . esc_attr__( 'z. B. Paketbeilage, Innenseite links', 'lindenweg16' ) . '">';
		echo '</td><td>';

		wp_dropdown_pages(
			array(
				'name'              => $name . '[seite_id]',
				'selected'          => (int) $row['seite_id'],
				'show_option_none'  => __( '— Startseite oder freie URL —', 'lindenweg16' ),
				'option_none_value' => '0',
				'post_status'       => 'publish',
			)
		);
		echo '<br><input type="text" name="' . esc_attr( $name ) . '[url]" value="' . esc_attr( $row['url'] ) . '" class="regular-text" placeholder="' . esc_attr__( 'oder freie URL: https://…', 'lindenweg16' ) . '">';

		if ( ! $neu && $row['seite_id'] > 0 && 'publish' !== get_post_status( $row['seite_id'] ) ) {
			echo '<br><span style="color:#b32d2e">' . esc_html__( 'Die gewählte Seite ist nicht veröffentlicht – bis dahin geht der Link auf die Startseite.', 'lindenweg16' ) . '</span>';
		} elseif ( ! $neu && 0 === (int) $row['seite_id'] && '' === $row['url'] ) {
			echo '<br><span class="description">' . esc_html__( 'Noch kein Ziel gewählt – Platzhalter: Startseite.', 'lindenweg16' ) . '</span>';
		}

		echo '</td><td>';
		echo '<input type="checkbox" name="' . esc_attr( $name ) . '[aktiv]" value="1"' . checked( $row['aktiv'], true, false ) . '>';
		echo '</td><td>';
		if ( ! $neu ) {
			echo '<input type="checkbox" name="' . esc_attr( $name ) . '[loeschen]" value="1">';
		}
		echo '</td></tr>';

		foreach ( $errors as $msg ) {
			echo '<tr style="background:#fef7f7"><td colspan="5" style="color:#b32d2e">' . esc_html( $msg ) . '</td></tr>';
		}
	}

	/**
	 * Scans je Link und Quelle.
	 */
	protected function render_scans() {
		$groups = Stats::group( Stats::totals() );

		echo '<h2>' . esc_html__( 'Aufrufe je Link und Quelle', 'lindenweg16' ) . '</h2>';

		if ( ! $groups ) {
			echo '<p>' . esc_html__( 'Noch keine Aufrufe gezählt.', 'lindenweg16' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Link', 'lindenweg16' ), __( 'Quelle', 'lindenweg16' ), __( '7 Tage', 'lindenweg16' ), __( '30 Tage', 'lindenweg16' ), __( 'Gesamt', 'lindenweg16' ) ) as $th ) {
				echo '<th>' . esc_html( $th ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $groups as $slug => $g ) {
				printf(
					'<tr><td><strong>/%s</strong></td><td><em>%s</em></td><td><strong>%s</strong></td><td><strong>%s</strong></td><td><strong>%s</strong></td></tr>',
					esc_html( $slug ),
					esc_html__( 'alle Quellen', 'lindenweg16' ),
					esc_html( number_format_i18n( $g['d7'] ) ),
					esc_html( number_format_i18n( $g['d30'] ) ),
					esc_html( number_format_i18n( $g['gesamt'] ) )
				);

				foreach ( $g['quellen'] as $q ) {
					printf(
						'<tr><td></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
						esc_html( '' === $q['quelle'] ? __( '(ohne Quelle)', 'lindenweg16' ) : $q['quelle'] ),
						esc_html( number_format_i18n( $q['d7'] ) ),
						esc_html( number_format_i18n( $q['d30'] ) ),
						esc_html( number_format_i18n( $q['gesamt'] ) )
					);
				}
			}

			echo '</tbody></table>';
		}

		echo '<p class="description">' . esc_html__( 'Gezählt wird serverseitig, anonym und je Tag: ohne IP-Adresse, ohne User-Agent, ohne Cookie, ohne JavaScript. HEAD-Anfragen und gängige Bots werden nicht mitgezählt. Es ist eine Zählung von Aufrufen, nicht von Personen.', 'lindenweg16' ) . '</p>';
	}

	/**
	 * Bestellungen nach Herkunft.
	 */
	protected function render_origin() {
		$ranges = OrderOrigin::ranges();
		$days   = 90;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige, Wert wird gegen die Liste geprüft.
		if ( isset( $_GET['herkunft'] ) && array_key_exists( (int) $_GET['herkunft'], $ranges ) ) {
			$days = (int) $_GET['herkunft']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		echo '<h2>' . esc_html__( 'Bestellungen nach Herkunft', 'lindenweg16' ) . '</h2>';

		echo '<p>';
		foreach ( $ranges as $d => $label ) {
			$url = add_query_arg( array( 'page' => self::SLUG, 'herkunft' => $d ), admin_url( 'admin.php' ) );
			echo $d === $days
				? '<strong>' . esc_html( $label ) . '</strong> | '
				: '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> | ';
		}
		echo '</p>';

		$rows = OrderOrigin::aggregate( OrderOrigin::collect( $days ) );

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Keine bezahlten Bestellungen im Zeitraum.', 'lindenweg16' ) . '</p>';
		} else {
			$sum_n = 0;
			$sum_u = 0.0;

			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Herkunft (utm_source)', 'lindenweg16' ), __( 'Bestellungen', 'lindenweg16' ), __( 'Umsatz (brutto, nach Erstattungen)', 'lindenweg16' ) ) as $th ) {
				echo '<th>' . esc_html( $th ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $rows as $quelle => $r ) {
				$sum_n += $r['anzahl'];
				$sum_u += $r['umsatz'];

				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $quelle ),
					esc_html( number_format_i18n( $r['anzahl'] ) ),
					esc_html( number_format_i18n( $r['umsatz'], 2 ) . ' €' )
				);
			}

			printf(
				'<tr><td><strong>%s</strong></td><td><strong>%s</strong></td><td><strong>%s</strong></td></tr>',
				esc_html__( 'Summe', 'lindenweg16' ),
				esc_html( number_format_i18n( $sum_n ) ),
				esc_html( number_format_i18n( $sum_u, 2 ) . ' €' )
			);

			echo '</tbody></table>';
		}

		echo '<p class="description">' . esc_html__( 'Gezählt werden bezahlte Bestellungen (in Bearbeitung, abgeschlossen). Die Herkunft kommt aus der WooCommerce-Bestellherkunft. Ohne Cookie-Einwilligung erfasst WooCommerce keine Herkunft; solche Bestellungen stehen als „unbekannt“ in der Liste – das ist kein Fehler. Die Scans oben und die Bestellungen hier ergeben deshalb nie eine vollständige Quote.', 'lindenweg16' ) . '</p>';
	}

	/**
	 * Kleines Widget fürs Dashboard: Aufrufe je Link.
	 */
	public function render_widget() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$groups = Stats::group( Stats::totals() );

		if ( ! $groups ) {
			return;
		}

		echo '<div class="lw16-card"><h2>' . esc_html__( 'Kurzlinks: Aufrufe', 'lindenweg16' ) . '</h2>';
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Link', 'lindenweg16' ) . '</th><th>' . esc_html__( '7 Tage', 'lindenweg16' ) . '</th><th>' . esc_html__( '30 Tage', 'lindenweg16' ) . '</th><th>' . esc_html__( 'Gesamt', 'lindenweg16' ) . '</th></tr></thead><tbody>';

		foreach ( $groups as $slug => $g ) {
			printf(
				'<tr><td>/%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $slug ),
				esc_html( number_format_i18n( $g['d7'] ) ),
				esc_html( number_format_i18n( $g['d30'] ) ),
				esc_html( number_format_i18n( $g['gesamt'] ) )
			);
		}

		echo '</tbody></table>';
		echo '<p class="lw16-hint"><a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Kurzlinks und Herkunft der Bestellungen', 'lindenweg16' ) . '</a></p></div>';
	}
}
