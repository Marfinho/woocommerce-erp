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
	 * Bildfolgen und Texte eines Produkts.
	 *
	 * Je Variante: die Fotos des Artikels dahinter (sonst die des
	 * Produkts), bei einem Set dahinter das Hauptbild jedes enthaltenen
	 * Moduls in der gewählten Variante. Dazu Kurztext und Beschreibung des
	 * Artikels, wenn welche gepflegt sind.
	 *
	 * @param \WC_Product_Variable $product Produkt.
	 * @return array|null {sets, texts, variations: [{attrs, set, text}]}; null, wenn nichts zu tauschen ist.
	 */
	public static function data( $product ) {
		$ctx   = OrderLines::cached_context();
		$group = isset( $ctx['groups'][ (int) $product->get_id() ] ) ? $ctx['groups'][ (int) $product->get_id() ] : array();

		if ( ! $group ) {
			return null;
		}

		// Fotos der eigenen Artikel und aller Module, die in ihnen stecken
		// (samt Geschwistern, denn die Wahl kann tauschen).
		$ids = array_keys( $group );
		foreach ( array_keys( $group ) as $article_id ) {
			foreach ( self::module_rows( $article_id, $ctx ) as $child ) {
				$ids = array_merge( $ids, array( $child ), array_keys( Variants::group_of( $child, $ctx ) ) );
			}
		}
		$rows = ArticleImages::for_articles( array_unique( $ids ) );

		$default = array_values( array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', (array) $product->get_gallery_image_ids() ) ) ) );
		$sets    = array( implode( ',', $default ) => 0 );
		$markup  = array( self::markup( $default ) );
		$texts   = array( null );
		$by_text = array();
		$list    = array();
		$changes = false;

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( (int) $child_id );

			if ( ! $variation || 'publish' !== $variation->get_status() ) {
				continue;
			}

			$attrs   = OrderLines::variation_attributes( $variation );
			$article = OrderLines::article_for_product_object( $variation, $ctx );
			$own     = isset( $rows[ $article ] ) ? ArticleImages::select( $rows[ $article ], $attrs ) : array();
			$modules = self::module_images( $article, $attrs, $rows, $ctx );
			$images  = array_values( array_unique( array_merge( $own ? $own : $default, $modules ) ) );
			$key     = implode( ',', $images );

			if ( ! isset( $sets[ $key ] ) ) {
				$sets[ $key ] = count( $markup );
				$markup[]     = self::markup( $images );
			}

			$text = self::texts( $article, $ctx );
			$tkey = $text ? md5( wp_json_encode( $text ) ) : '';
			if ( $text && ! isset( $by_text[ $tkey ] ) ) {
				$by_text[ $tkey ] = count( $texts );
				$texts[]          = $text;
			}

			$set_index  = $sets[ $key ];
			$text_index = $text ? $by_text[ $tkey ] : 0;
			$changes    = $changes || $set_index > 0 || $text_index > 0;

			$list[] = array(
				'attrs' => (array) $variation->get_variation_attributes(),
				'set'   => $set_index,
				'text'  => $text_index,
			);
		}

		if ( ! $changes ) {
			return null;
		}

		return array(
			'sets'       => $markup,
			'texts'      => $texts,
			'variations' => $list,
		);
	}

	/**
	 * Direkt enthaltene Module eines Artikels, die ein Shop-Produkt haben.
	 *
	 * @param int   $article_id Artikel (Set).
	 * @param array $ctx        Kontext.
	 * @return int[]
	 */
	protected static function module_rows( $article_id, array $ctx ) {
		$out = array();

		foreach ( isset( $ctx['bom'][ (int) $article_id ] ) ? (array) $ctx['bom'][ (int) $article_id ] : array() as $row ) {
			$row   = (array) $row;
			$child = isset( $row['child_id'] ) ? (int) $row['child_id'] : 0;

			if ( 'artikel' === ( isset( $row['child_type'] ) ? $row['child_type'] : '' ) && ! empty( $ctx['articles'][ $child ]['wc_product_id'] ) ) {
				$out[] = $child;
			}
		}

		return $out;
	}

	/**
	 * Hauptbilder der Module eines Sets in der gewählten Variante.
	 *
	 * Hauptbild ist das erste ERP-Foto des Moduls (passend zur Variante),
	 * sonst das Produktbild im Shop.
	 *
	 * @param int   $article_id Set.
	 * @param array $attrs      Merkmale der Variante.
	 * @param array $rows       Fotos je Artikel.
	 * @param array $ctx        Kontext.
	 * @return int[]
	 */
	public static function module_images( $article_id, array $attrs, array $rows, array $ctx ) {
		$out = array();

		foreach ( self::module_rows( $article_id, $ctx ) as $child ) {
			$child  = Variants::swap( $child, $attrs, $ctx );
			$images = isset( $rows[ $child ] ) ? ArticleImages::select( $rows[ $child ], $attrs ) : array();

			if ( $images ) {
				$out[] = (int) $images[0];
				continue;
			}

			$product = ! empty( $ctx['articles'][ $child ]['wc_product_id'] ) ? wc_get_product( (int) $ctx['articles'][ $child ]['wc_product_id'] ) : null;
			if ( $product && $product->get_image_id() ) {
				$out[] = (int) $product->get_image_id();
			}
		}

		return $out;
	}

	/**
	 * Kurztext und Beschreibung eines Artikels, fertig für die Seite.
	 *
	 * @param int   $article_id Artikel.
	 * @param array $ctx        Kontext.
	 * @return array|null {short, long}; leere Teile = Text des Produkts.
	 */
	protected static function texts( $article_id, array $ctx ) {
		$article = isset( $ctx['articles'][ (int) $article_id ] ) ? (array) $ctx['articles'][ (int) $article_id ] : array();
		$short   = isset( $article['shop_short'] ) ? trim( (string) $article['shop_short'] ) : '';
		$long    = isset( $article['shop_description'] ) ? trim( (string) $article['shop_description'] ) : '';

		if ( '' === $short && '' === $long ) {
			return null;
		}

		return array(
			'short' => '' === $short ? '' : wpautop( wp_kses_post( $short ) ),
			'long'  => '' === $long ? '' : wpautop( wp_kses_post( $long ) ),
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
