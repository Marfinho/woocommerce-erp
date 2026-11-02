<?php
/**
 * Preisübergabe an das Shop-Produkt.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Scheduler;

/**
 * Der Preis eines Artikels steht im Betrieb – und muss im Shop stehen.
 *
 * Bis hierher gab es dafür eine eigene Seite, auf der man den Preis noch
 * einmal von Hand „übernahm". Zwei Wahrheiten für dieselbe Zahl: Wer den
 * Preis am Artikel änderte und den zweiten Schritt vergaß, verkaufte
 * weiter zum alten. Jetzt zieht der Shop nach, sobald der Preis am
 * Artikel gespeichert wird.
 *
 * Geschrieben wird ausschliesslich der Normalpreis. Ein laufendes Angebot
 * in WooCommerce bleibt unberührt – sonst wäre eine Preisaktion mit einem
 * Klick weg, ohne dass es jemand merkt.
 *
 * Bei einem variablen Produkt (z. B. Farben) steht der Preis nicht am
 * Hauptprodukt, sondern an jeder Variante – dort wird er geschrieben. Gibt
 * der Shop Preise netto ein, wird der Bruttopreis mit dem Steuersatz des
 * Produkts zurückgerechnet. Im Produkt-Editor ist der Normalpreis gesperrt;
 * wird er trotzdem geändert (Schnellbearbeitung, Massenbearbeitung), stellt
 * der Abgleich ihn zurück, und einmal am Tag läuft ein Gesamtabgleich.
 */
class PriceSync {

	/**
	 * Ergebnis der letzten Übertragung, für die Rückmeldung im Formular.
	 *
	 * @var array|null
	 */
	protected static $last = null;

	/**
	 * Artikel, deren Shop-Preis am Ende der Anfrage zurückgestellt wird.
	 *
	 * @var array article_id => true.
	 */
	protected static $queue = array();

	/**
	 * Schreibt der Abgleich gerade selbst ins Shop-Produkt?
	 *
	 * @var bool
	 */
	protected static $running = false;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_betrieb_article_saved', array( $this, 'on_article_saved' ), 10, 3 );

		// Sicherheitsnetz: einmal am Tag alle Preise abgleichen.
		add_action( Scheduler::HOOK_ERP_DAILY, array( __CLASS__, 'push_all' ), 30, 0 );

