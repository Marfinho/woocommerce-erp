<?php
/**
 * Was der Konfigurator anbietet.
 *
 * Der Katalog stellt zusammen, was Formular und Ansicht brauchen: die
 * Teile mit ihren Maßen und Modellen, die Sperrbereiche der Basismodule,
 * die Farben und die Preise aus dem Shop. Er erfindet nichts – jeder
 * Eintrag hat einen Artikel im Stamm und ein bestellbares Produkt im Shop.
 * Wo eines von beidem fehlt, taucht das Teil nicht auf.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\ColorRegistry;

/**
 * Baut die Angebotsdaten des Konfigurators.
 */
class Catalog {

	/**
	 * Vollständige Angebotsdaten.
	 *
	 * @return array {bases, modules, colors, default_color, currency}
	 */
	public static function payload() {
		// Je Seitenaufruf wird der Katalog zweimal gebraucht – einmal für
		// das Skript, einmal für das Formular. Die Preise dafür zweimal aus
		// WooCommerce zu holen, wäre reine Verschwendung.
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$context = PartRepository::context( true );
		$colors  = ColorRegistry::all();
		$bases   = array();
		$modules = array();

		foreach ( $context['parts'] as $article_id => $part ) {
			$entry = self::entry( $part, $colors );

			// Ein Teil ohne bestellbares Produkt ist für den Kunden kein
			// Angebot. Es fehlt dann in der Auswahl statt beim Klick auf
			// „In den Warenkorb" zu scheitern.
			if ( ! $entry ) {
				continue;
			}

			if ( 'basis' === $part['role'] ) {
				$entry['blocks']      = self::blocks( $context, $article_id );
				$entry['max_modules'] = (int) $part['max_modules'];
				$bases[]              = $entry;
				continue;
			}

			$modules[] = $entry;
		}

		$cache = array(
			'bases'         => $bases,
			'modules'       => $modules,
			'colors'        => self::colors( $colors ),
			'default_color' => ColorRegistry::default_color(),
			'currency'      => self::currency(),
		);

		return $cache;
	}

	/**
	 * Sperrbereiche eines Basismoduls in der Form, die der Browser braucht.
	 *
	 * @param array $context    Stammdaten.
	 * @param int   $article_id Artikel des Basismoduls.
	 * @return array
	 */
	protected static function blocks( array $context, $article_id ) {
		$blocks = array();

		foreach ( (array) ( isset( $context['blocks'][ $article_id ] ) ? $context['blocks'][ $article_id ] : array() ) as $block ) {
			$blocks[] = array(
				'from'  => (float) $block['from_mm'],
				'to'    => (float) $block['to_mm'],
				'label' => '' === (string) $block['label'] ? __( 'Wandbefestigung', 'lindenweg16' ) : (string) $block['label'],
			);
		}

		return $blocks;
	}

	/**
	 * Ein Teil als Katalogeintrag.
	 *
	 * @param array $part   Teil aus PartRepository::context().
	 * @param array $colors Bekannte Farben.
	 * @return array|null Null, wenn das Teil nicht bestellbar ist.
	 */
	protected static function entry( array $part, array $colors ) {
		$offer  = self::prices( $part, $colors );
		$prices = $offer['prices'];

		// Ein Teil, das gerade ausverkauft ist, bleibt im Angebot: Der Kunde
		// soll sehen, dass es das gibt und warum er es nicht wählen kann,
		// statt dass es still verschwindet.
		if ( ! $prices && ! $offer['sold_out'] ) {
			return null;
		}

		return array(
			'article' => (int) $part['article_id'],
			'sku'     => (string) $part['sku'],
			'name'    => (string) $part['name'],
			'teaser'  => (string) $part['teaser'],
			'height'  => (float) $part['height_mm'],
			'width'   => (float) $part['width_mm'],
			'depth'   => (float) $part['depth_mm'],
			'model'   => WebModel::url( $part['model_file'] ),
			'prices'  => $prices,
			'sold_out' => $offer['sold_out'],
		);
	}

