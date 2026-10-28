<?php
/**
 * Pre-Sale: Produkte vorab für höhere Stufen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Loyalty;

defined( 'ABSPATH' ) || exit;

/**
 * Speichert Pre-Sales (Produkte, Kategorien oder Merkmale wie „Farbe: Rot“ sind
 * bis zu einem Tag nur für Kunden ab einer Stufe kaufbar) und sperrt den Kauf
 * für alle anderen. Die Rechenregeln stehen in LoyaltyRules.
 */
class LoyaltyPresale {

	const OPTION = 'lw16_loyalty_presales';

	/** @var array|null Pre-Sales der laufenden Anfrage. */
	protected static $cache = null;

	/** @var array<int,int> Stufen-Index je Kunde in dieser Anfrage. */
	protected static $tier_cache = array();

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'filter_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add' ), 20, 3 );
		add_filter( 'woocommerce_available_variation', array( $this, 'variation_data' ), 20, 3 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'classic_notice' ), 25 );
		add_filter( 'render_block', array( $this, 'block_notice' ), 10, 2 );
	}

	/**
	 * Alle Pre-Sales, neueste zuerst.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$list = get_option( self::OPTION, array() );
			$list = is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
			usort(
				$list,
				function ( $a, $b ) {
					return strcmp( (string) $b['von'], (string) $a['von'] );
				}
			);
			self::$cache = $list;
		}
		return self::$cache;
	}

	/**
	 * Pre-Sale anlegen oder ändern.
	 *
	 * @param array  $presale Geprüft (LoyaltyRules::sanitize_presale).
	 * @param string $id      Vorhandene Kennung oder leer für neu.
	 * @return string Kennung.
	 */
	public static function save( array $presale, $id = '' ) {
		$id   = $id ? sanitize_key( $id ) : strtolower( wp_generate_password( 10, false, false ) );
		$list = array_values(
			array_filter(
				self::all(),
				function ( $p ) use ( $id ) {
					return ( $p['id'] ?? '' ) !== $id;
				}
			)
		);

		$presale['id'] = $id;
		$list[]        = $presale;
		update_option( self::OPTION, $list, false );
		self::$cache = null;
		return $id;
	}

	/**
	 * Pre-Sale löschen.
	 *
	 * @param string $id Kennung.
	 * @return bool Gab es ihn?
	 */
	public static function delete( $id ) {
		$list = self::all();
		$keep = array_values(
			array_filter(
				$list,
				function ( $p ) use ( $id ) {
					return ( $p['id'] ?? '' ) !== $id;
				}
			)
		);
		if ( count( $keep ) === count( $list ) ) {
			return false;
		}
		update_option( self::OPTION, $keep, false );
		self::$cache = null;
		return true;
	}

	/**
	 * Ein Pre-Sale.
	 *
	 * @param string $id Kennung.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $p ) {
			if ( ( $p['id'] ?? '' ) === $id ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Stufen-Index des aktuellen Kunden (-1: nicht angemeldet oder keine Stufen).
	 *
	 * @return int
	 */
	protected static function tier_index() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return -1;
		}
		if ( ! isset( self::$tier_cache[ $user_id ] ) ) {
			$info                          = LoyaltyTiers::for_user( $user_id );
			self::$tier_cache[ $user_id ] = $info ? (int) $info['index'] : -1;
		}
		return self::$tier_cache[ $user_id ];
	}

	/**
	 * Merkmale eines Artikels für den Abgleich. Bei Varianten zählt die gewählte
	 * Ausprägung („Farbe: Rot“), sonst die Begriffe des Produkts.
	 *
	 * @param \WC_Product $product Produkt oder Variante.
	 * @return array Siehe LoyaltyRules::presale_matches().
	 */
	protected static function subject( $product ) {
		$parent_id = $product->get_parent_id();
		$base_id   = $parent_id ? $parent_id : $product->get_id();
		$ids       = array_unique( array( (int) $product->get_id(), (int) $base_id ) );

		$cats = array();
		foreach ( (array) wp_get_post_terms( $base_id, 'product_cat', array( 'fields' => 'ids' ) ) as $cat_id ) {
			$cats[] = (int) $cat_id;
			foreach ( get_ancestors( (int) $cat_id, 'product_cat', 'taxonomy' ) as $anc ) {
				$cats[] = (int) $anc;
			}
		}

		// Variante: nur die gewählte Ausprägung zählt („Farbe: Rot“), sonst sperrte ein Merkmal
		// des Elternprodukts alle Varianten.
		$chosen = $parent_id ? (array) $product->get_attributes() : array();
		$terms  = array();
		foreach ( get_object_taxonomies( 'product' ) as $tax ) {
			if ( 'product_cat' === $tax ) {
				continue;
			}
			if ( isset( $chosen[ $tax ] ) && is_string( $chosen[ $tax ] ) && '' !== $chosen[ $tax ] ) {
				$terms[] = $tax . ':' . strtolower( $chosen[ $tax ] );
				continue;
			}
			foreach ( (array) wp_get_post_terms( $base_id, $tax, array( 'fields' => 'slugs' ) ) as $slug ) {
				$terms[] = $tax . ':' . strtolower( $slug );
			}
		}

		return array( 'ids' => $ids, 'cats' => $cats, 'terms' => $terms );
	}

	/**
	 * Der Pre-Sale, der diesen Artikel heute für den Kunden sperrt.
	 *
	 * @param \WC_Product $product Produkt oder Variante.
	 * @return array|null
	 */
	public static function blocking( $product ) {
		if ( ! $product instanceof \WC_Product || current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}
		$running = array_filter(
			self::all(),
			function ( $p ) {
				return LoyaltyRules::presale_running( $p, current_time( 'Y-m-d' ) );
			}
		);
		if ( ! $running ) {
			return null;
		}
		return LoyaltyRules::presale_blocking( $running, current_time( 'Y-m-d' ), self::tier_index(), self::subject( $product ) );
	}

	/**
	 * Hinweistext für einen gesperrten Artikel.
	 *
	 * @param array $presale Sperrender Pre-Sale.
	 * @return string
	 */
	public static function message( array $presale ) {
		$tiers = LoyaltyTiers::all();
		$name  = isset( $tiers[ $presale['stufe'] ] ) ? $tiers[ $presale['stufe'] ]['name'] : '';
		$date  = date_i18n( get_option( 'date_format' ), strtotime( $presale['frei_ab'] ) );

		if ( ! get_current_user_id() ) {
			/* translators: 1: Stufe, 2: Datum */
			return sprintf( __( 'Vorab-Verkauf für Kunden ab Stufe %1$s – melde dich an. Für alle ab %2$s.', 'lindenweg16' ), $name, $date );
		}
		/* translators: 1: Stufe, 2: Datum */
		return sprintf( __( 'Vorab-Verkauf für Kunden ab Stufe %1$s. Für alle ab %2$s.', 'lindenweg16' ), $name, $date );
	}

	/**
	 * Gesperrte Artikel sind nicht kaufbar (auch im Warenkorb und per Store-API).
	 *
	 * @param bool        $purchasable Bisher.
	 * @param \WC_Product $product     Produkt.
	 * @return bool
	 */
	public function filter_purchasable( $purchasable, $product ) {
		return $purchasable && ! self::blocking( $product );
	}

	/**
	 * Klassischer Warenkorb: verständliche Meldung statt stummer Ablehnung.
	 *
	 * @param bool $ok           Bisher.
	 * @param int  $product_id   Produkt.
	 * @param int  $quantity     Menge.
	 * @param int  $variation_id Variante (Parameter 4 von WooCommerce).
	 * @return bool
	 */
	public function validate_add( $ok, $product_id, $quantity, $variation_id = 0 ) {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		$presale = $product ? self::blocking( $product ) : null;
		if ( $presale ) {
			wc_add_notice( self::message( $presale ), 'error' );
			return false;
		}
		return $ok;
	}

	/**
	 * Variantenauswahl: gesperrte Variante mit Hinweis statt Kaufen-Knopf.
	 *
	 * @param array                $data      Variantendaten.
	 * @param \WC_Product          $product   Variables Produkt.
	 * @param \WC_Product_Variation $variation Variante.
	 * @return array
	 */
	public function variation_data( $data, $product, $variation ) {
		$presale = self::blocking( $variation );
		if ( $presale ) {
			$data['is_purchasable']    = false;
			$data['availability_html'] = '<p class="lw16-presale-hinweis">' . esc_html( self::message( $presale ) ) . '</p>';
		}
		return $data;
	}

	/**
	 * Produktseite im klassischen Theme.
	 */
	public function classic_notice() {
		global $product;
		$presale = $product instanceof \WC_Product ? self::blocking( $product ) : null;
		if ( $presale ) {
			echo '<p class="lw16-presale-hinweis">' . esc_html( self::message( $presale ) ) . '</p>';
		}
	}

	/**
	 * Produktseite im Block-Theme: Hinweis vor dem Kaufen-Bereich.
	 *
	 * @param string $html  Block-HTML.
	 * @param array  $block Block.
	 * @return string
	 */
	public function block_notice( $html, $block ) {
		if ( 'woocommerce/add-to-cart-form' !== ( $block['blockName'] ?? '' ) || ! function_exists( 'wc_get_product' ) ) {
			return $html;
		}
		$product = wc_get_product( get_the_ID() );
		$presale = $product ? self::blocking( $product ) : null;
		return $presale ? '<p class="lw16-presale-hinweis">' . esc_html( self::message( $presale ) ) . '</p>' . $html : $html;
	}
}
