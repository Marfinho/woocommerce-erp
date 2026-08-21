# jsQR

Fremdcode. Bitte nicht bearbeiten – bei einem Update wird die Datei ersetzt.

- **Paket:** jsqr
- **Version:** 1.4.0
- **Herkunft:** https://github.com/cozmo/jsQR (npm-Paket `jsqr`, Datei `dist/jsQR.js`)
- **Lizenz:** Apache-2.0, siehe `LICENSE`
- **Übernommen:** August 2026

## Wozu

Browser auf iPhone und iPad können QR-Codes nicht selbst lesen: Der
`BarcodeDetector` ist eine Chromium-Schnittstelle, und auf iOS ist jeder
Browser WebKit. Ohne diesen Decoder wäre das Scannen in der mobilen
Erfassung dort nicht möglich.

Geladen wird die Datei **nur dann**, wenn der Browser keinen eigenen
Decoder mitbringt – auf Android und am Rechner bleibt sie ungenutzt.

## Grenzen

jsQR liest ausschließlich **QR-Codes**. Eindimensionale Barcodes von
Lieferanten (EAN, Code 128) erkennt der eingebaute Decoder von Chromium,
dieser hier nicht. Auf dem iPhone müssen solche Codes eingetippt werden.

## Geprüft

`tests/BetriebQrCodeTest.php` prüft den eigenen Encoder. Zusätzlich wurde
vor der Übernahme geprüft, dass jsQR die vom Plugin erzeugten Codes
(Lagerplatz, Artikel, Charge, Pickliste-Adresse) in mehreren Größen
korrekt liest.
