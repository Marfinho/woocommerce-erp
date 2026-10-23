<?php
/**
 * Versand: Gutscheincode anlegen und dem Beschenkten schicken.
 *
 * Ausgelöst wird nach der Zahlung (Status „in Bearbeitung“ oder
 * „Abgeschlossen“). Jede Position wird nur einmal versendet; im
 * Bestell-Backend gibt es die Aktion „Gutschein erneut senden“.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Gutschein;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\MailTemplate;
use Lindenweg16\Core\Settings;

/**
 * Legt WooCommerce-Gutscheine an und verschickt sie.
 */
class Versand {

	const AKTION = 'lw16_gutschein_senden';

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'woocommerce_order_status_processing', array( $this, 'senden' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'senden' ), 20 );
		add_filter( 'woocommerce_order_actions', array( $this, 'order_aktion' ) );
		add_action( 'woocommerce_order_action_' . self::AKTION, array( $this, 'erneut_senden' ) );
	}

	/**
	 * Aktion im Bestell-Backend anbieten.
	 *
	 * @param array $aktionen Bisherige Aktionen.
	 * @return array
	 */
	public function order_aktion( $aktionen ) {
		$aktionen[ self::AKTION ] = __( 'Gutschein erneut senden', 'lindenweg16' );

		return $aktionen;
	}

	/**
	 * Aktion „erneut senden“.
	 *
	 * @param \WC_Order $order Bestellung.
	 */
	public function erneut_senden( $order ) {
		$this->senden( $order->get_id(), true );
	}

	/**
	 * Alle Gutschein-Positionen einer Bestellung versenden.
	 *
	 * @param int  $order_id Bestellung.
	 * @param bool $erneut   Auch schon versendete noch einmal schicken.
	 */
	public function senden( $order_id, $erneut = false ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$g = $item->get_meta( '_' . Produkt::KEY );

			if ( ! is_array( $g ) || empty( $g['vorlage'] ) ) {
				continue;
			}
			if ( $item->get_meta( '_lw16_gutschein_versendet' ) && ! $erneut ) {
				continue;
			}

			$code = (string) $item->get_meta( '_lw16_gutschein_code' );
			if ( '' === $code ) {
				$code = $this->anlegen( $order, $item );
			}
			if ( '' === $code ) {
				$order->add_order_note( __( 'Gutschein konnte nicht angelegt werden.', 'lindenweg16' ) );
				continue;
			}

			$ok = $this->mailen( $order, $item, $g, $code );

			if ( $ok ) {
				$item->update_meta_data( '_lw16_gutschein_versendet', time() );
				$item->save();
				$order->add_order_note(
					sprintf(
						/* translators: 1: Code, 2: Empfänger. */
						__( 'Gutschein %1$s an %2$s gesendet.', 'lindenweg16' ),
						$code,
						$this->empfaenger( $order, $g )
					)
				);
			} else {
				$order->add_order_note( sprintf( /* translators: %s: Code. */ __( 'Gutschein %s konnte nicht per E-Mail gesendet werden.', 'lindenweg16' ), $code ) );
			}
		}
	}

	/**
	 * Betrag der Position, brutto.
	 *
	 * @param \WC_Order_Item_Product $item Position.
	 * @return float
	 */
	protected function betrag( $item ) {
		return round( (float) $item->get_total() + (float) $item->get_total_tax(), wc_get_price_decimals() );
	}

	/**
	 * Letzter Tag der Gültigkeit: Ende des dritten Jahres nach dem Kauf.
	 *
	 * @return int Zeitstempel.
	 */
	protected function gueltig_bis() {
		return (int) strtotime( ( (int) gmdate( 'Y' ) + 3 ) . '-12-31 23:59:59 UTC' );
	}

	/**
	 * WooCommerce-Gutschein in Höhe des bezahlten Betrags anlegen.
	 *
	 * @param \WC_Order              $order Bestellung.
	 * @param \WC_Order_Item_Product $item  Position.
	 * @return string Code oder leer.
	 */
	protected function anlegen( $order, $item ) {
		$betrag = $this->betrag( $item );

		if ( $betrag <= 0 ) {
			return '';
		}

		do {
			$code = 'LW16-' . strtoupper( wp_generate_password( 4, false ) ) . '-' . strtoupper( wp_generate_password( 4, false ) );
		} while ( wc_get_coupon_id_by_code( $code ) );

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( $betrag );
		$coupon->set_usage_limit( 1 );
		$coupon->set_date_expires( $this->gueltig_bis() );
		// Mit einem Gutschein keinen Gutschein kaufen.
		$coupon->set_excluded_product_ids( array( $item->get_product_id() ) );
		/* translators: %d: Bestellnummer. */
		$coupon->set_description( sprintf( __( 'Geschenkgutschein aus Bestellung %d', 'lindenweg16' ), $order->get_id() ) );
		$coupon->save();

		$item->update_meta_data( '_lw16_gutschein_code', $code );
		$item->save();

		return $code;
	}

	/**
	 * Empfängeradresse: die des Beschenkten, sonst die des Bestellers.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @param array     $g     Eingaben.
	 * @return string
	 */
	protected function empfaenger( $order, array $g ) {
		return ! empty( $g['email'] ) ? $g['email'] : $order->get_billing_email();
	}

	/**
	 * Mail an den Beschenkten, bei fremder Adresse eine Kopie an den Besteller.
	 *
	 * @param \WC_Order              $order Bestellung.
	 * @param \WC_Order_Item_Product $item  Position.
	 * @param array                  $g     Eingaben.
	 * @param string                 $code  Gutscheincode.
	 * @return bool
	 */
	protected function mailen( $order, $item, array $g, $code ) {
		$betrag = trim( html_entity_decode( wp_strip_all_tags( wc_price( $this->betrag( $item ), array( 'currency' => $order->get_currency() ) ) ) ) );
		$bis    = wp_date( 'd.m.Y', $this->gueltig_bis() );
		$an     = $this->empfaenger( $order, $g );
		$kopie  = $order->get_billing_email();
		$ok     = false;

		if ( Vorlagen::NUR_MAIL === $g['vorlage'] ) {
			$platz = array(
				'{von}'         => $g['von'],
				'{an}'          => $g['an'],
				'{text}'        => '' !== $g['text'] ? $g['text'] : '–',
				'{code}'        => $code,
				'{betrag}'      => $betrag,
				'{gueltig_bis}' => $bis,
			);
			$ok = MailTemplate::send( $an, 'gutschein_mail', $platz );

			if ( $ok && $kopie && strtolower( $kopie ) !== strtolower( $an ) ) {
				MailTemplate::send( $kopie, 'gutschein_mail', $platz );
			}

			return $ok;
		}

		$karte   = Vorlagen::render(
			$g['vorlage'],
			array(
				'von'         => $g['von'],
				'an'          => $g['an'],
				'text'        => $g['text'],
				'betrag'      => $betrag,
				'code'        => $code,
				'gueltig_bis' => $bis,
			)
		);
		/* translators: %s: Name des Schenkenden. */
		$betreff = sprintf( __( '%s hat Dir einen Gutschein geschenkt', 'lindenweg16' ), $g['von'] );
		$html    = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#f4f2ee">' . $karte . '</body></html>';

		$ok = $this->wp_mail( $an, $betreff, $html );

		if ( $ok && $kopie && strtolower( $kopie ) !== strtolower( $an ) ) {
			/* translators: %s: Betreff. */
			$this->wp_mail( $kopie, sprintf( __( 'Kopie: %s', 'lindenweg16' ), $betreff ), $html );
		}

		return $ok;
	}

	/**
	 * HTML-Mail im Absender des Shops.
	 *
	 * @param string $an      Empfänger.
	 * @param string $betreff Betreff.
	 * @param string $html    Inhalt.
	 * @return bool
	 */
	protected function wp_mail( $an, $betreff, $html ) {
		$absender = Settings::get( 'email_absender_name', get_bloginfo( 'name' ) );
		$von      = Settings::get( 'firma_email', get_bloginfo( 'admin_email' ) );

		return wp_mail(
			$an,
			$betreff,
			$html,
			array(
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . $absender . ' <' . $von . '>',
			)
		);
	}
}
