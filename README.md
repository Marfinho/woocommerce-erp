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
includes/Core/               Installer, Plugin, Roles, Settings, Scheduler, AuditLog,
                             Assets, Calc, HashChain, MailTemplate, Pdf, Privacy, ResetTool
includes/Admin/              Menu, Dashboard, GlobalSearch, SettingsPage, ResetPage
includes/Modules/Inventory/  Repository, StockManager, InventoryModule (Altbestand, siehe unten)
includes/Modules/Accounting/ BelegRepository, BelegOcr, PdfText, EuerCalculator,
                             FeeExtractor, GobdChecker, InvoiceGenerator, AccountingModule
includes/Modules/Loyalty/    LoyaltyRepository, LoyaltyFrontend, LoyaltyModule
includes/Modules/Pricing/    PricingModule
includes/Modules/Production/ ProductionModule, PackingSlipGenerator, DhlTracking
includes/Modules/Retention/  Wishlist, Waitlist, CartTracking
includes/Modules/POS/        POSModule
includes/Modules/Support/    SupportModule
includes/Modules/Betrieb/    Schema, CalcSettings, BetriebAudit, BetriebModule, Admin/
assets/css/                  admin.css, betrieb.css, betrieb-mobile.css, frontend.css
assets/js/                   admin.js, betrieb-mobile.js, betrieb-productpicker.js,
                             betrieb-staffel.js, loyalty.js, wishlist.js
assets/vendor/jsqr/          jsQR (Apache-2.0), nur fuer WebKit nachgeladen
docs/handbuch.html           Bedienungsanleitung
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
  `BundleCalculator`, `DiscountResolver`, `CapacityPlanner`, `StockLedger`,
  `IsoWeek`, `Kpi`, `KpiMeter`, `ProcurementAlert`, `MachineRate`,
  `ColorRegistry`, `ReorderToken`, `DemandPlanner`, `ProductionPlan`,
  `Consistency`, `QrCode`, `ScanResolver` und `PdfText` sind reine Funktionen
  und damit ohne WordPress-Testsuite prüfbar.
- **Kein hartes Löschen** bei Druckjobs, Lagerbewegungen und Chargen:
  Korrektur ausschließlich durch Storno-Gegenbuchung mit Begründung und
  Verweis auf den Ursprungssatz. Stammdaten (Artikel, Verbrauchsmaterial,
  Lagerplätze) lassen sich dagegen stilllegen und – solange keine Buchung
  daran hängt – löschen.
- **Capabilities** `lw16_capture`, `lw16_manage_production`,
  `lw16_view_reports`, `lw16_view_costs`, `lw16_manage_settings`,
  `lw16_manage_users` und die Rolle `lw16_betrieb`. Wer `manage_lw16` besitzt,
  behält vollen Zugriff.
- **Rechteprofile** (`AccessProfiles`) bündeln diese Capabilities zu Werkstatt,
  Fertigung, Betriebsleitung und Vollzugriff und werden je Nutzer über
  `Roles::set_betrieb_profile()` als User-Caps gesetzt – nicht über eine
  WordPress-Rolle, damit jemand zugleich Shop-Redakteur und Aushilfe sein kann.
  Sehen und Ändern sind getrennt: Erfassungsseiten hängen an `lw16_capture`,
  die schreibenden Stammdaten-Handler an `lw16_manage_production`. Geld ist ein
  eigenes Recht (`lw16_view_costs`).
- **Löhne** (`WageRepository`, `LaborCost`) je Nutzer mit Stichtag, nie
  rückwirkend. Daraus die Ist-Lohnkosten je Eintrag, Mitarbeiter und
  Fertigungsauftrag. Der kalkulatorische Stundensatz wird bewusst **nicht**
  automatisch nachgezogen – stattdessen zeigt die Benutzerseite den Vergleich.
- **Protokollsicht** (`UserActivity`) übersetzt die Audit-Zeilen in Sätze; ein
  Drift-Test prüft, dass jede tatsächlich protokollierte Tabelle eine
  Beschriftung hat.
- **Protokollierung** über das bestehende hashverkettete Audit-Log, ergänzt um
  Vorher-/Nachher-Abbild und Begründung.

**Bereiche**

