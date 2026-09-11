# Lindenweg16 ERP

Ein einziges, in sich geschlossenes WooCommerce-Plugin für lindenweg16.de, das
Buchhaltung, Lagerverwaltung, Loyalty, Produktions-/Bestellplanung,
Preiskalkulation, Versand, Kundenbindung sowie Support & Reporting kombiniert – direkt in
WooCommerce, ohne externen Sync.

## Betriebsanleitungen

Eine bebilderte Anleitung in vier Teilen, je einer pro Aufgabe: **`docs/betriebsanleitung.html`**
(im Browser öffnen).

| Anleitung | Für wen | Datei |
| --- | --- | --- |
| Teil A – Produktion | Werkstatt: drucken, montieren, buchen, Platten anordnen | `docs/betriebsanleitung.html#teil-produktion` |
| Teil B – Versand | Packtisch: picken, packen, versenden | `docs/betriebsanleitung.html#teil-versand` |
| Teil C – Personal | Zeiterfassung, Löhne, Rechteprofile | `docs/betriebsanleitung.html#teil-personal` |
| Teil D – Administration | Einrichtung, Kalkulation, Buchhaltung, Drucker im Netz, Livegang | `docs/betriebsanleitung.html#teil-admin` |

Die Bildschirmfotos in `docs/bilder/` stammen aus einer laufenden Installation
dieses Plugins mit Beispieldaten – sie sind nicht nachgezeichnet.

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
                             FeeExtractor, GobdChecker, InvoiceGenerator, Kontenrahmen,
                             AccountingModule
includes/Modules/Marketing/  Kontenrahmen-Gegenstueck: KampagnenRepository,
                             Kampagnenrechnung, KampagnenReport, MarketingModule
includes/Modules/Events/     EventPostType, EventFields, RsvpRechnung,
                             RsvpRepository, EventAdmin, EventFrontend,
                             EventsWidget, EventsSidebarWidget, EventsBlock,
                             EventsModule
assets/blocks/events/        block.json des Blocks „Nächste Events"
includes/Modules/Loyalty/    LoyaltyRepository, LoyaltyFrontend, LoyaltyModule
includes/Modules/Production/ ProductionModule, PackingSlipGenerator, DhlTracking
includes/Modules/Retention/  Wishlist, Waitlist, CartTracking
includes/Modules/POS/        POSModule
includes/Modules/Support/    SupportModule
includes/Modules/Betrieb/    Schema, CalcSettings, BetriebAudit, PriceSync, ShippingLabel,
                             BetriebModule, Admin/
includes/Modules/Betrieb/Dhl/ DhlProduct, DhlAddress, DhlRequest, DhlResponse,
                             DhlClient, DhlLabelRepository, DhlShipping
includes/Modules/Configurator/ Schema, PartRepository, BlockRepository, Layout, ConfigCodec,
                             Catalog, ShopLink, ColorHex, WebModel, CartIntegration, Frontend,
                             ConfiguratorModule, Admin/ConfiguratorPage
assets/css/                  admin.css, betrieb.css, betrieb-mobile.css, frontend.css
assets/js/                   admin.js, betrieb-mobile.js, betrieb-productpicker.js,
                             betrieb-staffel.js, loyalty.js, wishlist.js
assets/vendor/jsqr/          jsQR (Apache-2.0), nur fuer WebKit nachgeladen
assets/vendor/three/         three.js (MIT), nur auf Seiten mit dem Konfigurator
assets/models/demo/          Demo-Modelle des Konfigurators samt Erzeugerskript
docs/betriebsanleitung.html  Betriebsanleitung in vier Teilen (Produktion, Versand, Personal, Administration)
docs/ba.css                  gemeinsames Aussehen der Anleitungen
docs/bilder/                 Bildschirmfotos, aus einer laufenden Installation erzeugt
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
- **Etikettenbogen** (`LabelSheet`, `LabelPdf`): alle QR-Codes für Lagerplätze
  und Artikel als ein PDF. `LabelSheet` hält das Sollmass des Etiketts statt
  die Seite zu teilen (sonst läuft ein Klebebogen nach unten aus der
  Stanzung) und fasst waagerechte Modulläufe zu Rechtecken zusammen.
  `Pdf::rect()`/`Pdf::line()` zeichnen sie. Geprüft wurde die Ausgabe nicht
  nur mit Unit-Tests, sondern durch Rendern des PDFs und modulweisen
  Vergleich gegen `QrCode::matrix()`.
- **Formular-Handler unter Aufsicht** (`tests/AdminPostSchutzTest.php`):
  `admin_post_*` ist eine offene Tür – die Adresse ist erratbar und führt
  mitten in eine schreibende Funktion. Ein Test liest den Quelltext und
  verlangt von jedem der 104 Handler Nonce und Rechteprüfung, wobei er der
  Weiterreichung an `guard()`/`require_cap()` eine Ebene tief folgt. Zwei
  Kundenformulare (Wartelisten-Anmeldung, Support-Ticket) stehen namentlich
  als Ausnahme von der Rechteprüfung, mit Begründung; ein weiterer Test
  hält die Ausnahmeliste aktuell. Beim Schreiben des Tests fand sich keine
  echte Lücke – der Wert liegt darin, dass die nächste auffällt.
