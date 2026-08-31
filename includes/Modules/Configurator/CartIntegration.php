<?php
/**
 * Die Konfiguration im Warenkorb.
 *
 * Eine Konfiguration wird nicht zu einem Sammelposten, sondern zu genau
 * den Positionen, aus denen sie besteht: das Basismodul und je Modul eine
 * Zeile. Der Kunde sieht damit dieselben Preise wie auf den
 * Produktseiten, und Lager, Picklisten und Bestandsführung sehen ganz
 * gewöhnliche Bestellpositionen – der Konfigurator braucht dort keine
 * Sonderbehandlung.
 *
 * Zusammengehalten werden die Zeilen von einer Gruppenkennung. Sie sorgt
 * dafür, dass die Konfiguration als Einheit auftritt: Wer das Basismodul
 * entfernt, entfernt den ganzen Haken; wer die Menge ändert, ändert sie
 * für alle Teile. Alles andere wäre eine Falle – ein Haken mit zwei
 * Basismodulen und drei Modulen ist kein sinnvoller Auftrag.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

/**
 * Legt Konfigurationen in den Warenkorb und hält sie dort zusammen.
 */
class CartIntegration {

	/**
	 * Schlüssel der Zusatzdaten an einer Warenkorbposition.
	 */
	const KEY = 'lw16_haken';

	/**
	 * Verhindert, dass das Aufräumen einer Gruppe sich selbst auslöst.
	 *
	 * @var bool
	 */
	protected static $working = false;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_filter( 'woocommerce_get_item_data', array( $this, 'show_in_cart' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_to_order' ), 10, 4 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'remove_group' ), 10, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'sync_quantity' ), 10, 4 );
	}

	/**
	 * Eine geprüfte Konfiguration in den Warenkorb legen.
	 *
	 * @param array $config Konfiguration: base, color, qty, modules.
	 * @return array {ok, errors, count}
	 */
	public static function add( array $config ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array(
				'ok'     => false,
				'errors' => array( __( 'Der Warenkorb steht gerade nicht zur Verfügung.', 'lindenweg16' ) ),
				'count'  => 0,
			);
		}

		$context = PartRepository::context( true );
		$checked = Layout::validate( $config, $context );

		if ( $checked['errors'] ) {
			return array(
				'ok'     => false,
				'errors' => $checked['errors'],
				'count'  => 0,
			);
		}

		$base_id = (int) $config['base'];
		$base    = $context['parts'][ $base_id ];
		$color   = isset( $config['color'] ) ? (string) $config['color'] : '';
		$qty     = max( 1, min( Layout::MAX_QTY, isset( $config['qty'] ) ? (int) $config['qty'] : 1 ) );
		$code    = ConfigCodec::encode(
			array(
				'base'    => $base_id,
				'color'   => $color,
				'modules' => $checked['modules'],
			)
		);

		// Erst alle Positionen auflösen, dann buchen. Sonst liegt bei einem
		// nicht lieferbaren Modul ein halber Haken im Warenkorb.
		$lines  = array();
		$errors = array();

		$resolved = ShopLink::resolve( $base, $color );

		if ( ! $resolved['ok'] ) {
			$errors[] = $resolved['error'];
		} else {
			$lines[] = array(
				'shop' => $resolved,
				'meta' => array(
					'role' => 'basis',
					'name' => $base['name'],
					'pos'  => null,
				),
			);
		}

		foreach ( $checked['modules'] as $module ) {
			$part     = $context['parts'][ $module['article'] ];
			$resolved = ShopLink::resolve( $part, $color );

			if ( ! $resolved['ok'] ) {
				$errors[] = $resolved['error'];
				continue;
			}

			$lines[] = array(
				'shop' => $resolved,
				'meta' => array(
					'role' => 'modul',
					'name' => $part['name'],
					'pos'  => (float) $module['pos'],
				),
			);
		}

		if ( $errors ) {
			return array(
				'ok'     => false,
				'errors' => array_values( array_unique( $errors ) ),
				'count'  => 0,
			);
		}

		$group = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 );
		$added = 0;

		foreach ( $lines as $index => $line ) {
			$item_data = array(
				self::KEY => array(
					'group' => $group,
					'role'  => $line['meta']['role'],
					'name'  => $line['meta']['name'],
					'pos'   => $line['meta']['pos'],
					'code'  => $code,
					'index' => $index,
					'base'  => $base['name'],
				),
			);

			$key = WC()->cart->add_to_cart(
				$line['shop']['product_id'],
				$qty,
				$line['shop']['variation_id'],
				$line['shop']['variation'],
				$item_data
			);

			if ( $key ) {
				$added++;
			}
		}

		if ( ! $added ) {
			return array(
				'ok'     => false,
				'errors' => array( __( 'Die Konfiguration ließ sich nicht in den Warenkorb legen.', 'lindenweg16' ) ),
				'count'  => 0,
			);
		}

