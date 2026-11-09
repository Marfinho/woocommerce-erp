# -*- coding: utf-8 -*-
"""Schaubilder der Betriebsanleitung.

Jedes Kapitel von docs/betriebsanleitung.html beginnt mit einem Schaubild: Was
passiert, in Kürze. Darunter erklärt der Text, wie es im Einzelnen funktioniert.
Die Schaubilder stehen nicht von Hand im HTML, sondern hier; `python3
docs/anleitung.py sync` schreibt sie zwischen die Marker
`<!-- schaubild:KAPITEL -->` … `<!-- /schaubild:KAPITEL -->`, und das CI prüft,
dass beides übereinstimmt.

Schreibweise eines Schritts: du(titel, text) | erp(…) | ext(…) | warn(…) | ok(…)
    du    – das tust du (Farbe Gold)
    erp   – das macht das ERP von selbst (Grün)
    ext   – passiert außerhalb: Drucker, Bank, Post, Lieferant (Blau)
    warn  – Ausnahme, Pflicht, Vorsicht (Rot)
    ok    – Ergebnis (Grau)
Ein dritter Wert ist optional: eine Liste kurzer Unterpunkte.

Schlüssel: die Kennung des Kapitels (`prod-workflow`) oder `Kennung|Text der
Zwischenüberschrift` für ein Schaubild unter einem h3.
"""

from html import escape


def _s(kind):
    def f(titel, text='', alt=None):
        return (kind, titel, text, alt or [])
    return f


du, erp, ext, warn, ok = _s('du'), _s('erp'), _s('ext'), _s('warn'), _s('ok')

LEGENDE = {
    'du': 'Das tust du',
    'erp': 'Das macht das ERP',
    'ext': 'Außerhalb (Drucker, Bank, Post, Lieferant)',
    'warn': 'Pflicht oder Ausnahme',
    'ok': 'Ergebnis',
}

S = {}


def sb(key, titel, *schritte, hinweis=''):
    S[key] = {'titel': titel, 'schritte': list(schritte), 'hinweis': hinweis}


def svg(key, titel, markup, hinweis=''):
    S[key] = {'titel': titel, 'svg': markup, 'hinweis': hinweis}


# ---------------------------------------------------------------- Einstieg
sb('wer', 'Welche Anleitung ist meine?',
   du('Deine Aufgabe', 'Was machst du den ganzen Tag?'),
   erp('Dein Rechteprofil', 'bestimmt, was du im ERP siehst', ['Werkstatt / Fertigung', 'Packtisch', 'Betriebsleitung', 'Vollzugriff']),
   du('Den Teil lesen', 'die anderen musst du nicht kennen', ['A Produktion: drucken, montieren', 'B Versand: picken, packen', 'C Personal: Zeit, Rechte, Löhne', 'D Administration: einrichten, Geld']),
   ok('Du findest dich zurecht', 'jeder Teil beginnt mit dem Weg dorthin'))

sb('oberflaeche', 'Der Weg zu jeder Seite',
   du('Anmelden', 'Adresse der Website + /erp'),
   erp('Sechs Plätze links', 'Heute · Produktion · Verkauf · Lager · Katalog · Zahlen', ['sichtbar je nach Rechten']),
   du('Reiter wählen', 'oben auf der Seite'),
   du('Runden Knopf wählen', 'darunter, falls es mehrere Seiten gibt'),
   ok('Seite offen', 'Weg in der Anleitung: Platz → Reiter → Auswahl'),
   hinweis='Abkürzung: Strg+K (Mac ⌘+K) öffnet die Seitensuche.')

sb('grundregeln', 'Fünf Sätze, die für alle gelten',
   erp('Nichts wird gelöscht', 'Fehler werden storniert, beides bleibt stehen'),
   erp('Bestand = Summe aller Bewegungen', 'es gibt kein Feld zum Verstellen'),
   erp('Berechnetes wird nie gespeichert', 'jede Zahl entsteht bei jedem Aufruf neu'),
   erp('Jede Eingabe wird geprüft', 'die Meldung sagt in ganzen Sätzen, was fehlt'),
   du('Im Zweifel erfassen', 'eine Buchung mit Bemerkung schlägt keine Buchung'))

# ---------------------------------------------------------------- Teil A
sb('prod-anmelden', 'Anmelden und ankommen',
   du('Adresse öffnen', 'lindenweg16.de/erp'),
   ext('WordPress-Anmeldung', 'Benutzername und Passwort'),
   du('„Angemeldet bleiben“?', 'nur am eigenen Rechner', ['ohne Haken: trotzdem mindestens 14 Tage']),
   erp('Landet auf „Heute“', 'deine Plätze links, je nach Rechteprofil'),
   ok('Werkstatt: Produktion und Lager', 'die zwei Plätze, die du am meisten brauchst'))

sb('prod-heute', 'Was „Heute“ für dich zusammenträgt',
   erp('Liest alle Seiten', 'Platten, Bestellungen, Wartung, Nachbestellung, Rechnungen, Mails'),
   erp('„Dein Nächstes“', 'die wichtigste Aufgabe, mit großem Knopf'),
   erp('Hinweiskarten', 'nur wenn etwas offen ist, farbiger Streifen + Knopf'),
   du('Knopf drücken', 'führt direkt zur richtigen Seite'),
   ok('Erledigt = Karte weg', 'steht dort nichts, ist nichts zu tun'))

sb('prod-workflow', 'Vom Bedarf zur Platte zum Bestand',
   erp('Bedarf', 'aus Bestellungen, Sollbestand, Stückliste', ['abzüglich Lager und laufender Arbeit']),
   du('Plan freigeben', 'Artikel, Menge, Drucker wählen', ['Platten berechnen', 'Etiketten + Slicen anhaken']),
   erp('Freigegeben', 'Platten stehen bereit, Etiketten gedruckt, Datei geslict'),
   du('Rüsten ▶', 'Material einlegen, Platte frei bestätigen'),
   ext('Im Druck', 'Drucker meldet Fortschritt und Zeit'),
   du('Ist melden', 'Gut, Ausschuss, Rolle, Lagerplatz'),
   ok('Eingelagert', 'Bestand ↑, Charge, Filament ↓, Ausschuss → Bedarf'))

sb('prod-workflow|Schritt 2: Rüsten, drucken, melden', 'Der Weg einer Platte – und wo Zeit gemessen wird',
   du('Rüsten starten ▶', 'Rüstzeit läuft', ['Pause / Weiter möglich', 'Pause zählt nicht']),
   ext('Druck läuft', 'Start und Ende kommen vom Drucker'),
   warn('Warten', 'freigegeben → Rüsten und Druck fertig → gemeldet: wird getrennt sichtbar'),
   du('Druck fertig ▶', 'oder direkt Ist-Menge melden'),
   ok('Drei Zeiten je Platte', 'Rüsten (Handarbeit) · Druck (Maschine) · Warten'))

sb('prod-workflow|Schritt 3: Die Menge melden', 'Was beim „Melden und einlagern“ passiert',
   du('Zählen', 'Gut und Ausschuss'),
   warn('Ausschuss?', 'dann ist eine Fehlerursache Pflicht'),
   du('Rolle + Platz prüfen', 'Spule scannen, Lagerplatz wählen'),
   erp('Bucht in einem Rutsch', 'alles zusammen oder gar nichts', ['Lagerzugang der guten Teile', 'Charge für die Rückverfolgung', 'Filament von der Rolle ab', 'Kleinteile verbraucht', 'Zeiten → Soll gegen Ist', 'Ausschuss → zurück in den Bedarf']),
   ok('Erledigt', 'Bestätigung zeigt, was gebucht wurde'))

sb('prod-bedarf', 'Woraus „Fehlt“ entsteht',
   erp('Bedarf', 'offene Bestellungen + Sollbestand + Stückliste', ['optional: Wunschliste']),
   erp('minus Bestand', 'was im Regal liegt'),
   erp('minus in Arbeit', 'Platten und Aufträge unterwegs'),
   ok('Fehlt', 'die Zahl, die zählt'),
   du('Arbeite von oben nach unten', 'drucken → Workflow · montieren → Auftrag · bestellen → Einkauf'))

