<?php
/**
 * Produkte gebündelt vorladen.
 *
 * WooCommerce und WordPress puffern Beiträge und Metadaten – aber nur,
 * wenn man sie gebündelt darum bittet. Wer in einer Liste je Zeile
 * `wc_get_product()` ruft, bekommt je Zeile eine Abfrage für den Beitrag
 * und eine für die Metadaten. Einmal vorgeladen, kommen dieselben Aufrufe
 * ohne jede Abfrage aus.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Vorladen von Produktdaten für Listenansichten.
 */
final class ProductCache {

	/**
	 * Beiträge und Metadaten der Produkte in den Puffer holen.
	 *
	 * Danach kostet `wc_get_product()` für diese Kennungen keine Abfrage
	 * mehr. Kennungen, die es nicht gibt, stören nicht.
	 *
	 * @param int[] $ids Produkt-IDs.
	 */
	public static function prime( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( ! $ids ) {
			return;
		}

		// Füllt Beitrags- und Metadatenpuffer in je einer Abfrage. Damit ist
		// alles da, was `wc_get_product()`, `get_permalink()` und die
		// Bildausgabe danach brauchen.
		_prime_post_caches( $ids, true, true );
	}
}