| Seite | Inhalt |
|---|---|
| Dashboard | Kennzahlenkacheln mit Messbalken (Ziel/Alarm), offene Aufträge zum Starten, Wochenbudget, Alarme |
| Bedarf | Bedarf über alle Fertigungsstufen, Druck-/Fertigungsaufträge per Klick, automatischer Produktionsplan |
| Druckjournal | Erfassung, Journal mit Quoten, Storno |
| Artikel & Revisionen | Stammdaten, Revisionen samt 3MF-Datei, Komponenten, Stückliste, Beschaffung, Kalkulation, Bestellart-Rechner |
| Drucker & Wartung | Geräte mit berechnetem Stundensatz, Wartungsfälligkeiten, Aufkleber |
| Kapazität | Wochenvorschau, Rückwärtsrechnung, Auslastungsverlauf |
| Material | Filamentchargen, Verbrauchsmaterial, Beschaffungsalarm |
| Lager | Bestand je Artikel, Farbe und Lagerort, Soll/Ist, Korrekturbuchung mit Lagerplatz |
| Lagerplätze, Fertigung & Picklisten | Lagerplätze mit Etiketten, Druck- und Fertigungsaufträge mit Uhr, Picklisten |
| Bestellungen | WooCommerce-Bestellungen, manuelle Erfassung, Entwürfe bestätigen, Lieferschein für B2B |
| Qualität | Chargenbuch, Rückverfolgung in beide Richtungen, GPSR-PDF |
| Kontakte / B2B | Kontaktakte, Konditionen, Kommissionsbestand, Nachbestell-Link |
| Zeiterfassung | Tageseinträge, Wochenübersicht gegen Budget |
| Berichte | Kennzahlen im Zeitraum, CSV-Export, Monatsabschluss |
| Import & Doku | CSV-Importer für die Mappe, Verfahrensdokumentation als PDF |
| Einstellungen | Kalkulationsannahmen mit Historie, Farbsystem, Lagerhoheit, Rabattstaffel B2B, Kettenprüfung, Systemprüfung |

**Mobile Schnellerfassung**

Unter `/betrieb-erfassen/` hinter Login: eine Spalte, große Bedienelemente,
vier Reiter.

- **Druckjob** – fünf Eingaben, alles andere aus der Vorbelegung.
- **Lager** – Lagerplatz und Artikel scannen, Bewegung buchen, umlagern.
- **Picken** – Auswahl aller offenen Picklisten mit Restmengen, Abhaken per
  Knopf oder Scan.
- **Zeit** – Stoppuhr mit Start/Pause; gespeichert wird der Startzeitpunkt,
  nicht ein mitlaufender Zähler, damit Bildschirmsperre und geschlossener
  Browser die Messung nicht beenden.

Jede Buchung wandert zuerst in eine Warteschlange im Browser und verschwindet
erst nach Bestätigung des Servers — im Keller geht damit keine Eingabe
verloren. REST-Endpunkte unter `lw16/v1` mit Cookie- plus Nonce-Auth.

**Scannen.** Chromium bringt mit `BarcodeDetector` einen eigenen Code-Leser mit
und erkennt damit auch Strichcodes von Lieferanten. WebKit hat ihn nicht, und
auf iPhone und iPad ist jeder Browser WebKit; dort wird beim ersten Scan
`assets/vendor/jsqr` nachgeladen. Der liest nur QR-Codes — die eigenen
Etiketten sind QR, Lieferanten-Strichcodes müssen dort eingetippt werden. Geht
die Kamera gar nicht, nennt die Seite den Grund (keine https-Adresse, gesperrt,
belegt) statt pauschal „kein Scanner".

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

**QR-Codes.** `QrCode` erzeugt genormte Codes im Plugin selbst – kein Bild von
einem fremden Dienst, keine Internetverbindung nötig. Ein früherer Anlauf war
verworfen worden, weil er sich nicht verifizieren ließ; der jetzige wurde gegen
eine unabhängige Bibliothek geprüft (816 Matrizen mit fester Maske und 297 mit
automatischer, ohne Abweichung) und zusätzlich dekodiert. Verwendet für
Lagerplatz-, Artikel- und Chargenetiketten (Präfixe `LW16B:`, `LW16A:`,
`LW16C:`), den Geräteaufkleber, die Materialliste eines Fertigungsauftrags und
die Händlerkarte mit dem Nachbestell-Link. Kürzel und Kurz-URL stehen weiterhin
im Klartext daneben, falls ein Etikett verschmutzt.

**Mehrstufige Fertigung.** Alles ist ein Artikel; der Beschaffungsweg
unterscheidet die Stufen: Rohling aus dem Drucker, Halbzeug/Baugruppe durch
Montage, Zukauf, Hybrid. Aufträge gibt es in zwei Arten – der Fertigungsauftrag
bucht beim Fertigmelden selbst, der Druckauftrag wird über das Druckjournal
gebucht, weil Drucker, Charge und Ausschuss erst am Ende feststehen. Beide
tragen eine Uhr für die Ist-Zeit.

