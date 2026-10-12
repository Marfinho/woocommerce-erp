# Schriften

Alle Schriften liegen lokal im Theme und werden über `fontFace` in
`theme.json` eingebunden. Es gibt keine Anfragen an Google Fonts.

| Datei | Schrift | Schnitte | Verwendung |
|---|---|---|---|
| `bricolage-grotesque-latin-wght-normal.woff2` | Bricolage Grotesque (variabel) | 200–800 | Überschriften |
| `inter-latin-wght-normal.woff2` | Inter (variabel) | 100–900 | Fließtext, Bedienelemente |
| `dm-mono-latin-400-normal.woff2` | DM Mono | 400 | Preise, Mengen, Labels |
| `dm-mono-latin-500-normal.woff2` | DM Mono | 500 | Preise, Mengen, Labels |

Zeichenumfang: Subset „latin“ (Basis-Latein und Latin-1 mit ä, ö, ü, ß,
„ “, €, Gedanken- und Geviertstrich).

## Quelle

Die Schriften stammen aus Google Fonts. Die woff2-Dateien sind die
unveränderten Subsets aus den Fontsource-Paketen (npm), die die Dateien
von Google Fonts direkt übernehmen:

- `@fontsource-variable/bricolage-grotesque` 5.3.0 – https://fonts.google.com/specimen/Bricolage+Grotesque
- `@fontsource-variable/inter` 5.3.0 – https://fonts.google.com/specimen/Inter
- `@fontsource/dm-mono` 5.3.0 – https://fonts.google.com/specimen/DM+Mono

## Lizenz

Alle drei Schriften stehen unter der SIL Open Font License 1.1 (OFL).
Die Lizenztexte liegen daneben:

- `OFL-Bricolage-Grotesque.txt`
- `OFL-Inter.txt`
- `OFL-DM-Mono.txt`

Die OFL erlaubt Nutzung, Einbettung und Weitergabe mit dem Theme, auch
kommerziell.


## Zugeschnitten (Ladezeit)

Inter auf die Gewichte 400–700 und Bricolage Grotesque auf 600–800 begrenzt
(mehr nutzt das Theme nicht) und auf westeuropäische Zeichen reduziert
(Latin-1 + Latin Extended-A, €, typografische Anführungszeichen, Pfeile).
Zusammen 55 statt 87 KB. Erzeugt mit fontTools (instancer + subset).
