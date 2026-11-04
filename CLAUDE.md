# Hinweise für Claude

- **Theme-ZIP immer mit neu bauen:** Jede Änderung unter `block-theme/lindenweg16/` wird mit
  `bash block-theme/build-zip.sh` neu in `block-theme/dist/lindenweg16.zip` gebaut. Das ZIP
  gehört im selben Commit/PR mit ins Repo.
- **Version immer mit anheben (Auto-Update):** Jede Änderung am Plugin hebt die Version in
  `lindenweg16.php` an (`Version` im Kopf und `LW16_VERSION`) und bekommt einen passenden
  Abschnitt in `CHANGELOG.md`. Vorher `git pull`, damit die Nummer nicht doppelt vergeben wird.
- **Betriebsanleitung mitpflegen:** Bringt eine Änderung etwas Neues für Anwender (`### Neu` im
  `CHANGELOG.md`), gehört das Kapitel dazu in `docs/betriebsanleitung.html`. Jedes Kapitel beginnt
  mit einem Schaubild aus `docs/schaubilder.py`. Danach den Stand im `<meta name="anleitung-stand">`
  anheben und `python3 docs/anleitung.py sync` ausführen; `python3 docs/anleitung.py check` muss
  grün sein (das CI prüft es). Verweise wie „Kapitel D.15“ nie von Hand umnummerieren: neue Kapitel
  einfügen, `sync` laufen lassen, `check` meldet kaputte Verweise.
