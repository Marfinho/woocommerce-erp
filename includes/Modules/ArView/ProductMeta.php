<?php
/**
 * 3D-Modell je Produkt: Metabox im WooCommerce-Produkteditor.
 *
 * Gespeichert werden nur Anhang-IDs der Mediathek (GLB, USDZ, Poster) und
 * der Schalter für den Hinweis. Ob ein Produkt „ein Modell hat", entscheidet
 * allein die GLB-Datei – ohne sie gibt es weder Hinweis noch Eintrag in der
 * Produktauswahl der AR-Seite.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\ArView;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;

/**
 * Metabox, Speichern und Lesezugriff auf die Modelldaten.
 */
class ProductMeta {

	const META_GLB    = '_lw16_ar_glb';
	const META_USDZ   = '_lw16_ar_usdz';
	const META_POSTER = '_lw16_ar_poster';
	const META_HINT   = '_lw16_ar_hint';

	const NONCE  = 'lw16_ar_meta';
	const FIELD  = 'lw16_ar';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'add_meta_boxes_product', array( $this, 'add_box' ) );
		add_action( 'save_post_product', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Metabox anlegen.
	 */
	public function add_box() {
		add_meta_box(
			'lw16-ar-modell',
			__( '3D-Modell (AR)', 'lindenweg16' ),
			array( $this, 'render' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Skript für die Mediathek-Auswahl, nur auf dem Produkteditor.
	 *
	 * @param string $hook Admin-Hook.
	 */
	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			'lw16-ar-admin',
			LW16_PLUGIN_URL . 'assets/js/ar-admin.js',
			array(),
			Assets::version( 'assets/js/ar-admin.js' ),
			true
		);
		wp_localize_script(
			'lw16-ar-admin',
			'LW16_AR_ADMIN',
			array(
				'texts' => array(
					'glb'        => __( 'GLB-Datei wählen', 'lindenweg16' ),
					'usdz'       => __( 'USDZ-Datei wählen', 'lindenweg16' ),
					'poster'     => __( 'Posterbild wählen', 'lindenweg16' ),
					'use'        => __( 'Diese Datei verwenden', 'lindenweg16' ),
					'wrong_type' => __( 'Bitte eine Datei mit der Endung %s wählen.', 'lindenweg16' ),
					'none'       => __( 'Keine Datei gewählt', 'lindenweg16' ),
				),
			)
		);
	}

	/**
	 * Dateiname eines Anhangs für die Anzeige.
	 *
	 * @param int $id Anhang.
	 * @return string
	 */
	protected function label( $id ) {
		$file = $id ? get_attached_file( $id ) : '';

		return $file ? wp_basename( $file ) : '';
	}

	/**
	 * Ein Dateifeld der Metabox.
	 *
	 * @param string $key      Feldname (glb, usdz, poster).
	 * @param string $title    Beschriftung.
	 * @param string $accept   Erwartete Endungen für die Prüfung im Browser.
	 * @param int    $id       Aktuelle Anhang-ID.
	 * @param string $help     Hilfetext.
	 */
	protected function render_field( $key, $title, $accept, $id, $help ) {
		?>
		<div class="lw16-ar-feld" data-lw16-ar-feld="<?php echo esc_attr( $key ); ?>" data-accept="<?php echo esc_attr( $accept ); ?>">
			<p><strong><?php echo esc_html( $title ); ?></strong></p>
			<input type="hidden" name="<?php echo esc_attr( self::FIELD . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (int) $id ); ?>" data-lw16-ar-id>
			<p class="description" data-lw16-ar-name>
				<?php echo '' !== $this->label( $id ) ? esc_html( $this->label( $id ) ) : esc_html__( 'Keine Datei gewählt', 'lindenweg16' ); ?>
			</p>
			<p>
				<button type="button" class="button" data-lw16-ar-waehlen><?php esc_html_e( 'Auswählen', 'lindenweg16' ); ?></button>
				<button type="button" class="button-link-delete" data-lw16-ar-entfernen <?php echo $id ? '' : 'hidden'; ?>><?php esc_html_e( 'Entfernen', 'lindenweg16' ); ?></button>
			</p>
			<p class="description"><?php echo esc_html( $help ); ?></p>
		</div>
		<?php
	}

	/**
	 * Metabox ausgeben.
	 *
	 * @param \WP_Post $post Produkt.
	 */
	public function render( $post ) {
		$data = self::get( $post->ID, true );

		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );

		$this->render_field(
			'glb',
			__( 'GLB-Datei (Pflicht)', 'lindenweg16' ),
			'glb',
			$data['glb_id'],
			__( 'Maßstab 1 Einheit = 1 m, unkomprimiert exportiert (ohne Draco/Meshopt/KTX2).', 'lindenweg16' )
		);
		$this->render_field(
			'usdz',
			__( 'USDZ-Datei (optional)', 'lindenweg16' ),
			'usdz',
			$data['usdz_id'],
			__( 'Ohne USDZ erzeugt der Viewer sie auf dem iPhone automatisch aus der GLB.', 'lindenweg16' )
		);
		$this->render_field(
			'poster',
			__( 'Posterbild (optional)', 'lindenweg16' ),
			'jpg,jpeg,png,webp',
			$data['poster_id'],
			__( 'Wird gezeigt, bis das Modell geladen ist.', 'lindenweg16' )
		);
		?>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( self::FIELD . '[hint]' ); ?>" value="1" <?php checked( $data['hint'] ); ?>>
				<?php esc_html_e( 'AR-Hinweis auf dieser Produktseite anzeigen', 'lindenweg16' ); ?>
			</label>
		</p>
		<?php if ( ! $data['glb_id'] ) : ?>
			<p class="description"><?php esc_html_e( 'Ohne GLB-Datei erscheint nirgends ein Hinweis.', 'lindenweg16' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Gehört die Anhang-ID zu einer Datei mit einer der erlaubten Endungen?
	 *
	 * @param int   $id         Anhang.
	 * @param array $extensions Erlaubte Endungen, klein.
	 * @return bool
	 */
	public static function attachment_has_extension( $id, array $extensions ) {
		$id = (int) $id;

		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return false;
		}

		$file = get_attached_file( $id );

		return $file && in_array( UploadTypes::extension( $file ), $extensions, true );
	}

	/**
	 * Speichern.
	 *
	 * @param int      $post_id Produkt.
	 * @param \WP_Post $post    Beitrag.
	 */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || 'product' !== $post->post_type ) {
			return;
		}

		$input = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Werte werden einzeln auf Ganzzahlen bzw. 1 reduziert.

		$fields = array(
			'glb'    => array( self::META_GLB, array( 'glb' ) ),
			'usdz'   => array( self::META_USDZ, array( 'usdz' ) ),
			'poster' => array( self::META_POSTER, array( 'jpg', 'jpeg', 'png', 'webp' ) ),
		);

		foreach ( $fields as $key => $def ) {
			$id = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;

			if ( $id && self::attachment_has_extension( $id, $def[1] ) ) {
				update_post_meta( $post_id, $def[0], $id );
			} else {
				delete_post_meta( $post_id, $def[0] );
			}
		}

		if ( ! empty( $input['hint'] ) ) {
			update_post_meta( $post_id, self::META_HINT, '1' );
		} else {
			delete_post_meta( $post_id, self::META_HINT );
		}
	}

	/**
	 * Modelldaten eines Produkts.
	 *
	 * @param int  $product_id Produkt.
	 * @param bool $raw        true: auch ohne gültige Datei die IDs liefern (für die Metabox).
	 * @return array {glb_id, usdz_id, poster_id, hint, glb, usdz, poster}
	 */
	public static function get( $product_id, $raw = false ) {
		$product_id = (int) $product_id;

		$glb_id    = (int) get_post_meta( $product_id, self::META_GLB, true );
		$usdz_id   = (int) get_post_meta( $product_id, self::META_USDZ, true );
		$poster_id = (int) get_post_meta( $product_id, self::META_POSTER, true );

		$out = array(
			'glb_id'    => $glb_id,
			'usdz_id'   => $usdz_id,
			'poster_id' => $poster_id,
			'hint'      => '1' === (string) get_post_meta( $product_id, self::META_HINT, true ),
			'glb'       => '',
			'usdz'      => '',
			'poster'    => '',
		);

		if ( $raw ) {
			return $out;
		}

		// Nur Dateien, die es noch gibt: Wer das Modell aus der Mediathek
		// löscht, darf keinen Hinweis auf eine Seite ohne Modell hinterlassen.
		if ( self::attachment_has_extension( $glb_id, array( 'glb' ) ) ) {
			$out['glb'] = (string) wp_get_attachment_url( $glb_id );
		}

		if ( '' !== $out['glb'] && self::attachment_has_extension( $usdz_id, array( 'usdz' ) ) ) {
			$out['usdz'] = (string) wp_get_attachment_url( $usdz_id );
		}

		if ( '' !== $out['glb'] && $poster_id > 0 && wp_attachment_is_image( $poster_id ) ) {
			$out['poster'] = (string) wp_get_attachment_image_url( $poster_id, 'large' );
		}

		return $out;
	}

	/**
	 * Hat das Produkt ein verwendbares Modell?
	 *
	 * @param int $product_id Produkt.
	 * @return bool
	 */
	public static function has_model( $product_id ) {
		$data = self::get( $product_id );

		return '' !== $data['glb'];
	}

	/**
	 * Alle veröffentlichten Produkte mit Modell.
	 *
	 * @return int[] Produkt-IDs, nach Titel sortiert.
	 */
	public static function products_with_model() {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_GLB,
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return array_values(
			array_filter(
				array_map( 'intval', $ids ),
				array( __CLASS__, 'has_model' )
			)
		);
	}
}
