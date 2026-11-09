<?php
/**
 * Persönlicher Bewertungslink für Käufer ohne Kundenkonto.
 *
 * Der Shop verlangt für Bewertungen einen Kauf („nur verifizierte Besitzer“).
 * WooCommerce prüft das für die Anzeige des Formulars am angemeldeten Konto,
 * und die meisten Kunden bestellen als Gast. Die Bewertungsanfrage-Mail hätte
 * sie so auf eine Seite geschickt, auf der sie nicht bewerten dürfen.
 *
 * Die Mail trägt deshalb je Produkt einen signierten Link
 * `?lw16_bewerten=<bestellung>.<signatur>`. Nur auf diesem Aufruf und nur für
 * ein Produkt dieser Bestellung zeigt die Produktseite das Bewertungsformular,
 * vorbelegt mit Name und E-Mail aus der Bestellung. Die Prüfung beim Absenden
 * bleibt unverändert streng: WooCommerce nimmt die Bewertung nur an, wenn die
 * E-Mail-Adresse das Produkt gekauft hat.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * Bewertungslink mit Signatur.
 */
class ReviewLink {

	/**
	 * Abfrageparameter.
	 */
	const PARAM = 'lw16_bewerten';

	/**
	 * Gültige Bestellung dieses Aufrufs, sobald geprüft.
	 *
	 * @var \WC_Order|null
	 */
	protected $order = null;

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'pruefen' ), 5 );
	}

	/**
	 * Signatur zu Bestellung und E-Mail - reine Regel bis auf den Schlüssel.
	 *
	 * @param int    $order_id Bestellung.
	 * @param string $email    Rechnungs-E-Mail.
	 * @param string $key      Geheimer Schlüssel.
	 * @return string 20 Zeichen.
	 */
	public static function signatur( $order_id, $email, $key ) {
		return substr( hash_hmac( 'sha256', (int) $order_id . '|' . strtolower( trim( (string) $email ) ), (string) $key ), 0, 20 );
	}

	/**
	 * Wert des Parameters zerlegen - reine Regel.
	 *
	 * @param mixed $wert Eingabe.
	 * @return array|null {order_id, sig} oder null.
	 */
	public static function zerlegen( $wert ) {
		if ( ! is_string( $wert ) || ! preg_match( '/^(\d{1,12})\.([a-f0-9]{20})$/', $wert, $m ) ) {
			return null;
		}

		return array(
			'order_id' => (int) $m[1],
			'sig'      => $m[2],
		);
	}

	/**
	 * Vorname und Initial des Nachnamens, z. B. „Sven M.“ - reine Regel.
	 *
	 * @param string $vorname  Vorname.
	 * @param string $nachname Nachname.
	 * @return string
	 */
	public static function anzeigename( $vorname, $nachname ) {
		$vorname  = trim( (string) $vorname );
		$nachname = trim( (string) $nachname );

		if ( '' === $nachname ) {
			return $vorname;
		}

		return trim( $vorname . ' ' . mb_substr( $nachname, 0, 1 ) . '.' );
	}

	/**
	 * Geheimer Schlüssel der Installation.
	 *
	 * @return string
	 */
	protected static function schluessel() {
		return wp_salt( 'auth' ) . 'lw16_bewerten';
	}

	/**
	 * Link zur Bewertung eines Produkts aus einer Bestellung.
	 *
	 * @param \WC_Order $order      Bestellung.
	 * @param int       $product_id Produkt.
	 * @return string Leer, wenn das Produkt keine Seite hat.
	 */
	public static function url( $order, $product_id ) {
		$permalink = get_permalink( (int) $product_id );

		if ( ! $permalink ) {
			return '';
		}

		$wert = $order->get_id() . '.' . self::signatur( $order->get_id(), $order->get_billing_email(), self::schluessel() );

		return add_query_arg( self::PARAM, $wert, $permalink ) . '#reviews';
	}

	/**
	 * Aufruf mit Link: Signatur, Bestellung und Produkt prüfen.
	 */
	public function pruefen() {
		// phpcs:ignore WordPress.Security.NonceVerification -- Signierter Link aus einer Mail, kein Formular.
		$teile = isset( $_GET[ self::PARAM ] ) ? self::zerlegen( sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) ) : null;

		if ( null === $teile || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		// Persönliche Seite: nie aus dem oder in den Seiten-Cache.
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'lw16 Bewertungslink' );

		$order = wc_get_order( $teile['order_id'] );

		if ( ! $order || ! hash_equals( self::signatur( $order->get_id(), $order->get_billing_email(), self::schluessel() ), $teile['sig'] ) ) {
			return;
		}

		$product_id = (int) get_queried_object_id();
		$gekauft    = false;

		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() === $product_id ) {
				$gekauft = true;
				break;
			}
		}

		if ( ! $gekauft ) {
			return;
		}

		$this->order = $order;

		// Formular zeigen: Die Kaufprüfung entfällt nur für die Anzeige auf
		// dieser Seite. Beim Absenden (wp-comments-post.php) gilt wieder die
		// Einstellung, und WooCommerce prüft die E-Mail gegen die Käufe.
		add_filter( 'pre_option_woocommerce_review_rating_verification_required', array( $this, 'formular_zeigen' ) );
		add_filter( 'wp_get_current_commenter', array( $this, 'vorbelegen' ) );
	}

	/**
	 * Kaufprüfung für die Anzeige des Formulars aus.
	 *
	 * @return string
	 */
	public function formular_zeigen() {
		return 'no';
	}

	/**
	 * Name und E-Mail aus der Bestellung vorbelegen.
	 *
	 * @param array $commenter Bisher.
	 * @return array
	 */
	public function vorbelegen( $commenter ) {
		if ( ! $this->order ) {
			return $commenter;
		}

		if ( empty( $commenter['comment_author'] ) ) {
			$commenter['comment_author'] = self::anzeigename( $this->order->get_billing_first_name(), $this->order->get_billing_last_name() );
		}
		if ( empty( $commenter['comment_author_email'] ) ) {
			$commenter['comment_author_email'] = $this->order->get_billing_email();
		}

		return $commenter;
	}
}
