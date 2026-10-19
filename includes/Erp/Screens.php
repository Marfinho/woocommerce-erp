<?php
/**
 * Daten für die nativen ERP-Seiten Druckfarm, Aufträge und Versand.
 *
 * Die Seiten im wp-admin bleiben unverändert; hier werden dieselben
 * Datenquellen (Repositories, WooCommerce, DHL-Anbindung) als JSON
 * bereitgestellt, damit die Oberfläche sie ohne eingebettete Seite zeigt.
 * Schreibende Vorgänge rufen die vorhandenen Fachklassen auf – es gibt
 * keine zweite Geschäftslogik.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Betrieb\Admin\BambuPrintersPage;
use Lindenweg16\Modules\Betrieb\Admin\ShippingPage;
use Lindenweg16\Modules\Betrieb\Bambu\BambuJobRepository;
use Lindenweg16\Modules\Betrieb\Bambu\BambuPrinterRepository;
use Lindenweg16\Modules\Betrieb\ContactRepository;
use Lindenweg16\Modules\Betrieb\Dhl\DhlClient;
use Lindenweg16\Modules\Betrieb\Dhl\DhlLabelRepository;
use Lindenweg16\Modules\Betrieb\Dhl\DhlProduct;
use Lindenweg16\Modules\Betrieb\Dhl\DhlShipping;
use Lindenweg16\Modules\Betrieb\MaintenanceRepository;
use Lindenweg16\Modules\Betrieb\OrderOpsRepository;
use Lindenweg16\Modules\Betrieb\PrinterRepository;
use Lindenweg16\Modules\Betrieb\PrintJobRepository;
use Lindenweg16\Modules\Production\DhlTracking;

/**
 * Bereitet die Daten der nativen Seiten auf.
 */
class Screens {

	/**
	 * Druckerstatus-Wörter, wie sie die Karten im wp-admin zeigen.
	 *
	 * @return array
	 */
	public static function printer_status_labels() {
		return array(
			'mqtt_getrennt' => __( 'Drucker nicht erreichbar', 'lindenweg16' ),
			'idle'          => __( 'bereit', 'lindenweg16' ),
			'running'       => __( 'druckt', 'lindenweg16' ),
			'prepare'       => __( 'bereitet vor', 'lindenweg16' ),
			'pause'         => __( 'pausiert', 'lindenweg16' ),
			'finish'        => __( 'fertig', 'lindenweg16' ),
			'failed'        => __( 'Fehler', 'lindenweg16' ),
		);
	}

	/**
	 * Farbe und Text eines AMS-Fachs; leere Fächer entfallen.
	 *
	 * Reine Aufbereitung ohne Datenbank: Die Bridge liefert Farben als
	 * Hex mit Alphakanal und Restmengen manchmal leer oder negativ.
	 *
	 * @param array $units AMS-Einheiten wie aus BambuPrinterRepository::ams_units().
	 * @return array Liste von {material, color, remain}.
	 */
	public static function ams_trays( array $units ) {
		$out = array();

		foreach ( $units as $unit ) {
			foreach ( (array) ( isset( $unit['trays'] ) ? $unit['trays'] : array() ) as $tray ) {
				$material = isset( $tray['type'] ) ? trim( (string) $tray['type'] ) : '';

				if ( '' === $material ) {
					continue;
				}

				$color  = isset( $tray['color'] ) ? preg_replace( '/[^0-9A-Fa-f]/', '', (string) $tray['color'] ) : '';
				$color  = $color ? substr( str_pad( $color, 6, '0' ), 0, 6 ) : 'cccccc';
				$remain = isset( $tray['remain'] ) && null !== $tray['remain'] && '' !== $tray['remain'] ? (int) $tray['remain'] : null;

				if ( null !== $remain && ( $remain < 0 || $remain > 100 ) ) {
					$remain = null;
				}

				$out[] = array(
					'material' => $material,
					'color'    => '#' . strtolower( $color ),
					'remain'   => $remain,
				);
			}
		}

		return $out;
	}

