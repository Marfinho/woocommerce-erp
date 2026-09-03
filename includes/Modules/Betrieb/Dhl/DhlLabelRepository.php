<?php
/**
 * Erzeugte Versandlabels.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb\Dhl;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Installer;

/**
 * Führt Buch über die Labels: welche Sendung, welches Produkt, welche
 * Datei – und ob sie storniert wurde.
 *
 * Ein Label ist eine Frankierung und damit ein Kostenbeleg. Es wird
 * deshalb nicht gelöscht, wenn es storniert wird, sondern als storniert
 * markiert. Sonst stünde in der Abrechnung der DHL eine Position, zu der
 * es hier keinen Vorgang mehr gibt.
 */
class DhlLabelRepository {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'lw16_shipping_labels';
	}

	/**
	 * Dateiname eines Labels – reine Zeichenkettenlogik.
	 *
	 * Die Sendungsnummer steckt im Namen, damit sich eine Datei im
	 * Verzeichnis auch ohne Datenbank zuordnen lässt.
	 *
	 * @param int    $order_id    Bestellung.
	 * @param string $shipment_no Sendungsnummer.
	 * @return string
	 */
	public static function filename( $order_id, $shipment_no ) {
		$nummer = preg_replace( '/[^0-9A-Za-z]/', '', (string) $shipment_no );

		return sprintf( 'dhl-label-%d-%s.pdf', (int) $order_id, $nummer );
	}

	/**
	 * Label ablegen und eintragen.
	 *
	 * @param array $daten {order_id, shipment_no, product, billing_number, filename, weight_g}
	 * @return int
	 */
	public static function create( array $daten ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'order_id'       => (int) $daten['order_id'],
				'shipment_no'    => (string) $daten['shipment_no'],
				'product'        => (string) $daten['product'],
				'billing_number' => (string) $daten['billing_number'],
				'filename'       => (string) $daten['filename'],
				'weight_g'       => isset( $daten['weight_g'] ) ? (int) $daten['weight_g'] : 0,
				'storniert'      => 0,
				'erstellt_am'    => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Gültiges Label einer Bestellung.
	 *
	 * @param int $order_id Bestellung.
	 * @return object|null
	 */
	public static function for_order( $order_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE order_id = %d AND storniert = 0 ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $order_id
			)
		);
	}

	/**
	 * Einzelnes Label.
	 *
	 * @param int $id Label.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Als storniert markieren.
	 *
	 * @param int $id Label.
	 */
	public static function mark_voided( $id ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'storniert'     => 1,
				'storniert_am'  => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Vollständiger Pfad einer Labeldatei.
	 *
	 * @param string $filename Dateiname.
	 * @return string
	 */
	public static function path( $filename ) {
		return trailingslashit( Installer::docs_dir() ) . $filename;
	}
}
