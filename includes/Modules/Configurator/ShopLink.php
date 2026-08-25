<?php
/**
 * Vom Betriebsartikel zum bestellbaren WooCommerce-Produkt.
 *
 * Jedes Teil des Konfigurators ist ein echtes Produkt im Shop – Basismodul
 * wie Module. Der Warenkorb bekommt deshalb je Teil eine eigene Position
 * zum Preis des Shops. Das ist umständlicher als ein Sammelposten mit
 * gerechnetem Preis, aber es ist das Einzige, was in beide Richtungen
 * stimmt: Der Kunde sieht denselben Preis wie auf der Produktseite, und
 * Lager, Kommissionierung und Bestandsführung sehen dieselben Artikel wie
 * bei jeder anderen Bestellung.
 *
 * **Der Preis kommt niemals aus dem Browser.** Der Konfigurator schickt
 * eine Liste von Artikeln und Positionen; was sie kostet, entscheidet
 * WooCommerce beim Hinzufügen zum Warenkorb.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ColorRegistry;

/**
 * Findet Produkt, Variante und Preis zu einem Teil.
 */
class ShopLink {

	/**
	 * Das bestellbare Produkt zu einem Teil.
	 *
	 * Bei einem variablen Produkt wird über das Farbattribut die passende
	 * Variante gesucht. Gibt es sie nicht, ist das Teil in dieser Farbe
	 * nicht bestellbar – und wird gemeldet, nicht stillschweigend in einer
	 * anderen Farbe geliefert.
	 *
	 * @param array  $part  Teil aus PartRepository::context().
	 * @param string $color Farb-Slug.
	 * @return array {ok, product_id, variation_id, variation, price, name, error}
	 */
	public static function resolve( array $part, $color ) {
		$fail = static function ( $message ) {
			return array(
				'ok'           => false,
				'product_id'   => 0,
				'variation_id' => 0,
				'variation'    => array(),
				'price'        => 0.0,
				'name'         => '',
				'error'        => $message,
			);
		};

		$product_id = isset( $part['wc_product_id'] ) ? (int) $part['wc_product_id'] : 0;

		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return $fail(
				sprintf(
					/* translators: %s: Bezeichnung des Teils. */
					__( '„%s" ist im Shop nicht mit einem Produkt verknüpft.', 'lindenweg16' ),
					isset( $part['name'] ) ? $part['name'] : ''
				)
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return $fail(
				sprintf(
					/* translators: %s: Bezeichnung des Teils. */
					__( '„%s" gibt es im Shop nicht mehr.', 'lindenweg16' ),
					$part['name']
				)
			);
		}

		$variation_id = 0;
		$variation    = array();
		$buyable      = $product;

		if ( $product->is_type( 'variable' ) ) {
			$match = self::find_variation( $product, $color );

			if ( ! $match ) {
				return $fail(
					sprintf(
						/* translators: 1: Bezeichnung des Teils, 2: Farbe. */
						__( '„%1$s" gibt es nicht in %2$s.', 'lindenweg16' ),
						$product->get_name(),
						$color
					)
				);
			}

			$variation_id = (int) $match['id'];
			$variation    = $match['attributes'];
			$buyable      = wc_get_product( $variation_id );
		}

		if ( ! $buyable || ! $buyable->is_purchasable() || ! $buyable->is_in_stock() ) {
			return $fail(
				sprintf(
					/* translators: %s: Bezeichnung des Teils. */
					__( '„%s" ist derzeit nicht bestellbar.', 'lindenweg16' ),
					$product->get_name()
				)
			);
		}

		return array(
			'ok'           => true,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'variation'    => $variation,
			'price'        => (float) wc_get_price_to_display( $buyable ),
			'name'         => $product->get_name(),
			'error'        => '',
		);
	}

	/**
	 * Die Variante zu einer Farbe suchen.
	 *
	 * @param \WC_Product_Variable $product Variables Produkt.
	 * @param string               $color   Farb-Slug.
	 * @return array|null {id, attributes}
	 */
	protected static function find_variation( $product, $color ) {
		$taxonomy = ColorRegistry::taxonomy();
		$key      = 'attribute_' . $taxonomy;
		$color    = (string) $color;

		foreach ( $product->get_available_variations() as $variation ) {
			$attributes = isset( $variation['attributes'] ) ? (array) $variation['attributes'] : array();
			$value      = isset( $attributes[ $key ] ) ? (string) $attributes[ $key ] : '';

			// Ein leerer Wert heißt bei WooCommerce „jede Ausprägung". So
			// eine Variante passt auf jede Farbe und ist damit brauchbar.
			if ( '' !== $value && $value !== $color ) {
				continue;
			}

			return array(
				'id'         => (int) $variation['variation_id'],
				'attributes' => '' === $value ? array_merge( $attributes, array( $key => $color ) ) : $attributes,
			);
		}

		return null;
	}
}
