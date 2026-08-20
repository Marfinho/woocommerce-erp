<?php
/**
 * Lagerplätze und Picklisten.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\BinRepository;
use Lindenweg16\Modules\Betrieb\PicklistRepository;
use Lindenweg16\Modules\Betrieb\ScanResolver;
use Lindenweg16\Modules\Betrieb\StockRepository;
use Lindenweg16\Modules\Betrieb\WooIntegration;

/**
 * Verwaltung der Lagerplätze samt Etiketten und Picklisten.
 */
class BinsPage {

	const NONCE = 'lw16_betrieb_bins';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_lagerplaetze', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_betrieb_save_bin', array( $this, 'handle_save' ) );
		add_action( 'admin_post_lw16_betrieb_make_picklist', array( $this, 'handle_picklist' ) );
	}

	/**
	 * Zugriff prüfen.
	 */
	protected function require_cap() {
		if ( ! Roles::current_user_can_betrieb( 'lw16_manage_production' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Zurück zur Seite.
	 *
	 * @param string $msg  Meldung.
	 * @param string $type success oder error.
	 * @param array  $args Zusätzliche Parameter.
	 */
	protected function redirect( $msg, $type = 'success', array $args = array() ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'     => 'lw16-betrieb-lagerplaetze',
						'lw16_msg' => rawurlencode( $msg ),
						'lw16_typ' => $type,
					),
					$args
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Lagerplatz speichern.
	 */
	public function handle_save() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$id     = isset( $_POST['bin_id'] ) ? (int) $_POST['bin_id'] : 0;
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$input  = array_map(
			static function ( $value ) {
				return is_string( $value ) ? wp_unslash( $value ) : $value;
			},
			(array) $_POST
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = BinRepository::validate( $input, $id ? $id : null );

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error', $id ? array( 'edit' => $id ) : array() );
		}

		if ( $id ) {
			BinRepository::update( $id, $result['data'], $reason );
			$this->redirect( __( 'Lagerplatz gespeichert.', 'lindenweg16' ) );
		}

		BinRepository::insert( $result['data'] );
		$this->redirect( __( 'Lagerplatz angelegt. Etikett nicht vergessen.', 'lindenweg16' ) );
	}

	/**
	 * Pickliste aus den offenen Bestellungen erzeugen.
	 */
	public function handle_picklist() {
		$this->require_cap();
		check_admin_referer( self::NONCE . '_pick' );

		$lines = WooIntegration::open_order_lines();

		if ( ! $lines ) {
			$this->redirect( __( 'Keine offenen Bestellungen – es gibt nichts zu picken.', 'lindenweg16' ), 'error', array( 'view' => 'picklisten' ) );
		}

		$rows = PicklistRepository::condense( $lines, $this->bin_hints() );
		$id   = PicklistRepository::create( $rows );

		if ( ! $id ) {
			$this->redirect( __( 'Pickliste konnte nicht angelegt werden.', 'lindenweg16' ), 'error', array( 'view' => 'picklisten' ) );
		}

		$this->redirect(
			sprintf(
				/* translators: %d: Anzahl Positionen. */
				__( 'Pickliste mit %d Positionen angelegt. Sie steht jetzt am Handy bereit.', 'lindenweg16' ),
				count( $rows )
			),
			'success',
			array( 'view' => 'picklisten' )
		);
	}

	/**
	 * Wahrscheinlichster Lagerplatz je Artikel.
	 *
	 * Genommen wird der Platz mit dem größten Bestand. Das ist keine feste
	 * Zuordnung, sondern eine Vermutung aus den Bewegungen – ein Artikel darf
	 * an mehreren Plätzen liegen, und die Pickliste soll trotzdem eine
	 * sinnvolle Reihenfolge haben.
	 *
	 * @return array article_id => bin_id.
	 */
	protected function bin_hints() {
		$balances = BinRepository::balances( StockRepository::all() );
		$best     = array();

		foreach ( $balances as $bin_id => $articles ) {
			foreach ( $articles as $article_id => $colors ) {
				$sum = array_sum( $colors );
				if ( $sum <= 0 ) {
					continue;
				}
				if ( ! isset( $best[ $article_id ] ) || $sum > $best[ $article_id ]['qty'] ) {
					$best[ $article_id ] = array(
						'bin' => (int) $bin_id,
						'qty' => $sum,
					);
				}
			}
		}

		return array_map(
			static function ( $row ) {
				return $row['bin'];
			},
			$best
		);
	}

	/* ====================================================================
	 * Ausgabe
	 * ================================================================== */

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		// phpcs:disable WordPress.Security.NonceVerification
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'plaetze';
		$edit = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Lagerplätze & Picklisten', 'lindenweg16' ) . '</h1>';
		$this->notice();

		$base = admin_url( 'admin.php?page=lw16-betrieb-lagerplaetze' );
		echo '<nav class="lw16-tabs">';
		foreach ( array(
			'plaetze'    => __( 'Lagerplätze', 'lindenweg16' ),
			'etiketten'  => __( 'Etiketten', 'lindenweg16' ),
			'picklisten' => __( 'Picklisten', 'lindenweg16' ),
		) as $key => $label ) {
			printf(
				'<a href="%s" class="%s">%s</a> ',
				esc_url( add_query_arg( 'view', $key, $base ) ),
				$view === $key ? 'active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( 'etiketten' === $view ) {
			$this->render_labels();
		} elseif ( 'picklisten' === $view ) {
			$this->render_picklists();
		} else {
			$this->render_bins( $edit );
		}

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
	 * Liste und Formular der Lagerplätze.
	 *
	 * @param int $edit Zu bearbeitender Platz.
	 */
	protected function render_bins( $edit ) {
		$bins     = BinRepository::all();
		$balances = BinRepository::balances( StockRepository::all() );
		$articles = ArticleRepository::all_by_id();
		$current  = $edit ? BinRepository::find( $edit ) : null;

		if ( $bins ) {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array(
				__( 'Code', 'lindenweg16' ),
				__( 'Bezeichnung', 'lindenweg16' ),
				__( 'Art', 'lindenweg16' ),
				__( 'Liegt dort', 'lindenweg16' ),
				__( 'Status', 'lindenweg16' ),
				'',
			) as $head ) {
				echo '<th>' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $bins as $bin ) {
				$stock = BinRepository::stock_of( $balances, (int) $bin['id'] );
				$lines = array();
				foreach ( $stock as $article_id => $colors ) {
					foreach ( $colors as $color => $qty ) {
						$lines[] = sprintf(
							'%s %s: %s',
							isset( $articles[ $article_id ] ) ? $articles[ $article_id ]['sku'] : '?',
							$color,
							number_format_i18n( $qty, 0 )
						);
					}
				}

				echo '<tr>';
				printf( '<td><strong>%s</strong></td>', esc_html( $bin['code'] ) );
				printf( '<td>%s</td>', esc_html( (string) $bin['name'] ) );
				printf( '<td>%s</td>', esc_html( (string) $bin['kind'] ) );
				printf( '<td>%s</td>', $lines ? esc_html( implode( ' · ', $lines ) ) : '<span style="opacity:.5">—</span>' );
				printf(
					'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
					$bin['is_active'] ? 'gruen' : 'rot',
					$bin['is_active'] ? esc_html__( 'aktiv', 'lindenweg16' ) : esc_html__( 'stillgelegt', 'lindenweg16' )
				);
				printf(
					'<td><a href="%s">%s</a></td>',
					esc_url( add_query_arg( array( 'page' => 'lw16-betrieb-lagerplaetze', 'edit' => (int) $bin['id'] ), admin_url( 'admin.php' ) ) ),
					esc_html__( 'Bearbeiten', 'lindenweg16' )
				);
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Noch kein Lagerplatz angelegt. Ohne Plätze ist der Bestand da, aber niemand weiß wo.', 'lindenweg16' ) . '</p>';
		}

		echo '<h2>' . esc_html( $current ? __( 'Lagerplatz bearbeiten', 'lindenweg16' ) : __( 'Lagerplatz anlegen', 'lindenweg16' ) ) . '</h2>';
		echo '<p>' . esc_html__( 'Der Code steht auf dem Etikett und wird gescannt. Kurz halten: A01, KISTE3, VERSAND.', 'lindenweg16' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lw16-form">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_save_bin" />';
		printf( '<input type="hidden" name="bin_id" value="%d" />', $current ? (int) $current['id'] : 0 );

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->field( __( 'Code', 'lindenweg16' ), 'code', $current ? $current['code'] : '' );
		$this->field( __( 'Bezeichnung', 'lindenweg16' ), 'name', $current ? (string) $current['name'] : '' );

		echo '<tr><th scope="row">' . esc_html__( 'Art', 'lindenweg16' ) . '</th><td><select name="kind">';
		foreach ( BinRepository::KINDS as $kind ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $kind ),
				$current && $kind === $current['kind'] ? ' selected' : '',
				esc_html( $kind )
			);
		}
		echo '</select></td></tr>';

		$this->field( __( 'Sortierung', 'lindenweg16' ), 'sort_order', $current ? (string) $current['sort_order'] : '0' );

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="is_active" value="1"%s /> %s</label></td></tr>',
			esc_html__( 'Status', 'lindenweg16' ),
			! $current || $current['is_active'] ? ' checked' : '',
			esc_html__( 'aktiv', 'lindenweg16' )
		);

		if ( $current ) {
			$this->field( __( 'Begründung der Änderung', 'lindenweg16' ), 'reason', '' );
		}

		echo '</tbody></table>';
		printf( '<p><button class="button button-primary">%s</button></p>', esc_html__( 'Speichern', 'lindenweg16' ) );
		echo '</form>';
	}

	/**
	 * Eingabefeld.
	 *
	 * @param string $label Beschriftung.
	 * @param string $name  Feldname.
	 * @param string $value Wert.
	 */
	protected function field( $label, $name, $value ) {
		printf(
			'<tr><th scope="row"><label for="lw16-%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="lw16-%1$s" name="%1$s" value="%3$s" /></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value )
		);
	}

	/**
	 * Etiketten zum Ausdrucken.
	 */
	protected function render_labels() {
		$bins = BinRepository::all( true );

		echo '<h2>' . esc_html__( 'Etiketten', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Ausdrucken, zuschneiden, ankleben. Der QR-Code enthält den Platzcode – gescannt wird er in der mobilen Erfassung unter „Lager".', 'lindenweg16' ) . '</p>';

		if ( ! $bins ) {
			echo '<p>' . esc_html__( 'Keine aktiven Lagerplätze.', 'lindenweg16' ) . '</p>';
			return;
		}

		printf( '<p><button type="button" class="button" onclick="window.print()">%s</button></p>', esc_html__( 'Etiketten drucken', 'lindenweg16' ) );

		echo '<div class="lw16-labels">';
		foreach ( $bins as $bin ) {
			echo BinRepository::label( $bin ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapt in label().
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Artikeletiketten', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Für Kisten und Fächer, in denen ein bestimmter Artikel liegt. Zugekaufte Ware braucht kein eigenes Etikett – dort genügt der Barcode des Lieferanten, wenn er am Artikel hinterlegt ist.', 'lindenweg16' ) . '</p>';

		echo '<div class="lw16-labels">';
		foreach ( ArticleRepository::all() as $article ) {
			printf(
				'<div class="lw16-label lw16-label--bin"><div class="lw16-label__code">%s</div><div class="lw16-label__model">%s</div><div class="lw16-label__qr">%s</div></div>',
				esc_html( $article['sku'] ),
				esc_html( $article['name'] ),
				\Lindenweg16\Modules\Betrieb\DeviceLabel::qr( ScanResolver::PREFIX_ARTICLE . $article['sku'], 110, 'Q' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- erzeugtes SVG.
			);
		}
		echo '</div>';
	}

	/**
	 * Picklisten.
	 */
	protected function render_picklists() {
		echo '<h2>' . esc_html__( 'Picklisten', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Eine Pickliste fasst alles zusammen, was aus den offenen Bestellungen raus muss – sortiert nach Lagerplatz, nicht nach Bestellung. Gebucht wird erst beim Abhaken am Handy.', 'lindenweg16' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE . '_pick' );
		echo '<input type="hidden" name="action" value="lw16_betrieb_make_picklist" />';
		printf( '<p><button class="button button-primary">%s</button></p>', esc_html__( 'Pickliste aus offenen Bestellungen erzeugen', 'lindenweg16' ) );
		echo '</form>';

		$lists = PicklistRepository::all();

		if ( ! $lists ) {
			echo '<p>' . esc_html__( 'Noch keine Pickliste.', 'lindenweg16' ) . '</p>';
			return;
		}

		$articles = ArticleRepository::all_by_id();

		foreach ( $lists as $list ) {
			printf(
				'<h3>%s <span class="lw16-ampel lw16-ampel--%s">%s</span></h3>',
				esc_html( $list['code'] ),
				'offen' === $list['status'] ? 'gelb' : 'gruen',
				esc_html( $list['status'] )
			);

			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array(
				__( 'Platz', 'lindenweg16' ),
				__( 'Artikel', 'lindenweg16' ),
				__( 'Farbe', 'lindenweg16' ),
				__( 'Bestellung', 'lindenweg16' ),
				__( 'Soll', 'lindenweg16' ),
				__( 'Entnommen', 'lindenweg16' ),
			) as $head ) {
				echo '<th>' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( PicklistRepository::items( (int) $list['id'] ) as $item ) {
				$bin     = $item['bin_id'] ? BinRepository::find( (int) $item['bin_id'] ) : null;
				$article = isset( $articles[ (int) $item['article_id'] ] ) ? $articles[ (int) $item['article_id'] ] : null;

				echo '<tr>';
				printf( '<td>%s</td>', esc_html( $bin ? $bin['code'] : '—' ) );
				printf( '<td>%s</td>', esc_html( $article ? $article['sku'] . ' · ' . $article['name'] : '?' ) );
				printf( '<td>%s</td>', esc_html( (string) $item['color'] ) );
				printf( '<td>%s</td>', $item['wc_order_id'] ? esc_html( '#' . (int) $item['wc_order_id'] ) : '—' );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $item['qty_required'], 0 ) ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (float) $item['qty_picked'], 0 ) ) );
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}
}
