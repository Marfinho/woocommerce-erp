<?php
/**
 * Anonyme Tageszähler der AR-Ansicht.
 *
 * Gezählt wird, was für die Frage „lohnt sich der Hinweis?" nötig ist:
 * Banner gezeigt, Ja, Nein, AR gestartet. Je Tag, Produkt und Ereignis
 * steht eine Zahl in der Tabelle, sonst nichts.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\ArView;

defined( 'ABSPATH' ) || exit;

/**
 * Zählen und Auswerten.
 */
class Stats {

	const EVENT_SHOWN   = 'shown';
	const EVENT_YES     = 'yes';
	const EVENT_NO      = 'no';
	const EVENT_STARTED = 'started';

	/**
	 * Zulässige Ereignisse mit Beschriftung.
	 *
	 * @return array
	 */
	public static function events() {
		return array(
			self::EVENT_SHOWN   => __( 'Banner gezeigt', 'lindenweg16' ),
			self::EVENT_YES     => __( 'Ja', 'lindenweg16' ),
			self::EVENT_NO      => __( 'Nein', 'lindenweg16' ),
			self::EVENT_STARTED => __( 'AR gestartet', 'lindenweg16' ),
		);
	}

	/**
	 * Ist das ein bekanntes Ereignis?
	 *
	 * @param mixed $event Eingabe.
	 * @return bool
	 */
	public static function is_event( $event ) {
		return is_string( $event ) && array_key_exists( $event, self::events() );
	}

	/**
	 * Tabellenname.
	 *
	 * @return string
	 */
	protected static function table() {
		global $wpdb;

		return $wpdb->prefix . 'lw16_ar_stats';
	}

	/**
	 * Ein Ereignis zählen.
	 *
	 * @param int    $product_id Produkt.
	 * @param string $event      Ereignis.
	 * @return bool Ob gezählt wurde.
	 */
	public static function record( $product_id, $event ) {
		global $wpdb;

		$product_id = (int) $product_id;

		if ( $product_id <= 0 || ! self::is_event( $event ) ) {
			return false;
		}

		// Tabellenname und Spaltenwerte sind geprüft bzw. vorbereitet.
		$sql = $wpdb->prepare(
			'INSERT INTO ' . self::table() . ' (day, product_id, event, cnt) VALUES (%s, %d, %s, 1) ON DUPLICATE KEY UPDATE cnt = cnt + 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			current_time( 'Y-m-d' ),
			$product_id,
			$event
		);

		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Summen je Produkt und Ereignis, optional auf die letzten Tage begrenzt.
	 *
	 * @param int $days Anzahl Tage; 0 für alles.
	 * @return array Produkt => [shown, yes, no, started].
	 */
	public static function totals( $days = 0 ) {
		global $wpdb;

		$days = (int) $days;

		if ( $days > 0 ) {
			$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . ( $days - 1 ) . ' days' ) );
			$sql   = $wpdb->prepare(
				'SELECT product_id, event, SUM(cnt) AS cnt FROM ' . self::table() . ' WHERE day >= %s GROUP BY product_id, event', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$since
			);
		} else {
			$sql = 'SELECT product_id, event, SUM(cnt) AS cnt FROM ' . self::table() . ' GROUP BY product_id, event'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		return self::pivot( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Zeilen (Produkt, Ereignis, Anzahl) zu einer Zeile je Produkt falten.
	 *
	 * @param array $rows Zeilen mit product_id, event, cnt.
	 * @return array Produkt => [shown, yes, no, started]; unbekannte Ereignisse fallen weg.
	 */
	public static function pivot( array $rows ) {
		$out = array();

		foreach ( $rows as $row ) {
			$id    = (int) $row['product_id'];
			$event = (string) $row['event'];

			if ( ! self::is_event( $event ) ) {
				continue;
			}

			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array_fill_keys( array_keys( self::events() ), 0 );
			}

			$out[ $id ][ $event ] += (int) $row['cnt'];
		}

		return $out;
	}

	/**
	 * Ja-Quote: Anteil der Ja-Antworten an den gezeigten Bannern.
	 *
	 * @param int $yes   Ja.
	 * @param int $shown Gezeigt.
	 * @return float|null Prozent mit einer Nachkommastelle; null, wenn nichts gezeigt wurde.
	 */
	public static function rate( $yes, $shown ) {
		$shown = (int) $shown;

		if ( $shown <= 0 ) {
			return null;
		}

		return round( 100 * (int) $yes / $shown, 1 );
	}
}
