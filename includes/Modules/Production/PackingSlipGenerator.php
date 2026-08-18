<?php
/**
 * Packliste / Versandschein als PDF (mit Stücklisten-Auflösung).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Production;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Installer;
use Lindenweg16\Core\Pdf;
use Lindenweg16\Core\Format;
use Lindenweg16\Modules\Inventory\Repository;

/**
 * Erzeugt eine Packliste je Bestellung, bei Sets zusätzlich in die
 * enthaltenen Einzelmodule aufgelöst. Bewusst ohne Preise.
 */
class PackingSlipGenerator {

	/**
	 * Packliste erzeugen und geschützt ablegen.
	 */
	public static function generate( $order_id, $notiz = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$pdf = new Pdf();
		$pdf->enable_ci();

		$datum = $order->get_date_created()
			? $order->get_date_created()->date_i18n( 'd.m.Y' )
			: gmdate( 'd.m.Y' );

		// Titel + Meta rechts.
		$start_y = $pdf->get_y();
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->text( __( 'Lieferschein', 'lindenweg16' ), 22, true );

		$meta_x = Pdf::PAGE_WIDTH - Pdf::MARGIN;
		$pdf->set_color( '#6E7882' );
		$pdf->text_xy_right( __( 'Bestellnr.', 'lindenweg16' ) . ' #' . $order->get_order_number(), $meta_x, $start_y, 10 );
		$pdf->text_xy_right( __( 'Datum', 'lindenweg16' ) . ' ' . $datum, $meta_x, $start_y + 16, 10 );
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->space( 12 );

		// Lieferadresse.
		$pdf->set_color( Pdf::COLOR_COGNAC );
		$pdf->text( mb_strtoupper( __( 'Lieferadresse', 'lindenweg16' ) ), 8, true );
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->space( 2 );

		$name = trim( $order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name() );
		if ( $name ) {
			$pdf->text( $name, 11 );
		}
		$adr = array(
			$order->get_shipping_address_1() ?: $order->get_billing_address_1(),
			trim( ( $order->get_shipping_postcode() ?: $order->get_billing_postcode() ) . ' ' . ( $order->get_shipping_city() ?: $order->get_billing_city() ) ),
		);
		foreach ( array_filter( $adr ) as $line ) {
			$pdf->text( $line, 11 );
		}
		$pdf->space( 12 );

		// Tabellenkopf.
		$widths = array( 30, 350, 60 );
		$aligns = array( 'l', 'l', 'r' );

		$pdf->set_color( '#6E7882' );
		$pdf->table_cols(
			array(
				mb_strtoupper( __( 'Pos', 'lindenweg16' ) ),
				mb_strtoupper( __( 'Artikel', 'lindenweg16' ) ),
				mb_strtoupper( __( 'Menge', 'lindenweg16' ) ),
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
			$qty     = (float) $item->get_quantity();
			$produkt = Repository::get_by_woo_id( $item->get_product_id() );

			$pdf->table_cols(
				array( (string) $pos_nr, $item->get_name(), Format::qty( $qty ) ),
				$widths,
				$aligns,
				10,
				true
			);
			$pdf->set_draw_color( '#E0E0E0' );
			$pdf->hr( 0.3 );

			if ( $produkt && 'set' === $produkt->typ ) {
				foreach ( self::resolve_modules( $produkt, $qty ) as $mod_name => $menge ) {
					$pdf->set_color( '#6E7882' );
					$pdf->table_cols(
						array( '', "\xE2\x80\x94 " . $mod_name, Format::qty( $menge ) ),
						$widths,
						$aligns,
						9
					);
					$pdf->set_color( Pdf::COLOR_TINTE );
				}
			}
		}

		$pdf->space( 20 );

		if ( $notiz ) {
			$pdf->set_color( '#6E7882' );
			$pdf->text( __( 'Hinweis: ', 'lindenweg16' ) . $notiz, 10 );
			$pdf->set_color( Pdf::COLOR_TINTE );
		}
		$pdf->space( 8 );
		$pdf->set_color( '#6E7882' );
		$pdf->text( __( 'Bitte prüfe die Sendung bei Erhalt auf Vollständigkeit. Bei Fragen sind wir jederzeit für dich da.', 'lindenweg16' ), 10 );
		$pdf->set_color( Pdf::COLOR_TINTE );

		// Dateinamen bereinigen: Bestellnummern koennen durch Fremd-Plugins
		// Schraegstriche oder andere Sonderzeichen enthalten.
		$filename = sanitize_file_name( 'packliste-' . $order->get_order_number() . '-' . wp_generate_password( 6, false ) . '.pdf' );
		$dir      = Installer::docs_dir();
		$path     = trailingslashit( $dir ) . $filename;

		if ( ! $pdf->save( $path ) ) {
			return false;
		}

		// Vorgaengerdatei entfernen. Die Packliste wird bei jedem Abruf neu
		// erzeugt; ohne dieses Aufraeumen wuchs das Dokumentenverzeichnis mit
		// jedem Klick um eine verwaiste PDF.
		$alt = (string) $order->get_meta( '_lw16_packing_slip' );
		if ( $alt && $alt !== $filename ) {
			$alt_pfad = trailingslashit( $dir ) . basename( $alt );
			if ( is_file( $alt_pfad ) ) {
				@unlink( $alt_pfad ); // phpcs:ignore
			}
		}

		$order->update_meta_data( '_lw16_packing_slip', $filename );
		$order->save();

		return $filename;
	}

	public static function resolve_modules( $produkt, $menge, array $acc = array() ) {
		foreach ( Repository::get_stueckliste( $produkt->id ) as $pos ) {
			$modul = Repository::get_product( $pos->modul_id );
			if ( ! $modul ) {
				continue;
			}
			$menge_modul = $menge * (float) $pos->menge;
			if ( 'set' === $modul->typ ) {
				$acc = self::resolve_modules( $modul, $menge_modul, $acc );
			} else {
				$acc[ $modul->name ] = ( $acc[ $modul->name ] ?? 0 ) + $menge_modul;
			}
		}
		return $acc;
	}

}
