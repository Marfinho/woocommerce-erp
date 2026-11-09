<?php
/**
 * Von der Bestellposition zur Lagerzeile.
 *
 * Eine Stelle für alle, die wissen müssen, welcher Artikel in welcher Farbe
 * hinter einer Bestellposition steht: Lagerabgang, Reservierung, Pickliste,
 * Bedarf, Prognose. Die Regeln selbst stehen in `Variants`; hier wird nur
 * aus WooCommerce gelesen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Löst Bestellpositionen und Shop-Varianten auf Artikel auf.
 */
class OrderLines {

	/**
	 * Kontext einmal laden.
	 *
	 * @return array Kontext für Variants (articles, bom, groups).
	 */
	public static function context() {
		return Variants::context( ArticleRepository::all_by_id(), BomRepository::all_by_parent() );
	}

	/**
	 * Kontext, je Anfrage einmal geladen – für lesende Stellen, die je
	 * Produkt oder Position fragen (Lieferhinweis im Shop, Paketgewicht).
	 *
	 * @var array|null
	 */
	protected static $cached = null;

	/**
	 * Zwischengespeicherten Kontext holen.
	 *
	 * @return array
	 */
	public static function cached_context() {
		if ( null === self::$cached ) {
			self::$cached = self::context();
		}

		return self::$cached;
	}

	/**
	 * Merkmale einer Bestellposition.
	 *
	 * Zuerst die Variante selbst, dann die Angaben an der Position – eine
	 * Variante mit „beliebig“ trägt die Wahl des Kunden nur dort.
	 *
	 * @param \WC_Order_Item_Product $item Position.
	 * @return array Merkmal => Ausprägung.
	 */
	public static function item_attributes( $item ) {
		$raw = array();

		if ( is_object( $item ) && method_exists( $item, 'get_variation_id' ) && (int) $item->get_variation_id() && function_exists( 'wc_get_product' ) ) {
			$variation = wc_get_product( (int) $item->get_variation_id() );

			if ( $variation && method_exists( $variation, 'get_attributes' ) ) {
				foreach ( (array) $variation->get_attributes() as $key => $value ) {
					if ( is_scalar( $value ) ) {
						$raw[ (string) $key ] = (string) $value;
					}
				}
			}
		}

		$attrs = Variants::normalize_attributes( $raw );

		if ( is_object( $item ) && method_exists( $item, 'get_meta_data' ) ) {
			foreach ( (array) $item->get_meta_data() as $meta ) {
				$data  = is_object( $meta ) && method_exists( $meta, 'get_data' ) ? $meta->get_data() : (array) $meta;
				$key   = isset( $data['key'] ) ? (string) $data['key'] : '';
				$value = isset( $data['value'] ) ? $data['value'] : '';

				if ( '' === $key || '_' === $key[0] || ! is_scalar( $value ) ) {
					continue;
				}

				$key = Variants::key( $key );

				if ( '' !== $key && empty( $attrs[ $key ] ) && '' !== Variants::slug( (string) $value ) ) {
					$attrs[ $key ] = Variants::slug( (string) $value );
				}
			}
		}

		ksort( $attrs );

		return $attrs;
	}

	/**
	 * Merkmale einer Shop-Variante.
	 *
	 * @param \WC_Product $variation Variante.
	 * @return array Merkmal => Ausprägung (ohne „beliebig“).
	 */
	public static function variation_attributes( $variation ) {
		if ( ! is_object( $variation ) || ! method_exists( $variation, 'get_attributes' ) ) {
			return array();
		}

		return Variants::normalize_attributes( (array) $variation->get_attributes() );
	}

	/**
	 * Lagerzeilen einer Bestellposition.
	 *
	 * @param \WC_Order_Item_Product $item Position.
	 * @param array                  $ctx  Kontext aus context().
	 * @return array Zeilen (siehe Variants::lines()).
	 */
	public static function for_item( $item, array $ctx ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
			return array();
		}

		$product_id = (int) $item->get_product_id();

		if ( ! $product_id || empty( $ctx['groups'][ $product_id ] ) ) {
			return array();
		}

