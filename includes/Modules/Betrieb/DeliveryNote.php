<?php
/**
 * Lieferschein für Bestellungen außerhalb des Shops.
 *
 * WooCommerce-Bestellungen bekommen ihre Packliste vom Produktionsmodul.
 * Händlerbestellungen entstehen aber im Betrieb – manuell erfasst, über
 * den Nachbestell-Link oder als Kommissionsabrechnung. Sie kennen keine
 * WooCommerce-Bestellung und hatten deshalb bisher keinen Lieferschein.
 *
 * Preise stehen bewusst nicht darauf. Ein Lieferschein sagt, was in der
 * Kiste liegt; was es kostet, sagt die Rechnung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Core\Installer;
use Lindenweg16\Core\Pdf;

/**
 * Erzeugt das PDF und liefert den Dateinamen.
 */
class DeliveryNote {

	/**
	 * Anschrift eines Kontakts als Zeilen.
	 *
	 * Reine Logik. Leere Felder fallen weg, statt eine Leerzeile oder ein
	 * einsames Komma zu hinterlassen – ein Lieferschein mit Lücken in der
	 * Adresse sieht aus wie ein Fehler und wird auch als solcher gelesen.
	 *
	 * @param array $contact Kontakt.
	 * @return array
	 */
	public static function address_lines( array $contact ) {
		$lines = array();

		foreach ( array( 'company', 'contact_person', 'street' ) as $feld ) {
			$wert = isset( $contact[ $feld ] ) ? trim( (string) $contact[ $feld ] ) : '';

			if ( '' !== $wert ) {
				$lines[] = $wert;
			}
		}

		$ort = trim(
			( isset( $contact['zip'] ) ? trim( (string) $contact['zip'] ) : '' )
			. ' '
			. ( isset( $contact['city'] ) ? trim( (string) $contact['city'] ) : '' )
		);

		if ( '' !== $ort ) {
			$lines[] = $ort;
		}

		return $lines;
	}

	/**
	 * Fehlt der Anschrift etwas Wesentliches?
	 *
	 * @param array $contact Kontakt.
	 * @return bool
	 */
	public static function address_incomplete( array $contact ) {
		foreach ( array( 'street', 'zip', 'city' ) as $feld ) {
			if ( '' === trim( (string) ( isset( $contact[ $feld ] ) ? $contact[ $feld ] : '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Menge ohne überflüssige Nachkommastellen.
	 *
	 * @param float $qty Menge.
	 * @return string
	 */
	public static function fmt( $qty ) {
		$qty = (float) $qty;

		return abs( $qty - round( $qty ) ) < 0.001
			? number_format_i18n( $qty, 0 )
			: number_format_i18n( $qty, 2 );
	}

	/**
	 * Lieferschein erzeugen.
	 *
	 * @param int    $order_op_id Bestellung im Betrieb.
	 * @param string $note        Zusätzlicher Hinweis.
	 * @return string|false Dateiname oder false.
	 */
	public static function generate( $order_op_id, $note = '' ) {
		$order = OrderOpsRepository::find( $order_op_id );

		if ( ! $order ) {
			return false;
		}

		$contact  = $order['contact_id'] ? ContactRepository::find( (int) $order['contact_id'] ) : null;
		$items    = OrderOpsRepository::items( (int) $order['id'] );
		$articles = ArticleRepository::all_by_id();

		$pdf = new Pdf();
		$pdf->enable_ci();

		$start_y = $pdf->get_y();
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->text( __( 'Lieferschein', 'lindenweg16' ), 22, true );

		$meta_x = Pdf::PAGE_WIDTH - Pdf::MARGIN;
		$pdf->set_color( '#6E7882' );
		$pdf->text_xy_right( __( 'Bestellung', 'lindenweg16' ) . ' #' . (int) $order['id'], $meta_x, $start_y, 10 );
		$pdf->text_xy_right(
			__( 'Datum', 'lindenweg16' ) . ' ' . mysql2date( 'd.m.Y', $order['manual_order_date'] ? $order['manual_order_date'] : $order['created_at'] ),
			$meta_x,
			$start_y + 16,
			10
		);
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->space( 12 );

		$pdf->set_color( Pdf::COLOR_COGNAC );
		$pdf->text( mb_strtoupper( __( 'Lieferadresse', 'lindenweg16' ) ), 8, true );
		$pdf->set_color( Pdf::COLOR_TINTE );
		$pdf->space( 2 );

		foreach ( self::address_lines( (array) $contact ) as $line ) {
			$pdf->text( $line, 11 );
		}

		$pdf->space( 12 );

		$widths = array( 30, 260, 90, 60 );
		$aligns = array( 'l', 'l', 'l', 'r' );

		$pdf->set_color( '#6E7882' );
		$pdf->table_cols(
			array(
				mb_strtoupper( __( 'Pos', 'lindenweg16' ) ),
				mb_strtoupper( __( 'Artikel', 'lindenweg16' ) ),
				mb_strtoupper( __( 'Farbe', 'lindenweg16' ) ),
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

		$nr = 0;

		foreach ( $items as $item ) {
			$nr++;
			$article = isset( $articles[ (int) $item['article_id'] ] ) ? $articles[ (int) $item['article_id'] ] : null;

			$pdf->table_cols(
				array(
					(string) $nr,
					$article ? $article['sku'] . ' – ' . $article['name'] : '#' . (int) $item['article_id'],
					ColorRegistry::label( (string) $item['color'] ),
					self::fmt( $item['qty'] ),
				),
				$widths,
				$aligns,
				10,
				true
			);
			$pdf->set_draw_color( '#E0E0E0' );
			$pdf->hr( 0.3 );
		}

		$pdf->space( 20 );

		if ( $note ) {
			$pdf->set_color( '#6E7882' );
			$pdf->text( __( 'Hinweis: ', 'lindenweg16' ) . $note, 10 );
			$pdf->set_color( Pdf::COLOR_TINTE );
			$pdf->space( 8 );
		}

		$pdf->set_color( '#6E7882' );
		$pdf->text( __( 'Bitte prüfen Sie die Sendung bei Erhalt auf Vollständigkeit. Die Rechnung erhalten Sie getrennt.', 'lindenweg16' ), 10 );
		$pdf->set_color( Pdf::COLOR_TINTE );

		$filename = 'lieferschein-b2b-' . (int) $order['id'] . '-' . wp_generate_password( 6, false ) . '.pdf';
		$path     = trailingslashit( Installer::docs_dir() ) . $filename;

		$pdf->save( $path );

		return $filename;
	}
}
