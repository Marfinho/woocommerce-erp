<?php
/**
 * Affiliates: Eigenkäufe mit dem eigenen Code verhindern.
 *
 * Drei Schichten, von früh nach spät:
 *  1. Der Gutschein gilt nicht für das Konto oder die Adressen des Affiliates
 *     (Warenkorb, sobald der Kunde angemeldet ist oder seine Rechnungsadresse kennt).
 *  2. Der klassische Checkout bricht mit einem Hinweis ab, wenn die Rechnungsadresse
 *     dem Affiliate gehört.
 *  3. Was trotzdem durchrutscht (etwa im Block-Checkout oder mit einer anderen Adresse
 *     desselben Kontos), zählt nicht für die Provision: siehe Sales::collect().
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Affiliate;

defined( 'ABSPATH' ) || exit;

/**
 * Prüfungen im Warenkorb und an der Kasse.
 */
class Guard {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'coupon_valid' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'checkout' ), 10, 2 );
	}

	/**
	 * Der Hinweis an den Kunden.
	 *
	 * @return string
	 */
	public static function message() {
		return __( 'Den eigenen Ambassador-Code kannst du nicht selbst einlösen.', 'lindenweg16' );
	}

	/**
	 * Gehört der Gutschein einem Affiliate? Wenn ja, welchem.
	 *
	 * @param \WC_Coupon $coupon Gutschein.
	 * @return array|null
	 */
	protected function partner_of( $coupon ) {
		$id = (int) $coupon->get_meta( Partners::META_COUPON );

		return $id > 0 ? Partners::get( $id ) : null;
	}

	/**
	 * Gilt der Gutschein auch für diesen Kunden?
	 *
	 * @param bool       $valid  Bisheriges Ergebnis.
	 * @param \WC_Coupon $coupon Gutschein.
	 * @return bool
	 * @throws \Exception Mit dem Hinweis, damit WooCommerce ihn anzeigt.
	 */
	public function coupon_valid( $valid, $coupon ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon ) {
			return $valid;
		}

		$p = $this->partner_of( $coupon );
		if ( ! $p ) {
			return $valid;
		}

		$emails = array();
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$emails[] = WC()->customer->get_billing_email();
		}
		$me = wp_get_current_user();
		if ( $me && $me->exists() ) {
			$emails[] = $me->user_email;
		}

		if ( Rules::is_self_purchase( Partners::own_emails( $p ), (int) $p['user_id'], $emails, get_current_user_id() ) ) {
			throw new \Exception( esc_html( self::message() ), 100 );
		}

		return $valid;
	}

	/**
	 * Klassischer Checkout: Rechnungsadresse des Affiliates mit seinem Code.
	 *
	 * @param array     $data   Eingaben.
	 * @param \WP_Error $errors Fehler.
	 */
	public function checkout( $data, $errors ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$email = isset( $data['billing_email'] ) ? (string) $data['billing_email'] : '';

		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$coupon = new \WC_Coupon( $code );
			$p      = $this->partner_of( $coupon );

			if ( $p && Rules::is_self_purchase( Partners::own_emails( $p ), (int) $p['user_id'], array( $email ), get_current_user_id() ) ) {
				$errors->add( 'lw16_affiliate_self', self::message() );
				return;
			}
		}
	}
}