sb('prod-auftrag', 'Ein Fertigungsauftrag von Anfang bis Ende',
   du('Neuer Auftrag', 'Art, Artikel, Stück, Farbe'),
   erp('Materialliste', 'sofort gerechnet, noch nichts gebucht'),
   du('Starten', 'offen → läuft', ['Uhr läuft', 'zählt als „in Arbeit“']),
   du('Pausieren', 'wenn etwas dazwischenkommt, Zeit summiert sich'),
   du('Fertigmelden', 'tatsächliche Menge eintragen'),
   ok('Gebucht', 'Material ab, Sets zu, Zeit am Auftrag'))

sb('prod-drucken', 'Einen Druck von Hand nachtragen',
   du('Formular füllen', 'Drucker, Artikel, Farbe, Charge, Teile, Zeit'),
   warn('Ausschuss?', 'weniger gut als geplant → Fehlerursache Pflicht'),
   erp('Prüft und bucht', 'alles in einem Schritt', ['Lagerzugang', 'Charge', 'Filament', 'Kleinteile', 'Betriebsstunden', 'Ausschussquote']),
   du('Falsch gebucht?', 'Stornieren mit Begründung – es gibt keinen Löschknopf'),
   ok('Gegenbuchung', 'der Job bleibt sichtbar, der Bestand stimmt wieder'))

sb('prod-handy', 'Am Drucker mit dem Handy buchen',
   du('QR-Aufkleber scannen', 'oder /betrieb-erfassen/'),
   erp('Drucker und Artikel vorbelegt', 'meistens stimmt es schon'),
   du('− / + für Teile in Ordnung', 'bei Ausschuss: Ursache'),
   du('Großer Knopf „Buchen“', ''),
   ext('Kein Netz?', 'Buchung wartet im Browser und wird später gesendet'),
   ok('Server bestätigt', 'erst dann verschwindet sie aus der Warteschlange'))

sb('prod-montage', 'Ein Set montieren und fertigmelden',
   du('Auftrag suchen', 'unter Material steht, was du brauchst'),
   du('Material holen', 'Lagerplätze stehen auf den Etiketten'),
   du('Starten', 'die Uhr läuft mit'),
   du('Zusammenbauen', ''),
   warn('Echte Menge eintragen', 'nicht die geplante – sonst entsteht Bestand, den es nicht gibt'),
   erp('Fertigmelden bucht', 'Material ab · Sets zu · Zeit auf den Auftrag'))

sb('prod-material', 'Filament nachbestellen und einlagern',
   erp('Ampel auf der Karte', 'reicht · bald nachbestellen · jetzt nachbestellen'),
   du('Nachbestellen', 'Menge, Lieferant, Preis, Datum'),
   ext('Lieferung kommt', 'Rolle mit Chargencode beschriften'),
   du('Eingegangen ✓', 'Menge, Material, Chargencode'),
   erp('Rolle wird Charge', 'erst jetzt steigt der Bestand'),
   ok('Aktive Rolle zuerst', 'ihr Verbrauch wird zuerst abgezogen'))

sb('prod-drucker', 'Eine Wartung erfassen',
   erp('„Wartung fällig“', 'Kasten oben nennt je Gerät, was ansteht'),
   du('Wartung erfassen', 'Gerät und Intervall wählen'),
   erp('Checkliste', 'längere Intervalle enthalten die kürzeren'),
   warn('Alles abhaken', 'bei wöchentlich, monatlich, jährlich Pflicht – sie ist der Nachweis'),
   du('Ersatzteil, Düse gewechselt?', 'Haken setzen, Notiz'),
   ok('Gespeichert', 'Zählerstand und Fälligkeit aktualisiert'))

sb('prod-bambu', 'Von der Platte bis zum laufenden Druck',
   du('Platte freigeben', 'Workflow, „Automatisch slicen“'),
   erp('Slicing-Worker slict', 'mit den Profilen für Material + Druckermodell'),
   erp('Warteschlange', 'wartet, bis die Platte am Drucker frei ist'),
   ext('Bridge auf dem Pi', 'holt ab, überträgt die Datei, startet'),
   ext('Bambu-Drucker druckt', 'Fortschritt, Temperaturen, AMS, Kamera'),
   erp('Zurück ins ERP', 'Karte zeigt live Zustand, Zeiten kommen vom Drucker'),
   ok('Ist melden', 'du zählst, das ERP bucht'))

sb('prod-artikel', 'Ein Produkt bis zur Verkaufsreife',
   du('Neues Produkt', 'Beschaffungsweg entscheidet alles Weitere', ['druck → Revision', 'montage → Stückliste', 'zukauf → Beschaffung']),
   du('Revision anlegen', 'Version, Druckeinstellungen, Teile + Zeit + Gewicht'),
   du('3MF anhängen', 'Projektdatei mit Modell'),
   ext('Probedruck', 'erst wenn er gut ist …'),
   du('Aktiv setzen', 'löst die bisherige Revision ab'),
   du('Stückliste', 'Position + Menge je Stück'),
   ok('Kalkulation und Shop', 'ab hier rechnet das ERP den Preis'))

sb('prod-artikel|Etikett drucken', 'Vom Shop-Produkt zum Etikett',
   du('Produkt öffnen', 'Katalog → Produkte → Übersicht, Karte „Etikett“'),
   erp('Etikett zusammenstellen', 'Produktname, Foto aus dem Shop, Artikel-Nr.'),
   du('Wählen', 'Als PDF öffnen oder Etikett drucken (Anzahl)'),
   ext('Etikettendrucker', 'druckt auf die eingestellte Rolle'),
   ok('Etikett am Modul', 'Name, Bild und Artikel-Nr. auf einen Blick'))

sb('prod-lager', 'Der Bestand ist eine Summe',
   erp('Alle Bewegungen', 'Zugang, Abgang, Korrektur, Umlagerung, Kommission'),
   ok('Bestand', 'die Summe – kein Feld, das man verstellt'),
   du('Neue Buchung', 'Vorgang, Grund, Platz, Menge'),
   warn('Korrektur', 'Differenz mit Vorzeichen (−2) + Begründung Pflicht'),
   erp('Zeile im Protokoll', 'mit Nummer und Person'),
   du('Falsch? Storno', 'Gegenbuchung statt Löschen'))

sb('prod-inventur', 'Eine Inventur von der Zählliste bis zur Korrektur',
   du('Inventur anlegen', 'Stichtag'),
   erp('Soll festhalten', 'Bestand zum Stichtag'),
   du('Zählen', 'Handy, Scanner oder PDF-Liste', ['Blindzählung ohne Soll', '„= Soll“ per Tipp']),
   du('Abschließen', 'eigenes Recht'),
   erp('Differenz buchen', 'gegen den Bestand zum Zeitpunkt des Zählens', ['Herkunft „Inventur“', 'Verkäufe währenddessen bleiben richtig']),
   ok('Protokoll als CSV', 'dazu Bestandswert zum Stichtag'))

sb('prod-einkauf', 'Vom Vorschlag zur Lieferung',
   erp('Vorschlag „Bestellen“', 'Bestand, Reichweite, Meldegrenze, Menge, Link'),
   du('Bestellt ✓', 'auch ohne Meldebestand möglich'),
   erp('Bestellschein BE-JJJJ-0001', 'offene Einkäufe je Lieferant gebündelt'),
   ext('PDF per Mail an den Lieferanten', 'Bestätigung mit Liefertermin zurück'),
   du('Liefertermin eintragen', 'wird zur „erwarteten Lieferung“'),
   ext('Ware kommt', ''),
   erp('Wareneingang bucht zu', 'erst jetzt ändert sich der Bestand'))

sb('prod-qualitaet', 'Rückverfolgung von der Rolle zum Kunden',
   ext('Rolle kommt ins Haus', 'eindeutiger Chargencode'),
   erp('Bei jedem Druck', 'entsteht eine Charge: Artikel, Revision, Material, Drucker'),
   du('Chargenakte öffnen', 'GPSR-Nachweis als PDF'),
   warn('Material war schlecht?', ''),
   erp('Rückverfolgung', 'alle Teile derselben Rolle und alle betroffenen Bestellungen'),
   ok('Du weißt, wen du anschreiben musst', ''))