**Ein Teil wird dort abgebucht, wo es verbaut wird:** gedruckte Rohlinge und
ihre Kleinteile beim Druckjob, Bestandteile eines Halbzeugs beim Fertigmelden
des Auftrags, zugekaufte Beigaben beim Packen. Picklisten buchen nie – sie
liefern nur die Ortsangabe.

## Umsetzungsstand

Das Plugin ist im Einsatz. Die folgende Liste beschreibt, was tatsächlich
vorhanden ist – nicht, was geplant war.

- **Grundgerüst:** Aktivierung/Deaktivierung, alle Datenbanktabellen inkl.
  Indizes, versionierte Migrationen über `Installer::DB_VERSION`, Rollen und
  Capabilities, zwei Admin-Menüs, Action-Scheduler-Anbindung, hashverkettetes
  Audit-Log, geschützte Datei-Verzeichnisse.
- **Buchhaltung:** geschützte Belegerfassung (Upload außerhalb Uploads,
  authentifizierte Auslieferung), Belege auslesen (PDF-Textschicht exakt,
  Fotos über Tesseract falls vorhanden), GoBD-Festschreibung, Einnahmen
  automatisch aus WooCommerce, **Zahlungsgebühren von WooPayments automatisch
  als Aufwand**, EÜR nach Kategorie, EÜR-PDF, lückenlose PDF-Rechnungen,
  E-Rechnung (XRechnung/ZUGFeRD), Betriebsprüfungs-Export, GoBD-Selbstprüfung,
  Verfahrensdokumentation.
- **Betrieb:** siehe oben – der größte Teil des Plugins.
- **Loyalty:** Referral-Codes, Cookie-Tracking, Reward-Berechnung, Blockregeln
  mit Prüf-Warteschlange, Punktereifung, Checkout-Einlösung, Konto-Tab.
- **Produktion:** Druck-Queue, Packliste/Versandschein-PDF, DHL-Verlinkung und
  **Sendungsverfolgung im Kundenkonto** (Zeitleiste in den Bestelldetails und
  Spalte in der Bestellübersicht), Ist-Zeit-Erfassung, Mengenvorschläge.
- **Preiskalkulation:** rechnet auf den Artikeln des Moduls Betrieb.
  „Preis übernehmen" schreibt nur `_regular_price`, damit ein laufender
  Aktionspreis überlebt, dazu `_lw16_margin_percent`.
- **Kundenbindung:** Warenkorbabbrecher-Mails, Wunschliste, Post-Purchase-
  Sequenz, „Wieder verfügbar"-Warteliste.
- **Support & Reporting:** Reklamations-Tickets, Gutschrift aufs Guthabenkonto,
  VIP-Report, Wochen-Digest.
- **Absicherung:** wöchentlicher CSV-Export per E-Mail, Audit-Log,
  DSGVO-Auskunft und -Löschung über die WordPress-Bordmittel, Livegang-Reset.

### Zwei Lagerverwaltungen, eine zuständig

`includes/Modules/Inventory/` ist der ältere Bestand (Tabellen `lw16_produkte`,
`lw16_lagerbestand` …). Zuständig ist heute das Modul Betrieb; die alte
Verwaltung überspringt alle Produkte, die auf einen Betrieb-Artikel zeigen.
Die Übernahme läuft einmalig über *Betrieb → Import & Doku*. Der alte Code
bleibt, solange nicht alle Altdaten übernommen sind.

### Bewusste Vereinfachungen

- **Belege auslesen** funktioniert für PDFs mit Textschicht ohne jede
  Voraussetzung – das sind die meisten Lieferantenrechnungen, und die Werte
  sind dort exakt statt geraten. Für **Fotos und Scans** wird Tesseract auf dem
  Server gebraucht; fehlt es, sagt das Formular das, statt zu raten.
- Der PDF-Generator (`includes/Core/Pdf.php`) ist bewusst schlank und bettet
  Belegfotos als JPEG ein (PNG-Belege erscheinen im Export ohne Bild).
- Die DHL-Sendungsverfolgung erwartet einen API-Key in den Einstellungen; ohne
  Key ruht der Cron.
- Der Produktionsplan schätzt die Maschinenzeit aus der aktiven Revision.
  Fehlt eine, steht dort ein Strich statt einer erfundenen Zahl.