	/**
	 * Live-Stand aller Bambu-Drucker.
	 *
	 * @return array
	 */
	public static function printer_farm() {
		$labels = self::printer_status_labels();
		$out    = array();

		foreach ( BambuPrinterRepository::all() as $printer ) {
			$online = BambuPrinterRepository::is_online( $printer );
			$status = strtolower( (string) $printer->status );

			$snapshot = '';
			if ( $printer->snapshot_at ) {
				$snapshot = wp_nonce_url(
					add_query_arg(
						array(
							'action'     => 'lw16_bambu_snapshot',
							'printer_id' => $printer->printer_id,
							't'          => strtotime( $printer->snapshot_at . ' UTC' ),
						),
						admin_url( 'admin-post.php' )
					),
					BambuPrintersPage::NONCE . '_snapshot'
				);
			}

			$out[] = array(
				'id'        => $printer->printer_id,
				'name'      => $printer->name,
				'model'     => (string) $printer->printer_model,
				'online'    => (bool) $online,
				'status'    => $online ? $status : 'offline',
				'text'      => $online ? ( isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $printer->status ) : __( 'offline', 'lindenweg16' ),
				'progress'  => $online && null !== $printer->progress_percent ? (int) $printer->progress_percent : null,
				'remaining' => $online && null !== $printer->remaining_minutes ? (int) $printer->remaining_minutes : null,
				'nozzle'    => $online && null !== $printer->nozzle_temp ? (float) $printer->nozzle_temp : null,
				'bed'       => $online && null !== $printer->bed_temp ? (float) $printer->bed_temp : null,
				'trays'     => $online ? self::ams_trays( BambuPrinterRepository::ams_units( $printer ) ) : array(),
				'error'     => (string) $printer->last_error,
				'queue'     => (int) BambuJobRepository::count_queued( $printer->printer_id ),
				'seen'      => $printer->last_seen_at ? strtotime( $printer->last_seen_at . ' UTC' ) : 0,
				'snapshot'  => $snapshot,
				'service'   => self::service_reasons( $printer->printer_id ),
			);
		}

		return $out;
	}

	/**
	 * Fällige Wartung zu einem Bambu-Drucker (über das verknüpfte Gerät).
	 *
	 * @param string $bambu_id Bambu-Drucker.
	 * @return string[]
	 */
	protected static function service_reasons( $bambu_id ) {
		$device = Workflow::device_for( $bambu_id );
		$row    = $device ? PrinterRepository::find( $device ) : null;

		return $row ? MaintenanceRepository::reasons_for( $row ) : array();
	}

