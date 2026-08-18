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
use Lindenweg16\Core\Format;
use Lindenweg16\Core\Time;

class InvoiceGenerator {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lw16_rechnungen';
	}

	/**
	 * Name des MySQL-Locks für die Nummernvergabe (max. 64 Zeichen).
	 */
	const LOCK_NAME = 'lw16_invoice_number';

	/**
	 * Rechnung für eine Bestellung erzeugen (idempotent).
	 *
	 * @param int $order_id Bestell-ID.
	 * @return int|false Rechnungs-ID oder false.
	 */
	public static function generate_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$existing = self::get_by_order( $order_id );
		if ( $existing ) {
			// Reparaturpfad: Wenn beim ersten Versuch die PDF-Erzeugung
			// scheiterte, existiert der Datensatz ohne Datei. Ohne diesen
			// Zweig würde die Nummer für immer ohne Dokument bleiben.
			if ( empty( $existing->datei_pfad ) ) {
				self::attach_pdf( (int) $existing->id, $existing->rechnungsnummer, $order );
			}
			return (int) $existing->id;
		}

		$rechnung = self::reserve_number( $order );
		if ( ! $rechnung ) {
			return false;
		}

		self::attach_pdf( $rechnung['id'], $rechnung['nummer'], $order );

		$order->update_meta_data( '_lw16_invoice_number', $rechnung['nummer'] );
		$order->save();

		return $rechnung['id'];
	}

	/**
	 * Rechnungsnummer unter einem Datenbank-Lock vergeben.
	 *
	 * „MAX(laufnummer) + 1" gefolgt von einem INSERT ist ohne Sperre nicht
	 * eindeutig: zwei parallele Action-Scheduler-Läufe ermitteln dieselbe
	 * Nummer, der zweite INSERT scheitert am UNIQUE-Index und die Bestellung
	 * bleibt stillschweigend ohne Rechnung.
	 *
	 * @param \WC_Order $order Bestellung.
	 * @return array|false {id, nummer}
	 */
	protected static function reserve_number( $order ) {
		global $wpdb;

		$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, 10 ) );

		try {
			// Innerhalb der Sperre erneut prüfen – ein paralleler Lauf kann
			// die Rechnung inzwischen angelegt haben.
			$existing = self::get_by_order( $order->get_id() );
			if ( $existing ) {
				return array(
					'id'     => (int) $existing->id,
					'nummer' => $existing->rechnungsnummer,
				);
			}

			$created = $order->get_date_created();
			$jahr    = (int) ( $created ? $created->date( 'Y' ) : Time::year() );

			$modus = Settings::get( 'rechnungsnummer_modus', 'jahr' );
			$modus = 'durchlaufend' === $modus ? 'durchlaufend' : 'jahr';

			if ( 'durchlaufend' === $modus ) {
				$laufnummer = 1 + (int) $wpdb->get_var( 'SELECT COALESCE(MAX(laufnummer),0) FROM ' . self::table() ); // phpcs:ignore
			} else {
				$laufnummer = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(laufnummer),0) FROM ' . self::table() . ' WHERE jahr = %d', $jahr ) ); // phpcs:ignore
			}
			$nummer = \Lindenweg16\Core\Calc::rechnungsnummer( $modus, $jahr, $laufnummer );

			$ok = $wpdb->insert(
				self::table(),
				array(
					'order_id'        => $order->get_id(),
					'rechnungsnummer' => $nummer,
					'jahr'            => $jahr,
					'laufnummer'      => $laufnummer,
					'betrag'          => (float) $order->get_total(),
					'erstellt_am'     => Time::now(),
				),
				array( '%d', '%s', '%d', '%d', '%f', '%s' )
			);

			if ( ! $ok ) {
				return false;
			}

			return array(
				'id'     => (int) $wpdb->insert_id,
				'nummer' => $nummer,
			);
		} finally {
			if ( 1 === $locked ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) ); // phpcs:ignore
			}
		}
	}

	/**
	 * PDF erzeugen und mit dem Rechnungsdatensatz verknüpfen.
	 *
	 * Schlägt das Schreiben fehl, bleibt `datei_pfad` leer, damit ein späterer
	 * Aufruf es erneut versuchen kann.
	 *
	 * @param int       $rechnung_id Rechnungs-ID.
	 * @param string    $nummer      Rechnungsnummer.
	 * @param \WC_Order $order       Bestellung.
	 * @return bool
	 */
	protected static function attach_pdf( $rechnung_id, $nummer, $order ) {
		global $wpdb;

		$dir = Installer::docs_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$filename = 'rechnung-' . sanitize_file_name( $nummer ) . '-' . wp_generate_password( 8, false ) . '.pdf';
		$path     = trailingslashit( $dir ) . $filename;

		if ( ! self::build_pdf( $order, $nummer )->save( $path ) ) {
			return false;
		}

		$wpdb->update( self::table(), array( 'datei_pfad' => $filename ), array( 'id' => (int) $rechnung_id ) );
		return true;
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

		// Positionen. Alle Beträge brutto (inkl. Steuer), damit die Summe der
		// Zeilen exakt dem Gesamtbetrag entspricht – vorher wurden Netto-
		// Zeilenwerte gegen einen Brutto-Gesamtbetrag gestellt, und Coupons
		// sowie Gebühren (u. a. die eigene Guthaben-Einlösung) fehlten ganz.
		$pos_nr       = 0;
		$zeilen_summe = 0.0;

		foreach ( $order->get_items() as $item ) {
			$pos_nr++;
			$qty         = (float) $item->get_quantity();
			$brutto      = (float) $item->get_total() + (float) $item->get_total_tax();
			$einzelpreis = $qty > 0 ? $brutto / $qty : $brutto;
			$zeilen_summe += $brutto;

			$pdf->table_cols(
				array(
					(string) $pos_nr,
					$item->get_name(),
					Format::qty( $qty ),
					self::money( $einzelpreis ),
					self::money( $brutto ),
				),
				$widths,
				$aligns,
				10
			);
			$pdf->set_draw_color( '#E0E0E0' );
			$pdf->hr( 0.3 );
		}

		$versand = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		if ( abs( $versand ) > 0.0049 ) {
			$pos_nr++;
			$zeilen_summe += $versand;
			$pdf->table_cols(
				array(
					(string) $pos_nr,
					__( 'Versand', 'lindenweg16' ),
					'1',
					self::money( $versand ),
					self::money( $versand ),
				),
				$widths,
				$aligns,
				10
			);
			$pdf->set_draw_color( '#E0E0E0' );
			$pdf->hr( 0.3 );
		}

		// Gebühren und Gutschriften (negative Gebühren, z. B. eingelöstes
		// Guthaben) gehören auf die Rechnung, sonst geht sie nicht auf.
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$brutto = (float) $fee->get_total() + (float) $fee->get_total_tax();
			if ( abs( $brutto ) < 0.0049 ) {
				continue;
			}
			$pos_nr++;
			$zeilen_summe += $brutto;
			$pdf->table_cols(
				array(
					(string) $pos_nr,
					$fee->get_name() ? $fee->get_name() : __( 'Gebühr', 'lindenweg16' ),
					'1',
					self::money( $brutto ),
					self::money( $brutto ),
				),
				$widths,
				$aligns,
				10
			);
			$pdf->set_draw_color( '#E0E0E0' );
			$pdf->hr( 0.3 );
		}

		$pdf->space( 4 );

		// Gewährte Rabatte sind in den Zeilensummen bereits berücksichtigt und
		// werden nur informativ ausgewiesen.
		$rabatt = (float) $order->get_discount_total() + (float) $order->get_discount_tax();
		if ( $rabatt > 0.0049 ) {
			$pdf->set_color( '#6E7882' );
			$codes = $order->get_coupon_codes();
			$label = __( 'enthaltener Rabatt', 'lindenweg16' );
			if ( ! empty( $codes ) ) {
				$label .= ' (' . implode( ', ', $codes ) . ')';
			}
			$pdf->row( $label, '-' . self::money( $rabatt ), 9 );
			$pdf->set_color( Pdf::COLOR_TINTE );
		}

		$pdf->space( 8 );

		// Summenblock (rechts ausgerichtet).
		$sum_x = Pdf::PAGE_WIDTH - Pdf::MARGIN - 180;
		$sum_y = $pdf->get_y();
		$kleinunternehmer = (int) Settings::get( 'kleinunternehmer', 1 ) === 1;

		$pdf->set_color( '#6E7882' );

		$gesamt = (float) $order->get_total();

		// Falls Zeilensumme und Gesamtbetrag auseinanderlaufen (etwa durch
		// Rundung fremder Plugins), wird die Differenz offen ausgewiesen statt
		// stillschweigend verschluckt.
		$differenz = $gesamt - $zeilen_summe;
		if ( abs( $differenz ) > 0.0099 ) {
			$pdf->text_xy( __( 'Rundungsdifferenz', 'lindenweg16' ), $sum_x, $sum_y, 10 );
			$pdf->text_xy_right( self::money( $differenz ), Pdf::PAGE_WIDTH - Pdf::MARGIN, $sum_y, 10 );
			$sum_y += 16;
		}

		if ( ! $kleinunternehmer ) {
			$tax   = (float) $order->get_total_tax();
			$netto = $gesamt - $tax;

			$pdf->text_xy( __( 'Nettobetrag', 'lindenweg16' ), $sum_x, $sum_y, 10 );
			$pdf->text_xy_right( self::money( $netto ), Pdf::PAGE_WIDTH - Pdf::MARGIN, $sum_y, 10 );
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

	public static function get_by_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order_id ) ); // phpcs:ignore
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	protected static function money( $n ) {
		return Format::euro( $n );
	}
}
