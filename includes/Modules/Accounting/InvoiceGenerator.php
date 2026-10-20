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
use Lindenweg16\Core\HashChain;
use Lindenweg16\Core\AuditLog;

class InvoiceGenerator {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_rechnungen';
	}

	public static function generate_for_order( $order_id ) {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order_id ) ); // phpcs:ignore
		if ( $existing ) {
			// Pro Bestellung gibt es genau eine Rechnung (eindeutiger Index). Wird
			// eine stornierte Bestellung erneut abgeschlossen, bleibt die
			// stornierte stehen – das sagen wir, statt still nichts zu tun.
			if ( (int) $existing->storniert === 1 && ! $order->get_meta( '_lw16_rechnung_storno_hinweis' ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: Rechnungsnummer. */
						__( 'Rechnung %s ist storniert. Für diese Bestellung wird keine neue Rechnung erzeugt – bitte manuell nachbuchen.', 'lindenweg16' ),
						$existing->rechnungsnummer
					)
				);
				$order->update_meta_data( '_lw16_rechnung_storno_hinweis', 1 );
				$order->save();
			}
			return (int) $existing->id;
		}

		$jahr = (int) gmdate( 'Y', $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() );

		$modus = Settings::get( 'rechnungsnummer_modus', 'jahr' );
		if ( 'durchlaufend' === $modus ) {
			$laufnummer = 1 + (int) $wpdb->get_var( 'SELECT COALESCE(MAX(laufnummer),0) FROM ' . self::table() ); // phpcs:ignore
		} else {
			$laufnummer = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(laufnummer),0) FROM ' . self::table() . ' WHERE jahr = %d', $jahr ) ); // phpcs:ignore
		}
		$nummer = \Lindenweg16\Core\Calc::rechnungsnummer( $modus, $jahr, $laufnummer );

		// Dateiname VOR dem Insert generieren (für Hash-Kette).
		$filename = 'rechnung-' . $nummer . '-' . wp_generate_password( 8, false ) . '.pdf';

		$data = array(
			'order_id'        => $order_id,
			'rechnungsnummer' => $nummer,
			'jahr'            => $jahr,
			'laufnummer'      => $laufnummer,
			'datei_pfad'      => $filename,
			'betrag'          => (float) $order->get_total(),
			'erstellt_am'     => current_time( 'mysql' ),
		);

		// Hash-Verkettung.
		$rechnung_id = HashChain::insert( 'rechnungen', $data );

		if ( ! $rechnung_id ) {
			return false;
		}

		// PDF erzeugen und speichern.
		$path = trailingslashit( Installer::docs_dir() ) . $filename;
		self::build_pdf( $order, $nummer )->save( $path );

		$order->update_meta_data( '_lw16_invoice_number', $nummer );
		$order->save();

		AuditLog::record( 'rechnung_erstellt', 'rechnung', $rechnung_id, array(
			'rechnungsnummer' => $nummer,
			'betrag'          => (float) $order->get_total(),
		) );

		// Buchung im Journal erzeugen.
		$rechnung = self::get( $rechnung_id );
		if ( $rechnung ) {
			BuchungRepository::create_from_rechnung( $rechnung, $order );
		}

		return $rechnung_id;
	}

	/**
	 * Rechnungs-PDF im CI-Design: Logo links, Firmendaten rechts,
	 * Tabelle mit Pos/Beschreibung/Menge/Einzelpr./Gesamt, Summenblock,
	 * §19-Hinweis, Zahlungsinfo mit IBAN/BIC.
	 */
	protected static function build_pdf( $order, $nummer ) {
		$pdf = new Pdf();
		$pdf->enable_ci();

		$datum = $order->get_date_created()
			? $order->get_date_created()->date_i18n( 'd.m.Y' )
			: gmdate( 'd.m.Y' );

		// Titel + Rechnungsmeta rechts.
		$start_y = $pdf->get_y();
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->text( __( 'Rechnung', 'lindenweg16' ), 22, true );

		$pdf->set_color( '#6E7882' );
		$meta_x = Pdf::PAGE_WIDTH - Pdf::MARGIN;
		$pdf->text_xy_right( __( 'Rechnungsnr.', 'lindenweg16' ) . ' ' . $nummer, $meta_x, $start_y, 10 );
		$pdf->text_xy_right( __( 'Datum', 'lindenweg16' ) . ' ' . $datum, $meta_x, $start_y + 16, 10 );
		$pdf->text_xy_right( __( 'Bestellnr.', 'lindenweg16' ) . ' #' . $order->get_order_number(), $meta_x, $start_y + 32, 10 );
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->space( 12 );

		// Rechnungsadresse.
		$pdf->set_color( Pdf::COLOR_COGNAC );
		$pdf->text( mb_strtoupper( __( 'Rechnungsadresse', 'lindenweg16' ) ), 8, true );
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->space( 2 );

		$name = trim( $order->get_formatted_billing_full_name() );
		if ( $name ) {
			$pdf->text( $name, 11 );
		}
		$adr1 = $order->get_billing_address_1();
		if ( $adr1 ) {
			$pdf->text( $adr1, 11 );
		}
		$plz_city = trim( $order->get_billing_postcode() . ' ' . $order->get_billing_city() );
		if ( $plz_city ) {
			$pdf->text( $plz_city, 11 );
		}
		$pdf->space( 12 );

		// Tabellenkopf.
		$widths = array( 30, 220, 50, 70, 70 );
		$aligns = array( 'l', 'l', 'r', 'r', 'r' );

		$pdf->set_color( '#6E7882' );
		$pdf->table_cols(
			array(
				mb_strtoupper( __( 'Pos', 'lindenweg16' ) ),
				mb_strtoupper( __( 'Beschreibung', 'lindenweg16' ) ),
				mb_strtoupper( __( 'Menge', 'lindenweg16' ) ),
				mb_strtoupper( __( 'Einzelpr.', 'lindenweg16' ) ),
				mb_strtoupper( __( 'Gesamt', 'lindenweg16' ) ),
			),
			$widths,
			$aligns,
			8,
			true
		);
		$pdf->set_draw_color( Pdf::COLOR_TINTE );
		$pdf->hr( 1.5 );
		$pdf->set_color( Pdf::COLOR_TINTE );

		// Positionen.
		$pos_nr = 0;
		foreach ( $order->get_items() as $item ) {
			$pos_nr++;
			$qty        = max( 1, $item->get_quantity() );
			$einzelpreis = (float) $item->get_total() / $qty;

			$pdf->table_cols(
				array(
					(string) $pos_nr,
					$item->get_name(),
					(string) $item->get_quantity(),
					self::money( $einzelpreis ),
					self::money( (float) $item->get_total() ),
				),
				$widths,
				$aligns,
				10
			);
			$pdf->set_draw_color( '#E0E0E0' );
			$pdf->hr( 0.3 );
		}

		if ( (float) $order->get_shipping_total() > 0 ) {
			$pos_nr++;
			$pdf->table_cols(
				array(
					(string) $pos_nr,
					__( 'Versand', 'lindenweg16' ),
					'1',
					self::money( (float) $order->get_shipping_total() ),
					self::money( (float) $order->get_shipping_total() ),
				),
				$widths,
				$aligns,
				10
			);
			$pdf->set_draw_color( '#E0E0E0' );
			$pdf->hr( 0.3 );
		}

		$pdf->space( 8 );

		// Summenblock (rechts ausgerichtet).
		$sum_x = Pdf::PAGE_WIDTH - Pdf::MARGIN - 180;
		$sum_y = $pdf->get_y();
		$kleinunternehmer = (int) Settings::get( 'kleinunternehmer', 1 ) === 1;

		$pdf->set_color( '#6E7882' );
		if ( ! $kleinunternehmer ) {
			$subtotal = (float) $order->get_subtotal() + (float) $order->get_shipping_total();
			$tax      = (float) $order->get_total_tax();

			$pdf->text_xy( __( 'Zwischensumme', 'lindenweg16' ), $sum_x, $sum_y, 10 );
			$pdf->text_xy_right( self::money( $subtotal ), Pdf::PAGE_WIDTH - Pdf::MARGIN, $sum_y, 10 );
			$sum_y += 16;

			$pdf->text_xy( __( 'MwSt.', 'lindenweg16' ), $sum_x, $sum_y, 10 );
			$pdf->text_xy_right( self::money( $tax ), Pdf::PAGE_WIDTH - Pdf::MARGIN, $sum_y, 10 );
			$sum_y += 18;
		}

		$pdf->set_y( $sum_y );
		$pdf->set_draw_color( Pdf::COLOR_TINTE );
		$pdf->hr( 0.8 );
		$sum_y = $pdf->get_y();

		$pdf->set_color( Pdf::COLOR_COGNAC );
		$pdf->text_xy( __( 'Gesamtbetrag', 'lindenweg16' ), $sum_x, $sum_y, 14, true );
		$pdf->text_xy_right( self::money( (float) $order->get_total() ), Pdf::PAGE_WIDTH - Pdf::MARGIN, $sum_y, 14, true );
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->set_y( $sum_y + 28 );

		// §19-Hinweis.
		if ( $kleinunternehmer ) {
			$pdf->set_color( '#6E7882' );
			$pdf->text( __( 'Gemäß §19 UStG (Kleinunternehmerregelung) wird keine Umsatzsteuer berechnet.', 'lindenweg16' ), 9 );
			$pdf->set_color( Pdf::COLOR_TINTE );
		}

		$pdf->space( 24 );

		// Zahlungshinweis / IBAN.
		$pdf->set_color( '#6E7882' );
		$iban = (string) Settings::get( 'firma_iban' );
		$bic  = (string) Settings::get( 'firma_bic' );
		$zahlungs_text = __( 'Zahlbar innerhalb von 14 Tagen ohne Abzug.', 'lindenweg16' );
		if ( $iban ) {
			$zahlungs_text .= ' IBAN ' . $iban;
		}
		if ( $bic ) {
			$zahlungs_text .= ' · BIC ' . $bic;
		}
		$pdf->text( $zahlungs_text, 9 );
		$pdf->space( 6 );

		$pdf->set_color( Pdf::COLOR_COGNAC );
		$pdf->text( __( 'Vielen Dank für Deinen Einkauf bei Lindenweg16!', 'lindenweg16' ), 10 );
		$pdf->set_color( Pdf::COLOR_TINTE );

		return $pdf;
	}

	/**
	 * Rechnung stornieren (Gegenbuchung, kein Löschen).
	 *
	 * @param int    $id    Rechnungs-ID.
	 * @param string $grund Stornierungsgrund.
	 * @return bool
	 */
	public static function stornieren( $id, $grund = '' ) {
		global $wpdb;
		$rechnung = self::get( $id );
		if ( ! $rechnung || (int) $rechnung->storniert === 1 ) {
			return false;
		}

		// Original als storniert markieren (storniert nicht im Hash).
		$wpdb->update(
			self::table(),
			array( 'storniert' => 1, 'storno_grund' => $grund ),
			array( 'id' => (int) $id )
		);

		AuditLog::record( 'rechnung_storniert', 'rechnung', $id, array(
			'rechnungsnummer' => $rechnung->rechnungsnummer,
			'grund'           => $grund,
		) );

		// Zugehörige Buchung stornieren.
		$buchung = BuchungRepository::get_by_referenz( 'rechnung', $id );
		if ( $buchung ) {
			BuchungRepository::create_storno( (int) $buchung->id, $grund );
		}

		return true;
	}

	/**
	 * Paginierte Liste der Rechnungen.
	 *
	 * @param array $args jahr, per_page, page.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$t        = self::table();
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 50;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['jahr'] ) ) {
			$where   .= ' AND jahr = %d';
			$params[] = (int) $args['jahr'];
		}

		$sql      = "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	/**
	 * Gesamtanzahl Rechnungen.
	 *
	 * @param array $args jahr.
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;
		$t = self::table();
		if ( ! empty( $args['jahr'] ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE jahr = %d", (int) $args['jahr'] ) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore
	}

	public static function get_by_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order_id ) ); // phpcs:ignore
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	protected static function money( $n ) {
		return number_format( (float) $n, 2, ',', '.' ) . ' €';
	}
}
