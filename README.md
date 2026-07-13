# Lindenweg16 ERP

Ein einziges, in sich geschlossenes WooCommerce-Plugin für lindenweg16.de, das
Buchhaltung, Lagerverwaltung, Loyalty, Produktions-/Bestellplanung,
Preiskalkulation, Kundenbindung sowie Support & Reporting kombiniert – direkt in
WooCommerce, ohne externen Sync.

## Installation

1. Ordner als Plugin in `wp-content/plugins/` ablegen (WooCommerce muss aktiv sein).
2. Plugin aktivieren. Beim Aktivieren werden alle Datenbanktabellen angelegt, die
   Rolle `lw16_manager` mit der Capability `manage_lw16` registriert und das
   geschützte Beleg-Verzeichnis erstellt.
3. Sven und Katrin die Rolle `lw16_manager` zuweisen (Administratoren haben die
   Capability automatisch).
4. Unter **Lindenweg16 → Einstellungen** Firmendaten und Parameter pflegen.

## Architektur

- Reines PHP-Plugin nach WordPress-Konventionen, **HPOS-kompatibel**, kein Build-Tooling.
- Autoloader für den Namespace `Lindenweg16\` (Verzeichnis `includes/`).
- Eigene Capability `manage_lw16` / Rolle `lw16_manager`; alle Backend-Seiten und
  Aktionen prüfen darauf, nie pauschal auf „Administrator".
- Hintergrundjobs laufen über den **Action Scheduler** (bringt WooCommerce mit),
  nicht über rohen WP-Cron.
- Alle DB-Zugriffe über `$wpdb` mit prepared statements, Ausgaben escaped.
- Zentrale Bausteine unter `includes/Core/`: `Installer`, `Plugin`, `Roles`,
  `Settings`, `Scheduler`, `AuditLog`.

### Verzeichnisstruktur

```
lindenweg16.php              Bootstrap, Aktivierung, HPOS-Deklaration, Autoloader
includes/Core/               Installer, Plugin, Roles, Settings, Scheduler, AuditLog
includes/Admin/              Menu, SettingsPage
includes/Modules/Inventory/  Repository, StockManager, InventoryModule
includes/Modules/Accounting/ BelegRepository, EuerCalculator, AccountingModule
includes/Modules/Loyalty/    LoyaltyRepository, LoyaltyModule
includes/Modules/Pricing/    PricingCalculator, PricingModule
includes/Modules/Production/ ProductionModule
assets/                      admin.css, admin.js
```

## Umsetzungsstand

Das Plugin ist **lauffähig** und deckt das Grundgerüst (Phase 1) sowie die Kerne
der Module 1–5 ab. Der Aufbau folgt der im Prompt vorgeschlagenen Build-Reihenfolge.

### Umgesetzt

- **Phase 1 – Grundgerüst:** Aktivierung/Deaktivierung, alle Datenbanktabellen
  inkl. Indizes, Rolle/Capability, Admin-Menü „Lindenweg16", Action-Scheduler-Anbindung,
  Audit-Log-Baustein, Einstellungsseite.
- **Modul 2 – Lagerverwaltung:** Produkt-/Stücklisten-/Rezepturverwaltung,
  Echtzeit-Lagerabzug bei `woocommerce_order_status_completed` (Set → Modul →
  Rohmaterial, idempotent per Order-Meta), Wareneingang mit Einkaufseinheit-Umrechnung
  und automatischer Beleg-Verknüpfung bei Kaufteilen, manuelle Produktion,
  Bewegungshistorie (paginiert), Ampel-Übersicht, E-Mail bei Mindestbestand.
- **Modul 1 – Buchhaltung:** Belegerfassung (geschützter Upload außerhalb des
  öffentlichen Uploads-Verzeichnisses, serverseitige Verkleinerung, authentifizierte
  Auslieferung), GoBD-Festschreibung, automatische Einnahmenberechnung aus
  WooCommerce, EÜR-Übersicht nach Kategorie mit Zeitraumfilter (Transient-Cache),
  Steuer-Rücklage-Anzeige im Dashboard, §19-UStG-Hinweis.
- **Modul 3 – Loyalty:** Referral-Code-Generierung, Cookie-Tracking (nur beim
  ersten Aufruf, Stuffing-Schutz), Reward-Berechnung (Marge nur Backend), harte
  Blockierregeln + weiche Signale mit `review`-Warteschlange (Ein-Klick Freigeben/
  Ablehnen), Punktevergabe pro Euro, täglicher Reifeprozess (Wartezeit → Gutschrift),
  Reversal bei Storno/Rückgabe.
- **Modul 5 – Preiskalkulation:** globale Annahmen, editierbare Kostenfelder je
  Modul/Set, Kosten-/Preis-/Margenberechnung, „Preis übernehmen" schreibt
  `_regular_price` und `_lw16_margin_percent` (Konsistenz mit dem Referral-Programm),
  Audit-Log-Eintrag.
- **Modul 4 (Kern) – Druck-Queue:** aus offenen Bestellungen (Status `processing`),
  aufgelöst in Module inkl. Bestandsabgleich und fehlender Menge.

### Noch offen (klar abgegrenzt, Datenmodell steht bereits)

Folgende Punkte sind im Datenmodell und in der Menüstruktur vorbereitet, aber noch
nicht ausprogrammiert:

- **Modul 1:** PDF-Export der EÜR inkl. Beleg-Anhänge, automatische PDF-Rechnung
  je Bestellung (lückenlose Rechnungsnummern – Tabelle `lw16_rechnungen` vorhanden;
  vorgesehene Konvention: `JJJJ-NNNN`, pro Kalenderjahr neu beginnend).
- **Modul 3:** Checkout-Einlösung (Guthaben + Punkte) und Mein-Konto-Tab „Prämien".
- **Modul 4:** Packliste/Versandschein-PDF, DHL-Label-Verlinkung, DHL-Sendungs-
  verfolgung (Cron + `lw16_tracking_cache` + Zeitleiste), Ist-Zeit-Erfassung
  (`lw16_produktion_log`), vorausschauende Mengenvorschläge.
- **Modul 6:** Warenkorbabbrecher-Mail, Wunschliste, Post-Purchase-Sequenz,
  „Wieder verfügbar"-Warteliste (Hook `lw16_stock_replenished` wird bereits
  ausgelöst).
- **Modul 7:** Reklamations-Workflow, VIP-Report, Wochen-Digest.
- **Phase 9:** wöchentlicher Datenexport per E-Mail, PHPUnit-Tests.

Die vorhandenen Repositories, der `Scheduler` (Hooks bereits definiert) und die
ausgelösten Actions (`lw16_stock_replenished`, `lw16_movement_recorded`,
`lw16_dashboard_widgets`) bilden die Andockpunkte für diese Phasen.

## Sicherheit

- Beleg-Fotos liegen in `wp-content/uploads/lw16-belege/` mit `.htaccess`-Schutz
  und werden nur über einen authentifizierten Endpoint mit `manage_lw16`-Prüfung
  und Path-Traversal-Schutz ausgeliefert.
- Kritische Aktionen (Festschreibung, Referral-Freigabe/-Ablehnung, Preisübernahme)
  landen im Audit-Log.
- Nonces auf allen schreibenden Aktionen, Capability-Prüfung auf jeder Seite.
