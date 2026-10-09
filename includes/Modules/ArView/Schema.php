<?php
/**
 * Tabelle der AR-Ansicht.
 *
 * Eine einzige Zählertabelle: je Tag, Produkt und Ereignis eine Zeile mit
 * einer Zahl. Weder IP-Adresse noch Benutzer noch Gerätekennung kommen
 * vor – aus der Tabelle lässt sich keine Person ableiten, deshalb steht
 * sie auch nicht in `Core\Privacy`.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\ArView;

defined( 'ABSPATH' ) || exit;

/**
 * Liefert die CREATE-TABLE-Anweisung für den dbDelta-Durchlauf.
 */
class Schema {

	/**
	 * Tabellendefinitionen.
	 *
	 * @param string $p               Präfix inklusive lw16_.
	 * @param string $charset_collate Zeichensatz der Installation.
	 * @return array
	 */
	public static function tables( $p, $charset_collate ) {
		$t = array();

		$t[] = "CREATE TABLE {$p}ar_stats (
			day DATE NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			event VARCHAR(12) NOT NULL,
			cnt INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (day, product_id, event),
			KEY product_id (product_id)
		) {$charset_collate};";

		return $t;
	}
}
