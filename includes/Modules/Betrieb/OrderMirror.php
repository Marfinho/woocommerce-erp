<?php
/**
 * Was der Betrieb tut, wenn eine Shop-Bestellung gelöscht wird.
 *
 * Bis hierher passierte gar nichts: Die Betriebszeile blieb stehen und zeigte
 * auf eine Bestellung, die es nicht mehr gab. In der Liste stand dann eine
 * Bestellung über 0,00 € ohne Datum, und in der Händlerauswertung zählte sie
 * weiter als Bestellung mit.
 *
 * Zwei Fälle, die auseinandergehalten gehören:
 *
 * - **Papierkorb.** Die Bestellung kann zurückgeholt werden. Hier passiert
 *   nichts – wer beim Papierkorb-Klick schon abräumt, verliert die
 *   Betriebsdaten beim Wiederherstellen.
 * - **Endgültig gelöscht.** Bevor die Quelle geht, werden Wert und Datum in
 *   die Zeile geschrieben und der Zeitpunkt vermerkt. Die Bestellung *gab*
 *   es; die Kennzahlen der Vergangenheit dürfen sich nicht dadurch ändern,
 *   dass jemand heute aufräumt.
 *
 * Von sich aus löscht der Betrieb nichts. Was mit einer verwaisten Zeile
 * geschieht, entscheidet der Mensch auf der Bestellseite – im Testbetrieb
 * anders als im Echtbetrieb.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Hängt sich an die Löschvorgänge von WooCommerce.
 */
class OrderMirror {

	/**
	 * Hooks registrieren.
	 */
	public function register() {
		// HPOS: eigene Tabellen, eigener Hook.
		add_action( 'woocommerce_before_delete_order', array( $this, 'on_delete' ), 10, 1 );

		// Klassisch: Bestellungen sind Beiträge. before_delete_post feuert
		// für alles – der Beitragstyp wird deshalb geprüft.
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ), 10, 1 );
	}

	/**
	 * Bestellung wird endgültig gelöscht (HPOS).
	 *
	 * @param int $order_id Bestellung.
	 */
	public function on_delete( $order_id ) {
		self::freeze( (int) $order_id );
	}

	/**
	 * Beitrag wird endgültig gelöscht (klassische Speicherung).
	 *
	 * @param int $post_id Beitrag.
	 */
	public function on_delete_post( $post_id ) {
		if ( 'shop_order' !== get_post_type( $post_id ) ) {
			return;
		}

		self::freeze( (int) $post_id );
	}

	/**
	 * Wert und Datum festhalten, bevor die Quelle verschwindet.
	 *
	 * @param int $order_id Bestellung.
	 * @return bool Ob etwas festgehalten wurde.
	 */
	public static function freeze( $order_id ) {
		global $wpdb;

		$order_id = (int) $order_id;
		$row      = OrderOpsRepository::find_by_order( $order_id );

		if ( ! $row ) {
			return false;
		}

		$wert  = null;
		$datum = '';

		if ( function_exists( 'wc_get_order' ) ) {
			$wc = wc_get_order( $order_id );

			if ( $wc ) {
				$wert     = (float) $wc->get_total();
				$erstellt = $wc->get_date_created();
				$datum    = $erstellt ? $erstellt->date( 'Y-m-d' ) : '';
			}
		}

		$daten = OrderSource::snapshot( $row, $wert, $datum, current_time( 'mysql', true ) );

		$wpdb->update( OrderOpsRepository::table(), $daten, array( 'id' => (int) $row['id'] ) );

		BetriebAudit::record(
			'update',
			'order_ops',
			(int) $row['id'],
			$row,
			$daten,
			sprintf(
				/* translators: %d: WooCommerce-Bestellnummer. */
				__( 'WooCommerce-Bestellung %d gelöscht; Wert und Datum hier festgehalten.', 'lindenweg16' ),
				$order_id
			)
		);

		return true;
	}
}
