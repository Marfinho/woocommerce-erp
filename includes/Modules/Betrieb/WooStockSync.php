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

use Lindenweg16\Core\Log;
use Lindenweg16\Core\Scheduler;
use Lindenweg16\Core\Settings;

/**
 * Schreibt den verfügbaren Bestand in die Shop-Produkte.
 */
class WooStockSync {

	/**
	 * Option mit dem Zeitpunkt des letzten vollständigen Abgleichs.
	 */
	const LAST_RUN = 'lw16_shop_bestand_lauf';

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

		if ( is_admin() ) {
			// Von Hand im Produkt-Editor geändert? Dann gleich zurückstellen.
			add_action( 'woocommerce_process_product_meta', array( $this, 'on_product_edited' ), 99, 1 );
			add_action( 'woocommerce_save_product_variation', array( $this, 'on_variation_edited' ), 99, 1 );
			add_action( 'admin_footer', array( $this, 'lock_editor_fields' ) );
		}
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
	 * Ein Produkt wurde im Editor gespeichert.
	 *
	 * @param int $product_id Produkt.
	 */
	public function on_product_edited( $product_id ) {
		if ( self::$running || ! self::is_active() ) {
			return;
		}

		$article_id = StockOwnership::article_for_product( (int) $product_id );
		if ( $article_id ) {
			self::queue( $article_id );
		}
	}

	/**
	 * Eine Variante wurde im Editor gespeichert.
	 *
	 * @param int $variation_id Variante.
	 */
	public function on_variation_edited( $variation_id ) {
		$parent = (int) wp_get_post_parent_id( (int) $variation_id );

		if ( $parent ) {
			$this->on_product_edited( $parent );
		}
	}

	/**
	 * Bestandsfelder im Produkt-Editor sperren und erklären, warum.
	 *
	 * Eine von Hand eingetragene Menge würde beim nächsten Abgleich ohnehin
	 * überschrieben; besser, sie lässt sich gar nicht erst eintragen.
	 */
	public function lock_editor_fields() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! self::is_active() || ! $screen || 'product' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		global $post;

		if ( ! $post || ! StockOwnership::article_for_product( (int) $post->ID ) ) {
			return;
		}

		$hinweis = esc_js( __( 'Der Bestand wird im ERP geführt und hier automatisch übernommen. Änderungen bitte im ERP unter Lager vornehmen.', 'lindenweg16' ) );

		echo '<script>jQuery(function($){var m="' . $hinweis . '";' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. 'function lock(){$("#_manage_stock,#_stock,#_backorders,#_low_stock_amount,.variable_manage_stock,.variable_stock,.variable_backorders").prop("disabled",true).attr("title",m);'
			. 'if(!$("#lw16-bestand-hinweis").length){$("#inventory_product_data").prepend("<p id=\"lw16-bestand-hinweis\" style=\"margin:1em;padding:.6em;background:#fff8e5;border-left:4px solid #dba617\">"+$("<div>").text(m).html()+"</p>");}}'
			. 'lock();$(document).on("woocommerce_variations_loaded",lock);});</script>';
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
			try {
				self::sync( $ids );
			} catch ( \Throwable $e ) {
				Log::error( 'shop-bestand', 'Abgleich fehlgeschlagen: ' . $e->getMessage() );
			}
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

		$result = self::sync( $ids );

		// Lebenszeichen: Die Systemprüfung schlägt an, wenn es ausbleibt.
		update_option( self::LAST_RUN, time(), false );

		return $result;
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
			$balances  = count( $article_ids ) === 1 ? StockRepository::balances( (int) reset( $article_ids ) ) : StockRepository::balances();
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
	 * Verfügbare Menge eines Artikels: Lager minus offene Bestellungen.
	 *
	 * Dieselbe Rechnung wie beim Übertragen in den Shop - für alle, die
	 * „ist das lieferbar?" beantworten, ohne WooCommerce zu fragen.
	 *
	 * @param int $article_id Artikel.
	 * @return int
	 */
	public static function available_for_article( $article_id ) {
		$balances = StockRepository::balances( (int) $article_id );
		$stock    = ShopStock::stock_by_color( isset( $balances[ (int) $article_id ] ) ? $balances[ (int) $article_id ] : array() );
		$reserved = ShopStock::reserved_by_color( WooIntegration::open_order_lines(), (int) $article_id );

		return ShopStock::simple_quantity( $stock, $reserved );
	}

