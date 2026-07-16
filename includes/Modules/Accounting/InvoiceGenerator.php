<?php
/**
 * Automatische PDF-Rechnungserstellung je Bestellung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Accounting;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Installer;
use Lindenweg16\Core\Settings;
use Lindenweg16\Core\Pdf;

/**
 * Erzeugt lückenlos nummerierte PDF-Rechnungen (Konvention JJJJ-NNNN,
 * pro Kalenderjahr neu beginnend) mit §19-UStG-Hinweis und legt sie
 * geschützt ab. Abrufbar im Admin und im Kundenkonto.
 */
class InvoiceGenerator {

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_rechnungen';
	}

	/**
	 * Rechnung für eine Bestellung erzeugen (idempotent).
	 *
	 * @param int $order_id Bestell-ID.
	 * @return int|false Rechnungs-ID oder false.
	 */
	public static function generate_for_order( $order_id ) {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// Bereits vorhanden?
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order_id ) ); // phpcs:ignore
		if ( $existing ) {
			return (int) $existing->id;
		}

		$jahr = (int) gmdate( 'Y', $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() );

		// Nächste laufende Nummer atomar ermitteln (lückenlos je Jahr bzw. gesamt).
		$modus = Settings::get( 'rechnungsnummer_modus', 'jahr' );
		if ( 'durchlaufend' === $modus ) {
			$laufnummer = 1 + (int) $wpdb->get_var( 'SELECT COALESCE(MAX(laufnummer),0) FROM ' . self::table() ); // phpcs:ignore
		} else {
			$laufnummer = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(laufnummer),0) FROM ' . self::table() . ' WHERE jahr = %d', $jahr ) ); // phpcs:ignore
		}
		$nummer = \Lindenweg16\Core\Calc::rechnungsnummer( $modus, $jahr, $laufnummer );

		// Datensatz zuerst anlegen (UNIQUE schützt vor Doppelnummern).
		$ok = $wpdb->insert(
			self::table(),
			array(
				'order_id'        => $order_id,
				'rechnungsnummer' => $nummer,
				'jahr'            => $jahr,
				'laufnummer'      => $laufnummer,
				'betrag'          => (float) $order->get_total(),
				'erstellt_am'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%d', '%f', '%s' )
		);
		if ( ! $ok ) {
			return false;
		}
		$rechnung_id = (int) $wpdb->insert_id;

		// PDF erzeugen und Pfad nachtragen.
		$filename = 'rechnung-' . $nummer . '-' . wp_generate_password( 8, false ) . '.pdf';
		$path     = trailingslashit( Installer::docs_dir() ) . $filename;
		self::build_pdf( $order, $nummer )->save( $path );

		$wpdb->update( self::table(), array( 'datei_pfad' => $filename ), array( 'id' => $rechnung_id ) );

		$order->update_meta_data( '_lw16_invoice_number', $nummer );
		$order->save();

		return $rechnung_id;
	}

	/**
	 * Das Rechnungs-PDF aufbauen.
	 *
	 * @param \WC_Order $order  Bestellung.
	 * @param string    $nummer Rechnungsnummer.
	 * @return Pdf
	 */
	protected static function build_pdf( $order, $nummer ) {
		$pdf = new Pdf();
		$pdf->enable_ci();

		// Rechnungsempfänger.
		$pdf->text( __( 'Rechnung an:', 'lindenweg16' ), 10, true );
		$pdf->text( trim( $order->get_formatted_billing_full_name() ), 11 );
		$adr = array(
			$order->get_billing_address_1(),
			trim( $order->get_billing_postcode() . ' ' . $order->get_billing_city() ),
		);
		foreach ( array_filter( $adr ) as $line ) {
			$pdf->text( $line, 11 );
		}
		$pdf->space( 10 );

		// Rechnungsmeta.
		$pdf->row( __( 'Rechnungsnummer:', 'lindenweg16' ), $nummer, 11, true );
		$datum = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd.m.Y' ) : gmdate( 'd.m.Y' );
		$pdf->row( __( 'Rechnungsdatum:', 'lindenweg16' ), $datum, 11 );
		$pdf->row( __( 'Bestellnummer:', 'lindenweg16' ), '#' . $order->get_order_number(), 11 );
		$pdf->space( 8 );
		$pdf->hr();

		// Positionen.
		$pdf->row( __( 'Position', 'lindenweg16' ), __( 'Betrag', 'lindenweg16' ), 11, true );
		$pdf->hr();
		foreach ( $order->get_items() as $item ) {
			$name = $item->get_name() . ' x' . $item->get_quantity();
			$pdf->row( $name, self::money( (float) $item->get_total() ), 11 );
		}
		if ( (float) $order->get_shipping_total() > 0 ) {
			$pdf->row( __( 'Versand', 'lindenweg16' ), self::money( (float) $order->get_shipping_total() ), 11 );
		}
		$pdf->hr();
		$pdf->row( __( 'Gesamtbetrag', 'lindenweg16' ), self::money( (float) $order->get_total() ), 13, true );
		$pdf->space( 16 );

		// §19-UStG-Hinweis.
		if ( (int) Settings::get( 'kleinunternehmer', 1 ) === 1 ) {
			$pdf->text( __( 'Gemäß §19 UStG (Kleinunternehmerregelung) wird keine Umsatzsteuer berechnet.', 'lindenweg16' ), 10 );
		}
		$pdf->space( 8 );
		$pdf->text( __( 'Vielen Dank für Deinen Einkauf bei Lindenweg16!', 'lindenweg16' ), 10 );

		return $pdf;
	}

	/**
	 * Rechnung einer Bestellung holen.
	 *
	 * @param int $order_id Bestell-ID.
	 * @return object|null
	 */
	public static function get_by_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order_id ) ); // phpcs:ignore
	}

	/**
	 * Rechnung per ID.
	 *
	 * @param int $id Rechnungs-ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	/**
	 * Betrag als Euro.
	 *
	 * @param float $n Betrag.
	 * @return string
	 */
	protected static function money( $n ) {
		return number_format( (float) $n, 2, ',', '.' ) . ' EUR';
	}
}
