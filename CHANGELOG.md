# Changelog

Alle nennenswerten Änderungen am Plugin. Format nach
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/); die Versionsnummer
steht in `lindenweg16.php` (`Version` und `LW16_VERSION`).

Frühere Versionen sind hier nicht nachgetragen; der Stand davor steht in der Git-Historie
und im Abschnitt „Umsetzungsstand“ der README.

## [0.24.1] – 2026-10-19

### Verständliche Fehlermeldungen im ERP
- Beim Anordnen und Freigeben eines Plans erschien nur „Fehler 422“, weil die Oberfläche das
  Feld `error` der Antwort nicht las. Jetzt steht dort der Grund im Klartext.
- Für Antworten ohne Text gibt es Beschreibungen statt Nummern (keine Berechtigung, nicht mehr
  angemeldet, Eingaben unvollständig, Serverfehler …).
- Hinweise zur Plattenanordnung nennen den Artikel mit Nummer und Name (z. B. „HZ-BSM – Modul BSM“)
  und sagen, was zu tun ist (ungeslicte Projekt-3MF in der aktiven Revision hochladen).
- Scheitert die Anordnung, werden die Gründe der einzelnen Artikel mit angezeigt.

## [0.24.0] – 2026-10-19

### Soll gegen Ist
- Neue Seite „Soll gegen Ist“ im Bereich Fertigung: Die im Workflow gestempelte Druckzeit der
  erledigten Platten (letzte 90 Tage) wird je Artikel gegen die Planzeit gestellt. Bei Platten
  mit mehreren Artikeln wird die gemessene Zeit im Verhältnis der geplanten verteilt. Zusätzlich
  die mittlere Rüstzeit.
- Ab drei gemessenen Platten und 10 % Abweichung wird eine Korrektur angeboten. „Planzeit
  übernehmen“ schreibt die gemessene Plattenzeit in die aktive Revision (mit Eintrag im Protokoll);
  Planung, Etikett-Schätzung und Kalkulation rechnen danach damit.
- Mit dem Recht „Preise, Kosten und Löhne sehen“ wird die Marge nachgerechnet: Warnung, wenn der
  Deckungsbeitrag II mit gemessener Zeit um 5 Prozentpunkte oder mehr sinkt oder negativ wird.
- Neues Recht `fertigung.sollist`; Übernehmen braucht zusätzlich „Stammdaten ändern“.

## [0.23.0] – 2026-10-19

### Einkaufs-Workflow: Nachbestellen und Wareneingang
- Neue Seite „Einkauf“ im Bereich Lager mit drei Spalten: **Bestellen**, **Unterwegs**,
  **Eingegangen (14 Tage)**. Die Karten unter „Bestellen“ kommen aus dem Beschaffungsalarm
  (Zukaufartikel, Kleinteile, Verbrauchsmaterial, Filament), die dringendsten zuerst. Lieferant
  und Preis werden aus der letzten Bestellung vorbelegt.
- „Bestellt ✓“ legt die Bestellung an; die Karte wandert nach „Unterwegs“ und verschwindet aus
  „Bestellen“. Überfällige Bestellungen sind markiert.
- „Eingegangen ✓“ bucht den Bestand: Zukaufartikel als Bewegung im Lagerjournal, Kleinteile und
  Verbrauchsmaterial als Aufschlag, **Filament als neue Charge** (Rolle) mit Chargencode, Material
  und Preis je kg. Die Menge darf von der bestellten abweichen.
- Bestellungen gibt es jetzt auch für Zukaufartikel und Filament (neue Spalte `purchases.item_ref`,
  DB-Version 37; Filament wird über die Farbe erkannt).
- Neues Recht `lager.einkauf` (Wareneingang braucht zusätzlich `lager.buchen`); in den Presets
  Fertigung und Leitung enthalten, wo auch Bedarf enthalten war.

## [0.22.0] – 2026-10-19

### Versand-Workflow mit Lagerabgang beim Versand
- Der Versand ist ein Board mit drei Spalten: **Zu packen**, **Label da – zu versenden**,
  **Heute versendet**. Die Stufe ergibt sich aus den Tatsachen (Label vorhanden, Bestellung
  abgeschlossen), nicht aus einem eigenen Merker.