- **Lagerabgang genau einmal** (`Core\Lock`, `WooIntegration::book_stock()`):
  Die Doppelbuchungssperre fragte „schon gebucht?", buchte dann und setzte
  die Marke erst am Ende. Ein wiederholter Webhook, ein Doppelklick oder
  eine Sammelaktion neben einem Webhook laufen gleichzeitig: Beide sahen die
  Bestellung als ungebucht, beide zogen den Bestand ab. Prüfung und Buchung
  liegen jetzt hinter einer Sperre je Bestellung, und die Bestellung wird
  darin frisch geladen. Wer die Sperre nicht bekommt, bucht nicht – ein
  anderer Lauf tut es gerade. `Core\Lock` kapselt `GET_LOCK` und wird auch
  von der Hashkette benutzt; Transients taugen dafür nicht, weil sie selbst
  „prüfen, dann setzen" sind.
- **GoBD-Kette gegen sich selbst gesichert** (`HashChain::insert()`,
  `HashChain::check_rows()`): Vorgängerhash lesen und Datensatz schreiben
  liefen getrennt. Zwei gleichzeitige Schreiber lasen denselben Vorgänger,
  die Kette gabelte sich, und die Prüfung meldete „mögliche Manipulation" –
  ein selbst verursachter Fehlalarm, der genauso aussieht wie der echte
  Fall. Beide Schritte hängen jetzt an einem Sperrschlüssel der Datenbank;
  bekommt man ihn nicht, wird trotzdem geschrieben, denn ein fehlender
  Protokolleintrag wäre schlimmer als ein prüfbarer Bruch. Die Prüfung
  trennt zudem *Kettenglied* von *Inhalt* und rechnet gegen den
  gespeicherten Vorgänger: Ein veränderter Satz erzeugt eine Meldung an
  seiner Zeile statt zehntausend Folgemeldungen, in denen er untergeht.
  Die Beweiskraft bleibt – wer einen Hash nachzieht, bricht das Glied des
  nächsten Satzes.
- **Kennzahlen, korrigiert** (`RepeatRate`, `CapacityPlanner::weeks()`,
  `MachineRate::productive_at()`): Beim Bau der Verlaufsgraphen kamen drei
  Rechenfehler heraus, die vorher nicht auffielen, weil es immer nur einen
  Zeitraum gab. Die Wochenzahl war die Differenz zweier Zeitstempel, auf
  mindestens eins geklemmt – ein Tagesbericht verglich einen Tag Druckzeit
  gegen eine ganze Woche Kapazität und meldete ein Siebtel der echten
  Auslastung; ein Monat war um einen Tag zu kurz. Die Wiederkaufsrate nahm
  stur die letzten 365 Tage ab *heute* und zeichnete damit zwölfmal denselben
  Wert als Geschichte; sie blickt jetzt vom Stichtag aus zurück. Und die
  Kapazität zählte alle heutigen Drucker, auch für Monate, in denen es sie
  noch nicht gab – ein Kauf von heute senkte rückwirkend die Auslastung des
  letzten Jahres. Jeder der drei Fehler steht als Test fest, jeder wurde
  durch absichtliches Wiedereinbauen geprüft.
- **Bestand ohne verlorene Buchungen** (`ComponentConsumption::subtract()`):
  Der Verbrauch von Kleinteilen und Verpackung las `stock_qty`, rechnete in
  PHP und schrieb das Ergebnis zurück. Buchen zwei Vorgänge gleichzeitig,
  überschreibt der zweite den ersten und eine Buchung verschwindet lautlos.
  Jetzt rechnet die Datenbank (`SET stock_qty = stock_qty - %f`), wie im
  Wareneingang schon vorher.
