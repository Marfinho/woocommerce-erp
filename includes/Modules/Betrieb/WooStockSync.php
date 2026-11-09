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

		$ctx = OrderLines::context();

		foreach ( $order->get_items() as $item ) {
			foreach ( OrderLines::for_item( $item, $ctx ) as $line ) {
				self::queue( (int) $line['article_id'] );
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

		foreach ( OrderLines::articles_of_product( (int) $product_id ) as $article_id ) {
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

		if ( $result['geaendert'] ) {
			Log::attention( 'shop-bestand', sprintf( 'Abgleich hat %d Shop-Einträge korrigiert (geprüft: %d) - jemand hat den Bestand im Shop von Hand geändert oder eine Buchung kam nicht an.', $result['geaendert'], $result['geprueft'] ) );
		} else {
			Log::normal( 'shop-bestand', sprintf( 'Abgleich ohne Abweichung (%d Artikel geprüft)', $result['geprueft'] ) );
		}

		return $result;
	}

	/**
	 * Mehrere Artikel abgleichen.
	 *
	 * Abgeglichen wird je Shop-Produkt: Hinter einem Produkt können mehrere
	 * Artikel stehen (eine Variante je Typ oder Holz), und ein Set mit
	 * wählbaren Modulen hat den Bestand seiner Module. Ändert sich der
	 * Bestand eines Moduls, wird deshalb auch jedes Set nachgezogen, in das
	 * es gepackt wird.
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
			$ctx      = OrderLines::context();
			$products = ShopStock::products_for( array_map( 'intval', $article_ids ), $ctx );
			$free     = self::free_units( $ctx );

			foreach ( $article_ids as $article_id ) {
				$article = isset( $ctx['articles'][ (int) $article_id ] ) ? (array) $ctx['articles'][ (int) $article_id ] : array();
				if ( empty( $article['wc_product_id'] ) ) {
					$result['ohne_produkt']++;
				}
			}

			foreach ( $products as $product_id ) {
				$product = wc_get_product( (int) $product_id );

				if ( ! $product ) {
					$result['ohne_produkt']++;
					continue;
				}

				$result['geprueft']++;
				$result['geaendert'] += self::write_product( $product, $free, $ctx );
			}
		} finally {
			self::$running = false;
		}

		return $result;
	}

	/**
	 * Freie Menge je Artikel und Farbe: Lager minus offene Bestellungen.
	 *
	 * @param array $ctx Kontext.
	 * @return array article_id => color => Menge.
	 */
	protected static function free_units( array $ctx ) {
		$balances = StockRepository::balances();
		$lines    = WooIntegration::open_order_lines();
		$free     = array();

		foreach ( array_keys( $ctx['articles'] ) as $article_id ) {
			$free[ (int) $article_id ] = ShopStock::free(
				ShopStock::stock_by_color( isset( $balances[ $article_id ] ) ? $balances[ $article_id ] : array() ),
				ShopStock::reserved_by_color( $lines, (int) $article_id )
			);
		}

		return $free;
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
	 * bei variablen jede Variante nach ihrem Artikel und ihrer Farbe.
	 *
	 * @param \WC_Product $product Produkt.
	 * @param array       $free    article_id => color => freie Menge.
	 * @param array       $ctx     Kontext.
	 * @return array Produkt-ID => array( product => Objekt, qty => Stück, article_id => Artikel ).
	 */
	protected static function targets( $product, array $free, array $ctx ) {
		$group = isset( $ctx['groups'][ (int) $product->get_id() ] ) ? $ctx['groups'][ (int) $product->get_id() ] : array();

		if ( ! $group ) {
			return array();
		}

		if ( ! $product->is_type( 'variable' ) ) {
			$pick = Variants::pick( $group, array() );

			return array(
				$product->get_id() => array(
					'product'    => $product,
					'qty'        => ShopStock::simple_units( $free, (int) $pick['article_id'], $ctx ),
					'article_id' => (int) $pick['article_id'],
				),
			);
		}

		$color_key = Variants::key( ColorRegistry::taxonomy() );
		$default   = ColorRegistry::default_color();
		$out       = array();

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation ) {
				continue;
			}

			$attrs = OrderLines::variation_attributes( $variation );
			$color = isset( $attrs[ $color_key ] ) ? ColorRegistry::normalize( $attrs[ $color_key ] ) : '';
			$pick  = Variants::pick( $group, $attrs );

			$out[ $child_id ] = array(
				'product'    => $variation,
				'qty'        => ShopStock::variant_units( $free, (int) $pick['article_id'], '' === $color ? $default : $color, $attrs, $ctx ),
				'article_id' => (int) $pick['article_id'],
			);
		}

		return $out;
	}

	/**
	 * Bestand in ein Shop-Produkt schreiben.
	 *
	 * @param \WC_Product $product Produkt.
	 * @param array       $free    article_id => color => freie Menge.
	 * @param array       $ctx     Kontext.
	 * @return int Anzahl geänderter Produkte beziehungsweise Varianten.
	 */
	protected static function write_product( $product, array $free, array $ctx ) {
		$changed = 0;

		// Bei variablen Produkten liegt der Bestand an den Varianten; ein
		// eigener am Elternprodukt würde ihn überlagern.
		if ( $product->is_type( 'variable' ) && $product->get_manage_stock() ) {
			$product->set_manage_stock( false );
			$product->save();
			$changed++;
		}

		foreach ( self::targets( $product, $free, $ctx ) as $target ) {
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

		$ctx  = OrderLines::context();
		$free = self::free_units( $ctx );

		foreach ( array_keys( $ctx['groups'] ) as $wc_id ) {
			$product = wc_get_product( (int) $wc_id );

			if ( ! $product ) {
				continue;
			}

			foreach ( self::targets( $product, $free, $ctx ) as $target ) {
				$shop = $target['product']->get_manage_stock() ? (int) $target['product']->get_stock_quantity() : null;

				if ( null !== $shop && $shop === (int) $target['qty'] ) {
					continue;
				}

				$rows[] = array(
					'article_id' => (int) $target['article_id'],
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
	 * Was gilt im Shop bei Bestand 0?
	 *
	 * Eine Einstellung für alle Produkte (ERP → Einstellungen → Farben & Lager):
	 * `no` = ausverkauft, nicht kaufbar; `notify` = bestellbar, Kunde wird auf
	 * die Lieferzeit hingewiesen; `yes` = bestellbar ohne Hinweis.
	 *
	 * @return string no|notify|yes.
	 */
	public static function backorder_mode() {
		$mode = (string) Settings::get( 'betrieb_shop_nachbestellung', 'no' );

		return in_array( $mode, array( 'notify', 'yes' ), true ) ? $mode : 'no';
	}

	/**
	 * Menge setzen, wenn sie sich ändert.
	 *
	 * Ob bei Bestand 0 noch bestellt werden darf, bestimmt die globale
	 * Einstellung „Bestand 0 im Shop“ (Standard: nein, was nicht da ist, ist
	 * nicht kaufbar).
	 *
	 * @param \WC_Product $product Produkt oder Variante.
	 * @param int         $qty     Menge.
	 * @return bool Wurde geschrieben?
	 */
	protected static function write_quantity( $product, $qty ) {
		$mode = self::backorder_mode();

		if ( $qty > 0 ) {
			$status = 'instock';
		} else {
			$status = 'no' === $mode ? 'outofstock' : 'onbackorder';
		}

		if ( $product->get_manage_stock()
			&& (int) $product->get_stock_quantity() === (int) $qty
			&& $mode === $product->get_backorders()
			&& $product->get_stock_status() === $status ) {
			return false;
		}

		$product->set_manage_stock( true );
		$product->set_backorders( $mode );
		$product->set_stock_quantity( (int) $qty );
		$product->set_stock_status( $status );
		$product->save();

		return true;
	}
}