sb('prod-kapazitaet', 'Schaffe ich das?',
   du('Erwartete Bestellungen eintragen', 'je Produkt, für eine Woche'),
   erp('Stücklisten auflösen', ''),
   erp('Druckerstunden + Handstunden', 'gegen Geräte in Betrieb und Nettostunden'),
   ok('Passt in die Woche – oder nicht', 'vor Weihnachten oder einem Markt rechnen'))

sb('prod-kapazitaet|Soll gegen Ist', 'Plan gegen Wirklichkeit',
   erp('Platten gemeldet', 'letzte 90 Tage'),
   erp('Druckzeit je Teil', 'Median aus reinen Platten, gemischte nur als „geschätzt“'),
   erp('Rüsten gegen Rüst-Soll', 'je Drucker, Abweichung'),
   warn('Datenqualität', 'fehlgeschlagene Drucke und Platten ohne Zeit zählen nicht – werden aber gezählt'),
   du('Planzeit übernehmen', 'neue Planzeit in die Revision (Recht: Stammdaten ändern)'))

sb('prod-tagesplan', 'Wie der Plan für die Nacht entsteht',
   erp('Freigegebene Platten', 'aus dem Workflow'),
   ext('Live-Zustand der Drucker', 'frei, belegt, offline, AMS-Belegung'),
   erp('Je freier Drucker eine Platte', 'füllt das Nachtfenster, Material im AMS, dringend, Rolle reicht', ['sonst „umrüsten“']),
   warn('Nicht in der Nacht', 'Drucker, die noch drucken oder offline sind; kurze Platten'),
   ext('Push eine Stunde vor Feierabend', 'Meldungsart „Nachtschicht“'),
   du('Rüsten und freigeben', 'der Plan startet nichts von selbst'))

sb('prod-fehler', 'Wenn etwas nicht geht',
   du('Meldung zu Ende lesen', 'es sind ganze Sätze – meist steht die Lösung darin'),
   du('Unten in der Tabelle suchen', 'Meldung oder Beobachtung'),
   erp('Spalte „Was wirklich los ist“', ''),
   du('Spalte „Was du tust“', ''),
   warn('Nichts hilft?', 'Betriebsleitung oder Vollzugriff fragen – mit dem genauen Wortlaut'))

sb('prod-spickzettel', 'Ein Tag in der Werkstatt',
   du('Morgens', 'Heute ansehen · Bedarf prüfen · Plan freigeben · Uhr starten'),
   du('Erste Platte rüsten', 'Etiketten drucken'),
   du('Tagsüber', 'jede Platte sofort rüsten, starten, melden · jedes Set fertigmelden'),
   warn('Kaputt?', 'als Ausschuss mit Ursache buchen, nicht in den Müll'),
   du('Abends', 'Uhr stoppen · Aufträge pausieren · Wochenwartung'))

# ---------------------------------------------------------------- Teil B
sb('vers-ablauf', 'Ein Paket in fünf Schritten',
   du('Pickliste erzeugen', 'sortiert nach Lagerplatz'),
   du('Picken', 'Handy, ein Gang durchs Regal', ['Bestand wird gebucht']),
   du('Packen', 'Ware auf Bestellungen verteilen'),
   du('Lieferschein', 'PDF ohne Preise ins Paket'),
   du('Label + Versendet ✓', 'DHL-Label, Lagerabgang, Rechnung'),
   ok('Paket in der Abholkiste', ''),
   hinweis='Nach Lagerplatz sortiert: einmal durchs Regal statt fünfmal.')

sb('vers-anmelden', 'Anmelden und den Weg finden',
   du('Adresse + /erp', ''),
   ext('Anmeldung', 'Benutzername und Passwort'),
   erp('Plätze links', 'Verkauf: Versand, Aufträge, Picklisten · Lager: Bestand, Plätze'),
   ok('Weg lesen', 'Verkauf → Bestellungen → Versand = Platz → Reiter → Auswahl'))

sb('vers-pickliste', 'Pickliste erzeugen',
   erp('Zählt offene Bestellungen', 'und Positionen'),
   du('Pickliste erzeugen', ''),
   erp('Fasst zusammen', 'gleiche Artikel in gleicher Farbe'),
   erp('Sortiert nach Platz', 'deine Laufreihenfolge, Code PL…'),
   warn('Nicht zweimal hintereinander', 'sonst pickst du alles doppelt'))

sb('vers-picken', 'Picken: Handy oder Computer',
   du('Liste öffnen', 'Handy: Reiter „Picken“'),
   du('Zum Platz gehen', 'Menge und Farbe nehmen'),
   du('Haken oder Position scannen', 'scannen ist sicherer'),
   erp('Zeile erledigt', 'Lagerabgang gebucht'),
   warn('Ware nicht da?', 'mitnehmen, was da ist, sofort melden und korrigieren'),
   ok('„0 offen“', ''))

sb('vers-packen', 'Packen',
   du('Karton wählen', ''),
   du('Ware einer Bestellnummer', 'gegen Packliste prüfen: Menge, Farbe'),
   du('Beileger, Lieferschein', ''),
   du('Füllen und zukleben', ''),
   erp('Karton + Füllstoff', 'werden je Bestellung automatisch abgebucht'))

sb('vers-lieferschein', 'Lieferschein',
   du('Karte der Bestellung', 'Verkauf → Bestellungen → Versand'),
   du('„Lieferschein“', ''),
   erp('PDF', 'Lieferadresse + Artikelliste, keine Preise'),
   du('Drucken, ins Paket', 'Strg+P'),
   ok('Drei Zettel, drei Zwecke', 'Pickliste: Lager · Lieferschein: Kiste · Label: außen'))

sb('vers-label', 'Label erzeugen und „Versendet“',
   erp('DHL eingerichtet?', 'ja: Formular · nein: nur „Versendet“'),
   du('Produkt, Gewicht prüfen', 'Inland Paket, Europaket, Weltpaket'),
   du('Label erzeugen', 'ab jetzt ist das Paket frankiert'),
   ext('DHL', 'Sendungsnummer geht in die Sendungsverfolgung des Kunden'),
   du('Aufkleben, Paket weg', ''),
   du('Versendet ✓', 'Rückfrage bestätigen'),
   erp('Schließt ab', 'Bestellung fertig · Lagerabgang · Rechnung'),
   hinweis='Falsches Label? Solange nicht abgeholt: Stornieren.')

sb('vers-bestellungen', 'Zwei Listen, zwei Zwecke',
   ext('Shop-Bestellung', 'Kunde bestellt im Shop'),
   erp('Liste „Shop-Bestellungen“', 'Ansehen · Versand · Akte'),
   du('Aufträge', 'B2B, Telefon, Entwürfe'),
   erp('Schild je Auftrag', 'entwurf · bestätigt · storniert · Rechnung offen/überfällig'),
   ok('Überfällig → „Heute“', ''))

sb('vers-manuell', 'Verkauf ohne Shop',
   du('Wo wurde verkauft?', ''),
   du('Markt', 'Kasse: Kassatag starten, Verkäufe buchen'),
   du('Telefon, Abholung', 'manueller Auftrag mit Kontakt und Kanal'),
   erp('Rabatt aus der Staffel zum Bestelldatum', 'wird an der Bestellung festgeschrieben'),
   warn('Bestand geht ab', 'nur erfassen, was wirklich verkauft wurde'))

sb('vers-nachbestellung', 'Eine Händler-Nachbestellung bestätigen',
   ext('Händler scannt seine Karte', 'persönlicher Link, ohne Anmeldung'),
   ext('Wählt Mengen, schickt ab', ''),
   erp('Entwurf in „Aufträge“', 'noch kein Abgang, noch kein offener Posten'),
   du('Prüfen', 'Ist die Ware da?'),
   du('Bestätigen', ''),
   erp('Jetzt entstehen', 'Lagerabgang und offener Posten'))

sb('vers-kunden', 'Die Kundenakte',
   du('Kontakt öffnen', 'Verkauf → Kunden → Kontakte / B2B'),
   erp('Alles an einem Ort', 'Stammdaten, Konditionen, Bestellungen, Kommission, Gespräche'),
   du('Aktivität erfassen', 'mit Wiedervorlage – sonst versandet das Gespräch'),
   erp('Überfälliges', 'Zahlungserinnerung mit Mailtext zum Anpassen'),
   ok('Fällige Wiedervorlagen', 'oben in der Liste und auf „Heute“'))