- „Versendet ✓“ bucht den Lagerabgang (vorhandene, idempotente Regel der WooCommerce-Anbindung)
  und schließt danach die Bestellung ab. Der Abgang passiert damit beim Versand, nicht erst,
  wenn jemand im Shop abschließt; der Abschluss im Shop bleibt als gleichwertiger Weg erhalten
  und bucht nichts doppelt.
- Neue Packseite fürs Handy unter `#/bestellung/<id>`: Anschrift, Packliste zum Abhaken,
  Label erzeugen/öffnen, Lieferschein, große Taste „Versendet ✓“.
- Neue REST-Routen `erp/versand/<id>` und `erp/versand/<id>/versendet`; Recht `verkauf.versand`.

## [0.21.1] – 2026-10-19

### Workflow: Filament wird aus dem Bestand abgezogen

Das Filament steht in den Chargen (Rollen); sein Verbrauch ergibt sich aus Druckjobs, die einer
Rolle zugeordnet sind. Der Workflow hat die Rolle bisher nicht gesetzt – der Verbrauch fehlte in
der Rechnung. Jetzt:

- **Rolle beim Melden:** Jede Position bekommt die älteste Rolle ihrer Farbe mit genug Rest
  (FIFO), oder die, die der Mitarbeiter im Meldeformular wählt. Der Verbrauch (Teile mal
  Bauteilgewicht, inklusive Ausschuss) steht damit im Druckjournal und mindert den Restbestand.
  Gibt es keine Rolle in der Farbe, sagt das Formular es offen: „nicht abgezogen“.
- **Materialprüfung vor dem Freigeben:** Die Vorschau zeigt je Farbe Bedarf gegen Bestand und die
  vorgeschlagene Rolle; fehlt Material, steht es als Warnung dabei. Dieselbe Prüfung steht auf der
  Plattenseite („Material bereit?“).
- **Nachbestell-Alarm für Filament:** Reichweite je Farbe aus dem Verbrauch der letzten vier Wochen
  gegen Lieferzeit plus Puffer (einstellbar, Vorgabe 10 + 5 Tage). Der Alarm steht im Workflow, im
  Beschaffungsalarm (Dashboard, Materialseite) und in der Wochenmail, mit Bestellvorschlag in kg.
- Neu: `Erp\Material`, Tests `ErpMaterialTest`.

## [0.21.0] – 2026-10-19

### Der Auftragsablauf: vom Bedarf bis zum Bestand (Workflow)

Ein Ablauf statt vieler Seiten. Alle bisherigen Menüpunkte bleiben; „Workflow“ steht als
erster Eintrag oben (Verwaltung → Benutzer & Rechte: Recht „Workflow: Platten abarbeiten“,
für Freigabe, Etikett- und Hochrechnungs-Einstellungen „Workflow: Plan freigeben“).

- **Bedarf** aus Bestellungen, Merklisten, Sollbestand **und Hochrechnung**: Aus dem
  Verkauf der letzten 8 Wochen (einstellbar) wird ein Wochenmittel gebildet; der Bestand
  soll für 2 Wochen (einstellbar) reichen. Offene Bestellungen werden abgezogen. Jede Karte
  nennt ihren Grund; Platten, die schon laufen, zählen als „in Arbeit“.
- **Plan freigeben:** Positionen wählen, Platten werden angeordnet (ungeslicte Projekt-3MF,
  geslict wird in der Werkstatt), Dauer geschätzt, mit der Freigabe entstehen die
  **Etiketten** – ein Etikett je Platte auf dem Etikettendrucker des Lagers. Rolle/Größe
  (z. B. DK-11201), Drucker, Inhalt, Kopien und Auto-Druck sind einstellbar. Der QR-Code
  öffnet `…/erp/p/<Nummer>`; nach der Anmeldung landet man genau dort.
- **Platte am Handy:** Datei laden → Rüsten starten → Druck (automatisch erkannt, wenn der
  Bambu-Drucker verbunden ist, sonst per Taste; Live-Fortschritt) → Ist-Menge melden mit
  Ausschuss und Fehlerursache → „Dein Nächstes“. Funktioniert ohne Verbindung (vorgemerkt).