	/**
	 * Preise eines Teils je Farbe.
	 *
	 * Ein einfaches Produkt hat einen Preis für alle Farben; er steht unter
	 * dem Schlüssel `*`. Ein variables Produkt hat je Variante einen –
	 * fehlt eine Farbe dort, ist das Teil in dieser Farbe nicht bestellbar.
	 *
	 * @param array $part   Teil.
	 * @param array $colors Bekannte Farben.
	 * @return array {prices, sold_out}. `prices` ist leer, wenn das Teil gar
	 *               nicht bestellbar ist; `sold_out` nennt die Farben (oder `*`
	 *               für alle), in denen es zwar angeboten wird, aber gerade
	 *               nicht lieferbar ist.
	 */
	protected static function prices( array $part, array $colors ) {
		$none       = array(
			'prices'   => array(),
			'sold_out' => array(),
		);
		$product_id = (int) $part['wc_product_id'];

		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return $none;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return $none;
		}

		if ( ! $product->is_type( 'variable' ) ) {
			if ( ! $product->is_purchasable() ) {
				return $none;
			}

			if ( ! $product->is_in_stock() ) {
				return array(
					'prices'   => array(),
					'sold_out' => array( '*' ),
				);
			}

			return array(
				'prices'   => array( '*' => round( (float) wc_get_price_to_display( $product ), 2 ) ),
				'sold_out' => array(),
			);
		}

		$taxonomy = ColorRegistry::taxonomy();
		$key      = 'attribute_' . $taxonomy;
		$prices   = array();
		$sold_out = array();

		foreach ( $product->get_available_variations() as $variation ) {
			$child = wc_get_product( (int) $variation['variation_id'] );

			if ( ! $child || ! $child->is_purchasable() ) {
				continue;
			}

			$attributes = isset( $variation['attributes'] ) ? (array) $variation['attributes'] : array();
			$value      = isset( $attributes[ $key ] ) ? (string) $attributes[ $key ] : '';

			if ( ! $child->is_in_stock() ) {
				if ( '' === $value ) {
					$sold_out = array_merge( $sold_out, array_keys( $colors ) );
				} else {
					$sold_out[] = $value;
				}
				continue;
			}

			$price = round( (float) wc_get_price_to_display( $child ), 2 );

			// Eine Variante ohne festgelegte Farbe gilt für alle Farben.
			if ( '' === $value ) {
				foreach ( array_keys( $colors ) as $slug ) {
					if ( ! isset( $prices[ $slug ] ) ) {
						$prices[ $slug ] = $price;
					}
				}
				continue;
			}

			$prices[ $value ] = $price;
		}

		// Gibt es eine Farbe als lieferbare und als ausverkaufte Variante,
		// zählt die lieferbare.
		$sold_out = array_values( array_diff( array_unique( $sold_out ), array_keys( $prices ) ) );

		return array(
			'prices'   => $prices,
			'sold_out' => $sold_out,
		);
	}

	/**
	 * Das Währungszeichen als Zeichen, nicht als HTML-Entität.
	 *
	 * WooCommerce liefert `&euro;`. Der Browser schreibt den Preis über
	 * textContent in die Seite – dort stünde die Entität wörtlich da.
	 *
	 * @return string
	 */
	protected static function currency() {
		if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return '';
		}

		return html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Farben mit ihrem Bildschirmwert.
	 *
	 * @param array $colors Slug => Anzeigename.
	 * @return array
	 */
	protected static function colors( array $colors ) {
		$list = array();

		foreach ( $colors as $slug => $name ) {
			$list[] = array(
				'slug' => (string) $slug,
				'name' => (string) $name,
				'hex'  => ColorHex::for_slug( $slug ),
			);
		}

		return $list;
	}
}
