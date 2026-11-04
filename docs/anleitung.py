#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Pflege und Prüfung der Betriebsanleitung (docs/betriebsanleitung.html).

    python3 docs/anleitung.py sync    # Nummern, Inhaltsverzeichnis, Stand und Schaubilder neu schreiben
    python3 docs/anleitung.py check   # prüfen (das tut das CI); Exitcode 1 bei Fehlern

Was `sync` erzeugt – und deshalb nicht von Hand gepflegt wird:
  * die Kapitelnummern (A.1, A.2 …) in den Überschriften,
  * das Inhaltsverzeichnis,
  * die Schaubilder aus docs/schaubilder.py (zwischen den Markern
    `<!-- schaubild:KAPITEL -->` und `<!-- /schaubild:KAPITEL -->`),
  * der Stand der Anleitung (`<meta name="anleitung-stand">` und alle
    `<span data-stand>`) und das Stylesheet der Schaubilder.

Was `check` prüft:
  * `sync` wäre ein No-op (Nummern, Verzeichnis, Schaubilder aktuell),
  * jedes Kapitel hat ein Schaubild, und kein Schaubild zeigt ins Leere,
  * Sprungmarken und Kapitelverweise („Kapitel D.15“) führen irgendwohin,
  * Bilder existieren und haben einen Alternativtext,
  * Ids sind eindeutig, die Block-Tags sind sauber verschachtelt,
  * die Reiter im Backend (HandbuchPage.php) springen auf vorhandene Teile,
  * der Stand der Anleitung ist nicht neuer als das Plugin und nicht hinter einer
    Version, die im CHANGELOG etwas „Neu“ bringt (dann fehlt die Anleitung dazu).

