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

		// Anonymer Seitenaufruf-Zähler fürs Live-Dashboard: keine Adresse, kein Cookie,
		// keine Person, nur Anzahl je Zehn-Minuten-Fenster und Bereich der Website.
		$t[] = "CREATE TABLE {$p}traffic (
			day DATE NOT NULL,
			hour TINYINT UNSIGNED NOT NULL,
			slot TINYINT UNSIGNED NOT NULL,
			section VARCHAR(16) NOT NULL,
			views INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (day, hour, slot, section)
		) {$charset_collate};";

		/* -----------------------------------------------------------------
		 * Finanzen: Bankabgleich, Mahnwesen
		 * --------------------------------------------------------------- */

		// Ein Import (CAMT-Datei oder CSV) – damit sich nachvollziehen lässt, woher eine Umsatzzeile kam.
		$t[] = "CREATE TABLE {$p}bank_imports (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			file_name VARCHAR(255) NOT NULL DEFAULT '',
			format VARCHAR(10) NOT NULL DEFAULT '',
			account_iban VARCHAR(40) NULL,
			rows_total INT UNSIGNED NOT NULL DEFAULT 0,
			rows_new INT UNSIGNED NOT NULL DEFAULT 0,
			closing_balance DECIMAL(14,2) NULL,
			closing_date DATE NULL,
			created_at DATETIME NOT NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		// Kontoumsätze. Der Fingerabdruck verhindert, dass derselbe Umsatz zweimal importiert wird.
		$t[] = "CREATE TABLE {$p}bank_transactions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			import_id BIGINT UNSIGNED NOT NULL,
			fingerprint CHAR(40) NOT NULL,
			account_iban VARCHAR(40) NULL,
			booking_date DATE NOT NULL,
			value_date DATE NULL,
			amount DECIMAL(14,2) NOT NULL,
			currency CHAR(3) NOT NULL DEFAULT 'EUR',
			counterparty VARCHAR(255) NULL,
			counterparty_iban VARCHAR(40) NULL,
			purpose TEXT NULL,
			end_to_end VARCHAR(100) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'offen',
			match_type VARCHAR(20) NULL,
			match_id BIGINT UNSIGNED NULL,
			match_note VARCHAR(255) NULL,
			matched_at DATETIME NULL,
			matched_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY fingerprint (fingerprint),
			KEY booking_date (booking_date),
			KEY status (status),
			KEY match_ref (match_type, match_id)
		) {$charset_collate};";

		// Versandte Mahnungen und Erinnerungen je offenem Posten (Auftrag).
		$t[] = "CREATE TABLE {$p}dunning_notices (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_op_id BIGINT UNSIGNED NOT NULL,
			contact_id BIGINT UNSIGNED NULL,
			level TINYINT UNSIGNED NOT NULL DEFAULT 1,
			amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			fee DECIMAL(10,2) NOT NULL DEFAULT 0,
			new_due DATE NULL,
			sent_to VARCHAR(191) NULL,
			subject VARCHAR(255) NULL,
			body TEXT NULL,
			sent_at DATETIME NOT NULL,
			sent_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY order_op_id (order_op_id),
			KEY contact_id (contact_id)
		) {$charset_collate};";

		/* -----------------------------------------------------------------
		 * Verkauf: Angebote, Reklamationen
		 * --------------------------------------------------------------- */

		$t[] = "CREATE TABLE {$p}quotes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			number VARCHAR(30) NOT NULL,
			contact_id BIGINT UNSIGNED NULL,
			recipient VARCHAR(255) NOT NULL DEFAULT '',
			recipient_email VARCHAR(191) NULL,
			recipient_address TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'entwurf',
			quote_date DATE NOT NULL,
			valid_until DATE NULL,
			intro TEXT NULL,
			outro TEXT NULL,
			discount_pct DECIMAL(6,3) NOT NULL DEFAULT 0,
			shipping_gross DECIMAL(10,2) NOT NULL DEFAULT 0,
			total_gross DECIMAL(12,2) NOT NULL DEFAULT 0,
			order_op_id BIGINT UNSIGNED NULL,
			sent_at DATETIME NULL,
			decided_at DATETIME NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY number (number),
			KEY contact_id (contact_id),
			KEY status (status)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}quote_items (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			quote_id BIGINT UNSIGNED NOT NULL,
			position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			article_id BIGINT UNSIGNED NULL,
			color VARCHAR(50) NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			description TEXT NULL,
			qty DECIMAL(10,3) NOT NULL DEFAULT 1,
			unit_price_gross DECIMAL(10,4) NOT NULL DEFAULT 0,
			discount_pct DECIMAL(6,3) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY quote_id (quote_id)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}rma_cases (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			number VARCHAR(30) NOT NULL,
			wc_order_id BIGINT UNSIGNED NULL,
			order_op_id BIGINT UNSIGNED NULL,
			contact_id BIGINT UNSIGNED NULL,
			customer VARCHAR(255) NOT NULL DEFAULT '',
			customer_email VARCHAR(191) NULL,
			article_id BIGINT UNSIGNED NULL,
			color VARCHAR(50) NULL,
			qty DECIMAL(10,3) NOT NULL DEFAULT 1,
			reason VARCHAR(30) NOT NULL DEFAULT 'sonstiges',
			description TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'eingegangen',
			resolution VARCHAR(20) NULL,
			refund_amount DECIMAL(10,2) NULL,
			restocked_qty DECIMAL(10,3) NOT NULL DEFAULT 0,
			scrapped_qty DECIMAL(10,3) NOT NULL DEFAULT 0,
			history LONGTEXT NULL,
			received_on DATE NULL,
			closed_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY number (number),
			KEY status (status),
			KEY reason (reason),
			KEY wc_order_id (wc_order_id),
			KEY article_id (article_id)
		) {$charset_collate};";

		/* -----------------------------------------------------------------
		 * Lager: Inventur, Bestellungen an Lieferanten
		 * --------------------------------------------------------------- */

		$t[] = "CREATE TABLE {$p}stocktakes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(30) NOT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'offen',
			snapshot_at DATETIME NOT NULL,
			closed_at DATETIME NULL,
			closed_by BIGINT UNSIGNED NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY status (status)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}stocktake_lines (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			stocktake_id BIGINT UNSIGNED NOT NULL,
			article_id BIGINT UNSIGNED NOT NULL,
			color VARCHAR(50) NOT NULL DEFAULT '',
			expected_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
			counted_qty DECIMAL(14,3) NULL,
			unit_value DECIMAL(12,4) NULL,
			counted_at DATETIME NULL,
			counted_by BIGINT UNSIGNED NULL,
			movement_id BIGINT UNSIGNED NULL,
			note VARCHAR(255) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY line (stocktake_id, article_id, color),
			KEY stocktake_id (stocktake_id)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}supplier_orders (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			number VARCHAR(30) NOT NULL,
			supplier VARCHAR(255) NOT NULL DEFAULT '',
			supplier_email VARCHAR(191) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'entwurf',
			order_date DATE NOT NULL,
			wanted_by DATE NULL,
			note TEXT NULL,
			supplier_ref VARCHAR(100) NULL,
			sent_at DATETIME NULL,
			confirmed_at DATETIME NULL,
			confirmed_date DATE NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY number (number),
			KEY status (status),
			KEY supplier (supplier)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}supplier_order_items (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			supplier_order_id BIGINT UNSIGNED NOT NULL,
			purchase_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY purchase_id (purchase_id),
			KEY supplier_order_id (supplier_order_id)
		) {$charset_collate};";

		/* -----------------------------------------------------------------
		 * Verwaltung: Urlaub und Abwesenheit
		 * --------------------------------------------------------------- */

		$t[] = "CREATE TABLE {$p}absences (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(20) NOT NULL DEFAULT 'urlaub',
			date_from DATE NOT NULL,
			date_to DATE NOT NULL,
			half_day TINYINT(1) NOT NULL DEFAULT 0,
			days DECIMAL(5,1) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'beantragt',
			note TEXT NULL,
			decided_by BIGINT UNSIGNED NULL,
			decided_at DATETIME NULL,
			decision_note VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY date_from (date_from)
		) {$charset_collate};";

		return $t;
	}
}
