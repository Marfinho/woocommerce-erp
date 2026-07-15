<?php
/**
 * Installer: Datenbanktabellen, Rolle/Capability, Verzeichnisse.
 *
 * @package Lindenweg16
 */

namespace Lindenweg16\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Legt beim Aktivieren die komplette Datenstruktur an und räumt beim
 * Deaktivieren die geplanten Hintergrundjobs wieder ab.
 */
class Installer {

	/**
	 * Option-Key für die installierte Schema-Version.
	 */
	const DB_VERSION_OPTION = 'lw16_db_version';

	/**
	 * Aktuelle Schema-Version. Bei Änderungen an den Tabellen erhöhen.
	 */
	const DB_VERSION = '3';

	/**
	 * Aktivierungsroutine.
	 */
	public static function activate() {
		self::create_tables();
		Roles::add_role();
		self::protect_belege_directory();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		// Standard-Einstellungen setzen, falls noch nicht vorhanden.
		Settings::maybe_set_defaults();

		// Endpoints registrieren, damit der anschließende Flush sie kennt.
		add_rewrite_endpoint( 'praemien', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'wunschliste', EP_ROOT | EP_PAGES );
		flush_rewrite_rules();

		// Sicherheitshalber nochmal beim nächsten init flushen.
		update_option( 'lw16_flush_rewrite', 1 );
	}

