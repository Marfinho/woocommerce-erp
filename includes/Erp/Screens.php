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
use Lindenweg16\Modules\Betrieb\OrderOpsRepository;
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
			);
		}

		return $out;
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
	 * Versandliste.
	 *
	 * @param string $filter offen, versendet oder alle.
	 * @return array {orders, dhl}
	 */
	public static function shipping( $filter = 'offen' ) {
		global $wpdb;

		$dhl = array(
			'configured' => (bool) DhlClient::configured(),
			'sandbox'    => (bool) ( DhlClient::configured() && DhlClient::sandbox() ),
			'products'   => array(),
		);

		foreach ( DhlProduct::all() as $key => $product ) {
			$dhl['products'][] = array( 'id' => $key, 'label' => $product );
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( 'orders' => array(), 'dhl' => $dhl );
		}

		$orders = array();

		foreach ( wc_get_orders( array( 'status' => ShippingPage::statuses_for( $filter ), 'limit' => 100, 'orderby' => 'date', 'order' => 'ASC' ) ) as $order ) {
			$id    = $order->get_id();
			$lines = array();

			foreach ( $order->get_items() as $item ) {
				$lines[] = array( 'qty' => $item->get_quantity(), 'name' => $item->get_name() );
			}

			$land  = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();
			$label = DhlLabelRepository::for_order( $id );
			$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DhlTracking::table() . ' WHERE order_id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$entry = array(
				'id'        => $id,
				'number'    => $order->get_order_number(),
				'ts'        => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
				'status'    => wc_get_order_status_name( $order->get_status() ),
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
			} elseif ( $dhl['configured'] ) {
				$weight             = DhlShipping::weight_g( $order );
				$entry['suggested'] = array(
					'weight'  => (int) $weight,
					'product' => DhlProduct::for_destination( $land, $weight, 1 === (int) \Lindenweg16\Core\Settings::get( 'dhl_versand_warenpost', 0 ) ),
				);
			}

			$orders[] = $entry;
		}

		return array( 'orders' => $orders, 'dhl' => $dhl );
	}
}
