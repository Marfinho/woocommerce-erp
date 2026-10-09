# model-viewer

Fremdcode. Bitte nicht bearbeiten – bei einem Update wird die Datei ersetzt.

- **Paket:** @google/model-viewer
- **Version:** 4.3.1
- **Herkunft:** https://github.com/google/model-viewer (npm-Paket `@google/model-viewer`, Datei `dist/model-viewer.min.js`)
- **Lizenz:** Apache-2.0, siehe `LICENSE` (enthält three.js unter MIT, gebündelt)
- **Prüfsumme (SHA-256):** `8fe19ff9867419e3e66d95815632ad55a16d761e2e28c3cbe22eeeb6df46646e`
- **Übernommen:** Oktober 2026
- **Einzige Änderung:** die letzte Zeile `//# sourceMappingURL=…` wurde entfernt,
  weil die Map-Datei nicht mitgeliefert wird (sonst ein 404 in den Entwicklerwerkzeugen).

## Wozu

Die AR-Ansicht (`[l16_ar_ansicht]`, Modul `Modules/ArView/`) zeigt das 3D-Modell
eines Produkts und startet AR in Originalgröße. Die Datei liegt **lokal** im
Plugin, damit beim Seitenaufruf keine IP-Adresse an ein CDN übertragen wird.
Geladen wird sie als ES-Modul, **nur** auf Seiten mit dem Shortcode.

## Wichtig: Nachladen von Hilfsdateien

Die Bibliothek kann Hilfsdateien von Dritten nachladen: Draco- und
KTX2-Dekoder (`gstatic.com`) für komprimierte Modelle und einen Lottie-Lader
(`cdn.jsdelivr.net`). Das unterbindet `assets/js/ar-viewer.js`, indem es
diese Orte auf das eigene Plugin-Verzeichnis umlenkt. Meshopt lädt die
Bibliothek von sich aus nicht, solange kein Ort gesetzt ist – er bleibt
deshalb ungesetzt (ein gesetzter, aber fehlender Ort ließe jedes Modell
scheitern).

Folge: **Modelle müssen unkomprimiert als GLB exportiert werden** (ohne
Draco, Meshopt, KTX2/Basis). Ein komprimiertes Modell zeigt den
Fehlerhinweis auf der AR-Seite, statt Daten an Dritte zu senden.

## Update

Neue Version aus npm holen, `dist/model-viewer.min.js` ersetzen, die letzte
Zeile (`sourceMappingURL`) wieder entfernen, Version und Prüfsumme hier
nachtragen und die AR-Seite auf einem iPhone und einem Android-Gerät testen.