sb('vers-angebote', 'Vom Angebot zum Auftrag',
   du('Kontakt wählen', 'Adresse + Rabatt aus der Kondition'),
   du('Positionen', 'Artikel, Farbe, Menge, Preis, Rabatt'),
   erp('PDF', 'Gesamtrabatt, Versand, Nummer AN-JJJJ-0001'),
   du('Per Mail senden', 'Wiedervorlage in der Kundenakte'),
   ext('Kunde antwortet', ''),
   du('Angenommen → Auftrag', 'oder abgelehnt, oder Kopie'),
   erp('Bestätigter Auftrag', 'Zahlungsziel aus Kondition, Lagerabgang'))

sb('vers-reklamation', 'Eine Reklamation durchlaufen',
   du('Fall anlegen', 'Bestellung oder frei, mit Grund'),
   erp('Eingangsbestätigung', 'wahlweise per Mail mit Fallnummer'),
   ext('Ware kommt zurück', ''),
   du('Retoure oder Ausschuss', 'Lagerbuchung, Herkunft „Reklamation“'),
   du('Lösung wählen', 'Ersatz · Gutschrift · Reparatur · abgelehnt'),
   erp('Verlauf + Kennzeichen am Auftrag', 'Mail mit Lösung'),
   ok('Auswertung', 'Gründe und häufigste Artikel'))

sb('vers-nachkauf', 'Wer bald wieder bestellt',
   erp('Bestellrhythmus rechnen', 'ab zwei Bestellungen je Kunde'),
   erp('Nächste Bestellung erwartet', 'mit Sicherheit und Lieblingsartikeln'),
   warn('Überfällig oder verloren?', ''),
   du('Mailvorschlag senden', 'bei Händlern mit frischem Nachbestell-Link'),
   erp('Eintrag + Wiedervorlage', 'in der Kundenakte'),
   ok('Oder „Ruhe“', 'wer sich gemeldet hat, wird nicht erneut vorgeschlagen'))

sb('vers-affiliates', 'Vom Influencer zur Auszahlung',
   du('Affiliate anlegen', 'Name, Code, Gesamtanteil, Konto im Shop (Pflicht)'),
   ext('Ambassador teilt auf', 'Rabatt für Follower und eigene Provision, z. B. 10 + 10'),
   erp('Gutschein, Rolle, Link und Willkommensmail', 'der Code gilt sofort, die Mail sagt Danke und erklärt alles'),
   ext('Influencer teilt den Link', 'Zuschauer kaufen mit dem Rabatt'),
   erp('Bezahlte Bestellung zählt', 'Warenwert, Provision, Satz von damals; Eigenkäufe nicht'),
   warn('Wartezeit', 'Provision wird erst nach 30 Tagen auszahlbar'),
   ext('Ambassador fordert an', 'unter Mein Konto, mit Zahlungsangabe'),
   du('Überweisen, dann festhalten', 'oder ablehnen mit Begründung'),
   erp('Als Beleg gebucht', 'Werbekosten, Kostenstelle des Affiliates; Stornieren möglich'))

sb('vers-plaetze', 'Lagerplätze und Etiketten',
   du('Lagerplatz anlegen', 'Code, Bezeichnung, Art'),
   erp('Etikett als PDF', 'QR-Code + Klartext, Format DK-11201'),
   du('Drucken: „Tatsächliche Größe“', 'sonst wird der QR-Code zu klein'),
   du('Aufkleben, scannen', ''),
   erp('Scan zeigt', 'Platz → Inhalt · Artikel → Buchung · offene Bestellung → Wareneingang'))

sb('vers-bestand', 'Bestand korrigieren',
   du('Im Fach weniger als auf der Liste', ''),
   du('Buchen → Korrektur', 'Differenz eintragen (18 statt 20 = −2)'),
   warn('Begründung Pflicht', ''),
   erp('Korrekturbuchung', 'der Bestand stimmt wieder'))

sb('vers-fehler', 'Wenn am Packtisch etwas nicht geht',
   du('Beobachtung in der Tabelle suchen', ''),
   erp('Ursache steht daneben', ''),
   du('Abhilfe umsetzen', ''),
   warn('Kein Recht / kein Platz?', 'Person mit Vollzugriff fragen'))

sb('vers-spickzettel', 'Eine Runde Versand',
   du('Liste erzeugen', ''),
   du('Picken', 'bis „0 offen“'),
   du('Packen', 'nach Bestellnummer'),
   du('Lieferschein + Label', 'Gewicht ansehen!'),
   du('Versendet ✓', 'erst wenn das Paket wirklich weg ist'))

# ---------------------------------------------------------------- Teil C
sb('pers-warum', 'Wofür Zeit erfasst wird',
   du('Du schreibst Zeit auf', 'zwei Klicks mit der Uhr'),
   erp('Der Preis stimmt', 'Handarbeit steckt in jedem Artikelpreis'),
   erp('Die Woche bleibt machbar', 'Richtwert gegen Ist'),
   erp('Der Lohn ist nachvollziehbar', 'Stunden × Stundenlohn'),
   ok('Nicht zur Kontrolle', ''))

sb('pers-anmelden', 'Zur Zeiterfassung',
   du('Adresse + /erp', 'anmelden'),
   du('„Zeit“ oben rechts', 'Uhrsymbol, auf jeder Seite'),
   ok('Zeitseite', 'Uhr, Nachtragen, Meine Woche'))

sb('pers-geraete', 'Wo bin ich angemeldet?',
   du('Profilmenü öffnen', 'Bild unten in der Leiste, am Handy unter „Mehr“'),
   du('„Angemeldete Geräte“', 'zeigt Gerät, Browser, Anmeldezeit und gültig bis'),
   erp('Jedes Gerät hat seine Anmeldung', 'Browser und App vom Home-Bildschirm getrennt; 14 Tage gültig'),
   warn('Gerät verloren oder fremd?', '„Alle anderen abmelden“ – sofort'),
   ok('Nur dieses Gerät bleibt angemeldet', 'die anderen melden sich neu an'))

sb('pers-uhr', 'Die Stoppuhr',
   du('Uhr starten', 'wenn du anfängst'),
   erp('Merkt sich den Startzeitpunkt', 'läuft auch bei zugeklapptem Laptop weiter'),
   du('Pause / Weiter', 'Pause zählt nicht'),
   erp('Merkt, wo du warst', 'Bereiche des ERP als Gedächtnisstütze'),
   du('Stoppen', ''))

sb('pers-stoppen', 'Uhr stoppen und buchen',
   du('„Stoppen und erfassen“', ''),
   du('Kategorie wählen', 'bei mehreren Bereichen schlägt das ERP nichts vor'),
   du('Stunden prüfen', 'Vorschlag = Laufzeit ohne Pausen'),
   warn('Mehr als 10 Stunden?', 'sieht nach vergessener Uhr aus – echte Zeit eintragen'),
   du('Tätigkeit beschreiben', ''),
   erp('Ein Eintrag', 'auf den Starttag gebucht'))

sb('pers-nachtragen', 'Zeit nachtragen',
   du('Datum', 'heute oder früher'),
   du('Kategorie, Stunden', 'Dezimal: 0,75 statt 0:45'),
   du('Tätigkeit', 'kurz'),
   erp('Eintrag + Wochensumme', 'gegen den Richtwert'))

sb('pers-kategorien', 'Die richtige Kategorie',
   du('Wo ging die meiste Zeit hin?', ''),
   du('Eine der zehn Kategorien', 'Produktion · Rüstzeit · Druckzeit · Versand · Konstruktion · Marketing · Vertrieb · Verwaltung · Wartung · Sonstiges'),
   erp('Auswertung nach Kategorie', ''),
   warn('Nicht dauernd „Sonstiges“', ''))

sb('pers-handy', 'Zeit am Handy',
   du('/betrieb-erfassen/ → Reiter Zeit', ''),
   du('Starten', 'Pause / Weiter möglich'),
   du('Stoppen', 'Kategorie, Stunden, Tätigkeit'),
   du('Buchen', ''),
   ok('Auch die Uhr oben im ERP', 'funktioniert am Handy'))