		return array(
			'ok'     => true,
			'errors' => array(),
			'count'  => $added,
			'code'   => $code,
		);
	}

	/**
	 * Die Konfiguration an der Warenkorbposition anzeigen.
	 *
	 * @param array $data Bisherige Angaben.
	 * @param array $item Warenkorbposition.
	 * @return array
	 */
	public function show_in_cart( $data, $item ) {
		if ( empty( $item[ self::KEY ] ) ) {
			return $data;
		}

		$meta = $item[ self::KEY ];

		$data[] = array(
			'key'   => __( 'Haken', 'lindenweg16' ),
			'value' => sprintf(
				/* translators: 1: Bezeichnung des Basismoduls, 2: Gruppenkennung. */
				__( '%1$s (Konfiguration %2$s)', 'lindenweg16' ),
				$meta['base'],
				strtoupper( $meta['group'] )
			),
		);

		if ( 'modul' === $meta['role'] && null !== $meta['pos'] ) {
			$data[] = array(
				'key'   => __( 'Position', 'lindenweg16' ),
				'value' => sprintf(
					/* translators: %s: Position in Millimetern. */
					__( '%s mm von unten', 'lindenweg16' ),
					Layout::format_mm( $meta['pos'] )
				),
			);
		}

		return $data;
	}

	/**
	 * Die Konfiguration an der Bestellposition festhalten.
	 *
	 * Als Text: Er bleibt lesbar, wenn das Teil längst überarbeitet ist,
	 * und braucht zum Verstehen keinen Konfigurator.
	 *
	 * @param \WC_Order_Item_Product $item   Bestellposition.
	 * @param string                 $key    Warenkorbschlüssel.
	 * @param array                  $values Warenkorbposition.
	 * @param \WC_Order              $order  Bestellung.
	 */
	public function save_to_order( $item, $key, $values, $order ) {
		if ( empty( $values[ self::KEY ] ) ) {
			return;
		}

		$meta = $values[ self::KEY ];

		$item->add_meta_data( __( 'Haken', 'lindenweg16' ), strtoupper( $meta['group'] ), true );

		if ( 'modul' === $meta['role'] && null !== $meta['pos'] ) {
			$item->add_meta_data(
				__( 'Position', 'lindenweg16' ),
				sprintf(
					/* translators: %s: Position in Millimetern. */
					__( '%s mm von unten', 'lindenweg16' ),
					Layout::format_mm( $meta['pos'] )
				),
				true
			);
		}

		// Der Code steht nur an der Basiszeile: Einmal je Konfiguration
		// genügt, und dort wird er gesucht.
		if ( 'basis' === $meta['role'] ) {
			$item->add_meta_data( __( 'Konfigurationscode', 'lindenweg16' ), $meta['code'], true );
		}

		$item->add_meta_data( '_lw16_haken_gruppe', $meta['group'], true );
	}

	/**
	 * Mit dem Basismodul verschwindet der ganze Haken.
	 *
	 * @param string   $key  Entfernter Warenkorbschlüssel.
	 * @param \WC_Cart $cart Warenkorb.
	 */
	public function remove_group( $key, $cart ) {
		if ( self::$working ) {
			return;
		}

		$entfernt = method_exists( $cart, 'get_removed_cart_contents' ) ? (array) $cart->get_removed_cart_contents() : array();
		$removed  = isset( $entfernt[ $key ][ self::KEY ] ) ? $entfernt[ $key ][ self::KEY ] : null;

		if ( ! $removed || 'basis' !== $removed['role'] ) {
			return;
		}

		self::$working = true;

		foreach ( $cart->get_cart() as $other_key => $item ) {
			if ( ! empty( $item[ self::KEY ] ) && $item[ self::KEY ]['group'] === $removed['group'] ) {
				$cart->remove_cart_item( $other_key );
			}
		}

		self::$working = false;
	}

	/**
	 * Die Menge gilt für die ganze Konfiguration.
	 *
	 * @param string   $key      Geänderter Warenkorbschlüssel.
	 * @param int      $quantity Neue Menge.
	 * @param int      $old      Alte Menge.
	 * @param \WC_Cart $cart     Warenkorb.
	 */
	public function sync_quantity( $key, $quantity, $old, $cart ) {
		if ( self::$working ) {
			return;
		}

		$items = $cart->get_cart();

		if ( empty( $items[ $key ][ self::KEY ] ) ) {
			return;
		}

		$group = $items[ $key ][ self::KEY ]['group'];

		self::$working = true;

		foreach ( $items as $other_key => $item ) {
			if ( $other_key === $key || empty( $item[ self::KEY ] ) ) {
				continue;
			}

			if ( $item[ self::KEY ]['group'] === $group && (int) $item['quantity'] !== (int) $quantity ) {
				$cart->set_quantity( $other_key, (int) $quantity, false );
			}
		}

		self::$working = false;
	}
}