	/**
	 * Die Akte eines Druckers: Live-Stand, aktuelle Platte, Verlauf, Wartung.
	 *
	 * @param string $bambu_id Bambu-Drucker.
	 * @return array|null
	 */
	public static function printer_file( $bambu_id ) {
		$printer = BambuPrinterRepository::find_by_printer_id( sanitize_key( (string) $bambu_id ) );

		if ( ! $printer ) {
			return null;
		}

		$live = array();
		foreach ( self::printer_farm() as $row ) {
			if ( $row['id'] === $printer->printer_id ) {
				$live = $row;
			}
		}

		$device   = Workflow::device_for( $printer->printer_id );
		$row      = $device ? PrinterRepository::find( $device ) : null;
		$history  = array();
		$current  = null;
		$week_min = 0.0;
		$since    = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

		foreach ( RunRepository::for_printer( $printer->printer_id, 25 ) as $run ) {
			$lines = array();
			foreach ( $run['items'] as $item ) {
				$lines[] = (int) $item['qty'] . '× ' . ( isset( $item['name'] ) ? $item['name'] : '' );
			}

			if ( in_array( $run['status'], array( RunFlow::RUESTEN, RunFlow::DRUCKT, RunFlow::ZU_MELDEN ), true ) && ! $current ) {
				$current = array( 'id' => (int) $run['id'], 'status' => $run['status'], 'text' => implode( ', ', $lines ) );
			}

			$minutes = Variance::run_minutes( $run );

			if ( $minutes && (string) $run['reported_at'] >= $since ) {
				$week_min += $minutes['print'];
			}

			$history[] = array(
				'id'     => (int) $run['id'],
				'status' => $run['status'],
				'text'   => implode( ', ', $lines ),
				'when'   => $run['reported_at'] ? strtotime( $run['reported_at'] . ' UTC' ) : ( $run['released_at'] ? strtotime( $run['released_at'] . ' UTC' ) : 0 ),
				'plan'   => (int) $run['est_minutes'],
				'actual' => $minutes ? (int) round( $minutes['print'] ) : null,
			);
		}

		$log = array();
		if ( $device ) {
			foreach ( array_slice( MaintenanceRepository::for_printer( $device ), 0, 6 ) as $entry ) {
				$log[] = array(
					'on'   => $entry['performed_on'],
					'type' => (string) $entry['interval_type'],
					'part' => (string) $entry['part_replaced'],
					'note' => (string) $entry['note'],
				);
			}
		}

		return array(
			'id'       => $printer->printer_id,
			'name'     => $printer->name,
			'model'    => (string) $printer->printer_model,
			'live'     => $live,
			'current'  => $current,
			'week_h'   => round( $week_min / 60, 1 ),
			'service'  => $live ? $live['service'] : array(),
			'log'      => $log,
			'history'  => $history,
			'device'   => $row ? array(
				'code'   => (string) $row['code'],
				'status' => (string) $row['status'],
				'hours'  => round( PrintJobRepository::operating_hours( $device ), 1 ),
			) : null,
			'linked'   => (bool) $device,
		);
	}

	/**
	 * Aufträge (Bestellungen im Betrieb) mit Kontakt und Wert.
	 *
	 * @param string $status Filter: alle, entwurf, bestaetigt, storniert.
	 * @return array
	 */
	public static function orders( $status = '' ) {
		$args = array( 'limit' => 100 );

		if ( in_array( $status, array( 'entwurf', 'bestaetigt', 'storniert' ), true ) ) {
			$args['order_status'] = $status;
		}

		$contacts = array();
		$out      = array();

		foreach ( OrderOpsRepository::all( $args ) as $order ) {
			$cid = (int) $order['contact_id'];

			if ( $cid && ! isset( $contacts[ $cid ] ) ) {
				$row              = ContactRepository::find( $cid );
				$contacts[ $cid ] = $row ? (string) $row['company'] : '';
			}

			$out[] = array(
				'id'      => (int) $order['id'],
				'wc'      => (int) $order['wc_order_id'],
				'contact' => $cid ? $contacts[ $cid ] : '',
				'date'    => OrderOpsRepository::order_date( $order ),
				'value'   => OrderOpsRepository::order_value( $order ),
				'status'  => (string) $order['order_status'],
				'invoice' => (string) $order['invoice_status'],
				'overdue' => OrderOpsRepository::is_overdue( $order ),
			);
		}

		return $out;
	}

	/**
	 * Entwurf bestätigen.
	 *
	 * @param int $id Auftrag.
	 * @return array {ok, message}
	 */
	public static function confirm_order( $id ) {
		$ok = OrderOpsRepository::confirm( (int) $id );

		return array(
			'ok'      => $ok,
			'message' => $ok
				? __( 'Bestellung bestätigt, Lagerabgang und offener Posten gebucht.', 'lindenweg16' )
				: __( 'Nur Entwürfe lassen sich bestätigen.', 'lindenweg16' ),
		);
	}

