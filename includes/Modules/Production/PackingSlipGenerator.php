<?php
/**
 * Packliste / Versandschein als PDF (mit Stücklisten-Auflösung).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Production;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Installer;
use Lindenweg16\Core\Settings;
use Lindenweg16\Core\Pdf;
use Lindenweg16\Modules\Inventory\Repository;

/**
 * Erzeugt eine Packliste je Bestellung, bei Sets zusätzlich in die
 * enthaltenen Einzelmodule aufgelöst. Bewusst ohne Preise.
 */
class PackingSlipGenerator {

	/**
	 * Packliste erzeugen und geschützt ablegen. Immer neu generierbar.
	 *
	 * @param int    $order_id Bestell-ID.
	 * @param string $notiz    Optionale Notiz aufs Dokument.
	 * @return string|false Dateiname oder false.
	 */
	public static function generate( $order_id, $notiz = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$pdf = new Pdf();
		$pdf->text( __( 'Packliste / Lieferschein', 'lindenweg16' ), 16, true );
		$pdf->text( (string) Settings::get( 'firma_name' ), 10 );
		$pdf->space( 8 );

		$pdf->row( __( 'Bestellnummer:', 'lindenweg16' ), '#' . $order->get_order_number(), 11, true );
		$datum = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd.m.Y' ) : gmdate( 'd.m.Y' );
		$pdf->row( __( 'Bestelldatum:', 'lindenweg16' ), $datum, 11 );
		$pdf->space( 6 );

		// Lieferadresse.
		$pdf->text( __( 'Lieferadresse:', 'lindenweg16' ), 10, true );
		$pdf->text( trim( $order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name() ), 11 );
		$adr = array(
			$order->get_shipping_address_1() ?: $order->get_billing_address_1(),
			trim( ( $order->get_shipping_postcode() ?: $order->get_billing_postcode() ) . ' ' . ( $order->get_shipping_city() ?: $order->get_billing_city() ) ),
		);
		foreach ( array_filter( $adr ) as $line ) {
			$pdf->text( $line, 11 );
		}
		$pdf->space( 8 );
		$pdf->hr();

		$pdf->row( __( 'Artikel', 'lindenweg16' ), __( 'Menge', 'lindenweg16' ), 11, true );
		$pdf->hr();

		foreach ( $order->get_items() as $item ) {
			$qty     = (float) $item->get_quantity();
			$produkt = Repository::get_by_woo_id( $item->get_product_id() );

			$pdf->row( $item->get_name(), self::fmt( $qty ) . ' x', 11, true );

			// Set in Einzelmodule auflösen.
			if ( $produkt && 'set' === $produkt->typ ) {
				foreach ( self::resolve_modules( $produkt, $qty ) as $name => $menge ) {
					$pdf->row( '— ' . $name, self::fmt( $menge ) . ' x', 10, false );
				}
			}
		}

		$pdf->space( 12 );
		if ( $notiz ) {
			$pdf->text( __( 'Hinweis: ', 'lindenweg16' ) . $notiz, 10 );
		}
		$pdf->space( 6 );
		$pdf->text( __( 'Vielen Dank für Deine Bestellung!', 'lindenweg16' ), 10 );

		$filename = 'packliste-' . $order->get_order_number() . '-' . wp_generate_password( 6, false ) . '.pdf';
		$path     = trailingslashit( Installer::docs_dir() ) . $filename;
		$pdf->save( $path );

		$order->update_meta_data( '_lw16_packing_slip', $filename );
		$order->save();

		return $filename;
	}

	/**
	 * Ein Set rekursiv in Einzelmodule (Name => Gesamtmenge) auflösen.
	 *
	 * @param object $produkt Set-Produkt.
	 * @param float  $menge   Menge des Sets.
	 * @param array  $acc     Akkumulator.
	 * @return array
	 */
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

	/**
	 * Zahl formatieren.
	 *
	 * @param float $n Zahl.
	 * @return string
	 */
	protected static function fmt( $n ) {
		$s = number_format( (float) $n, 2, ',', '.' );
		return rtrim( rtrim( $s, '0' ), ',' );
	}
}
