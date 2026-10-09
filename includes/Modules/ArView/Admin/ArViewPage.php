<?php
/**
 * Verwaltung → „AR-Ansicht": Einstellungen und Auswertung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\ArView\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Admin\MenuGroups;
use Lindenweg16\Modules\ArView\Options;
use Lindenweg16\Modules\ArView\ProductMeta;
use Lindenweg16\Modules\ArView\Stats;
use Lindenweg16\Modules\ArView\Viewer;

/**
 * Menüpunkt, Formular und Zählertabelle.
 */
class ArViewPage {

	const SLUG   = 'lw16-arview';
	const NONCE  = 'lw16_arview_save';
	const ACTION = 'lw16_arview_save';

	/**
	 * Zeiträume der Auswertung: Tage => Beschriftung (0 = alles).
	 *
	 * @return array
	 */
	protected function ranges() {
		return array(
			30 => __( 'Letzte 30 Tage', 'lindenweg16' ),
			90 => __( 'Letzte 90 Tage', 'lindenweg16' ),
			0  => __( 'Gesamter Zeitraum', 'lindenweg16' ),
		);
	}

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Menüpunkt unter Verwaltung.
	 */
	public function add_menu() {
		add_submenu_page(
			MenuGroups::VERWALTUNG,
			__( 'AR-Ansicht', 'lindenweg16' ),
			__( 'AR-Ansicht', 'lindenweg16' ),
			LW16_CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Einstellungen speichern.
	 */
	public function handle_save() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt; Werte bereinigt Options::sanitize().
		$input = isset( $_POST['lw16_arview'] ) && is_array( $_POST['lw16_arview'] ) ? wp_unslash( $_POST['lw16_arview'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Ein nicht angehaktes Kästchen wird gar nicht gesendet.
		if ( ! array_key_exists( 'enabled', $input ) ) {
			$input['enabled'] = 0;
		}

		update_option( Options::OPTION, Options::sanitize( $input ) );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'gespeichert' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		if ( ! current_user_can( LW16_CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}

		$o = Options::get();

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'AR-Ansicht', 'lindenweg16' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige einer Rückmeldung.
		if ( isset( $_GET['gespeichert'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Einstellungen gespeichert.', 'lindenweg16' ) . '</p></div>';
		}

		$this->render_warnings( $o );
		$this->render_form( $o );
		$this->render_stats();

		echo '</div>';
	}

	/**
	 * Hinweise auf unvollständige Einrichtung.
	 *
	 * @param array $o Einstellungen.
	 */
	protected function render_warnings( array $o ) {
		if ( ! $o['enabled'] ) {
			return;
		}

		if ( '' === Options::viewer_url() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Es ist noch keine (veröffentlichte) Ziel-Seite gewählt. Solange das so bleibt, erscheint der Hinweis auf keiner Produktseite.', 'lindenweg16' ) . '</p></div>';
			return;
		}

		$page = get_post( (int) $o['page_id'] );

		if ( $page && ! has_shortcode( (string) $page->post_content, Viewer::SHORTCODE ) ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: Shortcode. */
					__( 'Die gewählte Seite enthält den Shortcode %s nicht. Bitte füge ihn dort ein, sonst landen Besucher auf einer leeren Seite.', 'lindenweg16' ),
					'<code>[' . Viewer::SHORTCODE . ']</code>'
				)
			) . '</p></div>';
		}
	}

	/**
	 * Einstellungsformular.
	 *
	 * @param array $o Einstellungen.
	 */
	protected function render_form( array $o ) {
		?>
		<h2><?php esc_html_e( 'Einstellungen', 'lindenweg16' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Funktion', 'lindenweg16' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="lw16_arview[enabled]" value="1" <?php checked( $o['enabled'] ); ?>>
							<?php esc_html_e( 'AR-Ansicht einschalten (Hinweis-Banner und AR-Seite)', 'lindenweg16' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lw16-arview-page"><?php esc_html_e( 'Ziel-Seite', 'lindenweg16' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_pages(
							array(
								'name'              => 'lw16_arview[page_id]',
								'id'                => 'lw16-arview-page',
								'selected'          => (int) $o['page_id'],
								'show_option_none'  => __( '— Seite wählen —', 'lindenweg16' ),
								'option_none_value' => '0',
							)
						);
						?>
						<p class="description">
							<?php
							echo wp_kses_post(
								sprintf(
									/* translators: %s: Shortcode. */
									__( 'Die Seite, auf der der Shortcode %s steht, zum Beispiel /an-deiner-wand.', 'lindenweg16' ),
									'<code>[' . Viewer::SHORTCODE . ']</code>'
								)
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lw16-arview-title"><?php esc_html_e( 'Banner: Titel', 'lindenweg16' ); ?></label></th>
					<td><input type="text" class="large-text" id="lw16-arview-title" name="lw16_arview[title]" value="<?php echo esc_attr( $o['title'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="lw16-arview-text"><?php esc_html_e( 'Banner: Text', 'lindenweg16' ); ?></label></th>
					<td><input type="text" class="large-text" id="lw16-arview-text" name="lw16_arview[text]" value="<?php echo esc_attr( $o['text'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="lw16-arview-yes"><?php esc_html_e( 'Knopf „Ja“', 'lindenweg16' ); ?></label></th>
					<td><input type="text" class="regular-text" id="lw16-arview-yes" name="lw16_arview[btn_yes]" value="<?php echo esc_attr( $o['btn_yes'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="lw16-arview-no"><?php esc_html_e( 'Knopf „Nein“', 'lindenweg16' ); ?></label></th>
					<td><input type="text" class="regular-text" id="lw16-arview-no" name="lw16_arview[btn_no]" value="<?php echo esc_attr( $o['btn_no'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="lw16-arview-days"><?php esc_html_e( 'Sperrdauer (Tage)', 'lindenweg16' ); ?></label></th>
					<td>
						<input type="number" id="lw16-arview-days" name="lw16_arview[lock_days]" value="<?php echo esc_attr( $o['lock_days'] ); ?>" min="<?php echo esc_attr( Options::DAYS_MIN ); ?>" max="<?php echo esc_attr( Options::DAYS_MAX ); ?>" step="1" class="small-text">
						<p class="description"><?php esc_html_e( 'So lange erscheint der Banner nach „Nein“ oder „Schließen“ auf keiner Produktseite. Standard: 7.', 'lindenweg16' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lw16-arview-delay"><?php esc_html_e( 'Verzögerung (Sekunden)', 'lindenweg16' ); ?></label></th>
					<td>
						<input type="number" id="lw16-arview-delay" name="lw16_arview[delay]" value="<?php echo esc_attr( $o['delay'] ); ?>" min="<?php echo esc_attr( Options::DELAY_MIN ); ?>" max="<?php echo esc_attr( Options::DELAY_MAX ); ?>" step="1" class="small-text">
						<p class="description"><?php esc_html_e( 'Der Banner erscheint nach dieser Zeit oder beim ersten Scrollen – je nachdem, was zuerst eintritt. Standard: 3.', 'lindenweg16' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Speichern', 'lindenweg16' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Zählertabelle.
	 */
	protected function render_stats() {
		$ranges = $this->ranges();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige, Wert wird gegen die Liste geprüft.
		$days = isset( $_GET['tage'] ) ? (int) $_GET['tage'] : 30;
		$days = array_key_exists( $days, $ranges ) ? $days : 30;

		$totals = Stats::totals( $days );

		// Nach Zahl der gezeigten Banner, die meisten zuerst.
		uasort(
			$totals,
			static function ( $a, $b ) {
				return $b[ Stats::EVENT_SHOWN ] <=> $a[ Stats::EVENT_SHOWN ];
			}
		);

		$sum = array_fill_keys( array_keys( Stats::events() ), 0 );
		?>
		<h2><?php esc_html_e( 'Auswertung', 'lindenweg16' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Anonyme Tageszähler je Produkt. Es werden weder IP-Adressen noch Benutzer- oder Gerätedaten gespeichert. „AR gestartet“ zählt das Antippen des Knopfs „An meiner Wand platzieren“.', 'lindenweg16' ); ?></p>

		<p>
			<?php foreach ( $ranges as $d => $label ) : ?>
				<a class="button <?php echo $d === $days ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tage' => $d ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</p>

		<table class="widefat striped" style="max-width:900px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Produkt', 'lindenweg16' ); ?></th>
					<?php foreach ( Stats::events() as $label ) : ?>
						<th style="text-align:right"><?php echo esc_html( $label ); ?></th>
					<?php endforeach; ?>
					<th style="text-align:right"><?php esc_html_e( 'Ja-Quote', 'lindenweg16' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $totals ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Noch keine Zahlen im gewählten Zeitraum.', 'lindenweg16' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $totals as $product_id => $row ) : ?>
					<?php
					foreach ( $row as $event => $count ) {
						$sum[ $event ] += $count;
					}
					$title = get_the_title( $product_id );
					$title = '' !== $title ? $title : sprintf( /* translators: %d: Produkt-ID. */ __( 'Produkt #%d (gelöscht)', 'lindenweg16' ), $product_id );
					$rate  = Stats::rate( $row[ Stats::EVENT_YES ], $row[ Stats::EVENT_SHOWN ] );
					?>
					<tr>
						<td><?php echo esc_html( $title ); ?></td>
						<?php foreach ( $row as $count ) : ?>
							<td style="text-align:right"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
						<?php endforeach; ?>
						<td style="text-align:right"><?php echo null === $rate ? '–' : esc_html( number_format_i18n( $rate, 1 ) . ' %' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<?php if ( count( $totals ) > 1 ) : ?>
				<tfoot>
					<tr>
						<th><?php esc_html_e( 'Gesamt', 'lindenweg16' ); ?></th>
						<?php foreach ( $sum as $count ) : ?>
							<th style="text-align:right"><?php echo esc_html( number_format_i18n( $count ) ); ?></th>
						<?php endforeach; ?>
						<?php $total_rate = Stats::rate( $sum[ Stats::EVENT_YES ], $sum[ Stats::EVENT_SHOWN ] ); ?>
						<th style="text-align:right"><?php echo null === $total_rate ? '–' : esc_html( number_format_i18n( $total_rate, 1 ) . ' %' ); ?></th>
					</tr>
				</tfoot>
			<?php endif; ?>
		</table>
		<?php
	}
}
