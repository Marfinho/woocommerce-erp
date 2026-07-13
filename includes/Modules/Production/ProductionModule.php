<?php
/**
 * Modul 4: Produktions- und Bestellplanung (Druck-Queue).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Production;

defined( 'ABSPATH' ) || exit;

use Lindenweg16\Modules\Inventory\Repository;

/**
 * Erzeugt die Druck-Queue aus offenen Bestellungen (Status processing),
 * aufgelöst in benötigte Module inkl. Bestandsabgleich.
 */
class ProductionModule {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		add_action( 'lw16_render_page_lw16_produktion', array( $this, 'render_admin' ) );
	}

	/**
	 * Produktions-Admin-Seite rendern.
	 */
	public function render_admin() {
		echo '<div class="wrap lw16-wrap"><h1>' . esc_html__( 'Produktionsplanung', 'lindenweg16' ) . '</h1>';
		echo '<h2>' . esc_html__( 'Druck-Queue (offene Bestellungen)', 'lindenweg16' ) . '</h2>';

		$queue = $this->build_queue();

		if ( empty( $queue ) ) {
			echo '<p>' . esc_html__( 'Keine offenen Bestellungen im Status „in Bearbeitung".', 'lindenweg16' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Modul', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Benötigt', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Bestand', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Fehlend', 'lindenweg16' ) . '</th>';
		echo '<th>' . esc_html__( 'Bestellungen', 'lindenweg16' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $queue as $row ) {
			$fehlend = max( 0, $row['benoetigt'] - $row['bestand'] );
			echo '<tr>';
			echo '<td>' . esc_html( $row['name'] ) . '</td>';
			echo '<td>' . esc_html( $this->fmt( $row['benoetigt'] ) ) . '</td>';
			echo '<td>' . esc_html( $this->fmt( $row['bestand'] ) ) . '</td>';
			if ( $fehlend > 0 ) {
				echo '<td class="lw16-fehlend">' . esc_html( $this->fmt( $fehlend ) ) . '</td>';
			} else {
				echo '<td>0</td>';
			}
			echo '<td>' . esc_html( implode( ', ', $row['orders'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Priorisiert nach Bestelldatum (älteste zuerst).', 'lindenweg16' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Druck-Queue aufbauen: Bestellungen -> Module (via Stückliste).
	 *
	 * @return array Modul-ID => {name, benoetigt, bestand, orders}
	 */
	protected function build_queue() {
		$orders = wc_get_orders(
			array(
				'status'  => array( 'wc-processing' ),
				'limit'   => -1,
				'orderby' => 'date',
				'order'   => 'ASC',
				'return'  => 'objects',
			)
		);

		$queue = array();

		foreach ( $orders as $order ) {
			$order_no = '#' . $order->get_order_number();
			foreach ( $order->get_items() as $item ) {
				$produkt = Repository::get_by_woo_id( $item->get_product_id() );
				if ( ! $produkt ) {
					continue;
				}
				$qty = (float) $item->get_quantity();
				$this->add_modules( $produkt, $qty, $order_no, $queue );
			}
		}

		// Bestand ergänzen.
		foreach ( $queue as $modul_id => &$row ) {
			$row['bestand'] = Repository::get_stock( $modul_id );
			$row['orders']  = array_values( array_unique( $row['orders'] ) );
		}
		unset( $row );

		return $queue;
	}

	/**
	 * Ein Produkt rekursiv in Module auflösen und in die Queue eintragen.
	 *
	 * @param object $produkt  Produkt.
	 * @param float  $menge    Menge.
	 * @param string $order_no Bestellnummer.
	 * @param array  $queue    Referenz auf Queue-Array.
	 */
	protected function add_modules( $produkt, $menge, $order_no, array &$queue ) {
		if ( 'set' === $produkt->typ ) {
			foreach ( Repository::get_stueckliste( $produkt->id ) as $pos ) {
				$modul = Repository::get_product( $pos->modul_id );
				if ( $modul ) {
					$this->add_modules( $modul, $menge * (float) $pos->menge, $order_no, $queue );
				}
			}
			return;
		}

		if ( 'modul' === $produkt->typ ) {
			if ( ! isset( $queue[ $produkt->id ] ) ) {
				$queue[ $produkt->id ] = array(
					'name'      => $produkt->name,
					'benoetigt' => 0,
					'bestand'   => 0,
					'orders'    => array(),
				);
			}
			$queue[ $produkt->id ]['benoetigt'] += $menge;
			$queue[ $produkt->id ]['orders'][]   = $order_no;
		}
	}

	/**
	 * Zahl formatieren.
	 *
	 * @param float $n Zahl.
	 * @return string
	 */
	protected function fmt( $n ) {
		$s = number_format( (float) $n, 2, ',', '.' );
		return rtrim( rtrim( $s, '0' ), ',' );
	}
}
