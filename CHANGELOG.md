# Changelog

Alle nennenswerten Änderungen am Plugin. Format nach
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/); die Versionsnummer
steht in `lindenweg16.php` (`Version` und `LW16_VERSION`).

Frühere Versionen sind hier nicht nachgetragen; der Stand davor steht in der Git-Historie
und im Abschnitt „Umsetzungsstand“ der README.

## [0.17.0] – 2026-10-19

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
