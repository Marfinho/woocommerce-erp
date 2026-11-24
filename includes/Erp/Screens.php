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
use Lindenweg16\Modules\Betrieb\OrderInserts;
use Lindenweg16\Modules\Betrieb\OrderPicking;
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
				$snapshot = Shell::nonce_url(
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
		$job_ids  = array();

		foreach ( RunRepository::for_printer( $printer->printer_id, 25 ) as $run ) {
			$lines = array();
			foreach ( $run['items'] as $item ) {
				$lines[] = (int) $item['qty'] . '× ' . ( isset( $item['name'] ) ? $item['name'] : '' );
			}

			if ( in_array( $run['status'], array( RunFlow::RUESTEN, RunFlow::DRUCKT, RunFlow::ZU_MELDEN ), true ) && ! $current ) {
				$current = array( 'id' => (int) $run['id'], 'status' => $run['status'], 'text' => implode( ', ', $lines ) );
			}

			$minutes = Variance::run_minutes( $run );

			if ( ! empty( $run['print_job_ids'] ) ) {
				$job_ids = array_merge( $job_ids, explode( ',', (string) $run['print_job_ids'] ) );
			}

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
				// Stunden des Geräts plus die der Platten dieses Druckers, die beim Melden auf einem anderen Gerät landeten.
				'hours'  => round( PrintJobRepository::operating_hours( $device ) + PrintJobRepository::hours_elsewhere( $job_ids, $device ), 1 ),
			) : null,
			'linked'   => (bool) $device,
			'free'     => BambuPrinterRepository::free_prints( $printer->printer_id, 20 ),
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
	 * Die Stufe ergibt sich aus den Tatsachen, nicht aus einem Merker: Bezahlt
	 * ist die Bestellung zu kommissionieren, kommissioniert ohne Label zu
	 * packen, mit Label zu versenden, im Status „abgeschlossen“ versendet. So kann der Stand nie von der Wirklichkeit
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
		$picked = OrderPicking::STATUS === $order->get_status();
		$stage = $done ? 'done' : ( $picked ? ( $label ? 'send' : 'pack' ) : 'pick' );

		$entry = array(
			'id'        => $id,
			'number'    => $order->get_order_number(),
			'ts'        => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
			'status'    => wc_get_order_status_name( $order->get_status() ),
			'stage'     => $stage,
			'name'      => $order->get_formatted_shipping_full_name() ? $order->get_formatted_shipping_full_name() : $order->get_formatted_billing_full_name(),
			'place'     => trim( $order->get_shipping_postcode() . ' ' . $order->get_shipping_city() . ' ' . $land ),
			'content'   => self::summarize_items( $lines ),
			'tracking'  => $row ? (string) $row->tracking_nummer : (string) DhlTracking::extract_tracking_number( $order ),
			'tstatus'   => $row ? (string) $row->aktueller_status : '',
			'slip'      => Shell::nonce_url( admin_url( 'admin-post.php?action=lw16_packliste&order_id=' . $id ), 'lw16_packliste_' . $id ),
			'edit'      => $order->get_edit_order_url(),
			'label'     => null,
			'suggested' => null,
		);

		$see_cost               = Access::can( 'finanzen.kosten' );
		$entry['tracking_more'] = array_values( array_filter( array_map( 'strval', (array) $order->get_meta( DhlShipping::META_MORE ) ) ) );
		$entry['labels']        = array();

		foreach ( DhlLabelRepository::all_for_order( $id ) as $l ) {
			$entry['labels'][] = array(
				'id'      => (int) $l->id,
				'number'  => (string) $l->shipment_no,
				'product' => DhlProduct::label( $l->product ),
				'weight'  => (int) $l->weight_g,
				'pdf'     => Shell::nonce_url( admin_url( 'admin-post.php?action=lw16_betrieb_dhl_pdf&label=' . (int) $l->id ), 'lw16_betrieb_dhl_pdf_' . (int) $l->id ),
				// Porto nur mit dem Recht, Preise und Kosten zu sehen.
				'cost'    => $see_cost && null !== $l->kosten_netto ? (float) $l->kosten_netto : null,
				'cost_note' => $see_cost ? (string) $l->kosten_info : '',
			);
		}

		$entry['cost_total'] = $see_cost ? DhlLabelRepository::cost_for_order( $id ) : null;

		if ( $label ) {
			$entry['label'] = array(
				'id'      => (int) $label->id,
				'product' => DhlProduct::label( $label->product ),
				'weight'  => (int) $label->weight_g,
				'pdf'     => Shell::nonce_url( admin_url( 'admin-post.php?action=lw16_betrieb_dhl_pdf&label=' . (int) $label->id ), 'lw16_betrieb_dhl_pdf_' . (int) $label->id ),
			);
		}

		if ( $dhl['configured'] && in_array( $stage, array( 'pack', 'send' ), true ) ) {
			$weight             = DhlShipping::weight_g( $order );
			$entry['suggested'] = array(
				'weight'  => $label ? 0 : (int) $weight,
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
		foreach ( wc_get_orders( array( 'status' => array( 'wc-processing', 'wc-on-hold', 'wc-' . OrderPicking::STATUS ), 'limit' => 100, 'orderby' => 'date', 'order' => 'ASC' ) ) as $order ) {
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

		foreach ( OrderPicking::lines( $order ) as $line ) {
			$items[] = array(
				'id'        => $line['id'],
				'name'      => $line['name'],
				'qty'       => $line['qty'],
				'sku'       => $line['sku'],
				'scanned'   => $line['scanned'],
				'scannable' => ! empty( $line['codes'] ),
			);
		}

		$entry['items']   = $items;
		$entry['beilagen'] = OrderInserts::for_order( $order );
		$entry['picked']   = OrderPicking::is_complete( OrderPicking::lines( $order ), OrderPicking::scans( $order ) ) && ! OrderInserts::missing( $entry['beilagen'], OrderInserts::checked( $order ) );
		$entry['code']     = self::friend_code( $order );
		$entry['address'] = array_values( array_filter( array_map( 'trim', explode( '<br/>', (string) $order->get_formatted_shipping_address( $order->get_formatted_billing_address() ) ) ) ) );
		$entry['address'] = array_map( 'wp_strip_all_tags', $entry['address'] );
		$entry['note']    = (string) $order->get_customer_note();
		$entry['dhl']     = $dhl;
		$entry['timeline'] = self::order_timeline( $order, $entry );
		$entry['customer'] = self::order_customer( $order );

		return $entry;
	}

	/**
	 * Der Weg einer Bestellung: Eingang, Notizen, Label, Versand.
	 *
	 * Aus den Bestellnotizen von WooCommerce und den Daten, die der Versand
	 * ohnehin kennt – nichts wird zusätzlich gespeichert.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @param array     $entry Karte aus ship_entry().
	 * @return array Liste aus ts, text, kind – älteste zuerst.
	 */
	protected static function order_timeline( $order, array $entry ) {
		$events = array();

		if ( $order->get_date_created() ) {
			$events[] = array( 'ts' => $order->get_date_created()->getTimestamp(), 'text' => __( 'Bestellung eingegangen', 'lindenweg16' ), 'kind' => 'start' );
		}

		if ( $order->get_date_paid() ) {
			$events[] = array( 'ts' => $order->get_date_paid()->getTimestamp(), 'text' => __( 'Bezahlt', 'lindenweg16' ), 'kind' => 'paid' );
		}

		if ( function_exists( 'wc_get_order_notes' ) ) {
			foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 30 ) ) as $note ) {
				$events[] = array(
					'ts'   => $note->date_created ? $note->date_created->getTimestamp() : 0,
					'text' => wp_strip_all_tags( (string) $note->content ),
					'kind' => $note->customer_note ? 'customer' : 'note',
				);
			}
		}

		if ( $order->get_date_completed() ) {
			$events[] = array( 'ts' => $order->get_date_completed()->getTimestamp(), 'text' => __( 'Versendet, Lagerabgang gebucht', 'lindenweg16' ), 'kind' => 'done' );
		}

		usort(
			$events,
			function ( $a, $b ) {
				return $a['ts'] <=> $b['ts'];
			}
		);

		return $events;
	}

	/**
	 * Wer hat bestellt, und wie oft schon?
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return array
	 */
	protected static function order_customer( $order ) {
		$email = (string) $order->get_billing_email();
		$count = 0;

		if ( '' !== $email && function_exists( 'wc_get_orders' ) ) {
			$count = count( wc_get_orders( array( 'billing_email' => $email, 'limit' => 100, 'return' => 'ids', 'status' => array( 'wc-processing', 'wc-on-hold', 'wc-completed' ) ) ) );
		}

		$company = (string) $order->get_billing_company();
		$row     = OrderOpsRepository::find_by_order( $order->get_id() );
		$linked  = null;

		if ( $row && (int) $row['contact_id'] ) {
			$contact = ContactRepository::find( (int) $row['contact_id'] );
			$linked  = $contact ? array( 'id' => (int) $contact['id'], 'company' => (string) $contact['company'] ) : null;
		}

		return array(
			'name'    => trim( $order->get_formatted_billing_full_name() ),
			'company' => $company,
			'email'   => $email,
			'phone'   => (string) $order->get_billing_phone(),
			'orders'  => $count,
			'edit'    => $order->get_edit_order_url(),
			'contact' => $linked,
			'suggest' => $linked ? array() : OrderContact::suggest( ContactRepository::all(), $email, $company ),
		);
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

		if ( in_array( $order->get_status(), array( 'processing', 'on-hold' ), true ) ) {
			return array( 'ok' => false, 'message' => __( 'Diese Bestellung ist noch nicht kommissioniert. Erst die Ware scannen und „Kommissioniert“ melden.', 'lindenweg16' ) );
		}

		if ( OrderPicking::STATUS !== $order->get_status() ) {
			return array( 'ok' => false, 'message' => __( 'Diese Bestellung ist nicht versandbereit (Status: ', 'lindenweg16' ) . wc_get_order_status_name( $order->get_status() ) . ').' );
		}

		// Der Lagerabgang ist beim Kommissionieren gebucht; die Marke verhindert eine zweite Buchung.
		( new \Lindenweg16\Modules\Betrieb\WooIntegration() )->book_stock( (int) $order_id );

		$order->update_status( 'completed', __( 'Versendet über das ERP.', 'lindenweg16' ) );

		\Lindenweg16\Core\AuditLog::record( 'erp_versendet', 'order', (int) $order_id, array() );

		return array( 'ok' => true, 'message' => __( 'Versendet.', 'lindenweg16' ) );
	}

	/**
	 * Freunde-werben-Freunde-Code des Kunden – für den Danke-Zettel.
	 *
	 * Gäste bekommen einen Code zu ihrer E-Mail-Adresse (Vorname plus Zahl).
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return string Code oder leer.
	 */
	protected static function friend_code( $order ) {
		if ( ! class_exists( '\Lindenweg16\Modules\Loyalty\LoyaltyCodes' ) ) {
			return '';
		}

		return \Lindenweg16\Modules\Loyalty\LoyaltyCodes::code_for_order( $order );
	}

	/**
	 * Einen Beileger abhaken oder den Haken lösen.
	 *
	 * @param int    $order_id Bestellung.
	 * @param string $id       Regel-ID.
	 * @param bool   $checked  Abgehakt?
	 * @return array {ok, message, beilagen, picked}
	 */
	public static function tick_insert( $order_id, $id, $checked ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;

		if ( ! $order ) {
			return array( 'ok' => false, 'message' => __( 'Bestellung nicht gefunden.', 'lindenweg16' ) );
		}

		if ( ! in_array( $order->get_status(), array( 'processing', 'on-hold' ), true ) ) {
			return array( 'ok' => false, 'message' => __( 'Diese Bestellung wird nicht mehr kommissioniert.', 'lindenweg16' ) );
		}

		if ( ! OrderInserts::tick( $order, (string) $id, (bool) $checked ) ) {
			return array( 'ok' => false, 'message' => __( 'Diesen Beileger gibt es bei dieser Bestellung nicht.', 'lindenweg16' ) );
		}

		$order   = wc_get_order( (int) $order_id );
		$inserts = OrderInserts::for_order( $order );

		return array(
			'ok'       => true,
			'message'  => '',
			'beilagen' => $inserts,
			'picked'   => OrderPicking::is_complete( OrderPicking::lines( $order ), OrderPicking::scans( $order ) ) && ! OrderInserts::missing( $inserts, OrderInserts::checked( $order ) ),
		);
	}

	/**
	 * Sendungsnummer eines außerhalb des ERP gekauften Labels eintragen.
	 *
	 * Wer sein Label nicht über die Schnittstelle erzeugt (zum Beispiel bei wenigen
	 * Paketen am Tag, um die Monatspauschale zu vermeiden), trägt die Nummer hier von
	 * Hand ein. Sie geht in die Sendungsverfolgung, der Kunde sieht sie im Konto.
	 *
	 * @param int    $order_id Bestellung.
	 * @param string $number   Sendungsnummer.
	 * @return array {ok, message}
	 */
	public static function set_tracking( $order_id, $number ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;

		if ( ! $order ) {
			return array( 'ok' => false, 'message' => __( 'Bestellung nicht gefunden.', 'lindenweg16' ) );
		}

		$number = preg_replace( '/\s+/', '', (string) $number );

		if ( ! preg_match( '/^[0-9A-Za-z]{8,40}$/', $number ) ) {
			return array( 'ok' => false, 'message' => __( 'Das sieht nicht wie eine Sendungsnummer aus (8 bis 40 Buchstaben und Ziffern, ohne Leerzeichen).', 'lindenweg16' ) );
		}

		DhlShipping::hand_over_tracking( (int) $order_id, $number );
		\Lindenweg16\Core\AuditLog::record( 'erp_sendungsnummer_manuell', 'order', (int) $order_id, array( 'sendung' => $number ) );

		return array( 'ok' => true, 'message' => __( 'Sendungsnummer eingetragen. Der Kunde sieht sie im Konto.', 'lindenweg16' ) );
	}

	/**
	 * Eine Position der Bestellung scannen (oder, ohne Code, von Hand abhaken).
	 *
	 * @param int    $order_id Bestellung.
	 * @param string $code     Gescannter Code; leer beim Abhaken.
	 * @param int    $item_id  Positions-ID zum Abhaken einer Position ohne Code.
	 * @return array {ok, result, message, items, picked}
	 */
	public static function scan_item( $order_id, $code, $item_id = 0 ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;

		if ( ! $order ) {
			return array( 'ok' => false, 'message' => __( 'Bestellung nicht gefunden.', 'lindenweg16' ) );
		}

		if ( ! in_array( $order->get_status(), array( 'processing', 'on-hold' ), true ) ) {
			return array( 'ok' => false, 'message' => __( 'Diese Bestellung wird nicht mehr kommissioniert.', 'lindenweg16' ) );
		}

		$lines = OrderPicking::lines( $order );
		$scans = OrderPicking::scans( $order );

		if ( (int) $item_id > 0 ) {
			$res    = OrderPicking::confirm_manual( $lines, $scans, (int) $item_id );
			$result = $res['ok'] ? 'ok' : 'unknown';
			$msg    = $res['ok'] ? __( 'Abgehakt.', 'lindenweg16' ) : __( 'Diese Position hat einen Code und muss gescannt werden.', 'lindenweg16' );
			$scans  = $res['scans'];
		} else {
			$res    = OrderPicking::apply_scan( $lines, $scans, $code );
			$result = $res['result'];
			$scans  = $res['scans'];
			$msg    = array(
				'ok'       => __( 'Passt.', 'lindenweg16' ),
				'unknown'  => __( 'Dieser Code gehört nicht zur Bestellung.', 'lindenweg16' ),
				'too_many' => __( 'Zu viel: Von dieser Position ist schon alles gescannt.', 'lindenweg16' ),
			)[ $result ];
		}

		if ( 'ok' === $result ) {
			OrderPicking::save_scans( $order, $scans );
		}

		$items = OrderPicking::lines( $order );

		return array(
			'ok'      => true,
			'result'  => $result,
			'message' => $msg,
			'items'   => array_map(
				static function ( $l ) {
					return array( 'id' => $l['id'], 'scanned' => $l['scanned'] );
				},
				$items
			),
			'picked'  => OrderPicking::is_complete( $items, OrderPicking::scans( $order ) ) && ! OrderInserts::missing( OrderInserts::for_order( $order ), OrderInserts::checked( $order ) ),
		);
	}

	/**
	 * Als kommissioniert melden: Lagerabgang buchen, Status setzen.
	 *
	 * Nur, wenn jede Position gescannt (oder abgehakt) ist.
	 *
	 * @param int $order_id Bestellung.
	 * @return array {ok, message}
	 */
	public static function mark_picked( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;

		if ( ! $order ) {
			return array( 'ok' => false, 'message' => __( 'Bestellung nicht gefunden.', 'lindenweg16' ) );
		}

		if ( OrderPicking::STATUS === $order->get_status() ) {
			return array( 'ok' => true, 'message' => __( 'Schon kommissioniert.', 'lindenweg16' ) );
		}

		if ( ! in_array( $order->get_status(), array( 'processing', 'on-hold' ), true ) ) {
			return array( 'ok' => false, 'message' => __( 'Diese Bestellung lässt sich nicht kommissionieren (Status: ', 'lindenweg16' ) . wc_get_order_status_name( $order->get_status() ) . ').' );
		}

		if ( ! OrderPicking::is_complete( OrderPicking::lines( $order ), OrderPicking::scans( $order ) ) ) {
			return array( 'ok' => false, 'message' => __( 'Es fehlt noch Ware: Jede Position muss gescannt sein.', 'lindenweg16' ) );
		}

		$fehlt = OrderInserts::missing( OrderInserts::for_order( $order ), OrderInserts::checked( $order ) );

		if ( $fehlt ) {
			/* translators: %s: Liste der Beileger. */
			return array( 'ok' => false, 'message' => sprintf( __( 'Es fehlen noch Beileger: %s.', 'lindenweg16' ), implode( ', ', $fehlt ) ) );
		}

		( new \Lindenweg16\Modules\Betrieb\WooIntegration() )->book_stock( (int) $order_id );

		$order->update_status( OrderPicking::STATUS, __( 'Kommissioniert über das ERP.', 'lindenweg16' ) );

		\Lindenweg16\Core\AuditLog::record( 'erp_kommissioniert', 'order', (int) $order_id, array() );

		return array( 'ok' => true, 'message' => __( 'Kommissioniert. Der Lagerabgang ist gebucht.', 'lindenweg16' ) );
	}

	/**
	 * Kommissionieren zurücknehmen: Bestand zurück, Status wieder „in Bearbeitung“.
	 *
	 * Mit Label geht es nicht: erst das Label stornieren, sonst läge ein
	 * frankiertes Paket zu einer Bestellung, die im Lager wieder offen ist.
	 *
	 * @param int $order_id Bestellung.
	 * @return array {ok, message}
	 */
	public static function unpick( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;

		if ( ! $order ) {
			return array( 'ok' => false, 'message' => __( 'Bestellung nicht gefunden.', 'lindenweg16' ) );
		}

		if ( OrderPicking::STATUS !== $order->get_status() ) {
			return array( 'ok' => false, 'message' => __( 'Nur eine kommissionierte Bestellung lässt sich zurücknehmen.', 'lindenweg16' ) );
		}

		if ( DhlLabelRepository::for_order( (int) $order_id ) ) {
			return array( 'ok' => false, 'message' => __( 'Zu dieser Bestellung gibt es ein Label. Erst das Label stornieren, dann zurücknehmen.', 'lindenweg16' ) );
		}

		( new \Lindenweg16\Modules\Betrieb\WooIntegration() )->reverse_stock( (int) $order_id, true );

		OrderPicking::save_scans( $order, array() );
		$order->delete_meta_data( OrderInserts::META_CHECKED );
		$order->save();
		$order->update_status( 'processing', __( 'Kommissionieren zurückgenommen über das ERP.', 'lindenweg16' ) );

		\Lindenweg16\Core\AuditLog::record( 'erp_kommissionieren_zurueck', 'order', (int) $order_id, array() );

		return array( 'ok' => true, 'message' => __( 'Zurückgenommen. Der Lagerabgang ist zurückgebucht.', 'lindenweg16' ) );
	}

	/**
	 * Eine Shop-Bestellung einem Kontakt zuordnen (oder die Zuordnung lösen).
	 *
	 * Schreibt über OrderOpsRepository::update_fields mit allen bisherigen
	 * Werten der Betriebszeile, damit nichts anderes verloren geht. Mit
	 * Kontakt wird die Kundenart zur Geschäftskundenart, ohne Kontakt wieder
	 * B2C – so verlangt es die Prüfung dort.
	 *
	 * @param int $wc_order_id Shop-Bestellung.
	 * @param int $contact_id  Kontakt oder 0 zum Lösen.
	 * @return array {ok, message}
	 */
	public static function link_contact( $wc_order_id, $contact_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $wc_order_id ) : null;

		if ( ! $order ) {
			return array( 'ok' => false, 'message' => __( 'Bestellung nicht gefunden.', 'lindenweg16' ) );
		}

		$contact = null;

		if ( (int) $contact_id > 0 ) {
			$contact = ContactRepository::find( (int) $contact_id );

			if ( ! $contact ) {
				return array( 'ok' => false, 'message' => __( 'Diesen Kontakt gibt es nicht (mehr).', 'lindenweg16' ) );
			}
		}

		$id = OrderOpsRepository::ensure_for_order( (int) $wc_order_id );
		$op = $id ? OrderOpsRepository::find( $id ) : null;

		if ( ! $op ) {
			return array( 'ok' => false, 'message' => __( 'Die Betriebszeile zur Bestellung ließ sich nicht anlegen.', 'lindenweg16' ) );
		}

		$input                  = $op;
		$input['contact_id']    = $contact ? (int) $contact['id'] : 0;
		$input['customer_type'] = $contact ? OrderContact::customer_type( (string) $contact['type'] ) : 'B2C';

		if ( ! OrderOpsRepository::update_fields( $id, $input, $contact ? __( 'Kontakt aus dem ERP zugeordnet', 'lindenweg16' ) : __( 'Kontakt-Zuordnung aus dem ERP gelöst', 'lindenweg16' ) ) ) {
			return array( 'ok' => false, 'message' => __( 'Die Zuordnung ließ sich nicht speichern.', 'lindenweg16' ) );
		}

		return array(
			'ok'      => true,
			'message' => $contact
				? sprintf( /* translators: %s: Firma. */ __( 'Der Bestellung ist %s zugeordnet. Sie zählt jetzt in der Akte mit.', 'lindenweg16' ), $contact['company'] )
				: __( 'Die Zuordnung ist gelöst.', 'lindenweg16' ),
		);
	}
}
