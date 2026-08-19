<?php
/**
 * Betriebliche Zusatzdaten zu Bestellungen.
 *
 * Warenkorbwert, Datum, PLZ und Positionen werden nicht dupliziert – die
 * kommen aus WooCommerce. Hier steht nur, was WooCommerce nicht kennt.
 * Bestellungen außerhalb des Shops (Fachhandel, Barverkauf) tragen stattdessen
 * eigene Werte und eigene Positionen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Lesen und Schreiben der Bestelldaten.
 */
class OrderOpsRepository {

	use SanitizesInput;

	const CUSTOMER_TYPES  = array( 'B2C', 'B2B', 'Verein', 'Presse' );
	const CHANNELS        = array( 'Shop', 'Etsy', 'Fachhandel', 'vor Ort', 'Verein', 'Sonstiges' );
	const PRICE_LEVELS    = array( 'uvp', 'haendler', 'verein', 'muster' );
	const INVOICE_STATUS  = array( 'offen', 'berechnet', 'bezahlt', 'storniert' );
	const ORDER_STATUS    = array( 'entwurf', 'bestaetigt', 'storniert' );

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_order_ops';
	}

	/**
	 * Tabellenname der Positionen.
	 *
	 * @return string
	 */
	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_order_op_items';
	}

	/**
	 * Bestellungen holen.
	 *
	 * @param array $args Filter.
	 * @return array
	 */
	public static function all( array $args = array() ) {
		global $wpdb;

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['contact_id'] ) ) {
			$where[]  = 'contact_id = %d';
			$params[] = (int) $args['contact_id'];
		}
		if ( ! empty( $args['invoice_status'] ) ) {
			$where[]  = 'invoice_status = %s';
			$params[] = (string) $args['invoice_status'];
		}
		if ( ! empty( $args['order_status'] ) ) {
			$where[]  = 'order_status = %s';
			$params[] = (string) $args['order_status'];
		}

		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$params[] = isset( $args['limit'] ) ? (int) $args['limit'] : 200;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Eine Bestellung holen.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Datensatz zu einer WooCommerce-Bestellung.
	 *
	 * @param int $wc_order_id Bestellung.
	 * @return array|null
	 */
	public static function find_by_order( $wc_order_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE wc_order_id = %d", (int) $wc_order_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Positionen einer manuellen Bestellung.
	 *
	 * @param int $order_op_id Bestellung.
	 * @return array
	 */
	public static function items( $order_op_id ) {
		global $wpdb;
		$table = self::items_table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_op_id = %d ORDER BY id ASC", (int) $order_op_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Datensatz zu einer WooCommerce-Bestellung sicherstellen.
	 *
	 * @param int $wc_order_id Bestellung.
	 * @return int ID.
	 */
	public static function ensure_for_order( $wc_order_id ) {
		global $wpdb;

		$existing = self::find_by_order( $wc_order_id );
		if ( $existing ) {
			return (int) $existing['id'];
		}

		$data = array(
			'wc_order_id'    => (int) $wc_order_id,
			'customer_type'  => 'B2C',
			'channel'        => 'Shop',
			'price_level'    => 'uvp',
			'invoice_status' => 'bezahlt',
			'order_status'   => 'bestaetigt',
			'created_at'     => current_time( 'mysql', true ),
			'created_by'     => get_current_user_id(),
		);

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		BetriebAudit::record( 'create', 'order_ops', $id, null, $data );

		return $id;
	}

	/**
	 * Betriebsfelder einer Bestellung speichern.
	 *
	 * @param int    $id     ID.
	 * @param array  $input  Eingaben.
	 * @param string $reason Begründung.
	 * @return bool
	 */
	public static function update_fields( $id, array $input, $reason = '' ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return false;
		}

		$data = array(
			'handling_minutes'     => self::decimal( $input, 'handling_minutes' ),
			'personalized'         => self::flag( $input, 'personalized' ),
			'shipping_method'      => self::text( $input, 'shipping_method' ),
			'shipping_cost_actual' => self::decimal( $input, 'shipping_cost_actual' ),
			'returned'             => self::flag( $input, 'returned' ),
			'complaint'            => self::flag( $input, 'complaint' ),
			'review_received'      => self::enum( $input, 'review_received', array( 'ja', 'nein', 'offen' ), 'offen' ),
			'customer_type'        => in_array( isset( $input['customer_type'] ) ? $input['customer_type'] : '', self::CUSTOMER_TYPES, true ) ? $input['customer_type'] : $before['customer_type'],
			'channel'              => in_array( isset( $input['channel'] ) ? $input['channel'] : '', self::CHANNELS, true ) ? $input['channel'] : $before['channel'],
			'price_level'          => self::enum( $input, 'price_level', self::PRICE_LEVELS, $before['price_level'] ),
			'payment_terms_days'   => self::int_or( $input, 'payment_terms_days', 0 ),
			'invoice_status'       => self::enum( $input, 'invoice_status', self::INVOICE_STATUS, $before['invoice_status'] ),
			'invoice_date'         => self::date( $input, 'invoice_date' ),
			'paid_date'            => self::date( $input, 'paid_date' ),
			'note'                 => isset( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) : null,
			'contact_id'           => self::int_or( $input, 'contact_id', 0 ) ? self::int_or( $input, 'contact_id', 0 ) : null,
			'updated_at'           => current_time( 'mysql', true ),
		);

		// Ein Geschäftskunde ohne Kontakt lässt sich später keiner Akte
		// zuordnen – dann ist die B2B-Auswertung wertlos.
		if ( 'B2C' !== $data['customer_type'] && ! $data['contact_id'] ) {
			return false;
		}

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		BetriebAudit::record( 'update', 'order_ops', (int) $id, $before, $data, $reason );

		return true;
	}

	/**
	 * Manuelle Bestellung anlegen (Fachhandel, Barverkauf, Nachbestellung).
	 *
	 * @param array $data       Kopfdaten.
	 * @param array $items      Positionen mit article_id, qty, unit_price_gross.
	 * @param bool  $book_stock Lagerabgänge mitbuchen. Bei der Kommissions-
	 *                          abrechnung ist die Ware bereits ausgebucht –
	 *                          dann würde ein zweiter Abgang entstehen.
	 * @return int
	 */
	public static function create_manual( array $data, array $items, $book_stock = true ) {
		global $wpdb;

		$row = array_merge(
			array(
				'wc_order_id'        => null,
				'manual_order_date'  => gmdate( 'Y-m-d' ),
				'manual_order_value' => 0,
				'customer_type'      => 'B2B',
				'channel'            => 'Fachhandel',
				'price_level'        => 'haendler',
				'invoice_status'     => 'offen',
				'order_status'       => 'bestaetigt',
			),
			$data
		);

		$row['created_at'] = current_time( 'mysql', true );
		$row['created_by'] = get_current_user_id();

		if ( false === $wpdb->insert( self::table(), $row ) ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;

		foreach ( $items as $item ) {
			$wpdb->insert(
				self::items_table(),
				array(
					'order_op_id'      => $id,
					'article_id'       => (int) $item['article_id'],
					'color'            => isset( $item['color'] ) ? ColorRegistry::normalize( $item['color'] ) : ColorRegistry::default_color(),
					'qty'              => (float) $item['qty'],
					'unit_price_gross' => isset( $item['unit_price_gross'] ) ? (float) $item['unit_price_gross'] : 0.0,
					'discountable'     => isset( $item['discountable'] ) ? (int) $item['discountable'] : 1,
					'created_at'       => current_time( 'mysql', true ),
				)
			);
		}

		BetriebAudit::record( 'create', 'order_ops', $id, null, array_merge( $row, array( 'items' => $items ) ) );

		// Lagerabgänge und Komponentenverbrauch nur bei bestätigten
		// Bestellungen – ein Entwurf aus dem Nachbestell-Link darf nichts
		// bewegen, bevor er geprüft ist.
		if ( $book_stock && 'bestaetigt' === $row['order_status'] ) {
			self::book_stock( $id );
		}

		return $id;
	}

	/**
	 * Lagerabgänge einer manuellen Bestellung buchen.
	 *
	 * @param int $id Bestellung.
	 * @return int Anzahl gebuchter Positionen.
	 */
	public static function book_stock( $id ) {
		$count = 0;

		foreach ( self::items( $id ) as $item ) {
			StockRepository::book(
				array(
					'article_id'    => (int) $item['article_id'],
					'color'         => $item['color'],
					'qty'           => -1 * (float) $item['qty'],
					'movement_type' => 'verkauf',
					'location'      => 'lager',
					'ref_type'      => 'order_ops',
					'ref_id'        => (int) $id,
				)
			);

			ComponentConsumption::consume_for_sale( (int) $item['article_id'], (float) $item['qty'], 'order_ops', (int) $id );

			// Eingebaute Unterbaugruppen verlassen mit dem Set das Lager.
			$ctx = array(
				'bom'      => BomRepository::all_by_parent(),
				'articles' => ArticleRepository::all_by_id(),
			);
			ComponentConsumption::book_assemblies(
				ComponentConsumption::assemblies_at_sale( (int) $item['article_id'], (float) $item['qty'], $ctx ),
				$item['color'],
				'order_ops',
				(int) $id
			);

			$count++;
		}

		ComponentConsumption::consume_supplies( $id );

		return $count;
	}

	/**
	 * Entwurf bestätigen: Erst dadurch entstehen Lagerabgang und offener Posten.
	 *
	 * @param int $id Bestellung.
	 * @return bool
	 */
	public static function confirm( $id ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before || 'entwurf' !== $before['order_status'] ) {
			return false;
		}

		$data = array(
			'order_status' => 'bestaetigt',
			'updated_at'   => current_time( 'mysql', true ),
		);

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		BetriebAudit::record( 'update', 'order_ops', (int) $id, $before, $data, __( 'Entwurf bestätigt.', 'lindenweg16' ) );

		self::book_stock( $id );

		return true;
	}

	/**
	 * Offene Posten mit Fälligkeit.
	 *
	 * @return array
	 */
	public static function open_invoices() {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results(
			"SELECT * FROM {$table}
			 WHERE invoice_status IN ( 'offen', 'berechnet' ) AND order_status = 'bestaetigt'
			 ORDER BY invoice_date ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Ist ein offener Posten überfällig?
	 *
	 * @param array $order Bestellung.
	 * @return bool
	 */
	public static function is_overdue( array $order ) {
		if ( 'bezahlt' === $order['invoice_status'] || 'storniert' === $order['invoice_status'] ) {
			return false;
		}
		if ( empty( $order['invoice_date'] ) ) {
			return false;
		}

		$due = strtotime( $order['invoice_date'] . ' 12:00:00 UTC' ) + (int) $order['payment_terms_days'] * DAY_IN_SECONDS;

		return $due < time();
	}

	/**
	 * Zwischenspeicher für geladene WooCommerce-Bestellungen.
	 *
	 * Wert und Datum werden je Bestellung mehrfach abgefragt – Dashboard und
	 * Berichte laufen über hunderte Sätze. Ohne diesen Speicher lädt jede
	 * Auswertung dieselbe Bestellung mehrfach aus der Datenbank.
	 *
	 * @var array
	 */
	protected static $wc_cache = array();

	/**
	 * WooCommerce-Bestellung einmalig laden.
	 *
	 * @param int $wc_order_id Bestellung.
	 * @return \WC_Order|null
	 */
	protected static function wc_order( $wc_order_id ) {
		$wc_order_id = (int) $wc_order_id;

		if ( ! $wc_order_id || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		if ( ! array_key_exists( $wc_order_id, self::$wc_cache ) ) {
			$order = wc_get_order( $wc_order_id );
			self::$wc_cache[ $wc_order_id ] = $order ? $order : null;
		}

		return self::$wc_cache[ $wc_order_id ];
	}

	/**
	 * Bestellwert – aus WooCommerce oder aus dem manuellen Feld.
	 *
	 * @param array $order Bestellung.
	 * @return float
	 */
	public static function order_value( array $order ) {
		$wc = self::wc_order( isset( $order['wc_order_id'] ) ? $order['wc_order_id'] : 0 );

		if ( $wc ) {
			return (float) $wc->get_total();
		}

		return (float) $order['manual_order_value'];
	}

	/**
	 * Bestelldatum – aus WooCommerce oder aus dem manuellen Feld.
	 *
	 * @param array $order Bestellung.
	 * @return string
	 */
	public static function order_date( array $order ) {
		$wc = self::wc_order( isset( $order['wc_order_id'] ) ? $order['wc_order_id'] : 0 );

		if ( $wc && $wc->get_date_created() ) {
			return $wc->get_date_created()->date( 'Y-m-d' );
		}

		return (string) $order['manual_order_date'];
	}
}
