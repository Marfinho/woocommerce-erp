# Changelog

Alle nennenswerten Änderungen am Plugin. Format nach
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/); die Versionsnummer
steht in `lindenweg16.php` (`Version` und `LW16_VERSION`).

Frühere Versionen sind hier nicht nachgetragen; der Stand davor steht in der Git-Historie
und im Abschnitt „Umsetzungsstand“ der README.

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
