<?php
/**
 * Das Gutschein-Produkt: Markierung, Formular, Warenkorb, Bestellung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Gutschein;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Assets;

/**
 * Alles rund um die Eingaben des Kunden.
 */
class Produkt {

	/**
	 * Produkt-Meta: Gutschein mit Vorlagenwahl.
	 */
	const META = '_lw16_gutschein';

	/**
	 * Schlüssel der Zusatzdaten an Warenkorb- und Bestellposition.
	 */
	const KEY = 'lw16_gutschein';

	/**
	 * Grenzen der Eingaben.
	 */
	const MAX_NAME = 60;
	const MAX_TEXT = 400;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'formular' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'pruefen' ), 10, 4 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'in_den_warenkorb' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'im_warenkorb_zeigen' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'in_die_bestellung' ), 10, 4 );
		add_filter( 'woocommerce_is_sold_individually', array( $this, 'einzeln' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );

		if ( is_admin() ) {
			add_action( 'woocommerce_product_options_general_product_data', array( $this, 'admin_feld' ) );
			add_action( 'woocommerce_process_product_meta', array( $this, 'admin_speichern' ) );
			add_action( 'admin_post_lw16_gutschein_vorschau', array( $this, 'admin_vorschau' ) );
		}
	}

	/**
	 * Ist dieses Produkt ein Gutschein mit Vorlagenwahl?
	 *
	 * @param int $produkt_id Produkt- oder Variations-ID.
	 * @return bool
	 */
	public static function ist_gutschein( $produkt_id ) {
		$produkt_id = (int) $produkt_id;
		$eltern     = (int) wp_get_post_parent_id( $produkt_id );

		if ( $eltern && 'product_variation' === get_post_type( $produkt_id ) ) {
			$produkt_id = $eltern;
		}

		return 'yes' === get_post_meta( $produkt_id, self::META, true );
	}

	/**
	 * Ein Gutschein ist immer ein Stück: Jeder braucht seinen eigenen Text.
	 *
	 * @param bool        $einzeln Bisherige Entscheidung.
	 * @param \WC_Product $produkt Produkt.
	 * @return bool
	 */
	public function einzeln( $einzeln, $produkt ) {
		return self::ist_gutschein( $produkt->get_id() ) ? true : $einzeln;
	}

	/**
	 * Stil und Skript nur auf der Seite eines Gutscheins laden.
	 */
	public function assets() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! self::ist_gutschein( get_the_ID() ) ) {
			return;
		}

		wp_enqueue_style( 'lw16-gutschein', LW16_PLUGIN_URL . 'assets/css/gutschein.css', array(), Assets::version( 'assets/css/gutschein.css' ) );
		wp_enqueue_script( 'lw16-gutschein', LW16_PLUGIN_URL . 'assets/js/gutschein.js', array(), Assets::version( 'assets/js/gutschein.js' ), true );
	}

	/**
	 * Das Formular über dem Warenkorb-Knopf.
	 */
	public function formular() {
		global $product;

		if ( ! $product instanceof \WC_Product || ! self::ist_gutschein( $product->get_id() ) ) {
			return;
		}

		$alt = array(
			'vorlage' => isset( $_POST['lw16_gv_vorlage'] ) ? Vorlagen::normalisiere( wp_unslash( $_POST['lw16_gv_vorlage'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'von'     => isset( $_POST['lw16_gv_von'] ) ? sanitize_text_field( wp_unslash( $_POST['lw16_gv_von'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'an'      => isset( $_POST['lw16_gv_an'] ) ? sanitize_text_field( wp_unslash( $_POST['lw16_gv_an'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'text'    => isset( $_POST['lw16_gv_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lw16_gv_text'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'email'   => isset( $_POST['lw16_gv_email'] ) ? sanitize_email( wp_unslash( $_POST['lw16_gv_email'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
		);

		$gewaehlt = '' !== $alt['vorlage'] ? $alt['vorlage'] : 'klassik';
		$betrag   = $product->is_type( 'simple' ) ? trim( html_entity_decode( wp_strip_all_tags( wc_price( (float) wc_get_price_to_display( $product ) ) ) ) ) : '';

		echo '<div class="lw16-gv" data-betrag="' . esc_attr( $betrag ) . '">';
		echo '<h3 class="lw16-gv__titel">' . esc_html__( 'Gutschein gestalten', 'lindenweg16' ) . '</h3>';

		echo '<fieldset class="lw16-gv__wahl"><legend class="screen-reader-text">' . esc_html__( 'Vorlage', 'lindenweg16' ) . '</legend>';
		foreach ( Vorlagen::alle() as $kennung => $v ) {
			echo '<label class="lw16-gv__karte lw16-gv__karte--' . esc_attr( $kennung ) . '"><input type="radio" name="lw16_gv_vorlage" value="' . esc_attr( $kennung ) . '"' . checked( $gewaehlt, $kennung, false ) . '><span>' . esc_html( $v['kurz'] ) . '</span></label>';
		}
		echo '<label class="lw16-gv__karte lw16-gv__karte--mail"><input type="radio" name="lw16_gv_vorlage" value="' . esc_attr( Vorlagen::NUR_MAIL ) . '"' . checked( $gewaehlt, Vorlagen::NUR_MAIL, false ) . '><span>' . esc_html__( 'Nur E-Mail', 'lindenweg16' ) . '</span></label>';
		echo '</fieldset>';

		echo '<div class="lw16-gv__felder">';
		echo '<p><label>' . esc_html__( 'Von', 'lindenweg16' ) . ' *<input type="text" name="lw16_gv_von" maxlength="' . (int) self::MAX_NAME . '" value="' . esc_attr( $alt['von'] ) . '" required></label></p>';
		echo '<p><label>' . esc_html__( 'An', 'lindenweg16' ) . ' *<input type="text" name="lw16_gv_an" maxlength="' . (int) self::MAX_NAME . '" value="' . esc_attr( $alt['an'] ) . '" required></label></p>';
		echo '<p><label>' . esc_html__( 'Text', 'lindenweg16' ) . '<textarea name="lw16_gv_text" rows="4" maxlength="' . (int) self::MAX_TEXT . '">' . esc_textarea( $alt['text'] ) . '</textarea></label></p>';
		echo '<p><label>' . esc_html__( 'E-Mail des Beschenkten', 'lindenweg16' ) . '<input type="email" name="lw16_gv_email" value="' . esc_attr( $alt['email'] ) . '"><small>' . esc_html__( 'Leer lassen, dann bekommst Du den Gutschein selbst und kannst ihn weitergeben.', 'lindenweg16' ) . '</small></label></p>';
		echo '</div>';

		echo '<div class="lw16-gv__vorschau" aria-live="polite">';
		foreach ( array_keys( Vorlagen::alle() ) as $kennung ) {
			echo '<div class="lw16-gv__bild" data-vorlage="' . esc_attr( $kennung ) . '">' . Vorlagen::render( $kennung, array( 'betrag' => $betrag ), true ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Vorlagen::render escaped selbst.
		}
		echo '<p class="lw16-gv__mailhinweis" data-vorlage="' . esc_attr( Vorlagen::NUR_MAIL ) . '">' . esc_html__( 'Der Beschenkte bekommt eine schlichte E-Mail mit Deinem Text und dem Gutscheincode.', 'lindenweg16' ) . '</p>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Eingaben aus der Anfrage lesen.
	 *
	 * @return array
	 */
	protected function eingaben() {
		// phpcs:disable WordPress.Security.NonceVerification -- Warenkorb-Anfragen von WooCommerce tragen keinen Nonce.
		return array(
			'vorlage' => isset( $_POST['lw16_gv_vorlage'] ) ? Vorlagen::normalisiere( wp_unslash( $_POST['lw16_gv_vorlage'] ) ) : '',
			'von'     => isset( $_POST['lw16_gv_von'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['lw16_gv_von'] ) ), 0, self::MAX_NAME ) : '',
			'an'      => isset( $_POST['lw16_gv_an'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['lw16_gv_an'] ) ), 0, self::MAX_NAME ) : '',
			'text'    => isset( $_POST['lw16_gv_text'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['lw16_gv_text'] ) ), 0, self::MAX_TEXT ) : '',
			'email'   => isset( $_POST['lw16_gv_email'] ) ? sanitize_email( wp_unslash( $_POST['lw16_gv_email'] ) ) : '',
		);
		// phpcs:enable
	}

	/**
	 * Vor dem Ablegen im Warenkorb: Pflichtangaben prüfen.
	 *
	 * @param bool $ok           Bisheriges Ergebnis.
	 * @param int  $produkt_id   Produkt.
	 * @param int  $menge        Menge.
	 * @param int  $variation_id Variation.
	 * @return bool
	 */
	public function pruefen( $ok, $produkt_id, $menge = 1, $variation_id = 0 ) {
		if ( ! self::ist_gutschein( $produkt_id ) ) {
			return $ok;
		}

		$e = $this->eingaben();

		if ( '' === $e['vorlage'] ) {
			wc_add_notice( __( 'Bitte wähle eine Vorlage oder „Nur E-Mail“.', 'lindenweg16' ), 'error' );
			$ok = false;
		}
		if ( '' === $e['von'] || '' === $e['an'] ) {
			wc_add_notice( __( 'Bitte trage ein, von wem der Gutschein ist und für wen.', 'lindenweg16' ), 'error' );
			$ok = false;
		}
		if ( ! empty( $_POST['lw16_gv_email'] ) && '' === $e['email'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			wc_add_notice( __( 'Die E-Mail-Adresse des Beschenkten sieht nicht richtig aus.', 'lindenweg16' ), 'error' );
			$ok = false;
		}

		return $ok;
	}

	/**
	 * Die Eingaben an die Warenkorbposition hängen.
	 *
	 * Verschiedene Texte ergeben verschiedene Positionen: WooCommerce bildet
	 * den Positionsschlüssel auch aus diesen Daten.
	 *
	 * @param array $daten        Zusatzdaten.
	 * @param int   $produkt_id   Produkt.
	 * @param int   $variation_id Variation.
	 * @return array
	 */
	public function in_den_warenkorb( $daten, $produkt_id, $variation_id ) {
		if ( ! self::ist_gutschein( $produkt_id ) ) {
			return $daten;
		}

		$daten[ self::KEY ] = $this->eingaben();

		return $daten;
	}

	/**
	 * Auswahl im Warenkorb und an der Kasse anzeigen.
	 *
	 * @param array $anzeige Bisherige Angaben.
	 * @param array $item    Warenkorbposition.
	 * @return array
	 */
	public function im_warenkorb_zeigen( $anzeige, $item ) {
		if ( empty( $item[ self::KEY ] ) ) {
			return $anzeige;
		}

		return array_merge( $anzeige, self::zeilen( $item[ self::KEY ] ) );
	}

	/**
	 * Lesbare Zeilen zu einer Auswahl.
	 *
	 * @param array $g Eingaben.
	 * @return array Liste aus key und value.
	 */
	protected static function zeilen( array $g ) {
		$zeilen = array(
			array(
				'key'   => __( 'Vorlage', 'lindenweg16' ),
				'value' => Vorlagen::label( $g['vorlage'] ),
			),
			array(
				'key'   => __( 'Von', 'lindenweg16' ),
				'value' => $g['von'],
			),
			array(
				'key'   => __( 'An', 'lindenweg16' ),
				'value' => $g['an'],
			),
		);

		if ( '' !== $g['text'] ) {
			$zeilen[] = array(
				'key'   => __( 'Text', 'lindenweg16' ),
				'value' => $g['text'],
			);
		}
		if ( '' !== $g['email'] ) {
			$zeilen[] = array(
				'key'   => __( 'E-Mail des Beschenkten', 'lindenweg16' ),
				'value' => $g['email'],
			);
		}

		return $zeilen;
	}

	/**
	 * Auswahl an der Bestellposition festhalten.
	 *
	 * @param \WC_Order_Item_Product $item   Bestellposition.
	 * @param string                 $key    Warenkorbschlüssel.
	 * @param array                  $values Warenkorbposition.
	 * @param \WC_Order              $order  Bestellung.
	 */
	public function in_die_bestellung( $item, $key, $values, $order ) {
		if ( empty( $values[ self::KEY ] ) ) {
			return;
		}

		foreach ( self::zeilen( $values[ self::KEY ] ) as $zeile ) {
			$item->add_meta_data( $zeile['key'], $zeile['value'], true );
		}

		// Maschinenlesbar, für den Versand.
		$item->add_meta_data( '_' . self::KEY, $values[ self::KEY ], true );
	}

	/**
	 * Schalter im Produktformular.
	 */
	public function admin_feld() {
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META,
				'label'       => __( 'Gutschein mit Vorlagenwahl', 'lindenweg16' ),
				'description' => __( 'Kunden wählen drei Vorlagen oder nur E-Mail und tragen Von, An und Text ein. Nach der Zahlung entsteht ein Gutscheincode in Höhe des Preises.', 'lindenweg16' ),
			)
		);
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=lw16_gutschein_vorschau' ), 'lw16_gutschein_vorschau' );
		echo '<p class="form-field"><label>' . esc_html__( 'Vorlagen', 'lindenweg16' ) . '</label><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Alle drei Vorlagen ansehen', 'lindenweg16' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Schalter speichern.
	 *
	 * @param int $post_id Produkt.
	 */
	public function admin_speichern( $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce prüft den Nonce des Produktformulars.
		update_post_meta( $post_id, self::META, isset( $_POST[ self::META ] ) ? 'yes' : 'no' );
	}

	/**
	 * Vorschau aller Vorlagen für das Backend.
	 */
	public function admin_vorschau() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'lindenweg16' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'lw16_gutschein_vorschau' );

		$beispiel = array(
			'von'         => 'Anna',
			'an'          => 'Ben',
			'text'        => __( 'Alles Gute zum Geburtstag! Such Dir etwas Schönes aus.', 'lindenweg16' ),
			'betrag'      => '50,00 €',
			'code'        => 'LW16-7K2M-Q9XD',
			'gueltig_bis' => '31.12.' . ( (int) gmdate( 'Y' ) + 3 ),
		);

		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Gutschein-Vorlagen', 'lindenweg16' ) . '</title></head><body style="margin:0;background:#f4f2ee">';
		foreach ( Vorlagen::alle() as $kennung => $v ) {
			echo '<h2 style="font-family:sans-serif;text-align:center;margin:24px 0 0;color:#22282f">' . esc_html( $v['label'] ) . '</h2>';
			echo Vorlagen::render( $kennung, $beispiel ); // phpcs:ignore WordPress.Security.EscapeOutput -- Vorlagen::render escaped selbst.
		}
		echo '</body></html>';
		exit;
	}
}