		if ( is_admin() ) {
			// Im Shop von Hand geändert? Dann gleich zurückstellen.
			add_action( 'woocommerce_process_product_meta', array( $this, 'on_product_edited' ), 99, 1 );
			add_action( 'woocommerce_save_product_variation', array( $this, 'on_variation_edited' ), 99, 1 );
			add_action( 'woocommerce_product_quick_edit_save', array( $this, 'on_product_object_edited' ), 99, 1 );
			add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'on_product_object_edited' ), 99, 1 );
			add_action( 'admin_footer', array( $this, 'lock_editor_fields' ) );
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Ein Shop-Produkt wurde im Editor gespeichert.
	 *
	 * @param int $product_id Produkt.
	 */
	public function on_product_edited( $product_id ) {
		if ( self::$running ) {
			return;
		}

		$article_id = StockOwnership::article_for_product( (int) $product_id );

		if ( $article_id ) {
			self::$queue[ $article_id ] = true;
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
	 * Schnell- oder Massenbearbeitung in der Produktliste.
	 *
	 * @param \WC_Product $product Produkt.
	 */
	public function on_product_object_edited( $product ) {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$this->on_product_edited( (int) $product->get_id() );
		}
	}

	/**
	 * Vorgemerkte Artikel zurückstellen.
	 */
	public static function flush() {
		if ( empty( self::$queue ) || self::$running ) {
			return;
		}

		$ids         = array_keys( self::$queue );
		self::$queue = array();

		foreach ( $ids as $article_id ) {
			self::push( (int) $article_id );
		}
	}

	/**
	 * Normalpreis im Produkt-Editor sperren und erklären, warum.
	 *
	 * Gesperrt per readonly, nicht disabled: Ein deaktiviertes Feld schickt
	 * das Formular nicht mit, und WooCommerce würde den Preis dann leeren.
	 */
	public function lock_editor_fields() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'product' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		global $post;

		if ( ! $post || ! StockOwnership::article_for_product( (int) $post->ID ) ) {
			return;
		}

		$hinweis = esc_js( __( 'Der Normalpreis kommt aus dem ERP (Katalog) und wird hier automatisch übernommen. Bitte den Preis im ERP am Artikel ändern. Angebotspreise lassen sich hier weiter setzen.', 'lindenweg16' ) );

		echo '<script>jQuery(function($){var m="' . $hinweis . '";' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. 'function lock(){$("#_regular_price,input[name^=\\"variable_regular_price\\"]").prop("readonly",true).attr("title",m).css("background","#f0f0f1");'
			. 'if(!$("#lw16-preis-hinweis").length){$("#general_product_data").prepend("<p id=\\"lw16-preis-hinweis\\" style=\\"margin:1em;padding:.6em;background:#fff8e5;border-left:4px solid #dba617\\">"+$("<div>").text(m).html()+"</p>");}}'
			. 'lock();$(document).on("woocommerce_variations_loaded woocommerce_variations_added",lock);});</script>';
	}

	/**
	 * Nach dem Speichern eines Artikels den Shop nachziehen.
	 *
	 * @param int        $article_id Artikel.
	 * @param array      $after      Gespeicherte Felder.
	 * @param array|null $before     Stand davor; null beim Anlegen.
	 */
	public function on_article_saved( $article_id, array $after, $before = null ) {
		if ( ! self::needs_push( is_array( $before ) ? $before : null, $after ) ) {
			return;
		}

		self::$last = self::push( (int) $article_id );
	}

	/**
	 * Ergebnis der letzten Übertragung abholen (und vergessen).
	 *
	 * @return array|null
	 */
	public static function take_last() {
		$last       = self::$last;
		self::$last = null;

		return $last;
	}

	/**
	 * Muss überhaupt etwas übertragen werden? Reine Logik.
	 *
	 * Ohne diese Prüfung schriebe jedes Speichern eines Artikels das
	 * Shop-Produkt neu – auch wenn nur eine Notiz geändert wurde. Das
	 * wäre eine Produktänderung pro Tippfehler, samt Eintrag in jedem
	 * Protokoll, das an WooCommerce hängt.
	 *
	 * @param array|null $before Stand davor; null beim Anlegen.
	 * @param array      $after  Gespeicherte Felder.
	 * @return bool
	 */
	public static function needs_push( $before, array $after ) {
		if ( ! array_key_exists( 'price_gross', $after ) && ! array_key_exists( 'wc_product_id', $after ) ) {
			return false;
		}

		// Eine Teiländerung schickt nur die Felder mit, die sie ändert -
		// die Preisübernahme aus der Kalkulation etwa nur den Preis. Die
		// Verknüpfung steht dann allein im Stand davor; ohne diesen
		// Rückgriff bliebe genau der Preis liegen, der gerade gesetzt
		// wurde.
		$wc_id = array_key_exists( 'wc_product_id', $after )
			? (int) $after['wc_product_id']
			: (int) ( is_array( $before ) && isset( $before['wc_product_id'] ) ? $before['wc_product_id'] : 0 );

		if ( ! $wc_id ) {
			return false;
		}

		if ( null === $before ) {
			return true;
		}

		// Erst jetzt verknüpft: Das Produkt kennt den Preis des Artikels
		// noch gar nicht.
		if ( array_key_exists( 'wc_product_id', $after )
			&& (int) ( isset( $before['wc_product_id'] ) ? $before['wc_product_id'] : 0 ) !== $wc_id ) {
			return true;
		}

		if ( ! array_key_exists( 'price_gross', $after ) ) {
			return false;
		}

		return self::differs(
			isset( $before['price_gross'] ) ? $before['price_gross'] : null,
			$after['price_gross']
		);
	}

	/**
	 * Unterscheiden sich zwei Preise? Reine Logik.
	 *
	 * Verglichen wird auf den Cent. Ein Vergleich der Zeichenketten
	 * meldete „12.90" und „12.9000" als Unterschied und schriebe das
	 * Produkt bei jedem Speichern neu.
	 *
	 * @param mixed $a        Erster Preis.
	 * @param mixed $b        Zweiter Preis.
	 * @param int   $decimals Nachkommastellen (4 für Nettopreise).
	 * @return bool
	 */
	public static function differs( $a, $b, $decimals = 2 ) {
		$leer_a = null === $a || '' === $a;
		$leer_b = null === $b || '' === $b;

		if ( $leer_a || $leer_b ) {
			return $leer_a !== $leer_b;
		}

		$decimals = max( 0, (int) $decimals );

		return abs( round( (float) $a, $decimals ) - round( (float) $b, $decimals ) ) >= 0.5 * pow( 10, -$decimals );
	}

	/**
	 * Welcher Normalpreis gehört ins Shop-Produkt? Reine Logik.
	 *
	 * Gibt der Shop Preise brutto ein, ist es der Bruttopreis auf den Cent.
	 * Gibt er sie netto ein, wird zurückgerechnet – auf vier Stellen, damit
	 * WooCommerce beim Hochrechnen wieder genau auf den Bruttopreis kommt.
	 *
	 * @param float      $gross        Bruttopreis aus dem ERP.
	 * @param bool       $net_entry    Gibt der Shop Preise netto ein?
	 * @param float|null $rate_percent Steuersatz des Produkts in Prozent.
	 * @return array {amount: string, decimals: int}
	 */
	public static function shop_amount( $gross, $net_entry, $rate_percent ) {
		$gross = (float) $gross;
		$rate  = null === $rate_percent ? 0.0 : (float) $rate_percent;

		if ( ! $net_entry || $rate <= 0 ) {
			return array( 'amount' => self::format( round( $gross, 2 ), 2 ), 'decimals' => 2 );
		}

		return array( 'amount' => self::format( round( $gross / ( 1 + $rate / 100 ), 4 ), 4 ), 'decimals' => 4 );
	}

	/**
	 * Zahl als Preis-Zeichenkette ohne überflüssige Nullen.
	 *
	 * @param float $value    Wert.
	 * @param int   $decimals Stellen.
	 * @return string
	 */
	protected static function format( $value, $decimals ) {
		$text = number_format( (float) $value, (int) $decimals, '.', '' );

		return false === strpos( $text, '.' ) ? $text : rtrim( rtrim( $text, '0' ), '.' );
	}

	/**
	 * Gibt der Shop für dieses Produkt Preise netto ein?
	 *
	 * @param \WC_Product $product Produkt oder Variante.
	 * @return bool
	 */
	protected static function net_entry( $product ) {
		if ( ! function_exists( 'wc_tax_enabled' ) || ! function_exists( 'wc_prices_include_tax' ) ) {
			return false;
		}

		return wc_tax_enabled() && ! wc_prices_include_tax() && 'taxable' === $product->get_tax_status();
	}

	/**
	 * Steuersatz eines Produkts (Shop-Basisland) in Prozent.
	 *
	 * @param \WC_Product $product Produkt oder Variante.
	 * @return float
	 */
	protected static function tax_rate( $product ) {
		if ( ! class_exists( 'WC_Tax' ) ) {
			return 0.0;
		}

		$sum = 0.0;

		foreach ( (array) \WC_Tax::get_base_tax_rates( $product->get_tax_class() ) as $rate ) {
			$sum += isset( $rate['rate'] ) ? (float) $rate['rate'] : 0.0;
		}

		return $sum;
	}

	/**
	 * Wohin gehört der Preis? Variable Produkte: an jede Variante.
	 *
	 * @param \WC_Product $product Shop-Produkt.
	 * @return \WC_Product[]
	 */
	protected static function targets( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$out = array();

			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation ) {
					$out[] = $variation;
				}
			}

			return $out;
		}

		if ( $product->is_type( 'grouped' ) ) {
			return array();
		}

		return array( $product );
	}

	/**
	 * Bruttopreis, den der Shop gerade als Normalpreis führt.
	 *
	 * Für die Anzeige im ERP: bei Varianten der der ersten, bei
	 * Netto-Eingabe hochgerechnet.
	 *
	 * @param \WC_Product $product Shop-Produkt.
	 * @return float|null
	 */
	public static function shop_gross( $product ) {
		$targets = self::targets( $product );

		if ( ! $targets ) {
			return null;
		}

		$price = $targets[0]->get_regular_price();

		if ( '' === $price || null === $price ) {
			return null;
		}

		$price = (float) $price;

		if ( self::net_entry( $targets[0] ) ) {
			$price = round( $price * ( 1 + self::tax_rate( $targets[0] ) / 100 ), 2 );
		}

		return $price;
	}

	/**
	 * Preis eines Artikels ins Shop-Produkt schreiben.
	 *
	 * @param int $article_id Artikel.
	 * @return array {status, message}
	 */
	public static function push( $article_id ) {
		$article = ArticleRepository::find( (int) $article_id );

		if ( ! $article ) {
			return self::result( 'unbekannt', __( 'Artikel nicht gefunden.', 'lindenweg16' ) );
		}

		$wc_id = isset( $article['wc_product_id'] ) ? (int) $article['wc_product_id'] : 0;

		if ( ! $wc_id ) {
			return self::result( 'ohne_shop', __( 'Kein Shop-Produkt verknüpft – der Preis bleibt im Betrieb.', 'lindenweg16' ) );
		}

		$preis = isset( $article['price_gross'] ) ? $article['price_gross'] : null;

		if ( null === $preis || (float) $preis <= 0 ) {
			return self::result( 'ohne_preis', __( 'Kein Bruttopreis am Artikel – nichts zu übertragen.', 'lindenweg16' ) );
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $wc_id ) : null;

		if ( ! $product ) {
			return self::result( 'fehlt', __( 'Das verknüpfte Shop-Produkt gibt es nicht mehr.', 'lindenweg16' ) );
		}

		$targets = self::targets( $product );

		if ( ! $targets ) {
			return self::result( 'ohne_ziel', __( 'Das verknüpfte Shop-Produkt hat keine Varianten, an denen ein Preis stehen kann.', 'lindenweg16' ) );
		}

		$geaendert = 0;
		$angebot   = false;

		self::$running = true;

		try {
			foreach ( $targets as $target ) {
				$soll = self::shop_amount( $preis, self::net_entry( $target ), self::tax_rate( $target ) );

				if ( '' !== (string) $target->get_sale_price() ) {
					$angebot = true;
				}

				if ( ! self::differs( $target->get_regular_price(), $soll['amount'], $soll['decimals'] ) ) {
					continue;
				}

				$target->set_regular_price( $soll['amount'] );
				$target->save();
				++$geaendert;
			}

			if ( $geaendert && $product->is_type( 'variable' ) && class_exists( 'WC_Product_Variable' ) ) {
				\WC_Product_Variable::sync( $product->get_id() );
			}
		} finally {
			self::$running = false;
		}

		if ( ! $geaendert ) {
			return self::result( 'gleich', __( 'Der Shop steht bereits auf diesem Preis.', 'lindenweg16' ) );
		}

		self::store_margin( (int) $article['id'], $wc_id, (float) $preis );

		AuditLog::record( 'preis_an_shop', 'article', (int) $article['id'], array( 'preis' => (float) $preis, 'wc_product_id' => $wc_id, 'ziele' => $geaendert ) );

		$message = sprintf(
			/* translators: 1: Artikelnummer, 2: Preis. */
			__( '%1$s: %2$s € in den Shop übertragen.', 'lindenweg16' ),
			$article['sku'],
			number_format_i18n( (float) $preis, 2 )
		);

		if ( $product->is_type( 'variable' ) ) {
			/* translators: %d: Anzahl Varianten. */
			$message .= ' ' . sprintf( _n( '(%d Variante)', '(%d Varianten)', $geaendert, 'lindenweg16' ), $geaendert );
		}

		if ( $angebot ) {
			$message .= ' ' . __( 'Hinweis: Im Shop läuft ein Angebotspreis – Kunden sehen weiter den Angebotspreis.', 'lindenweg16' );
		}

		return self::result( 'uebertragen', $message );
	}

	/**
	 * Alle verkäuflichen Artikel in den Shop schieben.
	 *
	 * Für den Fall, dass sich nicht ein Preis geändert hat, sondern eine
	 * Annahme darunter – dann stimmen auf einen Schlag viele nicht mehr.
	 *
	 * @return array Anzahl je Ergebnis.
	 */
	public static function push_all() {
		$zaehler = array();

		foreach ( ArticleRepository::selectable( true ) as $article ) {
			$status = self::push( (int) $article['id'] )['status'];

			$zaehler[ $status ] = isset( $zaehler[ $status ] ) ? $zaehler[ $status ] + 1 : 1;
		}

		return $zaehler;
	}

	/**
	 * Erreichte Marge am Produkt hinterlegen.
	 *
	 * Das Empfehlungsprogramm leitet die Prämie daraus ab. Stünde dort
	 * die Marge eines alten Preises, zahlte es an der neuen Kalkulation
	 * vorbei.
	 *
	 * @param int   $article_id Artikel.
	 * @param int   $wc_id      Shop-Produkt.
	 * @param float $preis      Bruttopreis.
	 */
	protected static function store_margin( $article_id, $wc_id, $preis ) {
		$settings = CalculationContext::settings();
		$cost     = CostCalculator::manufacturing_cost( $article_id, CalculationContext::build(), $settings );
		$ergebnis = CostCalculator::contribution_from_cost( $cost, $preis, $settings );

		update_post_meta( $wc_id, '_lw16_margin_percent', round( $ergebnis['db2_pct'] * 100, 2 ) );
	}

	/**
	 * Ergebnis bauen.
	 *
	 * @param string $status  Kennung.
	 * @param string $message Meldung.
	 * @return array
	 */
	protected static function result( $status, $message ) {
		return array(
			'status'  => $status,
			'message' => $message,
		);
	}
}
