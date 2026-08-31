<?php
/**
 * Was passiert, wenn die WooCommerce-Bestellung verschwindet.
 *
 * Eine Betriebszeile zu einer Shop-Bestellung hält selbst kaum Zahlen: Wert
 * und Datum holt sie sich bei Bedarf aus WooCommerce. Wird die Bestellung
 * dort gelöscht, zeigt die Zeile ins Leere – und zwar still. `order_value()`
 * liefert dann 0,00 €, `order_date()` einen leeren String, und in der Liste
 * steht eine Bestellung ohne Datum und ohne Wert, die niemand mehr zuordnen
 * kann.
 *
 * Zwei Fälle, die auseinandergehalten gehören:
 *
 * - **Papierkorb.** Die Bestellung kann zurückgeholt werden. Hier passiert
 *   nichts – wer eine Zeile beim Papierkorb-Klick abräumt, verliert sie beim
 *   Wiederherstellen.
 * - **Endgültig gelöscht.** Die Quelle kommt nicht wieder. Bevor sie geht,
 *   werden Wert und Datum in die Zeile geschrieben: Die Bestellung *gab* es,
 *   und die Kennzahlen der Vergangenheit sollen sich nicht dadurch ändern,
 *   dass jemand heute aufräumt.
 *
 * Gelöscht wird von sich aus nichts. Was mit einer verwaisten Zeile
 * geschieht, entscheidet der Mensch – im Testbetrieb anders als im
 * Echtbetrieb.
 *
 * Frei von Datenbank und WordPress-Umgebung.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Zustand einer Bestellzeile gegenüber ihrer Quelle.
 */
class OrderSource {

	/**
	 * Zeigt diese Zeile überhaupt auf eine Shop-Bestellung?
	 *
	 * Manuell erfasste Bestellungen haben keine Quelle und können deshalb
	 * auch nicht verwaisen.
	 *
	 * @param array $order Bestellzeile.
	 * @return bool
	 */
	public static function from_shop( array $order ) {
		return ! empty( $order['wc_order_id'] );
	}

	/**
	 * Ist die Quelle verschwunden?
	 *
	 * @param array $order    Bestellzeile.
	 * @param bool  $vorhanden Ob die WooCommerce-Bestellung geladen werden konnte.
	 * @return bool
	 */
	public static function is_orphaned( array $order, $vorhanden ) {
		return self::from_shop( $order ) && ! $vorhanden;
	}

	/**
	 * Hat die Zeile eigene Zahlen, mit denen sie ohne Quelle auskommt?
	 *
	 * @param array $order Bestellzeile.
	 * @return bool
	 */
	public static function has_snapshot( array $order ) {
		$wert  = isset( $order['manual_order_value'] ) ? $order['manual_order_value'] : null;
		$datum = isset( $order['manual_order_date'] ) ? (string) $order['manual_order_date'] : '';

		return null !== $wert && '' !== $wert && '' !== $datum;
	}

	/**
	 * Zählt diese Zeile noch in den Kennzahlen?
	 *
	 * Eine verwaiste Zeile ohne eigene Zahlen zählt als Bestellung über
	 * 0,00 € ohne Datum mit. In der Umsatzsumme fällt das nicht auf, in der
	 * Zahl der Bestellungen und im Ø-Warenkorb schon.
	 *
	 * @param array $order     Bestellzeile.
	 * @param bool  $vorhanden Ob die Quelle geladen werden konnte.
	 * @return bool
	 */
	public static function counts( array $order, $vorhanden ) {
		if ( ! self::is_orphaned( $order, $vorhanden ) ) {
			return true;
		}

		return self::has_snapshot( $order );
	}

	/**
	 * Die Felder, die vor dem Löschen festgehalten werden.
	 *
	 * Vorhandene eigene Angaben werden nicht überschrieben: Wer den Wert
	 * von Hand korrigiert hat, hat sich dabei etwas gedacht.
	 *
	 * @param array       $order Bestellzeile.
	 * @param float|null  $value Wert aus WooCommerce.
	 * @param string      $date  Datum aus WooCommerce (Y-m-d).
	 * @param string      $jetzt Zeitpunkt des Löschens (MySQL-UTC).
	 * @return array Felder für das Update.
	 */
	public static function snapshot( array $order, $value, $date, $jetzt ) {
		$daten = array( 'source_deleted_at' => $jetzt );

		$hat_wert = isset( $order['manual_order_value'] ) && null !== $order['manual_order_value'] && '' !== $order['manual_order_value'];

		if ( ! $hat_wert && null !== $value ) {
			$daten['manual_order_value'] = (float) $value;
		}

		$hat_datum = isset( $order['manual_order_date'] ) && '' !== (string) $order['manual_order_date'];

		if ( ! $hat_datum && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			$daten['manual_order_date'] = (string) $date;
		}

		return $daten;
	}

	/**
	 * Erklärung für die Liste.
	 *
	 * @param array $order Bestellzeile.
	 * @return string
	 */
	public static function explain( array $order ) {
		if ( self::has_snapshot( $order ) ) {
			return __( 'Die Bestellung wurde in WooCommerce gelöscht. Wert und Datum sind hier festgehalten, die Kennzahlen bleiben richtig.', 'lindenweg16' );
		}

		return __( 'Die Bestellung wurde in WooCommerce gelöscht, bevor Wert und Datum hier festgehalten werden konnten. Diese Zeile hat keine Zahlen mehr.', 'lindenweg16' );
	}
}
