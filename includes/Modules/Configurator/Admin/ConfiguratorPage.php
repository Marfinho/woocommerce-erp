<?php
/**
 * Pflegeseite des Konfigurators.
 *
 * Hier wird festgelegt, welcher Artikel im Konfigurator als Basismodul
 * oder als Modul erscheint, wie hoch er baut, welches Schaumodell ihn
 * zeigt und wo am Basismodul kein Modul sitzen darf.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator\Admin;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Roles;
use Lindenweg16\Modules\Betrieb\ArticleRepository;
use Lindenweg16\Modules\Configurator\BlockRepository;
use Lindenweg16\Modules\Configurator\ConfiguratorModule;
use Lindenweg16\Modules\Configurator\Frontend;
use Lindenweg16\Modules\Configurator\Layout;
use Lindenweg16\Modules\Configurator\PartRepository;
use Lindenweg16\Modules\Configurator\WebModel;

/**
 * Rendert die Seite „Konfigurator" und verarbeitet ihre Formulare.
 */
class ConfiguratorPage {

	const NONCE = 'lw16_haken_teil';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_render_' . str_replace( '-', '_', ConfiguratorModule::PAGE_SLUG ), array( $this, 'render' ) );
		add_action( 'admin_post_lw16_haken_save_part', array( $this, 'handle_save' ) );
		add_action( 'admin_post_lw16_haken_delete_part', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_lw16_haken_add_block', array( $this, 'handle_add_block' ) );
		add_action( 'admin_post_lw16_haken_delete_block', array( $this, 'handle_delete_block' ) );
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
	 * @param int    $edit Teil, das offen bleiben soll.
	 */
	protected function redirect( $msg, $type, $edit = 0 ) {
		$args = array(
			'page'     => ConfiguratorModule::PAGE_SLUG,
			'lw16_msg' => rawurlencode( $msg ),
			'lw16_typ' => $type,
		);

		if ( $edit ) {
			$args['edit'] = (int) $edit;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Teil speichern – samt Modelldatei, wenn eine mitkommt.
	 */
	public function handle_save() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$id    = isset( $_POST['part_id'] ) ? (int) $_POST['part_id'] : 0;
		$input = array_map(
			static function ( $value ) {
				return is_string( $value ) ? wp_unslash( $value ) : $value;
			},
			(array) $_POST
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = PartRepository::validate( $input, $id ? $id : null );

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error', $id );
		}

		if ( $id ) {
			PartRepository::update( $id, $result['data'] );
		} else {
			$id = PartRepository::insert( $result['data'] );

			if ( ! $id ) {
				$this->redirect( __( 'Das Teil ließ sich nicht anlegen.', 'lindenweg16' ), 'error' );
			}
		}

		$message = __( 'Teil gespeichert.', 'lindenweg16' );

		if ( ! empty( $_FILES['model']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$article = ArticleRepository::find( (int) $result['data']['article_id'] );
			$stored  = WebModel::store( (array) $_FILES['model'], $article ? $article['sku'] : 'teil' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			if ( $stored['errors'] ) {
				$this->redirect( implode( ' ', $stored['errors'] ), 'error', $id );
			}

			$before = PartRepository::find( $id );
			PartRepository::set_model( $id, $stored['name'] );

			// Die abgelöste Datei wird erst entfernt, wenn die neue steht.
			if ( $before && $before['model_file'] ) {
				WebModel::delete( (string) $before['model_file'] );
			}

			$message = __( 'Teil und Modell gespeichert.', 'lindenweg16' );
		}

		$this->redirect( $message, 'success' );
	}

	/**
	 * Teil löschen.
	 */
	public function handle_delete() {
		$this->require_cap();
		check_admin_referer( self::NONCE . '_delete' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$id = isset( $_POST['part_id'] ) ? (int) $_POST['part_id'] : 0;

		if ( ! PartRepository::delete( $id ) ) {
			$this->redirect( __( 'Das Teil ließ sich nicht löschen.', 'lindenweg16' ), 'error' );
		}

		$this->redirect( __( 'Teil entfernt. Der Artikel selbst bleibt unverändert.', 'lindenweg16' ), 'success' );
	}

	/**
	 * Sperrbereich anlegen.
	 */
	public function handle_add_block() {
		$this->require_cap();
		check_admin_referer( self::NONCE . '_block' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$part_id = isset( $_POST['part_id'] ) ? (int) $_POST['part_id'] : 0;
		$input   = array(
			'from_mm' => isset( $_POST['from_mm'] ) ? wp_unslash( $_POST['from_mm'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'to_mm'   => isset( $_POST['to_mm'] ) ? wp_unslash( $_POST['to_mm'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'label'   => isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$part = PartRepository::find( $part_id );

		if ( ! $part || 'basis' !== $part['role'] ) {
			$this->redirect( __( 'Sperrbereiche gibt es nur am Basismodul.', 'lindenweg16' ), 'error' );
		}

		$result = BlockRepository::create( $part_id, $input, (float) $part['height_mm'] );

		if ( $result['errors'] ) {
			$this->redirect( implode( ' ', $result['errors'] ), 'error', $part_id );
		}

		$this->redirect( __( 'Sperrbereich angelegt.', 'lindenweg16' ), 'success', $part_id );
	}

	/**
	 * Sperrbereich löschen.
	 */
	public function handle_delete_block() {
		$this->require_cap();
		check_admin_referer( self::NONCE . '_block_delete' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- durch check_admin_referer abgedeckt.
		$block_id = isset( $_POST['block_id'] ) ? (int) $_POST['block_id'] : 0;
		$part_id  = isset( $_POST['part_id'] ) ? (int) $_POST['part_id'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		BlockRepository::delete( $block_id );

		$this->redirect( __( 'Sperrbereich entfernt.', 'lindenweg16' ), 'success', $part_id );
	}

	/**
	 * Seite ausgeben.
	 */
	public function render() {
		$this->require_cap();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$message = isset( $_GET['lw16_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['lw16_msg'] ) ) : '';
		$type    = isset( $_GET['lw16_typ'] ) && 'error' === $_GET['lw16_typ'] ? 'error' : 'success';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$parts    = PartRepository::all();
		$articles = ArticleRepository::all_by_id();
		$blocks   = BlockRepository::all_by_part();
		$edit     = $edit_id ? PartRepository::find( $edit_id ) : null;

		echo '<div class="wrap lw16-wrap">';
		echo '<h1>' . esc_html__( 'Konfigurator', 'lindenweg16' ) . '</h1>';

		if ( '' !== $message ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				esc_html( $message )
			);
		}

		$this->render_intro();
		$this->render_list( $parts, $articles, $blocks );
		$this->render_form( $edit, $articles );

		if ( $edit && 'basis' === $edit['role'] ) {
			$this->render_blocks( $edit, isset( $blocks[ (int) $edit['id'] ] ) ? $blocks[ (int) $edit['id'] ] : array() );
		}

		echo '</div>';
	}

	/**
	 * Kurze Anleitung – vor allem zur Herkunft der Modelle.
	 */
	protected function render_intro() {
		echo '<div class="notice notice-info inline"><p>';
		printf(
			/* translators: %s: Shortcode. */
			esc_html__( 'Eingebunden wird der Konfigurator mit dem Shortcode %s auf einer Seite.', 'lindenweg16' ),
			'<code>[' . esc_html( Frontend::SHORTCODE ) . ']</code>'
		);
		echo '</p><p>';
		esc_html_e( 'Die Modelle sind Schaustücke, keine Konstruktionsdaten: aus dem CAD als STL oder 3MF exportieren, im Netz auf wenige tausend Dreiecke reduzieren und als .glb hochladen. Innengeometrie, Gewinde und Toleranzen gehören dort nicht hinein – die Datei ist im Netz öffentlich abrufbar.', 'lindenweg16' );
		echo '</p><p>';
		esc_html_e( 'Ohne hochgeladenes Modell zeigt der Konfigurator einen maßhaltigen Ersatzkörper aus Höhe, Breite und Tiefe. Bestellen lässt sich damit alles, es sieht nur nicht nach dem Haken aus.', 'lindenweg16' );
		echo '</p></div>';
	}

	/**
	 * Liste der Teile.
	 *
	 * @param array $parts    Teile.
	 * @param array $articles Artikel nach ID.
	 * @param array $blocks   Sperrbereiche nach Teil.
	 */
	protected function render_list( array $parts, array $articles, array $blocks ) {
		echo '<h2>' . esc_html__( 'Teile', 'lindenweg16' ) . '</h2>';

		if ( ! $parts ) {
			echo '<p>' . esc_html__( 'Noch kein Teil angelegt. Unten das Basismodul anlegen, danach die Module.', 'lindenweg16' ) . '</p>';
			return;
		}

		$labels = PartRepository::role_labels();

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Artikel', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Rolle', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Höhe', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Modell', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Sperrbereiche', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Aktiv', 'lindenweg16' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $parts as $part ) {
			$article = isset( $articles[ (int) $part['article_id'] ] ) ? $articles[ (int) $part['article_id'] ] : null;
			$zones   = isset( $blocks[ (int) $part['id'] ] ) ? count( $blocks[ (int) $part['id'] ] ) : 0;

			echo '<tr>';
			printf(
				'<td><strong>%s</strong><br><span class="description">%s</span></td>',
				esc_html( $article ? $article['name'] : __( 'Artikel fehlt', 'lindenweg16' ) ),
				esc_html( $article ? $article['sku'] : '' )
			);
			printf( '<td>%s</td>', esc_html( isset( $labels[ $part['role'] ] ) ? $labels[ $part['role'] ] : $part['role'] ) );
			printf( '<td>%s mm</td>', esc_html( Layout::format_mm( $part['height_mm'] ) ) );
			printf(
				'<td>%s</td>',
				$part['model_file'] ? esc_html__( 'hinterlegt', 'lindenweg16' ) : esc_html__( 'Ersatzkörper', 'lindenweg16' )
			);
			printf( '<td>%s</td>', 'basis' === $part['role'] ? esc_html( (string) $zones ) : '—' );
			printf( '<td>%s</td>', $part['is_active'] ? esc_html__( 'ja', 'lindenweg16' ) : esc_html__( 'nein', 'lindenweg16' ) );
			printf(
				'<td><a class="button button-small" href="%s">%s</a></td>',
				esc_url(
					add_query_arg(
						array(
							'page' => ConfiguratorModule::PAGE_SLUG,
							'edit' => (int) $part['id'],
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Bearbeiten', 'lindenweg16' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Formular für ein Teil.
	 *
	 * @param array|null $edit     Teil in Bearbeitung.
	 * @param array      $articles Artikel nach ID.
	 */
	protected function render_form( $edit, array $articles ) {
		$labels = PartRepository::role_labels();
		$value  = static function ( $edit, $key, $default = '' ) {
			return $edit && isset( $edit[ $key ] ) ? $edit[ $key ] : $default;
		};

		echo '<h2>' . ( $edit ? esc_html__( 'Teil bearbeiten', 'lindenweg16' ) : esc_html__( 'Teil anlegen', 'lindenweg16' ) ) . '</h2>';

		printf( '<form method="post" enctype="multipart/form-data" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="lw16_haken_save_part">';
		printf( '<input type="hidden" name="part_id" value="%d">', (int) $value( $edit, 'id', 0 ) );

		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="lw16-artikel">' . esc_html__( 'Artikel', 'lindenweg16' ) . '</label></th><td>';
		echo '<select name="article_id" id="lw16-artikel">';
		foreach ( ArticleRepository::selectable( true ) as $article ) {
			printf(
				'<option value="%d" %s>%s (%s)</option>',
				(int) $article['id'],
				selected( (int) $article['id'], (int) $value( $edit, 'article_id', 0 ), false ),
				esc_html( $article['name'] ),
				esc_html( $article['sku'] )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Nur verkäufliche, aktive Artikel. Preis und Verfügbarkeit kommen aus dem Shop, nicht von hier.', 'lindenweg16' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Rolle', 'lindenweg16' ) . '</th><td>';
		foreach ( $labels as $role => $label ) {
			printf(
				'<label style="margin-right:1.5em"><input type="radio" name="role" value="%s" %s> %s</label>',
				esc_attr( $role ),
				checked( $role, (string) $value( $edit, 'role', 'modul' ), false ),
				esc_html( $label )
			);
		}
		echo '</td></tr>';

		$measures = array(
			'height_mm' => __( 'Höhe bzw. Länge (mm)', 'lindenweg16' ),
			'width_mm'  => __( 'Breite (mm)', 'lindenweg16' ),
			'depth_mm'  => __( 'Tiefe (mm)', 'lindenweg16' ),
		);

		foreach ( $measures as $key => $label ) {
			printf(
				'<tr><th scope="row"><label for="lw16-%1$s">%2$s</label></th><td><input type="number" step="0.01" min="0" name="%1$s" id="lw16-%1$s" value="%3$s" class="small-text"></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( (string) $value( $edit, $key, '' ) )
			);
		}

		printf(
			'<tr><th scope="row"><label for="lw16-max">%s</label></th><td><input type="number" step="1" min="0" max="%d" name="max_modules" id="lw16-max" value="%s" class="small-text"><p class="description">%s</p></td></tr>',
			esc_html__( 'Module am Basismodul höchstens', 'lindenweg16' ),
			(int) Layout::MAX_MODULES,
			esc_attr( (string) $value( $edit, 'max_modules', 0 ) ),
			esc_html__( '0 heißt: so viele, wie der Platz hergibt.', 'lindenweg16' )
		);

		printf(
			'<tr><th scope="row"><label for="lw16-teaser">%s</label></th><td><input type="text" name="teaser" id="lw16-teaser" value="%s" class="regular-text"><p class="description">%s</p></td></tr>',
			esc_html__( 'Kurzbeschreibung', 'lindenweg16' ),
			esc_attr( (string) $value( $edit, 'teaser', '' ) ),
			esc_html__( 'Eine Zeile, die im Konfigurator unter dem Namen steht.', 'lindenweg16' )
		);

		printf(
			'<tr><th scope="row"><label for="lw16-sort">%s</label></th><td><input type="number" step="1" min="0" name="sort_order" id="lw16-sort" value="%s" class="small-text"></td></tr>',
			esc_html__( 'Reihenfolge', 'lindenweg16' ),
			esc_attr( (string) $value( $edit, 'sort_order', 0 ) )
		);

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="is_active" value="1" %s> %s</label></td></tr>',
			esc_html__( 'Aktiv', 'lindenweg16' ),
			checked( 1, (int) $value( $edit, 'is_active', 1 ), false ),
			esc_html__( 'Im Konfigurator anbieten', 'lindenweg16' )
		);

		echo '<tr><th scope="row"><label for="lw16-modell">' . esc_html__( 'Schaumodell (.glb)', 'lindenweg16' ) . '</label></th><td>';
		echo '<input type="file" name="model" id="lw16-modell" accept=".glb">';

		if ( $edit && $edit['model_file'] ) {
			printf(
				'<p class="description">%s <code>%s</code></p>',
				esc_html__( 'Hinterlegt:', 'lindenweg16' ),
				esc_html( (string) $edit['model_file'] )
			);
		}

		echo '<p class="description">' . esc_html__( 'Millimeter als Einheit, Ursprung an der Unterkante der Montagefläche, Y nach oben. Nur so sitzt jedes Modul ohne Nacharbeit an seiner Position.', 'lindenweg16' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( $edit ? __( 'Teil speichern', 'lindenweg16' ) : __( 'Teil anlegen', 'lindenweg16' ) );
		echo '</form>';

		if ( ! $edit ) {
			return;
		}

		printf( '<form method="post" action="%s" onsubmit="return confirm(\'%s\');">', esc_url( admin_url( 'admin-post.php' ) ), esc_js( __( 'Teil wirklich aus dem Konfigurator entfernen?', 'lindenweg16' ) ) );
		wp_nonce_field( self::NONCE . '_delete' );
		echo '<input type="hidden" name="action" value="lw16_haken_delete_part">';
		printf( '<input type="hidden" name="part_id" value="%d">', (int) $edit['id'] );
		printf( '<button type="submit" class="button-link delete">%s</button>', esc_html__( 'Teil aus dem Konfigurator entfernen', 'lindenweg16' ) );
		echo '</form>';
	}

	/**
	 * Sperrbereiche eines Basismoduls.
	 *
	 * @param array $part   Teil.
	 * @param array $blocks Bereiche.
	 */
	protected function render_blocks( array $part, array $blocks ) {
		echo '<h2>' . esc_html__( 'Sperrbereiche', 'lindenweg16' ) . '</h2>';
		echo '<p>' . esc_html__( 'Abschnitte des Basismoduls, auf denen kein Modul sitzen darf – üblicherweise die beiden Stellen, an denen der Haken an die Wand geschraubt wird. Gemessen von der Unterkante.', 'lindenweg16' ) . '</p>';

		if ( $blocks ) {
			echo '<table class="widefat striped" style="max-width:640px"><thead><tr>';
			echo '<th>' . esc_html__( 'Von', 'lindenweg16' ) . '</th>';
			echo '<th>' . esc_html__( 'Bis', 'lindenweg16' ) . '</th>';
			echo '<th>' . esc_html__( 'Bezeichnung', 'lindenweg16' ) . '</th>';
			echo '<th></th></tr></thead><tbody>';

			foreach ( $blocks as $block ) {
				echo '<tr>';
				printf( '<td>%s mm</td>', esc_html( Layout::format_mm( $block['from_mm'] ) ) );
				printf( '<td>%s mm</td>', esc_html( Layout::format_mm( $block['to_mm'] ) ) );
				printf( '<td>%s</td>', esc_html( $block['label'] ) );
				echo '<td>';
				printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
				wp_nonce_field( self::NONCE . '_block_delete' );
				echo '<input type="hidden" name="action" value="lw16_haken_delete_block">';
				printf( '<input type="hidden" name="block_id" value="%d">', (int) $block['id'] );
				printf( '<input type="hidden" name="part_id" value="%d">', (int) $part['id'] );
				printf( '<button type="submit" class="button-link delete">%s</button>', esc_html__( 'Löschen', 'lindenweg16' ) );
				echo '</form>';
				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		printf( '<form method="post" action="%s" style="margin-top:1em">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::NONCE . '_block' );
		echo '<input type="hidden" name="action" value="lw16_haken_add_block">';
		printf( '<input type="hidden" name="part_id" value="%d">', (int) $part['id'] );
		printf(
			'<label>%s <input type="number" step="0.01" min="0" name="from_mm" class="small-text"></label> ',
			esc_html__( 'von (mm)', 'lindenweg16' )
		);
		printf(
			'<label>%s <input type="number" step="0.01" min="0" name="to_mm" class="small-text"></label> ',
			esc_html__( 'bis (mm)', 'lindenweg16' )
		);
		printf(
			'<label>%s <input type="text" name="label" class="regular-text" placeholder="%s"></label> ',
			esc_html__( 'Bezeichnung', 'lindenweg16' ),
			esc_attr__( 'Wandbefestigung oben', 'lindenweg16' )
		);
		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Sperrbereich anlegen', 'lindenweg16' ) );
		echo '</form>';
	}
}
