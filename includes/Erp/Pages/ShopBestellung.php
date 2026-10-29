<?php
/**
 * ERP-Seite „Shop-Bestellung“: ansehen, Status ändern, Notiz anlegen, erstatten.
 *
 * Geht über die WooCommerce-Funktionen selbst (`update_status`,
 * `add_order_note`, `wc_create_refund`), damit Rechnung, Lagerrückbuchung und
 * die Erstattung beim Zahlungsanbieter wie sonst auch laufen. Rechte:
 * Ansehen `verkauf.shop_orders` (mit WordPress-Rolle „Shop-Manager“ wie die
 * Liste), Status und Notizen `verkauf.shop_orders.status`, Geld zurückgeben
 * `verkauf.shop_orders.erstatten`. Jede Änderung steht im Audit-Log und als
 * Notiz an der Bestellung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp\Pages;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\AuditLog;
use Lindenweg16\Core\Lock;
use Lindenweg16\Core\Log;
use Lindenweg16\Erp\RestApi;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Bestellansicht und Erstattung.
 */
class ShopBestellung {

	/**
	 * Routen anmelden.
	 *
	 * @param RestApi $api Schnittstelle.
	 */
	public static function register( RestApi $api ) {
		$ns = RestApi::NAMESPACE_V1;

		$gate = function ( array $perms ) use ( $api ) {
			return function () use ( $api, $perms ) {
				$perm = $api->needs( array_merge( array( 'verkauf.shop_orders' ), $perms ) );

				if ( ! $perm() ) {
					return false;
				}

				if ( ! current_user_can( 'edit_shop_orders' ) ) {
					return new WP_Error( 'lw16_erp_wp_role', __( 'Dafür braucht dein WordPress-Konto die Rolle „Shop-Manager“.', 'lindenweg16' ), array( 'status' => 403 ) );
				}

				return true;
			};
		};

		register_rest_route( $ns, '/erp/shop-bestellung', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get' ),
			'permission_callback' => $gate( array() ),
		) );
		register_rest_route( $ns, '/erp/shop-bestellung/status', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'status' ),
			'permission_callback' => $gate( array( 'verkauf.shop_orders.status' ) ),
		) );
		register_rest_route( $ns, '/erp/shop-bestellung/notiz', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'notiz' ),
			'permission_callback' => $gate( array( 'verkauf.shop_orders.status' ) ),
		) );
		register_rest_route( $ns, '/erp/shop-bestellung/erstattung', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'erstattung' ),
			'permission_callback' => $gate( array( 'verkauf.shop_orders.erstatten' ) ),
		) );
	}

	/**
	 * Fehlerantwort.
	 *
	 * @param string $message Text.
	 * @param int    $status  HTTP-Status.
	 * @return WP_REST_Response
	 */
	protected static function fail( $message, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), $status );
	}

	/**
	 * Bestellung holen – nur echte Shop-Bestellungen, keine Erstattungen.
	 *
	 * @param int $id Bestellung.
	 * @return \WC_Order|null
	 */
	protected static function order( $id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( (int) $id );

		return $order && 'shop_order' === $order->get_type() ? $order : null;
	}

	/**
	 * Zuordnung der Status, die man hier setzen darf.
	 *
	 * „Erstattet“ nicht: Der Status entsteht durch eine Erstattung, nicht
	 * durch Umstellen – sonst steht „erstattet“, aber das Geld ist nicht zurück.
	 *
	 * @return array Schlüssel (ohne wc-) => Bezeichnung.
	 */
	public static function settable_statuses() {
		$out = array();

		foreach ( wc_get_order_statuses() as $key => $label ) {
			$slug = preg_replace( '/^wc-/', '', $key );

			if ( 'refunded' !== $slug ) {
				$out[ $slug ] = $label;
			}
		}

		return $out;
	}

	/**
	 * Adresse als Zeilen.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @param string    $type  billing oder shipping.
	 * @return array
	 */
	protected static function address( $order, $type ) {
		$get = 'get_' . $type . '_';
		$name = trim( $order->{ $get . 'first_name' }() . ' ' . $order->{ $get . 'last_name' }() );
		$line = array_filter( array(
			$name,
			$order->{ $get . 'company' }(),
			$order->{ $get . 'address_1' }(),
			$order->{ $get . 'address_2' }(),
			trim( $order->{ $get . 'postcode' }() . ' ' . $order->{ $get . 'city' }() ),
			$order->{ $get . 'country' }(),
		), 'strlen' );

		return array_values( $line );
	}

	/**
	 * Bestellung samt Positionen, Erstattungen und Notizen.
	 *
	 * @param WP_REST_Request $request Anfrage (id).
	 * @return WP_REST_Response
	 */
	public static function get( WP_REST_Request $request ) {
		$order = self::order( $request->get_param( 'id' ) );

		if ( ! $order ) {
			return self::fail( __( 'Diese Bestellung gibt es nicht.', 'lindenweg16' ), 404 );
		}

		return new WP_REST_Response( self::payload( $order ) );
	}

	/**
	 * Daten für die Oberfläche.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return array
	 */
	public static function payload( $order ) {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			$qty      = (int) $item->get_quantity();
			$refunded = abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
			$gross    = (float) $item->get_total() + (float) $item->get_total_tax();
			$product  = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;

			$items[] = array(
				'id'       => (int) $item->get_id(),
				'name'     => (string) $item->get_name(),
				'sku'      => $product ? (string) $product->get_sku() : '',
				'qty'      => $qty,
				'refunded' => $refunded,
				'open'     => max( 0, $qty - $refunded ),
				'gross'    => round( $gross, 2 ),
				'unit'     => $qty ? round( $gross / $qty, 2 ) : 0.0,
			);
		}

		$shipping = array();

		foreach ( $order->get_items( 'shipping' ) as $line ) {
			$gross    = (float) $line->get_total() + (float) $line->get_total_tax();
			$done     = abs( (float) $order->get_total_refunded_for_item( $line->get_id(), 'shipping' ) )
				+ abs( (float) self::tax_refunded( $order, $line ) );
			$shipping[] = array(
				'id'    => (int) $line->get_id(),
				'name'  => (string) $line->get_name(),
				'gross' => round( $gross, 2 ),
				'open'  => max( 0.0, round( $gross - $done, 2 ) ),
			);
		}

		$refunds = array();

		foreach ( $order->get_refunds() as $refund ) {
			$by = $refund->get_refunded_by() ? get_userdata( $refund->get_refunded_by() ) : null;

			$refunds[] = array(
				'id'     => (int) $refund->get_id(),
				'ts'     => $refund->get_date_created() ? $refund->get_date_created()->getTimestamp() : 0,
				'amount' => (float) $refund->get_amount(),
				'reason' => (string) $refund->get_reason(),
				'by'     => $by ? $by->display_name : '',
			);
		}

		$notes = array();

		foreach ( (array) wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			$notes[] = array(
				'id'       => (int) $note->id,
				'ts'       => $note->date_created ? $note->date_created->getTimestamp() : 0,
				'text'     => wp_strip_all_tags( (string) $note->content ),
				'customer' => (bool) $note->customer_note,
				'by'       => (string) $note->added_by,
			);
		}

		$total    = (float) $order->get_total();
		$refunded = (float) $order->get_total_refunded();

		return array(
			'id'          => (int) $order->get_id(),
			'number'      => (string) $order->get_order_number(),
			'ts'          => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
			'status'      => $order->get_status(),
			'label'       => wc_get_order_status_name( $order->get_status() ),
			'currency'    => (string) $order->get_currency(),
			'method'      => (string) $order->get_payment_method_title(),
			'paid'        => $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : 0,
			'transaction' => (string) $order->get_transaction_id(),
			'email'       => (string) $order->get_billing_email(),
			'phone'       => (string) $order->get_billing_phone(),
			'billing'     => self::address( $order, 'billing' ),
			'shipping_to' => self::address( $order, 'shipping' ),
			'customer_note' => (string) $order->get_customer_note(),
			'items'       => $items,
			'shipping'    => $shipping,
			'total'       => round( $total, 2 ),
			'tax'         => round( (float) $order->get_total_tax(), 2 ),
			'refunded'    => round( $refunded, 2 ),
			'refundable'  => max( 0.0, round( $total - $refunded, 2 ) ),
			'refunds'     => $refunds,
			'notes'       => $notes,
			'statuses'    => self::settable_statuses(),
			'can'         => array(
				'status'   => \Lindenweg16\Erp\Access::can( 'verkauf.shop_orders.status' ),
				'refund'   => \Lindenweg16\Erp\Access::can( 'verkauf.shop_orders.erstatten' ),
			),
		);
	}

	/**
	 * Bereits erstattete Steuer einer Versandzeile.
	 *
	 * @param \WC_Order           $order Bestellung.
	 * @param \WC_Order_Item_Shipping $line  Versandzeile.
	 * @return float
	 */
	protected static function tax_refunded( $order, $line ) {
		$sum = 0.0;

		foreach ( array_keys( (array) $line->get_taxes()['total'] ) as $rate_id ) {
			$sum += abs( (float) $order->get_tax_refunded_for_item( $line->get_id(), $rate_id, 'shipping' ) );
		}

		return $sum;
	}

	/**
	 * Status ändern.
	 *
	 * @param WP_REST_Request $request Anfrage (id, status).
	 * @return WP_REST_Response
	 */
	public static function status( WP_REST_Request $request ) {
		$body  = (array) $request->get_json_params();
		$order = self::order( $body['id'] ?? 0 );

		if ( ! $order ) {
			return self::fail( __( 'Diese Bestellung gibt es nicht.', 'lindenweg16' ), 404 );
		}

		$new      = sanitize_key( (string) ( $body['status'] ?? '' ) );
		$settable = self::settable_statuses();

		if ( ! isset( $settable[ $new ] ) ) {
			return self::fail( __( 'Diesen Status kann man hier nicht setzen. „Erstattet“ entsteht durch eine Erstattung.', 'lindenweg16' ) );
		}

		if ( $order->get_status() === $new ) {
			return self::fail( __( 'Die Bestellung hat diesen Status schon.', 'lindenweg16' ) );
		}

		$old  = $order->get_status();
		$user = wp_get_current_user();
		$order->update_status( $new, sprintf( /* translators: %s: Name. */ __( 'Status im ERP geändert von %s:', 'lindenweg16' ), $user->display_name ) );

		AuditLog::record( 'shop_bestellung_status', 'shop_order', $order->get_id(), array( 'von' => $old, 'nach' => $new ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => __( 'Status geändert.', 'lindenweg16' ), 'order' => self::payload( self::order( $order->get_id() ) ) ) );
	}

	/**
	 * Notiz anlegen, wahlweise an den Kunden.
	 *
	 * @param WP_REST_Request $request Anfrage (id, text, kunde).
	 * @return WP_REST_Response
	 */
	public static function notiz( WP_REST_Request $request ) {
		$body  = (array) $request->get_json_params();
		$order = self::order( $body['id'] ?? 0 );

		if ( ! $order ) {
			return self::fail( __( 'Diese Bestellung gibt es nicht.', 'lindenweg16' ), 404 );
		}

		$text = sanitize_textarea_field( (string) ( $body['text'] ?? '' ) );

		if ( '' === $text ) {
			return self::fail( __( 'Bitte einen Text eintragen.', 'lindenweg16' ) );
		}

		// Eine Kundennotiz verschickt WooCommerce als E-Mail.
		$customer = ! empty( $body['kunde'] );
		$order->add_order_note( $text, $customer ? 1 : 0, true );

		AuditLog::record( 'shop_bestellung_notiz', 'shop_order', $order->get_id(), array( 'kunde' => $customer ) );

		return new WP_REST_Response( array( 'ok' => true, 'message' => $customer ? __( 'Notiz gespeichert und an den Kunden geschickt.', 'lindenweg16' ) : __( 'Notiz gespeichert.', 'lindenweg16' ), 'order' => self::payload( self::order( $order->get_id() ) ) ) );
	}

	/**
	 * Betrag und WooCommerce-Zeilen einer Erstattung berechnen und prüfen.
	 *
	 * Reine Rechenlogik ohne Schreiben: zeilenweise erstatten (Stück oder
	 * Versand) und/oder einen freien Betrag. Jede Zeile wird gegen das
	 * geprüft, was noch nicht erstattet ist.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @param array     $lines Zeilen: id, qty (Positionen), oder id, versand=true.
	 * @param float     $free  Freier Betrag brutto.
	 * @return array {ok, message, amount, line_items}
	 */
	public static function plan_refund( $order, array $lines, $free ) {
		$line_items = array();
		$amount     = 0.0;

		foreach ( $lines as $line ) {
			$id = (int) ( $line['id'] ?? 0 );

			if ( ! $id ) {
				continue;
			}

			$item = $order->get_item( $id );

			if ( ! $item ) {
				return array( 'ok' => false, 'message' => __( 'Eine Position gehört nicht zu dieser Bestellung.', 'lindenweg16' ) );
			}

			if ( $item instanceof \WC_Order_Item_Shipping ) {
				if ( empty( $line['versand'] ) ) {
					continue;
				}

				$total = (float) $item->get_total() - abs( (float) $order->get_total_refunded_for_item( $id, 'shipping' ) );
				$taxes = array();
				$gross = $total;

				foreach ( (array) $item->get_taxes()['total'] as $rate_id => $tax ) {
					$left              = (float) $tax - abs( (float) $order->get_tax_refunded_for_item( $id, $rate_id, 'shipping' ) );
					$taxes[ $rate_id ] = max( 0.0, round( $left, wc_get_price_decimals() ) );
					$gross            += $taxes[ $rate_id ];
				}

				if ( $total <= 0 && $gross <= 0 ) {
					return array( 'ok' => false, 'message' => __( 'Der Versand ist schon erstattet.', 'lindenweg16' ) );
				}

				$line_items[ $id ] = array( 'qty' => 0, 'refund_total' => max( 0.0, round( $total, wc_get_price_decimals() ) ), 'refund_tax' => $taxes );
				$amount           += $gross;
				continue;
			}

			$qty  = (int) ( $line['qty'] ?? 0 );
			$rest = (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $id ) );

			if ( $qty <= 0 ) {
				continue;
			}

			if ( $qty > $rest ) {
				return array( 'ok' => false, 'message' => sprintf( /* translators: %s: Artikel. */ __( 'Von „%s“ ist weniger übrig, als erstattet werden soll.', 'lindenweg16' ), $item->get_name() ) );
			}

			$dec        = wc_get_price_decimals();
			$total_left = (float) $item->get_total() - abs( (float) $order->get_total_refunded_for_item( $id ) );
			$total      = $qty === $rest ? $total_left : round( (float) $item->get_total() / max( 1, (int) $item->get_quantity() ) * $qty, $dec );
			$taxes      = array();
			$gross      = $total;

			foreach ( (array) $item->get_taxes()['total'] as $rate_id => $tax ) {
				$tax_left          = (float) $tax - abs( (float) $order->get_tax_refunded_for_item( $id, $rate_id ) );
				$part              = $qty === $rest ? $tax_left : round( (float) $tax / max( 1, (int) $item->get_quantity() ) * $qty, $dec );
				$taxes[ $rate_id ] = max( 0.0, $part );
				$gross            += $taxes[ $rate_id ];
			}

			$line_items[ $id ] = array( 'qty' => $qty, 'refund_total' => max( 0.0, $total ), 'refund_tax' => $taxes );
			$amount           += $gross;
		}

		$free = max( 0.0, round( (float) $free, 2 ) );
		$amount = round( $amount + $free, 2 );

		if ( $amount <= 0 ) {
			return array( 'ok' => false, 'message' => __( 'Bitte Positionen oder einen Betrag zum Erstatten wählen.', 'lindenweg16' ) );
		}

		$refundable = round( (float) $order->get_total() - (float) $order->get_total_refunded(), 2 );

		if ( $amount > $refundable + 0.001 ) {
			return array( 'ok' => false, 'message' => sprintf( /* translators: %s: Betrag. */ __( 'Es lassen sich höchstens noch %s € erstatten.', 'lindenweg16' ), number_format( $refundable, 2, ',', '.' ) ) );
		}

		return array( 'ok' => true, 'message' => '', 'amount' => $amount, 'line_items' => $line_items );
	}

	/**
	 * Erstatten.
	 *
	 * Nach `wc_create_refund`: Beim Zahlungsanbieter wird nur erstattet, wenn
	 * `zahlung` gesetzt ist und das Gateway es kann; sonst steht hinterher eine
	 * Erstattung in WooCommerce, das Geld muss von Hand zurück. Der Betrag wird
	 * serverseitig neu berechnet – was der Browser rechnet, gilt nicht.
	 *
	 * @param WP_REST_Request $request Anfrage (id, zeilen, frei, grund, bestand, zahlung).
	 * @return WP_REST_Response
	 */
	public static function erstattung( WP_REST_Request $request ) {
		$body  = (array) $request->get_json_params();
		$order = self::order( $body['id'] ?? 0 );

		if ( ! $order ) {
			return self::fail( __( 'Diese Bestellung gibt es nicht.', 'lindenweg16' ), 404 );
		}

		$id = $order->get_id();

		// Doppelklick oder zwei Geräte zugleich dürfen nicht zweimal Geld zurückgeben.
		if ( ! Lock::acquire( 'shop_refund_' . $id, 5 ) ) {
			return self::fail( __( 'Für diese Bestellung läuft gerade eine Erstattung.', 'lindenweg16' ), 409 );
		}

		try {
			// Frisch laden: Zwischen Anzeige und Klick kann jemand anders erstattet haben.
			$order = self::order( $id );
			$free  = \Lindenweg16\Modules\POS\POSModule::parse_amount( $body['frei'] ?? '' );
			$plan  = self::plan_refund( $order, array_values( array_filter( (array) ( $body['zeilen'] ?? array() ), 'is_array' ) ), $free );

			if ( ! $plan['ok'] ) {
				return self::fail( $plan['message'] );
			}

			$reason  = sanitize_text_field( (string) ( $body['grund'] ?? '' ) );
			$payment = ! empty( $body['zahlung'] );
			$restock = ! empty( $body['bestand'] );
			$user    = wp_get_current_user();

			$refund = wc_create_refund(
				array(
					'order_id'       => $id,
					'amount'         => $plan['amount'],
					'reason'         => $reason,
					'line_items'     => $plan['line_items'],
					'refund_payment' => $payment,
					'restock_items'  => $restock,
				)
			);

			if ( is_wp_error( $refund ) ) {
				Log::attention( 'shop', 'Erstattung fehlgeschlagen: ' . $refund->get_error_message(), array( 'order' => $id ) );

				return self::fail( $refund->get_error_message() );
			}

			$order = self::order( $id );
			$order->add_order_note(
				sprintf(
					/* translators: 1: Name, 2: Betrag, 3: ja/nein. */
					__( 'Im ERP erstattet von %1$s: %2$s €, Zahlung beim Anbieter: %3$s.', 'lindenweg16' ),
					$user->display_name,
					number_format( $plan['amount'], 2, ',', '.' ),
					$payment ? __( 'ja', 'lindenweg16' ) : __( 'nein (von Hand zurückzahlen)', 'lindenweg16' )
				)
			);

			AuditLog::record( 'shop_bestellung_erstattung', 'shop_order', $id, array( 'betrag' => $plan['amount'], 'zahlung' => $payment, 'bestand' => $restock, 'grund' => $reason ) );
		} finally {
			Lock::release( 'shop_refund_' . $id );
		}

		return new WP_REST_Response( array(
			'ok'      => true,
			'message' => sprintf( /* translators: %s: Betrag. */ __( 'Erstattet: %s €', 'lindenweg16' ), number_format( $plan['amount'], 2, ',', '.' ) ),
			'order'   => self::payload( self::order( $id ) ),
		) );
	}
}
