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

**Grundsätze**

- **Eigene Tabellen** mit Präfix `lw16_` und englischen Spaltennamen
  (abweichend vom übrigen Plugin, siehe Kommentar in `Schema.php`), angelegt
  über denselben `dbDelta`-Durchlauf wie die übrigen Tabellen.
- **Nichts Berechnetes wird gespeichert.** Stückpreis aus der Gebindegröße,
  Druckzeit je Teil aus dem Plattenwert, Maschinenstundensatz aus den
  Gerätedaten, Herstellkosten rekursiv über die Stückliste, Betriebsstunden aus
  dem Druckjournal, Bestand als Summe der Bewegungen.
- **Rechenlogik ohne Datenbank.** `BomResolver`, `CostCalculator`,
  `BundleCalculator`, `ReleaseGate`, `DiscountResolver`, `CapacityPlanner`,
  `StockLedger`, `IsoWeek`, `Kpi`, `ProcurementAlert`, `MachineRate`,
  `ColorRegistry` und `ReorderToken` sind reine Funktionen und damit ohne
  WordPress-Testsuite prüfbar.
- **Kein hartes Löschen** bei Druckjobs, Lagerbewegungen, Chargen und
  Zugversuchen: Korrektur ausschließlich durch Storno-Gegenbuchung mit
  Begründung und Verweis auf den Ursprungssatz.
- **Capabilities** `lw16_manage_production`, `lw16_view_reports`,
  `lw16_manage_settings` und die Rolle `lw16_betrieb`. Wer `manage_lw16`
  besitzt, behält vollen Zugriff.
- **Protokollierung** über das bestehende hashverkettete Audit-Log, ergänzt um
  Vorher-/Nachher-Abbild und Begründung.

**Bereiche**

| Seite | Inhalt |
|---|---|
| Dashboard | Kennzahlenkacheln mit Ampel, Wochenbudget, Alarme |
| Druckjournal | Erfassung, Journal mit Quoten, Storno |
| Artikel & Revisionen | Stammdaten, Revisionen, Komponenten, Stückliste, Beschaffung, Kalkulation, Bestellart-Rechner |
| Drucker & Wartung | Geräte mit berechnetem Stundensatz, Wartungsfälligkeiten, Aufkleber |
| Kapazität | Wochenvorschau, Rückwärtsrechnung, Auslastungsverlauf |
| Material | Filamentchargen, Verbrauchsmaterial, Beschaffungsalarm |
| Lager | Bestand je Artikel, Farbe und Lagerort, Soll/Ist, Korrekturbuchung |
| Bestellungen | WooCommerce-Bestellungen, manuelle Erfassung, Entwürfe bestätigen |
| Qualität | Zugversuche, Freigabestand, Chargenbuch, Rückverfolgung, GPSR-PDF |
| Kontakte / B2B | Kontaktakte, Konditionen, Kommissionsbestand, Nachbestell-Link |
| Zeiterfassung | Tageseinträge, Wochenübersicht gegen Budget |
| Berichte | Kennzahlen im Zeitraum, CSV-Export, Monatsabschluss |
| Import & Doku | CSV-Importer für die Mappe, Verfahrensdokumentation als PDF |
| Einstellungen | Kalkulationsannahmen mit Historie, Farbsystem, Lagerhoheit, Wochenmail |

**Mobile Schnellerfassung**

Unter `/betrieb-erfassen/` hinter Login: eine Spalte, große Bedienelemente,
fünf Eingaben je Druckjob (Drucker, Artikel, Teile i.O., Plattenwechsel,
optional Fehlerursache). Alles andere kommt aus der Vorbelegung. Jede Buchung
wandert zuerst in eine Warteschlange im Browser und verschwindet erst nach
Bestätigung des Servers — im Keller geht damit keine Eingabe verloren.
REST-Endpunkte unter `lw16/v1` mit Cookie- plus Nonce-Auth.

**Farbsystem**

Die Farbe ist ein globales WooCommerce-Produktattribut (Standard `pa_farbe`),
verwendet als Variantenattribut. Intern wird immer der Slug gespeichert, nie
der Anzeigename. Auflösung je Bestellposition: Variantenattribut → eindeutiges
Produktattribut → Standardfarbe → `ungeklaert`. Ungeklärte Bewegungen werden
gebucht und gemeldet, nie verworfen.

**Lagerhoheit**

Betrieb bucht die Lagerabgänge genau der Produkte, die über `wc_product_id`
auf einen Artikel im Artikelstamm zeigen; das Lager-Modul überspringt sie über
den Filter `lw16_inventory_skip_product`. Alles andere bleibt beim Lager-Modul.
Abschaltbar unter *Betrieb → Einstellungen → Farben & Lagerhoheit*.

**Nachbestell-Link**

Kein B2B-Portal: Jeder Händler bekommt unter `/nachbestellen/{token}/` einen
persönlichen Link ohne Login. Das Token ist 32 Byte zufällig und wird nur als
Hash gespeichert; die Seite ist nicht indexierbar, rate-limitiert je Token und
IP und zeigt weder Bestands- noch Umsatz- noch Fremdkonditionen. Sie erzeugt
ausschließlich Bestellentwürfe für ihren Kontakt — Lagerabgang und offener
Posten entstehen erst mit der Bestätigung im Backend.

**Kein selbstgebauter QR-Code.** Ein handgeschriebener Encoder ließ sich ohne
Referenzbibliothek nicht bit-genau verifizieren; ein Code, den der Scanner
nicht liest, wäre schlimmer als keiner. Der Geräteaufkleber trägt stattdessen
Kürzel und Kurz-URL in großer Schrift.

Umsetzungsstand: Meilensteine 1 bis 11 vollständig umgesetzt.

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
