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

use Lindenweg16\Core\Scheduler;

/**
 * Abonnements, Versand und Abruf.
 */
class Push {

	const OPT_KEYS   = 'lw16_push_vapid';
	const META_PREFIX = 'lw16_push_';
	const META_PREFS = 'lw16_push_prefs';
	const INBOX_MAX  = 20;
	const MAX_DEVICES = 8;
	const OPT_MORNING = 'lw16_push_morgens';
	const MORNING_HOUR = 8;

	/**
	 * Zu sendende Weckrufe, werden am Ende der Anfrage abgeschickt.
	 *
	 * @var array
	 */
	protected static $queue = array();

	/**
	 * Meldungsarten: Beschriftung, nötiges ERP-Recht, Bereich und ob sie ohne
	 * eigene Wahl an ist.
	 *
	 * Nur die beiden ersten sind von Anfang an an (so war es, bevor es mehr
	 * gab); alles Weitere schaltet jede Person für sich ein.
	 *
	 * @return array Schlüssel => array( Beschriftung, Recht, Bereich, an ).
	 */
	public static function categories() {
		return array(
			// Verkauf.
			'bestellung'      => array( __( 'Neue Shop-Bestellung', 'lindenweg16' ), 'verkauf.shop_orders', 'verkauf', true ),
			'zahlung'         => array( __( 'Zahlung zu einer wartenden Bestellung eingegangen (z. B. Vorkasse)', 'lindenweg16' ), 'verkauf.shop_orders', 'verkauf', false ),
			'storno'          => array( __( 'Bestellung storniert', 'lindenweg16' ), 'verkauf.shop_orders', 'verkauf', false ),
			'erstattung'      => array( __( 'Erstattung gebucht', 'lindenweg16' ), 'verkauf.shop_orders', 'verkauf', false ),
			'bewertung'       => array( __( 'Neue Produktbewertung', 'lindenweg16' ), 'verkauf.shop_products', 'verkauf', false ),
			'warteliste'      => array( __( 'Kunde trägt sich in die Warteliste eines Artikels ein', 'lindenweg16' ), 'verkauf.shop_products', 'verkauf', false ),
			'nachfassen'      => array( __( 'Wiedervorlage bei einem Kontakt fällig – nachfassen (morgens)', 'lindenweg16' ), 'verkauf.kontakte', 'verkauf', false ),
			'etikett_fehler'  => array( __( 'Etikettendruck fehlgeschlagen', 'lindenweg16' ), 'verkauf.versand', 'verkauf', false ),
			// Fertigung.
			'druck_fertig'    => array( __( 'Bambu-Druck fertig – Platte abräumen', 'lindenweg16' ), 'fertigung.bambu', 'fertigung', false ),
			'druck_fehler'    => array( __( 'Bambu-Druck fehlgeschlagen oder Ausgang unklar', 'lindenweg16' ), 'fertigung.bambu', 'fertigung', false ),
			'slicen_fehler'   => array( __( 'Slicen einer Platte fehlgeschlagen', 'lindenweg16' ), 'fertigung.workflow', 'fertigung', false ),
			'wartung'         => array( __( 'Drucker-Wartung neu fällig (morgens)', 'lindenweg16' ), 'fertigung.drucker', 'fertigung', false ),
			// Lager.
			'lager'           => array( __( 'Artikel fast oder ganz ausverkauft', 'lindenweg16' ), 'lager.bestand', 'lager', true ),
			'wieder_da'       => array( __( 'Ausverkaufter Artikel wieder auf Lager', 'lindenweg16' ), 'lager.bestand', 'lager', false ),
			'nachbestellen'   => array( __( 'Neu nachzubestellen: Bestand reicht nicht bis zur Lieferung (morgens)', 'lindenweg16' ), 'lager.einkauf', 'lager', false ),
			// Finanzen.
			'erechnung'       => array( __( 'E-Rechnung eingegangen', 'lindenweg16' ), 'finanzen.buchhaltung', 'finanzen', false ),
			// Verwaltung.
			'support'         => array( __( 'Neue Support-Anfrage', 'lindenweg16' ), 'verwaltung.support', 'verwaltung', false ),
			'mail_zugewiesen' => array( __( 'Mir wurde eine Mail zugewiesen', 'lindenweg16' ), 'verwaltung.mailbox', 'verwaltung', false ),
		);
	}

