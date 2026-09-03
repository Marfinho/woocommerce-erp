<?php
/**
 * Lager: Bestand je Artikel und Farbe, Soll/Ist, Reichweite, Korrekturbuchung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Betrieb\BinRepository;
use Lindenweg16\Modules\Betrieb\ColorRegistry;
use Lindenweg16\Modules\Betrieb\StockLedger;
use Lindenweg16\Modules\Betrieb\StockRepository;

/**
 * Rendert die Lagerseite.
 */
class StockPage {

	const NONCE = 'lw16_betrieb_stock';
	const SLUG  = 'lw16-betrieb-lager';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_lw16_betrieb_lager', array( $this, 'render' ) );
		add_action( 'admin_post_lw16_betrieb_stock_move', array( $this, 'handle_move' ) );
		add_action( 'admin_post_lw16_betrieb_stock_void', array( $this, 'handle_void' ) );
		add_action( 'admin_post_lw16_betrieb_stock_target', array( $this, 'handle_target' ) );
	}

	/**
	 * Zugriff prüfen.
	 */
	protected function require_cap( $cap = 'lw16_manage_production' ) {
		if ( ! Roles::current_user_can_betrieb( $cap ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ) );
		}
	}

	/**
	 * Manuelle Korrekturbuchung.
	 */
	public function handle_move() {
		$this->require_cap( 'lw16_capture' );
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$article = isset( $_POST['article_id'] ) ? (int) $_POST['article_id'] : 0;
		$color   = isset( $_POST['color'] ) ? sanitize_text_field( wp_unslash( $_POST['color'] ) ) : '';
		$qty     = isset( $_POST['qty'] ) ? (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['qty'] ) ) ) : 0.0;
		$note    = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$bin     = isset( $_POST['bin_id'] ) ? (int) $_POST['bin_id'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $article || 0.0 === $qty ) {
			$this->redirect( __( 'Bitte Artikel und eine Menge ungleich 0 angeben.', 'lindenweg16' ), 'error' );
		}
		if ( '' === $note ) {
			$this->redirect( __( 'Eine Korrekturbuchung braucht eine Begründung.', 'lindenweg16' ), 'error' );
		}

		StockRepository::book(
			array(
				'article_id'    => $article,
				'color'         => $color,
				'qty'           => $qty,
				'movement_type' => 'korrektur',
				'location'      => 'lager',
				'bin_id'        => $bin,
				'ref_type'      => 'manual',
				'note'          => $note,
			)
		);

		$this->redirect( __( 'Korrektur gebucht.', 'lindenweg16' ), 'success' );
	}

	/**
	 * Bewegung stornieren.
	 */
	public function handle_void() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$id     = isset( $_POST['movement_id'] ) ? (int) $_POST['movement_id'] : 0;
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = StockRepository::void( $id, $reason );
		$this->redirect( $result['message'], $result['success'] ? 'success' : 'error' );
	}

	/**
	 * Sollbestand setzen.
	 */
	public function handle_target() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$article = isset( $_POST['article_id'] ) ? (int) $_POST['article_id'] : 0;
		$color   = isset( $_POST['color'] ) ? sanitize_text_field( wp_unslash( $_POST['color'] ) ) : '';
		$qty     = isset( $_POST['target_qty'] ) ? (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['target_qty'] ) ) ) : 0.0;
		$remove  = ! empty( $_POST['remove'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $article ) {
			$this->redirect( __( 'Bitte einen Artikel wählen.', 'lindenweg16' ), 'error' );
		}

		// Entfernen ist etwas anderes als „Soll 0": Bei 0 meldet die
		// Bedarfsplanung jeden Bestand darunter, es gibt aber keinen
		// Bestand unter 0. Ein entfernter Sollbestand meldet gar nicht mehr.
		if ( $remove ) {
			StockRepository::remove_target( $article, $color );
			$this->redirect( __( 'Sollbestand entfernt.', 'lindenweg16' ), 'success' );
		}

		StockRepository::set_target( $article, $color, $qty );

		$this->redirect( __( 'Sollbestand gespeichert.', 'lindenweg16' ), 'success' );
	}

	/**
	 * Zurück zur Seite.
	 *
	 * @param string $msg  Meldung.
	 * @param string $type Typ.
	 */
	protected function redirect( $msg, $type ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::SLUG,
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
		// Wie im Menue: Buchen ist Erfassen, und wer bucht, muss den
		// Bestand sehen, gegen den er bucht.
		$this->require_cap( 'lw16_capture' );

		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Lager', 'lindenweg16' ) . '</h1>';
		echo '<p>' . esc_html__( 'Der Bestand ist die Summe aller Bewegungen. Es gibt kein Bestandsfeld, das driften könnte – Korrekturen sind eigene Buchungen mit Begründung.', 'lindenweg16' ) . '</p>';

		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['lw16_msg'] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				isset( $_GET['lw16_typ'] ) && 'error' === $_GET['lw16_typ'] ? 'error' : 'success',
				esc_html( sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification

		$this->render_balances();
		$this->render_unresolved();
		$this->render_form();
		$this->render_movements();

		echo '</div>';
	}

	/**
	 * Bestände je Artikel, Farbe und Lagerort.
	 */
	protected function render_balances() {
		$movements = StockRepository::all();
		$balances  = StockLedger::balances( $movements );
		$articles  = ArticleRepository::all_by_id();

		$targets = array();
		foreach ( StockRepository::targets() as $target ) {
			$targets[ (int) $target['article_id'] ][ (string) $target['color'] ] = (float) $target['target_qty'];
		}

		echo '<h2>' . esc_html__( 'Bestand', 'lindenweg16' ) . '</h2>';

		if ( ! $balances ) {
			echo '<p>' . esc_html__( 'Noch keine Bewegungen gebucht.', 'lindenweg16' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Artikel', 'lindenweg16' ),
			__( 'Farbe', 'lindenweg16' ),
			__( 'Lager', 'lindenweg16' ),
			__( 'beim Händler', 'lindenweg16' ),
			__( 'unterwegs', 'lindenweg16' ),
			__( 'gesamt', 'lindenweg16' ),
			__( 'Soll', 'lindenweg16' ),
			__( 'Differenz', 'lindenweg16' ),
			__( 'Reichweite', 'lindenweg16' ),
			'',
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		// Ein Sollbestand ohne Bewegung waere sonst unsichtbar - und das ist
		// genau der haeufige Fall: Man setzt ihn fuer etwas, das gerade
		// nicht da ist. Deshalb zeigt die Tabelle die Vereinigung aus
		// beidem, nicht nur die Bewegungen.
		$zeilen = array();

		foreach ( $balances as $article_id => $colors ) {
			foreach ( $colors as $color => $unused ) {
				$zeilen[ (int) $article_id ][ (string) $color ] = true;
			}
		}

		foreach ( $targets as $article_id => $colors ) {
			foreach ( $colors as $color => $unused ) {
				$zeilen[ (int) $article_id ][ (string) $color ] = true;
			}
		}

		foreach ( $zeilen as $article_id => $colors ) {
			$consumption = StockRepository::consumption( $article_id, 4 );
			ksort( $colors );

			foreach ( $colors as $color => $unused ) {
				$balance = StockLedger::pick( $balances, $article_id, $color );
				$target  = isset( $targets[ $article_id ][ $color ] ) ? $targets[ $article_id ][ $color ] : null;
				$diff    = null === $target ? null : $balance['gesamt'] - $target;
				$reach   = StockLedger::coverage_weeks( $balance['lager'], $consumption, 4 );

				echo '<tr>';
				printf(
					'<td>%s</td>',
					esc_html( isset( $articles[ $article_id ] ) ? $articles[ $article_id ]['sku'] . ' – ' . $articles[ $article_id ]['name'] : '#' . $article_id )
				);
				printf( '<td>%s</td>', esc_html( ColorRegistry::label( $color ) ) );

				foreach ( array( 'lager', 'kommission', 'unterwegs' ) as $location ) {
					printf( '<td>%s</td>', esc_html( number_format_i18n( $balance[ $location ], 0 ) ) );
				}
				printf( '<td><strong>%s</strong></td>', esc_html( number_format_i18n( $balance['gesamt'], 0 ) ) );
				printf( '<td>%s</td>', esc_html( null === $target ? '—' : number_format_i18n( $target, 0 ) ) );

				if ( null === $diff ) {
					echo '<td>—</td>';
				} else {
					printf(
						'<td><span class="lw16-ampel lw16-ampel--%s">%s</span></td>',
						esc_attr( $diff < 0 ? 'rot' : 'gruen' ),
						esc_html( ( $diff > 0 ? '+' : '' ) . number_format_i18n( $diff, 0 ) )
					);
				}

				printf(
					'<td>%s</td>',
					esc_html( null === $reach ? __( 'kein Verbrauch', 'lindenweg16' ) : number_format_i18n( $reach, 1 ) . ' ' . __( 'Wochen', 'lindenweg16' ) )
				);

				// Sollbestand aendern oder entfernen - ohne diesen Knopf war
				// ein einmal gesetzter Wert nicht mehr zu erreichen.
				echo '<td>';
				if ( null !== $target ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
					wp_nonce_field( self::NONCE );
					echo '<input type="hidden" name="action" value="lw16_betrieb_stock_target" />';
					printf( '<input type="hidden" name="article_id" value="%d" />', (int) $article_id );
					printf( '<input type="hidden" name="color" value="%s" />', esc_attr( $color ) );
					printf(
						'<input type="text" name="target_qty" size="4" value="%s" /> <button class="button button-small">%s</button>',
						esc_attr( number_format_i18n( $target, 0 ) ),
						esc_html__( 'Soll ändern', 'lindenweg16' )
					);
					echo '</form>';

					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:4px">';
					wp_nonce_field( self::NONCE );
					echo '<input type="hidden" name="action" value="lw16_betrieb_stock_target" />';
					echo '<input type="hidden" name="remove" value="1" />';
					printf( '<input type="hidden" name="article_id" value="%d" />', (int) $article_id );
					printf( '<input type="hidden" name="color" value="%s" />', esc_attr( $color ) );
					printf( '<button class="button button-small">%s</button>', esc_html__( 'Soll entfernen', 'lindenweg16' ) );
					echo '</form>';
				}
				echo '</td>';

				echo '</tr>';
			}
		}

		echo '</tbody></table>';
	}

	/**
	 * Bewegungen ohne geklärte Farbe.
	 */
	protected function render_unresolved() {
		$rows = StockRepository::unresolved();

		if ( ! $rows ) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p><strong>';
		printf(
			/* translators: %d: Anzahl. */
			esc_html__( '%d Bewegung(en) ohne eindeutige Farbe', 'lindenweg16' ),
			count( $rows )
		);
		echo '</strong> — ' . esc_html__( 'sie sind gebucht, aber keiner Farbe zugeordnet. Farbattribut am Produkt prüfen und per Korrektur umbuchen.', 'lindenweg16' ) . '</p></div>';
	}

	/**
	 * Formulare für Korrektur und Sollbestand.
	 */
	protected function render_form() {
		$articles = ArticleRepository::all();

		if ( ! $articles ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Korrekturbuchung', 'lindenweg16' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_stock_move" />';
		echo '<p>';
		$this->article_select( $articles );
		$this->color_select();
		$this->bin_select();
		printf(
			'<input type="text" name="qty" placeholder="%s" size="8" /> ',
			esc_attr__( 'Menge ±', 'lindenweg16' )
		);
		printf(
			'<input type="text" name="note" placeholder="%s" size="30" /> ',
			esc_attr__( 'Begründung (Pflicht)', 'lindenweg16' )
		);
		printf( '<button class="button button-primary">%s</button>', esc_html__( 'Buchen', 'lindenweg16' ) );
		echo '</p></form>';

		echo '<h2>' . esc_html__( 'Sollbestand', 'lindenweg16' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_betrieb_stock_target" />';
		echo '<p>';
		$this->article_select( $articles );
		$this->color_select();
		printf(
			'<input type="text" name="target_qty" placeholder="%s" size="8" /> ',
			esc_attr__( 'Sollmenge', 'lindenweg16' )
		);
		printf( '<button class="button">%s</button>', esc_html__( 'Speichern', 'lindenweg16' ) );
		echo '</p></form>';
	}

	/**
	 * Lagerplatzauswahl für die Korrekturbuchung.
	 *
	 * Ohne dieses Feld liesse sich ein Erstbestand nur am Handy einräumen.
	 * Der Platz ist auch hier eine Angabe an der Buchung, kein zweites
	 * Bestandsfeld – „ohne Platz" bleibt deshalb erlaubt.
	 */
	protected function bin_select() {
		$bins = BinRepository::all( true );

		if ( ! $bins ) {
			return;
		}

		echo '<select name="bin_id">';
		printf( '<option value="0">%s</option>', esc_html__( '— ohne Platz —', 'lindenweg16' ) );

		foreach ( $bins as $bin ) {
			printf(
				'<option value="%d">%s</option>',
				(int) $bin['id'],
				esc_html( trim( $bin['code'] . ' ' . (string) $bin['name'] ) )
			);
		}

		echo '</select> ';
	}

	/**
	 * Artikelauswahl.
	 *
	 * @param array $articles Artikel.
	 */
	protected function article_select( array $articles ) {
		echo '<select name="article_id">';
		foreach ( $articles as $article ) {
			printf(
				'<option value="%d">%s – %s</option>',
				(int) $article['id'],
				esc_html( $article['sku'] ),
				esc_html( $article['name'] )
			);
		}
		echo '</select> ';
	}

	/**
	 * Farbauswahl.
	 */
	protected function color_select() {
		echo '<select name="color">';
		foreach ( ColorRegistry::options( true ) as $slug => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $slug ), esc_html( $label ) );
		}
		echo '</select> ';
	}

	/**
	 * Letzte Bewegungen.
	 */
	protected function render_movements() {
		$rows     = StockRepository::recent( 60 );
		$articles = ArticleRepository::all_by_id();
		$types    = StockLedger::movement_labels();
		$places   = StockLedger::location_labels();

		$bins = array();
		foreach ( \Lindenweg16\Modules\Betrieb\BinRepository::all() as $bin ) {
			$bins[ (int) $bin['id'] ] = (string) $bin['code'];
		}

		echo '<h2>' . esc_html__( 'Letzte Bewegungen', 'lindenweg16' ) . '</h2>';

		if ( ! $rows ) {
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Zeit', 'lindenweg16' ),
			__( 'Artikel', 'lindenweg16' ),
			__( 'Farbe', 'lindenweg16' ),
			__( 'Menge', 'lindenweg16' ),
			__( 'Art', 'lindenweg16' ),
			__( 'Ort', 'lindenweg16' ),
			__( 'Platz', 'lindenweg16' ),
			__( 'Herkunft', 'lindenweg16' ),
			__( 'Bemerkung', 'lindenweg16' ),
			'',
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$qty = (float) $row['qty'];

			echo '<tr>';
			printf( '<td>%s</td>', esc_html( mysql2date( 'd.m.Y H:i', $row['moved_at'] ) ) );
			printf(
				'<td>%s</td>',
				esc_html( isset( $articles[ (int) $row['article_id'] ] ) ? $articles[ (int) $row['article_id'] ]['sku'] : '#' . $row['article_id'] )
			);
			printf( '<td>%s</td>', esc_html( ColorRegistry::label( $row['color'] ) ) );
			printf(
				'<td><strong>%s</strong></td>',
				esc_html( ( $qty > 0 ? '+' : '' ) . number_format_i18n( $qty, 0 ) )
			);
			printf( '<td>%s</td>', esc_html( isset( $types[ $row['movement_type'] ] ) ? $types[ $row['movement_type'] ] : $row['movement_type'] ) );
			printf( '<td>%s</td>', esc_html( isset( $places[ $row['location'] ] ) ? $places[ $row['location'] ] : $row['location'] ) );
			printf(
				'<td>%s</td>',
				esc_html( $row['bin_id'] && isset( $bins[ (int) $row['bin_id'] ] ) ? $bins[ (int) $row['bin_id'] ] : '—' )
			);
			printf( '<td>%s %s</td>', esc_html( (string) $row['ref_type'] ), esc_html( (string) $row['ref_id'] ) );
			printf( '<td>%s</td>', esc_html( (string) $row['note'] . ( $row['void_reason'] ? ' · ' . $row['void_reason'] : '' ) ) );

			echo '<td>';
			if ( ! $row['reverses_id'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="action" value="lw16_betrieb_stock_void" />';
				printf( '<input type="hidden" name="movement_id" value="%d" />', (int) $row['id'] );
				printf(
					'<input type="text" name="reason" placeholder="%s" size="10" /> <button class="button button-small">%s</button>',
					esc_attr__( 'Grund', 'lindenweg16' ),
					esc_html__( 'Storno', 'lindenweg16' )
				);
				echo '</form>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
