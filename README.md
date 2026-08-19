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
includes/Modules/Betrieb/    Schema, CalcSettings, BetriebAudit, BetriebModule, Admin/
assets/                      admin.css, admin.js, betrieb.css
```

### Modul Betrieb

Eigenes Hauptmenü **Betrieb** für die Fertigungs-, Lager- und Vertriebsdaten
des Haster Hakens. Löst die Excel-Mappe `Haster-Haken-Datenerfassung.xlsx`
und die Papierformulare ab.

- **Eigene Tabellen** mit Präfix `lw16_` und englischen Spaltennamen
  (abweichend vom übrigen Plugin, siehe Kommentar in `Schema.php`), angelegt
  über denselben `dbDelta`-Durchlauf wie die übrigen Tabellen.
- **Capabilities** `lw16_manage_production`, `lw16_view_reports`,
  `lw16_manage_settings` und die Rolle `lw16_betrieb`. Wer `manage_lw16`
  besitzt, behält vollen Zugriff.
- **Kalkulationsannahmen** liegen historisiert in `lw16_calc_settings`: jede
  Änderung erzeugt eine Zeile mit eigenem `valid_from`, Werte werden nie
  überschrieben. Eine Kalkulation von 2026 bleibt damit 2028 nachrechenbar.
- **Maschinenstundensatz wird berechnet, nicht gepflegt**: Abschreibung
  (Anschaffung / Lebensdauer) + Verschleiß + Energie (kW × Strompreis) +
  Flächenkosten, je Gerät aus `lw16_printers` und im Mittel über die
  produzierende Flotte (`MachineRate`). Gerätefelder überschreiben die
  globale Annahme. Die Formel selbst stammt aus `Core\Calc` und wird mit der
  Preiskalkulation geteilt.
- **Protokollierung** läuft über das bestehende hashverkettete Audit-Log,
  ergänzt um Vorher-/Nachher-Abbild und Begründung. Die Kettenprüfung liegt
  unter *Betrieb → Einstellungen → Protokoll & Kettenprüfung*.

Umsetzungsstand: Meilenstein 1 (Fundament). Die übrigen Seiten sind angelegt
und benennen den Meilenstein, in dem sie Inhalt bekommen.

## Umsetzungsstand

Das Plugin ist **lauffähig** und setzt alle im Prompt beschriebenen Phasen 1–9 um.

- **Phase 1 – Grundgerüst:** Aktivierung/Deaktivierung, alle Datenbanktabellen
  inkl. Indizes, Rolle/Capability, Admin-Menü „Lindenweg16", Action-Scheduler-Anbindung,
  Audit-Log, Einstellungsseite, geschützte Datei-Verzeichnisse.
- **Modul 2 – Lagerverwaltung:** Produkt-/Stücklisten-/Rezepturverwaltung,
  Echtzeit-Lagerabzug bei `woocommerce_order_status_completed` (Set → Modul →
  Rohmaterial, idempotent), Wareneingang mit Einkaufseinheit-Umrechnung und
  automatischer Beleg-Verknüpfung bei Kaufteilen, manuelle Produktion,
  Bewegungshistorie (paginiert), Ampel-Übersicht, Mindestbestand-Mail.
- **Modul 1 – Buchhaltung:** geschützte Belegerfassung (Upload außerhalb Uploads,
  Verkleinerung, authentifizierte Auslieferung), GoBD-Festschreibung, Einnahmen
  automatisch aus WooCommerce, EÜR nach Kategorie (Transient-Cache),
  **EÜR-PDF-Export inkl. Beleg-Fotos**, **automatische lückenlose PDF-Rechnungen**
  (Konvention `JJJJ-NNNN`, pro Jahr neu; im Admin und Kundenkonto abrufbar),
  Steuer-Rücklage im Dashboard, §19-UStG-Hinweis.
- **Modul 3 – Loyalty:** Referral-Codes, Cookie-Tracking (Stuffing-Schutz),
  Reward-Berechnung (Marge nur Backend), harte Blockregeln + weiche Signale mit
  `review`-Warteschlange (Ein-Klick), Punktevergabe, täglicher Reifeprozess,
  Reversal, **Checkout-Einlösung** (Guthaben + Punkte, gedeckelt) und
  **Mein-Konto-Tab „Prämien"**.
- **Modul 4 – Produktionsplanung:** Druck-Queue aus offenen Bestellungen,
  **Packliste/Versandschein-PDF** (Sets aufgelöst), **DHL-Label-Verlinkung**,
  **DHL-Sendungsverfolgung** (Cron + Cache + Zeitleiste im Konto), Druckauftrags-
  Status, **Ist-Zeit-Erfassung** + Ist-vs-Plan-Auswertung, **Mengenvorschläge**.
- **Modul 5 – Preiskalkulation:** globale Annahmen, editierbare Kostenfelder,
  Kosten-/Preis-/Margenberechnung, „Preis übernehmen" schreibt `_regular_price`
  und `_lw16_margin_percent`, Audit-Log.
- **Modul 6 – Kundenbindung:** Warenkorbabbrecher-Mails (nur mit bekannter E-Mail,
  zwei Stufen), Wunschliste (Merken-Button, Konto-Tab, Meistgewünscht-Report),
  Post-Purchase-Sequenz (Dankeschön + Bewertungsanfrage, Punkte-Bonus **nur bei
  echter Bewertung**), „Wieder verfügbar"-Warteliste (einmalige Benachrichtigung).
- **Modul 7 – Support & Reporting:** Reklamations-Tickets im Konto, Admin-Ansicht
  mit Gutschrift aufs Guthabenkonto (Audit-Log), Status-Benachrichtigung,
  VIP-Kunden-Report, Wochen-Digest per E-Mail.
- **Phase 9 – Absicherung:** wöchentlicher CSV-Datenexport per E-Mail (+ Sofort-
  Button), Audit-Log für kritische Aktionen samt Anzeige.

### Bewusste Vereinfachungen

- **OCR** für Belege ist nicht enthalten (setzt Shell-/Tesseract-Zugriff voraus,
  der zunächst zu prüfen ist); das Erfassungsformular ist so gebaut, dass OCR
  später leicht ergänzt werden kann.
- Der PDF-Generator (`includes/Core/Pdf.php`) ist bewusst schlank und bettet
  Belegfotos als JPEG ein (PNG-Belege erscheinen im Export ohne Bild).
- Die DHL-Sendungsverfolgung erwartet einen API-Key in den Einstellungen; ohne
  Key ruht der Cron.
- Die DHL-Sendungsverfolgung erwartet einen API-Key in den Einstellungen; ohne
  Key ruht der Cron.

## Tests

Die reinen Rechenregeln sind in `Lindenweg16\Core\Calc` gekapselt und werden von
den Modulen (Preiskalkulation, Loyalty, Rechnungen, EÜR) genutzt. Die Unit-Tests
laufen ohne volle WordPress-Testsuite:

```
composer install
composer test        # oder: php vendor/phpunit/phpunit/phpunit
```

Abgedeckt (Phase 9, Punkt 31): Reward-Berechnung, Selbstwerbungs-Schutz,
Punkte-Rundung, Einlöse-Deckelung, EÜR-/Rücklagen-Rechnung, Preiskalkulations-
Rundung, „Preis übernehmen"-/Referral-Margen-Konsistenz und die lückenlose
Rechnungsnummern-Konvention (28 Tests).

## Sicherheit

- Beleg-Fotos liegen in `wp-content/uploads/lw16-belege/` mit `.htaccess`-Schutz
  und werden nur über einen authentifizierten Endpoint mit `manage_lw16`-Prüfung
  und Path-Traversal-Schutz ausgeliefert.
- Kritische Aktionen (Festschreibung, Referral-Freigabe/-Ablehnung, Preisübernahme)
  landen im Audit-Log.
- Nonces auf allen schreibenden Aktionen, Capability-Prüfung auf jeder Seite.