sb('pers-auftragszeit', 'Zeit am Auftrag und an der Platte',
   du('Auftrag starten', 'Uhr läuft am Auftrag'),
   du('Platte: Rüsten ▶ und Druck ▶', 'Zeiten hängen an der Platte'),
   erp('Ist-Zeit je Auftrag und Platte', ''),
   erp('Ist-Lohnkosten', 'Zeit × Stundenlohn'),
   ok('Soll gegen Ist', 'lohnt sich das Set beim aktuellen Preis?'))

sb('pers-urlaub', 'Urlaub und Krankheit',
   du('Urlaub beantragen', 'Zeitraum wählen'),
   erp('Mail an alle, die genehmigen dürfen', ''),
   du('Genehmigen / ablehnen', 'eigenes Recht'),
   du('Krankheit melden', 'gilt sofort'),
   erp('Kalender, Konten, Resturlaub', 'andere sehen nur „abwesend“'),
   erp('Vorschlag für die Lohnabrechnung', 'Tage × Stunden × Stundenlohn'))

sb('pers-benutzer', 'Wer sieht was',
   ext('Konto kommt aus WordPress', ''),
   du('Person wählen', 'Liste links, Filter Alle / Mit ERP-Zugriff'),
   erp('Karte der Person', 'WordPress-Rollen, Shop-Rolle, Hinweise'),
   warn('Vollzugriff ist nicht beschneidbar', 'so sperrt sich niemand aus'))

sb('pers-profile', 'Rechte vergeben',
   du('Person wählen', ''),
   du('Vorlage wählen', 'Werkstatt · Packtisch · Fertigung · Betriebsleitung · Alles einzeln'),
   erp('Vorschau „So sieht … das ERP“', 'Seiten nach Bereich'),
   du('Einzelrechte anpassen', 'Sehen und Ändern sind getrennt, Geld ist eigenes Recht'),
   du('Speichern', 'Leiste unten: „3 Änderungen … nicht gespeichert“'),
   ok('Rechte hängen am Menschen', 'nicht an der WordPress-Rolle'))

sb('pers-lohn', 'Einen Stundenlohn festlegen',
   du('Person öffnen', 'Profilmenü → Löhne & Protokoll'),
   du('€ je Stunde, Gilt ab, Bemerkung', ''),
   erp('Neue Zeile mit Stichtag', 'nichts wird überschrieben'),
   erp('Alte Stunden', 'bleiben mit dem damaligen Satz bewertet'),
   warn('Kein Lohn hinterlegt?', 'Stunden erscheinen als „ohne Lohn“'))

sb('pers-protokoll', 'Das Protokoll eines Nutzers',
   erp('Jeder Vorgang', 'Zeit, Vorgang, Begründung'),
   erp('Hash des Vorgängers', 'macht die Kette fälschungssicher'),
   du('Fehler zurückverfolgen', '„Wann ist der Bestand gekippt?“'),
   warn('Keine Leistungsmessung', ''))

sb('pers-stundensatz', 'Gerechnet gegen bezahlt',
   erp('Bezahlt', 'Lohnkosten ÷ bewertete Stunden'),
   erp('Gerechnet', 'kalkulatorischer Stundensatz in jedem Preis'),
   warn('Weicht deutlich ab?', 'rotes Schild'),
   du('Entscheidest du', 'Einstellungen → Kalkulation, mit Stichtag'))

sb('pers-neuer', 'Einen neuen Mitarbeiter anlegen',
   du('WordPress-Konto', 'Benutzer → Neu, Rolle „Abonnent“, Benachrichtigung'),
   du('Rechte', 'Benutzer & Rechte → Vorlage → Speichern'),
   du('Stundenlohn', 'falls bezahlt'),
   du('Uhr zeigen', 'Zeit oben rechts'),
   ok('Beim Ausscheiden', 'Vorlage „Kein Zugriff“, Konto nicht löschen'))

sb('pers-datenschutz', 'Was erfasst wird – und was nicht',
   erp('Erfasst', 'Datum, Kategorie, Stunden, Tätigkeit, Bereiche des ERP'),
   warn('Nicht erfasst', 'keine Tasten, Bildschirmfotos, Standortdaten, Klickstrecken'),
   erp('Löhne und Protokolle', 'nur mit dem passenden Recht'),
   ok('Export nach außen', 'ohne Namen von Geschäftskunden'))

sb('pers-fehler', 'Wenn bei der Zeit etwas nicht geht',
   du('Meldung lesen', ''),
   du('Tabelle unten', 'Beobachtung → Ursache → Abhilfe'),
   warn('Lohnkosten 0 €?', 'kein Stundenlohn hinterlegt'))

# ---------------------------------------------------------------- Teil D
svg('adm-aufbau', 'Wie das Plugin aufgebaut ist', '''
<svg class="sv" viewBox="0 0 760 330" role="img" aria-label="Das Plugin lebt in WordPress und WooCommerce und nutzt dieselbe Datenbank wie der Shop. Das ERP unter /erp, die klassischen Seiten, die Dienste im Netz und Claude greifen darauf zu.">
<rect class="sv-flaeche" x="10" y="10" width="740" height="310" rx="14"/>
<text class="sv-kopf" x="28" y="36">Eine WordPress-Installation</text>
<rect class="sv-box erp" x="30" y="56" width="200" height="86" rx="10"/>
<text class="sv-t" x="130" y="84">Lindenweg16 ERP</text>
<text class="sv-s" x="130" y="104">Plugin</text>
<text class="sv-s" x="130" y="122">Lager · Fertigung · Buchhaltung</text>
<rect class="sv-box" x="270" y="56" width="200" height="86" rx="10"/>
<text class="sv-t" x="370" y="84">WooCommerce</text>
<text class="sv-s" x="370" y="104">Shop, Bestellungen,</text>
<text class="sv-s" x="370" y="122">Zahlung, Action Scheduler</text>
<rect class="sv-box" x="510" y="56" width="220" height="86" rx="10"/>
<text class="sv-t" x="620" y="84">WordPress</text>
<text class="sv-s" x="620" y="104">Konten, Mediathek,</text>
<text class="sv-s" x="620" y="122">Theme lindenweg16</text>
<rect class="sv-box du" x="30" y="176" width="700" height="56" rx="10"/>
<text class="sv-t" x="380" y="200">Eine Datenbank – Bestellung im Shop und Buchung im Lager sind dasselbe</text>
<text class="sv-s" x="380" y="220">keine Zwischenschicht, kein nächtlicher Abgleich</text>
<line class="sv-l" x1="130" y1="142" x2="130" y2="176"/><line class="sv-l" x1="370" y1="142" x2="370" y2="176"/><line class="sv-l" x1="620" y1="142" x2="620" y2="176"/>
<rect class="sv-box ext" x="30" y="260" width="200" height="46" rx="10"/><text class="sv-t" x="130" y="288">Browser: /erp</text>
<rect class="sv-box ext" x="270" y="260" width="200" height="46" rx="10"/><text class="sv-t" x="370" y="288">Dienste im Betrieb (Pi)</text>
<rect class="sv-box ext" x="510" y="260" width="220" height="46" rx="10"/><text class="sv-t" x="620" y="288">Claude (lesend, MCP)</text>
<line class="sv-l" x1="130" y1="260" x2="130" y2="232"/><line class="sv-l" x1="370" y1="260" x2="370" y2="232"/><line class="sv-l" x1="620" y1="260" x2="620" y2="232"/>
</svg>''')

sb('adm-installation', 'Installieren und aktualisieren',
   du('WooCommerce aktiv?', 'sonst bricht die Aktivierung ab'),
   du('Plugin aktivieren', ''),
   erp('Legt an', 'Tabellen, Rollen lw16_manager / lw16_betrieb, geschütztes Belegverzeichnis'),
   du('Rechte vergeben, /erp öffnen', 'Permalinks nicht „Einfach“'),
   ext('Update', 'WordPress findet höhere Versionsnummer auf GitHub', ['Kanal: main oder Release', 'Sicherung der Programmdateien vorher']),
   erp('Schema-Abgleich', 'ergänzt Tabellen und Spalten, nichts geht verloren'),
   warn('Vor jedem Update: Datenbanksicherung', 'das Plugin hat keinen Papierkorb'))