- **Folgen statt Eingaben:** Die Taste bucht Rüst- und Druckzeit (neue Zeitarten
  „Rüstzeit“ und „Druckzeit“, an die Platte gebunden), schreibt das Druckjournal (damit den
  Lagerzugang der guten Teile, Kleinteile laut Stückliste, Charge) und lässt Fehlmengen als
  Bedarf in den Plan zurückkehren. Alles über die vorhandenen Fachklassen, keine zweite
  Buchungslogik. Zeiten über 24 Stunden werden in mehrere Einträge geteilt.
- **Scannen** erkennt Plattenetiketten und springt zur Platte.
- **WordPress-Admin aufräumen:** Einstellung „ERP-Menüs im wp-admin ausblenden“ – das
  wp-admin zeigt dann nur noch die Website; die Seiten bleiben erreichbar und werden im ERP
  weiter eingebettet.
- Neu: `Erp\Workflow`, `RunFlow` (Zustandsmaschine), `RunRepository`, `Forecast`,
  Tabelle `lw16_runs`, `time_entries.run_id`, Datenbankversion 36.

## [0.20.0] – 2026-10-19

### ERP-Oberfläche: alle Module fein berechtigt, native Seiten, Offline, Subdomain

- **Modulrechte statt Sammelrecht:** Buchhaltung, Reporting, Loyalty, Kasse, Marketing,
  Produktion, Support, Anleitungen und AR-Ansicht haben je ein eigenes Recht
  (`lw16_mod_*`) und lassen sich pro Person vergeben. Wer `manage_lw16` hat, bekommt sie alle
  automatisch (Filter auf `user_has_cap`) – es ist keine Migration nötig, Administratoren
  verlieren nichts. Menü und Seitenaufruf prüfen das Modulrecht (`Menu::page_caps()`).
  Neu in der Navigation: Mailbox (Posteingang, Postfächer verwalten) und AR-Ansicht.
- **Formular- und Ajax-Vorgänge geprüft:** `Erp\ActionMap` ordnet jedem Vorgang
  (`admin-post.php`, `admin-ajax.php`) die feinen Rechte zu, die gleichzeitig nötig sind –
  „Artikel sehen“ reicht nicht mehr zum Speichern. Ein Test verlangt für jeden neu
  eingeführten Vorgang eine Zuordnung oder eine begründete Ausnahme. Damit gelten nun auch
  „Auftragsstatus ändern“, „Aufträge starten und fertigmelden“, „Stammdaten ändern“ und
  „Daten exportieren“ wirklich; kein Recht steht mehr auf „Folgt“.
- **Native Seiten:** Druckfarm (Live-Karten alle 5 s, Fortschritt, AMS-Farben, Kamerabild,
  Vollbild-Anzeige für Monitor/Tablet), Aufträge (Filter, Entwürfe bestätigen) und Versand
  (zu versenden/versendet, DHL-Label erzeugen und stornieren, Lieferschein, Sendungsstand).
  Sie nutzen dieselben Fachklassen wie die klassischen Seiten, die weiter erreichbar und
  gesperrt bleiben („Klassische Ansicht“).
- **Offline-Erfassung:** Ein Service Worker (`/erp/sw.js`) hält die Oberfläche ohne
  Verbindung bereit; Picklisten, zuletzt gescannte Codes und die Navigation bleiben
  lesbar. Buchungen und Entnahmen ohne Verbindung werden vorgemerkt (Anzeige „Offline · n
  vorgemerkt“) und bei Rückkehr der Verbindung der Reihe nach gesendet. Jede Buchung
  trägt eine Kennung (`client_ref`); der Server verbucht sie nur einmal, auch wenn die
  Antwort verloren ging.
- **Subdomain:** `define( 'LW16_ERP_HOST', 'erp.lindenweg16.de' );` in `wp-config.php`
  (Subdomain im Hosting auf dieselbe WordPress-Installation). Auf dieser Adresse liegen
  Oberfläche, wp-admin, Schnittstelle und Dateien auf derselben Herkunft; der Shop bleibt
  auf der Hauptadresse. Hinweis zu `COOKIE_DOMAIN` in der README.
- Tests: ActionMap, Subdomain, Offline-Gegenstücke, Modulrechte; Browsertests mit echtem
  Service Worker, Fake-Kamera und simulierter Schnittstelle.

## [0.19.0] – 2026-10-19

### ERP-Oberfläche: native Werkstatt-Seiten und durchgesetzte Rechte