	/**
	 * Zusammenfassung des Bestellinhalts: erste drei Positionen.
	 *
	 * @param array $lines Liste von {qty, name}.
	 * @return string
	 */
	public static function summarize_items( array $lines ) {
		if ( ! $lines ) {
			return '—';
		}

		$parts = array();
		foreach ( array_slice( $lines, 0, 3 ) as $line ) {
			$parts[] = $line['qty'] . '× ' . $line['name'];
		}

		if ( count( $lines ) > 3 ) {
			/* translators: %d: Anzahl weiterer Positionen. */
			$parts[] = sprintf( __( '… und %d weitere', 'lindenweg16' ), count( $lines ) - 3 );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Die DHL-Angaben, die die Oberfläche braucht.
	 *
	 * @return array {configured, sandbox, products}
	 */
	protected static function dhl_info() {
		$dhl = array(
			'configured' => (bool) DhlClient::configured(),
			'sandbox'    => (bool) ( DhlClient::configured() && DhlClient::sandbox() ),
			'products'   => array(),
		);

		foreach ( DhlProduct::all() as $key => $product ) {
			$dhl['products'][] = array( 'id' => $key, 'label' => $product );
		}

		return $dhl;
	}

	/**
	 * Eine Bestellung als Karte des Versand-Workflows.
	 *
	 * Die Stufe ergibt sich aus den Tatsachen, nicht aus einem Merker: Ohne
	 * Label ist die Bestellung zu packen, mit Label zu versenden, im Status
	 * „abgeschlossen“ versendet. So kann der Stand nie von der Wirklichkeit
	 * abweichen, auch wenn jemand im Shop etwas anders macht.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @param array     $dhl   Angaben aus dhl_info().
	 * @return array
	 */
	protected static function ship_entry( $order, array $dhl ) {
		global $wpdb;

		$id    = $order->get_id();
		$lines = array();

		foreach ( $order->get_items() as $item ) {
			$lines[] = array( 'qty' => $item->get_quantity(), 'name' => $item->get_name() );
		}

		$land  = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();
		$label = DhlLabelRepository::for_order( $id );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DhlTracking::table() . ' WHERE order_id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$done  = 'completed' === $order->get_status();

		$entry = array(
			'id'        => $id,
			'number'    => $order->get_order_number(),
			'ts'        => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
			'status'    => wc_get_order_status_name( $order->get_status() ),
			'stage'     => $done ? 'done' : ( $label ? 'send' : 'pack' ),
			'name'      => $order->get_formatted_shipping_full_name() ? $order->get_formatted_shipping_full_name() : $order->get_formatted_billing_full_name(),
			'place'     => trim( $order->get_shipping_postcode() . ' ' . $order->get_shipping_city() . ' ' . $land ),
			'content'   => self::summarize_items( $lines ),
			'tracking'  => $row ? (string) $row->tracking_nummer : (string) DhlTracking::extract_tracking_number( $order ),
			'tstatus'   => $row ? (string) $row->aktueller_status : '',
			'slip'      => wp_nonce_url( admin_url( 'admin-post.php?action=lw16_packliste&order_id=' . $id ), 'lw16_packliste_' . $id ),
			'edit'      => $order->get_edit_order_url(),
			'label'     => null,
			'suggested' => null,
		);

		if ( $label ) {
			$entry['label'] = array(
				'id'      => (int) $label->id,
				'product' => DhlProduct::label( $label->product ),
				'weight'  => (int) $label->weight_g,
				'pdf'     => wp_nonce_url( admin_url( 'admin-post.php?action=lw16_betrieb_dhl_pdf&label=' . (int) $label->id ), 'lw16_betrieb_dhl_pdf_' . (int) $label->id ),
			);
		} elseif ( $dhl['configured'] && ! $done ) {
			$weight             = DhlShipping::weight_g( $order );
			$entry['suggested'] = array(
				'weight'  => (int) $weight,
				'product' => DhlProduct::for_destination( $land, $weight, 1 === (int) \Lindenweg16\Core\Settings::get( 'dhl_versand_warenpost', 0 ) ),
			);
		}

		return $entry;
	}

	/**
	 * Versand-Workflow: offene Bestellungen und heute Versendetes.
	 *
	 * @return array {orders, dhl}
	 */
	public static function shipping() {
		$dhl = self::dhl_info();

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( 'orders' => array(), 'dhl' => $dhl );
		}

		$orders = array();
		$since  = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();

		// Älteste zuerst: Wer am längsten wartet, steht oben.
		foreach ( wc_get_orders( array( 'status' => array( 'wc-processing', 'wc-on-hold' ), 'limit' => 100, 'orderby' => 'date', 'order' => 'ASC' ) ) as $order ) {
			$orders[] = self::ship_entry( $order, $dhl );
		}

		foreach ( wc_get_orders( array( 'status' => array( 'wc-completed' ), 'limit' => 50, 'orderby' => 'date', 'order' => 'DESC', 'date_completed' => '>=' . $since ) ) as $order ) {
			$orders[] = self::ship_entry( $order, $dhl );
		}

		return array( 'orders' => $orders, 'dhl' => $dhl );
	}

	/**
	 * Eine Bestellung für die Packseite: Karte, Anschrift, Positionen.
	 *
	 * @param int $order_id Bestellung.
	 * @return array|null
	 */
	public static function ship_order( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;

		if ( ! $order ) {
			return null;
		}

		$dhl   = self::dhl_info();
		$entry = self::ship_entry( $order, $dhl );
		$items = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$items[] = array(
				'name' => $item->get_name(),
				'qty'  => (int) $item->get_quantity(),
				'sku'  => $product ? (string) $product->get_sku() : '',
			);
		}

		$entry['items']   = $items;
		$entry['address'] = array_values( array_filter( array_map( 'trim', explode( '<br/>', (string) $order->get_formatted_shipping_address( $order->get_formatted_billing_address() ) ) ) ) );
		$entry['address'] = array_map( 'wp_strip_all_tags', $entry['address'] );
		$entry['note']    = (string) $order->get_customer_note();
		$entry['dhl']     = $dhl;

		return $entry;
	}