	/**
	 * Sollmengen eines Shop-Produkts: bei einfachen Produkten es selbst,
	 * bei variablen jede Variante nach ihrer Farbe.
	 *
	 * @param \WC_Product $product  Produkt.
	 * @param array       $stock    color => Lagerbestand.
	 * @param array       $reserved color => reservierte Menge.
	 * @return array Produkt-ID => array( product => Objekt, qty => Stück ).
	 */
	protected static function targets( $product, array $stock, array $reserved ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return array(
				$product->get_id() => array(
					'product' => $product,
					'qty'     => ShopStock::simple_quantity( $stock, $reserved ),
				),
			);
		}

		$taxonomy   = ColorRegistry::taxonomy();
		$variations = array();
		$objects    = array();

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation ) {
				continue;
			}

			$attributes              = $variation->get_attributes();
			$variations[ $child_id ] = ColorRegistry::normalize( isset( $attributes[ $taxonomy ] ) ? $attributes[ $taxonomy ] : '' );
			$objects[ $child_id ]    = $variation;
		}

		$out = array();

		foreach ( ShopStock::per_variation( $variations, ShopStock::available( $stock, $reserved ), ColorRegistry::default_color() ) as $child_id => $qty ) {
			$out[ $child_id ] = array(
				'product' => $objects[ $child_id ],
				'qty'     => $qty,
			);
		}

		return $out;
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
		$changed = 0;

		// Bei variablen Produkten liegt der Bestand an den Varianten; ein
		// eigener am Elternprodukt würde ihn überlagern.
		if ( $product->is_type( 'variable' ) && $product->get_manage_stock() ) {
			$product->set_manage_stock( false );
			$product->save();
			$changed++;
		}

		foreach ( self::targets( $product, $stock, $reserved ) as $target ) {
			$changed += self::write_quantity( $target['product'], $target['qty'] ) ? 1 : 0;
		}

		if ( $changed && $product->is_type( 'variable' ) && class_exists( '\WC_Product_Variable' ) ) {
			\WC_Product_Variable::sync( $product->get_id() );
		}

		return $changed;
	}

	/**
	 * Wo weicht der Shop vom ERP ab?
	 *
	 * Nur lesend. Grundlage der Prüfung unter „Systemprüfung".
	 *
	 * @return array Zeilen: article_id, product_id, name, expected, shop (null = Lagerverwaltung aus).
	 */
	public static function deviations() {
		$rows = array();

		if ( ! function_exists( 'wc_get_product' ) ) {
			return $rows;
		}

		$balances = StockRepository::balances();
		$lines    = WooIntegration::open_order_lines();

		foreach ( ArticleRepository::all_by_id() as $article_id => $article ) {
			$wc_id   = (int) $article['wc_product_id'];
			$product = $wc_id ? wc_get_product( $wc_id ) : null;

			if ( ! $product ) {
				continue;
			}

			$stock    = ShopStock::stock_by_color( isset( $balances[ $article_id ] ) ? $balances[ $article_id ] : array() );
			$reserved = ShopStock::reserved_by_color( $lines, $article_id );

			foreach ( self::targets( $product, $stock, $reserved ) as $target ) {
				$shop = $target['product']->get_manage_stock() ? (int) $target['product']->get_stock_quantity() : null;

				if ( null !== $shop && $shop === (int) $target['qty'] ) {
					continue;
				}

				$rows[] = array(
					'article_id' => (int) $article_id,
					'product_id' => (int) $target['product']->get_id(),
					'name'       => (string) $target['product']->get_name(),
					'expected'   => (int) $target['qty'],
					'shop'       => $shop,
				);
			}
		}

		return $rows;
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
