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
use Lindenweg16\Core\ProductCache;

/**
 * Baut die Angebotsdaten des Konfigurators.
 */
class Catalog {

	/**
	 * Wo der fertige Katalog liegt.
	 */
	const TRANSIENT = 'lw16_konfigurator_katalog';

	/**
	 * Option mit der Fassung der Stammdaten.
	 */
	const VERSION_OPTION = 'lw16_katalog_version';

	/**
	 * Wie lange der Katalog höchstens gilt. Änderungen wirken schon über
	 * die Fassung sofort – das hier ist nur das Sicherheitsnetz.
	 */
	const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Wie viele Preiskontexte gleichzeitig abgelegt werden.
	 */
	const MAX_CONTEXTS = 8;

	/**
	 * Hat dieser Aufruf Stammdaten oder Produkte geaendert?
	 *
	 * @var bool
	 */
	protected static $geaendert = false;

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

		$context_key = self::price_context();
		$stored      = self::stored();

		if ( isset( $stored['payload'][ $context_key ] ) ) {
			$cache = $stored['payload'][ $context_key ];

			return $cache;
		}

		$cache = self::build();

		self::remember( $stored, $context_key, $cache );

		return $cache;
	}

	/**
	 * Den Katalog aus den Stammdaten und dem Shop zusammenstellen.
	 *
	 * Das ist der teure Teil: je Teil ein Produkt aus WooCommerce, bei
	 * variablen Produkten dazu jede einzelne Variante mit Preis und
	 * Bestand. Bei zwanzig Teilen in fünf Farben sind das über hundert
	 * Produktobjekte – für eine Seite, die sich zwischen zwei Bestellungen
	 * nicht ändert. Deshalb steht darüber der Zwischenspeicher.
	 *
	 * @return array
	 */
	protected static function build() {
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

		return array(
			'bases'         => $bases,
			'modules'       => $modules,
			'colors'        => self::colors( $colors ),
			'default_color' => ColorRegistry::default_color(),
			'currency'      => self::currency(),
		);
	}

	/**
	 * Den abgelegten Katalog holen, sofern er noch zur Fassung passt.
	 *
	 * @return array {version, payload}
	 */
	protected static function stored() {
		$leer = array(
			'version' => self::version(),
			'payload' => array(),
		);

		if ( ! function_exists( 'get_transient' ) ) {
			return $leer;
		}

		$stored = get_transient( self::TRANSIENT );

		if ( ! is_array( $stored )
			|| ! isset( $stored['version'], $stored['payload'] )
			|| ! is_array( $stored['payload'] )
			|| (string) $stored['version'] !== $leer['version']
		) {
			return $leer;
		}

		return $stored;
	}

	/**
	 * Den frisch gebauten Katalog ablegen.
	 *
	 * @param array  $stored      Bisheriger Stand.
	 * @param string $context_key Preiskontext.
	 * @param array  $payload     Katalogdaten.
	 */
	protected static function remember( array $stored, $context_key, array $payload ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}

		// Wer in diesem Aufruf noch etwas aendert, wuerde einen halb
		// veralteten Katalog festschreiben. Dann lieber gar nichts ablegen
		// und den naechsten Aufruf neu bauen lassen.
		if ( self::$geaendert ) {
			return;
		}

		// Der Preiskontext unterscheidet sich nur, wenn Steuern im Spiel
		// sind und der Shop brutto anzeigt. Ein paar Steuergebiete sind
		// realistisch, eine unbegrenzte Liste nicht – sie würde die Zeile
		// in der Datenbank aufblähen.
		if ( count( $stored['payload'] ) >= self::MAX_CONTEXTS ) {
			$stored['payload'] = array();
		}

		$stored['payload'][ $context_key ] = $payload;

		set_transient( self::TRANSIENT, $stored, self::TTL );
	}

	/**
	 * Fassung der Stammdaten. Ändert sie sich, ist der Speicher hinfällig.
	 *
	 * @return string
	 */
	protected static function version() {
		return (string) get_option( self::VERSION_OPTION, '1' );
	}

	/**
	 * Den abgelegten Katalog verwerfen.
	 *
	 * Aufgerufen, sobald sich ein Teil, ein Artikel, ein Sperrbereich oder
	 * ein Produkt im Shop ändert. Statt den Speicher zu löschen, zählt die
	 * Fassung hoch: Das wirkt sofort und über alle Preiskontexte hinweg,
	 * auch wenn parallel gerade jemand den alten Stand liest.
	 */
	public static function invalidate() {
		// Ein Import oder ein Massenlauf ruft das hundertfach auf. Einmal je
		// Aufruf hochzuzaehlen reicht vollkommen – der Speicher ist danach
		// so hinfaellig wie nach hundert Malen, kostet aber einen einzigen
		// Schreibvorgang statt hundert.
		if ( self::$geaendert ) {
			return;
		}

		self::$geaendert = true;

		update_option( self::VERSION_OPTION, (string) ( (int) get_option( self::VERSION_OPTION, '1' ) + 1 ), true );
	}

	/**
	 * Die Haken, die den Speicher hinfällig machen.
	 */
	public static function register_invalidation() {
		$hooks = array(
			// Stammdaten des Konfigurators und der Artikelverwaltung.
			'lw16_katalog_veraltet',
			// Produkte und Varianten im Shop: Preis, Bestand, Status.
			'woocommerce_update_product',
			'woocommerce_new_product',
			'woocommerce_trash_product',
			'woocommerce_delete_product',
			'woocommerce_update_product_variation',
			'woocommerce_new_product_variation',
			'woocommerce_product_set_stock',
			'woocommerce_variation_set_stock',
			'woocommerce_product_set_stock_status',
			'woocommerce_variation_set_stock_status',
		);

		foreach ( $hooks as $hook ) {
			add_action( $hook, array( __CLASS__, 'invalidate' ), 10, 0 );
		}
	}

	/**
	 * Kennung des Preiskontexts.
	 *
	 * `wc_get_price_to_display` rechnet je nach Einstellung mit oder ohne
	 * Steuer und, bei Bruttoanzeige, nach dem Steuergebiet des Kunden. Ein
	 * Katalog, der unter dem falschen Kontext ausgeliefert wird, zeigte
	 * falsche Preise – deshalb steckt der Kontext im Schlüssel.
	 *
	 * Für den Kleinunternehmer ohne Umsatzsteuer gibt es genau einen.
	 *
	 * @return string
	 */
	protected static function price_context() {
		if ( ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() ) {
			return 'netto';
		}

		$teile = array( (string) get_option( 'woocommerce_tax_display_shop', 'incl' ) );

		if ( function_exists( 'WC' ) && WC() && isset( WC()->customer ) && WC()->customer ) {
			$teile[] = implode( '-', (array) WC()->customer->get_taxable_address() );
		}

		return md5( implode( '|', $teile ) );
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
		$prices = self::prices( $part, $colors );

		if ( ! $prices ) {
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
	 * @return array Leer, wenn das Teil gar nicht bestellbar ist.
	 */
	protected static function prices( array $part, array $colors ) {
		$product_id = (int) $part['wc_product_id'];

		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return array();
		}

		if ( ! $product->is_type( 'variable' ) ) {
			if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				return array();
			}

			return array( '*' => round( (float) wc_get_price_to_display( $product ), 2 ) );
		}

		$taxonomy = ColorRegistry::taxonomy();
		$key      = 'attribute_' . $taxonomy;
		$prices   = array();

		// Vor dem Aufbau vorladen, nicht danach: `get_available_variations()`
		// holt jede Variante selbst einzeln herbei, und danach ist nichts
		// mehr zu sparen. Die Kennungen der Varianten stehen aber schon
		// vorher fest.
		ProductCache::prime( $product->get_children() );

		foreach ( $product->get_available_variations() as $variation ) {
			$child = wc_get_product( (int) $variation['variation_id'] );

			if ( ! $child || ! $child->is_purchasable() || ! $child->is_in_stock() ) {
				continue;
			}

			$price      = round( (float) wc_get_price_to_display( $child ), 2 );
			$attributes = isset( $variation['attributes'] ) ? (array) $variation['attributes'] : array();
			$value      = isset( $attributes[ $key ] ) ? (string) $attributes[ $key ] : '';

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

		return $prices;
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