- **Analyse-Export** (`AnalyseExport`) gibt den Betriebszustand als eine
  Markdown-Datei aus – gedacht zur Übergabe an eine Auswertung. Sie nennt ihre
  Einheiten, unterscheidet „nicht erfasst" von null und listet am Ende die
  bekannten Lücken; Kundennamen bleiben ohne ausdrückliche Wahl draußen.
  `render()` ist rein und getestet, `collect()` holt die Daten.
- **3MF an der Revision**: Die Druckdatei hängt an der Revision, liegt in
  `uploads/lw16-modelle` (per `.htaccess` gesperrt, Verzeichnislisting aus) und
  wird nur über `admin-post.php` mit Capability-Prüfung ausgeliefert. Auf
  nginx greift die `.htaccess` nicht – dort gehört eine `location`-Regel für
  das Verzeichnis in die Serverkonfiguration.
- **Zwei Dateiarten unter einer Endung**: `ModelFile::kind()` unterscheidet die
  Projektdatei (hat Build-Items) von der geslicten `.gcode.3mf` (leeres
  `3dmodel.model`, dafür `Metadata/plate_*.gcode`). Aus letzterer liest
  `ThreeMf::slice_info()` die Slicer-Zahlen; `revision_values()` rechnet den
  Plattenverbrauch über die Objektzahl auf das Einzelteil herunter und füllt
  nur leere Revisionsfelder.
- **Platte füllen** (`ThreeMf`) vervielfältigt nur die `<item>`-Einträge im
  `<build>`, nie das Netz: Die Geometrie bleibt bitgenau die freigegebene und
  die Datei so groß wie vorher. Vorausgesetzt wird eine Datei mit genau einer
  Platzierung; liegen schon mehrere Teile darin, wird abgelehnt statt geraten.
  Projektdateien aus Bambu Studio/Orca führen ihre Plattenbelegung zusätzlich
  in `Metadata/model_settings.config` – dort kann ein *Alle anordnen* im
  Slicer nötig sein. Benötigt die ZIP-Erweiterung von PHP.

## Tests

Die Rechenregeln sind in reinen Klassen gekapselt und laufen ohne die volle
WordPress-Testsuite; `tests/bootstrap.php` stellt die benötigten WordPress-
Funktionen nach und lädt die Klassen einzeln.

```
composer install
composer test        # oder: php vendor/phpunit/phpunit/phpunit
```

497 Tests. Abgedeckt sind unter anderem: Reward- und Punkterechnung, EÜR und
Steuerrücklage, Rechnungsnummern, Stücklistenauflösung und Kalkulation,
Rabattstaffel, Bedarfsplanung über alle Stufen, Produktionsplan, Zeitmessung an
Aufträgen, QR-Encoder, Scan-Auflösung, Lagerplatz-Vorschläge, Gebührenerkennung,
DHL-Status, Livegang-Reset, DSGVO-Abdeckung, Systemprüfung und das Auslesen
eines echten PDF-Belegs.

Einige Tests lesen den Quelltext, statt ihn auszuführen – sie halten fest, was
sonst stillschweigend auseinanderläuft: dass jede Ampelstufe eine Farbe hat,
dass Assets nicht an der Plugin-Version hängen, dass Markup und Skript der
mobilen Erfassung dieselben Element-IDs benutzen, dass die Verfahrens-
dokumentation jede Tabelle nennt und dass Auswahllisten stillgelegte Artikel
ausschließen. Jeder dieser Tests wurde gegengeprüft, indem der Fehler künstlich
wieder eingebaut wurde.

## Sicherheit

- Beleg-Fotos liegen in `wp-content/uploads/lw16-belege/` mit `.htaccess`-Schutz
  und werden nur über einen authentifizierten Endpoint mit `manage_lw16`-Prüfung
  und Path-Traversal-Schutz ausgeliefert. Dasselbe gilt für erzeugte PDFs.
- Kritische Aktionen landen im hashverketteten Audit-Log; ein nachträglich
  geänderter Datensatz bricht die Kette und wird unter *Betrieb → Einstellungen
  → Protokoll & Kettenprüfung* gemeldet.
- Nonces auf allen schreibenden Aktionen, Capability-Prüfung auf jeder Seite.
- Rücksprungziele aus Formularen werden gegen die bekannten Seiten geprüft,
  nie ungeprüft übernommen.
- Der Nachbestell-Link ist 32 Byte zufällig, wird nur als Hash gespeichert, ist
  nicht indexierbar und je Token und IP rate-limitiert.
- Der Livegang-Reset verlangt einen getippten Bestätigungssatz, ist
  Administratoren vorbehalten und protokolliert dauerhaft, wer wann was
  gelöscht hat.
