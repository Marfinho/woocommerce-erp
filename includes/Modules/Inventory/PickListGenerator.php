<?php
/**
 * Picklisten-PDF-Generator.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Inventory;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Installer;
use Lindenweg16\Core\Settings;
use Lindenweg16\Core\Pdf;

class PickListGenerator {

	public static function generate( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$lagerorte  = Repository::get_lagerorte();
		$positionen = self::resolve_order_items( $order, $lagerorte );

		if ( empty( $positionen ) ) {
			return false;
		}

		$pickliste_id = Repository::create_pickliste( array(
			'order_id'     => $order_id,
			'status'       => 'offen',
			'erstellt_von' => get_current_user_id(),
		) );

		foreach ( $positionen as $pos ) {
			Repository::add_pickliste_pos( $pickliste_id, $pos );
		}

		$filename = self::generate_pdf( $pickliste_id, $order );

		if ( $filename ) {
			Repository::update_pickliste( $pickliste_id, array( 'datei_pfad' => $filename ) );
		}

		return $pickliste_id;
	}

	protected static function resolve_order_items( $order, $lagerorte ) {
		$positionen = array();

		foreach ( $order->get_items() as $item ) {
			$woo_id  = $item->get_product_id();
			$produkt = Repository::get_by_woo_id( $woo_id );
			$qty     = (float) $item->get_quantity();

			if ( ! $produkt ) {
				continue;
			}

			if ( 'set' === $produkt->typ ) {
				foreach ( Repository::get_stueckliste( $produkt->id ) as $bom ) {
					$modul = Repository::get_product( $bom->modul_id );
					if ( ! $modul ) {
						continue;
					}
					$modul_menge = $qty * (float) $bom->menge;
					$positionen[] = array(
						'produkt_id'  => (int) $modul->id,
						'lagerort_id' => self::best_location( $modul->id, $lagerorte ),
						'soll_menge'  => $modul_menge,
					);
				}
			} else {
				$positionen[] = array(
					'produkt_id'  => (int) $produkt->id,
					'lagerort_id' => self::best_location( $produkt->id, $lagerorte ),
					'soll_menge'  => $qty,
				);
			}
		}

		return $positionen;
	}

	protected static function best_location( $produkt_id, $lagerorte ) {
		if ( empty( $lagerorte ) ) {
			return null;
		}

		$best_id    = null;
		$best_menge = -1;

		foreach ( $lagerorte as $ort ) {
			$menge = Repository::get_stock_at_location( $produkt_id, $ort->id );
			if ( $menge > $best_menge ) {
				$best_menge = $menge;
				$best_id    = (int) $ort->id;
			}
		}

		return $best_id;
	}

	/**
	 * Picklisten-PDF im CI-Design.
	 */
	public static function generate_pdf( $pickliste_id, $order = null ) {
		$pickliste = Repository::get_pickliste( $pickliste_id );
		if ( ! $pickliste ) {
			return false;
		}

		if ( ! $order ) {
			$order = wc_get_order( $pickliste->order_id );
		}
		if ( ! $order ) {
			return false;
		}

		$positionen = Repository::get_pickliste_positionen( $pickliste_id );

		$pdf = new Pdf();
		$pdf->enable_ci();

		$datum = $order->get_date_created()
			? $order->get_date_created()->date_i18n( 'd.m.Y' )
			: gmdate( 'd.m.Y' );

		// Titel + Meta.
		$start_y = $pdf->get_y();
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->text( __( 'Pickliste', 'lindenweg16' ), 22, true );

		$meta_x = Pdf::PAGE_WIDTH - Pdf::MARGIN;
		$pdf->set_color( '#6E7882' );
		$pdf->text_xy_right( __( 'Pickliste', 'lindenweg16' ) . ' #' . $pickliste_id, $meta_x, $start_y, 10 );
		$pdf->text_xy_right( __( 'Bestellnr.', 'lindenweg16' ) . ' #' . $order->get_order_number(), $meta_x, $start_y + 16, 10 );
		$pdf->text_xy_right( __( 'Datum', 'lindenweg16' ) . ' ' . $datum, $meta_x, $start_y + 32, 10 );
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->space( 8 );

		$kunde = trim( $order->get_shipping_full_name() ?: $order->get_billing_full_name() );
		if ( $kunde ) {
			$pdf->set_color( Pdf::COLOR_COGNAC );
			$pdf->text( mb_strtoupper( __( 'Kunde', 'lindenweg16' ) ), 8, true );
			$pdf->set_color( Pdf::COLOR_TINTE );
			$pdf->space( 2 );
			$pdf->text( $kunde, 11 );
			$pdf->space( 8 );
		}

		// Tabellenkopf.
		$widths = array( 70, 200, 60, 60, 40 );
		$aligns = array( 'l', 'l', 'r', 'l', 'l' );

		$pdf->set_color( '#6E7882' );
		$pdf->table_cols(
			array(
				mb_strtoupper( __( 'Lagerort', 'lindenweg16' ) ),
				mb_strtoupper( __( 'Artikel', 'lindenweg16' ) ),
				mb_strtoupper( __( 'Menge', 'lindenweg16' ) ),
				mb_strtoupper( __( 'Einheit', 'lindenweg16' ) ),
				'[ ] ' . mb_strtoupper( __( 'OK', 'lindenweg16' ) ),
			),
			$widths,
			$aligns,
			8,
			true
		);
		$pdf->set_draw_color( Pdf::COLOR_TINTE );
		$pdf->hr( 1.5 );
		$pdf->set_color( Pdf::COLOR_TINTE );

		// Positionen, nach Lagerort gruppiert.
		$current_ort = null;
		foreach ( $positionen as $pos ) {
			$ort_label = $pos->lagerort_kuerzel ?: "\xE2\x80\x94";

			if ( $ort_label !== $current_ort ) {
				if ( null !== $current_ort ) {
					$pdf->space( 6 );
				}
				$current_ort = $ort_label;
			}

			$menge_str = self::fmt( $pos->soll_menge );
			$pdf->table_cols(
				array(
					$ort_label,
					$pos->produkt_name ?: '?',
					$menge_str,
					$pos->basis_einheit ?: '',
					'[ ]',
				),
				$widths,
				$aligns,
				10
			);
			$pdf->set_draw_color( '#E0E0E0' );
			$pdf->hr( 0.3 );
		}

		$pdf->space( 16 );
		$pdf->set_draw_color( Pdf::COLOR_TINTE );
		$pdf->hr( 0.8 );
		$pdf->space( 8 );

		$pdf->set_color( Pdf::COLOR_COGNAC );
		$pdf->row(
			__( 'Positionen gesamt:', 'lindenweg16' ),
			(string) count( $positionen ),
			11,
			true
		);
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->space( 24 );

		$pdf->set_color( '#6E7882' );
		$pdf->text( __( 'Gepickt von: ________________________', 'lindenweg16' ), 11 );
		$pdf->space( 12 );
		$pdf->text( __( 'Datum/Uhrzeit: ________________________', 'lindenweg16' ), 11 );
		$pdf->set_color( Pdf::COLOR_TINTE );

		$filename = 'pickliste-' . $pickliste_id . '-' . $order->get_order_number() . '.pdf';
		$path     = trailingslashit( Installer::docs_dir() ) . $filename;
		$pdf->save( $path );

		return $filename;
	}

	protected static function fmt( $n ) {
		$s = number_format( (float) $n, 2, ',', '.' );
		return rtrim( rtrim( $s, '0' ), ',' );
	}
}