- **Scannen (neu, nativ):** Code per Kamera (BarcodeDetector, sonst jsQR) oder Handscanner
  lesen; zeigt Lagerplatz-Inhalt bzw. Artikelbestand je Platz und Farbe. Darunter „Bestand
  buchen“ (Zugang/Abgang, Grund, Platz) über die bestehende Schnittstelle `lager/bewegung`.
- **Picklisten (neu, nativ):** offene Listen, Positionen entnehmen oder Artikel scannen (eigene
  Etiketten und fremde Barcodes); Stand frischt alle 30 Sekunden auf.
- **Rechte durchgesetzt:** `lager.buchen` wird jetzt in der Schnittstelle `lager/bewegung`
  geprüft, `verkauf.versand.etikett` beim Erzeugen eines DHL-Etiketts. Neue Rechte
  `lager.scannen` und `verkauf.picken`. Wer vorher nicht in der ERP-Oberfläche eingerichtet
  wurde, behält sein Verhalten (Ableitung aus den alten Betrieb-Rechten); die Vorlage
  „Werkstatt“ enthält Scannen und Buchen, „Packtisch“ Picklisten und Etiketten, aber kein Buchen.
- **Startseite:** Kennzahlen aktualisieren sich jede Minute (nicht im Hintergrund-Tab).
- **App:** Web-App-Manifest unter `/erp/manifest.webmanifest`; mit gesetztem Website-Symbol
  lässt sich die Oberfläche auf Handy und Tablet auf den Startbildschirm legen.
- Tests: Rechte im Code gegen den Katalog geprüft, jede native Seite hat ihren Bildschirm.

## [0.17.0] – 2026-10-19

### Neu: ERP-Oberfläche unter /erp (Prototyp, zweiter Zugang neben wp-admin)

- **Eigene Oberfläche** unter `lindenweg16.de/erp`: Anmeldung mit dem normalen WordPress-Konto,
  Look des Shops, Handy, Tablet und Desktop (Seitenleiste bzw. Tabs unten, Hell/Dunkel,
  Suche mit Strg/⌘ K). Kleine Browser-Anwendung ohne Build-Schritt (`assets/erp/`).
- **Menü aus den Rechten:** Jede Person sieht nur die Seiten, die sie darf. Start zeigt
  Shop-Kennzahlen (WooCommerce), soweit erlaubt.
- **Alle bestehenden Seiten eingebettet** (Verkauf, Fertigung, Lager, Finanzen, Verwaltung,
  dazu Shop-Bestellungen und -Produkte); das wp-admin-Gerüst wird im Rahmen ausgeblendet.
- **Rechte pro Person, fein einstellbar** (Benutzer & Rechte): ~45 Einzelrechte in fünf
  Bereichen, Vorlagen (Werkstatt, Packtisch, Fertigung, Betriebsleitung), Voraussetzungen
  schalten automatisch mit, Vorschau „So sieht die Person das ERP“. Gespeichert am Nutzer
  (`lw16_erp_perms`); die sechs alten Betrieb-Rechte werden nachgeführt, bestehende Nutzer
  behalten ihre bisherigen Möglichkeiten.
- **Schutz:** keine Rechteausweitung (nur eigene Rechte vergebbar), keine Selbstaussperrung,
  Vollzugriff nicht beschneidbar, Änderungen im Protokoll. Plugin-Seiten prüfen das feine
  Recht auch beim direkten Aufruf im wp-admin.
- **Grenzen des Prototyps:** Rechte mit Kennzeichnung „Folgt“ werden gespeichert, aber erst
  von nativen Seiten durchgesetzt; Module hinter „Nur Vollzugriff“ prüfen noch `manage_lw16`.
- Neu: `includes/Erp/` (Permissions, Navigation, Access, Shell, RestApi), Tests
  `ErpPermissionsTest`, `ErpNavigationTest`. Route `lw16/v1/erp/*`.

## [0.18.0] – 2026-10-19

### Neu: Mailbox – ein Postfach für jeden Mitarbeiter

- **Postfächer** (persönlich oder geteilt wie `info@`) unter **Mailbox → Postfächer**,
  für IONOS vorbelegt (`imap.ionos.de:993`, `smtp.ionos.de:587`); Passwörter
  verschlüsselt, „Verbindung testen“ prüft Eingang und Ausgang vor dem Speichern.
