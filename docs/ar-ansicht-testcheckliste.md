# AR-Ansicht – Testcheckliste (Version 0.15.0)

Vor dem Livegang einmal durchgehen. Voraussetzungen: ein Produkt mit GLB-Modell
und gesetztem Haken „AR-Hinweis auf dieser Produktseite anzeigen“, eine
veröffentlichte Seite mit `[l16_ar_ansicht]`, diese Seite unter
**Verwaltung → AR-Ansicht** als Ziel-Seite gewählt.

Zum Zurücksetzen zwischen den Versuchen: im Browser den Eintrag
`l16_ar_hint_dismissed` (lokaler Speicher) und `l16_ar_hint_session`
(Sitzungsspeicher) löschen bzw. ein neues privates Fenster öffnen.

## 1. iPhone / Safari

- [ ] Produktseite öffnen: nach ca. 3 s (oder beim ersten Scrollen) fährt der Hinweis von unten ein.
- [ ] Schrift Nunito, Farbe #3A424C, abgerundete Ecken; Knöpfe und X lassen sich gut tippen.
- [ ] **Ja, zeig mal** → AR-Seite mit `?produkt=<ID>`; Modell lädt, ist drehbar.
- [ ] **An meiner Wand platzieren** → AR Quick Look öffnet; Modell lässt sich an der Wand platzieren, **nicht skalieren**.
- [ ] **Maßstab:** 1,00-m-Leiste mit Zollstock gegenprüfen.
- [ ] Zurück zur Produktseite: Hinweis erscheint in derselben Sitzung nicht mehr.
- [ ] In einem **Fremdbrowser** auf dem iPhone (Chrome/Firefox): kein Hinweis (kein AR Quick Look).
- [ ] Mit hochgeladener USDZ und ohne USDZ (dann erzeugt der Viewer sie selbst) testen.

## 2. Android / Chrome

- [ ] Gerät mit ARCore: Hinweis erscheint wie oben.
- [ ] **Ja** → AR-Seite; **An meiner Wand platzieren** startet AR (WebXR oder Scene Viewer), Modell in Originalgröße, nicht skalierbar.
- [ ] Gerät **ohne** ARCore bzw. Browser ohne WebXR (z. B. manche Samsung-Internet-Versionen): kein Hinweis auf der Produktseite.
- [ ] AR-Seite direkt geöffnet auf einem solchen Gerät: 3D-Ansicht + Hinweis „Öffne diese Seite auf deinem Smartphone“.

## 3. Rechner (Desktop)

- [ ] Produktseite: **nie** ein Hinweis (auch nicht nach Scrollen oder 10 s).
- [ ] AR-Seite mit `?produkt=<ID>`: drehbares Modell, **kein** AR-Knopf, Hinweis „Öffne diese Seite auf deinem Smartphone“ samt QR-Code in Schwarz-Weiß.
- [ ] QR-Code mit dem Handy scannen → AR-Seite öffnet sich mit demselben Produkt.
- [ ] AR-Seite ohne Parameter oder mit `?produkt=abc` / `?produkt=999999`: Auswahl aller Produkte mit Modell, Hinweistext bei ungültiger Nummer.
- [ ] Entwicklerwerkzeuge → Netzwerk: **keine** Anfragen an fremde Hosts (kein Google, kein jsDelivr, keine Google Fonts).

## 4. „Nein“-Sperre über 7 Tage (Zeitstempel manuell ändern)

Am besten im Chrome-Remote-Debugging des Handys oder in der mobilen Emulation des Desktop-Browsers (Gerätewerkzeugleiste, Android-Profil).

- [ ] **Nein, danke** tippen → Hinweis verschwindet; im lokalen Speicher steht `l16_ar_hint_dismissed` mit einer 13-stelligen Zahl.
- [ ] Seite neu laden, anderes Produkt öffnen: Hinweis erscheint **nicht**.
- [ ] Wert auf „jetzt minus 6 Tage“ setzen: `localStorage.setItem('l16_ar_hint_dismissed', Date.now() - 6*86400000)` → weiter gesperrt.
- [ ] Wert auf „jetzt minus 8 Tage“ setzen (`… - 8*86400000`) → Hinweis erscheint wieder.
- [ ] Dasselbe mit dem **Schließen-X** (und der Escape-Taste): sperrt wie „Nein“.
- [ ] Sperrdauer in den Einstellungen auf 14 Tage ändern → 8 Tage alter Zeitstempel sperrt jetzt wieder.
- [ ] Speichern blockiert (Browser-Einstellung „Websitedaten blockieren“ oder privates Fenster mit strikter Einstellung): Hinweis erscheint höchstens einmal pro Seitenaufruf, keine Fehlermeldung in der Konsole.
- [ ] Nach **Ja** ist in `sessionStorage` `l16_ar_hint_session` gesetzt, **kein** 7-Tage-Zeitstempel.
- [ ] Es werden keine Cookies gesetzt (Entwicklerwerkzeuge → Anwendung → Cookies).

## 5. Aktives Caching-Plugin

- [ ] Seiten-Cache aktiv, Produktseite zweimal als abgemeldeter Besucher am Handy laden (zweiter Aufruf aus dem Cache): Hinweis erscheint trotzdem und verhält sich gleich.
- [ ] Quelltext der gecachten Produktseite: Banner-Markup ist vorhanden und trägt `hidden`; keine Nonce im HTML.
- [ ] Zeitstempel löschen (siehe Abschnitt 4): Der Hinweis erscheint wieder, ohne den Cache zu leeren – die Entscheidung fällt im Browser.
- [ ] Zähler: Nach einigen Versuchen unter **Verwaltung → AR-Ansicht → Auswertung** steigen „gezeigt“, „Ja“, „Nein“ (die Nonce wird frisch geholt, auch bei tagealter Cache-Seite).
- [ ] AR-Seite: `?produkt=A` und `?produkt=B` liefern **unterschiedliche** Modelle (Cache unterscheidet Parameter), sonst die Seite vom Cache ausnehmen.
- [ ] Skript-Minimierung/-Zusammenfassung des Cache-Plugins: `ar-viewer.js` bleibt ein ES-Modul (`type="module"`); falls das Plugin es verändert, die Datei von der Optimierung ausnehmen.

## 6. Produkt ohne Modell (und weitere Randfälle)

- [ ] Produkt **ohne** GLB: Produktseite zeigt keinen Hinweis, im Quelltext kein `data-lw16-ar-hint`.
- [ ] Produkt mit GLB, aber **ohne** Haken „AR-Hinweis anzeigen“: kein Hinweis; Produkt steht aber in der Auswahl der AR-Seite.
- [ ] `?produkt=<ID eines Produkts ohne Modell>`: Auswahl statt Viewer.
- [ ] GLB aus der Mediathek löschen: Hinweis verschwindet von der Produktseite.
- [ ] Funktion unter **Verwaltung → AR-Ansicht** ausschalten: Hinweis weg, AR-Seite meldet „derzeit nicht verfügbar“.
- [ ] Ziel-Seite leer/unveröffentlicht: kein Hinweis, die Einstellungsseite zeigt eine Warnung.
- [ ] Upload-Rechte: Als Redakteur lässt sich keine `.glb`/`.usdz` hochladen; als Administrator schon; eine in `.glb` umbenannte Textdatei wird abgewiesen.
- [ ] Komprimierte GLB (Draco): AR-Seite zeigt den Fehlerhinweis, im Netzwerk-Tab **kein** Aufruf von gstatic.com.
