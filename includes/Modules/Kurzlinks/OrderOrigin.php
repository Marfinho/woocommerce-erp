<?php
/**
 * Bestellungen nach Herkunft: Anzahl und Umsatz je `utm_source`.
 *
 * Die Quelle liest WooCommerce selbst aus dem Besuch (Order Attribution) und
 * schreibt sie als Metadatum an die Bestellung. Gelesen wird über
 * `$order->get_meta()`, damit es mit und ohne HPOS läuft.
 *
 * Bestellungen ohne Quelle sind kein Fehler: Ohne Cookie-Einwilligung erfasst
 * WooCommerce keine Herkunft. Sie erscheinen als „unbekannt“.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Kurzlinks;

defined( 'ABSPATH' ) || exit;

/**
 * Sammeln und Zusammenfassen.
 */
class OrderOrigin {

	/**
	 * Meta-Schlüssel der Quelle (WooCommerce Order Attribution).
	 */
	const META_UTM_SOURCE = '_wc_order_attribution_utm_source';

	/**
	 * Schlüssel für Bestellungen ohne erfasste Herkunft.
	 */
	const UNKNOWN = 'unbekannt';

	/**
	 * Schlüssel für Direktaufrufe, die WooCommerce als „(direct)“ einträgt.
	 */
	const DIRECT = 'direkt';

	/**
	 * Zeiträume der Auswertung: Tage => Beschriftung (0 = alles).
	 *
	 * @return array
	 */
	public static function ranges() {
		return array(
			30  => __( 'Letzte 30 Tage', 'lindenweg16' ),
			90  => __( 'Letzte 90 Tage', 'lindenweg16' ),
			365 => __( 'Letzte 12 Monate', 'lindenweg16' ),
			0   => __( 'Gesamter Zeitraum', 'lindenweg16' ),
		);
	}

	/**
	 * Rohwert der Quelle auf einen Gruppenschlüssel abbilden.
	 *
	 * @param mixed $raw Wert von `_wc_order_attribution_utm_source`.
	 * @return string
	 */
	public static function normalize( $raw ) {
		if ( ! is_string( $raw ) ) {
			return self::UNKNOWN;
		}

		$s = strtolower( trim( $raw ) );

		if ( '' === $s ) {
			return self::UNKNOWN;
		}

		if ( '(direct)' === $s ) {
			return self::DIRECT;
		}

		return $s;
	}

	/**
	 * Zeilen zu Anzahl und Umsatz je Quelle zusammenfassen.
	 *
	 * @param array $rows Zeilen mit `source` (Rohwert) und `total` (Betrag nach Erstattungen).
	 * @return array Quelle => [ 'anzahl', 'umsatz' ]; absteigend nach Umsatz, „unbekannt“ zuletzt.
	 */
	public static function aggregate( array $rows ) {
		$out = array();

		foreach ( $rows as $row ) {
			$key = self::normalize( isset( $row['source'] ) ? $row['source'] : '' );

			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'anzahl' => 0,
					'umsatz' => 0.0,
				);
			}

			$out[ $key ]['anzahl']++;
			$out[ $key ]['umsatz'] += (float) ( isset( $row['total'] ) ? $row['total'] : 0 );
		}

		uksort(
			$out,
			function ( $a, $b ) use ( $out ) {
				if ( self::UNKNOWN === $a ) {
					return 1;
				}
				if ( self::UNKNOWN === $b ) {
					return -1;
				}

				$diff = $out[ $b ]['umsatz'] <=> $out[ $a ]['umsatz'];

				return 0 !== $diff ? $diff : strcmp( $a, $b );
			}
		);

		return $out;
	}

	/**
	 * Bezahlte Bestellungen des Zeitraums einlesen.
	 *
	 * @param int $days Anzahl Tage; 0 für alles.
	 * @return array Zeilen mit `source` und `total`.
	 */
	public static function collect( $days ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$args = array(
			'type'    => 'shop_order',
			'status'  => array( 'wc-processing', 'wc-completed' ),
			'orderby' => 'ID',
			'order'   => 'ASC',
			'limit'   => 200,
		);

		if ( (int) $days > 0 ) {
			$args['date_created'] = '>=' . ( time() - (int) $days * DAY_IN_SECONDS );
		}

		$rows = array();
		$page = 1;

		do {
			$args['paged'] = $page;
			$orders        = wc_get_orders( $args );

			foreach ( $orders as $order ) {
				$rows[] = array(
					'source' => $order->get_meta( self::META_UTM_SOURCE ),
					'total'  => (float) $order->get_total() - (float) $order->get_total_refunded(),
				);
			}

			$page++;
		} while ( count( $orders ) === $args['limit'] );

		return $rows;
	}
}