- **Lesen:** Abgleich per IMAP alle fünf Minuten und auf Knopfdruck, neueste Mails zuerst,
  Ordner, Suche, Filter (ungelesen, markiert, mir zugewiesen), Verlauf, Anhänge als Download.
  HTML-Mails in einem abgeschotteten Rahmen, fremde Bilder erst nach „Bilder laden“.
- **Schreiben:** Antworten, Allen antworten, Weiterleiten, Anhänge, Entwürfe, Vorschau.
  Die Mail geht **im Layout der Shop-Mails** raus (Logo, Farben, Schriften, Fußzeile des
  Themes) mit Signatur und Zitat; eine Kopie landet im Gesendet-Ordner bei IONOS.
- **Team:** Besitzer, Mitnutzer (nur lesen oder auch senden) und Verwalter; Zuweisung und
  interne Notizen in geteilten Postfächern; Zugriffe auf fremde persönliche Postfächer im
  Protokoll.
- **Shop:** Box „Mails“ an der Bestellung, „Im Shop“ unter der Kundenmail, Mail an den
  Kunden aus der Bestellung.
- **Datenschutz:** Mails erscheinen in der Auskunft (Werkzeuge → Daten exportieren); sie werden
  wegen der Aufbewahrungspflicht nicht gelöscht.
- Neue Tabellen (`DB_VERSION` 35), Hintergrundjob `lw16_mail_sync`, Menü **Mailbox**,
  Anleitung D.20. Einstellungen: Abruf an/aus, Aufbewahrungsfrist beim Abruf, Anhangsgrenze.
- **Theme** (1.2.0): Signatur und Zitat in persönlichen Mails; Zitatzeilen („> …“) werden als
  Zitatblock dargestellt.
- Tests: 52 neue Unit-Tests; Ende-zu-Ende-Tests gegen einen echten Dovecot in
  `tests/integration/`.

## [0.16.0] – 2026-10-19

### Neu: Updates per Knopfdruck aus GitHub

- **WordPress-Update-Funktion:** Plugin und Theme „Lindenweg16“ melden neue Versionen
  aus dem Branch `main` wie jedes andere Plugin – unter Plugins, Design → Themes und
  Dashboard → Aktualisierungen erscheint „Jetzt aktualisieren“. WordPress lädt, entpackt
  und ersetzt selbst; automatische Updates lassen sich wie gewohnt einschalten.
- **Woher:** Version aus `lindenweg16.php` bzw. `block-theme/lindenweg16/style.css` in
  `main`; Paket ist das ZIP des Branches bzw. `block-theme/dist/lindenweg16.zip`. Ein Update
  erscheint nur bei höherer Versionsnummer.
- **Ordnername bleibt:** Der entpackte Ordner wird auf den installierten Namen umbenannt
  (z. B. `woocommerce-erp-main`), damit kein zweites Plugin entsteht.
- **„Jetzt nach Updates suchen“** in der Plugin-Liste fragt GitHub sofort ab (sonst alle
  sechs Stunden); „Erneut prüfen“ unter Dashboard → Aktualisierungen ebenso.
- **„Details ansehen“** zeigt die letzten Einträge dieses Changelogs.
- Klasse `Core\Updater`, Tests in `tests/UpdaterTest.php`.

## [0.15.1] – 2026-10-19

### Geändert

- **E-Mails im Website-Design:** `MailTemplate::wrap_html()` gibt das Layout über den Filter
  `lindenweg16_mail_html` an das Lindenweg16-Block-Theme ab. Warenkorb-Erinnerung,
  Dankeschön, Bewertungsanfrage, Warteliste, Events, Support und Manager-Mails sehen damit
  aus wie die WooCommerce-Mails. Ohne das Theme bleibt das bisherige Layout.
- **Warenkorb-Erinnerung mit Artikelkarte:** `MailTemplate::send()` nimmt Zusatzdaten
  (`$context`) an und reicht sie an den Filter weiter. Die Erinnerung übergibt Bild, Link,
  Menge und aktuellen Preis je Artikel – das Theme zeigt daraus eine Karte wie in der
  Bestellübersicht statt der Textliste. Der Warenkorb-Snapshot speichert jetzt auch die
  Varianten-ID (für Bild und Preis der gewählten Farbe).