	/**
	 * Deaktivierungsroutine. Rolle und Daten bewusst NICHT löschen
	 * (GoBD: Buchhaltungsdaten müssen erhalten bleiben).
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( Scheduler::all_hooks() as $hook ) {
				as_unschedule_all_actions( $hook );
			}
		}
		flush_rewrite_rules();
	}

	/**
	 * Prüft bei jedem Laden, ob ein Schema-Upgrade nötig ist.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_tables();
			Roles::add_role();
			self::protect_belege_directory();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Absoluter Pfad zum geschützten Beleg-Verzeichnis.
	 *
	 * @return string
	 */
	public static function belege_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'lw16-belege';
	}

	/**
	 * Absoluter Pfad zum geschützten Rechnungs-/Dokumentenverzeichnis.
	 *
	 * @return string
	 */
	public static function docs_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'lw16-dokumente';
	}

	/**
	 * Beleg-Verzeichnis außerhalb des öffentlichen Zugriffs absichern.
	 */
	protected static function protect_belege_directory() {
		foreach ( array( self::belege_dir(), self::docs_dir() ) as $dir ) {
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			// Apache: direkter Zugriff verbieten.
			$htaccess = trailingslashit( $dir ) . '.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" ); // phpcs:ignore
			}

			// Verzeichnislisting verhindern.
			$index = trailingslashit( $dir ) . 'index.php';
			if ( ! file_exists( $index ) ) {
				file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore
			}
		}
	}

	/**
	 * Alle Plugin-Tabellen anlegen bzw. per dbDelta aktualisieren.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix . 'lw16_';

		$tables = array();

		// --- Modul 2: Lagerverwaltung -------------------------------------
		$tables[] = "CREATE TABLE {$p}produkte (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			woo_product_id BIGINT UNSIGNED NULL,
			name VARCHAR(255) NOT NULL,
			typ VARCHAR(20) NOT NULL,
			rohmaterial_kategorie VARCHAR(20) NULL,
			basis_einheit VARCHAR(20) NOT NULL DEFAULT 'stueck',
			einkaufseinheit_name VARCHAR(100) NULL,
			einkaufseinheit_umrechnung DECIMAL(14,4) NULL,
			mindestbestand DECIMAL(14,4) NOT NULL DEFAULT 0,
			filament_gramm DECIMAL(14,4) NULL,
			druckzeit_stunden DECIMAL(10,4) NULL,
			verpackung_euro DECIMAL(10,2) NULL,
			zusatzmaterial_euro DECIMAL(10,2) NULL,
			arbeitszeit_minuten DECIMAL(10,2) NULL,
			rabatt_prozent DECIMAL(6,3) NULL,
			erstellt_am DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY woo_product_id (woo_product_id),
			KEY typ (typ),
			KEY rohmaterial_kategorie (rohmaterial_kategorie)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}stueckliste (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			set_id BIGINT UNSIGNED NOT NULL,
			modul_id BIGINT UNSIGNED NOT NULL,
			menge DECIMAL(14,4) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY set_id (set_id),
			KEY modul_id (modul_id)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}rezeptur (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			modul_id BIGINT UNSIGNED NOT NULL,
			rohmaterial_id BIGINT UNSIGNED NOT NULL,
			menge DECIMAL(14,4) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY modul_id (modul_id),
			KEY rohmaterial_id (rohmaterial_id)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}lagerbestand (
			produkt_id BIGINT UNSIGNED NOT NULL,
			menge DECIMAL(14,4) NOT NULL DEFAULT 0,
			aktualisiert_am DATETIME NOT NULL,
			PRIMARY KEY  (produkt_id)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}lagerbestand_ort (
			produkt_id BIGINT UNSIGNED NOT NULL,
			lagerort_id BIGINT UNSIGNED NOT NULL,
			menge DECIMAL(14,4) NOT NULL DEFAULT 0,
			aktualisiert_am DATETIME NOT NULL,
			PRIMARY KEY  (produkt_id, lagerort_id),
			KEY lagerort_id (lagerort_id)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}lagerorte (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kuerzel VARCHAR(20) NOT NULL,
			name VARCHAR(255) NOT NULL,
			beschreibung TEXT NULL,
			aktiv TINYINT(1) NOT NULL DEFAULT 1,
			erstellt_am DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY kuerzel (kuerzel)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}pickliste (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'offen',
			datei_pfad VARCHAR(255) NULL,
			erstellt_von BIGINT UNSIGNED NULL,
			erstellt_am DATETIME NOT NULL,
			abgeschlossen_am DATETIME NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY status (status)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}pickliste_pos (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			pickliste_id BIGINT UNSIGNED NOT NULL,
			produkt_id BIGINT UNSIGNED NOT NULL,
			lagerort_id BIGINT UNSIGNED NULL,
			soll_menge DECIMAL(14,4) NOT NULL DEFAULT 0,
			ist_menge DECIMAL(14,4) NULL,
			gepickt TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY pickliste_id (pickliste_id),
			KEY produkt_id (produkt_id)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}lagerbewegung (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			produkt_id BIGINT UNSIGNED NOT NULL,
			lagerort_id BIGINT UNSIGNED NULL,
			menge DECIMAL(14,4) NOT NULL,
			typ VARCHAR(20) NOT NULL,
			referenz VARCHAR(191) NULL,
			beleg_id BIGINT UNSIGNED NULL,
			notiz TEXT NULL,
			erstellt_von BIGINT UNSIGNED NULL,
			erstellt_am DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY produkt_id (produkt_id),
			KEY lagerort_id (lagerort_id),
			KEY typ (typ),
			KEY beleg_id (beleg_id),
			KEY erstellt_am (erstellt_am)
		) {$charset_collate};";

		// --- Modul 1: Buchhaltung -----------------------------------------
		$tables[] = "CREATE TABLE {$p}belege (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			datei_pfad VARCHAR(255) NULL,
			betrag DECIMAL(12,2) NOT NULL DEFAULT 0,
			datum DATE NOT NULL,
			haendler VARCHAR(255) NULL,
			eur_kategorie VARCHAR(100) NULL,
			notiz TEXT NULL,
			quelle VARCHAR(20) NOT NULL DEFAULT 'manuell',
			festgeschrieben TINYINT(1) NOT NULL DEFAULT 0,
			festgeschrieben_am DATETIME NULL,
			erstellt_am DATETIME NOT NULL,
			erstellt_von BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY datum (datum),
			KEY eur_kategorie (eur_kategorie),
			KEY festgeschrieben (festgeschrieben),
			KEY quelle (quelle)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}rechnungen (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			rechnungsnummer VARCHAR(50) NOT NULL,
			jahr SMALLINT UNSIGNED NOT NULL,
			laufnummer INT UNSIGNED NOT NULL,
			datei_pfad VARCHAR(255) NULL,
			betrag DECIMAL(12,2) NOT NULL DEFAULT 0,
			erstellt_am DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY rechnungsnummer (rechnungsnummer),
			UNIQUE KEY order_id (order_id),
			KEY jahr (jahr)
		) {$charset_collate};";

		// --- Modul 3: Loyalty ---------------------------------------------
		$tables[] = "CREATE TABLE {$p}referrals (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			referrer_user_id BIGINT UNSIGNED NOT NULL,
			referred_user_id BIGINT UNSIGNED NULL,
			referred_order_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			reward_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			review_grund VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			completed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY referrer_user_id (referrer_user_id),
			KEY referred_user_id (referred_user_id),
			KEY referred_order_id (referred_order_id),
			KEY status (status)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}points_ledger (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NULL,
			points INT NOT NULL DEFAULT 0,
			type VARCHAR(30) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			notiz VARCHAR(255) NULL,
			available_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY order_id (order_id),
			KEY type (type),
			KEY status (status)
		) {$charset_collate};";

		// --- Modul 4: Produktionsplanung ----------------------------------
		$tables[] = "CREATE TABLE {$p}druckauftrag (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			produkt_id BIGINT UNSIGNED NOT NULL,
			menge DECIMAL(14,4) NOT NULL DEFAULT 1,
			status VARCHAR(20) NOT NULL DEFAULT 'geplant',
			order_referenz VARCHAR(191) NULL,
			erstellt_am DATETIME NOT NULL,
			abgeschlossen_am DATETIME NULL,
			PRIMARY KEY  (id),
			KEY produkt_id (produkt_id),
			KEY status (status)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}produktion_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			produkt_id BIGINT UNSIGNED NOT NULL,
			menge DECIMAL(14,4) NOT NULL DEFAULT 1,
			geplante_druckzeit_h DECIMAL(10,4) NULL,
			ist_druckzeit_h DECIMAL(10,4) NULL,
			geplante_arbeitszeit_min DECIMAL(10,2) NULL,
			ist_arbeitszeit_min DECIMAL(10,2) NULL,
			erstellt_am DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY produkt_id (produkt_id),
			KEY erstellt_am (erstellt_am)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}tracking_cache (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			tracking_nummer VARCHAR(100) NOT NULL,
			aktueller_status VARCHAR(100) NULL,
			voraussichtliches_lieferdatum DATE NULL,
			events LONGTEXT NULL,
			letzter_abruf DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY tracking_nummer (tracking_nummer),
			KEY aktueller_status (aktueller_status)
		) {$charset_collate};";

		// --- Modul 6: Kundenbindung ---------------------------------------
		$tables[] = "CREATE TABLE {$p}cart_tracking (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(191) NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			cart_snapshot LONGTEXT NULL,
			erstellt_am DATETIME NOT NULL,
			erinnert_am DATETIME NULL,
			zweite_erinnerung_am DATETIME NULL,
			umgewandelt TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY user_id (user_id),
			KEY umgewandelt (umgewandelt)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}wishlist (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			woo_product_id BIGINT UNSIGNED NOT NULL,
			hinzugefuegt_am DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_product (user_id, woo_product_id),
			KEY woo_product_id (woo_product_id)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}warteliste (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			produkt_id BIGINT UNSIGNED NOT NULL,
			email VARCHAR(191) NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			benachrichtigt TINYINT(1) NOT NULL DEFAULT 0,
			erstellt_am DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY produkt_id (produkt_id),
			KEY benachrichtigt (benachrichtigt),
			KEY email (email)
		) {$charset_collate};";

		// --- Modul 7: Support & Reporting ---------------------------------
		$tables[] = "CREATE TABLE {$p}tickets (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NULL,
			betreff VARCHAR(255) NOT NULL,
			nachricht TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'offen',
			erstellt_am DATETIME NOT NULL,
			beantwortet_am DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY order_id (order_id),
			KEY status (status)
		) {$charset_collate};";

		// --- Sicherheit: Audit-Log ----------------------------------------
		$tables[] = "CREATE TABLE {$p}audit_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NULL,
			aktion VARCHAR(100) NOT NULL,
			objekt_typ VARCHAR(50) NULL,
			objekt_id BIGINT UNSIGNED NULL,
			details LONGTEXT NULL,
			erstellt_am DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY aktion (aktion),
			KEY objekt (objekt_typ, objekt_id),
			KEY erstellt_am (erstellt_am)
		) {$charset_collate};";

		// --- Modul 8: Marktkasse (POS) -----------------------------------
		$tables[] = "CREATE TABLE {$p}kasse_tage (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			markt_name VARCHAR(255) NOT NULL,
			datum DATE NOT NULL,
			kassenbestand_anfang DECIMAL(12,2) NOT NULL DEFAULT 0,
			kassenbestand_ende DECIMAL(12,2) NULL,
			umsatz_gesamt DECIMAL(12,2) NOT NULL DEFAULT 0,
			notiz TEXT NULL,
			beleg_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'offen',
			erstellt_von BIGINT UNSIGNED NULL,
			erstellt_am DATETIME NOT NULL,
			abgeschlossen_am DATETIME NULL,
			PRIMARY KEY  (id),
			KEY datum (datum),
			KEY status (status),
			KEY beleg_id (beleg_id)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}kasse_positionen (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tag_id BIGINT UNSIGNED NOT NULL,
			produkt_id BIGINT UNSIGNED NULL,
			artikel_name VARCHAR(255) NOT NULL,
			menge INT NOT NULL DEFAULT 1,
			einzelpreis DECIMAL(12,2) NOT NULL DEFAULT 0,
			betrag DECIMAL(12,2) NOT NULL DEFAULT 0,
			storniert TINYINT(1) NOT NULL DEFAULT 0,
			erstellt_am DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tag_id (tag_id),
			KEY produkt_id (produkt_id),
			KEY storniert (storniert)
		) {$charset_collate};";

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}
	}
}