		$color = ColorRegistry::resolve_order_item( $item );

		return Variants::lines(
			$product_id,
			self::item_attributes( $item ),
			$color['color'],
			$color['resolved_from'],
			(float) $item->get_quantity(),
			$ctx
		);
	}

	/**
	 * Der Artikel hinter einer Bestellposition (ohne Set-Auflösung).
	 *
	 * @param \WC_Order_Item_Product $item Position.
	 * @param array|null             $ctx  Kontext (null = laden).
	 * @return int Artikel-ID oder 0.
	 */
	public static function article_for_item( $item, $ctx = null ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
			return 0;
		}

		$ctx   = is_array( $ctx ) ? $ctx : self::cached_context();
		$group = isset( $ctx['groups'][ (int) $item->get_product_id() ] ) ? $ctx['groups'][ (int) $item->get_product_id() ] : array();

		if ( ! $group ) {
			return 0;
		}

		$pick = Variants::pick( $group, self::item_attributes( $item ) );

		return (int) $pick['article_id'];
	}

	/**
	 * Der Artikel hinter einer Shop-Variante (oder einem einfachen Produkt).
	 *
	 * @param \WC_Product $product Produkt oder Variante.
	 * @param array|null  $ctx     Kontext (null = laden).
	 * @return int Artikel-ID oder 0.
	 */
	public static function article_for_product_object( $product, $ctx = null ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return 0;
		}

		$is_variation = method_exists( $product, 'is_type' ) && $product->is_type( 'variation' );
		$parent       = $is_variation ? (int) $product->get_parent_id() : (int) $product->get_id();
		$ctx          = is_array( $ctx ) ? $ctx : self::cached_context();
		$group        = isset( $ctx['groups'][ $parent ] ) ? $ctx['groups'][ $parent ] : array();

		if ( ! $group ) {
			return 0;
		}

		$pick = Variants::pick( $group, $is_variation ? self::variation_attributes( $product ) : array() );

		return (int) $pick['article_id'];
	}

	/**
	 * Alle Artikel eines Shop-Produkts.
	 *
	 * @param int        $product_id Produkt.
	 * @param array|null $ctx        Kontext (null = laden).
	 * @return int[]
	 */
	public static function articles_of_product( $product_id, $ctx = null ) {
		$ctx = is_array( $ctx ) ? $ctx : self::context();

		return isset( $ctx['groups'][ (int) $product_id ] ) ? array_map( 'intval', array_keys( $ctx['groups'][ (int) $product_id ] ) ) : array();
	}

	/**
	 * Wählbare Merkmale eines variablen Shop-Produkts (ohne Farbe).
	 *
	 * Für die Auswahl „Shop-Variante“ am Artikel: je Merkmal und Ausprägung
	 * ein Eintrag `pa_schuhsystem=spd` mit lesbarer Beschriftung.
	 *
	 * @param \WC_Product $product    Produkt.
	 * @param bool        $with_color Auch die Farbe anbieten (für die Fotos).
	 * @return array Liste {value, label}.
	 */
	public static function product_choices( $product, $with_color = false ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'is_type' ) || ! $product->is_type( 'variable' ) ) {
			return array();
		}

		$color = Variants::key( ColorRegistry::taxonomy() );
		$out   = array();

		foreach ( (array) $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! $attribute->get_variation() ) {
				continue;
			}

			$name = (string) $attribute->get_name();
			$key  = Variants::key( $name );

			if ( '' === $key || ( $color === $key && ! $with_color ) ) {
				continue;
			}

			$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $name, $product ) : $name;

			if ( $attribute->is_taxonomy() ) {
				foreach ( (array) $attribute->get_terms() as $term ) {
					$out[] = array(
						'value' => $key . '=' . Variants::slug( $term->slug ),
						'label' => $label . ': ' . $term->name,
					);
				}
				continue;
			}

			foreach ( (array) $attribute->get_options() as $option ) {
				$out[] = array(
					'value' => $key . '=' . Variants::slug( $option ),
					'label' => $label . ': ' . $option,
				);
			}
		}

		return $out;
	}
}