sb('adm-reihenfolge', 'Einrichten in dieser Reihenfolge',
   du('Firma', 'Logo, Kleinunternehmer'),
   du('Kalkulation', 'Annahmen'),
   du('Farben', 'Attribut + Standardfarbe'),
   du('Lagerplätze', ''),
   du('Drucker', 'Maschinenstundensatz'),
   du('Kleinteile, Filament', ''),
   du('Artikel, Revisionen, Stücklisten', ''),
   du('Staffel', ''),
   du('Rechte', ''),
   warn('Livegang', 'ganz zum Schluss, Probedaten löschen'),
   hinweis='Jeder Schritt braucht, was der vorige angelegt hat.')

sb('adm-firmendaten', 'Firmendaten, DHL und Mails',
   du('Einstellungen → Plugin', 'Firma, Bank, Logo, Markenfarbe'),
   du('Kleinunternehmer?', 'mit Steuerberatung klären'),
   du('DHL: vier Angaben, zweimal', 'API-Key + Secret · Benutzer + Passwort – je Sandbox und Live'),
   ext('„DHL-Zugang prüfen“', 'meldet sich an, ohne ein Label zu erzeugen'),
   du('E-Mail-Vorlagen', 'Platzhalter wie {firmenname}'),
   warn('Passwörter erscheinen nie wieder', 'leeres Feld = unverändert'))

sb('adm-annahmen', 'Eine Annahme ändern',
   du('Neuen Wert eintragen', 'Punkt oder Komma'),
   du('Datum, ab dem er gilt', ''),
   du('Begründung', 'erscheint im Verlauf'),
   du('Festschreiben', ''),
   erp('Alter Wert endet am Vortag', 'bleibt stehen'),
   ok('„Gültig zum Stichtag“', 'jede alte Kalkulation lässt sich nachrechnen'))

sb('adm-farben', 'Farben und Lagerhoheit',
   du('Attribut pa_farbe in WooCommerce', 'ein Begriff je Farbe'),
   erp('Gespeichert wird der Slug', 'nie der Anzeigename'),
   du('Standardfarbe setzen', 'für Positionen ohne Farbe'),
   du('Lagerhoheit', 'erst einschalten, wenn Artikel mit Shop-Produkten verknüpft sind'),
   erp('Betrieb bucht die Abgänge', 'das alte Lager-Modul lässt diese in Ruhe'),
   du('Bestand 0 im Shop wählen', 'ausverkauft oder bestellbar, für alle Produkte'),
   erp('Regel gilt sofort in allen Shop-Produkten', 'auch bei Sets mit Bestand 0'),
   warn('Beide buchen = doppelt abgezogen', ''))

sb('adm-staffel', 'Rabatte für Händler',
   du('Stufen eintragen', 'ab welchem Wert welcher Rabatt'),
   du('Gültig ab + Begründung', ''),
   du('Festschreiben', ''),
   erp('Bestellung löst Staffel zum Bestelldatum auf', 'und schreibt sie fest'),
   ok('Alte Bestellungen bleiben, wie sie waren', ''))

sb('adm-staffel|Kommissionsware buchen', 'Kommissionsware buchen',
   du('Händler + Vorgang wählen', 'ausliefern · abrechnen · zurücknehmen'),
   du('Mengen je Farbe eintragen', 'leere Felder bleiben unberührt'),
   erp('Prüft alles', 'eine falsche Zeile = nichts wird gebucht'),
   erp('Bucht einmal', 'Abrechnen = ein offener Posten über die ganze Lieferung'),
   ok('Staffel greift auf den Gesamtwert', ''))

sb('adm-stammdaten', 'Stammdaten, Konfigurator und AR',
   du('Produkt anlegen', 'Beschaffungsweg, Shop-Verknüpfung, Standardplatz'),
   du('Konfigurator', 'Kunde wählt Teile'),
   erp('Warenkorb löst in echte Artikel auf', 'Lagerabgang + Kalkulation wie sonst'),
   du('AR: GLB-Modell, Maßstab 1 Einheit = 1 m', 'unkomprimiert'),
   ext('Besucher am Handy', 'Hinweis, AR-Seite, Kamera'))

sb('adm-stammdaten|Set-Vorschlag im Konfigurator', 'Wunschkonfiguration wird zum Set',
   du('Set als Artikel mit Stückliste', 'Basismodul + Module, ganze Stück, Shop-Produkt verknüpft'),
   ext('Kunde stellt zusammen', 'Länge und Module, Anordnung frei'),
   erp('Vergleicht mit allen Sets', 'gleiche Teile in gleicher Zahl, Position egal'),
   erp('Schlägt das Set vor', 'Setpreis gegen Einzelpreise, nur wenn nicht teurer'),
   ok('Eine Position zum Setpreis', 'Anordnung und Code an der Bestellung'))

sb('adm-stammdaten|AR-Ansicht „An deiner Wand ansehen“', 'AR in fünf Schritten',
   du('Modell vorbereiten', 'GLB, Meter, keine Kompression'),
   du('Am Produkt hinterlegen', 'Box „3D-Modell (AR)“'),
   du('Seite mit [l16_ar_ansicht]', 'als Ziel-Seite wählen'),
   du('Einstellungen', 'Sperrdauer, Verzögerung, Texte'),
   ext('Am Handy testen', 'mit Zollstock nachmessen'))

sb('adm-preise', 'Vom Kostenblock zum Shop-Preis',
   erp('Kalkulation', 'Material · Maschine · Handarbeit · Komponenten · Verpackung · Gebühr'),
   du('Zielmarge eintragen', 'Deckungsbeitrag II in Prozent'),
   erp('Vorschlag', 'oder „nicht erreichbar“ – das ist Information'),
   du('Als Artikelpreis übernehmen', ''),
   erp('Shop zieht nach', 'Normalpreis; ein laufendes Angebot bleibt'))

sb('adm-buchhaltung', 'Von der Rechnung zur EÜR',
   du('Beleg erfassen', 'Foto, PDF oder E-Rechnung; oder mehrere auf einmal'),
   erp('Liest Text im Browser', 'Datei verlässt das Gerät nicht'),
   du('Prüfen', 'Datum, Händler, Betrag, Konto (Suchfeld)'),
   du('Speichern / festschreiben', ''),
   erp('Hash-Kette', 'festgeschrieben = unveränderlich (GoBD)'),
   erp('EÜR, Journal, DATEV', 'rechnen aus denselben Belegen'),
   warn('Falsch? Stornieren + neu erfassen', ''))

sb('adm-buchhaltung|Dauerbuchungen und „Auf Rechnung warten“', 'Eine Dauerbuchung mit wartender Rechnung',
   erp('Termin ist fällig', ''),
   erp('Wartet auf die Rechnung', 'noch kein Beleg · Aufgabe auf „Heute“'),
   ext('Rechnung kommt', 'Abo, Telefon, Strom'),
   du('Hochladen oder fotografieren', 'Beleg-Erfassung mit erwartetem Betrag vorbelegt'),
   erp('Beleg entsteht', 'Quelle „Dauerbuchung“, Datei in der Hash-Kette'),
   warn('Kommt keine?', 'Termin mit Grund auf „Entfällt“ setzen – nichts wird gelöscht'))

sb('adm-bank', 'Vom Konto zur Zuordnung',
   ext('Bank', 'Kontoauszug (CAMT, CSV) oder Vivid, stündlich'),
   erp('Umsätze einlesen', 'Fingerabdruck verhindert Doppelte'),
   erp('Vorschläge', 'Betrag + Nummer im Zweck + Name'),
   du('Zuordnen', 'Auftrag · Shop-Bestellung · Beleg · ohne Zuordnung'),
   erp('Folge', 'Rechnung bezahlt · Zahlung in WooCommerce · verknüpft'),
   ok('Saldo → Liquidität', 'Live-Kontostand ist der Startwert'))