Nur Standardbibliothek.
"""

import re
import sys
from html import unescape
from html.parser import HTMLParser
from pathlib import Path

DOCS = Path(__file__).resolve().parent
ROOT = DOCS.parent
DOC = DOCS / 'betriebsanleitung.html'

sys.path.insert(0, str(DOCS))
import schaubilder  # noqa: E402

# Teil → (Kennbuchstabe, Kennung der Sektion)
TEILE = [('', None), ('A', 'teil-produktion'), ('B', 'teil-versand'), ('C', 'teil-personal'), ('D', 'teil-admin')]

CSS_START = '/* schaubilder:start */'
CSS_ENDE = '/* schaubilder:ende */'

CSS = CSS_START + '''
.schaubild{margin:22px 0 28px;padding:16px 18px 16px;background:var(--karte);border:1px solid var(--linie);border-radius:12px;box-shadow:var(--schatten);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;line-height:1.38}
.sb-kopf{font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--akzent);font-weight:700;margin:0 0 18px}
ol.fluss{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(4,1fr);gap:24px 38px;counter-reset:sb}
ol.fluss.c1{grid-template-columns:minmax(0,420px)}
ol.fluss.c2{grid-template-columns:repeat(2,1fr)}
ol.fluss.c3{grid-template-columns:repeat(3,1fr)}
ol.fluss.c5{grid-template-columns:repeat(5,1fr);gap:24px 34px}
ol.fluss.c2>li:nth-child(2n)::after,ol.fluss.c3>li:nth-child(3n)::after,ol.fluss.c4>li:nth-child(4n)::after,ol.fluss.c5>li:nth-child(5n)::after{display:none}
ol.fluss>li{position:relative;margin:0;padding:11px 12px 10px;border:1.5px solid var(--linie);border-left-width:5px;border-radius:9px;background:var(--papier);font-size:14px;counter-increment:sb;break-inside:avoid}
ol.fluss>li::before{content:counter(sb);position:absolute;top:-11px;left:-13px;width:22px;height:22px;border-radius:50%;background:var(--text);color:var(--karte);font:700 12px/22px system-ui,sans-serif;text-align:center}
ol.fluss>li::after{content:"\\279C";position:absolute;right:-28px;top:50%;transform:translateY(-50%);color:var(--text-3);font-size:18px}
ol.fluss>li:last-child::after{display:none}
ol.fluss>li>b{display:block;font-size:14px}
ol.fluss>li>span{display:block;color:var(--text-2);font-size:13px;margin-top:3px}
ol.fluss>li.du{border-left-color:var(--akzent);background:var(--akzent-weich)}
ol.fluss>li.erp{border-left-color:var(--gruen);background:var(--gruen-weich)}
ol.fluss>li.ext{border-left-color:var(--blau);background:var(--blau-weich)}
ol.fluss>li.warn{border-left-color:var(--rot);background:var(--rot-weich)}
ol.fluss>li.ok{border-left-color:var(--text-3)}
ol.fluss ul.alt{list-style:none;margin:8px 0 0;padding:0}
ol.fluss ul.alt li{margin:3px 0;padding:2px 0 2px 9px;border-left:2px solid var(--linie);font-size:12.5px;color:var(--text-2)}
.sb-leg{display:flex;flex-wrap:wrap;gap:6px 16px;margin:16px 0 0;font-size:12px;color:var(--text-2)}
.sb-leg span::before{content:"";display:inline-block;width:10px;height:10px;margin-right:6px;border-radius:3px;border:1.5px solid var(--linie);vertical-align:-1px}
.sb-leg .du::before{background:var(--akzent-weich);border-color:var(--akzent)}
.sb-leg .erp::before{background:var(--gruen-weich);border-color:var(--gruen)}
.sb-leg .ext::before{background:var(--blau-weich);border-color:var(--blau)}
.sb-leg .warn::before{background:var(--rot-weich);border-color:var(--rot)}
.sb-leg .ok::before{background:var(--papier);border-color:var(--text-3)}
.sb-unter{margin:10px 0 0;font-size:13px;color:var(--text-2)}
svg.sv{display:block;width:100%;height:auto}
.sv-flaeche{fill:var(--papier);stroke:var(--linie)}
.sv-box{fill:var(--karte);stroke:var(--linie);stroke-width:1.5}
.sv-box.erp{fill:var(--gruen-weich);stroke:var(--gruen)}
.sv-box.du{fill:var(--akzent-weich);stroke:var(--akzent)}
.sv-box.ext{fill:var(--blau-weich);stroke:var(--blau)}
.sv-kopf{fill:var(--text-3);font:700 12px system-ui,sans-serif;letter-spacing:.08em;text-transform:uppercase}
.sv-t{fill:var(--text);font:700 14px system-ui,sans-serif;text-anchor:middle}
.sv-s{fill:var(--text-2);font:12.5px system-ui,sans-serif;text-anchor:middle}
.sv-l{stroke:var(--text-3);stroke-width:1.6;fill:none}
.sv-l.strich{stroke-dasharray:5 4}
.sv-pf{fill:var(--text-3)}
@media (max-width:900px){
	ol.fluss,ol.fluss.c3,ol.fluss.c4,ol.fluss.c5{grid-template-columns:repeat(2,1fr)}
	ol.fluss>li::after{display:block !important}
	ol.fluss>li:nth-child(2n)::after{display:none !important}
	ol.fluss>li:last-child::after{display:none !important}
}
@media (max-width:640px){
	ol.fluss,ol.fluss.c2,ol.fluss.c3,ol.fluss.c4,ol.fluss.c5{grid-template-columns:1fr;gap:26px}
	ol.fluss>li::after{content:"\\2193";right:auto;left:50%;top:auto;bottom:-25px;transform:translateX(-50%);display:block !important}
	ol.fluss>li:nth-child(2n)::after{display:block !important}
	ol.fluss>li:last-child::after{display:none !important}
}
@media print{.schaubild{break-inside:avoid;box-shadow:none}}
''' + CSS_ENDE

H2 = re.compile(r'<h2 id="([^"]+)"><span class="nr">([^<]*)</span> (.*?)</h2>', re.S)
MARKER = re.compile(r'\n?<!-- schaubild:([^>]*?) -->.*?<!-- /schaubild:\1 -->\n?', re.S)


# --------------------------------------------------------------------- Aufbau
def teile(html):
    """Liefert [(Buchstabe, start, ende)] der Sektionen; der Einstieg liegt vor der ersten."""
    pos = []
    for buchstabe, sec in TEILE[1:]:
        m = re.search(r'<section class="teil" id="%s">' % sec, html)
        pos.append((buchstabe, m.start() if m else len(html)))
    return pos


def kapitel(html):
    """Alle h2 in Dokumentreihenfolge als Liste von dicts mit id, teil, titel, start, ende."""
    pos = teile(html)
    out = []
    for m in H2.finditer(html):
        teil = ''
        for buchstabe, start in pos:
            if m.start() >= start:
                teil = buchstabe
        out.append({'id': m.group(1), 'teil': teil, 'titel': m.group(3), 'nr': m.group(2), 'start': m.start(), 'ende': m.end()})
    return out


def nummern(html):
    zaehler = {}
    for k in kapitel(html):
        zaehler[k['teil']] = zaehler.get(k['teil'], 0) + 1
        k['neu'] = ('%s.%d' % (k['teil'], zaehler[k['teil']])) if k['teil'] else str(zaehler[k['teil']])
        yield k


def text(s):
    return unescape(re.sub(r'<[^>]+>', '', s)).strip()


# ----------------------------------------------------------------------- sync
def sync(html):
    # 1. Kapitelnummern
    liste = list(nummern(html))
    neu = {k['id']: k['neu'] for k in liste}
    html = H2.sub(lambda m: '<h2 id="%s"><span class="nr">%s</span> %s</h2>' % (m.group(1), neu[m.group(1)], m.group(3)), html)

    # 2. Inhaltsverzeichnis: fünf <ol class="flach"> der Reihe nach
    gruppen = {}
    for k in liste:
        gruppen.setdefault(k['teil'], []).append(k)
    listen = []
    for buchstabe, _ in TEILE:
        zeilen = ['\t\t<li><a href="#%s"><span class="nr">%s</span> %s</a></li>' % (k['id'], k['neu'], k['titel']) for k in gruppen.get(buchstabe, [])]
        listen.append('<ol class="flach">\n%s\n\t</ol>' % '\n'.join(zeilen))
    it = iter(listen)
    html = re.sub(r'<ol class="flach">.*?</ol>', lambda m: next(it), html, flags=re.S)

    # 3. Stand
    stand = re.search(r'<meta name="anleitung-stand" content="([^"]+)">', html)
    if stand:
        html = re.sub(r'(<span data-stand>)[^<]*(</span>)', r'\g<1>%s\g<2>' % stand.group(1), html)

    # 4. Stylesheet der Schaubilder
    if CSS_START in html:
        html = re.sub(re.escape(CSS_START) + r'.*?' + re.escape(CSS_ENDE), lambda m: CSS, html, flags=re.S)
    else:
        html = html.replace('</style>', CSS + '\n</style>', 1)

    # 5. Schaubilder
    html = MARKER.sub('', html)
    liste = list(nummern(html))
    ende_von = {}
    for i, k in enumerate(liste):
        ende_von[k['id']] = liste[i + 1]['start'] if i + 1 < len(liste) else len(html)
    einfuegen = []  # (Position, Text) – von hinten einfügen, damit Positionen stimmen
    for key, spec in schaubilder.S.items():
        kap, _, h3 = key.partition('|')
        k = next((x for x in liste if x['id'] == kap), None)
        if not k:
            continue
        block = '\n<!-- schaubild:%s -->\n%s\n<!-- /schaubild:%s -->\n' % (key, schaubilder.render(key, spec), key)
        if not h3:
            pos = k['ende']
            nach = re.match(r'\s*<p><span class="weg">.*?</p>', html[pos:ende_von[kap]], re.S)
            if nach:
                pos += nach.end()
            einfuegen.append((pos, block))
        else:
            gefunden = None
            for m in re.finditer(r'<h([34])[^>]*>(.*?)</h\1>', html[k['ende']:ende_von[kap]], re.S):
                if text(m.group(2)) == h3:
                    gefunden = k['ende'] + m.end()
                    break
            if gefunden is not None:
                einfuegen.append((gefunden, block))
    for pos, block in sorted(einfuegen, key=lambda x: -x[0]):
        html = html[:pos] + block + html[pos:]
    return html


# ---------------------------------------------------------------------- check
class Balance(HTMLParser):
    BLOCK = {'div', 'section', 'table', 'thead', 'tbody', 'figure', 'ul', 'ol', 'header', 'nav', 'svg', 'style', 'body', 'html'}

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.stack, self.fehler, self.ids, self.hrefs, self.imgs = [], [], [], [], []

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag in self.BLOCK:
            self.stack.append((tag, self.getpos()[0]))
        if 'id' in a:
            self.ids.append(a['id'])
        if tag == 'a' and (a.get('href') or '').startswith('#') and len(a['href']) > 1:
            self.hrefs.append(a['href'][1:])
        if tag == 'img':
            self.imgs.append((a.get('src'), a.get('alt'), self.getpos()[0]))

    def handle_endtag(self, tag):
        if tag not in self.BLOCK:
            return
        if not self.stack or self.stack[-1][0] != tag:
            self.fehler.append('Zeile %d: </%s> passt nicht zu %s' % (self.getpos()[0], tag, self.stack[-1] if self.stack else 'nichts'))
            return
        self.stack.pop()


def version_tuple(v):
    return tuple(int(x) for x in v.split('.'))


def check(html):
    f = []

    # Aktualität von Nummern, Verzeichnis, Schaubildern
    if sync(html) != html:
        f.append('Die Anleitung ist nicht synchron (Kapitelnummern, Inhaltsverzeichnis, Schaubilder oder Stand). '
                 'Bitte `python3 docs/anleitung.py sync` ausführen und das Ergebnis committen.')

    liste = list(nummern(html))
    ids = {k['id'] for k in liste}
    labels = {k['neu'] for k in liste}

    # Schaubilder
    for k in liste:
        if k['id'] not in schaubilder.S:
            f.append('Kapitel %s (%s) hat kein Schaubild. In docs/schaubilder.py ergänzen.' % (k['neu'], k['id']))
        elif '<!-- schaubild:%s -->' % k['id'] not in html:
            f.append('Das Schaubild für %s steht nicht im Dokument (`sync` ausführen).' % k['id'])
    for key in schaubilder.S:
        kap, _, h3 = key.partition('|')
        if kap not in ids:
            f.append('Schaubild „%s“ gehört zu keinem Kapitel.' % key)
        elif h3 and '<!-- schaubild:%s -->' % key not in html:
            f.append('Schaubild „%s“ findet seine Zwischenüberschrift nicht.' % key)

    # Struktur
    p = Balance()
    p.feed(html)
    f.extend(p.fehler)
    for tag, zeile in p.stack:
        f.append('Zeile %d: <%s> wird nie geschlossen' % (zeile, tag))
    doppelt = sorted({i for i in p.ids if p.ids.count(i) > 1})
    for i in doppelt:
        f.append('Id „%s“ kommt mehrfach vor.' % i)
    for h in sorted(set(p.hrefs)):
        if h not in p.ids:
            f.append('Sprungmarke #%s führt ins Leere.' % h)
    for src, alt, zeile in p.imgs:
        if not src or (src.startswith('bilder/') and not (DOCS / src).is_file()):
            f.append('Zeile %d: Bild „%s“ fehlt.' % (zeile, src))
        if not alt or not alt.strip():
            f.append('Zeile %d: Bild „%s“ ohne Alternativtext.' % (zeile, src))

    # Kapitelverweise im Fließtext
    body = html[html.index('<div class="seite">'):]
    body = re.sub(r'<(?:script|style).*?</(?:script|style)>', '', body, flags=re.S)
    body = re.sub(r'<span class="nr">[^<]*</span>', '', body)
    for m in re.finditer(r'(?<![\w.])([A-D])\.(\d{1,2})(?![\d])', unescape(re.sub(r'<[^>]+>', ' ', body))):
        if '%s.%s' % (m.group(1), m.group(2)) not in labels:
            f.append('Verweis auf Kapitel %s.%s – das gibt es nicht.' % (m.group(1), m.group(2)))
    nummer_von = {k['id']: k['neu'] for k in liste}
    for m in re.finditer(r'<a href="#([^"]+)">Kapitel ([A-D]\.\d+|\d+)</a>', html):
        if m.group(1) in nummer_von and nummer_von[m.group(1)] != m.group(2):
            f.append('Der Verweis „Kapitel %s“ zeigt auf #%s, und das ist Kapitel %s.' % (m.group(2), m.group(1), nummer_von[m.group(1)]))
    if '{ref:' in html or 'TODO' in html:
        f.append('Platzhalter {ref:…} oder TODO im Text.')

    # Backend-Reiter
    pfad = ROOT / 'includes' / 'Admin' / 'HandbuchPage.php'
    if pfad.is_file():
        for sprung in re.findall(r"__\( '[^']*', 'lindenweg16' \),\s*'([a-z-]+)',", pfad.read_text(encoding='utf8')):
            if sprung not in p.ids:
                f.append('HandbuchPage.php springt auf #%s, das es in der Anleitung nicht gibt.' % sprung)

    # Stand gegen Plugin und CHANGELOG
    m = re.search(r'<meta name="anleitung-stand" content="([^"]+)">', html)
    if not m:
        f.append('Im <head> fehlt <meta name="anleitung-stand" content="…">.')
    else:
        stand = m.group(1)
        plugin = re.search(r"define\( 'LW16_VERSION', '([^']+)' \)", (ROOT / 'lindenweg16.php').read_text(encoding='utf8'))
        if plugin and version_tuple(stand) > version_tuple(plugin.group(1)):
            f.append('Stand %s der Anleitung ist neuer als das Plugin %s.' % (stand, plugin.group(1)))
        cl = (ROOT / 'CHANGELOG.md').read_text(encoding='utf8')
        for abschnitt in re.split(r'(?m)^## ', cl)[1:]:
            v = re.match(r'\[(\d+\.\d+\.\d+)\]', abschnitt)
            if v and version_tuple(v.group(1)) > version_tuple(stand) and re.search(r'(?m)^### Neu', abschnitt):
                f.append('Version %s bringt im CHANGELOG etwas Neues, die Anleitung steht erst bei %s. '
                         'Anleitung nachziehen, dann den Stand im <meta> anheben und `sync` ausführen.' % (v.group(1), stand))
    return f


def main(argv):
    befehl = argv[1] if len(argv) > 1 else 'check'
    html = DOC.read_text(encoding='utf8')
    if befehl == 'sync':
        neu = sync(html)
        if neu != html:
            DOC.write_text(neu, encoding='utf8')
            print('betriebsanleitung.html aktualisiert.')
        else:
            print('Nichts zu tun.')
        return 0
    if befehl == 'check':
        fehler = check(html)
        for e in fehler:
            print('FEHLER: ' + e)
        kap = len(list(nummern(html)))
        print('%d Kapitel, %d Schaubilder, %d Fehler.' % (kap, len(schaubilder.S), len(fehler)))
        return 1 if fehler else 0
    print(__doc__)
    return 2


if __name__ == '__main__':
    sys.exit(main(sys.argv))
