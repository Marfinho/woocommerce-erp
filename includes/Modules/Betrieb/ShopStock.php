<?php
/**
 * Verfügbarer Bestand für den Shop – reine Rechenregeln.
 *
 * Der Shop soll nie mehr Ware anbieten, als im Lager liegt und nicht schon
 * einer offenen Bestellung gehört. Die Bestellung bucht erst beim Abschluss
 * aus dem Bewegungskonto; bis dahin ist die Menge reserviert.
 *
 * Reine Logik ohne Datenbank- und WooCommerce-Zugriff.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Rechnet Lagerbestand und Reservierung in Shop-Mengen um.
 */
class ShopStock {

	/**
	 * Lagerbestand je Farbe aus den Salden eines Artikels.
	 *
	 * Nur der Lagerort „lager" zählt: Was beim Händler liegt oder unterwegs
	 * ist, lässt sich im Shop nicht verkaufen.
	 *
	 * @param array $balances Ergebnis von StockLedger::balances() für einen Artikel (color => location => Menge).
	 * @return array color => Menge.
	 */
	public static function stock_by_color( array $balances ) {
		$out = array();

		foreach ( $balances as $color => $locations ) {
			$out[ (string) $color ] = isset( $locations['lager'] ) ? (float) $locations['lager'] : 0.0;
		}

		return $out;
	}

	/**
	 * Offene Bestellpositionen eines Artikels je Farbe summieren.
	 *
	 * @param array $lines      Zeilen mit article_id, color, qty (siehe WooIntegration::open_order_lines()).
	 * @param int   $article_id Artikel.
	 * @return array color => reservierte Menge.
	 */
	public static function reserved_by_color( array $lines, $article_id ) {
		$out = array();

		foreach ( $lines as $line ) {
			if ( (int) $line['article_id'] !== (int) $article_id ) {
				continue;
			}

			$color         = (string) $line['color'];
			$out[ $color ] = ( isset( $out[ $color ] ) ? $out[ $color ] : 0.0 ) + (float) $line['qty'];
		}

		return $out;
	}

	/**
	 * Verfügbare Menge je Farbe: Lager minus Reservierung, nie unter null.
	 *
	 * Ganze Stück nach unten gerundet: 2,5 verfügbar heißt, zwei lassen
	 * sich verkaufen.
	 *
	 * @param array $stock    color => Lagerbestand.
	 * @param array $reserved color => reservierte Menge.
	 * @return array color => ganze Stück.
	 */
	public static function available( array $stock, array $reserved ) {
		$out = array();

		foreach ( $stock as $color => $qty ) {
			$free          = (float) $qty - ( isset( $reserved[ $color ] ) ? (float) $reserved[ $color ] : 0.0 );
			$out[ $color ] = max( 0, (int) floor( $free + 0.0005 ) );
		}

		return $out;
	}

	/**
	 * Mengen je Variante des Shop-Produkts.
	 *
	 * Eine Variante ohne Farbe gilt als Standardfarbe. Eine Farbe, die das
	 * Lager nicht kennt, hat keinen Bestand.
	 *
	 * @param array  $variations Variante => Farbe (Slug, leer = Standardfarbe).
	 * @param array  $available  color => verfügbare Stück.
	 * @param string $default    Standardfarbe.
	 * @return array Variante => Stück.
	 */
	public static function per_variation( array $variations, array $available, $default ) {
		$out = array();

		foreach ( $variations as $id => $color ) {
			$color       = '' === (string) $color ? (string) $default : (string) $color;
			$out[ $id ] = isset( $available[ $color ] ) ? (int) $available[ $color ] : 0;
		}

		return $out;
	}

	/**
	 * Menge eines einfachen Produkts: alle Farben zusammen.
	 *
	 * Ungeklärte Bewegungen zählen mit – die Ware fehlt oder liegt dort
	 * tatsächlich, auch wenn die Farbe nicht feststeht.
	 *
	 * @param array $stock    color => Lagerbestand.
	 * @param array $reserved color => reservierte Menge.
	 * @return int
	 */
	public static function simple_quantity( array $stock, array $reserved ) {
		$free = array_sum( $stock ) - array_sum( $reserved );

		return max( 0, (int) floor( $free + 0.0005 ) );
	}
}