sb('adm-mahnwesen', 'Von der Erinnerung zur letzten Mahnung',
   erp('Offener Posten', 'B2B-Rechnung oder Shop-Vorkasse, mit Altersklasse'),
   du('Zahlungserinnerung', ''),
   du('1. Mahnung', 'neue Frist, Gebühr'),
   du('Letzte Mahnung', 'Verzugszinsen, 40 €-Pauschale bei Unternehmern'),
   erp('Mail + PDF', 'Kundenakte + Wiedervorlage + Protokoll'),
   warn('Mahnstopp', 'mit Grund und Enddatum nimmt den Posten heraus'),
   ok('Bezahlt', 'automatisch im Bankabgleich oder von Hand'))

sb('adm-steuer', 'Von den Belegen zur Voranmeldung',
   du('Kleinunternehmer?', 'Umsatzgrenzen § 19 UStG mit Hochrechnung'),
   erp('Regelbesteuerung', 'USt 19 % / 7 % aus Shop-Bestellungen (Kz 81, 86)'),
   du('Vorsteuer je Beleg', 'Satz wählen, Beleg bleibt unverändert'),
   erp('Zahllast (Kz 66)', 'je Monat oder Quartal, CSV'),
   erp('DATEV-Buchungsstapel', 'EXTF 700, SKR03, BU-Schlüssel 8 / 9'),
   ext('Steuerberatung', 'ersten Export gemeinsam prüfen'))

sb('adm-liquiditaet', 'Wie die Kurve entsteht',
   ext('Startwert', 'Live-Saldo der Vivid-Konten'),
   erp('+ Eingänge', 'offene Rechnungen zur Fälligkeit, Shop-Umsatz'),
   erp('− Ausgänge', 'Dauerbuchungen, Löhne, Einkäufe, USt-Zahllast'),
   erp('Zwölf Wochen', 'Mindestpuffer mit Warnung'),
   du('Was wäre wenn', 'Regler: Umsatz, Preise, späte Zahler, einmalige Ausgabe, Aushilfe …'),
   erp('Täglicher Überblick per Mail', 'nur wenn etwas zu tun ist'))

sb('adm-kasse', 'Marktkasse, Events und Shop-Bestellungen',
   du('Kassatag starten', 'Anlass, Kostenstelle, Wechselgeld'),
   du('Kacheln antippen → Kassieren', 'freier Betrag für Sonderfälle'),
   du('Tag abschließen', 'Kasse gezählt → Differenz'),
   erp('Tagesumsatz als Beleg', 'EÜR, Marketing und DATEV zählen ihn mit'),
   du('Shop-Bestellung erstatten', 'Menge je Position, Versand, freier Betrag'),
   warn('Rückzahlung ist endgültig', 'eigenes Recht, nur einzeln vergeben'))

sb('adm-kasse|Wie die Anmeldung abläuft', 'Eine Event-Anmeldung',
   ext('Besucher meldet sich an', 'Name, E-Mail, Einwilligung, Begleitpersonen'),
   erp('Bestätigungslink per Mail', 'bis dahin belegt die Anmeldung keinen Platz'),
   ext('Klickt auf den Link', ''),
   erp('Platz belegt – oder Warteschlange', 'Plätze zählen, nicht Anmeldungen'),
   ext('Absage über denselben Link', ''),
   erp('Erster der Warteliste rückt nach', 'Gruppen nur geschlossen'))

sb('adm-berichte', 'Ziele, Rückblick und Abschluss',
   du('Quartal wählen, Ziele eintragen', 'leer = kein Ziel'),
   erp('Rechnet beim Rasterwechsel um', 'Summen verteilt, Quoten bleiben'),
   erp('Stundenlohn', 'was pro gearbeiteter Stunde bleibt'),
   erp('Wochenrückblick', 'Freitag 16 Uhr per Mail, ein Tipp'),
   du('Monat abschließen', ''),
   ok('Werte eingefroren', 'ein Monatsbericht, der sich nachträglich ändert, ist keiner'))

sb('adm-live', 'Was die Wand zeigt',
   erp('Fragt alle 15 Sekunden', 'hält den Bildschirm wach'),
   erp('Kennzahlen, Verkauf, Website', 'mit Zielchips'),
   erp('Produktion + Drucker', 'große Kacheln mit Fortschritt'),
   erp('Aufgaben aus „Heute“', 'Wandnutzer: alle, ohne persönliche Mails'),
   warn('Verbindung weg?', 'der letzte Stand bleibt stehen'))

sb('adm-live|Besucher jetzt', 'Wer ist gerade auf der Website – ohne Cookie',
   ext('Besucher öffnet eine Seite', 'ohne Einwilligungsbanner, nichts wird im Browser gespeichert'),
   erp('Seite meldet sich alle 25 Sekunden', 'mit einer Zufallsnummer, die nur im Tab lebt'),
   erp('Server hält nur die Seite fest', 'Pfad, Titel, Bereich, Gerät – keine IP-Adresse, kein Browsername'),
   du('„Besucher jetzt“ ansehen', 'Verkauf → Besucher jetzt, aktualisiert alle 10 Sekunden'),
   warn('Tab zu oder 90 Sekunden still', 'die Seite verschwindet aus der Anzeige'),
   erp('Reiter „Wege“', 'zählt nur Schritte von Seite A nach B je Tag, ohne zu merken, wer es war'),
   erp('Produkte, Kampagnen, Suche, Fehlerseiten', 'weitere anonyme Tageszähler, 90 Tage'))

sb('adm-pruefung', 'Zwei Prüfungen',
   du('Systemprüfung', 'nach größeren Änderungen'),
   erp('Sucht Widersprüche', 'zurückgezogene Revision, stillgelegte Position, Gerät ohne Daten'),
   du('Kettenprüfung', ''),
   erp('Jeder Eintrag enthält den Hash des Vorgängers', ''),
   warn('Bruch', 'die Meldung sagt, an welcher Stelle'))

sb('adm-import', 'Daten aus einer Tabelle übernehmen',
   du('Blätter als CSV', 'Artikel zuerst, dann Druckjournal'),
   du('Datei lesen', 'Spalten den Feldern zuordnen'),
   du('Vorschau erstellen', 'Datei erneut wählen'),
   warn('Vorschau lesen', ''),
   du('Import jetzt durchführen', ''),
   ok('Verfahrensdokumentation', 'liest die Werte beim Erzeugen frisch – einmal jährlich als PDF ablegen'))

svg('adm-pi', 'Die Dienste holen ab – nichts wird hineingeschickt', '''
<svg class="sv" viewBox="0 0 760 340" role="img" aria-label="Raspberry Pi und Slicing-Rechner bauen ausgehende HTTPS-Verbindungen zur Website auf und fragen dort nach Aufträgen. Der Bambu-Drucker hängt im lokalen Netz am Pi. Optional klingelt WordPress über Home Assistant per MQTT, damit der Pi sofort nachfragt.">
<defs><marker id="pf-pi" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path class="sv-pf" d="M0 0 L10 5 L0 10 z"/></marker></defs>
<rect class="sv-flaeche" x="10" y="10" width="740" height="320" rx="14"/>
<rect class="sv-box ext" x="30" y="40" width="170" height="76" rx="10"/>
<text class="sv-t" x="115" y="72">Bambu-Drucker</text><text class="sv-s" x="115" y="92">LAN-Modus</text>
<rect class="sv-box ext" x="290" y="40" width="170" height="76" rx="10"/>
<text class="sv-t" x="375" y="66">Raspberry Pi</text><text class="sv-s" x="375" y="86">Bambu-Bridge,</text><text class="sv-s" x="375" y="102">Etikettendrucker</text>
<line class="sv-l" x1="200" y1="78" x2="288" y2="78" marker-end="url(#pf-pi)" marker-start="url(#pf-pi)"/>
<text class="sv-s" x="245" y="68">LAN</text>
<rect class="sv-box ext" x="290" y="236" width="170" height="76" rx="10"/>
<text class="sv-t" x="375" y="262">Slicing-Rechner</text><text class="sv-s" x="375" y="282">OrcaSlicer, Docker</text><text class="sv-s" x="375" y="298">(x86, im Betrieb)</text>
<rect class="sv-box du" x="290" y="138" width="170" height="76" rx="10"/>
<text class="sv-t" x="375" y="164">Türklingel</text><text class="sv-s" x="375" y="184">Home Assistant</text><text class="sv-s" x="375" y="200">(optional, per MQTT)</text>
<rect class="sv-box erp" x="560" y="40" width="170" height="272" rx="10"/>
<text class="sv-t" x="645" y="70">Website</text><text class="sv-s" x="645" y="92">WordPress + ERP</text>
<text class="sv-s" x="645" y="130">Warteschlangen für</text><text class="sv-s" x="645" y="148">Etiketten, Bambu-</text><text class="sv-s" x="645" y="166">Aufträge und Slicing</text>
<text class="sv-s" x="645" y="204">Token: nur die</text><text class="sv-s" x="645" y="222">Prüfsumme wird</text><text class="sv-s" x="645" y="240">gespeichert</text>
<line class="sv-l" x1="460" y1="70" x2="558" y2="70" marker-end="url(#pf-pi)"/>
<text class="sv-s" x="509" y="60">fragt an</text>
<line class="sv-l" x1="460" y1="274" x2="558" y2="274" marker-end="url(#pf-pi)"/>
<text class="sv-s" x="509" y="264">fragt an</text>
<line class="sv-l strich" x1="558" y1="176" x2="462" y2="176" marker-end="url(#pf-pi)"/>
<text class="sv-s" x="509" y="166">klingelt</text>
<line class="sv-l strich" x1="375" y1="138" x2="375" y2="118" marker-end="url(#pf-pi)"/>
<text class="sv-s" x="405" y="132">sofort</text>
</svg>''', hinweis='Alle Pfeile gehen von innen nach außen: Pi und Slicing-Rechner fragen bei der Website an, nie umgekehrt. Kein Port am Router, kein offener Port auf dem Pi. Ohne Klingel fragen sie mit wachsenden Pausen nach (bis 60 s).')

