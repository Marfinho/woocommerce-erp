<?php
/**
 * Tabellen der ERP-Oberfläche.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Erp;

defined( 'ABSPATH' ) || exit;

/**
 * Datenbankschema.
 */
class Schema {

	/**
	 * Tabellendefinitionen für dbDelta.
	 *
	 * Eine Platte (Arbeitseinheit der Werkstatt) steht in genau einer Zeile.
	 * Die Positionen liegen als JSON, weil sie nur zusammen mit der Platte
	 * gelesen werden; gebucht wird über die vorhandenen Tabellen (Druckjournal,
	 * Lagerbewegungen, Zeiten), nicht aus dieser Zeile.
	 *
	 * @param string $p                Tabellenvorsilbe.
	 * @param string $charset_collate  Zeichensatz.
	 * @return string[]
	 */
	public static function tables( $p, $charset_collate ) {
		$t = array();

		$t[] = "CREATE TABLE {$p}runs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch VARCHAR(20) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'freigegeben',
			printer_id BIGINT UNSIGNED NULL,
			bambu_printer VARCHAR(50) NULL,
			plate_job_id BIGINT UNSIGNED NULL,
			items LONGTEXT NOT NULL,
			est_minutes INT UNSIGNED NOT NULL DEFAULT 0,
			released_at DATETIME NOT NULL,
			setup_at DATETIME NULL,
			print_at DATETIME NULL,
			print_end_at DATETIME NULL,
			reported_at DATETIME NULL,
			worker_id BIGINT UNSIGNED NULL,
			failure_reason VARCHAR(30) NULL,
			note TEXT NULL,
			print_job_ids VARCHAR(255) NULL,
			created_by BIGINT UNSIGNED NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY batch (batch),
			KEY plate_job_id (plate_job_id),
			KEY reported_at (reported_at)
		) {$charset_collate};";

		return $t;
	}
}
