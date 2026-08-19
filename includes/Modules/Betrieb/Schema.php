<?php
/**
 * Tabellendefinitionen des Moduls Betrieb.
 *
 * Bewusst als eigene Datei, damit der Installer nicht weiter wächst. Die
 * Definitionen sind dbDelta-tauglich (zwei Leerzeichen nach PRIMARY KEY,
 * Typen in Großschreibung, ein Feld je Zeile).
 *
 * Konvention: Die Spalten dieses Moduls sind englisch benannt – abweichend
 * vom übrigen Plugin. Grund: Das Modul ist in sich geschlossen und die
 * Fachbegriffe (sourcing_type, scrap_rate, disposition) haben keine
 * etablierte deutsche Entsprechung. Die Oberfläche ist durchgängig deutsch.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Modules\Betrieb;

defined( 'ABSPATH' ) || exit;

/**
 * Liefert alle CREATE-TABLE-Anweisungen des Moduls Betrieb.
 */
class Schema {

	/**
	 * Alle Tabellen des Moduls.
	 *
	 * @param string $p               Tabellenpräfix inkl. lw16_.
	 * @param string $charset_collate Zeichensatz-Klausel.
	 * @return string[]
	 */
	public static function tables( $p, $charset_collate ) {
		$t = array();

		/* ---------------------------------------------------------------
		 * Stammdaten: Artikel, Beschaffung, Komponenten, Stückliste
		 * ------------------------------------------------------------- */

		$t[] = "CREATE TABLE {$p}articles (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			sku VARCHAR(64) NOT NULL,
			name VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'entwicklung',
			wc_product_id BIGINT UNSIGNED NULL,
			legacy_produkt_id BIGINT UNSIGNED NULL,
			sourcing_type VARCHAR(20) NOT NULL DEFAULT 'druck',
			sellable TINYINT(1) NOT NULL DEFAULT 1,
			price_gross DECIMAL(10,4) NULL,
			dealer_price DECIMAL(10,4) NULL,
			weight_g DECIMAL(10,3) NULL,
			postprocessing_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			load_rating_kg DECIMAL(10,3) NULL,
			discountable TINYINT(1) NOT NULL DEFAULT 1,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sku (sku),
			KEY wc_product_id (wc_product_id),
			KEY legacy_produkt_id (legacy_produkt_id),
			KEY status (status),
			KEY sourcing_type (sourcing_type)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}article_sourcing (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			article_id BIGINT UNSIGNED NOT NULL,
			supplier_name VARCHAR(255) NULL,
			supplier_contact VARCHAR(255) NULL,
			purchase_price_net DECIMAL(10,4) NOT NULL DEFAULT 0,
			min_order_qty DECIMAL(10,3) NOT NULL DEFAULT 1,
			lead_time_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			own_processing_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			unit_note TEXT NULL,
			valid_from DATE NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY article_valid (article_id, valid_from)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}components (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(64) NOT NULL,
			name VARCHAR(255) NOT NULL,
			category VARCHAR(30) NOT NULL DEFAULT 'sonstiges',
			supplier VARCHAR(255) NULL,
			pack_qty DECIMAL(10,3) NOT NULL DEFAULT 1,
			pack_price_net DECIMAL(10,4) NOT NULL DEFAULT 0,
			lead_time_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			min_order_qty DECIMAL(10,3) NOT NULL DEFAULT 1,
			stock_qty DECIMAL(10,3) NOT NULL DEFAULT 0,
			safety_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY category (category)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}bom (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			parent_article_id BIGINT UNSIGNED NOT NULL,
			child_type VARCHAR(20) NOT NULL DEFAULT 'komponente',
			child_id BIGINT UNSIGNED NOT NULL,
			qty DECIMAL(10,3) NOT NULL DEFAULT 1,
			position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			note VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY parent_article_id (parent_article_id),
			KEY child (child_type, child_id)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}article_revisions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			article_id BIGINT UNSIGNED NOT NULL,
			version VARCHAR(20) NOT NULL,
			material VARCHAR(20) NULL,
			nozzle_mm DECIMAL(10,3) NULL,
			layer_mm DECIMAL(10,3) NULL,
			perimeters TINYINT UNSIGNED NULL,
			infill_pct DECIMAL(10,3) NULL,
			infill_pattern VARCHAR(50) NULL,
			nozzle_temp SMALLINT UNSIGNED NULL,
			bed_temp SMALLINT UNSIGNED NULL,
			cooling_pct SMALLINT UNSIGNED NULL,
			supports TINYINT(1) NOT NULL DEFAULT 0,
			orientation_note TEXT NULL,
			orientation_image_id BIGINT UNSIGNED NULL,
			parts_per_plate SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			print_time_plate_h DECIMAL(8,3) NOT NULL DEFAULT 0,
			weight_g DECIMAL(10,3) NOT NULL DEFAULT 0,
			released_at DATETIME NULL,
			released_by BIGINT UNSIGNED NULL,
			superseded_by BIGINT UNSIGNED NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY article_version (article_id, version),
			KEY is_active (article_id, is_active)
		) {$charset_collate};";

		/* ---------------------------------------------------------------
		 * Fertigung: Drucker, Druckjournal, Filament, Chargen
		 * ------------------------------------------------------------- */

		$t[] = "CREATE TABLE {$p}printers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(20) NOT NULL,
			model VARCHAR(255) NULL,
			serial VARCHAR(100) NULL,
			acquired_on DATE NULL,
			acquisition_cost DECIMAL(10,4) NULL,
			nozzle_mm DECIMAL(10,3) NULL,
			lifetime_h DECIMAL(10,3) NULL,
			power_kw DECIMAL(10,3) NULL,
			spare_parts_per_h DECIMAL(10,4) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'betrieb',
			hours_at_last_nozzle_change DECIMAL(8,3) NOT NULL DEFAULT 0,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY status (status)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}print_jobs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_date DATE NOT NULL,
			iso_week TINYINT UNSIGNED NOT NULL DEFAULT 1,
			iso_year SMALLINT UNSIGNED NOT NULL DEFAULT 2026,
			printer_id BIGINT UNSIGNED NOT NULL,
			article_id BIGINT UNSIGNED NOT NULL,
			article_revision_id BIGINT UNSIGNED NULL,
			color VARCHAR(50) NULL,
			filament_lot_id BIGINT UNSIGNED NULL,
			parts_planned DECIMAL(10,3) NOT NULL DEFAULT 0,
			parts_ok DECIMAL(10,3) NOT NULL DEFAULT 0,
			failure_reason VARCHAR(30) NOT NULL DEFAULT 'keine',
			print_time_h DECIMAL(8,3) NOT NULL DEFAULT 0,
			plate_changes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			batch_id BIGINT UNSIGNED NULL,
			note TEXT NULL,
			voided_at DATETIME NULL,
			voided_by BIGINT UNSIGNED NULL,
			void_reason VARCHAR(255) NULL,
			reverses_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY job_date (job_date),
			KEY iso (iso_year, iso_week),
			KEY printer_id (printer_id),
			KEY article_id (article_id),
			KEY filament_lot_id (filament_lot_id),
			KEY batch_id (batch_id)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}filament_lots (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lot_code VARCHAR(64) NOT NULL,
			received_on DATE NULL,
			material VARCHAR(20) NULL,
			manufacturer VARCHAR(255) NULL,
			color VARCHAR(50) NULL,
			kg_received DECIMAL(10,3) NOT NULL DEFAULT 0,
			price_per_kg DECIMAL(10,4) NOT NULL DEFAULT 0,
			dried_on DATE NULL,
			location VARCHAR(100) NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY lot_code (lot_code),
			KEY material (material)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}batches (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_code VARCHAR(64) NOT NULL,
			produced_on DATE NOT NULL,
			article_id BIGINT UNSIGNED NOT NULL,
			article_revision_id BIGINT UNSIGNED NULL,
			filament_lot_id BIGINT UNSIGNED NULL,
			printer_id BIGINT UNSIGNED NULL,
			qty DECIMAL(10,3) NOT NULL DEFAULT 0,
			inspected TINYINT(1) NOT NULL DEFAULT 0,
			disposition VARCHAR(100) NULL,
			note TEXT NULL,
			voided_at DATETIME NULL,
			void_reason VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY batch_code (batch_code),
			KEY article_id (article_id),
			KEY filament_lot_id (filament_lot_id),
			KEY produced_on (produced_on)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}tensile_tests (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			test_date DATE NOT NULL,
			article_id BIGINT UNSIGNED NOT NULL,
			article_revision_id BIGINT UNSIGNED NOT NULL,
			filament_lot_id BIGINT UNSIGNED NULL,
			sample_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			break_load_n DECIMAL(10,3) NOT NULL DEFAULT 0,
			failure_mode VARCHAR(255) NULL,
			tester VARCHAR(100) NULL,
			note TEXT NULL,
			voided_at DATETIME NULL,
			void_reason VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY revision (article_revision_id),
			KEY article_id (article_id)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}maintenance_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			printer_id BIGINT UNSIGNED NOT NULL,
			performed_on DATE NOT NULL,
			interval_type VARCHAR(20) NOT NULL DEFAULT 'woechentlich',
			tasks_json LONGTEXT NULL,
			operating_hours_at DECIMAL(8,3) NULL,
			part_replaced VARCHAR(255) NULL,
			next_due_hours DECIMAL(8,3) NULL,
			performed_by BIGINT UNSIGNED NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY printer_id (printer_id),
			KEY performed_on (performed_on)
		) {$charset_collate};";

		/* ---------------------------------------------------------------
		 * Lager
		 * ------------------------------------------------------------- */

		$t[] = "CREATE TABLE {$p}stock_movements (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			article_id BIGINT UNSIGNED NOT NULL,
			color VARCHAR(50) NULL,
			qty DECIMAL(10,3) NOT NULL DEFAULT 0,
			movement_type VARCHAR(30) NOT NULL,
			location VARCHAR(20) NOT NULL DEFAULT 'lager',
			contact_id BIGINT UNSIGNED NULL,
			ref_type VARCHAR(20) NULL,
			ref_id BIGINT UNSIGNED NULL,
			moved_at DATETIME NOT NULL,
			note TEXT NULL,
			reverses_id BIGINT UNSIGNED NULL,
			void_reason VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY article_color (article_id, color),
			KEY location (location),
			KEY movement_type (movement_type),
			KEY contact_id (contact_id),
			KEY ref (ref_type, ref_id),
			KEY moved_at (moved_at)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}stock_targets (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			article_id BIGINT UNSIGNED NOT NULL,
			color VARCHAR(50) NOT NULL DEFAULT '',
			target_qty DECIMAL(10,3) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY article_color (article_id, color)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}supplies (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			unit VARCHAR(30) NOT NULL DEFAULT 'Stück',
			stock_qty DECIMAL(10,3) NOT NULL DEFAULT 0,
			min_order_qty DECIMAL(10,3) NOT NULL DEFAULT 1,
			lead_time_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			supplier VARCHAR(255) NULL,
			price_per_unit DECIMAL(10,4) NOT NULL DEFAULT 0,
			consumption_per_order DECIMAL(10,3) NOT NULL DEFAULT 0,
			safety_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY name (name)
		) {$charset_collate};";

		/* ---------------------------------------------------------------
		 * Bestellungen, Zeit, Kapazität
		 * ------------------------------------------------------------- */

		$t[] = "CREATE TABLE {$p}order_ops (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wc_order_id BIGINT UNSIGNED NULL,
			contact_id BIGINT UNSIGNED NULL,
			manual_order_value DECIMAL(10,4) NULL,
			manual_order_date DATE NULL,
			handling_minutes SMALLINT UNSIGNED NULL,
			personalized TINYINT(1) NOT NULL DEFAULT 0,
			shipping_method VARCHAR(100) NULL,
			shipping_cost_actual DECIMAL(10,4) NULL,
			returned TINYINT(1) NOT NULL DEFAULT 0,
			complaint TINYINT(1) NOT NULL DEFAULT 0,
			review_received VARCHAR(10) NOT NULL DEFAULT 'offen',
			customer_type VARCHAR(20) NOT NULL DEFAULT 'B2C',
			channel VARCHAR(30) NOT NULL DEFAULT 'Shop',
			price_level VARCHAR(20) NOT NULL DEFAULT 'uvp',
			applied_discount_pct DECIMAL(10,3) NOT NULL DEFAULT 0,
			applied_tier_id BIGINT UNSIGNED NULL,
			payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			invoice_status VARCHAR(20) NOT NULL DEFAULT 'offen',
			invoice_date DATE NULL,
			paid_date DATE NULL,
			order_status VARCHAR(20) NOT NULL DEFAULT 'bestaetigt',
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wc_order_id (wc_order_id),
			KEY contact_id (contact_id),
			KEY customer_type (customer_type),
			KEY invoice_status (invoice_status),
			KEY manual_order_date (manual_order_date)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}order_op_items (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_op_id BIGINT UNSIGNED NOT NULL,
			article_id BIGINT UNSIGNED NOT NULL,
			color VARCHAR(50) NULL,
			qty DECIMAL(10,3) NOT NULL DEFAULT 1,
			unit_price_gross DECIMAL(10,4) NOT NULL DEFAULT 0,
			discountable TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY order_op_id (order_op_id),
			KEY article_id (article_id)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}time_entries (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_date DATE NOT NULL,
			iso_week TINYINT UNSIGNED NOT NULL DEFAULT 1,
			iso_year SMALLINT UNSIGNED NOT NULL DEFAULT 2026,
			category VARCHAR(30) NOT NULL DEFAULT 'sonstiges',
			description VARCHAR(255) NULL,
			hours DECIMAL(8,3) NOT NULL DEFAULT 0,
			phase_block VARCHAR(2) NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY entry_date (entry_date),
			KEY iso (iso_year, iso_week),
			KEY category (category)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}capacity_weeks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			iso_year SMALLINT UNSIGNED NOT NULL,
			iso_week TINYINT UNSIGNED NOT NULL,
			printer_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
			demand_current_h DECIMAL(8,3) NOT NULL DEFAULT 0,
			demand_prebuild_h DECIMAL(8,3) NOT NULL DEFAULT 0,
			hour_budget DECIMAL(8,3) NOT NULL DEFAULT 0,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY iso (iso_year, iso_week)
		) {$charset_collate};";

		/* ---------------------------------------------------------------
		 * Bestellarten (Sets)
		 * ------------------------------------------------------------- */

		$t[] = "CREATE TABLE {$p}bundles (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			bundle_price_gross DECIMAL(10,4) NOT NULL DEFAULT 0,
			active TINYINT(1) NOT NULL DEFAULT 1,
			valid_from DATE NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY active (active)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}bundle_items (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bundle_id BIGINT UNSIGNED NOT NULL,
			article_id BIGINT UNSIGNED NOT NULL,
			qty DECIMAL(10,3) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY bundle_id (bundle_id),
			KEY article_id (article_id)
		) {$charset_collate};";

		/* ---------------------------------------------------------------
		 * B2B: Kontakte, Profile, Konditionen, Token
		 * ------------------------------------------------------------- */

		$t[] = "CREATE TABLE {$p}contacts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(20) NOT NULL DEFAULT 'lead',
			company VARCHAR(255) NOT NULL,
			city VARCHAR(100) NULL,
			contact_person VARCHAR(255) NULL,
			phone VARCHAR(50) NULL,
			email VARCHAR(191) NULL,
			source VARCHAR(255) NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'identifiziert',
			follow_up_on DATE NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY type (type),
			KEY status (status),
			KEY follow_up_on (follow_up_on)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}contact_activities (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			activity_date DATE NOT NULL,
			activity_type VARCHAR(20) NOT NULL DEFAULT 'anruf',
			summary TEXT NULL,
			next_step VARCHAR(255) NULL,
			follow_up_on DATE NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY contact_id (contact_id),
			KEY activity_date (activity_date),
			KEY follow_up_on (follow_up_on)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}contact_profiles (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			billing_company VARCHAR(255) NULL,
			billing_address TEXT NULL,
			billing_email VARCHAR(191) NULL,
			vat_id VARCHAR(50) NULL,
			shipping_address TEXT NULL,
			delivery_notes TEXT NULL,
			decision_maker VARCHAR(255) NULL,
			goods_receiver VARCHAR(255) NULL,
			preferred_channel VARCHAR(30) NULL,
			best_time VARCHAR(255) NULL,
			preferred_articles LONGTEXT NULL,
			preferred_colors VARCHAR(255) NULL,
			excluded_articles LONGTEXT NULL,
			order_frequency VARCHAR(20) NULL,
			season_peak VARCHAR(255) NULL,
			next_expected_order DATE NULL,
			packaging_preference VARCHAR(30) NULL,
			display_provided TINYINT(1) NOT NULL DEFAULT 0,
			shelf_situation TEXT NULL,
			competitor_products TEXT NULL,
			commission_allowed TINYINT(1) NOT NULL DEFAULT 0,
			credit_limit DECIMAL(10,4) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY contact_id (contact_id)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}price_tiers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tier_name VARCHAR(100) NOT NULL,
			min_order_value DECIMAL(10,4) NOT NULL DEFAULT 0,
			discount_pct DECIMAL(10,3) NOT NULL DEFAULT 0,
			valid_from DATE NOT NULL,
			valid_until DATE NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY valid_from (valid_from)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}contact_terms (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			min_order_value DECIMAL(10,4) NOT NULL DEFAULT 0,
			discount_pct DECIMAL(10,3) NOT NULL DEFAULT 0,
			payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			exclusive_postcode VARCHAR(20) NULL,
			valid_from DATE NOT NULL,
			valid_until DATE NULL,
			agreed_by VARCHAR(255) NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY contact_valid (contact_id, valid_from)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}reorder_tokens (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			token_hash VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NULL,
			revoked_at DATETIME NULL,
			last_used_at DATETIME NULL,
			use_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY contact_id (contact_id)
		) {$charset_collate};";

		/* ---------------------------------------------------------------
		 * Kalkulationsannahmen und Kennzahlen
		 * ------------------------------------------------------------- */

		$t[] = "CREATE TABLE {$p}calc_settings (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			setting_key VARCHAR(64) NOT NULL,
			value_decimal DECIMAL(14,6) NOT NULL DEFAULT 0,
			valid_from DATE NOT NULL,
			note VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY key_valid (setting_key, valid_from)
		) {$charset_collate};";

		$t[] = "CREATE TABLE {$p}kpi_snapshots (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			period_year SMALLINT UNSIGNED NOT NULL,
			period_month TINYINT UNSIGNED NOT NULL,
			metric_key VARCHAR(64) NOT NULL,
			value_decimal DECIMAL(14,6) NOT NULL DEFAULT 0,
			is_manual TINYINT(1) NOT NULL DEFAULT 0,
			frozen_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY period_metric (period_year, period_month, metric_key)
		) {$charset_collate};";

		return $t;
	}
}
