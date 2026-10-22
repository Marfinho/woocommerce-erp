<?php
/**
 * Web-Push für die ERP-Oberfläche.
 *
 * Der Server weckt den Service Worker des Geräts über den Push-Dienst des Browsers
 * (VAPID, RFC 8292). Es wird bewusst kein Inhalt mitgeschickt: Die Nachricht legt
 * der Server in einen Posteingang des Geräts, der Service Worker holt sie danach
 * ab. So muss nichts verschlüsselt werden, und über den Push-Dienst läuft kein Text.
 *
 * Abonnements und Posteingang liegen in den Benutzermetadaten (ein Eintrag je
 * Gerät), die Wahl der Meldungsarten ebenso. Die reinen Teile (Base64url,
 * Signatur umwandeln, Token bauen, Endpunkt prüfen) stehen ohne WordPress
 * bereit und sind getestet.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Abonnements, Versand und Abruf.
 */
class Push {

	const OPT_KEYS   = 'lw16_push_vapid';
	const META_PREFIX = 'lw16_push_';
	const META_PREFS = 'lw16_push_prefs';
	const INBOX_MAX  = 20;
	const MAX_DEVICES = 8;

	/**
	 * Zu sendende Weckrufe, werden am Ende der Anfrage abgeschickt.
	 *
	 * @var array
	 */
	protected static $queue = array();

	/**
	 * Meldungsarten: Beschriftung und nötiges ERP-Recht.
	 *
	 * @return array
	 */
	public static function categories() {
		return array(
			'bestellung' => array( __( 'Neue Shop-Bestellung', 'lindenweg16' ), 'verkauf.shop_orders' ),
			'lager'      => array( __( 'Artikel fast oder ganz ausverkauft', 'lindenweg16' ), 'lager.bestand' ),
		);
	}

