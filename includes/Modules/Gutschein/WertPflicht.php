<?php
/**
 * Gutscheine mit wählbarem Wert nur über die Produktseite kaufen.
 *
 * Das Gutschein-Produkt im Shop ist ein Germanized-Gutschein (`_gc_is_voucher`).
 * Den Wert (25, 50, 75 oder 100 €) wählt der Kunde auf der Produktseite in einem
 * Feld `gc_voucher_value`, das ein eigenes Plugin beisteuert. Der Warenkorb-Knopf
 * in Produktlisten (Startseite, Shop, leerer Warenkorb) legte das Produkt bisher
 * ohne diese Wahl ab – zum hinterlegten Grundpreis von 10 € und ohne Wert.
 *
 * Diese Klasse schickt den Kunden aus Listen auf die Produktseite („Wert wählen“)
 * und lehnt jeden Warenkorb-Zugang ohne gewählten Wert ab, auch über die
 * Store-API und über Links wie `?add-to-cart=`.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Gutschein;

defined( 'ABSPATH' ) || exit;

/**
 * Pflicht zur Wertwahl bei Gutscheinen.
 */
class WertPflicht {

	/**
	 * Produkt-Meta von Germanized: Produkt ist ein Gutschein.
	 */
	const META = '_gc_is_voucher';

	/**
	 * Feld mit dem gewählten Wert auf der Produktseite.
	 */
	const FELD = 'gc_voucher_value';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_filter( 'woocommerce_product_supports', array( $this, 'kein_ajax' ), 10, 3 );
		add_filter( 'woocommerce_product_add_to_cart_url', array( $this, 'zur_produktseite' ), 20, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'knopftext' ), 20, 2 );
		add_filter( 'woocommerce_product_add_to_cart_description', array( $this, 'knopftext' ), 20, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'pruefen' ), 5, 2 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'pruefen_store_api' ), 10, 2 );
	}

	/**
	 * Ist dieses Produkt ein Gutschein mit wählbarem Wert?
	 *
	 * @param \WC_Product|int|null $produkt Produkt oder ID.
	 * @return bool
	 */
	public static function ist_wertgutschein( $produkt ) {
		$id = is_object( $produkt ) && method_exists( $produkt, 'get_id' ) ? (int) $produkt->get_id() : (int) $produkt;

		if ( $id <= 0 ) {
			return false;
		}

		$eltern = (int) wp_get_post_parent_id( $id );
		if ( $eltern && 'product_variation' === get_post_type( $id ) ) {
			$id = $eltern;
		}

		return 'yes' === get_post_meta( $id, self::META, true );
	}

	/**
	 * Gültiger Wert aus einer Eingabe - reine Regel.
	 *
	 * @param mixed $wert Eingabe.
	 * @return bool
	 */
	public static function wert_gewaehlt( $wert ) {
		return is_scalar( $wert ) && is_numeric( $wert ) && (float) $wert > 0;
	}

	/**
	 * In Listen keinen Warenkorb-Knopf, der sofort ablegt.
	 *
	 * @param bool        $kann    Bisher.
	 * @param string      $feature Fähigkeit.
	 * @param \WC_Product $produkt Produkt.
	 * @return bool
	 */
	public function kein_ajax( $kann, $feature, $produkt ) {
		if ( 'ajax_add_to_cart' === $feature && self::ist_wertgutschein( $produkt ) ) {
			return false;
		}
		return $kann;
	}

	/**
	 * Der Knopf in Listen führt auf die Produktseite.
	 *
	 * @param string      $url     Bisher.
	 * @param \WC_Product $produkt Produkt.
	 * @return string
	 */
	public function zur_produktseite( $url, $produkt ) {
		return self::ist_wertgutschein( $produkt ) ? (string) $produkt->get_permalink() : $url;
	}

	/**
	 * Knopftext in Listen.
	 *
	 * @param string      $text    Bisher.
	 * @param \WC_Product $produkt Produkt.
	 * @return string
	 */
	public function knopftext( $text, $produkt = null ) {
		return self::ist_wertgutschein( $produkt ) ? __( 'Wert wählen', 'lindenweg16' ) : $text;
	}

	/**
	 * Klassischer Warenkorb-Zugang (Produktformular, `?add-to-cart=`).
	 *
	 * @param bool $ok         Bisher.
	 * @param int  $produkt_id Produkt.
	 * @return bool
	 */
	public function pruefen( $ok, $produkt_id ) {
		if ( ! $ok || ! self::ist_wertgutschein( $produkt_id ) ) {
			return $ok;
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- Warenkorb-Anfragen von WooCommerce tragen keinen Nonce.
		$wert = isset( $_REQUEST[ self::FELD ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::FELD ] ) ) : '';

		if ( self::wert_gewaehlt( $wert ) ) {
			return $ok;
		}

		wc_add_notice( self::meldung(), 'error' );

		return false;
	}

	/**
	 * Warenkorb-Zugang über die Store-API (Knöpfe der Block-Listen).
	 *
	 * Die Store-API kennt das Wert-Feld nicht. Ein Gutschein mit wählbarem Wert
	 * kommt deshalb nur über das Formular der Produktseite in den Warenkorb.
	 *
	 * @param \WC_Product $produkt Produkt.
	 * @param array       $anfrage Daten der Anfrage.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException Ohne gewählten Wert.
	 */
	public function pruefen_store_api( $produkt, $anfrage = array() ) {
		if ( ! self::ist_wertgutschein( $produkt ) ) {
			return;
		}

		$wert = is_array( $anfrage ) && isset( $anfrage['cart_item_data'][ self::FELD ] ) ? $anfrage['cart_item_data'][ self::FELD ] : null;

		if ( self::wert_gewaehlt( $wert ) ) {
			return;
		}

		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'lw16_gutschein_wert', self::meldung(), 400 );
		}
	}

	/**
	 * Hinweis an den Kunden.
	 *
	 * @return string
	 */
	public static function meldung() {
		return __( 'Bitte wähle auf der Seite des Gutscheins zuerst einen Wert aus.', 'lindenweg16' );
	}
}