## [0.15.0] – 2026-10-09

### Neu: AR-Ansicht „An deiner Wand ansehen“

Mobile Besucher sehen ein Produkt (zum Beispiel den Haster Haken) per AR in
Originalgröße an ihrer eigenen Wand – ohne App.

- **Modul `Modules/ArView/`** mit Metabox, Banner, Shortcode, REST-Endpunkt und Admin-Seite.
- **3D-Modell je Produkt:** Metabox „3D-Modell (AR)“ im WooCommerce-Produkteditor
  (GLB Pflicht, USDZ und Posterbild optional, Schalter „AR-Hinweis auf dieser
  Produktseite anzeigen“), Auswahl über die Mediathek. Ohne Modell erscheint nirgends ein Hinweis.
- **Upload-Typen:** `.glb` (`model/gltf-binary`) und `.usdz` (`model/vnd.usdz+zip`),
  nur für Administratoren; der Dateiinhalt muss zur Endung passen.
- **Hinweis-Banner** (Bottom-Sheet, kein Modal) auf Produktseiten mit Modell und
  aktiviertem Schalter – nur auf AR-fähigen Mobilgeräten (iOS: AR Quick Look,
  Android: WebXR/ARCore). Die gesamte Logik läuft im Browser, der Server gibt nur
  verstecktes Markup aus; die Seiten bleiben cachebar. Erscheint nach der
  eingestellten Verzögerung (Standard 3 s) oder beim ersten Scrollen.
  „Nein“ und Schließen-X sperren den Hinweis für die eingestellte Zeit
  (Standard 7 Tage, nur ein Zeitstempel im localStorage, alle Storage-Zugriffe in
  try/catch); „Ja“ führt auf die AR-Seite (`?produkt=ID`) und unterdrückt den
  Hinweis für die Sitzung.
- **AR-Seite** per Shortcode `[l16_ar_ansicht]`: `<model-viewer>` mit
  `ar-modes="webxr scene-viewer quick-look"`, `ar-placement="wall"`,
  `ar-scale="fixed"`, `camera-controls`, eigener AR-Knopf „An meiner Wand platzieren“,
  Anleitung, Produktname, Preis, „In den Warenkorb“, „Zurück zum Produkt“.
  Fallbacks: Produktauswahl bei fehlender/ungültiger ID, Hinweis „Öffne diese Seite
  auf deinem Smartphone“ samt QR-Code (Schwarz-Weiß) auf dem Rechner.
- **Lokal statt CDN:** model-viewer 4.3.1 und die Schrift Nunito liegen im Plugin
  (`assets/vendor/model-viewer/`, `assets/vendor/nunito/`), geladen als ES-Modul
  nur auf Seiten mit dem Shortcode. Die Nachlade-Orte für Draco/KTX2/Lottie (Google, jsDelivr)
  sind auf das Plugin umgelenkt, damit keine IP-Adresse an Dritte geht –
  Modelle müssen daher **unkomprimiert** als GLB exportiert werden.
- **Verwaltung → AR-Ansicht:** Funktion an/aus, Ziel-Seite, Banner-Texte,
  Sperrdauer (Standard 7), Verzögerung (Standard 3) und eine Auswertung je Produkt:
  Banner gezeigt / Ja / Nein / AR gestartet / Ja-Quote (30 Tage, 90 Tage, gesamt).
- **Anonyme Zähler:** neue Tabelle `lw16_ar_stats` (nur Tag, Produkt, Ereignis,
  Anzahl; `DB_VERSION` 34). Übermittlung per REST (`GET lw16/v1/ar-nonce`,
  `POST lw16/v1/ar-event`, Nonce im Kopf, nie aus dem Cache) – ohne IP, Benutzer- oder Gerätedaten.
- Betriebsanleitung: neuer Abschnitt „AR-Ansicht“ in Teil D (Kapitel D.8) samt
  Fehlerzeilen in D.19; Testcheckliste in `docs/ar-ansicht-testcheckliste.md`.
- Tests: `ArViewOptionsTest`, `ArViewStatsTest`, `ArViewUploadTypesTest`,
  `ArViewViewerTest`, `ArViewAssetsTest`.

### Geändert

- Version auf 0.15.0, Schema-Version auf 34.
