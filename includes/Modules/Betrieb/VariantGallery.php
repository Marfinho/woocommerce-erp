<?php
/**
 * Produktseite: Beim Wählen einer Variante zeigt die Galerie alle Fotos
 * des Artikels dahinter.
 *
 * Die Fotos werden im ERP am Artikel gepflegt (ArticleImages). Hier wird für
 * jede Variante die Bildfolge vorbereitet – fertiges Galerie-Markup von
 * WooCommerce selbst (`wc_get_gallery_image_html`), damit Zoom, Lightbox
 * und Vorschaubilder genauso funktionieren wie bei der normalen Galerie.
 * Das Skript tauscht die Galerie und startet sie neu.
 *
 * Hat keine Variante eigene Fotos, passiert nichts: Es bleibt die Galerie
 * des Produkts.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;

/**
 * Bildfolgen je Shop-Variante für die Produktseite.
 */
class VariantGallery {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 30 );
	}

	/**
	 * Daten und Skript auf Produktseiten variabler Produkte.
	 */
	public function enqueue() {
		if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return;
		}

		$data = self::data( $product );

		if ( ! $data ) {
			return;
		}

		$deps = wp_script_is( 'wc-single-product', 'registered' ) ? array( 'jquery', 'wc-single-product' ) : array( 'jquery' );

		wp_enqueue_script(
			'lw16-variant-gallery',
			LW16_PLUGIN_URL . 'assets/js/variant-gallery.js',
			$deps,
			Assets::version( 'assets/js/variant-gallery.js' ),
			true
		);
		wp_add_inline_script( 'lw16-variant-gallery', 'window.LW16_VARIANT_GALLERY = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Bildfolgen eines Produkts.
	 *
	 * @param \WC_Product_Variable $product Produkt.
	 * @return array|null {sets: [Markup], variations: [{attrs, set}]}; null ohne eigene Fotos.
	 */
	public static function data( $product ) {
		$ctx   = OrderLines::cached_context();
		$group = isset( $ctx['groups'][ (int) $product->get_id() ] ) ? $ctx['groups'][ (int) $product->get_id() ] : array();

		if ( ! $group ) {
			return null;
		}

		$rows = ArticleImages::for_articles( array_keys( $group ) );

		if ( ! $rows ) {
			return null;
		}

		$default = array_values( array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', (array) $product->get_gallery_image_ids() ) ) ) );
		$sets    = array( implode( ',', $default ) => 0 );
		$markup  = array( self::markup( $default ) );
		$list    = array();

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( (int) $child_id );

			if ( ! $variation || 'publish' !== $variation->get_status() ) {
				continue;
			}

			$article = OrderLines::article_for_product_object( $variation, $ctx );
			$images  = isset( $rows[ $article ] ) ? ArticleImages::select( $rows[ $article ], OrderLines::variation_attributes( $variation ) ) : array();
			$key     = $images ? implode( ',', $images ) : implode( ',', $default );

			if ( ! isset( $sets[ $key ] ) ) {
				$sets[ $key ] = count( $markup );
				$markup[]     = self::markup( $images );
			}

			$list[] = array(
				'attrs' => (array) $variation->get_variation_attributes(),
				'set'   => $sets[ $key ],
			);
		}

		// Nur die Galerie des Produkts: nichts zu tauschen.
		if ( count( $markup ) < 2 ) {
			return null;
		}

		return array(
			'sets'       => $markup,
			'variations' => $list,
		);
	}

	/**
	 * Galerie-Markup einer Bildfolge.
	 *
	 * @param int[] $ids Mediendateien.
	 * @return string
	 */
	protected static function markup( array $ids ) {
		if ( ! $ids ) {
			return function_exists( 'wc_placeholder_img_src' )
				? '<div class="woocommerce-product-gallery__image--placeholder"><img src="' . esc_url( wc_placeholder_img_src( 'woocommerce_single' ) ) . '" alt="" class="wp-post-image" /></div>'
				: '';
		}

		$html = '';

		foreach ( array_values( $ids ) as $i => $id ) {
			$html .= wc_get_gallery_image_html( (int) $id, 0 === $i );
		}

		return $html;
	}
}
