<?php
/**
 * Tabelle der Kurzlinks.
 *
 * Eine einzige Zählertabelle: je Tag, Kurzlink und Quelle eine Zeile mit einer
 * Zahl. Weder IP-Adresse noch User-Agent noch Kennung kommen vor – aus der
 * Tabelle lässt sich keine Person ableiten, deshalb steht sie auch nicht in
 * `Core\Privacy`. Die Links selbst liegen in einer Option (siehe `Links`).
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Kurzlinks;

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

		// 64 Zeichen je Textspalte halten den Primärschlüssel auch bei utf8mb4
		// unter der Indexgrenze älterer MySQL-Versionen.
		$t[] = "CREATE TABLE {$p}kurzlinks_stats (
			datum DATE NOT NULL,
			slug VARCHAR(64) NOT NULL,
			utm_source VARCHAR(64) NOT NULL DEFAULT '',
			anzahl INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (datum, slug, utm_source),
			KEY slug (slug)
		) {$charset_collate};";

		return $t;
	}
}
