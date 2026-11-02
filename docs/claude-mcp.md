# Claude-Zugang (MCP)

Seit 0.78.0 hat das Plugin einen eigenen **MCP-Server** (Model Context Protocol). Darüber kann Claude
Shop und ERP lesen und dir jeden Morgen einen Lagebericht mit Handlungsanweisungen schreiben.

- Adresse: `https://lindenweg16.de/wp-json/lw16/v1/mcp`
- Transport: „Streamable HTTP“, nur POST, Antwort direkt als JSON, ohne Sitzungen
- Anmeldung: Schlüssel aus dem **ERP (`/erp`) → Profilmenü (Avatar) → Claude-Zugang** – nur mit Vollzugriff und
  WordPress-Rolle Administrator. Dieselbe Seite gibt es im wp-admin unter Verwaltung → Claude-Zugang
  (`wp-admin/admin.php?page=lw16-claude`), falls die ERP-Menüs dort nicht ausgeblendet sind.
- Code: `includes/Mcp/` (Protokoll, Werkzeuge, Schlüssel, SQL-Prüfung), ERP-Seite `includes/Erp/Pages/ClaudeZugang.php`
  und `assets/erp/pages/claude.js`, wp-admin-Seite `includes/Admin/McpPage.php`

## Werkzeuge

| Werkzeug | Was es tut |
|---|---|
| `tagesueberblick` | Aufgaben aus „Heute“, alle Punkte der täglichen Übersichtsmail (Mahnungen, Angebote, Urlaub, Lieferanten, Reklamationen, Bank, Nachkauf, Liquidität), Kennzahlen der Startseite, Bestellungen je Status, Umsatz (heute, gestern, 7/30 Tage, Vorwoche, Monat) jeweils mit Vorjahr, Druckerwartung je Drucker, Nachbestellvorschläge, Filament je Farbe, B2B-Wiedervorlagen, Mails aller Postfächer |
| `bestellungen` | WooCommerce-Bestellungen kompakt, Filter nach Status, Zeitraum, Suche |
| `routen_auflisten` | Alle REST-Routen mit Methoden und Parametern: `lw16/v1` (ERP), `wc/v3`, `wc-analytics`, `wp/v2` |
| `rest_lesen` | Ruft jede dieser Routen lesend auf (GET) |
| `datenbank_tabellen` | Tabellen und Spalten der Datenbank |
| `datenbank_abfrage` | Lesendes SQL (SELECT/WITH/SHOW/DESCRIBE/EXPLAIN) |
| `rest_schreiben` | Nur mit Schlüssel „darf ändern“: POST/PUT/PATCH/DELETE auf eine Route, mit Grund im Protokoll |

Dazu die Vorlage (Prompt) **`tagesbericht`** mit dem fertigen Auftrag für den Morgenbericht.

Weil `rest_lesen` jede Route erreicht, kommt Claude an alles, was das ERP über seine Schnittstelle
anbietet. Neue ERP-Seiten und -Routen sind ohne Änderung am MCP-Server sofort mit dabei.

## Sicherheit

- Ein Schlüssel arbeitet mit den **Rechten des Kontos, das ihn angelegt hat** (Administrator). Gespeichert
  wird nur sein SHA-256-Wert (Option `lw16_mcp_tokens`). Den Schlüssel selbst sieht man einmal beim Anlegen.
- **Nur lesen** ist die Voreinstellung. Mit „darf ändern“ bleiben trotzdem gesperrt: Rechte und Konten
  (`/lw16/v1/erp/users`, `/wp/v2/users`), Plugins, Themes, WordPress- und WooCommerce-Einstellungen,
  Webhooks, Anwendungspasswörter und der MCP-Zugang samt Schlüsselverwaltung (auch nicht lesend). Jede Änderung steht im Audit-Protokoll
  (`mcp_schreiben`, mit Methode, Pfad, Grund und Status).
