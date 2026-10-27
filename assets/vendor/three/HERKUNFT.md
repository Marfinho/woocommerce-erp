# three.js (Ausschnitt)

Fremdcode, nicht bearbeiten.

- **Paket:** three, Version r169 (`three.module.min.js` und die Dateien unter `jsm/`)
- **Lizenz:** MIT, siehe `LICENSE`
- **Nachträglich ergänzt (unverändert aus dem npm-Paket `three@0.169.0`, `examples/jsm/`):**
  - `jsm/exporters/GLTFExporter.js` und `jsm/utils/TextureUtils.js` – der Konfigurator schreibt damit
    die aktuelle Konfiguration als .glb, die in der AR-Ansicht an die Wand gestellt wird.

Die Dateien werden nur auf Seiten mit dem Konfigurator geladen, die Exportdatei erst beim Antippen
von „An meiner Wand ansehen“.
