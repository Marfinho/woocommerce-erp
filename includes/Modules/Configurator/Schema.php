<?php
/**
 * Tabellen des Konfigurators.
 *
 * Der Konfigurator erfindet keine Artikel. Was er zeigt, steht im
 * Artikelstamm des Moduls Betrieb; hier liegt nur, was der Artikelstamm
 * nicht wissen kann: wie das Teil im Raum aussieht, wie hoch es baut und
 * ob es das Basismodul ist oder daran hängt.
 *
 * Zwei Tabellen statt einer Spalte mit JSON: Sperrbereiche sind Zeilen, die
 * einzeln bearbeitet, begründet und gezählt werden – als JSON-Klumpen wären
 * sie weder abfragbar noch im Formular sauber zu pflegen.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Configurator;

defined( 'ABSPATH' ) || exit;

/**
 * Liefert die CREATE-TABLE-Anweisungen für den dbDelta-Durchlauf.
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

		/*
		 * Ein Artikel bekommt höchstens eine Rolle im Konfigurator, deshalb
		 * article_id als UNIQUE. Wer dasselbe Teil als Basis und als Modul
		 * verkaufen will, legt dafür einen zweiten Artikel an – das ist im
		 * Lager ohnehin die ehrlichere Abbildung.
		 */
		$t[] = "CREATE TABLE {$p}config_parts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			article_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL DEFAULT 'modul',
			model_file VARCHAR(255) NULL,
			height_mm DECIMAL(10,2) NOT NULL DEFAULT 0,
			width_mm DECIMAL(10,2) NOT NULL DEFAULT 0,
			depth_mm DECIMAL(10,2) NOT NULL DEFAULT 0,
			max_modules SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			teaser VARCHAR(255) NULL,
			model_align VARCHAR(8) NULL,
			model_rot_x DECIMAL(7,2) NOT NULL DEFAULT 0,
			model_rot_y DECIMAL(7,2) NOT NULL DEFAULT 0,
			model_rot_z DECIMAL(7,2) NOT NULL DEFAULT 0,
			model_off_x DECIMAL(9,2) NOT NULL DEFAULT 0,
			model_off_y DECIMAL(9,2) NOT NULL DEFAULT 0,
			model_off_z DECIMAL(9,2) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY article_id (article_id),
			KEY role (role),
			KEY is_active (is_active)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}config_blocks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			part_id BIGINT UNSIGNED NOT NULL,
			from_mm DECIMAL(10,2) NOT NULL DEFAULT 0,
			to_mm DECIMAL(10,2) NOT NULL DEFAULT 0,
			label VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY part_id (part_id)
		) {$charset_collate};";

		return $t;
	}

	/**
	 * Namen der Tabellen ohne Präfix – für die Installationsprüfung.
	 *
	 * @return array
	 */
	public static function table_names() {
		return array( 'config_parts', 'config_blocks' );
	}
}