- **Stoppuhr** (`Stopwatch`, `TimeCategoryGuess`, `TimerState`, `Admin\TimerBar`):
  Start und Stopp in der Adminleiste, für jeden Nutzer ab dem Profil
  Werkstatt. Die laufende Uhr liegt als User-Meta, nicht als Tabellenzeile –
  sie ist ein Zustand, kein Vorgang; gebucht wird erst beim bestätigten
  Stoppen, und `handle_time()` weist einen zweiten Versand ab, wenn die Uhr
  schon weg ist. `Stopwatch` kappt nichts heimlich: Eine Uhr über zehn
  Stunden gilt als vergessen und wird im Formular hinterfragt, statt still
  auf acht gekürzt zu werden. Gebucht wird auf den **Start**tag – wer um
  23:40 anfängt, hat am Vortag gearbeitet. `TimeCategoryGuess` leitet aus
  den während der Laufzeit besuchten Seiten einen **Vorschlag** ab, keine
  Festlegung: Er kommt nur bei mindestens zwei Dritteln Anteil, nie bei
  Gleichstand, und wird im Formular begründet („38 Min. Produktion, 12 Min.
  Verwaltung"). Eine offen gelassene Seite zählt je Sprung höchstens 15
  Minuten.
- **Kommission als Liste** (`CommissionBatch`, `CommissionRepository`): Eine
  Auslieferung umfasst selten einen Artikel – bis hierher war sie achtzehnmal
  Auswahlfeld, Menge, Buchen. Jetzt eine Liste über alle Artikel und Farben,
  einmal buchen, am Schreibtisch wie am Handy (eigener Reiter in der mobilen
  Erfassung, mit Suche und mitlaufender Summe). `CommissionBatch` ist rein:
  leere Felder erzeugen keine Buchung über null Stück, dieselbe Ware zweimal
  wird zusammengezählt statt zweimal gebucht, und ein Fehler in einer Zeile
  verhindert die ganze Liste – eine halb gebuchte Lieferung sieht aus wie
  eine fertige. Die Bewegungen kommen weiterhin aus
  `StockLedger::commission_pair()`; ein Test vergleicht Liste gegen
  Einzelbuchung, damit die Regel nur an einer Stelle steht. **Nebeneffekt mit
  Geld dahinter:** Abrechnen erzeugt nun *einen* offenen Posten über die
  ganze Lieferung. Die Rabattstaffel hängt am Bestellwert – acht einzeln
  abgerechnete Artikel landeten achtmal in der untersten Stufe. Der alte
  Einzelhandler ist entfallen, samt seiner Kopie der Abrechnungslogik.
- **Ein Etikettenformat für alles** (`LabelSheet::DEFAULT_ROLL`): Eine Anlage
  hat einen Etikettendrucker, und darin liegt eine Rolle. Der plugin-weite
  Standard ist **DK-11201** (29 × 90 mm, längs beschriftet) und steht als
  eine Konstante da – wer die Rolle wechselt, ändert eine Zeile. Ein Test
  liest die Aufrufer und schlägt an, sobald eine Stelle ein Format wieder
  beim Namen nennt; ein zweiter prüft, dass die Konstante ein Format meint,
  das es gibt. Neu dazu: `LabelPdf::printer_labels()` – die Geräteaufkleber
  gab es bisher nur als HTML-Kacheln zum Ausdrucken auf Papier. Ihr QR-Code
  trägt eine ganze Adresse statt eines kurzen Codes und ist damit dichter;
  gegengeprüft wurde er nicht durch Rechnen allein, sondern durch Rendern des
  PDFs und Dekodieren mit OpenCV, plus einem Test auf die Modulgröße im
  ungünstigsten Fall (lange Domain, langes Gerätekürzel: 0,59 mm je Modul).
- **Gelöschte Shop-Bestellungen** (`OrderSource`, `OrderMirror`,
  `OrderOpsRepository::orphaned()`): Wird eine Bestellung in WooCommerce
  endgültig gelöscht, zeigte die Betriebszeile ins Leere – `order_value()`
  lieferte 0,00 €, `order_date()` einen leeren String, und die Zeile zählte
  in der Händlerauswertung weiter mit. `OrderMirror` schreibt jetzt an den
  Löschhooks (HPOS wie klassisch) Wert und Datum in die Zeile, bevor die
  Quelle verschwindet; vorhandene eigene Angaben bleiben stehen. Der
  Papierkorb löst nichts aus – eine Bestellung dort lässt sich zurückholen.
  Für alles, was vorher gelöscht wurde, gleicht `orphaned()` ab; die
  Systemprüfung meldet es und `Betrieb → Bestellungen` bietet ein Storno an,
  das die Lagerbewegungen als Gegenbuchungen zurücknimmt. Der Verbrauch an
  Kleinteilen geht bewusst **nicht** zurück: Er wird als Feld
  fortgeschrieben, die damalige Menge ist nirgends nachrechenbar, und aus
  der heutigen Stückliste zurückzurechnen ergäbe eine Zahl, die nur zufällig
  stimmt. Dafür ist das Rücksetz-Werkzeug da.
- **Ziele stehen bei den Kennzahlen**: Ziel und Alarmgrenze sind
  Eingabefelder in der Kennzahlentabelle selbst, nicht in einer zweiten
  Tabelle darunter – zwei Stellen für dieselbe Zahl heisst früher oder
  später zwei verschiedene Zahlen. Unter dem Feld steht, was daraus für den
  gezeigten Zeitraum folgt, aber nur wo es sich unterscheidet; bei
  Verhältnisgrößen bleibt die Zeile still, und ein leeres Feld zeigt die
  Vorgabe als Platzhalter. `TrendChart::isolated()` zeichnet ein Ziel, das
  nur für einen Zeitraum gilt, als kurzen Strich – eine Linie braucht zwei
  Punkte, und ein eingetragenes Ziel unsichtbar zu lassen schickt den
  Nutzer auf Fehlersuche in der Eingabe.
- **Verlauf und Ziele** (`Period`, `KpiGoals`, `TrendChart`): Der Bericht
  zeigt jede Kennzahl als Verlauf, umschaltbar zwischen Tag, Woche, Monat,
  Quartal und Jahr. `Period` bildet die Eimer – ISO-Wochen über `IsoWeek`,
  Monats- und Jahresgrenzen nie durch Tageszählung genähert; ein Test prüft,
  dass die Eimer lückenlos aneinanderstoßen. Ziele werden nur je Quartal
  eingetragen (`lw16_kpi_goals`); `KpiGoals::derive()` rechnet sie um und
  unterscheidet dabei **Summengrößen** (Umsatz, Bestellungen – nach Tagen
  verteilt) von **Verhältnisgrößen** (Quoten, Durchschnitte – unverändert).
  Die Art steht als `kind` an jeder Kennzahl in `Kpi::definitions()`, statt
  im Code geraten zu werden. Fehlt für ein berührtes Quartal das Ziel, gibt
  es keines – erfunden wird nichts. `TrendChart` trennt wie `KpiMeter`
  Rechnen und Zeichnen; `path()` unterbricht die Linie an Datenlücken, statt
  sie zu überbrücken. Geprüft wurde die Ausgabe nicht nur mit Unit-Tests,
  sondern durch Rendern des SVG im Browser – dabei fielen ein zu schmaler
  linker Rand (`0.000,00 €` statt `20.000,00 €`) und kollidierende
  Zeitmarken auf.
- **Nachbestellungen** (`Purchase`, `PurchaseRepository`): Bestell-Link je
  Komponente, Formular und Lieferantenshop gehen gleichzeitig auf. Der
  Bestand ändert sich ausschliesslich beim Wareneingang, und der ist je
  Bestellung einmalig – ein zweiter Klick wird abgewiesen statt zu
  verdoppeln. `Purchase::clean_url()` lässt nur http/https durch.
- **Etikettendrucker**: `LabelSheet::rolls()` liefert die Rollenformate des
  Brother QL. Die erste Zahl ist immer die Rollenbreite, die zweite die
  Etikettenlänge – so benennt Brother seine Rollen: DK-11209 ist 62 × 29,
  DK-11201 ist 29 × 90. Das gewählte Format muss zur eingelegten Rolle
  passen. `LabelPdf::build_roll()` erzeugt eine Seite je Etikett mit
  `new Pdf( $w, $h, 0 )`; die Seitengrösse von `Core\Pdf` ist dafür
  instanzweise statt konstant. Hohe Etiketten (h ≥ 0,9 · w) werden
  gestapelt gesetzt, damit der Code die volle Breite bekommt.
  Das Adressetikett wird der Länge nach beschriftet (`'quer' => true`):
  Die **Seite bleibt das Etikett**, gedreht wird der Aufdruck – über
  `Pdf::begin_quarter_turn()`, das eine Transformationsmatrix in den
  Inhaltsstrom schreibt und Breite und Höhe für die Dauer der Drehung
  tauscht. Eine Seite, die anders herum steht als die Rolle, würde den
  Treiber drehen oder skalieren lassen; skaliert ist der QR-Code
  unlesbar. `roll_page()` liefert deshalb das Seitenmass, `roll_cell()`
  das Zeichenfeld in Beschriftungsrichtung.
- **Wartungscheckliste** (`MaintenanceChecklist`) je Intervall, kumulativ
  aufgebaut (monatlich enthält wöchentlich). Bei periodischen Intervallen
  müssen alle Punkte abgehakt sein; geprüft wird serverseitig in
  `MaintenanceRepository::validate()`, das JavaScript blendet nur um und
  sperrt den Knopf. Anlassbezogene Wartungen (Verschleiß, Saison) haben
  bewusst keine Pflichtliste.
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

### Modul Konfigurator

Der Haster Haken zum Zusammenstellen: ein Basismodul und Module, die daran
vertikal frei verschiebbar sind. Eingebunden wird er mit dem Shortcode
`[lw16_konfigurator]`; gepflegt wird er unter **Betrieb → Konfigurator**.

**Grundsätze**

- **Der Konfigurator erfindet keine Artikel.** Basismodule und Module sind
  Artikel aus dem Stamm mit einem Produkt im Shop. Gepflegt wird hier nur,
  was der Artikelstamm nicht wissen kann: Rolle, Maße, Schaumodell und die
  Sperrbereiche des Basismoduls.
- **Eine Konfiguration wird zu echten Bestellpositionen** – Basismodul plus
  je Modul eine Zeile, jede zum Preis ihres Produkts. Lager, Picklisten und
  Bestandsführung brauchen deshalb keine Sonderbehandlung. Eine
  Gruppenkennung hält die Zeilen zusammen: Wer das Basismodul entfernt,
  entfernt den ganzen Haken, und die Menge gilt für alle Teile.
- **Der Preis kommt nie aus dem Browser.** Der Konfigurator schickt Artikel
  und Positionen, den Rest entscheidet WooCommerce.
- **Das Formular ist die Wahrheit, die 3D-Ansicht ist Zugabe.** Alles, was im
  Bild passiert, landet in denselben Feldern, die auch ohne JavaScript
  ausgefüllt werden können. Ohne WebGL fehlt die Ansicht und sonst nichts.
- **Die Regeln stehen zweimal.** Verbindlich ist `Layout.php` auf dem Server;
  die Fassung im Browser sorgt nur dafür, dass sich ein Modul beim Ziehen
  sofort sperrig anfühlt.
- **Schaumodelle sind keine Konstruktionsdaten.** Die `.glb`-Dateien liegen
  öffentlich im Upload-Verzeichnis (`lw16-konfigurator/`) und gehören
  reduziert exportiert – ohne Innengeometrie, Gewinde und Toleranzen. Die
  3MF-Dateien der Revisionen bleiben davon unberührt im geschützten
  Verzeichnis.
- **Ohne Modell zeigt der Konfigurator einen maßhaltigen Ersatzkörper** aus
  Höhe, Breite und Tiefe. Bestellen lässt sich damit alles.
- **Drei Demo-Modelle liegen bei** (`assets/models/demo/`, erzeugt von
  `erzeugen.py` daneben): Schiene 600 mm mit Schraubaugen bei 40–90 und
  500–550 mm, Haken 80 mm, Ablage 150 mm. Sie sind je Teil auswählbar, zum
  Einrichten und Ausprobieren – nicht zum Verkaufen.
- **Das Skript ist ein ganz gewöhnliches, kein Modul.** three.js wird über
  `import()` nachgeladen; nackte Namen löst die Zuordnung im Seitenkopf
  (`importmap`) auch aus einem klassischen Skript auf. Der Tag darf nicht
  über `script_loader_tag` ersetzt werden – der Filter bekommt ihn samt der
  Zeile, die den Katalog trägt.
- **Die Einheit des Modells ist gleichgültig.** Die Ansicht skaliert es auf
  die gepflegte Bauhöhe; sie ist es, an der die Module hängen. Wichtig sind
  nur Y nach oben und der Ursprung an der Unterkante.

**Aufbauregeln.** Positionen sind Millimeter von der Unterkante des
Basismoduls, gemessen an der Unterkante des Moduls. Zwei Module dürfen sich
nicht überlappen, keines über die Schiene hinausragen und keines auf einem
Sperrbereich sitzen – dort wird der Haken an die Wand geschraubt.

**Geteilte Links.** Eine Konfiguration lässt sich als Code weitergeben
(`?haken=v1.<Basis>.<Farbe>.<Artikel>@<Position>,…`). Gelesen wird er streng:
Was nicht der Form entspricht, wird abgewiesen; was der Katalog nicht mehr
kennt, fällt beim Öffnen still heraus.

**Farbe.** Der Konfigurator nutzt das Farbsystem des Moduls Betrieb. Ist das
Shop-Produkt variabel, wird die Variante zur gewählten Farbe gesucht – gibt
es sie nicht, ist das Teil in dieser Farbe nicht bestellbar und sagt das
auch. Die Bildschirmfarbe der Vorschau kommt aus `ColorHex` und lässt sich
über den Filter `lw16_konfigurator_farbwert` je Slug anpassen.

**An der Bestellung** steht die Konfiguration als Text: Gruppenkennung an
jeder Zeile, die Position je Modul, der vollständige Code an der Zeile des
Basismoduls.

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
  als Aufwand**, EÜR nach Kontengruppe (SKR03), EÜR-PDF, lückenlose PDF-Rechnungen,
  E-Rechnung (XRechnung/ZUGFeRD), Betriebsprüfungs-Export, GoBD-Selbstprüfung,
  Verfahrensdokumentation.
- **Marketing:** Kampagnen als Kostenstellen (Events, Anzeigen, Print),
  Budget mit Ampel, Belegzuordnung mit Anteilen, Kilometergeld als Eigenbeleg,
  Umsatzzuordnung über Marktkasse, Gutscheincode und die Kampagnenkennung von
  WooCommerce.
- **Events:** Social Rides, Märkte und Rennen als eigener Inhaltstyp, frei
  definierbare Zusatzfelder je Event (inklusive eingebetteter Komoot-Tour),
  Anmeldung mit Bestätigungslink auch ohne Benutzerkonto, Begleitpersonen mit
  Namen, optionale Teilnehmerbegrenzung mit automatisch nachrückender
  Warteliste, QR-Code auf die Eventseite.
- **Betrieb:** siehe oben – der größte Teil des Plugins.
- **Konfigurator:** Basismodul und frei verschiebbare Module in einer
  räumlichen Ansicht (three.js, Blickwinkel auf ±30° begrenzt), Sperrbereiche
  für die Wandbefestigung, Prüfung des Aufbaus auf dem Server, echte
  Bestellpositionen je Teil, teilbare Konfigurationslinks, vollständig
  bedienbar ohne WebGL.
- **Loyalty:** Referral-Codes, Cookie-Tracking, Reward-Berechnung, Blockregeln
  mit Prüf-Warteschlange, Punktereifung, Checkout-Einlösung, Konto-Tab.
- **Produktion:** Lieferschein-PDF (im Code noch `PackingSlipGenerator`) und
  **Sendungsverfolgung im Kundenkonto** (Zeitleiste in den Bestelldetails und
  Spalte in der Bestellübersicht), Ist-Zeit-Erfassung. Die Versandliste selbst
  steht unter Betrieb → Versand, die Fertigungsvorschläge unter Betrieb → Bedarf.
- **Versand (Betrieb → Versand):** Bestellliste mit Lieferschein,
  Sendungsnummer und **DHL-Label über den Geschäftskundenversand**
  (Parcel DE Shipping v2). Anmeldung über die Authentication API: API-Key und
  API-Secret der Anwendung plus Benutzer und Passwort des
  Geschäftskundenportals werden gegen ein Bearer-Token getauscht, das je
  Umgebung getrennt zwischengespeichert und vor Ablauf erneuert wird; ohne
  API-Secret bleibt es beim bisherigen Basic-Auth-Weg. Sandbox und Live haben
  eigene Einstellungsfelder für alle vier Zugangsdaten – ein Login der einen
  Umgebung wird von der anderen mit `invalid_grant` abgewiesen; für die
  Sandbox legt man keinen Benutzer an, dort steht der feste Testzugang der
  DHL vorbelegt. Auf welche Kundennummer gebucht wird, entscheidet
  `DhlSandbox`: in der Testumgebung auf die der DHL, sonst auf die eigene.
  Produkt und Gewicht werden aus Zielland und
  Artikelstamm vorgeschlagen, das PDF landet im geschützten
  Dokumentenverzeichnis, die Sendungsnummer geht direkt in die
  Sendungsverfolgung. Stornieren nimmt die Frankierung zurück; der Vorgang
  bleibt als storniert stehen. Produktwahl, Abrechnungsnummer,
  Adressaufbereitung, Auftrag und Antwortauswertung liegen netzfrei in
  `Modules/Betrieb/Dhl/` und sind vollständig getestet.
- **Preise:** entstehen am Betriebsartikel und gehen von dort automatisch ins
  verknüpfte Shop-Produkt (`PriceSync`). Geschrieben wird nur
  `_regular_price`, damit ein laufender Aktionspreis überlebt, dazu
  `_lw16_margin_percent` fürs Empfehlungsprogramm. Zielmarge und
  Preisvorschlag stehen im Reiter Kalkulation des Artikels.
- **Kundenbindung:** Warenkorbabbrecher-Mails, Wunschliste, Post-Purchase-
  Sequenz, „Wieder verfügbar"-Warteliste.
- **Support & Reporting:** Reklamations-Tickets, Gutschrift aufs Guthabenkonto,
  VIP-Report, Wochen-Digest.
- **Absicherung:** wöchentlicher CSV-Export per E-Mail, Audit-Log,
  DSGVO-Auskunft und -Löschung über die WordPress-Bordmittel, Livegang-Reset.

### Zwei Achsen: Kostenart und Kostenstelle

Jede Ausgabe trägt zwei voneinander unabhängige Merkmale.

- Die **Kostenart** ist ein Konto aus SKR03 (`Kontenrahmen`, ein kurierter
  Auszug, erweiterbar über den Filter `lw16_kontenrahmen`). Sie sagt, *was*
  gekauft wurde, und bestimmt die EÜR-Zeile. Nur sie interessiert das
  Finanzamt.
- Die **Kostenstelle** ist eine Kampagne (`lw16_kampagnen`, Nummer im Format
  `K-JJJJ-NNN`). Sie sagt, *wofür* – ein Markt, eine Anzeigenschaltung, eine
  Flyeraktion. Nur sie beantwortet, ob sich etwas gelohnt hat.

Beides in eine Nummer zu falten funktioniert nicht: Ein Marktbesuch erzeugt
Standgebühr (4600), Fahrtkosten (4672), Verbrauchsmaterial (4980) und
womöglich Bewirtung (4650) – vier Kostenarten, eine Kostenstelle. Dieselbe
Trennung macht DATEV mit `Konto` gegen `KOST1`.

Die Zuordnung liegt in `lw16_kampagne_beleg` statt in einer Spalte des Belegs:
Belege sind nach der Festschreibung unveränderlich, die Zuordnung ist reine
Auswertung und muss korrigierbar bleiben. Über `anteil_prozent` lässt sich ein
Beleg auf mehrere Kostenstellen aufteilen (eine Flyerrechnung für zwei
Märkte); über alle Kostenstellen zusammen sind nie mehr als 100 % möglich.

`eur_kategorie` bleibt als Klartext im Beleg stehen und wird nie überschrieben
– das Feld steckt in der Hash-Kette (`HashChain::HASH_FIELDS`). Die
Kontonummer kommt in der eigenen Spalte `konto` daneben; für Altbelege füllt
`Installer::maybe_backfill_konten()` sie aus der alten Kategorie.

### Was einer Kampagne als Umsatz zugerechnet wird

Nur drei Nachweise, bewusst keine Schätzung darüber hinaus:

1. **Marktkasse** – ein Kassentag mit `kampagne_id` zählt mit seinem
   Tagesumsatz. Das ist gemessen, nicht attribuiert.
2. **Gutscheincode** – ein Code, den es nur bei dieser Kampagne gab. Die
   einzige belastbare Brücke von einem Flyer zu einer späteren Bestellung.
3. **Kampagnenkennung** – `utm_campaign`, die WooCommerce ab Version 8.5 von
   sich aus an jede Bestellung schreibt (`_wc_order_attribution_utm_campaign`).

Der Umsatz zählt über das Kampagnenende hinaus (Einstellung
*Nachlauffenster*, Standard 30 Tage); die Kosten hängen dagegen am Beleg. Was
sich über keinen der drei Wege nachweisen lässt, bleibt unzugeordnet. Eine
vollständig erstattete Bestellung zählt gar nicht; eine Bestellung mit
Gesamtsumme 0 (100 % Rabatt) dagegen schon – sie hat die Kampagne etwas
gekostet.

### Rabatt ist Einsatz, aber keine Ausgabe

Ein gewährter Nachlass (Startgutschein, Aktionscode) ist **Erlösschmälerung**,
keine Betriebsausgabe: Es gibt keinen Beleg, es fließt nichts ab, der
Bestellwert ist bereits gemindert. Die EÜR ist damit von sich aus richtig –
`EuerCalculator::einnahmen()` rechnet mit `$order->get_total()`, also nach
Abzug. Wer den Rabatt zusätzlich als Aufwand buchte, hätte ihn doppelt drin.

Für die Kampagnenauswertung zählt er trotzdem, sonst stünde eine reine
Gutscheinaktion mit null Kosten und grüner Ampel da. Die Kostenstelle weist
deshalb drei Zahlen aus:

| Größe | Quelle | in der EÜR |
|---|---|---|
| Kosten (gebucht) | zugeordnete Belege | ja |
| Rabatt (nicht gebucht) | `get_discount_total()` der zugeordneten Bestellungen | nein |
| Einsatz gesamt | Summe beider | – |

Ergebnis, ROAS und Einsatz je Bestellung rechnen gegen den Einsatz gesamt.
Das **Budget misst sich weiter nur an den gebuchten Kosten** – ein Budget
zahlt man aus, einen Rabatt gewährt man. Die Kampagnenübersicht zeigt aus
Kostengründen nur die gebuchten Kosten; den vollen Einsatz zeigt die
Einzelansicht.

Nicht erfasst ist die Einlösung von Punkten und Guthaben: Die läuft im
Loyalty-Modul als negative Gebühr (`LoyaltyFrontend::apply_redeem_fee()`),
nicht als Coupon, und taucht in `get_discount_total()` darum nicht auf. Ob
sie überhaupt einer Marketingkampagne zuzurechnen wäre, ist eine eigene
Frage.

### Zeitpunkt: EÜR gegen Kampagne

Bei der EÜR gilt das Abflussprinzip – eine im Dezember gezahlte Standgebühr
für den Januar-Markt ist eine Dezember-Ausgabe. Die Kampagnenauswertung will
sie beim Januar-Markt sehen. Darum hat die Kampagne ein eigenes
`event_datum`, und beide Sichten bleiben getrennte Abfragen.

### Events: Post Type statt eigener Tabelle

Ein Event ist öffentlicher Inhalt mit Titel, Text, Bild und Adresse – genau
das, wofür WordPress einen Custom Post Type hat. `lw16_event` bringt Editor,
Beitragsbild, Vorschau, Permalinks, Revisionen und die Sichtbarkeit für
Suchmaschinen mit; alles davon wäre in einer eigenen Tabelle noch einmal zu
bauen gewesen. Die Eventtypen (Social Ride, Markt, Rennen) sind eine
Taxonomie und damit im Backend erweiterbar, ohne Code anzufassen.

Die **Anmeldungen** sind Bewegungsdaten und liegen deshalb in
`lw16_event_rsvp` – mit Unique-Index auf (Event, E-Mail), damit dieselbe
Adresse nicht zweimal auf der Liste steht.

**Zusatzfelder** liegen als Post-Meta am Event, nicht in einer globalen
Definition: Jedes Event trägt seine eigenen Felder. Für die drei Eventtypen
gibt es Vorlagen als Startpunkt (`EventFields::vorlagen()`), die beim ersten
Bearbeiten vorgeschlagen und danach frei geändert werden. Es gibt bewusst
**kein Freitext-HTML- oder iframe-Feld**: Eingebettet wird nur, was einen
eigenen Feldtyp hat. Bei der Komoot-Tour wird aus der eingefügten Adresse
nur die Tour-ID und ein etwaiges Freigabe-Token gelesen und die
Einbettungsadresse daraus selbst gebaut (`EventFields::komoot_embed_url()`) –
so kann in dem Feld nichts landen, was auf eine fremde Seite zeigt.

Das Komoot-Feld hat einen **Stil** – dieselben fünf, die Komoots eigener
Einbettungsdialog anbietet (`EventFields::komoot_stile()`):

| Stil | `layout` | Höhe |
|---|---|---|
| Klassisch – Karte, Statistiken, Höhenprofil | `classic` + `profile=1` | 700 |
| Nur Karte – interaktive Karte, minimale Oberfläche | `map` | 540 |
| Kompakte Karte – Titelbild und Eckdaten | `compact` | 260 |
| Fotostreifen – Galerie zuerst | `gallery` + `gallery=1` | 640 |
| Highlights – scrollbare Reihe | `highlights` | 430 |

Die Höhen stammen aus Komoots Dialog und gehören zum Stil: ein einheitlicher
Wert ergäbe bei *Kompakte Karte* einen leeren Streifen und bei *Klassisch*
einen Rollbalken. Ein Test vergleicht die erzeugte Adresse Zeichen für
Zeichen mit dem Code, den Komoot selbst ausgibt.

`komoot_stil()` ist die Positivliste; ein unbekannter Wert fällt auf
*Klassisch* zurück, statt als Parameter in der Adresse zu landen. Sie
übersetzt außerdem die Werte der ersten Fassung (`profil`, `karte`).

Die **Grundkarte** (Karte, Satellit, Gelände) ist davon unberührt – die
schaltet der Besucher im Kartenfenster selbst um, dafür gibt es keinen
Parameter.

Im Backend zeigt eine **Vorschau** die Einbettung im gewählten Stil, bevor das
Event gespeichert ist. Die Adresse dafür baut PHP über denselben Weg wie beim
Ausliefern (`EventAdmin::ajax_komoot_vorschau()`); sie im Browser
zusammenzusetzen hieße, die Regeln ein zweites Mal zu schreiben, und die
zweite Fassung wäre die, die irgendwann abweicht.

### Anmeldung: bestätigen, warten, nachrücken

Die Anmeldung läuft über einen Bestätigungslink per E-Mail. Der Platz wird
**erst bei der Bestätigung** vergeben, nicht beim Absenden – sonst blockierte
eine nie bestätigte Anmeldung einen Platz. Unbestätigte Anmeldungen verfallen
nach sieben Tagen und werden täglich aufgeräumt
(`Scheduler::HOOK_DAILY_EVENTS`).

Eine Anmeldung kann **Begleitpersonen** mitbringen; jede braucht einen Namen
(bei einer Ausfahrt oder einem Rennen muss auf der Startliste stehen, wer
mitfährt) und belegt einen eigenen Platz. Höchstens zehn je Anmeldung – das
Formular ist öffentlich. Gezählt werden deshalb durchgehend **Plätze, nicht
Anmeldungen**: `RsvpRepository::plaetze()` summiert, `count()` zählt Zeilen,
und die Kapazität hängt an der ersten der beiden. Der CSV-Export schreibt
eine Zeile je Person, nicht je Anmeldung – so liest sich eine Startliste.

Ist die Teilnehmerzahl erreicht, landen weitere Anmeldungen auf der
Warteliste. Bei einer Absage rückt in der Reihenfolge der Bestätigung nach,
wer als Erster zugesagt hatte, und bekommt sofort eine E-Mail. Eine Gruppe
rückt nur geschlossen nach, und es wird **streng der Reihe nach** aufgefüllt:
Passt der Nächste nicht mehr hinein, ist Schluss – auch wenn hinter ihm ein
Einzelner läge, der noch passte. Sonst überholte eine Einzelperson eine
wartende Familie, und zwar jedes Mal wieder. Im Einzelfall lässt sich der
Status im Backend von Hand setzen. Dasselbe
passiert, wenn die Maximalzahl im Backend angehoben oder auf 0 (unbegrenzt)
gesetzt wird. Eine Teilnehmerzahl von 0 bedeutet durchgehend „unbegrenzt",
nie „niemand" – die Unterscheidung steckt in `RsvpRechnung::ist_begrenzt()`.

Das öffentliche Formular hat einen Honigtopf und eine Bremse von zehn
Versuchen je IP und Stunde. Die Einwilligung zur Speicherung ist Pflichtfeld,
und `lw16_event_rsvp` ist für DSGVO-Auskunft und -Löschung angemeldet
(`Privacy::sources()`).

### Die Eventreihe auf der Startseite

Die nächsten Termine als seitlich blätterbare Reihe, ein Event je Ansicht,
mit Datum, kleinem Titelbild, Auszug und einem Balken der freien Plätze.
Dieselbe Ausgabe steht über drei Wege bereit – der Aufbau selbst aber nur
einmal, in `EventsWidget::render()`:

- Block **Nächste Events** für den Seiten-Editor (`EventsBlock`)
- Shortcode `[lw16_events anzahl="6" typ="social-ride" titel="…" alle_link="0" autoplay="1" intervall="7"]`
- klassisches Widget für Widget-Bereiche (`EventsSidebarWidget`)
- direkt in der Theme-Vorlage, so eingebunden in `theme/front-page.php`

Der **Block ist dynamisch**: Gespeichert werden nur die Einstellungen, die
Ausgabe kommt bei jedem Seitenaufruf frisch vom Server (`save()` gibt `null`
zurück). Gespeichertes HTML trüge die Teilnehmerzahlen von gestern im Beitrag
mit sich herum. Die Vorschau im Editor rendert über `ServerSideRender`
denselben Weg wie die fertige Seite, statt ihn nachzubauen.

Das Editor-Skript ist **ohne JSX und ohne Build-Schritt** geschrieben
(`wp.element.createElement`), weil das Plugin ohne Build-Werkzeug auskommt –
ein einzelner Block wäre kein guter Grund, damit anzufangen. Die
Abhängigkeitsliste steht deshalb von Hand in `EventsBlock::register_block()`,
und `block.json` verweist mit `editorScript` auf dieses Handle statt auf eine
Datei. `EventsBlockTest` hält beide Seiten zusammen: Jede Einstellung aus
`block.json` muss im Editor bedienbar *und* im Render-Rückruf ausgewertet
sein – sonst fehlt sie später still auf der Seite.

Das **Blättern macht CSS allein**: `scroll-snap-type: x mandatory` rastet
jede Karte ein, `grid-auto-columns: 100%` gibt eine Karte je Ansicht. Pfeile,
Punkte und Pausenknopf sind Zugabe, stehen im Markup auf `hidden` und blenden
sich erst mit JavaScript ein – ohne Skript bleibt die Reihe wischbar und mit
den Pfeiltasten bedienbar. Auf schmalen Bildschirmen sind die Pfeile
ausgeblendet: dort wird gewischt.

Die Reihe **blättert von allein weiter** (Standard 7 s, einstellbar zwischen
`INTERVALL_MIN` und `INTERVALL_MAX`; `intervall_ms()` begrenzt den Wert – zu
kurz ist Flackern statt Blättern). Sie hält an, sobald jemand hinsieht oder
sie bedient:

- **Pausenknopf** – die bewusste Entscheidung. Sie überlebt Mausbewegungen;
  wer von Hand pausiert hat, läuft nicht weiter, nur weil der Zeiger die
  Karte verlässt.
- **Zeiger darüber oder Tastaturfokus darin** – solange jemand liest oder
  sich durchtabbt, bewegt sich nichts.
- **Tab im Hintergrund** (`visibilitychange`) und **Wischen** auf dem Handy.
- **`prefers-reduced-motion: reduce`** schaltet den Selbstlauf ganz ab – und
  dann auch den Pausenknopf, der sonst ohne Wirkung dastünde. Bewegung, die
  von allein losgeht, ist für manche Menschen ein Problem und nicht nur
  Geschmackssache.

Die **Punkte** (`role="tablist"`) zeigen die Position und springen auf Klick
zur Karte; die aktive folgt beim Scrollen über `aria-selected`.

Der **Balken** zeigt die Auslastung, nicht die freien Plätze – ein fast
voller Balken liest sich schneller als eine kleine Zahl. Bei überschrittener
Grenze (von Hand nachgetragene Zusagen) bleibt er bei 100 %, statt über den
Rand zu laufen. Ohne Teilnehmerbegrenzung gibt es keinen Balken, sondern nur
den Hinweis, dass die Anmeldung offen ist; ist die Anmeldung ausgeschaltet,
fehlt der Bereich ganz.

Ein Event **ohne Beginn taucht nicht auf** – ohne Datum lässt sich weder
sortieren noch sagen, ob es noch bevorsteht. Gezählt wird ab Mitternacht,
damit ein Event am eigenen Tag nicht mittags von der Startseite verschwindet.
Steht nichts an, gibt `render()` einen leeren String zurück und der ganze
Abschnitt entfällt – eine Überschrift ohne Inhalt sähe nach Fehler aus.

Stil und Skript lädt `EventsModule::braucht_assets()` nur, wo Events
vorkommen: auf Eventseiten, auf der Startseite, wo das Widget aktiv ist oder
der Shortcode im Inhalt steht.

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
  nur leere Revisionsfelder. Benötigt die ZIP-Erweiterung von PHP.
- **Kein serverseitiges Anordnen.** Ein früherer `ThreeMf::fill_plate()` legte
  mehrere Instanzen als `<item>`-Einträge auf die Platte. Das Raster rechnete
  mit achsparallelen Hüllquadern und verschenkte damit bei ineinander legbaren
  Konturen (T-Formen) die halbe Platte. Richtiges Nesting braucht die
  Bauteilkontur; das kann der Slicer besser, dort bleibt es auch.
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
