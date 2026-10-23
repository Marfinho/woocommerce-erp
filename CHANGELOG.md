# Changelog

Alle nennenswerten Änderungen am Plugin. Format nach
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/); die Versionsnummer
steht in `lindenweg16.php` (`Version` und `LW16_VERSION`).

Frühere Versionen sind hier nicht nachgetragen; der Stand davor steht in der Git-Historie
und im Abschnitt „Umsetzungsstand“ der README.

## [0.37.0] – 2026-10-23

### Gutschein mit Vorlagenwahl
- Neues Modul „Gutschein“: Im Produktformular (Allgemein) lässt sich ein Produkt als „Gutschein mit Vorlagenwahl“ markieren. Unter dem Schalter gibt es eine Vorschau aller drei Vorlagen.
- Auf der Produktseite wählt der Kunde zwischen drei Vorlagen im Stil des Haster-Haken-Flyers (Hell – Grau & Pink, Pink – volle Farbe, Dunkel – Schwarz & Pink: fette Grotesk, Sperrschrift, dünne Linien, Akzent #EE1C4F) oder „Nur E-Mail“. Felder: Von, An, Text und optional die E-Mail des Beschenkten. Die Karte schreibt beim Tippen live mit.
- Auswahl und Texte stehen im Warenkorb, an der Kasse und an der Bestellposition. Ein Gutschein ist immer ein Stück, damit jeder seinen eigenen Text hat.
- Nach der Zahlung entsteht ein WooCommerce-Gutscheincode (`LW16-XXXX-XXXX`) in Höhe des bezahlten Betrags, einmal einlösbar, gültig bis Ende des dritten Jahres, nicht für den Kauf weiterer Gutscheine. Die gestaltete Karte geht per Mail an den Besteller und, wenn angegeben, an den Beschenkten.
- Der Gutschein geht bei den drei Vorlagen zusätzlich als PDF (A5 hoch, randlos, im Stil der Vorlage) im Anhang der Mail raus. Der Besteller bekommt ihn immer, ein Beschenkter mit eigener Adresse ebenfalls.
- „Nur E-Mail“ nutzt die neue Mail-Vorlage „Gutschein (nur als E-Mail)“, die unter Einstellungen wie die anderen Mails angepasst werden kann.
- Im Bestell-Backend gibt es die Aktion „Gutschein erneut senden“.
- Auf dem Gutschein-PDF sitzt unten rechts ein QR-Code (Vektor, mit Plugin-eigenem Generator). Er führt auf den Shop mit `utm_source=gutschein`, `utm_medium=qr`, `utm_campaign=gutschein-pdf` und `utm_content=<Vorlage>`. WooCommerce speichert das bei der Bestellung; im Marketing legt man eine Kampagne mit der Kennung `gutschein-pdf` an, um Bestellungen zuzuordnen. Die Adresse ist über den Filter `lw16_gutschein_qr_url` änderbar.
- Wortmarke und Titel der PDFs sind mit Bricolage Grotesque vorgezeichnete Bilder (`assets/gutschein/`, erzeugt mit `branding/gutschein/erzeuge-bilder.py`), weil der PDF-Generator nur Helvetica kennt. Der Generator kann dafür jetzt Zeichenabstand (Sperrschrift).
- Noch nicht in echtem WordPress/WooCommerce getestet.

## [0.36.0] – 2026-10-22

### Push-Benachrichtigungen
- Das ERP kann Benachrichtigungen aufs Handy und den Desktop schicken (Web-Push mit VAPID, ohne Fremdbibliothek). Einschalten pro Gerät im Profilmenü unter „Benachrichtigungen“, mit Testmeldung und Auswahl der Meldungsarten.
- Meldungsarten: neue Shop-Bestellung, Artikel fast oder ganz ausverkauft; jeweils nur für Personen mit dem passenden Recht.
- Der Push-Dienst des Browsers bekommt keinen Text: Er weckt nur den Service Worker, der die Meldung selbst beim Server abholt.
- iPhone und iPad: nur, wenn das ERP über „Zum Home-Bildschirm“ als App installiert ist (iOS 16.4 oder neuer).

## [0.35.1] – 2026-10-22

- **Belege ohne Tesseract auf dem Server:** Fotos werden jetzt im Browser gelesen (tesseract.js, wird beim ersten Mal einmalig geladen). Das Foto bleibt auf dem Gerät, nur der erkannte Text geht zur Auswertung an den Server (`buch/belege/ocr-text`). Klappt auch das nicht (offline), bleibt es bei der Handeingabe.

## [0.35.0] – 2026-10-22

### Buchhaltung und Marketing-Kosten im ERP

- Buchhaltung mit Reitern: Belege (Foto per Kamera, Datei- und E-Rechnung-Upload, OCR füllt Felder vor, Doppelbeleg-Warnung, Festschreiben, Storno mit Grund), Rechnungen, Kasse, Journal, Dauerbuchungen, Marketing-Kosten, Anlagen/AfA, Jahresabschluss-Checkliste, EÜR, GoBD-Prüfung, Export.
- Marketing: Kampagnen-Auswertung mit Kosten, Budget-Ampel, ROAS, Status, Duplizieren, Vorlagen; Kostenübersicht je Kanal/Monat mit CSV-Export und Zuordnung loser Belege.
- Alle Schreibvorgänge laufen über die bestehenden GoBD-Repositories (Hash-Kette, Protokoll).
- Noch nicht in echtem WordPress, Browser oder mit Tesseract getestet.

## [0.34.1] – 2026-10-22

### Seitenaufruf-Zähler robuster, mit Selbsttest
- **Meldung ohne Ursprungsprüfung:** Die Meldung geht jetzt an die Adresse ohne Host (nur Pfad) und als einfacher Text. Vorher
  scheiterte sie, wenn die Website unter einer anderen Schreibweise der Adresse aufgerufen wurde (mit und ohne www), weil der Browser
  sie dann als fremden Ursprung vorab abfragte.
- **Block „Seitenaufrufe“ zeigt den letzten gezählten Aufruf** („vor x Min“) oder sagt, dass noch keiner gezählt wurde und warum
  das sein kann: Angemeldete Personen zählen nicht, ein Seiten-Cache liefert die alte Seite ohne Skript aus.

## [0.34.1] (Anleitung) – 2026-10-22

- **Betriebsanleitung:** Das Stylesheet steht jetzt direkt in `docs/betriebsanleitung.html` (die Datei `docs/ba.css`
  entfällt). Auf Servern, die die separate CSS-Datei nicht auslieferten (503), erschien die Anleitung ohne Gestaltung.

## [0.34.0] – 2026-10-22

### Live-Dashboard für den Vollbildschirm
- **Neue Seite `#/live`** (Knopf „● Live“ in der Kopfzeile am Desktop; `#/live/tv` startet direkt im Kiosk ohne Menü):
  Uhr und Datum groß, darunter Verkauf heute (Umsatz, Bestellungen, Ø Warenkorb, zu versenden, Verlauf nach Stunde, letzte
  Bestellungen, Vergleich zu gestern), Seitenaufrufe der Website, Kennzahlen des Monats gegen ihre Ziele (grün, gelb, rot),
  Produktion (Platten nach Stand, Drucker mit Fortschritt und Restzeit, knappes Filament) und laufende Kampagnen mit Kosten,
  Umsatz, Ertrag je Euro und Budgetverbrauch.
- **Live:** Alle 15 Sekunden neue Werte (kurz zwischengespeichert, damit die Datenbank nicht belastet wird), Anzeige „vor
  x s“ und bei Verbindungsabbruch der letzte Stand mit Uhrzeit. „Vollbild“ nutzt den Browser-Vollbildmodus und hält den
  Bildschirm wach. Eine neue Bestellung leuchtet kurz auf.
- **Rechte:** Jede Person sieht nur die Blöcke, für die sie das Recht hat (Verkauf, Berichte, Produktion, Marketing).
  Personenbezogene Kennzahlen wie Wochenstunden fehlen bewusst, der Bildschirm hängt im Raum.
- **Seitenaufrufe statt Besucher:** Ein kleines Skript am Seitenende der Website meldet jeden Aufruf anonym: kein Cookie, keine
  Adresse, kein Seitenpfad gespeichert, nur Anzahl je Zehn-Minuten-Fenster und Bereich (Start, Shop, Warenkorb, Kasse,
  Inhalt). Nicht gezählt werden angemeldete Personen, Roboter und Browser mit „Do Not Track“. Es zählt Aufrufe, nicht
  Personen: Wer dreimal lädt, zählt dreimal. Neue Tabelle `traffic` (DB-Version 40).

## [0.33.1] – 2026-10-22

### Druckzeit ist keine Arbeitszeit
- **Der Workflow bucht beim Melden einer Platte nur noch die Rüstzeit** als Arbeitszeit. Die Druckzeit ist Maschinenzeit
  (sie steht im Druckjournal und in Soll gegen Ist): Während der Drucker läuft, arbeitet die Person an etwas anderem.
  Vorher stand sie in „Meine Woche“ und hätte in jede Lohnabrechnung gezählt.
- **Ältere Buchungen** (Kategorie Druckzeit, mit einer Platte verbunden) bleiben gespeichert, zählen aber nicht mehr mit:
  nicht in Wochensummen, Löhnen, Lohnabrechnungen und Kapazität. Von Hand erfasste Druckzeit ohne Platte zählt weiter.

## [0.33.0] – 2026-10-21

### Montage im Workflow
- **Spur „Montage“ unter dem Brett:** Der Workflow zeigt jetzt auch, was aus Rohlingen und Kleinteilen montiert werden
  muss (Halbzeuge und Module mit Stückliste, Beschaffungsweg „Montage“). Jede Karte nennt die Teile der Stückliste mit
  Bedarf, Lagerbestand und Zustand: **da**, **im Druck** (Platte läuft oder ist freigegeben) oder **fehlt**.
- **Montage anlegen:** Sind alle Teile da, legt ein Klick den Fertigungsauftrag samt Pickliste an. Vorher nicht, damit
  der Planer den Bedarf an Rohlingen nicht für gedeckt hält, die noch gar nicht gedruckt sind.
- **Arbeitsseite `#/montage/<Nr>`** (am Handy nutzbar): Material holen, Uhr mit Pause und Weiter, Fertigmelden mit
  Stückzahl. Gebucht wird beim Fertigmelden wie bisher: Material geht ab, die Stücke kommen ins Lager. Abbrechen darf, wer
  Pläne freigeben darf.
- **Heute:** Offene Montagen stehen unter „Dein Nächstes“ (nach Platte melden und rüsten).

### Stempeluhr als Sprechblase
- **Klick auf die Uhr in der Kopfzeile** öffnet eine Blase statt der Zeitseite: Stand (läuft, Pause, steht), laufende Zeit,
  Start, Heute gebucht, Woche gegen Sollstunden und was währenddessen im Betrieb lief. Dort lassen sich die Uhr starten,
  pausieren, fortsetzen, verwerfen und mit Kategorie, Stunden und Tätigkeit stoppen und buchen. „Mehr …“ führt zur
  Zeiterfassung. Escape, Klick daneben oder Seitenwechsel schließen die Blase.
- **Am Handy erreichbar:** Die Uhr stand dort bisher gar nicht in der Kopfzeile (sie erschien erst ab 900 px Breite).

### Lohnabrechnungen aus den Zeiten
- **Neue Seite `#/lohn`** (Knopf „Lohnabrechnungen erstellen“ auf der Seite Löhne, Recht wie Löhne): Monat wählen, je Person
  Stunden, Stundenlohn und Brutto. „Alle offenen als Entwurf anlegen“ oder je Person „Abrechnung erstellen“.
- **Gerechnet wird brutto:** Stunden mal der am jeweiligen Tag gültigen Stundenlohn. Ändert sich der Lohn im Monat, gibt es
  zwei Zeilen mit je eigenem Satz. Dazu frei eintragbare Zuschläge und Abzüge (Prämie, Vorschuss mit Minus). **Lohnsteuer
  und Sozialversicherung werden nicht berechnet**, das steht auch auf Seite und Beleg; die Abrechnung ist die Grundlage für
  den Steuerberater oder ein Lohnprogramm.
- **Ablauf:** Entwurf (rechnet sich bei jedem Speichern aus den Zeiten neu) → Festschreiben (bekommt die laufende Nummer
  `LA-JJJJ-NNNN` und eine Prüfsumme, bleibt unverändert) → Bezahlt (mit Datum) oder Storniert (mit Grund, bleibt stehen).
  Korrektur heißt stornieren und neu anlegen. Eine zweite Abrechnung für denselben Monat und dieselbe Person wird abgewiesen.
- **Lücken werden benannt, nicht mit null bewertet:** Fehlt für Stunden der Stundenlohn, lässt sich der Entwurf anlegen, aber
  nicht festschreiben; die Zeile sagt, wie viele Stunden es betrifft. Werden nach dem Festschreiben noch Zeiten für den Monat
  nachgetragen, zeigt die Zeile die Abweichung in Stunden.
- **Belege:** PDF je Abrechnung (Tage, Sätze, Zuschläge, Gesamtbetrag, Prüfsumme) und CSV je Monat (Semikolon, deutsches
  Zahlenformat, UTF-8 mit Marke für Excel, gegen Formeln abgesichert).
- **Obergrenzen für Minijobs und Aushilfen:** Je Person lassen sich eine Verdienstgrenze je Monat, eine Stundengrenze je
  Monat und eine Verdienstgrenze je Jahr eintragen („Obergrenze setzen“ an der Zeile; leer heißt keine Grenze, die Zahlen
  legt der Admin fest, nichts ist fest eingebaut). Die Zeile zeigt Verbrauch in Prozent, ab 90 % gelb, darüber mit Warnung;
  der Dialog rechnet mit Zuschlägen und Abzügen live mit. **Festschreiben fragt bei überschrittener Grenze nach** und
  schreibt erst nach Bestätigung fest (im Protokoll vermerkt). Das Jahr zählt die übrigen gültigen Abrechnungen des
  Kalenderjahres dazu.
- Neue Tabelle `payslips` (DB-Version 39), in Verfahrensdokumentation und Reset-Schutz aufgenommen; alle Schritte stehen im Protokoll.

## [0.32.0] – 2026-10-21

### Picklisten im ERP erzeugen
- **Verkauf → Bestellungen → Picklisten:** Der neue Kasten *Neue Pickliste aus Bestellungen* zeigt, wie viele offene
  Bestellungen und Positionen die Liste enthielte, und erzeugt sie mit einem Klick (vorher nur in der Klassischen
  Ansicht). Gibt es schon eine offene Liste aus Bestellungen, fragt das ERP vor dem Erzeugen nach. Die neue Liste
  öffnet sich sofort. Der Kasten erscheint nur mit dem Recht „Picklisten erzeugen“ (`lw16_betrieb_make_picklist`).
- Neue REST-Routen `erp/picklisten/bestellungen` (Vorschau) und `erp/picklisten/erzeugen`.
- Betriebsanleitung B.3 beschreibt den neuen Weg.

## [0.31.0] – 2026-10-20

### Betriebsanleitung neu, vier Fehler behoben
- **Betriebsanleitung vollständig neu geschrieben** für die Oberfläche unter `/erp`: alle Wege (Platz → Reiter → Auswahl,
  Profilmenü), Teil A–D mit 96 neuen Bildschirmfotos aus einer Testinstallation mit Beispieldaten (Desktop und Handy).
  Die alten wp-admin-Bilder sind entfernt. Was nur in der Klassischen Ansicht geht (Komponenten, Verbrauchsmaterial,
  erste Filamentcharge, manuelle Bestellung, Slicing-Worker-Token, Livegang) steht ausdrücklich dabei.
- **Zahlen → Buchhaltung:** Seitenumbruch der Belegliste und der Rechnungen warf einen Fehler, wenn nur eine Seite da war.
- **Bedarf und Dashboard:** Der Sollbestand wurde als Zeichenkette gerechnet und konnte bei Dezimalwerten falsche Lücken
  und Warnungen erzeugen; jetzt als Zahl.
- **Produktion → Drucker:** Die Gerätezeile verlinkt bei einem verknüpften Bambu-Drucker auf die Druckerakte.
- **Suchfeld:** Beschriftung „Gehe zu … Seite“ (es sucht nur Seiten, keine Artikel oder Bestellungen).
- Tests: `SollbestandSpalteTest`, `DruckerAkteLinkTest`.

## [0.30.0] – 2026-10-20

### Hinzugefügt
- **Workflow erkennt die Druckdatei:** Die Bambu-Bridge meldet den Dateinamen des Jobs auf dem Drucker
  (`subtask_name`/`gcode_file`). Das ERP ordnet ihn der Platte zu und schaltet genau diese auf „Im Druck“ bzw. „Ist melden“ –
  auch wenn mehrere Platten für denselben Drucker anstehen. Die Karte zeigt „Datei am Drucker erkannt“ bzw. „wartet auf
  <Datei>“. Ohne Dateinamen gilt die alte Regel (genau eine Platte pro Drucker).
- Die Projekt-3MF heißt jetzt lesbar `Platte-<Drucker>-<Datum>-<Nr>-<Zufall>.3mf` statt `bambu-plate-…`. Beim Senden aus
  Bambu Studio den Projektnamen unverändert lassen (Suffixe wie `_plate_1` sind ok).
- Schema: `bambu_printers.current_file` (DB_VERSION 38).

**Hinweis:** Die Bridge auf dem Pi muss aktualisiert werden (`raspberry-pi/bambu_bridge.py`).

## [0.29.0] – 2026-10-20

### Anordnung auf dem Bett sichtbar
- **Plan freigeben:** Unter der Plattenliste steht je Platte „Anordnung auf dem Bett“ mit den Umrissen der Teile
  (aufklappbar, bei nur einer Platte offen).
- **Plattenseite (Rüsten):** Derselbe Block steht über den Schritten, damit man vor dem Slicen sieht, wie die Teile
  liegen. Die Zeichnung ist die der Bambu-Seite; Teile, deren Form sich nicht mehr auslesen lässt, erscheinen als Punkt.

### Anordnung: mehr Teile pro Platte
- **Echter Umriss statt Hülle:** Der Packer rechnet jetzt mit dem Schattenbild des Teils (alle Dreiecke von oben,
  Raster 0,5 mm) statt mit der konvexen Hülle. Einbuchtungen werden genutzt: Kleine Teile liegen in der Öffnung eines
  C-förmigen Klemmrings oder neben den Aufhängebögen einer Schale. Die Plan-Vorschau und die Plattenansicht zeichnen
  denselben Umriss. Sperrflächen und Randabstand stecken im Raster, der Abstand bleibt 5 mm.
- **Suche:** Je Teil wird die niedrigste, dann linkeste freie Lage gesucht, bitweise für ein ganzes Bett auf einmal.
  Dazu zwei feste Sortierungen und gewürfelte Varianten (fester Startwert), solange mehr Platten nötig sind, als die
  Fläche mindestens verlangt (Zeitbudget 4 s). Behalten wird die Anordnung mit den wenigsten Platten. Die
  Hüllen-Anordnung läuft zur Gegenprobe mit, wenn mehr als eine Platte entsteht.
- **Keine Kippung:** Gedreht wird nur um die senkrechte Achse in Vierteldrehungen; die Auflagefläche bleibt, wie in der
  Projektdatei vorgegeben (ein Test sichert das). 45°-Schritte lassen sich über den Filter `lw16_plate_rotations`
  einschalten, `array( 0 )` schaltet jede Drehung ab.
- **Fehler behoben:** Beim Zusammensetzen der 3MF lagen gedrehte Teile um ihre Höhe oder Breite neben der berechneten
  Position (die Matrix drehte um die Ecke des ungedrehten Teils). Jetzt liegt die Ecke des gedrehten Teils auf der
  Zielposition.
- **Schneller:** Die konvexe Hülle prüfte jeden Punkt gegen jeden (quadratischer Aufwand); große Netze brauchten
  Minuten, jetzt Sekundenbruchteile.
- **Bekannte Grenze:** Die konvexe Gegenprobe füllt Einbuchtungen weiter auf.

## [0.28.1] – 2026-10-20

### Behoben
- **Mehrere Platten, ein Etikett:** Beim Freigeben mehrerer Platten kam nur das erste Etikett aus dem Drucker, weil alle
  Seiten in einem Druckauftrag lagen. Jetzt legt das ERP für jedes Etikett einen eigenen Druckauftrag an (Platten und
  Lagerplatz-Etiketten). Der Pi-Client bleibt unverändert.
- **Theme-Seite und Finanz-Dashboard** teilten die Kennung `lindenweg16`; die Theme-Seite heißt jetzt
  `lindenweg16-inhalte`.

## [0.28.0] – 2026-10-19

### Roadmap-Runde: Werkstatt, B2B und Verwaltung
- **Wareneingang per Scan:** Scannt man einen Artikel oder ein Kleinteil mit offener Bestellung, steht unter dem
  Bestand der Block „Wareneingang“ mit Menge und „Eingegangen ✓“.
- **Stempeluhr:** Pause und Weiter; die laufende Uhr steht in der Kopfzeile und zählt dort mit. Die Pause rückt den
  Start nach hinten, es zählt nur die gearbeitete Zeit.
- **Kommission als Listenbuchung vom Lager aus** (Händler wählen, dann dieselbe Liste wie in der Kundenakte).
- **Heute** zeigt zusätzlich fällige Wartung, überfällige Lieferungen und Rechnungen und fällige Wiedervorlagen.
- **wp-admin nur für die Website:** Mit der Einstellung leiten Anmeldung und Dashboard ERP-Nutzer ohne
  Websiteverwaltung ins ERP.
- **Rechte:** Vorlage und Vorschau „So sieht … das ERP“ stehen oben, die Einzelrechte sind eingeklappt.
- **Bambu nativ:** Druckaufträge einreihen (Upload, Modellablage, AMS-Fach) und abbrechen, Platten anordnen mit
  Ansicht und Download, Slicing-Profile hochladen, ersetzen, löschen.
- **Konfigurator nativ:** Teile und Sperrbereiche, Modell-Upload (.glb).
- **Marketing schreibend:** Kampagnen anlegen und ändern, Belege zuordnen, Kilometer, beenden.
- **Import & Doku nativ:** CSV-Import in vier Schritten (Datei, Zuordnung, Vorschau, Import), Migration und
  Verfahrensdokumentation. Deutsche Datums- und Zahlenformate werden umgewandelt.
- **Postfächer verwalten nativ:** Anlegen, ändern, testen, Freigaben, ohne dass Passwörter je ausgegeben werden.
- **Behoben:** Die laufende Uhr in der Kopfzeile überdeckte eine Funktion und legte die Workflow-Seite lahm; ein
  Test sichert jetzt ab, dass keine Variable eine Funktion in `erp.js` überdeckt.
- **Bleibt eingebettet:** Kasse, Buchhaltung (Erfassen und Storno), Shop-Produkte.

## [0.27.0] – 2026-10-19

### B2B: Kundenakte ausgebaut
- **Kommission buchen** in der Akte: Ausliefern, Abrechnen (ein Rechnungsposten über alle Positionen, damit die
  Rabattstaffel auf den Gesamtwert greift) und Zurücknehmen, mit Mengenliste je Artikel und Farbe. Hinweis, wenn laut
  Profil keine Kommission vereinbart ist; Doppelbuchungssperre.
- **Bestellung ↔ Kontakt:** Auf der Bestellseite wird ein Kontakt mit gleicher E-Mail oder Firma vorgeschlagen und mit
  einem Klick zugeordnet (oder gelöst); die Bestellung zählt dann in der Akte und in den B2B-Kennzahlen. Von der
  Bestellung führt ein Link zur Akte.
- **Händlerprofil** in der Akte: USt-IdNr., Rechnungs- und Lieferadresse, Lieferhinweise, Entscheider, Wareneingang,
  Kanal, Bestellrhythmus, nächste erwartete Bestellung, Verpackung, Kreditlimit (Warnung bei Überschreitung durch
  offene Posten), Kommission vereinbart, Display. Beim Speichern bleiben nicht geänderte Felder erhalten.
- **Preisliste je Händler:** Händlerpreise netto, Mengenrabatt-Staffel und Zahlungsziel des Kunden, druckbar und als
  PDF speicherbar.
- **Zahlungserinnerung** bei überfälligen Posten: vorbereiteter, änderbarer Mailtext; nach dem Senden steht eine
  Aktivität mit Wiedervorlage in einer Woche in der Akte.
- Kontaktliste lädt nur noch Bestellungen, die einem Kontakt zugeordnet sind (schneller bei vielen Shop-Bestellungen).

## [0.26.0] – 2026-10-19

### Fast alle Seiten nativ im ERP
Die eingebetteten alten wp-admin-Seiten sind durch eigene Seiten ersetzt (jede mit „Klassische Ansicht“ als
Rückfall und denselben Prüfungen, Rechten und Protokolleinträgen wie vorher). Neu: Seiten leben in eigenen Dateien
(`includes/Erp/Pages/`, `assets/erp/pages/`, Anleitung in `includes/Erp/Pages/README.md`).
- **Produktakte:** Revisionen mit 3MF-Upload, Stammdaten (auch neu anlegen, stilllegen), Stückliste mit
  Tiefenprüfung, Kalkulation mit „Preis übernehmen“, Beschaffung.
- **Lager:** Bestand (buchen, umlagern, Kommission, Journal mit Storno, Sollbestand), Lagerplätze mit
  Fertigungsaufträgen, Bedarf als Ansicht auf den Plan.
- **Produktion:** Druckjournal, Drucker & Wartung, Bambu (Status, Verbindung, Geräteverknüpfung), Qualität,
  Kapazität, Produktionsaufträge.
- **Verkauf:** Kontakte mit Akte (Konditionen, Bestellungen, offene Posten, Nachbestell-Link), Shop-Bestellungen,
  Loyalty-Prüfung, Marketing (lesend).
- **Zahlen:** Finanz-Dashboard, Berichte (Verlauf, Ziele, Export), Reporting, Buchhaltung (nur lesend, GoBD).
- **Verwaltung:** Zeiterfassung mit Stempeluhr, Löhne, Etikettendrucker (mit „Erneut senden“/„Abbrechen“),
  Einstellungen (Geheimnisse nie im Klartext).
- **Mailbox** (lesen, antworten, weiterleiten, Entwürfe), Support, Anleitungen, AR-Ansicht.
- **Bleibt eingebettet:** Kasse, Konfigurator, Postfächer verwalten, Import & Doku, Shop-Produkte, Buchhaltung
  (Erfassen/Storno), Marketing (Anlegen), Bambu (Einreihen, Platten, Slicing-Profile).
- Warteschlange offline: eine mitgegebene `client_ref` bleibt erhalten (keine Doppelbuchung beim Nachsenden).

## [0.25.0] – 2026-10-19

### Neue Menüführung und Optik im ERP
- **Sechs Bereiche statt rund 40 Seiten in fünf Listen:** links Heute, Produktion, Verkauf, Lager,
  Katalog, Zahlen. Innerhalb eines Bereichs Reiter, darunter bei Bedarf eine Auswahl (z. B. Verkauf →
  Bestellungen → Versand, Aufträge, Picklisten, Shop-Bestellungen). Ein Klick auf den Bereich öffnet
  die zuletzt besuchte Seite.
- Mailbox mit Zähler und Profilmenü (Einstellungen, Benutzer & Rechte, Löhne, Etikettendrucker, Import,
  Support, Anleitungen, Hell/Dunkel, Shop, Abmelden) statt langer Listen. Handy: Leiste unten mit „Mehr“.
- Neue Optik: schmale dunkle Leiste, große Karten, ruhige Flächen; kein wp-admin-Look mehr im Rahmen.
  Rechte und Seiten bleiben unverändert; die Zuordnung steht in `Navigation::layout()`.
- **Heute:** „Dein Nächstes“ und eine Aufgabenliste quer über alles (Platte melden, rüsten, Bestellungen
  versenden, nachbestellen, Mails) – nur, was die Person laut Rechten erledigen darf.
- **Drucker:** Übersicht mit Live-Stand und fälliger Wartung; Druckerakte mit Verlauf (Soll/Ist je Platte),
  Wartung und Verbindung.
- **Material:** eine Karte je Filamentfarbe mit Bestand, Reichweite, Rollen, Bestellung unterwegs;
  Nachbestellen und Wareneingang direkt an der Karte.
- **Produkte:** eine Liste statt Artikel, Revisionen und Shop an verschiedenen Orten, mit Filtern
  „Ohne 3MF“, „Marge kritisch“, „Nicht im Shop“, „Ohne Preis“. Produktakte mit Preis, Kosten, Marge, Lager,
  Druckdaten und Soll gegen Ist; Stammdaten, 3MF und Revisionen, Stückliste, Kalkulation und Shop öffnen
  als Reiter in der Akte. Im Workflow führt „keine 3MF“ direkt zum Hochladen.
- **Bestellung:** Packseite mit Kunde und Verlauf (Eingang, Zahlung, Notizen, Versand).
- **Behoben: Lieferschein, Label, Live-Bild und 3MF-Download öffneten oft nicht.** Die Adressen enthielten
  „&#038;“ statt „&“ (wp_nonce_url liefert HTML), dadurch fehlten Prüfzahl und Nummer. Außerdem sperrte das ERP
  den Lieferschein für den Packtisch (verlangte das Produktionsrecht) und das Live-Bild für die Druckfarm (verlangte
  das Bambu-Recht).
- Neue Schnittstellen `erp/aufgaben`, `erp/drucker/<id>`, `erp/material`, `erp/produkte`, `erp/produkte/<id>`.

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