	/**
	 * Hooks anmelden.
	 */
	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order_object' ), 20, 1 );
		add_action( 'woocommerce_order_status_on-hold_to_processing', array( __CLASS__, 'on_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_cancelled' ), 20, 1 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refunded' ), 20, 2 );
		add_action( 'comment_post', array( __CLASS__, 'on_comment' ), 20, 3 );
		add_action( 'woocommerce_low_stock', array( __CLASS__, 'on_low_stock' ), 20, 1 );
		add_action( 'woocommerce_no_stock', array( __CLASS__, 'on_no_stock' ), 20, 1 );
		add_action( 'lw16_stock_replenished', array( __CLASS__, 'on_product_replenished' ), 20, 1 );
		add_action( 'lw16_betrieb_stock_replenished', array( __CLASS__, 'on_article_replenished' ), 20, 1 );
		add_action( 'lw16_waitlist_signup', array( __CLASS__, 'on_waitlist' ), 20, 2 );
		add_action( 'lw16_support_ticket_created', array( __CLASS__, 'on_ticket' ), 20, 2 );
		add_action( 'lw16_audit_written', array( __CLASS__, 'on_audit' ), 20, 1 );
		add_action( Scheduler::HOOK_SHOP_STOCK, array( __CLASS__, 'morning_check' ), 20, 0 );
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
			$out[ $key ] = is_array( $saved ) && array_key_exists( $key, $saved ) ? (bool) $saved[ $key ] : ! empty( $def[3] );
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
	 * @param int    $only     Nur an diese Person (0 = an alle).
	 * @param int    $except   Nicht an diese Person, z. B. wer es selbst ausgelöst hat.
	 */
	public static function notify( $category, $title, $body, $url = '', $only = 0, $except = 0 ) {
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
			if ( ( $only && $uid !== (int) $only ) || ( $except && $uid === (int) $except ) ) {
				continue;
			}
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
	 * Einen Auslöser ausführen, ohne die eigentliche Anfrage zu gefährden.
	 *
	 * Die Auslöser hängen an Kasse, Drucker-Bridge und Postfach. Ein Fehler
	 * beim Benachrichtigen darf dort nichts abbrechen.
	 *
	 * @param callable $fn Auslöser.
	 */
	protected static function safely( $fn ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			error_log( 'lw16 push: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * Bestellung zu einer Kennung.
	 *
	 * @param int $order_id Bestellung.
	 * @return \WC_Order|null
	 */
	protected static function order( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		return is_object( $order ) ? $order : null;
	}

	/**
	 * Betrag als Text.
	 *
	 * @param float $amount Betrag.
	 * @return string
	 */
	protected static function money( $amount ) {
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( html_entity_decode( wc_price( $amount ) ) );
		}
		return number_format_i18n( (float) $amount, 2 ) . ' €';
	}

	/**
	 * Neue Bestellung (klassischer Checkout).
	 *
	 * @param int $order_id Bestellung.
	 */
	public static function on_order( $order_id ) {
		$order = self::order( $order_id );
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
			sprintf( /* translators: 1: Summe, 2: Anzahl Positionen */ __( '%1$s, %2$d Positionen', 'lindenweg16' ), self::money( $order->get_total() ), (int) $order->get_item_count() ),
			'#/seite/shop-bestellungen'
		);
	}

	/**
	 * Zahlung zu einer wartenden Bestellung (Vorkasse: „In Wartestellung“ → „In Bearbeitung“).
	 *
	 * @param int $order_id Bestellung.
	 */
	public static function on_paid( $order_id ) {
		self::safely(
			static function () use ( $order_id ) {
				$order = self::order( $order_id );
				if ( ! $order ) {
					return;
				}
				self::notify(
					'zahlung',
					sprintf( /* translators: %s: Bestellnummer */ __( 'Zahlung eingegangen: #%s', 'lindenweg16' ), $order->get_order_number() ),
					trim( self::money( $order->get_total() ) . ', ' . $order->get_formatted_billing_full_name(), ', ' ),
					'#/seite/shop-bestellungen'
				);
			}
		);
	}

	/**
	 * Bestellung storniert. Wer selbst storniert, bekommt keine Meldung.
	 *
	 * @param int $order_id Bestellung.
	 */
	public static function on_cancelled( $order_id ) {
		self::safely(
			static function () use ( $order_id ) {
				$order = self::order( $order_id );
				if ( ! $order ) {
					return;
				}
				self::notify(
					'storno',
					sprintf( /* translators: %s: Bestellnummer */ __( 'Bestellung #%s storniert', 'lindenweg16' ), $order->get_order_number() ),
					trim( self::money( $order->get_total() ) . ', ' . $order->get_formatted_billing_full_name(), ', ' ),
					'#/seite/shop-bestellungen',
					0,
					get_current_user_id()
				);
			}
		);
	}

	/**
	 * Erstattung gebucht. Wer sie gebucht hat, bekommt keine Meldung.
	 *
	 * @param int $order_id  Bestellung.
	 * @param int $refund_id Erstattung.
	 */
	public static function on_refunded( $order_id, $refund_id ) {
		self::safely(
			static function () use ( $order_id, $refund_id ) {
				$order  = self::order( $order_id );
				$refund = self::order( $refund_id );
				if ( ! $order || ! $refund ) {
					return;
				}
				$reason = method_exists( $refund, 'get_reason' ) ? (string) $refund->get_reason() : '';
				self::notify(
					'erstattung',
					sprintf( /* translators: %s: Bestellnummer */ __( 'Erstattung zu #%s', 'lindenweg16' ), $order->get_order_number() ),
					trim( self::money( $refund->get_amount() ) . ( '' !== $reason ? ', ' . $reason : '' ) ),
					'#/seite/shop-bestellungen',
					0,
					get_current_user_id()
				);
			}
		);
	}

	/**
	 * Neue Produktbewertung (keine Antworten, kein Spam).
	 *
	 * @param int        $comment_id Kommentar.
	 * @param int|string $approved   1, 0 oder 'spam'.
	 * @param array      $data       Kommentardaten.
	 */
	public static function on_comment( $comment_id, $approved, $data ) {
		self::safely(
			static function () use ( $comment_id, $approved, $data ) {
				if ( 'spam' === $approved || 'trash' === $approved || ! is_array( $data ) || ! empty( $data['comment_parent'] ) ) {
					return;
				}
				$post_id = isset( $data['comment_post_ID'] ) ? (int) $data['comment_post_ID'] : 0;
				if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) {
					return;
				}
				$rating = (int) get_comment_meta( $comment_id, 'rating', true );
				$stars  = $rating >= 1 && $rating <= 5 ? str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) . ' ' : '';
				$author = isset( $data['comment_author'] ) ? (string) $data['comment_author'] : '';
				$text   = isset( $data['comment_content'] ) ? (string) $data['comment_content'] : '';
				self::notify(
					'bewertung',
					$stars . sprintf( /* translators: %s: Produkt */ __( 'Bewertung zu %s', 'lindenweg16' ), get_the_title( $post_id ) ),
					( 1 === (int) $approved ? '' : __( 'Wartet auf Freigabe. ', 'lindenweg16' ) ) . trim( $author . ': ' . $text, ': ' ),
					'#/seite/shop-produkte',
					0,
					get_current_user_id()
				);
			}
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

	/**
	 * Name eines Shop-Produkts oder Betriebsartikels.
	 *
	 * @param int $produkt_id Shop-Produkt (0 = keins).
	 * @param int $article_id Betriebsartikel (0 = keiner).
	 * @return string
	 */
	protected static function item_name( $produkt_id, $article_id ) {
		if ( $produkt_id && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $produkt_id );
			if ( $product ) {
				return $product->get_name();
			}
		}
		if ( $article_id && class_exists( '\Lindenweg16\Modules\Betrieb\ArticleRepository' ) ) {
			$article = \Lindenweg16\Modules\Betrieb\ArticleRepository::find( $article_id );
			if ( $article ) {
				return trim( $article['sku'] . ' – ' . $article['name'], ' –' );
			}
		}
		return '';
	}

	/**
	 * Shop-Produkt wieder auf Lager (Bestand von 0 aufgefüllt).
	 *
	 * @param int $produkt_id Produkt.
	 */
	public static function on_product_replenished( $produkt_id ) {
		self::safely(
			static function () use ( $produkt_id ) {
				$name = self::item_name( (int) $produkt_id, 0 );
				if ( '' !== $name ) {
					self::notify( 'wieder_da', __( 'Wieder auf Lager', 'lindenweg16' ), $name, '#/seite/lager' );
				}
			}
		);
	}

	/**
	 * Betriebsartikel wieder auf Lager (Bestand am Lagerort von 0 aufgefüllt).
	 *
	 * @param int $article_id Artikel.
	 */
	public static function on_article_replenished( $article_id ) {
		self::safely(
			static function () use ( $article_id ) {
				$name = self::item_name( 0, (int) $article_id );
				if ( '' !== $name ) {
					self::notify( 'wieder_da', __( 'Wieder auf Lager', 'lindenweg16' ), $name, '#/seite/lager' );
				}
			}
		);
	}

	/**
	 * Neue Anmeldung auf der Warteliste.
	 *
	 * @param int $produkt_id Shop-Produkt (0 = keins).
	 * @param int $article_id Betriebsartikel (0 = keiner).
	 */
	public static function on_waitlist( $produkt_id, $article_id ) {
		self::safely(
			static function () use ( $produkt_id, $article_id ) {
				$name = self::item_name( (int) $produkt_id, (int) $article_id );
				if ( '' !== $name ) {
					self::notify( 'warteliste', __( 'Neu auf der Warteliste', 'lindenweg16' ), $name, '#/seite/lager' );
				}
			}
		);
	}

	/**
	 * Neue Support-Anfrage.
	 *
	 * @param int    $ticket_id Ticket.
	 * @param string $betreff   Betreff.
	 */
	public static function on_ticket( $ticket_id, $betreff ) {
		self::safely(
			static function () use ( $ticket_id, $betreff ) {
				self::notify(
					'support',
					sprintf( /* translators: %d: Ticketnummer */ __( 'Neue Support-Anfrage #%d', 'lindenweg16' ), (int) $ticket_id ),
					(string) $betreff,
					'#/seite/support'
				);
			}
		);
	}

	/**
	 * Ereignisse aus dem Änderungsprotokoll: Drucker, Slicer, Etiketten,
	 * E-Rechnungen, zugewiesene Mails.
	 *
	 * @param array $data Eintrag (aktion, objekt_id, user_id, details als JSON).
	 */
	public static function on_audit( $data ) {
		self::safely(
			static function () use ( $data ) {
				self::from_audit( is_array( $data ) ? $data : array() );
			}
		);
	}

	/**
	 * Meldung zu einem Protokolleintrag.
	 *
	 * @param array $data Eintrag.
	 */
	protected static function from_audit( array $data ) {
		$aktion  = isset( $data['aktion'] ) ? (string) $data['aktion'] : '';
		$id      = isset( $data['objekt_id'] ) ? (int) $data['objekt_id'] : 0;
		$details = isset( $data['details'] ) && is_string( $data['details'] ) ? json_decode( $data['details'], true ) : array();
		$details = is_array( $details ) ? $details : array();
		$meldung = isset( $details['meldung'] ) ? (string) $details['meldung'] : '';

		switch ( $aktion ) {
			case 'bambu_druck_erfolgreich':
			case 'bambu_druck_fehlgeschlagen':
			case 'bambu_druck_unklar':
				$printer = isset( $details['drucker'] ) ? (string) $details['drucker'] : '';
				$file    = '';
				if ( class_exists( '\Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository' ) ) {
					$row     = \Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository::find_by_printer_id( $printer );
					$printer = $row && ! empty( $row->name ) ? (string) $row->name : $printer;
					$job     = \Lindenweg16\Modules\Betrieb\Bambu\BambuJobRepository::find( $id );
					$file    = $job && ! empty( $job->file_name ) ? (string) $job->file_name : '';
				}
				if ( 'bambu_druck_erfolgreich' === $aktion ) {
					/* translators: %s: Drucker */
					self::notify( 'druck_fertig', sprintf( __( 'Druck fertig auf %s', 'lindenweg16' ), $printer ), $file, '#/seite/druckfarm' );
				} else {
					$title = 'bambu_druck_unklar' === $aktion
						/* translators: %s: Drucker */
						? sprintf( __( 'Druck auf %s unklar – bitte klären', 'lindenweg16' ), $printer )
						/* translators: %s: Drucker */
						: sprintf( __( 'Druck auf %s fehlgeschlagen', 'lindenweg16' ), $printer );
					self::notify( 'druck_fehler', $title, trim( $file . ( '' !== $meldung ? ': ' . $meldung : '' ), ': ' ), '#/seite/druckfarm' );
				}
				break;

			case 'bambu_platte_slicen_fehlgeschlagen':
				/* translators: %d: Platte */
				self::notify( 'slicen_fehler', sprintf( __( 'Slicen von Platte %d fehlgeschlagen', 'lindenweg16' ), $id ), $meldung, '#/seite/workflow' );
				break;

			case 'dhl_druck_fehlgeschlagen':
				self::notify( 'etikett_fehler', __( 'Etikett nicht gedruckt', 'lindenweg16' ), $meldung, '#/seite/etiketten' );
				break;

			case 'erechnung_empfangen':
				$absender = isset( $details['absender'] ) ? (string) $details['absender'] : '';
				$betrag   = isset( $details['betrag'] ) && is_numeric( $details['betrag'] ) ? self::money( (float) $details['betrag'] ) : '';
				self::notify( 'erechnung', __( 'E-Rechnung eingegangen', 'lindenweg16' ), trim( $absender . ', ' . $betrag, ', ' ), '#/seite/buchhaltung' );
				break;

			case 'mail_zugewiesen':
				$an    = isset( $details['an'] ) ? (int) $details['an'] : 0;
				$actor = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
				if ( ! $an || $an === $actor || ! class_exists( '\Lindenweg16\Modules\Mailbox\MailRepository' ) ) {
					break;
				}
				$msg  = \Lindenweg16\Modules\Mailbox\MailRepository::get_message( $id );
				$from = $actor ? get_userdata( $actor ) : null;
				self::notify(
					'mail_zugewiesen',
					$from
						/* translators: %s: Name */
						? sprintf( __( '%s hat dir eine Mail zugewiesen', 'lindenweg16' ), $from->display_name )
						: __( 'Dir wurde eine Mail zugewiesen', 'lindenweg16' ),
					$msg ? trim( $msg['from_name'] . ': ' . $msg['subject'], ': ' ) : '',
					'#/seite/mailbox',
					$an
				);
				break;
		}
	}

	/* ====================================================================
	 * Morgendliche Prüfung
	 * ================================================================== */

	/**
	 * Was neu dazugekommen ist: Kennungen in $now, die nicht in $before stehen.
	 *
	 * @param array $now    Kennung => Beschriftung.
	 * @param array $before Kennungen vom letzten Mal.
	 * @return array Kennung => Beschriftung.
	 */
	public static function newly( array $now, array $before ) {
		return array_diff_key( $now, array_flip( array_map( 'strval', $before ) ) );
	}

	/**
	 * Hat irgendwer mit Gerät diese Art eingeschaltet? Erspart die teuren
	 * Abfragen, solange niemand die Meldung will.
	 *
	 * @param string $category Art.
	 * @return bool
	 */
	protected static function wanted( $category ) {
		global $wpdb;
		$users = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( self::META_PREFIX . 'dev_' ) . '%' ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		foreach ( (array) $users as $uid ) {
			$prefs = self::prefs( (int) $uid );
			if ( ! empty( $prefs[ $category ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Einmal am Morgen (stündlicher Lauf, ab 8 Uhr Ortszeit): neu fällige
	 * Wartungen, neu nachzubestellende Positionen und fällige Wiedervorlagen. Was gestern schon
	 * gemeldet wurde, kommt nicht noch einmal.
	 */
	public static function morning_check() {
		$today = current_time( 'Y-m-d' );
		if ( (int) current_time( 'G' ) < self::MORNING_HOUR ) {
			return;
		}
		$seen = get_option( self::OPT_MORNING );
		$seen = is_array( $seen ) ? $seen : array();
		if ( isset( $seen['tag'] ) && $seen['tag'] === $today ) {
			return;
		}
		// Vorher merken: Scheitert eine Prüfung, läuft sie nicht stündlich erneut.
		$seen['tag'] = $today;
		update_option( self::OPT_MORNING, $seen, false );

		self::safely(
			static function () use ( &$seen ) {
				if ( ! self::wanted( 'wartung' ) || ! class_exists( '\Lindenweg16\Modules\Betrieb\MaintenanceRepository' ) ) {
					return;
				}
				$due = array();
				foreach ( \Lindenweg16\Modules\Betrieb\MaintenanceRepository::due() as $row ) {
					if ( $row['reasons'] ) {
						$due[ (string) $row['printer']['id'] ] = $row['printer']['code'] . ': ' . $row['reasons'][0];
					}
				}
				$new             = self::newly( $due, isset( $seen['wartung'] ) ? (array) $seen['wartung'] : array() );
				$seen['wartung'] = array_map( 'strval', array_keys( $due ) );
				if ( $new ) {
					self::notify(
						'wartung',
						1 === count( $new ) ? __( 'Drucker-Wartung fällig', 'lindenweg16' ) : sprintf( /* translators: %d: Anzahl */ __( '%d Drucker brauchen Wartung', 'lindenweg16' ), count( $new ) ),
						implode( '; ', $new ),
						'#/seite/drucker'
					);
				}
			}
		);

		self::safely(
			static function () use ( &$seen ) {
				if ( ! self::wanted( 'nachbestellen' ) || ! class_exists( '\Lindenweg16\Modules\Betrieb\ProcurementAlert' ) ) {
					return;
				}
				$alerts = array();
				foreach ( \Lindenweg16\Modules\Betrieb\ProcurementAlert::all() as $a ) {
					// Filament hat keine eigene Kennung (id 0), die Farbe steht in der Beschriftung.
					$alerts[ $a['kind'] . ':' . $a['id'] . ':' . $a['label'] ] = $a['label'];
				}
				$new                   = self::newly( $alerts, isset( $seen['nachbestellen'] ) ? (array) $seen['nachbestellen'] : array() );
				$seen['nachbestellen'] = array_map( 'strval', array_keys( $alerts ) );
				if ( $new ) {
					self::notify(
						'nachbestellen',
						1 === count( $new ) ? __( 'Nachbestellen', 'lindenweg16' ) : sprintf( /* translators: %d: Anzahl */ __( '%d Positionen nachbestellen', 'lindenweg16' ), count( $new ) ),
						implode( ', ', $new ),
						'#/seite/einkauf'
					);
				}
			}
		);

		self::safely(
			static function () use ( &$seen ) {
				if ( ! self::wanted( 'nachfassen' ) || ! class_exists( '\Lindenweg16\Modules\Betrieb\ContactRepository' ) ) {
					return;
				}
				$due = array();
				foreach ( \Lindenweg16\Modules\Betrieb\ContactRepository::due_follow_ups() as $c ) {
					// Mit Datum: Wer nach dem Nachfassen einen neuen Termin bekommt, kommt dann wieder.
					$due[ $c['id'] . ':' . $c['follow_up_on'] ] = (string) $c['company'];
				}
				$new                = self::newly( $due, isset( $seen['nachfassen'] ) ? (array) $seen['nachfassen'] : array() );
				$seen['nachfassen'] = array_map( 'strval', array_keys( $due ) );
				if ( $new ) {
					self::notify(
						'nachfassen',
						1 === count( $new ) ? __( 'Heute nachfassen', 'lindenweg16' ) : sprintf( /* translators: %d: Anzahl */ __( '%d Kontakte heute nachfassen', 'lindenweg16' ), count( $new ) ),
						implode( ', ', $new ),
						1 === count( $new ) ? '#/kontakt/' . (int) strtok( (string) key( $new ), ':' ) : '#/seite/kontakte'
					);
				}
			}
		);

		update_option( self::OPT_MORNING, $seen, false );
	}
}
