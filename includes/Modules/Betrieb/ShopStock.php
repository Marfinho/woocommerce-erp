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

	/**
	 * Freie Menge je Farbe, ungerundet.
	 *
	 * @param array $stock    color => Lagerbestand.
	 * @param array $reserved color => reservierte Menge.
	 * @return array color => Menge (auch negativ).
	 */
	public static function free( array $stock, array $reserved ) {
		$out = array();

		foreach ( array_unique( array_merge( array_keys( $stock ), array_keys( $reserved ) ) ) as $color ) {
			$out[ (string) $color ] = ( isset( $stock[ $color ] ) ? (float) $stock[ $color ] : 0.0 ) - ( isset( $reserved[ $color ] ) ? (float) $reserved[ $color ] : 0.0 );
		}

		return $out;
	}

	/**
	 * Stück einer Shop-Variante – reine Logik.
	 *
	 * Ein gewöhnlicher Artikel hat, was in dieser Farbe frei ist. Ein Set,
	 * das beim Verkauf gepackt wird, hat so viele, wie sich aus seinen
	 * Modulen (getauscht nach der Wahl der Variante) packen lassen.
	 *
	 * @param array  $free       article_id => color => freie Menge.
	 * @param int    $article_id Artikel der Variante.
	 * @param string $color      Farbe.
	 * @param array  $attrs      Merkmale der Variante.
	 * @param array  $ctx        Kontext (Variants::context()).
	 * @return int
	 */
	public static function variant_units( array $free, $article_id, $color, array $attrs, array $ctx ) {
		$units = static function ( $id ) use ( $free, $color ) {
			return isset( $free[ (int) $id ][ (string) $color ] ) ? (float) $free[ (int) $id ][ (string) $color ] : 0.0;
		};

		if ( ! Variants::packs_at_sale( (int) $article_id, $ctx ) ) {
			return max( 0, (int) floor( $units( $article_id ) + 0.0005 ) );
		}

		return self::pack_units( Variants::pack( (int) $article_id, 1.0, $attrs, $ctx ), $units );
	}

	/**
	 * Stück eines einfachen Produkts – reine Logik.
	 *
	 * Alle Farben zusammen; ein gepacktes Set nach der Stückliste, wie sie
	 * steht.
	 *
	 * @param array $free       article_id => color => freie Menge.
	 * @param int   $article_id Artikel.
	 * @param array $ctx        Kontext.
	 * @return int
	 */
	public static function simple_units( array $free, $article_id, array $ctx ) {
		$units = static function ( $id ) use ( $free ) {
			return isset( $free[ (int) $id ] ) ? (float) array_sum( $free[ (int) $id ] ) : 0.0;
		};

		if ( ! Variants::packs_at_sale( (int) $article_id, $ctx ) ) {
			return max( 0, (int) floor( $units( $article_id ) + 0.0005 ) );
		}

		return self::pack_units( Variants::pack( (int) $article_id, 1.0, array(), $ctx ), $units );
	}

	/**
	 * Wie viele Sets lassen sich packen?
	 *
	 * @param array    $pack  Ergebnis von Variants::pack() für ein Set.
	 * @param callable $units Freie Menge eines Artikels.
	 * @return int
	 */
	protected static function pack_units( array $pack, $units ) {
		$min = null;

		foreach ( $pack['articles'] as $child => $need ) {
			if ( $need <= 0 ) {
				continue;
			}

			$sets = (int) floor( max( 0.0, $units( $child ) ) / $need + 0.0005 );
			$min  = null === $min ? $sets : min( $min, $sets );
		}

		return null === $min ? 0 : max( 0, $min );
	}

	/**
	 * Welche Shop-Produkte sind betroffen, wenn sich diese Artikel ändern?
	 *
	 * Das eigene Produkt jedes Artikels und das jedes Sets, in das er
	 * gepackt wird.
	 *
	 * @param int[] $article_ids Artikel.
	 * @param array $ctx         Kontext.
	 * @return int[] Produkt-IDs.
	 */
	public static function products_for( array $article_ids, array $ctx ) {
		$out  = array();
		$want = array_flip( array_map( 'intval', $article_ids ) );

		foreach ( $article_ids as $id ) {
			$article = isset( $ctx['articles'][ (int) $id ] ) ? (array) $ctx['articles'][ (int) $id ] : array();
			if ( ! empty( $article['wc_product_id'] ) ) {
				$out[ (int) $article['wc_product_id'] ] = true;
			}
		}

		foreach ( $ctx['bom'] as $parent => $rows ) {
			$article = isset( $ctx['articles'][ (int) $parent ] ) ? (array) $ctx['articles'][ (int) $parent ] : array();

			if ( empty( $article['wc_product_id'] ) || ! Variants::packs_at_sale( (int) $parent, $ctx ) ) {
				continue;
			}

			foreach ( (array) $rows as $row ) {
				$row = (array) $row;
				if ( 'artikel' !== $row['child_type'] ) {
					continue;
				}

				// Auch die Geschwister zählen: Das Set kann jedes davon enthalten.
				foreach ( array_merge( array_keys( Variants::group_of( (int) $row['child_id'], $ctx ) ), array( (int) $row['child_id'] ) ) as $member ) {
					if ( isset( $want[ (int) $member ] ) ) {
						$out[ (int) $article['wc_product_id'] ] = true;
					}
				}
			}
		}

		return array_map( 'intval', array_keys( $out ) );
	}
}
