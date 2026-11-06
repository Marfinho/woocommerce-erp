<?php
/**
 * Einblicke ohne Einwilligung: Produkt-Trichter, Kampagnen, Suche, Fehlerseiten.
 *
 * Alles sind anonyme Tageszähler (siehe `Stat`): Wie oft wurde ein Produkt
 * angesehen und in den Warenkorb gelegt, wie viele Besuche kamen aus welcher
 * Kampagne, was wurde gesucht und gefunden, welche Adressen gibt es nicht. Die
 * Bestellungen dazu kennt WooCommerce selbst.
 *
 * Nicht gezählt werden angemeldete Personen, Bots und Browser mit „Do Not Track“.
 * Suchbegriffe mit „@“, langen Zahlen oder Links werden verworfen und erscheinen
 * erst ab MIN_SHOW gleichen Fällen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

use Lindenweg16\Modules\Kurzlinks\OrderOrigin;

defined( 'ABSPATH' ) || exit;

/**
 * Zählen und Auswerten.
 */
class Insights {

	/**
	 * Suchbegriffe und Adressen erscheinen erst ab so vielen gleichen Fällen.
	 */
	const MIN_SHOW = 2;

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'woocommerce_add_to_cart', array( $this, 'add_to_cart' ), 10, 3 );
		add_action( 'template_redirect', array( $this, 'track_request' ), 99 );
	}

	/**
	 * Ein Produkt wurde in den Warenkorb gelegt.
	 *
	 * @param string $cart_id    Schlüssel der Position.
	 * @param int    $product_id Produkt.
	 */
	public function add_to_cart( $cart_id, $product_id ) {
		unset( $cart_id );

		if ( is_user_logged_in() || self::request_is_bot() ) {
			return;
		}

		Stat::bump( 'cart', (string) (int) $product_id, '', time() );
	}

	/**
	 * Suche und Fehlerseiten der laufenden Anfrage zählen.
	 */
	public function track_request() {
		if ( is_admin() || is_user_logged_in() || is_preview() || is_feed() || self::request_is_bot() ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'GET' !== $method ) {
			return;
		}

		if ( is_search() ) {
			$term = self::clean_term( get_query_var( 's' ) );

			if ( '' !== $term ) {
				Stat::bump( 'search', $term, '', time() );

				global $wp_query;
				if ( $wp_query && 0 === (int) $wp_query->found_posts ) {
					Stat::bump( 'miss', $term, '', time() );
				}
			}

			return;
		}

		if ( is_404() ) {
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$path = Presence::clean_path( (string) wp_parse_url( $uri, PHP_URL_PATH ) );

			if ( self::is_noise_path( $path ) ) {
				return;
			}

			$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			Stat::bump( 'e404', substr( $path, 0, 120 ), Flow::source( $ref, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), time() );
		}
	}

	/**
	 * Kommt die Anfrage von einem Roboter?
	 *
	 * @return bool
	 */
	protected static function request_is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		return Traffic::is_bot( $ua );
	}

	/**
	 * Neu geöffnete Seite: Produktansicht und Kampagne zählen.
	 *
	 * @param array $body Meldung des Browsers (i = Produktnummer, q = utm-Teil der Adresse).
	 * @param int   $now  Zeitpunkt.
	 */
	public static function on_view( array $body, $now ) {
		$pid = isset( $body['i'] ) && is_numeric( $body['i'] ) ? (int) $body['i'] : 0;

		if ( $pid > 0 ) {
			Stat::bump( 'pview', (string) $pid, '', $now );
		}

		$camp = self::campaign( isset( $body['q'] ) ? $body['q'] : '' );

		if ( null !== $camp ) {
			Stat::bump( 'camp', $camp['source'], $camp['campaign'], $now );
		}
	}

	/**
	 * Suchbegriff bereinigen – reine Regel.
	 *
	 * @param mixed $raw Eingabe.
	 * @return string Kleinbuchstaben, 2 bis 60 Zeichen; leer, wenn er nach Person oder Link aussieht.
	 */
	public static function clean_term( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$term = function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw ) : strtolower( $raw );
		$term = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $term ) ) );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $term ) : strlen( $term );

		if ( $len < 2 || $len > 60 || false !== strpos( $term, '@' ) || false !== strpos( $term, '://' ) || false !== strpos( $term, 'www.' ) || preg_match( '/\d{6,}/', $term ) ) {
			return '';
		}

		return $term;
	}

	/**
	 * Quelle und Kampagne aus dem utm-Teil der Adresse – reine Regel.
	 *
	 * @param mixed $query Teil der Adresse, z. B. „?utm_source=insta&utm_campaign=herbst“.
	 * @return array|null {source, campaign} oder null ohne Angaben.
	 */
	public static function campaign( $query ) {
		if ( ! is_string( $query ) || '' === $query ) {
			return null;
		}

		$args = array();
		parse_str( ltrim( substr( $query, 0, 300 ), '?&' ), $args );

		$clean = static function ( $key ) use ( $args ) {
			$v = isset( $args[ $key ] ) && is_string( $args[ $key ] ) ? strtolower( $args[ $key ] ) : '';

			return substr( (string) preg_replace( '/[^a-z0-9_.\-]/', '', $v ), 0, 40 );
		};

		$source   = $clean( 'utm_source' );
		$campaign = $clean( 'utm_campaign' );

		if ( '' === $source && '' === $campaign ) {
			return null;
		}

		return array(
			'source'   => '' === $source ? 'ohne-quelle' : $source,
			'campaign' => $campaign,
		);
	}

	/**
	 * Gehört die Adresse zu Dateien oder Systemseiten, die keine kaputten Links sind? – reine Regel.
	 *
	 * @param string $path Pfad.
	 * @return bool
	 */
	public static function is_noise_path( $path ) {
		$path = strtolower( (string) $path );

		return (bool) preg_match( '#\.(css|js|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|mp4|txt|xml|php|env|sql|zip|bak)$#', $path )
			|| (bool) preg_match( '#^/(wp-content|wp-includes|wp-admin|wp-json|xmlrpc|\.well-known|\.git|cgi-bin)#', $path );
	}

	/**
	 * Produkt-Trichter verbinden – reine Rechnung.
	 *
	 * @param array $views  Produktnummer => Ansichten.
	 * @param array $carts  Produktnummer => in den Warenkorb gelegt.
	 * @param array $orders Produktnummer => Bestellungen.
	 * @param array $names  Produktnummer => Name.
	 * @return array Liste {id, name, views, carts, orders, cart_rate, buy_rate, hint}, meistangesehene zuerst.
	 */
	public static function funnel( array $views, array $carts, array $orders, array $names ) {
		$ids = array_unique( array_merge( array_keys( $views ), array_keys( $carts ), array_keys( $orders ) ) );
		$out = array();

		foreach ( $ids as $id ) {
			$v = isset( $views[ $id ] ) ? (int) $views[ $id ] : 0;
			$c = isset( $carts[ $id ] ) ? (int) $carts[ $id ] : 0;
			$o = isset( $orders[ $id ] ) ? (int) $orders[ $id ] : 0;

			$hint = '';
			if ( $v >= 10 && 0 === $c ) {
				$hint = 'nie_im_warenkorb';
			} elseif ( $v >= 10 && 0 === $o ) {
				$hint = 'nie_gekauft';
			} elseif ( $c >= 5 && $o * 4 < $c ) {
				$hint = 'warenkorb_abbruch';
			}

			$out[] = array(
				'id'        => (int) $id,
				'name'      => isset( $names[ $id ] ) && '' !== $names[ $id ] ? (string) $names[ $id ] : '#' . $id,
				'views'     => $v,
				'carts'     => $c,
				'orders'    => $o,
				'cart_rate' => $v > 0 ? round( $c / $v * 100, 1 ) : null,
				'buy_rate'  => $v > 0 ? round( $o / $v * 100, 1 ) : null,
				'hint'      => $hint,
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['views'] <=> $a['views'] ?: $b['orders'] <=> $a['orders'] ?: $a['id'] <=> $b['id'];
			}
		);

		return $out;
	}

	/**
	 * Zählzeilen nach Mindestzahl trennen – reine Regel.
	 *
	 * @param array $rows Zeilen {label, extra, n}.
	 * @param int   $min  Mindestzahl.
	 * @return array {rows: [{label, extra, n}], hidden: Summe der übrigen}
	 */
	public static function visible( array $rows, $min = self::MIN_SHOW ) {
		$out    = array();
		$hidden = 0;

		foreach ( $rows as $r ) {
			$n = (int) $r['n'];

			if ( $n < $min ) {
				$hidden += $n;
				continue;
			}

			$out[] = array(
				'label' => (string) $r['label'],
				'extra' => (string) $r['extra'],
				'n'     => $n,
			);
		}

		return array(
			'rows'   => array_slice( $out, 0, 50 ),
			'hidden' => $hidden,
		);
	}

	/**
	 * Besuche und Bestellungen je Quelle verbinden – reine Rechnung.
	 *
	 * @param array $visits Zeilen {label = Quelle, extra = Kampagne, n}.
	 * @param array $origin Quelle => {anzahl, umsatz} (siehe OrderOrigin::aggregate).
	 * @return array Liste {source, visits, orders, revenue, rate, campaigns: [{name, visits}]}, meiste Besuche zuerst.
	 */
	public static function campaigns( array $visits, array $origin ) {
		$by = array();

		foreach ( $visits as $v ) {
			$s = (string) $v['label'];

			if ( ! isset( $by[ $s ] ) ) {
				$by[ $s ] = array(
					'source'    => $s,
					'visits'    => 0,
					'orders'    => 0,
					'revenue'   => 0.0,
					'rate'      => null,
					'campaigns' => array(),
				);
			}

			$by[ $s ]['visits'] += (int) $v['n'];

			if ( '' !== (string) $v['extra'] ) {
				$by[ $s ]['campaigns'][] = array(
					'name'   => (string) $v['extra'],
					'visits' => (int) $v['n'],
				);
			}
		}

		foreach ( $origin as $source => $o ) {
			$key = (string) $source;

			if ( ! isset( $by[ $key ] ) ) {
				$by[ $key ] = array(
					'source'    => $key,
					'visits'    => 0,
					'orders'    => 0,
					'revenue'   => 0.0,
					'rate'      => null,
					'campaigns' => array(),
				);
			}

			$by[ $key ]['orders']  = (int) $o['anzahl'];
			$by[ $key ]['revenue'] = round( (float) $o['umsatz'], 2 );
		}

		foreach ( $by as &$row ) {
			$row['rate'] = $row['visits'] > 0 ? round( $row['orders'] / $row['visits'] * 100, 1 ) : null;
		}
		unset( $row );

		$list = array_values( $by );

		usort(
			$list,
			static function ( $a, $b ) {
				return $b['visits'] <=> $a['visits'] ?: $b['orders'] <=> $a['orders'] ?: strcmp( $a['source'], $b['source'] );
			}
		);

		return $list;
	}

	/**
	 * Bestellungen je Produkt im Zeitraum (aus WooCommerce).
	 *
	 * @param int $days Zeitraum.
	 * @return array Produktnummer => Bestellungen; leer, wenn die Auswertungstabelle fehlt.
	 */
	public static function orders_by_product( $days ) {
		global $wpdb;

		$lookup = $wpdb->prefix . 'wc_order_product_lookup';
		$stats  = $wpdb->prefix . 'wc_order_stats';

		if ( $lookup !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) ) || $stats !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stats ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return array();
		}

		$since = gmdate( 'Y-m-d', time() - ( (int) $days - 1 ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT l.product_id AS pid, COUNT(DISTINCT l.order_id) AS orders FROM {$lookup} l INNER JOIN {$stats} s ON s.order_id = l.order_id WHERE s.status IN ('wc-processing','wc-completed','wc-on-hold') AND l.date_created >= %s GROUP BY l.product_id", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['pid'] ] = (int) $r['orders'];
		}

		return $out;
	}

	/**
	 * Auswertung für das ERP.
	 *
	 * @param int $days Zeitraum (7, 30 oder 90).
	 * @return array {days, products, campaigns, search, misses, e404}
	 */
	public static function snapshot( $days ) {
		$days   = Flow::clean_days( $days );
		$views  = array();
		$carts  = array();
		$names  = array();
		$origin = array();

		foreach ( Stat::rows( 'pview', $days ) as $r ) {
			$views[ (int) $r['label'] ] = (int) $r['n'];
		}

		foreach ( Stat::rows( 'cart', $days ) as $r ) {
			$carts[ (int) $r['label'] ] = (int) $r['n'];
		}

		$orders = self::orders_by_product( $days );

		foreach ( array_unique( array_merge( array_keys( $views ), array_keys( $carts ), array_keys( $orders ) ) ) as $id ) {
			$names[ $id ] = function_exists( 'get_the_title' ) ? html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) : '';
		}

		if ( class_exists( OrderOrigin::class ) ) {
			$origin = OrderOrigin::aggregate( OrderOrigin::collect( $days ) );
		}

		$search = self::visible( Stat::rows( 'search', $days ) );
		$miss   = self::visible( Stat::rows( 'miss', $days ) );
		$e404   = self::visible( Stat::rows( 'e404', $days ) );

		return array(
			'days'      => $days,
			'products'  => array_slice( self::funnel( $views, $carts, $orders, $names ), 0, 100 ),
			'campaigns' => self::campaigns( Stat::rows( 'camp', $days ), $origin ),
			'search'    => $search,
			'misses'    => $miss,
			'e404'      => $e404,
		);
	}
}
