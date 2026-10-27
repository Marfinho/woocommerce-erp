<?php
/**
 * Bestandsübergabe an den Shop.
 *
 * Das Bewegungskonto ist die einzige Wahrheit für den Bestand. WooCommerce
 * bekommt davon nur eine Kopie, damit der Shop „ausverkauft" anzeigt und
 * nicht mehr verkauft, als da ist. Geschrieben wird nach jeder Buchung und
 * jedem Statuswechsel einer Bestellung (gesammelt am Ende der Anfrage), und
 * einmal je Stunde komplett als Sicherheitsnetz.
 *
 * WooCommerces eigener Lagerabzug wird für diese Produkte abgeschaltet,
 * sonst zöge er zusätzlich ab, was das ERP schon bucht.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Scheduler;
use Lindenweg16\Core\Settings;

/**
 * Schreibt den verfügbaren Bestand in die Shop-Produkte.
 */
class WooStockSync {

	/**
	 * Artikel, die am Ende der Anfrage abgeglichen werden.
	 *
	 * @var array article_id => true.
	 */
	protected static $queue = array();

	/**
	 * Läuft gerade ein Abgleich?
	 *
	 * @var bool
	 */
	protected static $running = false;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_stock_booked', array( __CLASS__, 'queue' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 40, 1 );
		add_action( 'woocommerce_trash_order', array( $this, 'on_order_status_changed' ), 10, 1 );
		add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', array( $this, 'prevent_woo_adjustment' ), 10, 2 );
		add_action( Scheduler::HOOK_SHOP_STOCK, array( __CLASS__, 'sync_all' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Ist die Übergabe an den Shop eingeschaltet?
	 *
	 * Sie braucht die Lagerhoheit: Bucht das alte Lager-Modul, sagt das
	 * Bewegungskonto nichts über den Bestand.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return StockOwnership::is_active() && (bool) Settings::get( 'betrieb_shop_bestand', 1 );
	}

	/**
	 * Artikel zum Abgleich am Ende der Anfrage vormerken.
	 *
	 * @param int $article_id Artikel.
	 */
	public static function queue( $article_id ) {
		if ( (int) $article_id > 0 ) {
			self::$queue[ (int) $article_id ] = true;
		}
	}

	/**
	 * Eine Bestellung hat Status oder Papierkorb gewechselt: Reservierung neu rechnen.
	 *
	 * @param int $order_id Bestellung.
	 */
	public function on_order_status_changed( $order_id ) {
		if ( ! self::is_active() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}

		$map = StockOwnership::article_map();

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			if ( isset( $map[ $product_id ] ) ) {
				self::queue( $map[ $product_id ] );
			}
		}
	}

	/**
	 * WooCommerces Lagerabzug für Produkte mit ERP-Artikel überspringen.
	 *
	 * Gilt für Abzug und Rückbuchung gleichermaßen.
	 *
	 * @param bool                   $prevent Bisherige Entscheidung.
	 * @param \WC_Order_Item_Product $item    Bestellposition.
	 * @return bool
	 */
	public function prevent_woo_adjustment( $prevent, $item ) {
		if ( $prevent || ! self::is_active() ) {
			return $prevent;
		}

		return StockOwnership::article_for_product( (int) $item->get_product_id() ) > 0;
	}

	/**
	 * Vorgemerkte Artikel abgleichen.
	 */
	public static function flush() {
		if ( empty( self::$queue ) || self::$running ) {
			return;
		}

		$ids         = array_keys( self::$queue );
		self::$queue = array();

		if ( self::is_active() ) {
			self::sync( $ids );
		}
	}

	/**
	 * Alle Artikel mit Shop-Produkt abgleichen.
	 *
	 * @return array {geprueft, geaendert, ohne_produkt}
	 */
	public static function sync_all() {
		if ( ! self::is_active() ) {
			return array( 'geprueft' => 0, 'geaendert' => 0, 'ohne_produkt' => 0 );
		}

		$ids = array();
		foreach ( ArticleRepository::all() as $article ) {
			if ( (int) $article['wc_product_id'] > 0 ) {
				$ids[] = (int) $article['id'];
			}
		}

		return self::sync( $ids );
	}