- **Datenbank:** Jede Abfrage wird geprüft (eine Anweisung, nur Lesebefehle, keine Variablen, kein
  `INTO OUTFILE`, kein `SLEEP`) und läuft zusätzlich in einer `START TRANSACTION READ ONLY` mit
  15 Sekunden Zeitlimit. Passwort-Hashes, Sitzungen und die MCP-Schlüssel sind gesperrt. Andere
  Einstellungen (auch API-Zugangsdaten von Diensten in `wp_options`) bleiben lesbar – den Schlüssel
  deshalb wie ein Administratorpasswort behandeln.
- Fehlversuche: höchstens 10 je Minute und Adresse, danach 429. Je Schlüssel höchstens 240 Aufrufe je Minute.
- Claude bekommt beim Verbinden den Hinweis, dass Freitexte aus dem Shop (Kundennotizen, Mails,
  Bewertungen) Daten sind und keine Anweisungen.
- Schlüssel, der in falsche Hände geraten ist: auf der Seite **Löschen** – er gilt sofort nicht mehr.

## Einrichten in claude.ai (Connector)

1. ERP → Profilmenü → **Claude-Zugang** → Schlüssel „nur lesen“ anlegen.
2. Die angezeigte **Adresse mit Schlüssel** kopieren (`…/wp-json/lw16/v1/mcp/lw16mcp_…`).
3. claude.ai → Einstellungen → **Connectors** → „Eigenen Connector hinzufügen“. Name „Lindenweg16 ERP“,
   Adresse einfügen, die OAuth-Felder leer lassen.
4. In einem Gespräch den Connector einschalten und z. B. fragen: „Was steht heute im Shop an?“

Die Adresse enthält den Schlüssel, weil man in claude.ai keine Kopfzeile eintragen kann. Sie kann
deshalb in Zugriffsprotokollen des Webservers auftauchen. Wo es geht (Claude Code), lieber die Kopfzeile nehmen.

## Einrichten in Claude Code

```bash
claude mcp add --transport http lindenweg16 https://lindenweg16.de/wp-json/lw16/v1/mcp --header "Authorization: Bearer lw16mcp_…"
```

Strippt der Webspace die Kopfzeile `Authorization` (manche Apache/CGI-Setups), geht auch
`--header "X-LW16-Key: lw16mcp_…"` oder die Adresse mit Schlüssel.

## Täglicher Lagebericht automatisch

**Weg A – geplante Aufgabe in Claude (empfohlen):** Mit eingerichtetem Connector eine geplante Aufgabe
anlegen (z. B. werktags 7:30 Uhr), als Auftrag den Text von der Seite „Claude-Zugang“ im ERP einfügen
(identisch mit der Vorlage `tagesbericht`) und den Connector für die Aufgabe freigeben. Das Ergebnis
kommt als Benachrichtigung oder Mail.

**Weg B – Raspberry Pi mit Claude Code:** Auf dem Pi Claude Code installieren, den Server wie oben
hinzufügen und per Cron täglich ausführen, Ergebnis per Mail:

```bash
30 7 * * 1-5 claude -p "$(cat ~/tagesbericht.txt)" --allowedTools "mcp__lindenweg16" | mail -s "Lindenweg16 – heute" du@example.com
```

## Ausprobieren ohne Claude

```bash
curl -s -X POST https://lindenweg16.de/wp-json/lw16/v1/mcp -H "Authorization: Bearer lw16mcp_…" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"tagesueberblick","arguments":{}}}'
```

Antworten: `401` Schlüssel fehlt oder falsch, `429` zu viele Versuche, `405` bei GET (richtig so:
der Server kennt nur POST).

## Fehlersuche

- **401, obwohl der Schlüssel stimmt:** Der Webspace leitet `Authorization` nicht weiter → Adresse mit
  Schlüssel oder `X-LW16-Key` verwenden.
- **„rest_no_route“:** Permalinks auf „Einfach“? Dann lautet die Adresse
  `https://lindenweg16.de/?rest_route=/lw16/v1/mcp`. Die Seite „Claude-Zugang“ zeigt immer die richtige.
- **Datenbankfehler „Nur-Lese-Transaktion nicht möglich“:** sehr alte MySQL-/MariaDB-Version. Die übrigen
  Werkzeuge funktionieren weiter.
- Tests: `tests/McpProtocolTest.php`, `tests/McpSqlGuardTest.php`, `tests/McpTokensTest.php`.