sb('adm-pi|Bambu-Lab-Drucker anbinden', 'Einen Bambu-Drucker anbinden',
   ext('LAN-Modus am Drucker', 'IP, Zugangscode, Seriennummer'),
   ext('Feste IP im Router', ''),
   du('„Bambu-Drucker anlegen“', 'ID, Name, IP, Code, Seriennummer, Gerät'),
   du('Token übernehmen', 'wird nur einmal angezeigt'),
   ext('install-bambu-bridge.sh auf dem Pi', ''),
   ok('Karte steht auf „Online“', 'nach etwa einer Minute'))

sb('adm-pi|Slicing-Worker für die Plattenanordnung', 'Automatisch slicen einrichten',
   ext('Worker-Rechner mit Docker', 'OrcaSlicer'),
   du('Worker-Token erzeugen', 'und in .env.slicing eintragen'),
   du('Drei Profile exportieren', 'Drucker · Filament · Prozess'),
   du('Material + Druckermodell zuordnen', 'hochladen'),
   du('Testplatte slicen', 'Druckvorschau in Bambu Studio ansehen'),
   warn('Fehlgeschlagen?', 'Grund steht unter dem Status → beheben → „Erneut slicen“'))

sb('adm-automatisch', 'Was ohne Zutun läuft',
   erp('Action Scheduler', 'gehört zu WooCommerce'),
   erp('Bei Ereignissen', 'Lagerabgang, Storno, „wieder verfügbar“'),
   erp('Zeitgesteuert', 'Bankabruf stündlich · Mails · Sendungsverfolgung · Wartung der Warteschlangen'),
   warn('Ohne Besucher oder Cron', 'bleiben Aufgaben liegen → WooCommerce → Status → Geplante Aktionen'))

sb('adm-livegang', 'Sauber starten',
   du('Datenbanksicherung', 'es gibt keinen Papierkorb'),
   du('Beide Listen lesen', 'was bleibt · was gelöscht wird'),
   du('Bestätigen', 'gilt zehn Minuten'),
   warn('Jetzt endgültig löschen', 'genau einmal, am Tag vor dem Start'),
   ok('Hash-Kette beginnt bei null', ''))

sb('adm-sicherung', 'Was wann gesichert wird',
   du('Vor jedem Update', 'Datenbank'),
   du('Vor dem Livegang', 'Datenbank'),
   du('Vor „Alle Preise übertragen“', 'Datenbank'),
   du('Laufend', 'Datenbank + wp-content/uploads (Belege, Modelle, lw16-mail)'),
   ok('Wöchentlicher Datenexport per Mail', 'ersetzt keine Serversicherung'))

sb('adm-fehler', 'Wenn etwas nicht geht',
   du('Meldung zu Ende lesen', ''),
   du('Beobachtung in der Tabelle', ''),
   erp('Ursache + Abhilfe', ''),
   warn('Immer zuerst', 'WooCommerce aktiv? Permalinks gespeichert? Recht vorhanden?'))

sb('adm-mailbox', 'Von IONOS ins ERP und zurück',
   du('Postfach bei IONOS anlegen', ''),
   du('Mailbox → Postfächer', 'Bezeichnung, Adresse, Passwort, Besitzer'),
   du('Verbindung testen', 'Eingang und Ausgang müssen ein Häkchen haben'),
   erp('Holt Mails per IMAP', 'alle 5 Minuten, beim Öffnen, „Jetzt abrufen“'),
   du('Lesen, antworten, weiterleiten', 'Signatur + Layout der Shop-Mails'),
   ext('Gesendet über IONOS-SMTP', 'landet auch im Gesendet-Ordner bei IONOS'))

sb('adm-claude', 'Wie Claude Shop und ERP liest',
   du('Schlüssel anlegen', 'ERP → Profilmenü → Claude-Zugang'),
   du('Adresse mit Schlüssel', 'in claude.ai als Connector eintragen'),
   ext('Claude fragt', 'Tagesüberblick, Bestellungen, Routen, Datenbank (lesend)'),
   erp('Prüft und begrenzt', 'nur SHA-256 gespeichert · SQL nur lesend · Drosselung'),
   ok('Morgenbericht', 'mit Handlungsanweisungen'),
   warn('Schlüssel verloren?', 'Löschen – er gilt sofort nicht mehr'))

sb('adm-erp', 'Offline, Storno, Deinstallation',
   ext('Netz weg', 'Buchungen werden vorgemerkt'),
   erp('Netz zurück', 'sie werden gesendet'),
   du('Bestellung storniert / erstattet', ''),
   erp('Rechnung storniert, Gegenbuchung, Lager zurück', 'nichts wird gelöscht'),
   warn('Ware schon beim Kunden?', 'Lagerrückbuchung abschalten, erst bei Retoure buchen'),
   warn('Plugin löschen', 'Daten bleiben (GoBD) – alles löschen nur mit LW16_REMOVE_ALL_DATA'))


# ---------------------------------------------------------------- Ausgabe
def render(key, spec):
    """HTML eines Schaubilds. Reine Funktion – der Generator schreibt sie ins Dokument."""
    kopf = '<div class="sb-kopf">Schaubild · %s</div>' % escape(spec['titel'])
    if 'svg' in spec:
        body = spec['svg'].strip()
        leg = ''
    else:
        items = []
        used = []
        for kind, titel, text, alt in spec['schritte']:
            if kind not in used:
                used.append(kind)
            li = '<li class="%s"><b>%s</b>' % (kind, escape(titel))
            if text:
                li += '<span>%s</span>' % escape(text)
            if alt:
                li += '<ul class="alt">%s</ul>' % ''.join('<li>%s</li>' % escape(a) for a in alt)
            items.append(li + '</li>')
        n = len(items)
        spalten = {1: 1, 2: 2, 3: 3, 4: 4, 5: 5, 6: 3, 7: 4, 8: 4, 9: 5, 10: 5}.get(n, 5)
        body = '<ol class="fluss c%d">\n\t' % spalten + '\n\t'.join(items) + '\n</ol>'
        order = ['du', 'erp', 'ext', 'warn', 'ok']
        leg = '<p class="sb-leg">%s</p>' % ' '.join(
            '<span class="%s">%s</span>' % (k, LEGENDE[k]) for k in order if k in used)
    unter = '<p class="sb-unter">%s</p>' % escape(spec['hinweis']) if spec.get('hinweis') else ''
    label = escape('Schaubild: ' + spec['titel'], quote=True)
    return '<figure class="schaubild" role="group" aria-label="%s">\n%s\n%s\n%s%s\n</figure>' % (
        label, kopf, body, leg, ('\n' + unter) if unter else '')
