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

	/**
	 * Pickliste für eine Bestellung erstellen und als PDF speichern.
	 *
	 * @param int $order_id WooCommerce-Bestell-ID.
	 * @return int|false Picklisten-ID oder false.
	 */
	public static function generate( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$lagerorte = Repository::get_lagerorte();
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

	/**
	 * Bestellpositionen in Picklisten-Positionen auflösen.
	 * Sets werden in ihre Module aufgelöst. Jedem Produkt wird
	 * der Lagerort mit dem höchsten Bestand zugewiesen.
	 */
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

	/**
	 * Lagerort mit dem höchsten Bestand für ein Produkt finden.
	 */
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
	 * PDF für eine Pickliste generieren.
	 *
	 * @param int    $pickliste_id Picklisten-ID.
	 * @param object $order        WooCommerce-Order.
	 * @return string|false Dateiname oder false.
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

		$pdf->text( __( 'Pickliste', 'lindenweg16' ), 18, true );
		$pdf->space( 6 );

		$pdf->row( __( 'Pickliste Nr.:', 'lindenweg16' ), '#' . $pickliste_id, 11, true );
		$pdf->row( __( 'Bestellung:', 'lindenweg16' ), '#' . $order->get_order_number(), 11 );
		$datum = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd.m.Y' ) : gmdate( 'd.m.Y' );
		$pdf->row( __( 'Bestelldatum:', 'lindenweg16' ), $datum, 11 );
		$pdf->row( __( 'Erstellt am:', 'lindenweg16' ), wp_date( 'd.m.Y H:i', strtotime( $pickliste->erstellt_am ) ), 11 );
		$pdf->space( 6 );

		$kunde = trim( $order->get_shipping_full_name() ?: $order->get_billing_full_name() );
		if ( $kunde ) {
			$pdf->row( __( 'Kunde:', 'lindenweg16' ), $kunde, 11 );
		}
		$pdf->space( 8 );

		$pdf->hr();
		$pdf->text(
			self::pad( __( 'Lagerort', 'lindenweg16' ), 12 )
			. self::pad( __( 'Artikel', 'lindenweg16' ), 30 )
			. self::pad( __( 'Menge', 'lindenweg16' ), 10 )
			. self::pad( __( 'Einheit', 'lindenweg16' ), 10 )
			. '[ ] ' . __( 'OK', 'lindenweg16' ),
			10,
			true
		);
		$pdf->hr();

		$current_ort = null;
		foreach ( $positionen as $pos ) {
			$ort_label = $pos->lagerort_kuerzel ?: '—';

			if ( $ort_label !== $current_ort ) {
				if ( null !== $current_ort ) {
					$pdf->space( 6 );
				}
				$current_ort = $ort_label;
			}

			$menge_str = self::fmt( $pos->soll_menge );
			$pdf->text(
				self::pad( $ort_label, 12 )
				. self::pad( $pos->produkt_name ?: '?', 30 )
				. self::pad( $menge_str, 10 )
				. self::pad( $pos->basis_einheit ?: '', 10 )
				. '[ ]',
				10
			);
		}

		$pdf->space( 16 );
		$pdf->hr();
		$pdf->space( 8 );
		$pdf->row(
			__( 'Positionen gesamt:', 'lindenweg16' ),
			(string) count( $positionen ),
			11,
			true
		);
		$pdf->space( 20 );

		$pdf->text( __( 'Gepickt von: ________________________', 'lindenweg16' ), 11 );
		$pdf->space( 12 );
		$pdf->text( __( 'Datum/Uhrzeit: ________________________', 'lindenweg16' ), 11 );

		$filename = 'pickliste-' . $pickliste_id . '-' . $order->get_order_number() . '.pdf';
		$path     = trailingslashit( Installer::docs_dir() ) . $filename;
		$pdf->save( $path );

		return $filename;
	}

	protected static function fmt( $n ) {
		$s = number_format( (float) $n, 2, ',', '.' );
		return rtrim( rtrim( $s, '0' ), ',' );
	}

	protected static function pad( $str, $width ) {
		$len = strlen( $str );
		if ( $len >= $width ) {
			return substr( $str, 0, $width - 1 ) . ' ';
		}
		return $str . str_repeat( ' ', $width - $len );
	}
}