	/**
	 * Hooks anmelden.
	 */
	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order_object' ), 20, 1 );
		add_action( 'woocommerce_low_stock', array( __CLASS__, 'on_low_stock' ), 20, 1 );
		add_action( 'woocommerce_no_stock', array( __CLASS__, 'on_no_stock' ), 20, 1 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 99 );
	}

	/* ====================================================================
	 * Reine Teile
	 * ================================================================== */

	/**
	 * Base64url ohne Auffüllung.
	 *
	 * @param string $bin Rohdaten.
	 * @return string
	 */
	public static function b64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Ist das ein brauchbarer Endpunkt eines Push-Dienstes?
	 *
	 * @param string $endpoint Adresse.
	 * @return bool
	 */
	public static function valid_endpoint( $endpoint ) {
		if ( ! is_string( $endpoint ) || strlen( $endpoint ) > 1000 ) {
			return false;
		}
		$parts = parse_url( $endpoint ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ( ! empty( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return false;
		}
		return (bool) preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $parts['host'] );
	}

	/**
	 * Kennung eines Geräts aus dem Endpunkt.
	 *
	 * @param string $endpoint Adresse.
	 * @return string 32 Hexzeichen.
	 */
	public static function device_id( $endpoint ) {
		return substr( hash( 'sha256', (string) $endpoint ), 0, 32 );
	}

	/**
	 * ECDSA-Signatur von DER (OpenSSL) in die Rohform r||s (64 Byte), wie JWT sie verlangt.
	 *
	 * @param string $der Signatur.
	 * @return string Leer bei Fehler.
	 */
	public static function der_to_raw( $der ) {
		$pos = 0;
		$len = strlen( $der );
		if ( $len < 8 || "\x30" !== $der[0] ) {
			return '';
		}
		$pos = ( ord( $der[1] ) & 0x80 ) ? 2 + ( ord( $der[1] ) & 0x7f ) : 2;
		$out = '';
		for ( $i = 0; $i < 2; $i++ ) {
			if ( $pos + 2 > $len || "\x02" !== $der[ $pos ] ) {
				return '';
			}
			$n   = ord( $der[ $pos + 1 ] );
			$int = substr( $der, $pos + 2, $n );
			if ( strlen( $int ) !== $n ) {
				return '';
			}
			$int  = ltrim( $int, "\x00" );
			$out .= str_pad( $int, 32, "\x00", STR_PAD_LEFT );
			$pos += 2 + $n;
		}
		return 64 === strlen( $out ) ? $out : '';
	}

	/**
	 * Signierten Kopf-Teil (JWT) für VAPID bauen.
	 *
	 * @param string   $audience Ursprung des Push-Dienstes (https://host).
	 * @param string   $subject  mailto: oder https:-Adresse des Betreibers.
	 * @param int      $exp      Ablauf (Unix-Zeit).
	 * @param callable $sign     Funktion: Text → DER-Signatur (SHA-256, ECDSA P-256).
	 * @return string Leer bei Fehler.
	 */
	public static function build_jwt( $audience, $subject, $exp, $sign ) {
		$head    = self::b64url( '{"typ":"JWT","alg":"ES256"}' );
		$payload = self::b64url( json_encode( array( 'aud' => $audience, 'exp' => (int) $exp, 'sub' => $subject ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$input   = $head . '.' . $payload;
		$der     = call_user_func( $sign, $input );
		$raw     = is_string( $der ) ? self::der_to_raw( $der ) : '';
		return '' === $raw ? '' : $input . '.' . self::b64url( $raw );
	}

	/**
	 * Eine Meldung für den Posteingang bereinigen.
	 *
	 * @param string $title Titel.
	 * @param string $body  Text.
	 * @param string $url   Ziel (relativ oder gleiche Website).
	 * @param string $tag   Gruppe.
	 * @return array
	 */
	public static function message( $title, $body, $url, $tag ) {
		$cut = static function ( $s, $n ) {
			$s = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $s ) ) );
			return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n ) : substr( $s, 0, $n );
		};
		return array(
			'title' => $cut( $title, 80 ),
			'body'  => $cut( $body, 200 ),
			'url'   => (string) $url,
			'tag'   => preg_replace( '/[^a-z0-9_-]/i', '', (string) $tag ),
			'time'  => time(),
		);
	}

	/* ====================================================================
	 * Schlüssel
	 * ================================================================== */

	/**
	 * VAPID-Schlüssel (wird beim ersten Mal erzeugt).
	 *
	 * @return array|null array( 'private' => PEM, 'public' => Base64url ) oder null, wenn OpenSSL es nicht kann.
	 */
	public static function keys() {
		$keys = get_option( self::OPT_KEYS );
		if ( is_array( $keys ) && ! empty( $keys['private'] ) && ! empty( $keys['public'] ) ) {
			return $keys;
		}
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return null;
		}
		$res = @openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $res ) {
			return null;
		}
		$pem = '';
		openssl_pkey_export( $res, $pem );
		$det = openssl_pkey_get_details( $res );
		if ( '' === $pem || empty( $det['ec']['x'] ) || empty( $det['ec']['y'] ) ) {
			return null;
		}
		$pub  = "\x04" . str_pad( $det['ec']['x'], 32, "\x00", STR_PAD_LEFT ) . str_pad( $det['ec']['y'], 32, "\x00", STR_PAD_LEFT );
		$keys = array( 'private' => $pem, 'public' => self::b64url( $pub ) );
		update_option( self::OPT_KEYS, $keys, false );
		return $keys;
	}

	/* ====================================================================
	 * Geräte
	 * ================================================================== */

	/**
	 * Geräte einer Person.
	 *
	 * @param int $user_id Benutzer.
	 * @return array Kennung => Eintrag.
	 */
	public static function devices( $user_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s", (int) $user_id, $wpdb->esc_like( self::META_PREFIX . 'dev_' ) . '%' ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$v = maybe_unserialize( $r->meta_value );
			if ( is_array( $v ) && ! empty( $v['endpoint'] ) ) {
				$out[ substr( $r->meta_key, strlen( self::META_PREFIX . 'dev_' ) ) ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Gerät zu einem Endpunkt (für den Abruf durch den Service Worker, ohne Anmeldung).
	 *
	 * @param string $endpoint Adresse.
	 * @return array|null array( 'user_id' =>, 'id' =>, 'dev' => ) oder null.
	 */
	public static function find_device( $endpoint ) {
		global $wpdb;
		if ( ! self::valid_endpoint( $endpoint ) ) {
			return null;
		}
		$id  = self::device_id( $endpoint );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 1", self::META_PREFIX . 'dev_' . $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			return null;
		}
		$dev = maybe_unserialize( $row->meta_value );
		if ( ! is_array( $dev ) || ( isset( $dev['endpoint'] ) && $dev['endpoint'] !== $endpoint ) ) {
			return null;
		}
		return array( 'user_id' => (int) $row->user_id, 'id' => $id, 'dev' => $dev );
	}

	/**
	 * Gerät anmelden.
	 *
	 * @param int    $user_id  Benutzer.
	 * @param string $endpoint Adresse.
	 * @param string $agent    Browser (zur Anzeige).
	 * @return true|string Fehlertext.
	 */
	public static function subscribe( $user_id, $endpoint, $agent ) {
		if ( ! self::valid_endpoint( $endpoint ) ) {
			return __( 'Der Push-Dienst deines Browsers hat eine unbrauchbare Adresse geliefert.', 'lindenweg16' );
		}
		$id   = self::device_id( $endpoint );
		$have = self::devices( $user_id );
		if ( ! isset( $have[ $id ] ) && count( $have ) >= self::MAX_DEVICES ) {
			return __( 'Zu viele Geräte angemeldet. Bitte zuerst ein altes Gerät abmelden.', 'lindenweg16' );
		}
		$old = self::find_device( $endpoint );
		if ( $old && (int) $old['user_id'] !== (int) $user_id ) {
			delete_user_meta( $old['user_id'], self::META_PREFIX . 'dev_' . $id );
		}
		update_user_meta(
			$user_id,
			self::META_PREFIX . 'dev_' . $id,
			array(
				'endpoint' => $endpoint,
				'agent'    => substr( sanitize_text_field( $agent ), 0, 120 ),
				'since'    => isset( $have[ $id ]['since'] ) ? $have[ $id ]['since'] : time(),
				'inbox'    => isset( $have[ $id ]['inbox'] ) ? $have[ $id ]['inbox'] : array(),
			)
		);
		return true;
	}

	/**
	 * Gerät abmelden.
	 *
	 * @param int    $user_id  Benutzer.
	 * @param string $endpoint Adresse.
	 */
	public static function unsubscribe( $user_id, $endpoint ) {
		delete_user_meta( $user_id, self::META_PREFIX . 'dev_' . self::device_id( $endpoint ) );
	}

	/**
	 * Gewählte Meldungsarten einer Person.
	 *
	 * @param int $user_id Benutzer.
	 * @return array Schlüssel => bool; Standard: alle an.
	 */
	public static function prefs( $user_id ) {
		$saved = get_user_meta( $user_id, self::META_PREFS, true );
		$out   = array();
		foreach ( self::categories() as $key => $def ) {
			$out[ $key ] = is_array( $saved ) && array_key_exists( $key, $saved ) ? (bool) $saved[ $key ] : true;
		}
		return $out;
	}

	/**
	 * Meldungsarten speichern.
	 *
	 * @param int   $user_id Benutzer.
	 * @param array $in      Schlüssel => bool.
	 */
	public static function save_prefs( $user_id, $in ) {
		$out = array();
		foreach ( self::categories() as $key => $def ) {
			$out[ $key ] = ! empty( $in[ $key ] );
		}
		update_user_meta( $user_id, self::META_PREFS, $out );
	}

	/* ====================================================================
	 * Versand und Abruf
	 * ================================================================== */

	/**
	 * Meldung an alle berechtigten Personen, die diese Art eingeschaltet haben.
	 *
	 * @param string $category Art (Schlüssel aus categories()).
	 * @param string $title    Titel.
	 * @param string $body     Text.
	 * @param string $url      Ziel in der Oberfläche, z. B. '#/seite/shop-bestellungen'.
	 */
	public static function notify( $category, $title, $body, $url = '' ) {
		global $wpdb;
		$cats = self::categories();
		if ( ! isset( $cats[ $category ] ) ) {
			return;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT user_id, meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( self::META_PREFIX . 'dev_' ) . '%' ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		$msg = self::message( $title, $body, $url, $category );
		$ok  = array();
		foreach ( (array) $rows as $r ) {
			$uid = (int) $r->user_id;
			if ( ! isset( $ok[ $uid ] ) ) {
				$prefs      = self::prefs( $uid );
				$ok[ $uid ] = ! empty( $prefs[ $category ] ) && Access::can( $cats[ $category ][1], $uid );
			}
			if ( ! $ok[ $uid ] ) {
				continue;
			}
			$dev = get_user_meta( $uid, $r->meta_key, true );
			if ( ! is_array( $dev ) || empty( $dev['endpoint'] ) ) {
				continue;
			}
			$inbox   = isset( $dev['inbox'] ) && is_array( $dev['inbox'] ) ? $dev['inbox'] : array();
			$inbox[] = $msg;
			$dev['inbox'] = array_slice( $inbox, -self::INBOX_MAX );
			update_user_meta( $uid, $r->meta_key, $dev );
			self::$queue[ $uid . ':' . $r->meta_key ] = array( 'uid' => $uid, 'key' => $r->meta_key, 'endpoint' => $dev['endpoint'] );
		}
	}

	/**
	 * Weckrufe abschicken (am Ende der Anfrage, damit die Kasse nicht wartet).
	 */
	public static function flush() {
		$queue       = self::$queue;
		self::$queue = array();
		foreach ( $queue as $q ) {
			$code = self::wake( $q['endpoint'] );
			if ( 404 === $code || 410 === $code ) {
				delete_user_meta( $q['uid'], $q['key'] );
			}
		}
	}

	/**
	 * Einen Weckruf an den Push-Dienst schicken.
	 *
	 * @param string $endpoint Adresse.
	 * @return int HTTP-Status, 0 bei Fehler.
	 */
	public static function wake( $endpoint ) {
		$keys = self::keys();
		if ( ! $keys || ! self::valid_endpoint( $endpoint ) ) {
			return 0;
		}
		$p   = parse_url( $endpoint ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$aud = 'https://' . $p['host'];
		$sub = 'mailto:' . sanitize_email( get_option( 'admin_email' ) );
		$jwt = self::build_jwt(
			$aud,
			$sub,
			time() + 12 * 3600,
			static function ( $input ) use ( $keys ) {
				$sig = '';
				return openssl_sign( $input, $sig, $keys['private'], OPENSSL_ALGO_SHA256 ) ? $sig : '';
			}
		);
		if ( '' === $jwt ) {
			return 0;
		}
		$res = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'   => 5,
				'headers'   => array(
					'Authorization'  => 'vapid t=' . $jwt . ', k=' . $keys['public'],
					'TTL'            => '3600',
					'Urgency'        => 'normal',
					'Content-Length' => '0',
				),
				'body'      => '',
				'sslverify' => true,
			)
		);
		return is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	}

	/**
	 * Posteingang eines Geräts leeren und zurückgeben.
	 *
	 * @param string $endpoint Adresse.
	 * @return array Meldungen (neueste zuletzt).
	 */
	public static function take_inbox( $endpoint ) {
		$found = self::find_device( $endpoint );
		if ( ! $found ) {
			return array();
		}
		$inbox = isset( $found['dev']['inbox'] ) && is_array( $found['dev']['inbox'] ) ? $found['dev']['inbox'] : array();
		if ( $inbox ) {
			$found['dev']['inbox'] = array();
			update_user_meta( $found['user_id'], self::META_PREFIX . 'dev_' . $found['id'], $found['dev'] );
		}
		return $inbox;
	}

	/* ====================================================================
	 * Auslöser
	 * ================================================================== */

	/**
	 * Neue Bestellung (klassischer Checkout).
	 *
	 * @param int $order_id Bestellung.
	 */
	public static function on_order( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( $order ) {
			self::on_order_object( $order );
		}
	}

	/**
	 * Neue Bestellung.
	 *
	 * @param \WC_Order $order Bestellung.
	 */
	public static function on_order_object( $order ) {
		static $done = array();
		if ( ! is_object( $order ) || isset( $done[ $order->get_id() ] ) ) {
			return;
		}
		$done[ $order->get_id() ] = true;
		self::notify(
			'bestellung',
			sprintf( /* translators: %s: Bestellnummer */ __( 'Neue Bestellung #%s', 'lindenweg16' ), $order->get_order_number() ),
			sprintf( /* translators: 1: Summe, 2: Anzahl Positionen */ __( '%1$s, %2$d Positionen', 'lindenweg16' ), wp_strip_all_tags( html_entity_decode( wc_price( $order->get_total() ) ) ), (int) $order->get_item_count() ),
			'#/seite/shop-bestellungen'
		);
	}

	/**
	 * Bestand niedrig.
	 *
	 * @param \WC_Product $product Produkt.
	 */
	public static function on_low_stock( $product ) {
		if ( is_object( $product ) ) {
			self::notify( 'lager', __( 'Bestand wird knapp', 'lindenweg16' ), $product->get_name(), '#/seite/lager' );
		}
	}

	/**
	 * Ausverkauft.
	 *
	 * @param \WC_Product $product Produkt.
	 */
	public static function on_no_stock( $product ) {
		if ( is_object( $product ) ) {
			self::notify( 'lager', __( 'Ausverkauft', 'lindenweg16' ), $product->get_name(), '#/seite/lager' );
		}
	}
}
