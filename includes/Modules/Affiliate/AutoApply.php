<?php
/**
 * Affiliates: Link mit Code, der den Rabatt von selbst in den Warenkorb legt.
 *
 * Ein Link wie `lindenweg16.de/?aff=ANNA10` merkt sich den Code in einem Cookie
 * (Laufzeit wie beim Empfehlungs-Cookie) und legt ihn in den Warenkorb, sobald dort etwas
 * liegt. Wer den Code im Warenkorb wieder entfernt, bekommt ihn in dieser Sitzung nicht
 * noch einmal. Das Cookie entsteht nur, wenn jemand den Link anklickt, und enthält nichts
 * als den Code.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Affiliate;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Settings;

/**
 * Cookie setzen und Gutschein anwenden.
 */
class AutoApply {

	const PARAM  = 'aff';
	const COOKIE = 'lw16_aff';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'wp_loaded', array( $this, 'capture' ) );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply' ), 5 );
		add_action( 'woocommerce_removed_coupon', array( $this, 'removed' ) );
	}

	/**
	 * Die Adresse zum Teilen.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function link( $code ) {
		return add_query_arg( self::PARAM, rawurlencode( $code ), home_url( '/' ) );
	}

	/**
	 * Code aus dem Link in ein Cookie übernehmen (nur für aktive Affiliates).
	 */
	public function capture() {
		if ( empty( $_GET[ self::PARAM ] ) || headers_sent() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$code = Rules::normalize_code( sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$p    = Rules::is_valid_code( $code ) ? Partners::by_code( $code ) : null;

		if ( ! $p || ! $p['aktiv'] ) {
			return;
		}

		$days = max( 1, (int) Settings::get( 'referral_cookie_tage', 30 ) );

		setcookie( self::COOKIE, $p['code'], time() + $days * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		$_COOKIE[ self::COOKIE ] = $p['code'];

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'lw16_aff_removed', null );
		}
	}

	/**
	 * Merkt: Der Kunde hat den Code selbst entfernt.
	 *
	 * @param string $code Entfernter Gutscheincode.
	 */
	public function removed( $code ) {
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';

		if ( '' !== $cookie && 0 === strcasecmp( (string) $code, $cookie ) && function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'lw16_aff_removed', strtolower( $cookie ) );
		}
	}

	/**
	 * Den gemerkten Code in den Warenkorb legen, wenn er gilt.
	 *
	 * @param \WC_Cart $cart Warenkorb.
	 */
	public function apply( $cart ) {
		static $busy = false;

		if ( $busy || ! $cart instanceof \WC_Cart || $cart->is_empty() || empty( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}

		$code = Rules::normalize_code( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
		$p    = Rules::is_valid_code( $code ) ? Partners::by_code( $code ) : null;

		if ( ! $p || ! $p['aktiv'] || $cart->has_discount( $p['code'] ) ) {
			return;
		}

		if ( function_exists( 'WC' ) && WC()->session && WC()->session->get( 'lw16_aff_removed' ) === strtolower( $p['code'] ) ) {
			return;
		}

		$busy   = true;
		$coupon = new \WC_Coupon( $p['code'] );
		$check  = $coupon->get_id() ? ( new \WC_Discounts( $cart ) )->is_coupon_valid( $coupon ) : false;

		if ( true === $check ) {
			$cart->set_applied_coupons( array_merge( $cart->get_applied_coupons(), array( wc_format_coupon_code( $p['code'] ) ) ) );
			/* translators: %s: Code. */
			wc_add_notice( sprintf( __( 'Dein Rabattcode %s wurde angewendet.', 'lindenweg16' ), $p['code'] ), 'success' );
		}

		$busy = false;
	}
}