	/**
	 * Mehrere Artikel abgleichen.
	 *
	 * @param int[] $article_ids Artikel.
	 * @return array {geprueft, geaendert, ohne_produkt}
	 */
	public static function sync( array $article_ids ) {
		$result = array( 'geprueft' => 0, 'geaendert' => 0, 'ohne_produkt' => 0 );

		if ( ! function_exists( 'wc_get_product' ) || empty( $article_ids ) || self::$running ) {
			return $result;
		}

		self::$running = true;

		try {
			$movements = count( $article_ids ) === 1 ? StockRepository::all( (int) reset( $article_ids ) ) : StockRepository::all();
			$balances  = StockLedger::balances( $movements );
			$lines     = WooIntegration::open_order_lines();
			$articles  = ArticleRepository::all_by_id();

			foreach ( $article_ids as $article_id ) {
				$article_id = (int) $article_id;
				$wc_id      = isset( $articles[ $article_id ]['wc_product_id'] ) ? (int) $articles[ $article_id ]['wc_product_id'] : 0;
				$product    = $wc_id ? wc_get_product( $wc_id ) : null;

				if ( ! $product ) {
					$result['ohne_produkt']++;
					continue;
				}

				$stock    = ShopStock::stock_by_color( isset( $balances[ $article_id ] ) ? $balances[ $article_id ] : array() );
				$reserved = ShopStock::reserved_by_color( $lines, $article_id );

				$result['geprueft']++;
				$result['geaendert'] += self::write_product( $product, $stock, $reserved );
			}
		} finally {
			self::$running = false;
		}

		return $result;
	}

	/**
	 * Bestand in ein Shop-Produkt schreiben.
	 *
	 * @param \WC_Product $product  Produkt.
	 * @param array       $stock    color => Lagerbestand.
	 * @param array       $reserved color => reservierte Menge.
	 * @return int Anzahl geänderter Produkte beziehungsweise Varianten.
	 */
	protected static function write_product( $product, array $stock, array $reserved ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return self::write_quantity( $product, ShopStock::simple_quantity( $stock, $reserved ) ) ? 1 : 0;
		}

		$taxonomy   = ColorRegistry::taxonomy();
		$variations = array();
		$objects    = array();

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation ) {
				continue;
			}

			$attributes             = $variation->get_attributes();
			$variations[ $child_id ] = ColorRegistry::normalize( isset( $attributes[ $taxonomy ] ) ? $attributes[ $taxonomy ] : '' );
			$objects[ $child_id ]   = $variation;
		}

		$quantities = ShopStock::per_variation( $variations, ShopStock::available( $stock, $reserved ), ColorRegistry::default_color() );
		$changed    = 0;

		// Der Bestand liegt an den Varianten; ein eigener am Elternprodukt
		// würde ihn überlagern.
		if ( $product->get_manage_stock() ) {
			$product->set_manage_stock( false );
			$product->save();
			$changed++;
		}

		foreach ( $quantities as $child_id => $qty ) {
			$changed += self::write_quantity( $objects[ $child_id ], $qty ) ? 1 : 0;
		}

		if ( $changed && class_exists( '\WC_Product_Variable' ) ) {
			\WC_Product_Variable::sync( $product->get_id() );
		}

		return $changed;
	}

	/**
	 * Menge setzen, wenn sie sich ändert.
	 *
	 * Rückstand bestellen ist aus: Was nicht da ist, ist nicht kaufbar.
	 *
	 * @param \WC_Product $product Produkt oder Variante.
	 * @param int         $qty     Menge.
	 * @return bool Wurde geschrieben?
	 */
	protected static function write_quantity( $product, $qty ) {
		$status = $qty > 0 ? 'instock' : 'outofstock';

		if ( $product->get_manage_stock()
			&& (int) $product->get_stock_quantity() === (int) $qty
			&& 'no' === $product->get_backorders()
			&& $product->get_stock_status() === $status ) {
			return false;
		}

		$product->set_manage_stock( true );
		$product->set_backorders( 'no' );
		$product->set_stock_quantity( (int) $qty );
		$product->set_stock_status( $status );
		$product->save();

		return true;
	}
}