	/**
	 * Als versendet melden: Lagerabgang buchen, Bestellung abschließen.
	 *
	 * Der Lagerabgang passiert hier, beim Versand – nicht erst, wenn jemand
	 * die Bestellung im Shop abschließt. Gebucht wird über die vorhandene
	 * Regel der WooCommerce-Anbindung (einmal je Bestellung, mit Sperre);
	 * der Statuswechsel danach löst sie noch einmal aus und findet nichts
	 * mehr zu tun.
	 *
	 * @param int $order_id Bestellung.
	 * @return array {ok, message}
	 */
	public static function mark_shipped( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;

		if ( ! $order ) {
			return array( 'ok' => false, 'message' => __( 'Bestellung nicht gefunden.', 'lindenweg16' ) );
		}

		if ( 'completed' === $order->get_status() ) {
			return array( 'ok' => true, 'message' => __( 'Schon als versendet gemeldet.', 'lindenweg16' ) );
		}

		if ( ! in_array( $order->get_status(), array( 'processing', 'on-hold' ), true ) ) {
			return array( 'ok' => false, 'message' => __( 'Diese Bestellung ist nicht versandbereit (Status: ', 'lindenweg16' ) . wc_get_order_status_name( $order->get_status() ) . ').' );
		}

		( new \Lindenweg16\Modules\Betrieb\WooIntegration() )->book_stock( (int) $order_id );

		$order->update_status( 'completed', __( 'Versendet über das ERP.', 'lindenweg16' ) );

		\Lindenweg16\Core\AuditLog::record( 'erp_versendet', 'order', (int) $order_id, array() );

		return array( 'ok' => true, 'message' => __( 'Versendet. Der Lagerabgang ist gebucht.', 'lindenweg16' ) );
	}
}
