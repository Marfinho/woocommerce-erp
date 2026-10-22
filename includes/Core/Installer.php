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
	 *
	 * DB_VERSION 29: Die Bambu-Lab-/Plattenanordnungs-/Slicing-Tabellen
	 * (bambu_printers, bambu_jobs, bambu_plate_jobs, slicing_profiles,
	 * slicing_jobs) kamen dazu, ohne dass diese Nummer mit hochgezählt
	 * wurde - auf jeder Installation, die den vorherigen Stand schon
	 * einmal geladen hatte, lief create_tables() dadurch nie für sie:
	 * maybe_upgrade() vergleicht nur diese Zahl, nicht den tatsächlichen
	 * Tabellenbestand. Sichtbar wurde das dadurch, dass ein neu
	 * angelegter Bambu-Drucker zwar als "angelegt" gemeldet wurde
	 * (INSERT gegen eine nicht existierende Tabelle schlägt bei
	 * $wpdb->insert() standardmässig ohne Fehlermeldung fehl), aber nie
	 * in der Liste auftauchte.
	 *
	 * DB_VERSION 30: bambu_printers um ams_json (AMS-Belegung: Material,
	 * Farbe, Restmenge je Fach) und snapshot_at (Zeitpunkt des letzten
	 * Kamera-Standbilds) erweitert.
	 *
	 * DB_VERSION 31: bambu_jobs um ams_tray_id (gewähltes AMS-Fach für
	 * den gesamten Druckauftrag) erweitert.
	 *
	 * DB_VERSION 32: article_revisions um color (Filamentfarbe) erweitert;
	 * slicing_profiles ebenso, und der bisherige eindeutige Schlüssel
	 * material+printer_model durch material+color+printer_model ersetzt -
	 * vorher gab es systemweit nur EIN Profil (und damit eine Farbe) je
	 * Material, unabhängig davon, welche Farbe ein Artikel tatsächlich
	 * braucht. dbDelta legt die neue Spalte automatisch an, den alten
	 * eindeutigen Schlüssel muss maybe_replace_slicing_profile_unique_key()
	 * explizit entfernen (dbDelta löscht Indizes nicht selbst).
	 *
	 * DB_VERSION 33: neue Tabelle bambu_filament_catalog - ein Katalog
	 * aller echten Bambu-Lab-Filamente (per RFID-ID, z. B. "GFA01"), die
	 * je in einem AMS gesteckt haben oder in einem Slicing-Profil
	 * hochgeladen wurden. Die freie Farb-Texteingabe aus DB_VERSION 32
	 * wird dadurch zu einer Auswahl aus diesem Katalog (siehe
	 * BambuFilamentCatalogRepository).
	 *
	 * DB_VERSION 34: neue Tabelle ar_stats - anonyme Tageszähler der
	 * AR-Ansicht (Banner gezeigt, Ja, Nein, AR gestartet je Produkt).
	 *
	 * DB_VERSION 35: Mailbox - sechs neue Tabellen (mail_accounts,
	 * mail_shares, mail_folders, mail_messages, mail_attachments,
	 * mail_notes), siehe Modules\Mailbox\Schema.
	 *
	 * DB_VERSION 36: ERP-Workflow - neue Tabelle runs (Platten) und
	 * time_entries.run_id, siehe Erp\Schema.
	 *
	 * DB_VERSION 40: Tabelle traffic (anonymer Seitenaufruf-Zähler für das Live-Dashboard im ERP).
	 *
	 * DB_VERSION 39: Tabelle payslips (Lohnabrechnungen aus erfassten Zeiten, im ERP unter Löhne).
	 *
	 * DB_VERSION 37: purchases um item_ref (Filamentfarbe) erweitert; Bestellungen
	 * gibt es nun auch für Zukaufartikel und Filament.
	 *
	 * DB_VERSION 38: bambu_printers um current_file (Dateiname des Jobs auf dem
	 * Drucker) – damit ordnet der ERP-Workflow einen Druck der Platte zu.
	 */
	const DB_VERSION = '40';

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
		add_rewrite_rule( '^betrieb-erfassen/?$', 'index.php?lw16_erfassung=1', 'top' );
		add_rewrite_rule( '^nachbestellen/([a-f0-9]{64})/?$', 'index.php?lw16_reorder=1&lw16_token=$matches[1]', 'top' );
		add_rewrite_endpoint( 'praemien', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'wunschliste', EP_ROOT | EP_PAGES );
		flush_rewrite_rules();

		// Sicherheitshalber nochmal beim nächsten init flushen.
		update_option( 'lw16_flush_rewrite', 1 );

		// Hintergrundjobs beim naechsten Aufruf sofort neu einplanen.
		delete_transient( Scheduler::TRANSIENT_CHECKED );
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
		delete_transient( Scheduler::TRANSIENT_CHECKED );
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

			// Neue Hintergrundjobs (z. B. der Mailabruf) sofort einplanen, nicht erst
			// nach Ablauf des Stunden-Merkers in Scheduler::ensure_recurring().
			delete_transient( Scheduler::TRANSIENT_CHECKED );

			// Ein Update laeuft ohne Aktivierung durch. Ohne diesen Merker
			// blieben Rewrite-Regeln, die erst in der neuen Fassung dazukommen,
			// unbekannt – die Adressen liefen dann dauerhaft auf 404.
			update_option( 'lw16_flush_rewrite', 1 );
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
	 * Absoluter Pfad zum geschützten Verzeichnis der 3MF-Modelle.
	 *
	 * Konstruktionsdaten liegen bewusst nicht in der Mediathek: dort wäre
	 * jede Datei über ihre Adresse öffentlich abrufbar.
	 *
	 * @return string
	 */
	public static function models_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'lw16-modelle';
	}

	/**
	 * Absoluter Pfad zum geschützten Verzeichnis der Mail-Anhänge.
	 *
	 * @return string
	 */
	public static function mail_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'lw16-mail';
	}

	/**
	 * Dokumentenverzeichnis anlegen, falls es fehlt.
	 *
	 * Angelegt wird es beim Aktivieren. Ein Verzeichnis kann aber auch
	 * später verschwinden – bei einem Umzug, beim Aufräumen des
	 * Upload-Ordners. Wer eine Datei ablegt, die es sonst nirgends mehr
	 * gibt – ein Versandlabel etwa –, prüft das vorher.
	 */
	public static function ensure_docs_dir() {
		self::protect_belege_directory();
	}

	/**
	 * Beleg-Verzeichnis außerhalb des öffentlichen Zugriffs absichern.
	 */
	protected static function protect_belege_directory() {
		foreach ( array( self::belege_dir(), self::docs_dir(), self::models_dir(), self::mail_dir() ) as $dir ) {
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
			belegnummer VARCHAR(50) NULL,
			beleg_jahr SMALLINT UNSIGNED NULL,
			beleg_laufnummer INT UNSIGNED NULL,
			datei_pfad VARCHAR(255) NULL,
			betrag DECIMAL(12,2) NOT NULL DEFAULT 0,
			datum DATE NOT NULL,
			haendler VARCHAR(255) NULL,
			eur_kategorie VARCHAR(100) NULL,
			konto VARCHAR(10) NULL,
			notiz TEXT NULL,
			quelle VARCHAR(20) NOT NULL DEFAULT 'manuell',
			festgeschrieben TINYINT(1) NOT NULL DEFAULT 0,
			festgeschrieben_am DATETIME NULL,
			storniert TINYINT(1) NOT NULL DEFAULT 0,
			storno_von_id BIGINT UNSIGNED NULL,
			storno_grund TEXT NULL,
			hash VARCHAR(64) NOT NULL DEFAULT '',
			vorheriger_hash VARCHAR(64) NOT NULL DEFAULT '',
			erstellt_am DATETIME NOT NULL,
			erstellt_von BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY datum (datum),
			KEY eur_kategorie (eur_kategorie),
			KEY konto (konto),
			KEY festgeschrieben (festgeschrieben),
			KEY quelle (quelle),
			KEY beleg_jahr (beleg_jahr),
			KEY belegnummer (belegnummer)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}rechnungen (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			rechnungsnummer VARCHAR(50) NOT NULL,
			jahr SMALLINT UNSIGNED NOT NULL,
			laufnummer INT UNSIGNED NOT NULL,
			datei_pfad VARCHAR(255) NULL,
			betrag DECIMAL(12,2) NOT NULL DEFAULT 0,
			storniert TINYINT(1) NOT NULL DEFAULT 0,
			storno_von_id BIGINT UNSIGNED NULL,
			storno_grund TEXT NULL,
			hash VARCHAR(64) NOT NULL DEFAULT '',
			vorheriger_hash VARCHAR(64) NOT NULL DEFAULT '',
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

		$tables[] = "CREATE TABLE {$p}shipping_labels (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			shipment_no VARCHAR(50) NOT NULL,
			product VARCHAR(20) NOT NULL,
			billing_number VARCHAR(20) NOT NULL,
			filename VARCHAR(191) NOT NULL,
			weight_g INT UNSIGNED NOT NULL DEFAULT 0,
			storniert TINYINT(1) NOT NULL DEFAULT 0,
			erstellt_am DATETIME NOT NULL,
			storniert_am DATETIME NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY shipment_no (shipment_no),
			KEY storniert (storniert)
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
			produkt_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			article_id BIGINT UNSIGNED NULL,
			email VARCHAR(191) NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			benachrichtigt TINYINT(1) NOT NULL DEFAULT 0,
			erstellt_am DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY produkt_id (produkt_id),
			KEY article_id (article_id),
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
			payload_before_json LONGTEXT NULL,
			payload_after_json LONGTEXT NULL,
			grund VARCHAR(255) NULL,
			hash VARCHAR(64) NOT NULL DEFAULT '',
			vorheriger_hash VARCHAR(64) NOT NULL DEFAULT '',
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
			kampagne_id BIGINT UNSIGNED NULL,
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
			KEY beleg_id (beleg_id),
			KEY kampagne_id (kampagne_id)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}kasse_positionen (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tag_id BIGINT UNSIGNED NOT NULL,
			produkt_id BIGINT UNSIGNED NULL,
			article_id BIGINT UNSIGNED NULL,
			artikel_name VARCHAR(255) NOT NULL,
			menge INT NOT NULL DEFAULT 1,
			einzelpreis DECIMAL(12,2) NOT NULL DEFAULT 0,
			betrag DECIMAL(12,2) NOT NULL DEFAULT 0,
			storniert TINYINT(1) NOT NULL DEFAULT 0,
			erstellt_am DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tag_id (tag_id),
			KEY produkt_id (produkt_id),
			KEY article_id (article_id),
			KEY storniert (storniert)
		) {$charset_collate};";

		// --- Modul 1: Buchungsjournal (GoBD) ---------------------------------
		$tables[] = "CREATE TABLE {$p}buchungen (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			buchungsnummer VARCHAR(50) NOT NULL,
			jahr SMALLINT UNSIGNED NOT NULL,
			laufnummer INT UNSIGNED NOT NULL,
			buchungsdatum DATE NOT NULL,
			erfasst_am DATETIME NOT NULL,
			typ VARCHAR(20) NOT NULL DEFAULT 'ausgabe',
			betrag DECIMAL(12,2) NOT NULL DEFAULT 0,
			kategorie VARCHAR(100) NOT NULL DEFAULT '',
			beschreibung TEXT NULL,
			referenz_typ VARCHAR(50) NOT NULL DEFAULT '',
			referenz_id BIGINT UNSIGNED NULL,
			storniert TINYINT(1) NOT NULL DEFAULT 0,
			storno_von_id BIGINT UNSIGNED NULL,
			hash VARCHAR(64) NOT NULL DEFAULT '',
			vorheriger_hash VARCHAR(64) NOT NULL DEFAULT '',
			erstellt_von BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY jahr (jahr),
			KEY buchungsdatum (buchungsdatum),
			KEY typ (typ),
			KEY referenz (referenz_typ, referenz_id),
			KEY buchungsnummer (buchungsnummer)
		) {$charset_collate};";

		// --- Modul 1: E-Rechnungs-Eingang --------------------------------
		$tables[] = "CREATE TABLE {$p}erechnung_eingang (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			datei_pfad VARCHAR(255) NOT NULL DEFAULT '',
			format VARCHAR(20) NOT NULL DEFAULT '',
			absender_name VARCHAR(255) NULL,
			absender_steuer_id VARCHAR(50) NULL,
			rechnungsnummer_ext VARCHAR(100) NULL,
			betrag DECIMAL(12,2) NOT NULL DEFAULT 0,
			datum DATE NOT NULL,
			xml_inhalt LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'empfangen',
			beleg_id BIGINT UNSIGNED NULL,
			hash VARCHAR(64) NOT NULL DEFAULT '',
			vorheriger_hash VARCHAR(64) NOT NULL DEFAULT '',
			erstellt_am DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY datum (datum),
			KEY beleg_id (beleg_id)
		) {$charset_collate};";

		// --- Modul 1: Kassenbuch (GoBD) ----------------------------------
		$tables[] = "CREATE TABLE {$p}kassenbuch (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kassenbuchnummer VARCHAR(50) NOT NULL,
			jahr SMALLINT UNSIGNED NOT NULL,
			laufnummer INT UNSIGNED NOT NULL,
			datum DATE NOT NULL,
			beschreibung VARCHAR(255) NOT NULL DEFAULT '',
			einnahme DECIMAL(12,2) NOT NULL DEFAULT 0,
			ausgabe DECIMAL(12,2) NOT NULL DEFAULT 0,
			saldo DECIMAL(12,2) NOT NULL DEFAULT 0,
			beleg_id BIGINT UNSIGNED NULL,
			storniert TINYINT(1) NOT NULL DEFAULT 0,
			storno_von_id BIGINT UNSIGNED NULL,
			hash VARCHAR(64) NOT NULL DEFAULT '',
			vorheriger_hash VARCHAR(64) NOT NULL DEFAULT '',
			erstellt_am DATETIME NOT NULL,
			erstellt_von BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY jahr (jahr),
			KEY datum (datum),
			KEY kassenbuchnummer (kassenbuchnummer),
			KEY beleg_id (beleg_id)
		) {$charset_collate};";

		// --- Modul 1: Dauerbuchungen ------------------------------------
		$tables[] = "CREATE TABLE {$p}dauerbuchungen (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bezeichnung VARCHAR(255) NOT NULL,
			betrag DECIMAL(12,2) NOT NULL DEFAULT 0,
			kategorie VARCHAR(100) NOT NULL DEFAULT '',
			typ VARCHAR(20) NOT NULL DEFAULT 'ausgabe',
			intervall VARCHAR(20) NOT NULL DEFAULT 'monatlich',
			start_datum DATE NOT NULL,
			ende_datum DATE NULL,
			naechste_ausfuehrung DATE NOT NULL,
			letzte_ausfuehrung DATE NULL,
			aktiv TINYINT(1) NOT NULL DEFAULT 1,
			erstellt_am DATETIME NOT NULL,
			erstellt_von BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY aktiv (aktiv),
			KEY naechste_ausfuehrung (naechste_ausfuehrung)
		) {$charset_collate};";

		// --- Modul 1: Anlagenverzeichnis (AfA) --------------------------
		$tables[] = "CREATE TABLE {$p}anlagen (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			anlagennummer VARCHAR(50) NOT NULL,
			jahr SMALLINT UNSIGNED NOT NULL,
			laufnummer INT UNSIGNED NOT NULL,
			bezeichnung VARCHAR(255) NOT NULL,
			anschaffungsdatum DATE NOT NULL,
			anschaffungskosten DECIMAL(12,2) NOT NULL DEFAULT 0,
			nutzungsdauer_jahre INT UNSIGNED NOT NULL DEFAULT 1,
			afa_methode VARCHAR(20) NOT NULL DEFAULT 'linear',
			buchwert DECIMAL(12,2) NOT NULL DEFAULT 0,
			beleg_id BIGINT UNSIGNED NULL,
			kategorie VARCHAR(100) NOT NULL DEFAULT '',
			ausgeschieden TINYINT(1) NOT NULL DEFAULT 0,
			ausgeschieden_am DATE NULL,
			erstellt_am DATETIME NOT NULL,
			erstellt_von BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY anlagennummer (anlagennummer),
			KEY jahr (jahr),
			KEY kategorie (kategorie),
			KEY ausgeschieden (ausgeschieden),
			KEY beleg_id (beleg_id)
		) {$charset_collate};";

		// --- Modul 1: GoBD-Prüfprotokoll ---------------------------------
		// --- Modul 11: Marketing (Kostenstellen) --------------------------
		$tables[] = "CREATE TABLE {$p}kampagnen (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kostenstelle VARCHAR(20) NOT NULL,
			name VARCHAR(255) NOT NULL,
			typ VARCHAR(20) NOT NULL DEFAULT 'event',
			kanal VARCHAR(100) NULL,
			start_datum DATE NULL,
			ende_datum DATE NULL,
			event_datum DATE NULL,
			budget_geplant DECIMAL(12,2) NOT NULL DEFAULT 0,
			utm_campaign VARCHAR(100) NULL,
			gutschein_code VARCHAR(50) NULL,
			gefahrene_km DECIMAL(10,1) NOT NULL DEFAULT 0,
			km_beleg_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'geplant',
			notiz TEXT NULL,
			erstellt_am DATETIME NOT NULL,
			erstellt_von BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY kostenstelle (kostenstelle),
			KEY typ (typ),
			KEY status (status),
			KEY utm_campaign (utm_campaign),
			KEY gutschein_code (gutschein_code)
		) {$charset_collate};";

		// Zuordnung Beleg zu Kostenstelle: bewusst eine eigene Tabelle. Belege
		// sind nach der Festschreibung unveraenderlich, die Zuordnung ist reine
		// Auswertung und muss auch danach noch korrigierbar bleiben - und ein
		// Beleg kann sich auf mehrere Kostenstellen aufteilen.
		$tables[] = "CREATE TABLE {$p}kampagne_beleg (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kampagne_id BIGINT UNSIGNED NOT NULL,
			beleg_id BIGINT UNSIGNED NOT NULL,
			anteil_prozent DECIMAL(6,3) NOT NULL DEFAULT 100,
			erstellt_am DATETIME NOT NULL,
			erstellt_von BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY kampagne_beleg (kampagne_id, beleg_id),
			KEY beleg_id (beleg_id)
		) {$charset_collate};";

		// --- Modul 12: Events ---------------------------------------------
		// Die Events selbst sind ein Custom Post Type - oeffentlicher Inhalt
		// mit Titel, Text und Bild. Die Anmeldungen sind Bewegungsdaten und
		// liegen deshalb hier.
		//
		// email ist wie in den uebrigen Tabellen auf 191 Zeichen begrenzt:
		// Laenger passt die Spalte unter utf8mb4 nicht mehr in einen Index.
		//
		// begleiter haelt die Namen der Begleitpersonen als JSON, plaetze die
		// Summe daraus einschliesslich der anmeldenden Person. Die Spalte ist
		// abgeleitet, steht aber trotzdem in der Tabelle: Die Belegung eines
		// Events ist damit eine Summe statt einer Zeilenzahl - und das ist die
		// Zahl, an der die Kapazitaet haengt.
		$tables[] = "CREATE TABLE {$p}event_rsvp (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			name VARCHAR(255) NOT NULL,
			email VARCHAR(191) NOT NULL,
			begleiter TEXT NULL,
			plaetze SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			notiz TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'offen',
			token_hash CHAR(64) NULL,
			token_ablauf DATETIME NULL,
			bestaetigt_am DATETIME NULL,
			abgesagt_am DATETIME NULL,
			erstellt_am DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_email (event_id, email),
			KEY event_status (event_id, status),
			KEY token_hash (token_hash)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$p}gobd_pruefung (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			pruef_datum DATETIME NOT NULL,
			jahr SMALLINT UNSIGNED NOT NULL,
			ergebnis VARCHAR(20) NOT NULL DEFAULT 'ok',
			fehler_anzahl INT UNSIGNED NOT NULL DEFAULT 0,
			warn_anzahl INT UNSIGNED NOT NULL DEFAULT 0,
			details LONGTEXT NULL,
			geprueft_von BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY jahr (jahr),
			KEY ergebnis (ergebnis)
		) {$charset_collate};";

		// --- Modul Betrieb ------------------------------------------------
		$tables = array_merge( $tables, \Lindenweg16\Modules\Betrieb\Schema::tables( $p, $charset_collate ) );

		// --- Modul Konfigurator -------------------------------------------
		$tables = array_merge( $tables, \Lindenweg16\Modules\Configurator\Schema::tables( $p, $charset_collate ) );

		// --- Modul AR-Ansicht ---------------------------------------------
		$tables = array_merge( $tables, \Lindenweg16\Modules\ArView\Schema::tables( $p, $charset_collate ) );

		// --- Modul Mailbox ------------------------------------------------
		$tables = array_merge( $tables, \Lindenweg16\Modules\Mailbox\Schema::tables( $p, $charset_collate ) );

		// ERP-Oberfläche: Platten (Arbeitseinheiten der Werkstatt).
		$tables = array_merge( $tables, \Lindenweg16\Erp\Schema::tables( $p, $charset_collate ) );

		// Migration: Die Druck-Warteschlange hiess zunächst DHL-spezifisch.
		// Muss vor dbDelta laufen – sonst legt dbDelta die neuen, leeren
		// Tabellen an und die alten mit den Daten blieben ungenutzt liegen.
		self::maybe_rename_print_queue_tables();

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}

		// Startwerte der Kalkulationsannahmen (nur wenn noch keine vorhanden).
		\Lindenweg16\Modules\Betrieb\CalcSettings::seed_defaults();
		\Lindenweg16\Modules\Betrieb\CalcSettings::seed_price_tiers();

		// Migration: Bestehende Daten mit Hash-Ketten versehen (einmalig).
		self::maybe_backfill_hashes();

		// Migration: Altbelege auf den Kontenrahmen heben.
		self::maybe_backfill_konten();

		// Migration: Tabellen entfernter Funktionen abräumen.
		self::drop_removed_tables();

		// Migration: DHL-Zugangsdaten auf getrennte Sandbox-/Live-Felder heben.
		self::maybe_migrate_dhl_credentials();

		// Migration: slicing_profiles von einem Profil je Material auf eines
		// je Material+Farbe umstellen.
		self::maybe_replace_slicing_profile_unique_key();
	}

	/**
	 * DB_VERSION 32: slicing_profiles hatte bisher einen eindeutigen
	 * Schlüssel material+printer_model - nur ein Profil (und damit eine
	 * Farbe) je Material war möglich. dbDelta legt die neue Spalte "color"
	 * zwar automatisch an, entfernt aber nie von sich aus einen
	 * bestehenden Index; ohne diesen Schritt bliebe der alte, zu enge
	 * Schlüssel bestehen und ein zweites Profil desselben Materials in
	 * einer anderen Farbe ließe sich nicht anlegen.
	 */
	protected static function maybe_replace_slicing_profile_unique_key() {
		global $wpdb;

		$table = $wpdb->prefix . 'lw16_slicing_profiles';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}

		$has_old_index = $wpdb->get_var(
			$wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'material_printer' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $has_old_index ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX material_printer" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Die früher einzigen DHL-Zugangsdaten (dhl_versand_api_key/api_secret/
	 * user/password) galten für Sandbox und Live gleichermaßen – das war
	 * der Grund für das Problem, das zu dieser Trennung geführt hat: Ein
	 * Live-Login an der Sandbox (oder umgekehrt) scheitert mit
	 * „invalid_grant". Wer schon Werte eingetragen hatte, hat damit fast
	 * immer den Live-Zugang gemeint – die Sandbox hat einen von DHL
	 * öffentlich dokumentierten festen Testzugang für Benutzer/Passwort,
	 * den niemand von Hand eintippt. Die alten Werte bleiben zusätzlich
	 * unangetastet stehen, falls doch etwas anderes gemeint war.
	 */
	protected static function maybe_migrate_dhl_credentials() {
		$alt = get_option( Settings::OPTION, array() );

		if ( ! is_array( $alt ) || empty( $alt['dhl_versand_api_key'] ) ) {
			return;
		}

		if ( ! empty( $alt['dhl_versand_live_api_key'] ) ) {
			return; // Schon migriert oder schon eigens gepflegt.
		}

		$alt['dhl_versand_live_api_key']    = $alt['dhl_versand_api_key'];
		$alt['dhl_versand_live_api_secret'] = isset( $alt['dhl_versand_api_secret'] ) ? $alt['dhl_versand_api_secret'] : '';
		$alt['dhl_versand_live_user']       = isset( $alt['dhl_versand_user'] ) ? $alt['dhl_versand_user'] : '';
		$alt['dhl_versand_live_password']   = isset( $alt['dhl_versand_password'] ) ? $alt['dhl_versand_password'] : '';

		update_option( Settings::OPTION, $alt );
	}

	/**
	 * Die Druck-Warteschlange hiess zunächst dhl_print_printers/
	 * dhl_print_jobs – sie war nur für DHL-Versandlabels gedacht. Seitdem
	 * trägt sie auch Artikel- und Lagerplatz-Etiketten, deshalb die
	 * generischen Namen print_queue_printers/print_queue_jobs.
	 *
	 * Ein RENAME TABLE statt eines neuen dbDelta-Anlegens, damit
	 * registrierte Drucker (samt Token) und bereits wartende Jobs eines
	 * bestehenden Systems erhalten bleiben – niemand soll seinen
	 * Raspberry Pi neu einrichten müssen, nur weil die Tabelle umbenannt
	 * wurde.
	 */
	protected static function maybe_rename_print_queue_tables() {
		global $wpdb;

		$umbenennen = array(
			$wpdb->prefix . 'lw16_dhl_print_printers' => $wpdb->prefix . 'lw16_print_queue_printers',
			$wpdb->prefix . 'lw16_dhl_print_jobs'     => $wpdb->prefix . 'lw16_print_queue_jobs',
		);

		foreach ( $umbenennen as $alt => $neu ) {
			$alt_da = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $alt ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$neu_da = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $neu ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( $alt_da && ! $neu_da ) {
				$wpdb->query( "RENAME TABLE {$alt} TO {$neu}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/**
	 * Tabellen, die zu entfernten Funktionen gehören, abräumen.
	 *
	 * dbDelta löscht nie – ohne diesen Schritt bliebe eine Tabelle stehen,
	 * die kein Code mehr liest oder schreibt. Betroffen sind ausdrücklich
	 * keine Buchhaltungsdaten, deren Aufbewahrung die GoBD verlangt.
	 */
	protected static function drop_removed_tables() {
		global $wpdb;

		// DB_VERSION 8: Zugversuche wurden ersatzlos entfernt.
		$table = $wpdb->prefix . 'lw16_tensile_tests';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Belege ohne Kontonummer aus ihrer alten Freitext-Kategorie ableiten.
	 *
	 * `eur_kategorie` bleibt dabei unangetastet: Das Feld steckt in der
	 * Hash-Kette, ein Überschreiben würde jede Prüfung der Belegkette
	 * scheitern lassen. Die Kontonummer kommt daneben.
	 */
	protected static function maybe_backfill_konten() {
		global $wpdb;

		$table = $wpdb->prefix . 'lw16_belege';

		$has_col = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'konto'",
			DB_NAME,
			$table
		) );
		if ( ! $has_col ) {
			return;
		}

		foreach ( \Lindenweg16\Modules\Accounting\Kontenrahmen::legacy_map() as $kategorie => $konto ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET konto = %s WHERE (konto IS NULL OR konto = '') AND eur_kategorie = %s", // phpcs:ignore
				$konto,
				$kategorie
			) );
		}
	}

	/**
	 * Bestehende Datensätze (vor DB_VERSION 4) nachträglich mit Hashes versehen.
	 */
	protected static function maybe_backfill_hashes() {
		if ( (int) get_option( self::DB_VERSION_OPTION ) >= 4 ) {
			return; // Bereits migriert.
		}

		global $wpdb;

		// Belege: Belegnummern nachträglich vergeben.
		$belege_table = $wpdb->prefix . 'lw16_belege';
		$has_col = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'belegnummer'",
			DB_NAME,
			$belege_table
		) );
		if ( $has_col ) {
			$unmigrated = $wpdb->get_results(
				"SELECT id, datum FROM {$belege_table} WHERE (belegnummer IS NULL OR belegnummer = '') ORDER BY id ASC", // phpcs:ignore
				ARRAY_A
			);
			$counters = array();
			foreach ( $unmigrated as $row ) {
				$jahr = (int) substr( $row['datum'], 0, 4 );
				if ( ! $jahr ) {
					$jahr = (int) gmdate( 'Y' );
				}
				if ( ! isset( $counters[ $jahr ] ) ) {
					$counters[ $jahr ] = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COALESCE(MAX(beleg_laufnummer), 0) FROM {$belege_table} WHERE beleg_jahr = %d", // phpcs:ignore
						$jahr
					) );
				}
				$counters[ $jahr ]++;
				$wpdb->update(
					$belege_table,
					array(
						'belegnummer'      => Calc::belegnummer( $jahr, $counters[ $jahr ] ),
						'beleg_jahr'       => $jahr,
						'beleg_laufnummer' => $counters[ $jahr ],
					),
					array( 'id' => (int) $row['id'] )
				);
			}
		}

		// Hash-Ketten für alle Tabellen nachträglich berechnen.
		$tables_to_hash = array(
			'belege'     => $wpdb->prefix . 'lw16_belege',
			'rechnungen' => $wpdb->prefix . 'lw16_rechnungen',
			'audit_log'  => $wpdb->prefix . 'lw16_audit_log',
		);

		foreach ( $tables_to_hash as $key => $table ) {
			$hash_col = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'hash'",
				DB_NAME,
				$table
			) );
			if ( ! $hash_col ) {
				continue;
			}
			$rows = $wpdb->get_results(
				"SELECT * FROM {$table} WHERE hash = '' OR hash IS NULL ORDER BY id ASC", // phpcs:ignore
				ARRAY_A
			);
			$prev_hash = '';
			// Finde den letzten vorhandenen Hash (falls teilweise migriert).
			$last = $wpdb->get_var( "SELECT hash FROM {$table} WHERE hash != '' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore
			if ( $last ) {
				$prev_hash = $last;
			}
			foreach ( $rows as $row ) {
				$hash = HashChain::compute( $key, $row, $prev_hash );
				$wpdb->update( $table, array( 'hash' => $hash, 'vorheriger_hash' => $prev_hash ), array( 'id' => (int) $row['id'] ) );
				$prev_hash = $hash;
			}
		}
	}
}
